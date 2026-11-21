<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashReconciliation;
use App\Models\CashRegister;
use App\Models\ExpenseType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CashReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-24 12:00:00', 'America/Bogota'));
        $this->seed(RoleSeeder::class);
    }

    private function payload(CashRegister $register, ExpenseType $type): array
    {
        return [
            'cash_register_id' => $register->id, 'date' => '2026-11-21',
            'sales' => '15000000.00', 'cash' => '10000000.00', 'bank' => '4004500.00', 'qr' => '25000.00',
            'expenses' => [['expense_type_id' => $type->id, 'detail' => 'Transporte', 'amount' => '995500.00']],
        ];
    }

    public function test_create_calculates_exact_difference_and_enforces_one_per_register_per_day(): void
    {
        $admin = User::factory()->create(['role_id' => Role::findByName('admin', 'web')->id]);
        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR']);
        $register = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja', 'code' => '1']);
        $type = ExpenseType::create(['name' => 'Fletes']);
        $payload = $this->payload($register, $type);

        $this->actingAs($admin, 'sanctum')->postJson('/api/reconciliations', $payload)
            ->assertCreated()->assertJsonPath('expenses_total', '995500.00')->assertJsonPath('difference', '25000.00')
            ->assertJsonPath('expenses.0.type_name', 'Fletes')->assertJsonPath('version', 1);
        $this->postJson('/api/reconciliations', $payload)->assertUnprocessable()->assertJsonValidationErrors('date');
        $payload['date'] = '2026-11-22';
        $payload['sales'] = '1.00';
        $payload['cash'] = '0.10';
        $payload['bank'] = '0.20';
        $payload['qr'] = '0.30';
        $payload['expenses'][0]['amount'] = '0.01';
        $this->postJson('/api/reconciliations', $payload)
            ->assertCreated()->assertJsonPath('expenses_total', '0.01')->assertJsonPath('difference', '-0.39');
        $payload['date'] = '2026-11-23';
        $payload['sales'] = '0.61';
        $this->postJson('/api/reconciliations', $payload)->assertCreated()->assertJsonPath('difference', '0.00');
    }

    public function test_expense_totals_are_persisted_on_create_edit_removal_and_rollback(): void
    {
        $admin = User::factory()->create(['role_id' => Role::findByName('admin', 'web')->id]);
        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR']);
        $register = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja', 'code' => '1']);
        $type = ExpenseType::create(['name' => 'Fletes']);
        $inactive = ExpenseType::create(['name' => 'Inactivo', 'is_active' => false]);
        $payload = $this->payload($register, $type);
        $payload['expenses'] = [
            ['expense_type_id' => $type->id, 'amount' => '0.10'],
            ['expense_type_id' => $type->id, 'amount' => '0.20'],
        ];
        $this->actingAs($admin, 'sanctum');
        $created = $this->postJson('/api/reconciliations', $payload)->assertCreated()
            ->assertJsonPath('expenses_total', '0.30')->json();
        $this->assertSame('0.30', CashReconciliation::findOrFail($created['id'])->expenses_total);

        $edit = ['version' => 1, 'sales' => '1', 'cash' => '0', 'bank' => '0', 'qr' => '0',
            'expenses' => [
                ['id' => $created['expenses'][0]['id'], 'expense_type_id' => $type->id, 'amount' => '0.15'],
                ['expense_type_id' => $type->id, 'amount' => '0.07'],
            ]];
        $updated = $this->putJson('/api/reconciliations/'.$created['id'], $edit)->assertOk()
            ->assertJsonPath('expenses_total', '0.22')->assertJsonPath('difference', '-0.78')->json();
        $this->assertSame('0.22', CashReconciliation::findOrFail($created['id'])->expenses_total);
        $this->assertDatabaseMissing('cash_reconciliation_expenses', ['id' => $created['expenses'][1]['id']]);

        $edit['version'] = 2;
        $edit['expenses'][0]['amount'] = '0.99';
        $edit['expenses'][1] = ['expense_type_id' => $inactive->id, 'amount' => '1.00'];
        $this->putJson('/api/reconciliations/'.$created['id'], $edit)
            ->assertUnprocessable()->assertJsonValidationErrors('expenses.1.expense_type_id');
        $this->assertSame('0.22', CashReconciliation::findOrFail($created['id'])->expenses_total);
        $this->getJson('/api/reconciliations/'.$created['id'])->assertOk()
            ->assertJsonPath('expenses_total', '0.22')->assertJsonPath('expenses.0.amount', '0.15')
            ->assertJsonPath('version', $updated['version']);

        $edit['expenses'] = [];
        $this->putJson('/api/reconciliations/'.$created['id'], $edit)->assertOk()->assertJsonPath('expenses_total', '0.00');
        $this->assertSame('0.00', CashReconciliation::findOrFail($created['id'])->expenses_total);
        $payload['date'] = '2026-11-20';
        $payload['expenses'] = [];
        $empty = $this->postJson('/api/reconciliations', $payload)->assertCreated()->assertJsonPath('expenses_total', '0.00')->json();
        $this->assertSame('0.00', CashReconciliation::findOrFail($empty['id'])->expenses_total);
    }

    public function test_expense_total_supports_the_maximum_number_and_size_of_expenses(): void
    {
        $admin = User::factory()->create(['role_id' => Role::findByName('admin', 'web')->id]);
        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR']);
        $register = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja', 'code' => '1']);
        $type = ExpenseType::create(['name' => 'Fletes']);
        $payload = $this->payload($register, $type);
        $payload['expenses'] = array_fill(0, 100, ['expense_type_id' => $type->id, 'amount' => '999999999999.99']);
        $created = $this->actingAs($admin, 'sanctum')->postJson('/api/reconciliations', $payload)
            ->assertCreated()->assertJsonPath('expenses_total', '99999999999999.00')->json();
        $this->assertSame('99999999999999.00', CashReconciliation::findOrFail($created['id'])->expenses_total);
    }

    public function test_availability_checks_register_and_date_with_create_permission_and_branch_access(): void
    {
        $admin = User::factory()->create(['role_id' => Role::findByName('admin', 'web')->id]);
        $role = Role::create(['name' => 'registrador', 'guard_name' => 'web']);
        $role->givePermissionTo('reconciliations.create');
        $user = User::factory()->create(['role_id' => $role->id]);
        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR']);
        $other = Branch::create(['name' => 'Norte', 'code' => 'NTE']);
        $user->branches()->attach($branch);
        $register = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja', 'code' => '1']);
        $another = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja 2', 'code' => '2']);
        $outside = CashRegister::create(['branch_id' => $other->id, 'name' => 'Otra', 'code' => '1']);
        $type = ExpenseType::create(['name' => 'Fletes']);
        $this->actingAs($admin, 'sanctum')->postJson('/api/reconciliations', $this->payload($register, $type))->assertCreated();

        $this->actingAs($user, 'sanctum');
        $this->getJson("/api/reconciliations/availability?cash_register_id={$register->id}&date=2026-11-21")
            ->assertOk()->assertExactJson(['available' => false]);
        $this->getJson("/api/reconciliations/availability?cash_register_id={$register->id}&date=2026-11-22")
            ->assertOk()->assertExactJson(['available' => true]);
        $this->getJson("/api/reconciliations/availability?cash_register_id={$another->id}&date=2026-11-21")
            ->assertOk()->assertExactJson(['available' => true]);
        $this->getJson('/api/reconciliations')->assertForbidden();
        $this->getJson("/api/reconciliations/availability?cash_register_id={$outside->id}&date=2026-11-21")->assertForbidden();
        $this->getJson("/api/reconciliations/availability?cash_register_id={$register->id}&date=incorrecta")
            ->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->getJson('/api/reconciliations/availability?date=2026-11-21')
            ->assertUnprocessable()->assertJsonValidationErrors('cash_register_id');
        $register->update(['is_active' => false]);
        $this->getJson("/api/reconciliations/availability?cash_register_id={$register->id}&date=2026-11-21")
            ->assertUnprocessable()->assertJsonValidationErrors('cash_register_id');
        $register->update(['is_active' => true]);
        $branch->update(['is_active' => false]);
        $this->getJson("/api/reconciliations/availability?cash_register_id={$register->id}&date=2026-11-21")
            ->assertUnprocessable()->assertJsonValidationErrors('cash_register_id');
        $role->revokePermissionTo('reconciliations.create');
        $this->actingAs($user->fresh(), 'sanctum')
            ->getJson("/api/reconciliations/availability?cash_register_id={$register->id}&date=2026-11-21")->assertForbidden();
    }

    public function test_future_dates_are_rejected_using_colombia_time_for_availability_and_creation(): void
    {
        $this->travelTo(Carbon::parse('2026-11-22 04:59:59', 'UTC'));
        $admin = User::factory()->create(['role_id' => Role::findByName('admin', 'web')->id]);
        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR']);
        $register = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja', 'code' => '1']);
        $type = ExpenseType::create(['name' => 'Fletes']);
        $payload = $this->payload($register, $type);
        $this->actingAs($admin, 'sanctum');

        $this->getJson("/api/reconciliations/availability?cash_register_id={$register->id}&date=2026-11-21")
            ->assertOk()->assertExactJson(['available' => true]);
        $this->getJson("/api/reconciliations/availability?cash_register_id={$register->id}&date=2026-11-22")
            ->assertUnprocessable()->assertJsonValidationErrors('date');
        $payload['date'] = '2026-11-22';
        $this->postJson('/api/reconciliations', $payload)->assertUnprocessable()->assertJsonValidationErrors('date')
            ->assertJsonPath('errors.date.0', 'La fecha del cuadre no puede ser posterior a la fecha actual de Colombia.');
        $payload['date'] = '2026-11-20';
        $this->postJson('/api/reconciliations', $payload)->assertCreated();
        $payload['date'] = '2026-11-21';
        $this->postJson('/api/reconciliations', $payload)->assertCreated();

        $this->travelTo(Carbon::parse('2026-11-22 05:00:00', 'UTC'));
        $this->getJson("/api/reconciliations/availability?cash_register_id={$register->id}&date=2026-11-22")
            ->assertOk()->assertExactJson(['available' => true]);
        $payload['date'] = '2026-11-22';
        $id = $this->postJson('/api/reconciliations', $payload)->assertCreated()->json('id');

        $this->travelTo(Carbon::parse('2026-11-22 04:59:59', 'UTC'));
        $edit = ['version' => 1, 'sales' => '1', 'cash' => '0', 'bank' => '0', 'qr' => '0', 'expenses' => []];
        $this->putJson("/api/reconciliations/{$id}", $edit)->assertOk()->assertJsonPath('date', '2026-11-22');
    }

    public function test_expense_detail_is_optional_on_create_and_update(): void
    {
        $admin = User::factory()->create(['role_id' => Role::findByName('admin', 'web')->id]);
        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR']);
        $register = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja', 'code' => '1']);
        $type = ExpenseType::create(['name' => 'Fletes']);
        $payload = $this->payload($register, $type);
        unset($payload['expenses'][0]['detail']);

        $this->actingAs($admin, 'sanctum');
        $created = $this->postJson('/api/reconciliations', $payload)
            ->assertCreated()->assertJsonPath('expenses.0.detail', '')->assertJsonPath('expenses_total', '995500.00')->json();
        $this->assertDatabaseHas('cash_reconciliation_expenses', ['id' => $created['expenses'][0]['id'], 'detail' => '']);

        $edit = ['version' => 1, 'sales' => '1', 'cash' => '0', 'bank' => '0', 'qr' => '0',
            'expenses' => [['id' => $created['expenses'][0]['id'], 'expense_type_id' => $type->id, 'detail' => 'Entrega', 'amount' => '0.25']]];
        $this->putJson('/api/reconciliations/'.$created['id'], $edit)->assertOk()->assertJsonPath('expenses.0.detail', 'Entrega');
        $edit['version'] = 2;
        $edit['expenses'][0]['detail'] = null;
        $this->putJson('/api/reconciliations/'.$created['id'], $edit)
            ->assertOk()->assertJsonPath('expenses.0.detail', '')->assertJsonPath('difference', '-0.75');
        $edit['version'] = 3;
        $edit['expenses'][0]['detail'] = str_repeat('a', 256);
        $this->putJson('/api/reconciliations/'.$created['id'], $edit)->assertUnprocessable()->assertJsonValidationErrors('expenses.0.detail');
        $edit['expenses'][0]['detail'] = ['incorrecto'];
        $this->putJson('/api/reconciliations/'.$created['id'], $edit)->assertUnprocessable()->assertJsonValidationErrors('expenses.0.detail');
    }

    public function test_permissions_and_branch_membership_apply_to_read_create_and_update(): void
    {
        $role = Role::create(['name' => 'gestor_cuadre', 'guard_name' => 'web']);
        $role->givePermissionTo(['reconciliations.view', 'reconciliations.create', 'reconciliations.update']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR']);
        $other = Branch::create(['name' => 'Norte', 'code' => 'NTE']);
        $user->branches()->attach($branch);
        $register = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja', 'code' => '1']);
        $outside = CashRegister::create(['branch_id' => $other->id, 'name' => 'Otra', 'code' => '1']);
        $type = ExpenseType::create(['name' => 'Fletes']);
        $payload = $this->payload($register, $type);
        $this->actingAs($user, 'sanctum');
        $id = $this->postJson('/api/reconciliations', $payload)->assertCreated()->json('id');
        $this->postJson('/api/reconciliations', $this->payload($outside, $type))->assertForbidden();
        $this->getJson('/api/reconciliations')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $id);
        $user->branches()->detach($branch);
        $this->getJson('/api/reconciliations')->assertOk()->assertJsonCount(0);
        $this->getJson("/api/reconciliations/{$id}")->assertForbidden();
        $this->putJson("/api/reconciliations/{$id}", [])->assertForbidden();
        $user->branches()->attach($branch);
        $role->revokePermissionTo('reconciliations.view');
        $this->actingAs($user->fresh(), 'sanctum')->getJson('/api/reconciliations')->assertForbidden();
        $role->revokePermissionTo(['reconciliations.create', 'reconciliations.update']);
        $this->actingAs($user->fresh(), 'sanctum')->postJson('/api/reconciliations', $payload)->assertForbidden();
        $this->putJson("/api/reconciliations/{$id}", [])->assertForbidden();
    }

    public function test_edits_preserve_historical_type_and_reject_stale_versions_and_inactive_new_types(): void
    {
        $admin = User::factory()->create(['role_id' => Role::findByName('admin', 'web')->id]);
        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR']);
        $register = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja', 'code' => '1']);
        $type = ExpenseType::create(['name' => 'Fletes']);
        $payload = $this->payload($register, $type);
        $this->actingAs($admin, 'sanctum');
        $created = $this->postJson('/api/reconciliations', $payload)->assertCreated()->json();
        $type->update(['name' => 'Transporte', 'is_active' => false]);
        $expenseId = $created['expenses'][0]['id'];
        $edit = ['version' => 1, 'sales' => '1.00', 'cash' => '0.00', 'bank' => '0.00', 'qr' => '0.00',
            'expenses' => [['id' => $expenseId, 'expense_type_id' => $type->id, 'detail' => 'Editado', 'amount' => '0.25']]];
        $this->putJson('/api/reconciliations/'.$created['id'], $edit)
            ->assertOk()->assertJsonPath('difference', '-0.75')->assertJsonPath('expenses.0.type_name', 'Fletes')->assertJsonPath('version', 2);
        $this->putJson('/api/reconciliations/'.$created['id'], $edit)->assertStatus(409);
        $edit['version'] = 2;
        $edit['expenses'][0]['id'] = $expenseId + 999;
        $this->putJson('/api/reconciliations/'.$created['id'], $edit)->assertUnprocessable()->assertJsonValidationErrors('expenses.0.id');
        unset($edit['expenses'][0]['id']);
        $this->putJson('/api/reconciliations/'.$created['id'], $edit)->assertUnprocessable()->assertJsonValidationErrors('expenses.0.expense_type_id');
        $this->getJson('/api/reconciliations/'.$created['id'])->assertOk()->assertJsonPath('version', 2)->assertJsonPath('expenses.0.id', $expenseId);
        $edit['expenses'] = [];
        $this->putJson('/api/reconciliations/'.$created['id'], $edit)
            ->assertOk()->assertJsonPath('expenses_total', '0.00')->assertJsonPath('difference', '-1.00')->assertJsonCount(0, 'expenses');
        $this->assertDatabaseMissing('cash_reconciliation_expenses', ['id' => $expenseId]);
    }

    public function test_historical_branch_access_is_preserved_if_register_is_moved(): void
    {
        $admin = User::factory()->create(['role_id' => Role::findByName('admin', 'web')->id]);
        $role = Role::create(['name' => 'consultor', 'guard_name' => 'web']);
        $role->givePermissionTo('reconciliations.view');
        $user = User::factory()->create(['role_id' => $role->id]);
        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR']);
        $other = Branch::create(['name' => 'Norte', 'code' => 'NTE']);
        $user->branches()->attach($branch);
        $register = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja', 'code' => '1']);
        $type = ExpenseType::create(['name' => 'Fletes']);
        $id = $this->actingAs($admin, 'sanctum')->postJson('/api/reconciliations', $this->payload($register, $type))->assertCreated()->json('id');
        $register->update(['branch_id' => $other->id]);

        $this->actingAs($user, 'sanctum')->getJson('/api/reconciliations')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $id)->assertJsonPath('0.branch.id', $branch->id);
        $user->branches()->sync([$other->id]);
        $this->getJson("/api/reconciliations/{$id}")->assertForbidden();
    }

    public function test_invalid_amounts_and_inactive_registers_are_rejected(): void
    {
        $admin = User::factory()->create(['role_id' => Role::findByName('admin', 'web')->id]);
        $branch = Branch::create(['name' => 'Centro', 'code' => 'CTR']);
        $register = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja', 'code' => '1']);
        $type = ExpenseType::create(['name' => 'Fletes']);
        $payload = $this->payload($register, $type);
        $this->actingAs($admin, 'sanctum');
        foreach (['-1', '1.001', '1e3', '1000000000000', 10] as $invalid) {
            $payload['sales'] = $invalid;
            $this->postJson('/api/reconciliations', $payload)->assertUnprocessable()->assertJsonValidationErrors('sales');
        }
        $payload['sales'] = '1';
        unset($payload['cash']);
        $this->postJson('/api/reconciliations', $payload)->assertUnprocessable()->assertJsonValidationErrors('cash');
        $payload['cash'] = '0';
        $branch->update(['is_active' => false]);
        $this->postJson('/api/reconciliations', $payload)->assertUnprocessable()->assertJsonValidationErrors('cash_register_id');
        $branch->update(['is_active' => true]);
        $register->update(['is_active' => false]);
        $this->postJson('/api/reconciliations', $payload)->assertUnprocessable()->assertJsonValidationErrors('cash_register_id');
        $this->assertDatabaseCount('cash_reconciliations', 0);
    }
}
