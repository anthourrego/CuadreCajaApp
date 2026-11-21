<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashReconciliation;
use App\Models\CashRegister;
use App\Models\ExpenseType;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CashReconciliationController extends Controller
{
    public function availability(Request $request): JsonResponse
    {
        abort_unless(Access::allows($request->user(), 'reconciliations.create'), 403);
        $data = $request->validate([
            'cash_register_id' => ['required', 'integer'],
            'date' => $this->dateRules(),
        ], ['date.before_or_equal' => 'La fecha del cuadre no puede ser posterior a la fecha actual de Colombia.']);
        $register = CashRegister::with('branch')->findOrFail($data['cash_register_id']);
        abort_unless(Access::branch($request->user(), 'reconciliations.create', $register->branch_id), 403);
        if (! $register->is_active || ! $register->branch->is_active) {
            throw ValidationException::withMessages(['cash_register_id' => 'La caja y su sucursal deben estar activas.']);
        }

        return response()->json(['available' => ! CashReconciliation::where('cash_register_id', $register->id)
            ->where('date', $data['date'])->exists()]);
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless(Access::allows($request->user(), 'reconciliations.view'), 403);
        $data = $request->validate([
            'branch_id' => ['sometimes', 'integer'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $records = CashReconciliation::query()
            ->whereIn('branch_id', Access::branchIds($request->user(), 'reconciliations.view'))
            ->when(isset($data['branch_id']), fn ($query) => $query->where('branch_id', $data['branch_id']))
            ->when(isset($data['date']), fn ($query) => $query->where('date', $data['date']))
            ->with(['expenses', 'cashRegister:id,name,code', 'branch:id,name,code'])
            ->orderByDesc('date')->orderByDesc('id')->get();

        return response()->json($records->map(fn (CashReconciliation $record) => $this->payload($record, $request)));
    }

    public function show(Request $request, CashReconciliation $reconciliation): JsonResponse
    {
        abort_unless(Access::branch($request->user(), 'reconciliations.view', $reconciliation->branch_id), 403);

        return response()->json($this->payload($reconciliation->load(['expenses', 'cashRegister:id,name,code', 'branch:id,name,code']), $request));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(Access::allows($request->user(), 'reconciliations.create'), 403);
        $data = $this->validated($request, true);

        $record = DB::transaction(function () use ($request, $data) {
            $register = CashRegister::lockForUpdate()->findOrFail($data['cash_register_id']);
            $branch = Branch::whereKey($register->branch_id)->lockForUpdate()->firstOrFail();
            abort_unless(Access::branch($request->user(), 'reconciliations.create', $register->branch_id), 403);
            if (! $register->is_active || ! $branch->is_active) {
                throw ValidationException::withMessages(['cash_register_id' => 'La caja y su sucursal deben estar activas.']);
            }
            if (CashReconciliation::where('cash_register_id', $register->id)->where('date', $data['date'])->exists()) {
                throw ValidationException::withMessages(['date' => 'Ya existe un cuadre para esta caja y fecha.']);
            }
            $record = CashReconciliation::create([
                'cash_register_id' => $register->id, 'branch_id' => $register->branch_id, 'date' => $data['date'],
                'sales' => $data['sales'], 'cash' => $data['cash'], 'bank' => $data['bank'], 'qr' => $data['qr'],
                'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
            ]);
            $this->saveExpenses($record, $data['expenses']);

            return $record;
        });

        return response()->json($this->payload($record->load(['expenses', 'cashRegister:id,name,code', 'branch:id,name,code']), $request), 201);
    }

    public function update(Request $request, CashReconciliation $reconciliation): JsonResponse
    {
        abort_unless(Access::branch($request->user(), 'reconciliations.update', $reconciliation->branch_id), 403);
        $data = $this->validated($request, false);

        $record = DB::transaction(function () use ($request, $reconciliation, $data) {
            $record = CashReconciliation::whereKey($reconciliation->id)->lockForUpdate()->firstOrFail();
            abort_unless(Access::branch($request->user(), 'reconciliations.update', $record->branch_id), 403);
            if ($record->version !== (int) $data['version']) {
                abort(409, 'El cuadre fue modificado por otro usuario. Recarga antes de guardar.');
            }
            $record->update([
                'sales' => $data['sales'], 'cash' => $data['cash'], 'bank' => $data['bank'], 'qr' => $data['qr'],
                'updated_by' => $request->user()->id, 'version' => $record->version + 1,
            ]);
            $this->saveExpenses($record, $data['expenses']);

            return $record;
        });

        return response()->json($this->payload($record->load(['expenses', 'cashRegister:id,name,code', 'branch:id,name,code']), $request));
    }

    private function validated(Request $request, bool $creating): array
    {
        $money = ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'];

        return $request->validate([
            'cash_register_id' => $creating ? ['required', 'integer', 'exists:cash_registers,id'] : ['missing'],
            'date' => $creating ? $this->dateRules() : ['missing'],
            'version' => $creating ? ['missing'] : ['required', 'integer', 'min:1'],
            'sales' => $money,
            'cash' => $money,
            'bank' => $money,
            'qr' => $money,
            'expenses' => ['present', 'array', 'max:100'],
            'expenses.*.id' => $creating ? ['missing'] : ['sometimes', 'integer', 'distinct'],
            'expenses.*.expense_type_id' => ['required', 'integer', Rule::exists('expense_types', 'id')],
            'expenses.*.detail' => ['nullable', 'string', 'max:255'],
            'expenses.*.amount' => $money,
        ], [
            'date.before_or_equal' => 'La fecha del cuadre no puede ser posterior a la fecha actual de Colombia.',
            'sales.regex' => 'La venta debe ser un valor no negativo con hasta dos decimales.',
            'cash.regex' => 'El efectivo debe ser un valor no negativo con hasta dos decimales.',
            'bank.regex' => 'El banco debe ser un valor no negativo con hasta dos decimales.',
            'qr.regex' => 'El QR debe ser un valor no negativo con hasta dos decimales.',
            'expenses.*.amount.regex' => 'El gasto debe ser un valor no negativo con hasta dos decimales.',
        ]);
    }

    private function dateRules(): array
    {
        return ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('America/Bogota')->toDateString()];
    }

    private function saveExpenses(CashReconciliation $record, array $expenses): void
    {
        $existing = $record->expenses()->get()->keyBy('id');
        $retained = [];
        $total = 0;
        foreach ($expenses as $index => $expense) {
            $old = isset($expense['id']) ? $existing->get($expense['id']) : null;
            if (isset($expense['id']) && ! $old) {
                throw ValidationException::withMessages(["expenses.{$index}.id" => 'El gasto no pertenece a este cuadre.']);
            }
            $type = ExpenseType::whereKey($expense['expense_type_id'])->lockForUpdate()->firstOrFail();
            if (! $type->is_active && (! $old || $old->expense_type_id !== $type->id)) {
                throw ValidationException::withMessages(["expenses.{$index}.expense_type_id" => 'Selecciona un tipo de gasto activo.']);
            }
            $values = [
                'expense_type_id' => $type->id,
                'type_name' => $old && $old->expense_type_id === $type->id ? $old->type_name : $type->name,
                'detail' => $expense['detail'] ?? '',
                'amount' => $expense['amount'],
            ];
            if ($old) {
                $old->update($values);
                $retained[] = $old->id;
            } else {
                $retained[] = $record->expenses()->create($values)->id;
            }
            $total += $this->cents($expense['amount']);
        }
        $record->expenses()->whereNotIn('id', $retained)->delete();
        $record->forceFill(['expenses_total' => $this->decimal($total)])->save();
    }

    private function payload(CashReconciliation $record, Request $request): array
    {
        $total = $this->cents($record->expenses_total);
        $difference = $this->cents($record->cash) + $this->cents($record->bank) + $this->cents($record->qr) + $total - $this->cents($record->sales);

        return [
            'id' => $record->id, 'date' => $record->date, 'cash_register_id' => $record->cash_register_id,
            'branch_id' => $record->branch_id, 'cash_register' => $record->cashRegister, 'branch' => $record->branch,
            'sales' => $record->sales, 'cash' => $record->cash, 'bank' => $record->bank, 'qr' => $record->qr,
            'expenses' => $record->expenses->map(fn ($expense) => $expense->only(['id', 'expense_type_id', 'type_name', 'detail', 'amount'])),
            'expenses_total' => $record->expenses_total, 'difference' => $this->decimal($difference),
            'created_by' => $record->created_by, 'updated_by' => $record->updated_by, 'version' => $record->version,
            'can_update' => Access::branch($request->user(), 'reconciliations.update', $record->branch_id),
        ];
    }

    private function cents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function decimal(int $cents): string
    {
        return ($cents < 0 ? '-' : '').intdiv(abs($cents), 100).'.'.str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }
}
