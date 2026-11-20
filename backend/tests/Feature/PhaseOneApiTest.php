<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PhaseOneApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_login_returns_a_token_that_can_access_the_profile(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);
        $user->update(['role_id' => Role::findByName('admin', 'web')->id]);

        $response = $this->postJson('/api/login', [
            'username' => strtoupper($user->username),
            'password' => 'correct-password',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.role.name', 'admin')
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'username', 'email', 'role_id', 'role']]);

        $this->withToken($response->json('token'))
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('id', $user->id);
    }

    public function test_invalid_login_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);

        $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'incorrect-password',
        ])->assertUnauthorized();
    }

    public function test_email_is_not_accepted_as_a_login_identifier(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);

        $this->postJson('/api/login', [
            'username' => $user->email,
            'password' => 'correct-password',
        ])->assertUnauthorized();
    }

    public function test_users_only_see_configuration_for_their_assigned_branches(): void
    {
        $user = User::factory()->create();
        $user->update(['role_id' => Role::findByName('supervisor', 'web')->id]);
        $assignedBranch = Branch::create(['name' => 'Centro', 'code' => 'CTR']);
        $otherBranch = Branch::create(['name' => 'Norte', 'code' => 'NTE']);
        $assignedBranch->roles()->attach(Role::findByName('supervisor')->id);

        $assignedAccount = Account::create([
            'branch_id' => $assignedBranch->id,
            'name' => 'Caja menor',
            'type' => 'caja_menor',
        ]);
        Account::create([
            'branch_id' => $otherBranch->id,
            'name' => 'Banco',
            'type' => 'banco',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/branches')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $assignedBranch->id);

        $this->getJson('/api/accounts')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $assignedAccount->id);
    }

    public function test_only_admins_can_create_branches_and_cash_registers(): void
    {
        $cashier = User::factory()->create();
        $cashier->update(['role_id' => Role::findByName('cajero', 'web')->id]);

        $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/branches', ['name' => 'Centro', 'code' => 'CTR'])
            ->assertForbidden();

        $admin = User::factory()->create();
        $admin->update(['role_id' => Role::findByName('admin', 'web')->id]);

        $branchResponse = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/branches', [
                'name' => 'Centro',
                'code' => 'CTR',
            ])
            ->assertCreated();

        $branchId = $branchResponse->json('id');

        $this->postJson('/api/registers', [
            'branch_id' => $branchId,
            'name' => 'Caja 1',
            'code' => 'CAJA-1',
            'user_ids' => [$cashier->id],
        ])->assertCreated()
            ->assertJsonPath('users.0.id', $cashier->id);

        $this->assertDatabaseHas('cash_register_user', [
            'user_id' => $cashier->id,
        ]);
    }

    public function test_cashiers_cannot_read_other_registers_or_branch_accounts(): void
    {
        $cashier = User::factory()->create();
        $cashier->update(['role_id' => Role::findByName('cajero', 'web')->id]);
        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR']);
        $assignedRegister = CashRegister::create([
            'branch_id' => $branch->id,
            'name' => 'Caja asignada',
            'code' => 'CAJA-1',
        ]);
        $otherRegister = CashRegister::create([
            'branch_id' => $branch->id,
            'name' => 'Otra caja',
            'code' => 'CAJA-2',
        ]);
        $assignedRegister->users()->attach($cashier);
        $branch->roles()->attach(Role::findByName('cajero')->id);
        $account = Account::create([
            'branch_id' => $branch->id,
            'name' => 'Caja menor',
            'type' => 'caja_menor',
        ]);

        $this->actingAs($cashier, 'sanctum')
            ->getJson('/api/registers')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $assignedRegister->id);

        $this->getJson("/api/registers/{$otherRegister->id}")->assertForbidden();
        $this->getJson('/api/accounts')->assertOk()->assertJsonCount(0);
        $this->getJson("/api/accounts/{$account->id}")->assertForbidden();

        $this->getJson("/api/branches/{$branch->id}")
            ->assertOk()
            ->assertJsonMissingPath('accounts')
            ->assertJsonCount(1, 'cash_registers')
            ->assertJsonMissing(['id' => $otherRegister->id]);
    }

    public function test_register_options_and_edit_actions_follow_permissions_per_branch(): void
    {
        $role = Role::findByName('supervisor', 'web');
        $role->givePermissionTo(['registers.create', 'registers.update']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $allowed = Branch::create(['name' => 'Centro', 'code' => 'CTR']);
        $inactive = Branch::create(['name' => 'Inactiva', 'code' => 'INA', 'is_active' => false]);
        $other = Branch::create(['name' => 'Norte', 'code' => 'NTE']);
        $allowed->roles()->attach($role);
        $inactive->roles()->attach($role);
        $register = CashRegister::create(['branch_id' => $allowed->id, 'name' => 'Caja', 'code' => 'C1']);
        CashRegister::create(['branch_id' => $other->id, 'name' => 'Otra caja', 'code' => 'C1']);

        $this->actingAs($user, 'sanctum')->getJson('/api/registers/options')
            ->assertOk()->assertJsonCount(2)
            ->assertJsonPath('0.id', $allowed->id)
            ->assertJsonPath('1.is_active', false)
            ->assertJsonMissing(['id' => $other->id]);
        $this->getJson('/api/registers')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.id', $register->id)->assertJsonPath('0.can_update', true);
        $this->putJson("/api/registers/{$register->id}", ['name' => 'Caja editada'])
            ->assertOk()->assertJsonPath('name', 'Caja editada');

        $role->revokePermissionTo(['registers.create', 'registers.update']);
        $this->actingAs($user->fresh(), 'sanctum')->getJson('/api/registers/options')->assertForbidden();
        $this->getJson('/api/registers')->assertOk()->assertJsonPath('0.can_update', false);
        $this->putJson("/api/registers/{$register->id}", ['is_active' => false])->assertForbidden();

        $role->givePermissionTo('registers.create');
        $role->revokePermissionTo(['branches.view', 'registers.view_all']);
        $this->actingAs($user->fresh(), 'sanctum')->getJson('/api/registers/options')
            ->assertOk()->assertJsonCount(2);
        $this->getJson('/api/registers')->assertOk()->assertJsonCount(0);
    }

    public function test_register_management_preserves_branch_and_assignments_and_filters_by_branch(): void
    {
        $admin = User::factory()->create(['role_id' => Role::findByName('admin', 'web')->id]);
        $cashier = User::factory()->create();
        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR']);
        $other = Branch::create(['name' => 'Norte', 'code' => 'NTE']);
        $payload = ['branch_id' => $branch->id, 'name' => 'Caja', 'code' => 'C1', 'user_ids' => [$cashier->id]];
        $id = $this->actingAs($admin, 'sanctum')->postJson('/api/registers', $payload)
            ->assertCreated()->json('id');
        $this->postJson('/api/registers', $payload)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/registers', [...$payload, 'branch_id' => $other->id])->assertCreated();
        $this->putJson("/api/registers/{$id}", ['name' => 'Caja editada', 'code' => 'C2'])
            ->assertOk()->assertJsonPath('branch_id', $branch->id)->assertJsonPath('users.0.id', $cashier->id);
        $this->putJson("/api/registers/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('is_active', false);
        $this->putJson("/api/registers/{$id}", ['is_active' => true])->assertOk()->assertJsonPath('is_active', true);
        $this->getJson("/api/registers?branch_id={$branch->id}")
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $id)->assertJsonPath('0.code', 'C2');
        $this->assertDatabaseHas('cash_register_user', ['cash_register_id' => $id, 'user_id' => $cashier->id]);
    }

    public function test_branch_updates_preserve_role_assignments(): void
    {
        $admin = User::factory()->create();
        $admin->update(['role_id' => Role::findByName('admin', 'web')->id]);
        $supervisor = User::factory()->create();
        $supervisor->update(['role_id' => Role::findByName('supervisor', 'web')->id]);
        $cashier = User::factory()->create();
        $cashier->update(['role_id' => Role::findByName('cajero', 'web')->id]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/branches', [
            'name' => 'Centro', 'code' => 'CTR', 'address' => 'Calle 1',
        ])->assertCreated()->assertJsonPath('is_active', true);
        $id = $response->json('id');
        Branch::findOrFail($id)->roles()->attach(Role::findByName('cajero', 'web')->id);

        $this->postJson('/api/branches', ['name' => 'Duplicada', 'code' => 'CTR'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->putJson("/api/branches/{$id}", ['name' => 'Centro nuevo'])
            ->assertOk()->assertJsonPath('name', 'Centro nuevo');
        $this->assertDatabaseHas('branch_role', ['branch_id' => $id, 'role_id' => Role::findByName('cajero', 'web')->id]);
        $this->putJson("/api/branches/{$id}", ['roles' => ['admin']])->assertUnprocessable();
        $this->putJson("/api/branches/{$id}", ['user_ids' => [$supervisor->id]])->assertUnprocessable();
        $this->putJson("/api/branches/{$id}", ['user_ids' => []])->assertUnprocessable();
        $this->putJson("/api/branches/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('is_active', false);
        $this->assertDatabaseHas('branch_role', ['branch_id' => $id, 'role_id' => Role::findByName('cajero', 'web')->id]);
        $this->putJson("/api/branches/{$id}", ['is_active' => true])->assertOk()->assertJsonPath('is_active', true);

        $this->actingAs($supervisor, 'sanctum')->putJson("/api/branches/{$id}", ['name' => 'No autorizado'])->assertForbidden();
    }

    public function test_branch_permissions_follow_allowed_role_and_register_assignment_does_not_bypass_them(): void
    {
        $user = User::factory()->create();
        $user->update(['role_id' => Role::findByName('cajero', 'web')->id]);
        $peer = User::factory()->create();
        $peer->update(['role_id' => Role::findByName('cajero', 'web')->id]);
        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR']);
        $branch->roles()->attach(Role::findByName('cajero')->id);
        $register = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja', 'code' => 'C1']);
        $other = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Otra', 'code' => 'C2']);
        $register->users()->attach($user);
        $account = Account::create(['branch_id' => $branch->id, 'name' => 'Banco', 'type' => 'banco']);

        $this->actingAs($user, 'sanctum')->getJson('/api/branches')->assertJsonCount(1);
        $this->getJson('/api/registers')->assertJsonCount(1)->assertJsonPath('0.id', $register->id);
        $this->getJson("/api/registers/{$other->id}")->assertForbidden();
        $this->getJson("/api/accounts/{$account->id}")->assertForbidden();
        $this->getJson("/api/branches/{$branch->id}")->assertJsonMissingPath('accounts')->assertJsonCount(1, 'cash_registers');
        $this->actingAs($peer, 'sanctum')->getJson('/api/branches')->assertJsonCount(1);

        $branch->roles()->detach();
        $this->actingAs($user, 'sanctum')->getJson('/api/branches')->assertJsonCount(0);
        $this->getJson('/api/registers')->assertJsonCount(0);
        $this->getJson("/api/registers/{$register->id}")->assertForbidden();
        $this->getJson("/api/branches/{$branch->id}")->assertForbidden();
        $branch->roles()->attach(Role::findByName('supervisor', 'web')->id);
        $user->update(['role_id' => Role::findByName('supervisor', 'web')->id]);
        $this->actingAs($user->fresh(), 'sanctum');
        $this->getJson('/api/registers')->assertJsonCount(2);
        $this->getJson("/api/accounts/{$account->id}")->assertOk();
    }

    public function test_migration_converts_direct_and_register_assignments_to_roles_without_deleting_old_assignments(): void
    {
        $migration = require database_path('migrations/2026_11_16_140000_add_branch_role_access.php');
        $migration->down();
        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });
        $supervisor = User::factory()->create();
        $supervisor->update(['role_id' => Role::findByName('supervisor', 'web')->id]);
        $cashier = User::factory()->create();
        $cashier->update(['role_id' => Role::findByName('cajero', 'web')->id]);
        DB::table('model_has_roles')->insert([
            ['role_id' => $supervisor->role_id, 'model_id' => $supervisor->id, 'model_type' => $supervisor->getMorphClass()],
            ['role_id' => $cashier->role_id, 'model_id' => $cashier->id, 'model_type' => $cashier->getMorphClass()],
        ]);
        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR']);
        $branch->users()->attach($supervisor);
        $branch->users()->attach($cashier);
        $register = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja', 'code' => 'C1']);
        $register->users()->attach($cashier);
        $migration->up();

        $this->assertDatabaseHas('branch_role', ['branch_id' => $branch->id, 'role_id' => Role::findByName('supervisor')->id]);
        $this->assertDatabaseHas('branch_role', ['branch_id' => $branch->id, 'role_id' => Role::findByName('cajero')->id]);
        $this->assertDatabaseHas('branch_user', ['branch_id' => $branch->id, 'user_id' => $supervisor->id]);
        $this->assertDatabaseHas('cash_register_user', ['cash_register_id' => $register->id, 'user_id' => $cashier->id]);
        $this->assertSame(2, DB::table('branch_role')->count());
        Schema::drop('model_has_roles');
    }

    public function test_inactive_branch_rejects_new_accounts_and_registers_but_retains_existing_configuration(): void
    {
        $admin = User::factory()->create();
        $admin->update(['role_id' => Role::findByName('admin', 'web')->id]);
        $inactive = Branch::create(['name' => 'Centro', 'code' => 'CTR', 'is_active' => false]);
        $active = Branch::create(['name' => 'Norte', 'code' => 'NTE']);
        $account = Account::create(['branch_id' => $inactive->id, 'name' => 'Banco', 'type' => 'banco']);
        $register = CashRegister::create(['branch_id' => $inactive->id, 'name' => 'Caja', 'code' => 'C1']);
        $otherAccount = Account::create(['branch_id' => $active->id, 'name' => 'Otra cuenta', 'type' => 'banco']);
        $otherRegister = CashRegister::create(['branch_id' => $active->id, 'name' => 'Otra caja', 'code' => 'C2']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/accounts', ['branch_id' => $inactive->id, 'name' => 'Nueva', 'type' => 'banco'])->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        $this->postJson('/api/registers', ['branch_id' => $inactive->id, 'name' => 'Nueva', 'code' => 'C3'])->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        $this->putJson("/api/accounts/{$otherAccount->id}", ['branch_id' => $inactive->id])->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        $this->putJson("/api/registers/{$otherRegister->id}", ['branch_id' => $inactive->id])->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        $this->putJson("/api/accounts/{$account->id}", ['branch_id' => $inactive->id, 'name' => 'Banco editado'])->assertOk();
        $this->putJson("/api/registers/{$register->id}", ['branch_id' => $inactive->id, 'name' => 'Caja editada'])->assertOk();
        $this->getJson("/api/branches/{$inactive->id}")->assertOk()->assertJsonCount(1, 'accounts')->assertJsonCount(1, 'cash_registers');
        $this->putJson("/api/branches/{$inactive->id}", ['is_active' => true])->assertOk();
        $this->postJson('/api/accounts', ['branch_id' => $inactive->id, 'name' => 'Nueva', 'type' => 'banco'])->assertCreated();
        $this->postJson('/api/registers', ['branch_id' => $inactive->id, 'name' => 'Nueva', 'code' => 'C3'])->assertCreated();
    }

    public function test_admin_can_create_users_and_cannot_demote_the_last_admin(): void
    {
        $admin = User::factory()->create();
        $admin->update(['role_id' => Role::findByName('admin', 'web')->id]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users', [
                'name' => 'Cajero',
                'username' => 'cajero.1',
                'email' => 'cashier@example.com',
                'password' => 'a-strong-password',
                'password_confirmation' => 'a-strong-password',
                'role_id' => Role::findByName('cajero', 'web')->id,
            ])
            ->assertCreated()
            ->assertJsonPath('role.name', 'cajero');

        $this->putJson("/api/users/{$admin->id}", ['role_id' => Role::findByName('supervisor', 'web')->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No se puede quitar el último administrador activo.');
    }

    public function test_usernames_are_normalized_and_must_be_unique(): void
    {
        $admin = User::factory()->create();
        $admin->update(['role_id' => Role::findByName('admin', 'web')->id]);
        $payload = [
            'name' => 'Cajero',
            'username' => 'Caja.Norte',
            'email' => 'cashier@example.test',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
            'role_id' => Role::findByName('cajero', 'web')->id,
        ];

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users', $payload)
            ->assertCreated()
            ->assertJsonPath('username', 'caja.norte');

        $payload['email'] = 'another@example.test';
        $payload['username'] = 'CAJA.NORTE';
        $this->postJson('/api/users', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');
    }
}
