<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Support\Access;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class BranchController extends Controller
{
    private const VALIDATION_MESSAGES = [
        'name.required' => 'El nombre de la sucursal es obligatorio.',
        'code.required' => 'El código de la sucursal es obligatorio.',
        'code.unique' => 'Ya existe una sucursal con este código.',
        'municipality_code.exists' => 'El municipio no existe o su ubicación está inactiva.',
        'roles.missing' => 'Las sucursales se asignan desde los roles.',
        'user_ids.missing' => 'La asignación de usuarios a sucursales ya no está disponible; usa roles.',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = Branch::query()
            ->whereIn('id', Access::branchIds($request->user(), 'branches.view'))
            ->with('municipality.department')
            ->orderBy('name');

        return response()->json($query->get()->each(function (Branch $branch) use ($request) {
            $branch->setAttribute('can_update', Access::branch($request->user(), 'branches.update', $branch->id));
        }));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(Access::allows($request->user(), 'branches.create'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:255', 'unique:branches,code'],
            'address' => ['nullable', 'string', 'max:255'],
            'municipality_code' => ['nullable', 'string', 'size:5', $this->activeMunicipalityRule()],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => ['missing'],
            'user_ids' => ['missing'],
        ], self::VALIDATION_MESSAGES);

        $branch = Branch::create($data);

        return response()->json($branch->load('municipality.department'), 201);
    }

    public function show(Request $request, Branch $branch): JsonResponse
    {
        $user = $request->user();
        abort_unless(Access::branch($user, 'branches.view', $branch->id), 403);
        $branch->load('municipality.department');
        if (Access::branch($user, 'accounts.view', $branch->id)) {
            $branch->load('accounts');
        }
        if (Access::branch($user, 'registers.view_all', $branch->id)) {
            $branch->load('cashRegisters');
        } elseif (Access::branch($user, 'registers.view_assigned', $branch->id)) {
            $branch->setRelation('cashRegisters', $branch->cashRegisters()
                ->whereHas('users', fn ($query) => $query->whereKey($user->id))->get());
        }

        return response()->json($branch);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        abort_unless(Access::branch($request->user(), 'branches.update', $branch->id), 403);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('branches', 'code')->ignore($branch->id)],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'municipality_code' => [
                'sometimes', 'nullable', 'string', 'size:5',
                $request->input('municipality_code') === $branch->municipality_code
                    ? Rule::exists('municipalities', 'code')
                    : $this->activeMunicipalityRule(),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => ['missing'],
            'user_ids' => ['missing'],
        ], self::VALIDATION_MESSAGES);

        $branch->update($data);

        return response()->json($branch->load('municipality.department'));
    }

    private function activeMunicipalityRule(): Exists
    {
        return Rule::exists('municipalities', 'code')->where(
            fn (Builder $query) => $query->where('is_active', true)
                ->whereIn('department_code', DB::table('departments')->where('is_active', true)->select('code')),
        );
    }
}
