<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    private const TYPES = ['caja_mayor', 'caja_menor', 'banco', 'qr'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $branchIds = Access::branchIds($user, 'accounts.view');

        $accounts = Account::query()
            ->whereIn('branch_id', $branchIds)
            ->with('branch:id,name,code')
            ->when($request->integer('branch_id'), fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->orderBy('name')
            ->get();

        return response()->json($accounts);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(self::TYPES)],
            'is_active' => ['sometimes', 'boolean'],
        ], ['branch_id.exists' => 'La sucursal no existe o está inactiva.']);
        abort_unless(Access::branch($request->user(), 'accounts.create', $data['branch_id']), 403);

        return response()->json(Account::create($data)->load('branch:id,name,code'), 201);
    }

    public function show(Request $request, Account $account): JsonResponse
    {
        abort_unless(Access::branch($request->user(), 'accounts.view', $account->branch_id), 403);

        return response()->json($account->load('branch:id,name,code'));
    }

    public function update(Request $request, Account $account): JsonResponse
    {
        abort_unless(Access::branch($request->user(), 'accounts.update', $account->branch_id), 403);
        $data = $request->validate([
            'branch_id' => [
                'sometimes', 'required', 'integer',
                (string) $request->input('branch_id') === (string) $account->branch_id
                    ? Rule::exists('branches', 'id')
                    : Rule::exists('branches', 'id')->where('is_active', true),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', 'string', Rule::in(self::TYPES)],
            'is_active' => ['sometimes', 'boolean'],
        ], ['branch_id.exists' => 'La sucursal no existe o está inactiva.']);
        if (isset($data['branch_id'])) {
            abort_unless(Access::branch($request->user(), 'accounts.update', $data['branch_id']), 403);
        }

        $account->update($data);

        return response()->json($account->load('branch:id,name,code'));
    }
}
