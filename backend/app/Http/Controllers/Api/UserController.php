<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    private const VALIDATION_MESSAGES = [
        'name.required' => 'El nombre es obligatorio.',
        'username.required' => 'El nombre de usuario es obligatorio.',
        'username.min' => 'El usuario debe tener al menos 3 caracteres.',
        'username.max' => 'El usuario no puede superar 64 caracteres.',
        'username.regex' => 'El usuario solo admite letras, números, puntos, guiones y guiones bajos.',
        'username.unique' => 'El nombre de usuario ya está en uso.',
        'email.required' => 'El correo electrónico es obligatorio.',
        'email.email' => 'El correo electrónico no es válido.',
        'email.unique' => 'El correo electrónico ya está en uso.',
        'password.required' => 'La contraseña es obligatoria.',
        'password.min' => 'La contraseña debe tener al menos 12 caracteres.',
        'password.confirmed' => 'Las contraseñas no coinciden.',
        'role_id.required' => 'Selecciona un rol.',
        'role_id.exists' => 'El rol seleccionado no es válido.',
        'roles.missing' => 'Selecciona un único rol mediante role_id.',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->ensureManage($request);

        $users = User::query()->with('role.permissions')->orderBy('name')->get()
            ->filter(fn (User $user) => $this->canManageUser($request->user(), $user))
            ->map(fn (User $user) => $this->userPayload($user));

        return response()->json($users->values());
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureManage($request);
        $this->normalizeUsername($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[a-z0-9._-]+$/', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('guard_name', 'web')],
            'default_branch_id' => ['sometimes', 'nullable', 'integer'],
            'roles' => ['missing'],
        ], self::VALIDATION_MESSAGES);

        unset($data['password_confirmation']);

        $user = DB::transaction(function () use ($data, $request) {
            $actor = $this->lockedActor($request);
            $role = $this->delegableRole($actor, $data['role_id']);
            $this->validateDefaultBranch($role, $data['default_branch_id'] ?? null);
            $user = User::create($data);

            return $user->refresh();
        });

        return response()->json($this->userPayload($user), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->ensureManage($request);
        $this->normalizeUsername($request);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'username' => ['sometimes', 'required', 'string', 'min:3', 'max:64', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'required', 'string', 'min:12', 'confirmed'],
            'is_active' => ['sometimes', 'boolean'],
            'role_id' => ['sometimes', 'required', 'integer', Rule::exists('roles', 'id')->where('guard_name', 'web')],
            'default_branch_id' => ['sometimes', 'nullable', 'integer'],
            'roles' => ['missing'],
        ], self::VALIDATION_MESSAGES);

        return DB::transaction(function () use ($data, $user, $request) {
            $actor = $this->lockedActor($request);
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->canManageUser($actor, $user), 403);
            $roleId = $data['role_id'] ?? null;
            $role = $roleId !== null ? $this->delegableRole($actor, $roleId) : $user->role;
            $active = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $user->is_active;

            if ($user->id === $request->user()->id && ! $active) {
                return response()->json(['message' => 'No puedes desactivar tu propia cuenta.'], 422);
            }

            if ($user->is_active && $user->isAdmin()
                && (! $active || ($roleId !== null && (int) $roleId !== (int) $user->role_id))
                && User::where('role_id', $user->role_id)->where('is_active', true)->count() <= 1) {
                return response()->json(['message' => 'No se puede quitar el último administrador activo.'], 422);
            }

            $attributes = $data;
            unset($attributes['password_confirmation']);
            if (array_key_exists('default_branch_id', $data)) {
                $this->validateDefaultBranch($role, $data['default_branch_id']);
            } elseif ($role->id !== $user->role_id && $user->default_branch_id !== null
                && ! Access::activeBranchesForRole($role)->whereKey($user->default_branch_id)->exists()) {
                $attributes['default_branch_id'] = null;
            }
            $user->fill($attributes)->save();
            $user->unsetRelation('role');

            if (! $active || array_key_exists('password', $data)) {
                $user->tokens()->delete();
            }

            return response()->json($this->userPayload($user));
        });
    }

    public function defaultBranchOptions(Request $request): JsonResponse
    {
        $this->ensureManage($request);

        return response()->json(Access::activeBranchesForRole($request->user()->role)
            ->orderBy('name')->get(['id', 'name', 'code']));
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'role_id' => $user->role_id,
            'default_branch_id' => $user->default_branch_id,
            'role' => ['id' => $user->role->id, 'name' => $user->role->name],
            'permissions' => $user->permissionNames(),
        ];
    }

    private function ensureManage(Request $request): void
    {
        abort_unless(Access::allows($request->user(), 'users.manage'), 403);
    }

    private function lockedActor(Request $request): User
    {
        Role::where('name', 'admin')->where('guard_name', 'web')->lockForUpdate()->firstOrFail();
        $actor = $request->user()->fresh();
        abort_unless($actor->is_active && Access::allows($actor, 'users.manage'), 403);

        return $actor;
    }

    private function canManageUser(User $actor, User $target): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }

        return Access::canDelegate($actor, $target->role);
    }

    private function delegableRole(User $actor, int $roleId): Role
    {
        $role = Role::where('guard_name', 'web')->whereKey($roleId)->firstOrFail();
        abort_unless(Access::canDelegate($actor, $role), 403);

        return $role;
    }

    private function validateDefaultBranch(Role $role, ?int $branchId): void
    {
        if ($branchId !== null && ! Access::activeBranchesForRole($role)->whereKey($branchId)->exists()) {
            throw ValidationException::withMessages([
                'default_branch_id' => 'Selecciona una sucursal activa autorizada para el rol del usuario.',
            ]);
        }
    }

    private function normalizeUsername(Request $request): void
    {
        if (is_string($request->input('username'))) {
            $request->merge(['username' => strtolower(trim($request->input('username')))]);
        }
    }
}
