<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(): JsonResponse
    {
        $employees = Employee::withCount(['events', 'corrections', 'correctionLogs'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'employees' => $employees,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'biotime_id' => ['required', 'string', 'max:64', 'unique:employees,biotime_id'],
            'name' => ['required', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'custom_ref_slots' => ['nullable', 'array', 'size:4'],
            'custom_ref_slots.*' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'active' => ['nullable', 'boolean'],
        ]);

        $employee = Employee::create($validated);

        return response()->json([
            'message' => 'Empleado registrado correctamente.',
            'employee' => $employee,
        ], 201);
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'custom_ref_slots' => ['nullable', 'array', 'size:4'],
            'custom_ref_slots.*' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'active' => ['nullable', 'boolean'],
        ]);

        $employee->update($validated);

        return response()->json([
            'message' => 'Datos del empleado actualizados.',
            'employee' => $employee->fresh(),
        ]);
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $name = $employee->name;
        $employee->delete();

        return response()->json([
            'message' => "Empleado {$name} y sus registros asociados fueron eliminados.",
        ]);
    }
}
