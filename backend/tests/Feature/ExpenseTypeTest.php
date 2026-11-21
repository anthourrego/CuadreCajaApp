<?php

namespace Tests\Feature;

use App\Models\ExpenseType;
use App\Models\User;
use Database\Seeders\ExpenseTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ExpenseTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_initial_types_are_global_and_reseeding_preserves_edits(): void
    {
        $this->seed(ExpenseTypeSeeder::class);
        $this->assertDatabaseCount('expense_types', 4);
        foreach (['Fletes', 'Gasto Local', 'Devolución', 'Vale'] as $name) {
            $this->assertDatabaseHas('expense_types', ['name' => $name, 'is_active' => true]);
        }

        $type = ExpenseType::where('name', 'Fletes')->firstOrFail();
        $type->update(['name' => 'Transporte', 'is_active' => false]);
        $this->seed(ExpenseTypeSeeder::class);

        $this->assertDatabaseCount('expense_types', 4);
        $this->assertDatabaseHas('expense_types', ['id' => $type->id, 'name' => 'Transporte', 'is_active' => false]);
        $this->assertDatabaseMissing('expense_types', ['name' => 'Fletes']);
    }

    public function test_active_types_are_available_to_authenticated_users_only(): void
    {
        $this->seed(ExpenseTypeSeeder::class);
        $type = ExpenseType::where('name', 'Vale')->firstOrFail();
        $type->update(['is_active' => false]);
        $user = User::factory()->create(['role_id' => Role::findByName('cajero', 'web')->id]);

        $this->getJson('/api/expense-types')->assertUnauthorized();
        $this->actingAs($user, 'sanctum')->getJson('/api/expense-types')
            ->assertOk()->assertJsonCount(3)->assertJsonMissing(['name' => 'Vale']);
        $this->getJson('/api/expense-types?include_inactive=1')->assertForbidden();
        $this->postJson('/api/expense-types', ['name' => 'Otro'])->assertForbidden();
        $this->putJson("/api/expense-types/{$type->id}", ['is_active' => true])->assertForbidden();
    }

    public function test_delegated_manager_can_create_edit_and_toggle_types(): void
    {
        $role = Role::create(['name' => 'gestor_gastos', 'guard_name' => 'web']);
        $role->givePermissionTo('expenses.manage_types');
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user, 'sanctum');
        $id = $this->postJson('/api/expense-types', ['name' => 'Papelería'])
            ->assertCreated()->assertJsonPath('is_active', true)->json('id');
        $this->postJson('/api/expense-types', ['name' => 'Papelería'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/expense-types', ['name' => '   '])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->putJson("/api/expense-types/{$id}", ['name' => 'Suministros'])->assertOk()->assertJsonPath('name', 'Suministros');
        $this->putJson("/api/expense-types/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('is_active', false);
        $this->getJson('/api/expense-types')->assertJsonCount(0);
        $this->getJson('/api/expense-types?include_inactive=1')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $id);
        $this->putJson("/api/expense-types/{$id}", ['is_active' => true])->assertOk()->assertJsonPath('is_active', true);
        $this->deleteJson("/api/expense-types/{$id}")->assertMethodNotAllowed();
    }

    public function test_admin_can_delegate_the_expense_type_permission(): void
    {
        $admin = User::factory()->create(['role_id' => Role::findByName('admin', 'web')->id]);
        $options = $this->actingAs($admin, 'sanctum')->getJson('/api/roles/options')->assertOk()->json('global');
        $this->assertSame('Administrar tipos de gasto', $options['expenses.manage_types']);
        $this->postJson('/api/roles', ['name' => 'gestor_gastos', 'permissions' => ['expenses.manage_types']])->assertCreated();
    }
}
