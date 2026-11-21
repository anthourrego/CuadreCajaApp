<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\BranchSeeder;
use Database\Seeders\CashRegisterSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DivipolaSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DivipolaTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_the_initial_branch_and_default_admin(): void
    {
        $this->seed(DatabaseSeeder::class);
        $branch = Branch::where('code', '001')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();
        $register = CashRegister::where('branch_id', $branch->id)->where('code', '001')->firstOrFail();

        $this->assertDatabaseCount('branches', 1);
        $this->assertDatabaseHas('branches', ['name' => 'Sucursal principal', 'code' => '001']);
        $this->assertDatabaseCount('cash_registers', 1);
        $this->assertDatabaseHas('cash_registers', ['id' => $register->id, 'name' => 'Caja principal', 'is_active' => true]);
        $this->assertDatabaseCount('cash_register_user', 0);
        $this->assertDatabaseHas('branch_user', ['branch_id' => $branch->id, 'user_id' => $admin->id]);
        $this->assertSame($branch->id, $admin->default_branch_id);
        $this->assertSame('admin', $admin->role->name);
        $this->assertSame('admin@cuadrecaja.test', $admin->email);
        $this->actingAs($admin, 'sanctum')->getJson('/api/user')
            ->assertOk()->assertJsonPath('default_branch.id', $branch->id);
        $this->getJson('/api/registers')->assertOk()->assertJsonPath('0.id', $register->id);
    }

    public function test_initial_cash_register_seeder_preserves_existing_changes_and_assignments(): void
    {
        $this->seed([BranchSeeder::class, CashRegisterSeeder::class]);
        $branch = Branch::where('code', '001')->firstOrFail();
        $register = $branch->cashRegisters()->where('code', '001')->firstOrFail();
        $user = User::factory()->create();
        $register->update(['name' => 'Caja editada', 'is_active' => false]);
        $register->users()->attach($user);
        $branch->update(['is_active' => false]);

        $this->seed(CashRegisterSeeder::class);

        $this->assertDatabaseCount('cash_registers', 1);
        $this->assertDatabaseHas('cash_registers', ['id' => $register->id, 'branch_id' => $branch->id, 'code' => '001', 'name' => 'Caja editada', 'is_active' => false]);
        $this->assertDatabaseHas('cash_register_user', ['cash_register_id' => $register->id, 'user_id' => $user->id]);
    }

    public function test_initial_cash_register_is_not_created_for_an_inactive_branch(): void
    {
        $this->seed(BranchSeeder::class);
        Branch::where('code', '001')->update(['is_active' => false]);

        try {
            $this->seed(CashRegisterSeeder::class);
            $this->fail('Se esperaba un error al crear una caja en una sucursal inactiva.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('sucursal principal está inactiva', $exception->getMessage());
        }

        $this->assertDatabaseCount('cash_registers', 0);
    }

    public function test_default_admin_seeder_is_idempotent_and_does_not_replace_the_password(): void
    {
        $this->seed([RoleSeeder::class, BranchSeeder::class, AdminUserSeeder::class]);
        $admin = User::where('username', 'admin')->firstOrFail();
        $passwordHash = $admin->password;
        $admin->update(['default_branch_id' => null]);
        $admin->branches()->detach();

        $this->seed(AdminUserSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertSame($passwordHash, $admin->fresh()->password);
        $this->assertNull($admin->fresh()->default_branch_id);
        $this->assertDatabaseCount('branch_user', 0);
    }

    public function test_initial_branch_seeder_preserves_existing_branch_changes_when_rerun(): void
    {
        $this->seed([RoleSeeder::class, BranchSeeder::class]);
        $branch = Branch::where('code', '001')->firstOrFail();
        $branch->update(['name' => 'Principal editada']);
        $user = User::factory()->create();
        $branch->users()->attach($user);

        $this->seed(BranchSeeder::class);

        $this->assertDatabaseCount('branches', 1);
        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'name' => 'Principal editada', 'code' => '001']);
        $this->assertDatabaseHas('branch_user', ['branch_id' => $branch->id, 'user_id' => $user->id]);
    }

    public function test_catalog_is_complete_preserves_codes_and_can_be_seeded_again(): void
    {
        $this->seed(DivipolaSeeder::class);
        $this->seed(DivipolaSeeder::class);

        $this->assertSame(33, DB::table('departments')->count());
        $this->assertSame(1122, DB::table('municipalities')->count());
        $this->assertDatabaseHas('departments', ['code' => '05', 'name' => 'ANTIOQUIA']);
        $this->assertDatabaseHas('municipalities', ['code' => '05001', 'department_code' => '05', 'name' => 'MEDELLÍN']);
        $this->assertDatabaseHas('municipalities', ['code' => '11001', 'department_code' => '11', 'name' => 'BOGOTÁ, D.C.']);
        $this->assertDatabaseHas('municipalities', ['code' => '91798', 'type' => 'Área no municipalizada']);
    }

    public function test_authenticated_users_can_query_the_catalog_by_department(): void
    {
        $this->seed(DivipolaSeeder::class);
        $user = User::factory()->create();

        $this->getJson('/api/departments')->assertUnauthorized();
        $this->actingAs($user, 'sanctum')->getJson('/api/departments')
            ->assertOk()->assertJsonCount(33)->assertJsonFragment(['code' => '05', 'name' => 'ANTIOQUIA', 'is_active' => true]);
        $this->getJson('/api/departments/05/municipalities')
            ->assertOk()->assertJsonFragment(['code' => '05001', 'department_code' => '05', 'name' => 'MEDELLÍN', 'type' => 'Municipio', 'is_active' => true]);
        $this->getJson('/api/departments/00/municipalities')->assertNotFound();
    }

    public function test_branch_location_is_optional_validated_and_returned(): void
    {
        $this->seed([RoleSeeder::class, DivipolaSeeder::class]);
        $admin = User::factory()->create();
        $admin->update(['role_id' => Role::findByName('admin', 'web')->id]);
        $branch = Branch::create(['name' => 'Anterior', 'code' => 'ANT']);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/branches/{$branch->id}")
            ->assertOk()->assertJsonPath('municipality', null);

        $this->postJson('/api/branches', ['name' => 'Nueva', 'code' => 'NUE', 'municipality_code' => '99999'])
            ->assertUnprocessable()->assertJsonValidationErrors('municipality_code');

        $this->putJson("/api/branches/{$branch->id}", ['municipality_code' => '05001'])
            ->assertOk()->assertJsonPath('municipality.code', '05001')
            ->assertJsonPath('municipality.department.code', '05');
        $this->getJson('/api/branches')->assertOk()->assertJsonPath('0.municipality.name', 'MEDELLÍN');

        $this->putJson("/api/branches/{$branch->id}", ['municipality_code' => null])
            ->assertOk()->assertJsonPath('municipality', null);
    }

    public function test_administrator_can_manage_catalog_and_other_roles_cannot(): void
    {
        $this->seed([RoleSeeder::class, DivipolaSeeder::class]);
        $user = User::factory()->create();
        $user->update(['role_id' => Role::findByName('cajero', 'web')->id]);
        $this->actingAs($user, 'sanctum')->postJson('/api/departments', ['code' => '00', 'name' => 'Prueba'])->assertForbidden();
        $this->getJson('/api/departments?include_inactive=1')->assertForbidden();
        $this->getJson('/api/departments/05/municipalities?include_inactive=1')->assertForbidden();
        $this->postJson('/api/municipalities', [])->assertForbidden();
        $this->putJson('/api/departments/05', ['is_active' => false])->assertForbidden();
        $this->putJson('/api/municipalities/05001', ['name' => 'Otro'])->assertForbidden();

        $admin = User::factory()->create();
        $admin->update(['role_id' => Role::findByName('admin', 'web')->id]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/departments', ['code' => '00', 'name' => 'Prueba'])->assertCreated();
        $this->postJson('/api/departments', ['code' => '00', 'name' => 'Duplicado'])->assertUnprocessable();
        $this->postJson('/api/departments', ['code' => '0a', 'name' => 'Inválido'])->assertUnprocessable();
        $this->postJson('/api/municipalities', ['code' => '01001', 'department_code' => '00', 'name' => 'Pueblo', 'type' => 'Municipio'])->assertUnprocessable();
        $this->postJson('/api/municipalities', ['code' => '00001', 'department_code' => '00', 'name' => 'Pueblo', 'type' => 'Otro'])->assertUnprocessable();
        $this->postJson('/api/municipalities', ['code' => '00001', 'department_code' => '00', 'name' => 'Pueblo', 'type' => 'Municipio'])->assertCreated();
        $this->putJson('/api/municipalities/00001', ['name' => 'Pueblo nuevo'])->assertOk()->assertJsonPath('name', 'Pueblo nuevo');
        $this->putJson('/api/municipalities/00001', ['code' => '00002'])->assertUnprocessable();
        $this->putJson('/api/municipalities/00001', ['department_code' => '05'])->assertUnprocessable();
        $this->putJson('/api/departments/00', ['code' => '01'])->assertUnprocessable();
    }

    public function test_inactive_locations_cannot_be_newly_assigned_but_existing_branch_location_is_preserved(): void
    {
        $this->seed([RoleSeeder::class, DivipolaSeeder::class]);
        $admin = User::factory()->create();
        $admin->update(['role_id' => Role::findByName('admin', 'web')->id]);
        $branch = Branch::create(['name' => 'Sucursal', 'code' => 'SC', 'municipality_code' => '05001']);

        $this->actingAs($admin, 'sanctum')->putJson('/api/municipalities/05001', ['is_active' => false])->assertOk();
        $this->getJson('/api/departments/05/municipalities')->assertJsonMissing(['code' => '05001']);
        $this->getJson('/api/departments/05/municipalities?include_inactive=1')->assertJsonFragment(['code' => '05001', 'is_active' => false]);
        $this->postJson('/api/branches', ['name' => 'Nueva', 'code' => 'NUE', 'municipality_code' => '05001'])->assertUnprocessable();
        $this->putJson("/api/branches/{$branch->id}", ['name' => 'Editada', 'municipality_code' => '05001'])->assertOk()->assertJsonPath('municipality.code', '05001');

        $this->putJson('/api/municipalities/05001', ['is_active' => true])->assertOk();
        $this->putJson('/api/municipalities/05002', ['is_active' => false])->assertOk();
        $this->putJson('/api/departments/05', ['is_active' => false])->assertOk();
        $this->getJson('/api/departments')->assertJsonMissing(['code' => '05']);
        $this->getJson('/api/departments/05/municipalities')->assertNotFound();
        $this->getJson('/api/departments/05/municipalities?include_inactive=1')->assertOk();
        $this->postJson('/api/municipalities', ['code' => '05999', 'department_code' => '05', 'name' => 'Pueblo', 'type' => 'Municipio'])->assertUnprocessable();
        $this->postJson('/api/branches', ['name' => 'Nueva', 'code' => 'NUE', 'municipality_code' => '05001'])->assertUnprocessable();
        $this->putJson("/api/branches/{$branch->id}", ['name' => 'Editada otra vez'])->assertOk()->assertJsonPath('municipality.code', '05001');
        $this->putJson('/api/departments/05', ['is_active' => true])->assertOk();
        $this->getJson('/api/departments/05/municipalities')->assertJsonFragment(['code' => '05001']);
        $this->getJson('/api/departments/05/municipalities')->assertJsonMissing(['code' => '05002']);
    }

    public function test_seeding_again_does_not_overwrite_manual_edits_or_status(): void
    {
        $this->seed(DivipolaSeeder::class);
        DB::table('departments')->where('code', '05')->update(['name' => 'Antioquia editada', 'is_active' => false]);
        DB::table('municipalities')->where('code', '05001')->update(['name' => 'Medellín editada', 'is_active' => false]);
        $this->seed(DivipolaSeeder::class);

        $this->assertDatabaseHas('departments', ['code' => '05', 'name' => 'Antioquia editada', 'is_active' => false]);
        $this->assertDatabaseHas('municipalities', ['code' => '05001', 'name' => 'Medellín editada', 'is_active' => false]);
    }
}
