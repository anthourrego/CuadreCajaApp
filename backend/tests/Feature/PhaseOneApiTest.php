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

    public function test_new_database_uses_the_consolidated_schema(): void
    {
        $this->assertFalse(Schema::hasTable('cash_register_user'));
        $this->assertFalse(Schema::hasTable('model_has_roles'));
        $this->assertTrue(Schema::hasTable('model_has_permissions'));
        $columns = Schema::getColumnListing('cash_reconciliations');
        $this->assertSame('expenses_total', $columns[array_search('qr', $columns, true) + 1]);
        $users = collect(Schema::getColumns('users'))->keyBy('name');
        $this->assertFalse($users['role_id']['nullable']);
        $this->assertTrue($users['default_branch_id']['nullable']);
        $foreignKeys = collect(Schema::getForeignKeys('users'));
        $this->assertTrue($foreignKeys->contains(fn ($key) => $key['columns'] === ['role_id'] && $key['foreign_table'] === 'roles'));
        $this->assertTrue($foreignKeys->contains(fn ($key) => $key['columns'] === ['default_branch_id'] && $key['foreign_table'] === 'branches'));
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

    public function test_api_version_is_available_only_to_authenticated_users(): void
    {
        config(['app.version' => '0.1.0']);

        $this->getJson('/api/version')->assertUnauthorized();

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/version')
            ->assertOk()
            ->assertExactJson(['version' => '0.1.0']);
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
        $assignedBranch->users()->attach($user);

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
        ])->assertCreated()
            ->assertJsonMissingPath('users');
    }

    public function test_cashiers_can_read_all_registers_in_assigned_branches_but_not_accounts(): void
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
        $branch->users()->attach($cashier);
        $account = Account::create([
            'branch_id' => $branch->id,
            'name' => 'Caja menor',
            'type' => 'caja_menor',
        ]);

        $this->actingAs($cashier, 'sanctum')
            ->getJson('/api/registers')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment(['id' => $assignedRegister->id])
            ->assertJsonFragment(['id' => $otherRegister->id]);

        $this->getJson("/api/registers/{$otherRegister->id}")->assertOk();
        $this->getJson('/api/accounts')->assertOk()->assertJsonCount(0);
        $this->getJson("/api/accounts/{$account->id}")->assertForbidden();

        $this->getJson("/api/branches/{$branch->id}")
            ->assertOk()
            ->assertJsonMissingPath('accounts')
            ->assertJsonCount(2, 'cash_registers')
            ->assertJsonFragment(['id' => $otherRegister->id]);
    }

    public function test_register_options_and_edit_actions_follow_permissions_per_branch(): void
    {
        $role = Role::findByName('supervisor', 'web');
        $role->givePermissionTo(['registers.create', 'registers.update']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $allowed = Branch::create(['name' => 'Centro', 'code' => 'CTR']);
        $inactive = Branch::create(['name' => 'Inactiva', 'code' => 'INA', 'is_active' => false]);
        $other = Branch::create(['name' => 'Norte', 'code' => 'NTE']);
        $user->branches()->attach([$allowed->id, $inactive->id]);
        $register = CashRegister::create(['branch_id' => $allowed->id, 'name' => 'Caja', 'code' => 'C1']);
        $otherRegister = CashRegister::create(['branch_id' => $other->id, 'name' => 'Otra caja', 'code' => 'C1']);

        $this->actingAs($user, 'sanctum')->getJson('/api/registers/options')
            ->assertOk()->assertJsonCount(2)
            ->assertJsonPath('0.id', $allowed->id)
            ->assertJsonPath('1.is_active', false)
            ->assertJsonMissing(['id' => $other->id]);
        $this->getJson('/api/registers')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.id', $register->id)->assertJsonPath('0.can_update', true);
        $this->getJson("/api/registers/{$otherRegister->id}")->assertForbidden();
        $this->getJson('/api/registers?branch_id='.$other->id)->assertOk()->assertJsonCount(0);
        $this->putJson("/api/registers/{$register->id}", ['name' => 'Caja editada'])
            ->assertOk()->assertJsonPath('name', 'Caja editada');

        $role->revokePermissionTo(['registers.create', 'registers.update']);
        $this->actingAs($user->fresh(), 'sanctum')->getJson('/api/registers/options')->assertForbidden();
        $this->getJson('/api/registers')->assertOk()->assertJsonPath('0.can_update', false);
        $this->putJson("/api/registers/{$register->id}", ['is_active' => false])->assertForbidden();

        $role->givePermissionTo('registers.create');
        $role->revokePermissionTo('branches.view');
        $this->actingAs($user->fresh(), 'sanctum')->getJson('/api/registers/options')
            ->assertOk()->assertJsonCount(2);
        $this->getJson('/api/registers')->assertOk()->assertJsonCount(1);
    }

    public function test_register_management_preserves_branch_and_filters_by_branch(): void
    {
        $admin = User::factory()->create(['role_id' => Role::findByName('admin', 'web')->id]);
        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR']);
        $other = Branch::create(['name' => 'Norte', 'code' => 'NTE']);
        $payload = ['branch_id' => $branch->id, 'name' => 'Caja', 'code' => 'C1'];
        $id = $this->actingAs($admin, 'sanctum')->postJson('/api/registers', $payload)
            ->assertCreated()->json('id');
        $this->postJson('/api/registers', $payload)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/registers', [...$payload, 'branch_id' => $other->id])->assertCreated();
        $this->putJson("/api/registers/{$id}", ['name' => 'Caja editada', 'code' => 'C2'])
            ->assertOk()->assertJsonPath('branch_id', $branch->id)->assertJsonMissingPath('users');
        $this->putJson("/api/registers/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('is_active', false);
        $this->putJson("/api/registers/{$id}", ['is_active' => true])->assertOk()->assertJsonPath('is_active', true);
        $this->getJson("/api/registers?branch_id={$branch->id}")
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $id)->assertJsonPath('0.code', 'C2');
        $this->postJson('/api/registers', [...$payload, 'code' => 'C3', 'user_ids' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('user_ids');
        $this->putJson("/api/registers/{$id}", ['user_ids' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('user_ids');
    }

    public function test_removing_register_assignments_requires_the_legacy_table_to_be_empty(): void
    {
        $this->assertFalse(Schema::hasTable('cash_register_user'));
        Schema::create('cash_register_user', function (Blueprint $table) {
            $table->unsignedBigInteger('cash_register_id');
            $table->unsignedBigInteger('user_id');
        });
        DB::table('cash_register_user')->insert(['cash_register_id' => 1, 'user_id' => 1]);
        $migration = require database_path('migrations/2026_11_21_140000_remove_cash_register_user.php');

        try {
            $migration->up();
            $this->fail('La migración debe proteger las asignaciones existentes.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('contiene asignaciones', $exception->getMessage());
        }

        $this->assertDatabaseCount('cash_register_user', 1);
        DB::table('cash_register_user')->delete();
        $migration->up();
        $this->assertFalse(Schema::hasTable('cash_register_user'));
        $migration->up();
        $this->assertFalse(Schema::hasTable('cash_register_user'));
    }

    public function test_branch_updates_preserve_user_assignments(): void
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
        $this->assertDatabaseHas('branch_user', ['branch_id' => $id, 'user_id' => $admin->id]);
        Branch::findOrFail($id)->users()->attach($supervisor);

        $this->postJson('/api/branches', ['name' => 'Duplicada', 'code' => 'CTR'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->putJson("/api/branches/{$id}", ['name' => 'Centro nuevo'])
            ->assertOk()->assertJsonPath('name', 'Centro nuevo');
        $this->assertDatabaseHas('branch_user', ['branch_id' => $id, 'user_id' => $supervisor->id]);
        $this->putJson("/api/branches/{$id}", ['roles' => ['admin']])->assertUnprocessable();
        $this->putJson("/api/branches/{$id}", ['user_ids' => [$supervisor->id]])->assertUnprocessable();
        $this->putJson("/api/branches/{$id}", ['user_ids' => []])->assertUnprocessable();
        $this->putJson("/api/branches/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('is_active', false);
        $this->assertDatabaseHas('branch_user', ['branch_id' => $id, 'user_id' => $supervisor->id]);
        $this->putJson("/api/branches/{$id}", ['is_active' => true])->assertOk()->assertJsonPath('is_active', true);

        $this->actingAs($supervisor, 'sanctum')->putJson("/api/branches/{$id}", ['name' => 'No autorizado'])->assertForbidden();
    }

    public function test_user_branch_assignments_limit_role_permissions_and_register_assignment(): void
    {
        $user = User::factory()->create();
        $user->update(['role_id' => Role::findByName('cajero', 'web')->id]);
        $peer = User::factory()->create();
        $peer->update(['role_id' => Role::findByName('cajero', 'web')->id]);
        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR']);
        $branch->users()->attach([$user->id, $peer->id]);
        $register = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja', 'code' => 'C1']);
        $other = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Otra', 'code' => 'C2']);
        $account = Account::create(['branch_id' => $branch->id, 'name' => 'Banco', 'type' => 'banco']);

        $this->actingAs($user, 'sanctum')->getJson('/api/branches')->assertJsonCount(1);
        $this->getJson('/api/registers')->assertJsonCount(2);
        $this->getJson("/api/registers/{$other->id}")->assertOk();
        $this->getJson("/api/accounts/{$account->id}")->assertForbidden();
        $this->getJson("/api/branches/{$branch->id}")->assertJsonMissingPath('accounts')->assertJsonCount(2, 'cash_registers');
        $this->actingAs($peer, 'sanctum')->getJson('/api/branches')->assertJsonCount(1);

        $branch->users()->detach($user);
        $this->actingAs($user, 'sanctum')->getJson('/api/branches')->assertJsonCount(0);
        $this->getJson('/api/registers')->assertJsonCount(0);
        $this->getJson("/api/registers/{$register->id}")->assertForbidden();
        $this->getJson("/api/branches/{$branch->id}")->assertForbidden();
        $branch->users()->attach($user);
        $user->update(['role_id' => Role::findByName('supervisor', 'web')->id]);
        $this->actingAs($user->fresh(), 'sanctum');
        $this->getJson('/api/registers')->assertJsonCount(2);
        $this->getJson("/api/accounts/{$account->id}")->assertOk();
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
                'branch_ids' => [],
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
            'branch_ids' => [],
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
