<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Municipality;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocationController extends Controller
{
    private const TYPES = ['Municipio', 'Isla', 'Área no municipalizada'];

    public function departments(Request $request): JsonResponse
    {
        $includeInactive = $this->includeInactive($request);

        return response()->json(Department::query()
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
            ->orderBy('name')->get(['code', 'name', 'is_active']));
    }

    public function municipalities(Request $request, Department $department): JsonResponse
    {
        $includeInactive = $this->includeInactive($request);
        abort_if(! $includeInactive && ! $department->is_active, 404);

        return response()->json($department->municipalities()
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
            ->orderBy('name')->get(['code', 'department_code', 'name', 'type', 'is_active']));
    }

    public function storeDepartment(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{2}$/', 'unique:departments,code'],
            'name' => ['required', 'string', 'max:255'],
        ], $this->messages());

        return response()->json(Department::create($data), 201);
    }

    public function updateDepartment(Request $request, Department $department): JsonResponse
    {
        $this->ensureAdmin($request);
        $data = $request->validate([
            'code' => ['prohibited'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ], $this->messages());

        $department->update($data);

        return response()->json($department);
    }

    public function storeMunicipality(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{5}$/', 'unique:municipalities,code'],
            'department_code' => ['required', 'string', Rule::exists('departments', 'code')->where('is_active', true)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(self::TYPES)],
        ], $this->messages());

        if (! str_starts_with($data['code'], $data['department_code'])) {
            return response()->json(['message' => 'El código debe comenzar con el código del departamento.'], 422);
        }

        return response()->json(Municipality::create($data), 201);
    }

    public function updateMunicipality(Request $request, Municipality $municipality): JsonResponse
    {
        $this->ensureAdmin($request);
        $data = $request->validate([
            'code' => ['prohibited'],
            'department_code' => ['prohibited'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', 'string', Rule::in(self::TYPES)],
            'is_active' => ['sometimes', 'boolean'],
        ], $this->messages());

        $municipality->update($data);

        return response()->json($municipality);
    }

    private function includeInactive(Request $request): bool
    {
        if ($request->boolean('include_inactive')) {
            $this->ensureAdmin($request);

            return true;
        }

        return false;
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless(Access::allows($request->user(), 'locations.manage'), 403);
    }

    private function messages(): array
    {
        return [
            'code.required' => 'El código es obligatorio.',
            'code.regex' => 'El código debe contener únicamente los dígitos indicados.',
            'code.unique' => 'Ya existe un registro con este código.',
            'code.prohibited' => 'No se puede modificar un código existente.',
            'department_code.required' => 'Selecciona un departamento.',
            'department_code.exists' => 'El departamento no existe o está inactivo.',
            'department_code.prohibited' => 'No se puede cambiar el departamento de un registro existente.',
            'name.required' => 'El nombre es obligatorio.',
            'type.in' => 'El tipo seleccionado no es válido.',
        ];
    }
}
