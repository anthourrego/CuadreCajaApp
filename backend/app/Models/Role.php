<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    public static function bootHasPermissions(): void
    {
        // Spatie's default deletion hook also detaches users through model_has_roles.
        static::deleting(function (Role $role) {
            $role->permissions()->detach();
        });
    }
}
