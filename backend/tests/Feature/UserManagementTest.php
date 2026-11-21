<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_create_update_deactivate_and_reactivate_a_user(): void
    {
        $admin = User::factory()->create();
        $admin->update(['role_id' => Role::findByName('admin', 'web')->id]);
        $token = $admin->createToken('test')->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];

        $response = $this->withHeaders($headers)->postJson('/api/users', [
            'name' => 'Cajero',
            'username' => 'cajero',
            'email' => 'cashier@example.test',
            'password' => 'password-seguro-123',
            'password_confirmation' => 'password-seguro-123',
            'role_id' => Role::findByName('cajero', 'web')->id,
            'branch_ids' => [],
        ])->assertCreated()->assertJsonPath('is_active', true);

        $id = $response->json('id');
        $this->withHeaders($headers)->putJson("/api/users/{$id}", [
            'name' => 'Supervisor',
            'role_id' => Role::findByName('supervisor', 'web')->id,
        ])->assertOk()->assertJsonPath('name', 'Supervisor')->assertJsonPath('role.name', 'supervisor');

        $user = User::findOrFail($id);
        $userToken = $user->createToken('test')->plainTextToken;
        $this->withHeaders($headers)->putJson("/api/users/{$id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('is_active', false);
        $this->assertDatabaseHas('users', ['id' => $id, 'is_active' => false]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $id]);
        app('auth')->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$userToken])->getJson('/api/user')->assertUnauthorized();
        $this->postJson('/api/login', ['username' => 'cajero', 'password' => 'password-seguro-123'])->assertUnauthorized();

        $this->withHeaders($headers)->putJson("/api/users/{$id}", ['is_active' => true])
            ->assertOk()->assertJsonPath('is_active', true);
        $this->postJson('/api/login', ['username' => 'cajero', 'password' => 'password-seguro-123'])->assertOk();
    }

    public function test_inactive_accounts_cannot_use_even_an_existing_token(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_only_admins_can_manage_users_and_an_admin_cannot_disable_themselves(): void
    {
        $cashier = User::factory()->create();
        $cashier->update(['role_id' => Role::findByName('cajero', 'web')->id]);
        $other = User::factory()->create();
        $this->actingAs($cashier, 'sanctum')->putJson("/api/users/{$other->id}", ['is_active' => false])->assertForbidden();

        $admin = User::factory()->create();
        $admin->update(['role_id' => Role::findByName('admin', 'web')->id]);
        $this->actingAs($admin, 'sanctum')->putJson("/api/users/{$admin->id}", ['is_active' => false])
            ->assertUnprocessable();
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'is_active' => true]);
    }

    public function test_inactive_admin_does_not_count_as_the_last_active_admin(): void
    {
        $admin = User::factory()->create();
        $admin->update(['role_id' => Role::findByName('admin', 'web')->id]);
        $inactive = User::factory()->create(['is_active' => false]);
        $inactive->update(['role_id' => Role::findByName('admin', 'web')->id]);

        $this->actingAs($admin, 'sanctum')->putJson("/api/users/{$admin->id}", ['role_id' => Role::findByName('cajero', 'web')->id])
            ->assertUnprocessable()->assertJsonPath('message', 'No se puede quitar el último administrador activo.');
    }

    public function test_changing_a_password_revokes_existing_tokens(): void
    {
        $admin = User::factory()->create();
        $admin->update(['role_id' => Role::findByName('admin', 'web')->id]);
        $user = User::factory()->create();
        $user->createToken('test');

        $this->actingAs($admin, 'sanctum')->putJson("/api/users/{$user->id}", [
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
    }

    public function test_users_without_management_permissions_can_edit_only_their_own_profile(): void
    {
        $user = User::factory()->create(['role_id' => Role::findByName('cajero', 'web')->id]);
        $other = User::factory()->create();
        $this->putJson('/api/user/profile', [])->assertUnauthorized();

        $this->actingAs($user, 'sanctum')->putJson('/api/user/profile', [
            'name' => 'Nuevo nombre', 'email' => 'nuevo@example.test',
            'role_id' => Role::findByName('admin', 'web')->id, 'is_active' => false,
        ])->assertOk()->assertJsonPath('name', 'Nuevo nombre')
            ->assertJsonPath('username', $user->username)->assertJsonPath('role.name', 'cajero')
            ->assertJsonPath('is_active', true);
        $this->putJson('/api/user/profile', [
            'name' => 'Nuevo nombre', 'email' => $other->email,
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->putJson('/api/user/profile', [
            'name' => 'Intento', 'username' => 'otro.usuario', 'email' => 'nuevo@example.test',
        ])->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->assertSame('Nuevo nombre', $user->fresh()->name);
        $this->assertSame($user->username, $user->fresh()->username);
    }

    public function test_admin_cannot_change_an_existing_username(): void
    {
        $admin = User::factory()->create(['role_id' => Role::findByName('admin', 'web')->id]);
        $user = User::factory()->create();

        $this->actingAs($admin, 'sanctum')->putJson("/api/users/{$user->id}", [
            'username' => 'nuevo.usuario', 'name' => 'Intento',
        ])->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->assertSame($user->username, $user->fresh()->username);
        $this->assertSame($user->name, $user->fresh()->name);
        $this->putJson("/api/users/{$user->id}", ['name' => 'Actualizado'])
            ->assertOk()->assertJsonPath('username', $user->username);
    }

    public function test_changing_own_password_requires_current_password_and_revokes_all_tokens(): void
    {
        $user = User::factory()->create(['password' => 'contraseña-anterior']);
        $token = $user->createToken('test')->plainTextToken;
        $user->createToken('other');
        $payload = [
            'current_password' => 'incorrecta',
            'password' => 'contraseña-nueva-123',
            'password_confirmation' => 'contraseña-nueva-123',
        ];
        $this->withToken($token)->putJson('/api/user/password', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertSame(2, $user->tokens()->count());
        $payload['current_password'] = 'contraseña-anterior';
        $payload['password_confirmation'] = 'no-coincide';
        $this->putJson('/api/user/password', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $payload['password_confirmation'] = $payload['password'];
        $this->putJson('/api/user/password', $payload)->assertOk();
        $this->assertTrue(Hash::check($payload['password'], $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
        $this->postJson('/api/login', ['username' => $user->username, 'password' => $payload['password']])->assertOk();
    }

    public function test_users_require_exactly_one_valid_role(): void
    {
        $admin = User::factory()->create(['role_id' => Role::findByName('admin', 'web')->id]);
        $user = User::factory()->create();
        $payload = [
            'name' => 'Nuevo', 'username' => 'nuevo', 'email' => 'nuevo@example.test',
            'password' => 'password-seguro-123', 'password_confirmation' => 'password-seguro-123',
        ];
        $this->actingAs($admin, 'sanctum')->postJson('/api/users', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('role_id');
        $this->postJson('/api/users', $payload + ['role_id' => 99999])
            ->assertUnprocessable()->assertJsonValidationErrors('role_id');
        $this->putJson("/api/users/{$user->id}", ['roles' => ['admin', 'cajero']])
            ->assertUnprocessable()->assertJsonValidationErrors('roles');
        $this->putJson("/api/users/{$user->id}", ['role_id' => Role::findByName('supervisor', 'web')->id])
            ->assertOk()->assertJsonPath('role.name', 'supervisor');
        $this->assertSame(Role::findByName('supervisor', 'web')->id, $user->fresh()->role_id);
    }

    public function test_branch_assignments_are_per_user_and_default_branch_must_be_assigned_and_active(): void
    {
        $admin = User::factory()->create(['role_id' => Role::findByName('admin', 'web')->id]);
        $role = Role::findByName('cajero', 'web');
        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR']);
        $inactive = Branch::create(['name' => 'Norte', 'code' => 'NTE', 'is_active' => false]);
        $other = Branch::create(['name' => 'Sur', 'code' => 'SUR']);
        $payload = [
            'name' => 'Cajero', 'username' => 'cajero.prueba', 'email' => 'cajero@example.test',
            'password' => 'password-seguro-123', 'password_confirmation' => 'password-seguro-123',
            'role_id' => $role->id, 'branch_ids' => [$branch->id],
        ];

        $this->actingAs($admin, 'sanctum')->getJson('/api/users/branch-options')
            ->assertOk()->assertJsonCount(3)->assertJsonFragment(['id' => $inactive->id, 'is_active' => false]);
        $this->postJson('/api/users', $payload + ['default_branch_id' => $other->id])
            ->assertUnprocessable()->assertJsonValidationErrors('default_branch_id');
        $this->postJson('/api/users', $payload + ['default_branch_id' => $inactive->id])
            ->assertUnprocessable()->assertJsonValidationErrors('default_branch_id');
        $this->postJson('/api/users', array_replace($payload, ['branch_ids' => [$other->id], 'default_branch_id' => $branch->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('default_branch_id');
        $id = $this->postJson('/api/users', $payload + ['default_branch_id' => $branch->id])
            ->assertCreated()->assertJsonPath('default_branch_id', $branch->id)
            ->assertJsonPath('branch_ids.0', $branch->id)->json('id');
        $this->assertDatabaseHas('branch_user', ['branch_id' => $branch->id, 'user_id' => $id]);
        $this->putJson("/api/users/{$id}", ['name' => 'Cajero editado'])
            ->assertOk()->assertJsonPath('default_branch_id', $branch->id);
        $this->putJson("/api/users/{$id}", ['branch_ids' => []])
            ->assertOk()->assertJsonPath('default_branch_id', null)->assertJsonCount(0, 'branch_ids');
        $this->putJson("/api/users/{$id}", ['default_branch_id' => null])
            ->assertOk()->assertJsonPath('default_branch_id', null);
    }

    public function test_user_branch_membership_limits_registers_and_profile_default_branch(): void
    {
        $role = Role::findByName('cajero', 'web');
        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR']);
        $other = Branch::create(['name' => 'Norte', 'code' => 'NTE']);
        $user = User::factory()->create(['role_id' => $role->id, 'default_branch_id' => $branch->id]);
        $user->branches()->attach([$branch->id, $other->id]);
        $visible = CashRegister::create(['branch_id' => $other->id, 'name' => 'Asignada', 'code' => 'A1']);
        $unassigned = CashRegister::create(['branch_id' => $branch->id, 'name' => 'No asignada', 'code' => 'A1']);

        $this->actingAs($user, 'sanctum')->getJson('/api/user')
            ->assertOk()->assertJsonPath('default_branch.id', $branch->id)->assertJsonCount(2, 'branches');
        $this->getJson('/api/registers')->assertOk()->assertJsonCount(2);
        $this->getJson('/api/registers?branch_id='.$branch->id)->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $unassigned->id);
        $this->getJson('/api/users/branch-options')->assertForbidden();

        $branch->update(['is_active' => false]);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('default_branch', null);
        $branch->update(['is_active' => true]);
        $user->branches()->detach($branch);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('default_branch', null);
        $this->getJson('/api/registers')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $visible->id);
        $this->getJson("/api/registers/{$unassigned->id}")->assertForbidden();
    }

    public function test_manager_can_only_assign_users_to_the_managers_own_branches(): void
    {
        $managerRole = Role::create(['name' => 'gestor', 'guard_name' => 'web']);
        $managerRole->givePermissionTo(['users.manage', 'branches.view']);
        $manager = User::factory()->create(['role_id' => $managerRole->id]);
        $allowed = Branch::create(['name' => 'Centro', 'code' => 'CTR']);
        $outside = Branch::create(['name' => 'Norte', 'code' => 'NTE']);
        $manager->branches()->attach($allowed);
        $targetRole = Role::create(['name' => 'operador', 'guard_name' => 'web']);
        $targetRole->givePermissionTo('branches.view');
        $target = User::factory()->create(['role_id' => $targetRole->id]);
        $target->branches()->attach($outside);

        $this->actingAs($manager, 'sanctum')->getJson('/api/users')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $manager->id);
        $this->postJson('/api/users', [
            'name' => 'Nuevo', 'username' => 'nuevo', 'email' => 'nuevo@example.test',
            'password' => 'password-seguro-123', 'password_confirmation' => 'password-seguro-123',
            'role_id' => $targetRole->id, 'branch_ids' => [$outside->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('branch_ids');
        $this->putJson("/api/users/{$target->id}", ['branch_ids' => [$allowed->id]])->assertForbidden();
    }
}
