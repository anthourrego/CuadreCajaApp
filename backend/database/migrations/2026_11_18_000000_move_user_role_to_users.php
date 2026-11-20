<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $type = (new User)->getMorphClass();
        $choices = json_decode(getenv('ROLE_MIGRATION_CHOICES') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($choices)) {
            throw new RuntimeException('ROLE_MIGRATION_CHOICES debe ser un objeto JSON.');
        }

        if (DB::table('model_has_roles')->where('model_type', '!=', $type)->exists()) {
            throw new RuntimeException('Hay roles asignados a otros modelos; no se puede eliminar la tabla sin reconciliarlos.');
        }
        if (DB::table('model_has_permissions')->where('model_type', $type)->exists()) {
            throw new RuntimeException('Hay permisos directos de usuario; asígnalos a roles antes de migrar.');
        }

        $assignments = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $type)
            ->select('model_has_roles.model_id', 'roles.id', 'roles.name', 'roles.guard_name')
            ->get()->groupBy('model_id');
        $selected = [];
        foreach (DB::table('users')->pluck('id') as $id) {
            $roles = $assignments->get($id, collect());
            if ($roles->isEmpty() || $roles->contains(fn ($role) => $role->guard_name !== 'web')) {
                throw new RuntimeException("El usuario {$id} no tiene un rol web válido.");
            }
            if ($roles->count() === 1) {
                $selected[$id] = $roles->first()->id;

                continue;
            }
            $choice = $choices[$id] ?? null;
            $names = $roles->pluck('name')->sort()->values()->all();
            $expected = is_array($choice) && isset($choice['expected']) && is_array($choice['expected'])
                ? collect($choice['expected'])->sort()->values()->all() : [];
            $role = is_array($choice) && isset($choice['role'])
                ? $roles->firstWhere('name', $choice['role']) : null;
            if ($names !== $expected || ! $role) {
                throw new RuntimeException("El usuario {$id} tiene varios roles; indica la selección y sus roles actuales en ROLE_MIGRATION_CHOICES.");
            }
            $selected[$id] = $role->id;
        }
        if ($assignments->keys()->diff(DB::table('users')->pluck('id'))->isNotEmpty()) {
            throw new RuntimeException('Hay asignaciones de rol para usuarios inexistentes.');
        }
        $adminId = DB::table('roles')->where('name', 'admin')->where('guard_name', 'web')->value('id');
        if ($adminId !== null) {
            $activeAdminIds = DB::table('users')->where('is_active', true)
                ->whereIn('id', DB::table('model_has_roles')->where('model_type', $type)->where('role_id', $adminId)->select('model_id'))
                ->pluck('id');
            if ($activeAdminIds->isNotEmpty() && ! $activeAdminIds->contains(fn ($id) => ($selected[$id] ?? null) == $adminId)) {
                throw new RuntimeException('La selección eliminaría el último administrador activo.');
            }
        }

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->index()->constrained('roles')->restrictOnDelete();
        });
        DB::transaction(function () use ($selected) {
            foreach ($selected as $id => $roleId) {
                DB::table('users')->where('id', $id)->update(['role_id' => $roleId]);
            }
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable(false)->change();
        });
        Schema::drop('model_has_roles');
    }

    public function down(): void
    {
        throw new RuntimeException('Esta migración requiere reversión manual: las asignaciones múltiples originales ya no se conservan.');
    }
};
