<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RoleMigrationTest extends TestCase
{
    public function test_migration_requires_explicit_choices_and_preserves_selected_roles(): void
    {
        $previousConnection = DB::getDefaultConnection();
        $previousChoices = getenv('ROLE_MIGRATION_CHOICES');
        config(['database.connections.role_migration_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('role_migration_test');
        putenv('ROLE_MIGRATION_CHOICES');

        try {
            $files = glob(database_path('migrations\\*.php'));
            sort($files);
            foreach ($files as $file) {
                if (str_ends_with($file, '2026_11_18_000000_move_user_role_to_users.php')) {
                    continue;
                }
                (require $file)->up();
            }
            $this->seed(RoleSeeder::class);
            $adminId = DB::table('roles')->where('name', 'admin')->value('id');
            $cashierId = DB::table('roles')->where('name', 'cajero')->value('id');
            $supervisorId = DB::table('roles')->where('name', 'supervisor')->value('id');
            $first = DB::table('users')->insertGetId([
                'name' => 'Uno', 'username' => 'uno', 'email' => 'uno@example.test',
                'password' => 'hash', 'is_active' => true,
            ]);
            $second = DB::table('users')->insertGetId([
                'name' => 'Dos', 'username' => 'dos', 'email' => 'dos@example.test',
                'password' => 'hash', 'is_active' => true,
            ]);
            $type = (new User)->getMorphClass();
            DB::table('model_has_roles')->insert([
                ['model_id' => $first, 'model_type' => $type, 'role_id' => $cashierId],
                ['model_id' => $second, 'model_type' => $type, 'role_id' => $adminId],
                ['model_id' => $second, 'model_type' => $type, 'role_id' => $cashierId],
                ['model_id' => $second, 'model_type' => $type, 'role_id' => $supervisorId],
            ]);
            $migration = require database_path('migrations/2026_11_18_000000_move_user_role_to_users.php');
            try {
                $migration->up();
                $this->fail('La migración debe rechazar roles múltiples sin una selección.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('varios roles', $exception->getMessage());
            }
            $this->assertFalse(Schema::hasColumn('users', 'role_id'));
            $this->assertTrue(Schema::hasTable('model_has_roles'));

            putenv('ROLE_MIGRATION_CHOICES='.json_encode([
                $second => ['role' => 'cajero', 'expected' => ['admin', 'cajero', 'supervisor']],
            ], JSON_THROW_ON_ERROR));
            try {
                $migration->up();
                $this->fail('La migración debe proteger el último administrador activo.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('último administrador', $exception->getMessage());
            }
            $this->assertFalse(Schema::hasColumn('users', 'role_id'));

            putenv('ROLE_MIGRATION_CHOICES='.json_encode([
                $second => ['role' => 'admin', 'expected' => ['admin', 'cajero', 'supervisor']],
            ], JSON_THROW_ON_ERROR));
            $migration->up();

            $this->assertSame($cashierId, DB::table('users')->where('id', $first)->value('role_id'));
            $this->assertSame($adminId, DB::table('users')->where('id', $second)->value('role_id'));
            $this->assertFalse(Schema::hasTable('model_has_roles'));
            $columns = collect(Schema::getColumns('users'))->keyBy('name');
            $this->assertFalse($columns['role_id']['nullable']);
            $this->assertNotEmpty(Schema::getForeignKeys('users'));
            $this->assertTrue(collect(Schema::getIndexes('users'))->contains(fn ($index) => $index['columns'] === ['role_id']));
        } finally {
            DB::setDefaultConnection($previousConnection);
            DB::purge('role_migration_test');
            putenv($previousChoices === false ? 'ROLE_MIGRATION_CHOICES' : 'ROLE_MIGRATION_CHOICES='.$previousChoices);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
}
