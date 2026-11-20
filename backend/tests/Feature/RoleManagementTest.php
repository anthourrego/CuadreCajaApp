<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_admin_manages_roles_and_only_deletes_unassigned_roles(): void
    {
        $admin = User::factory()->create();
        $admin->update(['role_id' => Role::findByName('admin', 'web')->id]);
        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/roles', [
            'name' => 'encargado', 'permissions' => ['branches.view', 'registers.view_assigned'],
        ])->assertCreated()->assertJsonMissingPath('branch_ids');
        $this->postJson('/api/roles', [
            'name' => 'legacy', 'permissions' => [], 'branch_ids' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('branch_ids');
        $id = $response->json('id');
        $user = User::factory()->create();
        $user->update(['role_id' => Role::findByName('encargado', 'web')->id]);
        $this->deleteJson("/api/roles/{$id}")->assertUnprocessable();
        $this->putJson("/api/roles/{$id}", [
            'name' => 'encargado', 'permissions' => [],
        ])->assertOk()->assertJsonMissingPath('branch_ids');
        $this->deleteJson("/api/roles/{$id}")->assertUnprocessable();
        $user->update(['role_id' => Role::findByName('cajero', 'web')->id]);
        $this->deleteJson("/api/roles/{$id}")->assertOk();
        $this->assertDatabaseMissing('roles', ['id' => $id]);

        $adminRole = Role::findByName('admin', 'web');
        $this->putJson("/api/roles/{$adminRole->id}", ['name' => 'admin', 'permissions' => []])->assertForbidden();
        $this->deleteJson("/api/roles/{$adminRole->id}")->assertForbidden();
    }

    public function test_permissions_and_branches_follow_the_user(): void
    {
        $user = User::factory()->create();
        $other = Role::create(['name' => 'otro', 'guard_name' => 'web']);
        $other->givePermissionTo(['branches.view', 'registers.view_assigned']);
        $user->update(['role_id' => $other->id]);
        $b = Branch::create(['name' => 'B', 'code' => 'B']);
        $user->branches()->attach($b);
        $account = Account::create(['branch_id' => $b->id, 'name' => 'Banco', 'type' => 'banco']);
        $assigned = CashRegister::create(['branch_id' => $b->id, 'name' => 'Asignada', 'code' => 'C1']);
        $unassigned = CashRegister::create(['branch_id' => $b->id, 'name' => 'Otra', 'code' => 'C2']);
        $assigned->users()->attach($user);

        $this->actingAs($user, 'sanctum')->getJson('/api/registers')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $assigned->id);
        $this->getJson("/api/registers/{$unassigned->id}")->assertForbidden();
        $this->getJson("/api/accounts/{$account->id}")->assertForbidden();
        $this->getJson("/api/branches/{$b->id}")->assertOk()->assertJsonMissingPath('accounts')->assertJsonCount(1, 'cash_registers');
        $user->branches()->detach($b);
        $this->getJson("/api/registers/{$assigned->id}")->assertForbidden();
        $this->getJson("/api/branches/{$b->id}")->assertForbidden();
    }

    public function test_delegation_cannot_grant_privileges_or_branches_outside_actor_scope(): void
    {
        $actor = User::factory()->create();
        $manager = Role::create(['name' => 'gestor', 'guard_name' => 'web']);
        $manager->givePermissionTo(['users.manage', 'roles.manage', 'branches.view', 'registers.view_assigned']);
        $actor->update(['role_id' => $manager->id]);
        $a = Branch::create(['name' => 'A', 'code' => 'A']);
        $b = Branch::create(['name' => 'B', 'code' => 'B']);
        $actor->branches()->attach($a);
        $powerful = Role::create(['name' => 'potente', 'guard_name' => 'web']);
        $powerful->givePermissionTo('registers.view_all');
        $target = User::factory()->create();
        $target->update(['role_id' => $powerful->id]);
        $target->branches()->attach($b);
        $admin = User::factory()->create();
        $admin->update(['role_id' => Role::findByName('admin', 'web')->id]);

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/roles', ['name' => 'exceso', 'permissions' => ['registers.view_all']])->assertForbidden();
        $this->putJson("/api/roles/{$powerful->id}", ['name' => 'potente', 'permissions' => []])->assertForbidden();
        $this->putJson("/api/users/{$target->id}", ['password' => 'password-seguro-123', 'password_confirmation' => 'password-seguro-123'])->assertForbidden();
        $this->putJson("/api/users/{$actor->id}", ['role_id' => Role::findByName('admin', 'web')->id])->assertForbidden();
        $this->postJson('/api/users', [
            'name' => 'Nuevo', 'username' => 'nuevo', 'email' => 'nuevo@example.test',
            'password' => 'password-seguro-123', 'password_confirmation' => 'password-seguro-123',
            'role_id' => Role::findByName('admin', 'web')->id, 'branch_ids' => [],
        ])->assertForbidden();
        $this->putJson("/api/users/{$admin->id}", ['is_active' => false])->assertForbidden();

        $response = $this->postJson('/api/roles', ['name' => 'delegado', 'permissions' => ['branches.view']])->assertCreated();
        $managed = User::factory()->create();
        $this->putJson("/api/users/{$managed->id}", ['role_id' => $response->json('id'), 'branch_ids' => [$a->id]])->assertOk();
        $this->postJson('/api/users', [
            'name' => 'Fuera', 'username' => 'fuera', 'email' => 'fuera@example.test',
            'password' => 'password-seguro-123', 'password_confirmation' => 'password-seguro-123',
            'role_id' => $response->json('id'), 'branch_ids' => [$b->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('branch_ids');
        $this->getJson('/api/roles')->assertJsonFragment(['id' => $response->json('id')])->assertJsonMissing(['name' => 'admin']);
    }

    public function test_editing_accounts_and_registers_requires_access_to_both_branches(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
        $role->givePermissionTo(['accounts.update', 'registers.update']);
        $user->update(['role_id' => $role->id]);
        $a = Branch::create(['name' => 'A', 'code' => 'A']);
        $b = Branch::create(['name' => 'B', 'code' => 'B']);
        $user->branches()->attach($a);
        $account = Account::create(['branch_id' => $a->id, 'name' => 'Banco', 'type' => 'banco']);
        $register = CashRegister::create(['branch_id' => $a->id, 'name' => 'Caja', 'code' => 'C']);
        $this->actingAs($user, 'sanctum')->putJson("/api/accounts/{$account->id}", ['branch_id' => $b->id])->assertForbidden();
        $this->putJson("/api/registers/{$register->id}", ['branch_id' => $b->id])->assertForbidden();
        $this->putJson("/api/accounts/{$account->id}", ['name' => 'Nuevo'])->assertOk();
        $this->putJson("/api/registers/{$register->id}", ['name' => 'Nueva'])->assertOk();
    }

    public function test_reseeding_does_not_restore_customized_permissions(): void
    {
        $supervisor = Role::findByName('supervisor');
        $supervisor->syncPermissions(['branches.view']);
        $this->seed(RoleSeeder::class);
        $this->assertSame(['branches.view'], $supervisor->fresh()->permissions->pluck('name')->all());
    }

    public function test_permission_migration_initializes_existing_roles_without_losing_user_branch_assignments(): void
    {
        $supervisor = Role::findByName('supervisor', 'web');
        $cashier = Role::findByName('cajero', 'web');
        $supervisor->syncPermissions([]);
        $cashier->syncPermissions([]);
        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR']);
        $supervisorUser = User::factory()->create(['role_id' => $supervisor->id]);
        $cashierUser = User::factory()->create(['role_id' => $cashier->id]);
        $branch->users()->attach([$supervisorUser->id, $cashierUser->id]);

        $migration = require database_path('migrations/2026_11_17_000000_initialize_role_permissions.php');
        $migration->up();

        $this->assertTrue($supervisor->fresh()->hasPermissionTo('accounts.view', 'web'));
        $this->assertTrue($cashier->fresh()->hasPermissionTo('registers.view_assigned', 'web'));
        $this->assertFalse($cashier->fresh()->hasPermissionTo('registers.view_all', 'web'));
        $this->assertDatabaseCount('branch_user', 2);
    }
}
