<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Support\Access;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(Access::GLOBAL + Access::BRANCH) as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (['admin', 'supervisor', 'cajero'] as $name) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            if ($role->wasRecentlyCreated && isset(Access::DEFAULTS[$name])) {
                $role->syncPermissions(Access::DEFAULTS[$name]);
            }
        }
    }
}
