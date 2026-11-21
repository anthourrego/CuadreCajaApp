<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExpenseType;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $includeInactive = $request->boolean('include_inactive');
        if ($includeInactive) {
            $this->ensureManage($request);
        }

        return response()->json(ExpenseType::query()
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
            ->orderBy('name')->get(['id', 'name', 'is_active']));
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureManage($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:expense_types,name'],
        ], $this->messages());

        return response()->json(ExpenseType::create($data)->only(['id', 'name', 'is_active']), 201);
    }

    public function update(Request $request, ExpenseType $expenseType): JsonResponse
    {
        $this->ensureManage($request);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('expense_types', 'name')->ignore($expenseType->id)],
            'is_active' => ['sometimes', 'boolean'],
        ], $this->messages());
        $expenseType->update($data);

        return response()->json($expenseType->only(['id', 'name', 'is_active']));
    }

    private function ensureManage(Request $request): void
    {
        abort_unless(Access::allows($request->user(), 'expenses.manage_types'), 403);
    }

    private function messages(): array
    {
        return [
            'name.required' => 'El nombre del tipo de gasto es obligatorio.',
            'name.unique' => 'Ya existe un tipo de gasto con este nombre.',
        ];
    }
}
