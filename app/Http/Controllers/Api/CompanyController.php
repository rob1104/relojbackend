<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function index(): JsonResponse
    {
        $companies = Company::withCount('employees')
            ->orderBy('business_name')
            ->get();

        return response()->json([
            'companies' => $companies,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateCompany($request);
        $company = Company::create($validated);

        return response()->json([
            'message' => "Razón social \"{$company->business_name}\" creada correctamente.",
            'company' => $company->loadCount('employees'),
        ], 201);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $validated = $this->validateCompany($request);
        $company->update($validated);

        return response()->json([
            'message' => "Razón social \"{$company->business_name}\" actualizada correctamente.",
            'company' => $company->fresh()->loadCount('employees'),
        ]);
    }

    public function destroy(Company $company): JsonResponse
    {
        $name = $company->business_name;
        $company->delete();

        return response()->json([
            'message' => "Razón social \"{$name}\" eliminada (los empleados asignados quedaron sin empresa).",
        ]);
    }

    public function assignEmployees(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ]);

        $company = ! empty($validated['company_id'])
            ? Company::findOrFail($validated['company_id'])
            : null;

        $count = Employee::whereIn('id', $validated['employee_ids'])
            ->update(['company_id' => $company?->id]);

        $msg = $company
            ? "Se asignaron {$count} empleado(s) a la razón social \"{$company->business_name}\"."
            : "Se desvinculó la razón social de {$count} empleado(s).";

        activity('companies')
            ->event('assign')
            ->causedBy($request->user())
            ->withProperties([
                'company_id' => $company?->id,
                'company_name' => $company?->business_name ?? 'Sin empresa',
                'updated_count' => $count,
                'employee_ids' => $validated['employee_ids'],
            ])
            ->log($msg);

        return response()->json([
            'message' => $msg,
            'updated_count' => $count,
            'company' => $company?->loadCount('employees'),
        ]);
    }

    public function updateWeekStart(Request $request, Company $company): JsonResponse
    {
        $validated = $request->validate([
            'week_start_day' => ['required', 'integer', 'min:0', 'max:6'],
        ]);

        $company->update([
            'week_start_day' => (int) $validated['week_start_day'],
        ]);

        return response()->json([
            'message' => "Inicio de semana actualizado para \"{$company->business_name}\".",
            'company' => $company->fresh()->loadCount('employees'),
        ]);
    }

    private function validateCompany(Request $request): array
    {
        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:191'],
            'rfc' => ['nullable', 'string', 'max:20'],
            'imss_employer_registration' => ['nullable', 'string', 'max:40'],
            'workplace_address' => ['nullable', 'string', 'max:255'],
            'device_identifier' => ['nullable', 'string', 'max:191'],
            'legal_representative' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'active' => ['nullable', 'boolean'],
            'week_start_day' => ['nullable', 'integer', 'min:0', 'max:6'],
        ]);

        if (! empty($validated['rfc'])) {
            $validated['rfc'] = strtoupper(trim($validated['rfc']));
        }
        if (! empty($validated['imss_employer_registration'])) {
            $validated['imss_employer_registration'] = strtoupper(trim($validated['imss_employer_registration']));
        }
        if (empty($validated['device_identifier'])) {
            $validated['device_identifier'] = 'Reloj Biométrico BioTime Pro · TimeXis';
        }
        if (! array_key_exists('active', $validated)) {
            $validated['active'] = true;
        }

        return $validated;
    }
}
