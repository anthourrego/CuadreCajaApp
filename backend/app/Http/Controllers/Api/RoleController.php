<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless(Access::allows($actor, 'roles.manage') || Access::allows($actor, 'users.manage'), 403);

        return response()->json(Role::where('guard_name', 'web')->with('permissions')->orderBy('name')->get()
            ->filter(fn (Role $role) => Access::canDelegate($actor, $role))
            ->map(fn (Role $role) => $this->payload($role))->values());
    }

    public function options(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless(Access::allows($actor, 'roles.manage'), 403);
        $branches = Branch::query()
            ->when(! $actor->isAdmin(), fn ($query) => $query->whereHas('roles', fn ($roles) => $roles->where('roles.id', $actor->role_id)))
            ->orderBy('name')->get(['id', 'name', 'code', 'is_active']);

        return response()->json(['global' => Access::GLOBAL, 'branch' => Access::BRANCH, 'branches' => $branches]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureManage($request);
        $data = $this->validated($request);

        return DB::transaction(function () use ($request, $data) {
            $actor = $this->lockedActor($request);
            abort_unless(Access::canGrant($actor, $data['permissions'], $data['branch_ids']), 403);
            $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
            $role->syncPermissions($data['permissions']);
            $this->syncBranches($role, $data['branch_ids']);

            return response()->json($this->payload($role->load('permissions')), 201);
        });
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $this->ensureManage($request);
        $data = $this->validated($request, $role);

        return DB::transaction(function () use ($request, $role, $data) {
            $actor = $this->lockedActor($request);
            $role = Role::whereKey($role->id)->lockForUpdate()->firstOrFail();
            abort_if($role->name === 'admin' || $role->guard_name !== 'web', 403);
            abort_unless(Access::canDelegate($actor, $role) && Access::canGrant($actor, $data['permissions'], $data['branch_ids']), 403);
            if (in_array($role->name, ['supervisor', 'cajero']) && $data['name'] !== $role->name) {
                return response()->json(['message' => 'No se puede cambiar el nombre de un rol inicial.'], 422);
            }
            $role->update(['name' => $data['name']]);
            $role->syncPermissions($data['permissions']);
            $this->syncBranches($role, $data['branch_ids']);

            return response()->json($this->payload($role->load('permissions')));
        });
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->ensureManage($request);

        return DB::transaction(function () use ($request, $role) {
            $actor = $this->lockedActor($request);
            $role = Role::whereKey($role->id)->lockForUpdate()->firstOrFail();
            abort_if($role->name === 'admin' || $role->guard_name !== 'web', 403);
            abort_unless(Access::canDelegate($actor, $role), 403);
            if (User::where('role_id', $role->id)->exists()
                || DB::table('branch_role')->where('role_id', $role->id)->exists()) {
                return response()->json(['message' => 'No se puede eliminar un rol con usuarios o sucursales asignados.'], 422);
            }
            $role->delete();

            return response()->json(['message' => 'Rol eliminado.']);
        });
    }

    private function validated(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:100', 'regex:/^[a-z0-9._-]+$/', Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($role?->id)],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::in(array_keys(Access::GLOBAL + Access::BRANCH))],
            'branch_ids' => ['present', 'array'],
            'branch_ids.*' => ['required', 'integer', 'distinct', 'exists:branches,id'],
        ]);
    }

    private function payload(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => $role->permissions->pluck('name')->values(),
            'branch_ids' => Branch::whereHas('roles', fn ($query) => $query->whereKey($role->id))->pluck('id'),
            'users_count' => User::where('role_id', $role->id)->count(),
        ];
    }

    private function syncBranches(Role $role, array $branchIds): void
    {
        DB::table('branch_role')->where('role_id', $role->id)->delete();
        foreach ($branchIds as $branchId) {
            DB::table('branch_role')->insert(['role_id' => $role->id, 'branch_id' => $branchId]);
        }
    }

    private function ensureManage(Request $request): void
    {
        abort_unless(Access::allows($request->user(), 'roles.manage'), 403);
    }

    private function lockedActor(Request $request): User
    {
        Role::where('name', 'admin')->where('guard_name', 'web')->lockForUpdate()->firstOrFail();
        $actor = $request->user()->fresh();
        abort_unless($actor->is_active && Access::allows($actor, 'roles.manage'), 403);

        return $actor;
    }
}
