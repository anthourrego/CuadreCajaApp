<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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

    private function userPayload(User $user): array
    {
        $defaultBranch = $user->default_branch_id === null ? null : Access::activeBranchesForRole($user->role)
            ->whereKey($user->default_branch_id)
            ->first(['id', 'name', 'code']);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'role_id' => $user->role_id,
            'default_branch_id' => $user->default_branch_id,
            'default_branch' => $defaultBranch,
            'role' => ['id' => $user->role->id, 'name' => $user->role->name],
            'permissions' => $user->permissionNames(),
        ];
    }
}
