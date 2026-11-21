<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string'],
        ]);

        return DB::transaction(function () use ($credentials) {
            $user = User::where('username', strtolower(trim($credentials['username'])))->lockForUpdate()->first();

            if (! $user || ! $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
                return response()->json(['message' => 'Credenciales inválidas.'], 401);
            }

            return response()->json([
                'token' => $user->createToken('web-app')->plainTextToken,
                'user' => $this->userPayload($user),
            ]);
        });
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request->user()));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['missing'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'username.missing' => 'El nombre de usuario no se puede modificar.',
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'El correo electrónico no es válido.',
            'email.unique' => 'El correo electrónico ya está en uso.',
        ]);
        $user->update($data);

        return response()->json($this->userPayload($user));
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ], [
            'current_password.required' => 'Ingresa tu contraseña actual.',
            'password.min' => 'La contraseña debe tener al menos 12 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ]);

        DB::transaction(function () use ($request, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'La contraseña actual es incorrecta.']);
            }
            $user->update(['password' => $data['password']]);
            $user->tokens()->delete();
        });

        return response()->json(['message' => 'Contraseña actualizada. Inicia sesión nuevamente.']);
    }

    private function userPayload(User $user): array
    {
        $branches = $user->branches()->orderBy('name')
            ->get(['branches.id', 'branches.name', 'branches.code', 'branches.is_active']);
        $defaultBranch = $branches->firstWhere('id', $user->default_branch_id);
        if (! $defaultBranch?->is_active) {
            $defaultBranch = null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'role_id' => $user->role_id,
            'default_branch_id' => $user->default_branch_id,
            'default_branch' => $defaultBranch,
            'branch_ids' => $branches->modelKeys(),
            'branches' => $branches,
            'role' => ['id' => $user->role->id, 'name' => $user->role->name],
            'permissions' => $user->permissionNames(),
        ];
    }
}
