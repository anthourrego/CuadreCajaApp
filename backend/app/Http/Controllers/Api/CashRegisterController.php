<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CashRegisterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $registers = CashRegister::query()
            ->whereIn('branch_id', Access::assignedBranchIds($user))
            ->when($request->integer('branch_id'), fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->with('branch:id,name,code')
            ->orderBy('name')
            ->get();

        return response()->json($registers->each(function (CashRegister $register) use ($user) {
            $register->setAttribute('can_update', Access::branch($user, 'registers.update', $register->branch_id));
        }));
    }

    public function options(Request $request): JsonResponse
    {
        abort_unless(Access::allows($request->user(), 'registers.create'), 403);

        return response()->json(Branch::query()
            ->whereIn('id', Access::branchIds($request->user(), 'registers.create'))
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'is_active']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:255',
                Rule::unique('cash_registers', 'code')->where('branch_id', $request->input('branch_id')),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'user_ids' => ['missing'],
        ], [
            'branch_id.exists' => 'La sucursal no existe o está inactiva.',
            'user_ids.missing' => 'Asigna usuarios desde la administración de usuarios.',
        ]);
        abort_unless(Access::branch($request->user(), 'registers.create', $data['branch_id']), 403);

        $register = CashRegister::create($data);

        return response()->json($register->load('branch:id,name,code'), 201);
    }

    public function show(Request $request, CashRegister $cashRegister): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($cashRegister->branch_id, Access::assignedBranchIds($user)), 403, 'No tienes acceso a esta caja.');

        return response()->json($cashRegister->load('branch:id,name,code'));
    }

    public function update(Request $request, CashRegister $cashRegister): JsonResponse
    {
        abort_unless(Access::branch($request->user(), 'registers.update', $cashRegister->branch_id), 403);
        $data = $request->validate([
            'branch_id' => [
                'sometimes', 'required', 'integer',
                (string) $request->input('branch_id') === (string) $cashRegister->branch_id
                    ? Rule::exists('branches', 'id')
                    : Rule::exists('branches', 'id')->where('is_active', true),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('cash_registers', 'code')
                    ->where('branch_id', $request->input('branch_id', $cashRegister->branch_id))
                    ->ignore($cashRegister->id),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'user_ids' => ['missing'],
        ], [
            'branch_id.exists' => 'La sucursal no existe o está inactiva.',
            'user_ids.missing' => 'Asigna usuarios desde la administración de usuarios.',
        ]);
        if (isset($data['branch_id'])) {
            abort_unless(Access::branch($request->user(), 'registers.update', $data['branch_id']), 403);
        }

        $cashRegister->update($data);

        return response()->json($cashRegister->load('branch:id,name,code'));
    }
}
