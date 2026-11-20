<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\User;
use Spatie\Permission\Models\Role;

class Access
{
    public const GLOBAL = [
        'users.manage' => 'Administrar usuarios',
        'roles.manage' => 'Administrar roles',
        'locations.manage' => 'Administrar ubicaciones',
        'branches.create' => 'Crear sucursales',
    ];

    public const BRANCH = [
        'branches.view' => 'Consultar sucursales',
        'branches.update' => 'Editar sucursales',
        'accounts.view' => 'Consultar cuentas',
        'accounts.create' => 'Crear cuentas',
        'accounts.update' => 'Editar cuentas',
        'registers.view_all' => 'Consultar todas las cajas',
        'registers.view_assigned' => 'Consultar cajas asignadas',
        'registers.create' => 'Crear cajas',
        'registers.update' => 'Editar cajas',
    ];

    public const DEFAULTS = [
        'supervisor' => ['branches.view', 'accounts.view', 'registers.view_all'],
        'cajero' => ['branches.view', 'registers.view_assigned'],
    ];

    public static function allows(User $user, string $permission): bool
    {
        return $user->isAdmin() || $user->role->hasPermissionTo($permission, 'web');
    }

    public static function branchIds(User $user, string $permission): array
    {
        if ($user->isAdmin()) {
            return Branch::query()->pluck('id')->all();
        }

        if (! self::allows($user, $permission)) {
            return [];
        }

        return $user->branches()->pluck('branches.id')->all();
    }

    public static function assignedBranchIds(User $user): array
    {
        if ($user->isAdmin()) {
            return Branch::query()->pluck('id')->all();
        }

        return $user->branches()->pluck('branches.id')->all();
    }

    public static function branch(User $user, string $permission, int $branchId): bool
    {
        return $user->isAdmin() || in_array($branchId, self::branchIds($user, $permission));
    }

    public static function canDelegate(User $actor, Role $role): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }
        if ($role->name === 'admin') {
            return false;
        }

        return self::canGrant($actor, $role->permissions->pluck('name')->all());
    }

    public static function canGrant(User $actor, array $permissions): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }
        foreach ($permissions as $permission) {
            if (! isset(self::GLOBAL[$permission]) && ! isset(self::BRANCH[$permission])) {
                return false;
            }
            if (! self::allows($actor, $permission)) {
                return false;
            }
        }

        return true;
    }
}
