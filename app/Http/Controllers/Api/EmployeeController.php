<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\BioTimeApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function __construct(
        private readonly BioTimeApiService $bioTime
    ) {}

    public function index(): JsonResponse
    {
        $employees = Employee::with('company:id,business_name,rfc,imss_employer_registration,workplace_address,device_identifier')
            ->withCount(['events', 'corrections', 'correctionLogs'])
            ->orderByRaw('CAST(biotime_id AS UNSIGNED), biotime_id')
            ->get();

        return response()->json([
            'employees' => $employees,
        ]);
    }

    private function rules(?int $ignoreId = null): array
    {
        $uniqueRule = 'unique:employees,biotime_id'.($ignoreId ? ','.$ignoreId : '');

        return [
            'biotime_id' => [$ignoreId ? 'sometimes' : 'required', 'string', 'max:64', $uniqueRule],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'nickname' => ['nullable', 'string', 'max:100'],
            'card_no' => ['nullable', 'string', 'max:64'],
            'device_password' => ['nullable', 'string', 'max:64'],
            'department' => ['nullable', 'string', 'max:255'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'position' => ['nullable', 'string', 'max:255'],
            'hire_date' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:16'],
            'birthday' => ['nullable', 'date'],
            'emp_type' => ['nullable', 'integer'],
            'verify_mode' => ['nullable', 'integer'],
            'ssn' => ['nullable', 'string', 'max:64'],
            'mobile' => ['nullable', 'string', 'max:64'],
            'contact_tel' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'string', 'max:150'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'enable_att' => ['nullable', 'boolean'],
            'enable_overtime' => ['nullable', 'boolean'],
            'enable_holiday' => ['nullable', 'boolean'],
            'device_privilege' => ['nullable', 'string', 'max:100'],
            'area' => ['nullable', 'string'],
            'app_status' => ['nullable', 'string', 'max:64'],
            'fingerprint' => ['nullable', 'string', 'max:64'],
            'face' => ['nullable', 'string', 'max:64'],
            'palm' => ['nullable', 'string', 'max:64'],
            'vl_face' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'custom_ref_slots' => ['nullable', 'array', 'size:4'],
            'custom_ref_slots.*' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    private function normalizeEmployeeNames(array &$validated, ?Employee $existing = null): void
    {
        $first = trim((string) ($validated['first_name'] ?? ''));
        $last = trim((string) ($validated['last_name'] ?? ''));
        $full = trim((string) ($validated['name'] ?? ''));

        if ($first !== '' || $last !== '') {
            $validated['first_name'] = $first;
            $validated['last_name'] = $last ?: null;
            $validated['name'] = trim(preg_replace('/\s+/', ' ', "{$first} {$last}"));
        } elseif ($full !== '') {
            $validated['name'] = $full;
            $words = preg_split('/\s+/', $full) ?: [];
            if (count($words) >= 3) {
                $validated['first_name'] = implode(' ', array_slice($words, 0, -2));
                $validated['last_name'] = implode(' ', array_slice($words, -2));
            } elseif (count($words) === 2) {
                $validated['first_name'] = $words[0];
                $validated['last_name'] = $words[1];
            } else {
                $validated['first_name'] = $full;
                $validated['last_name'] = null;
            }
        } elseif ($existing) {
            $validated['name'] = $existing->name;
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $this->normalizeEmployeeNames($validated);
        $validated['needs_push'] = true;

        $employee = Employee::create($validated);

        $pushRes = $this->bioTime->pushSingleRecord('employees', $employee);
        $msg = ! empty($pushRes['synced'])
            ? 'Empleado creado y sincronizado en directo con BioTime Pro.'
            : 'Empleado guardado localmente (se enviará a BioTime Pro en la próxima sincronización).';

        return response()->json([
            'message' => $msg,
            'synced_to_biotime' => ! empty($pushRes['synced']),
            'employee' => $employee->fresh(),
        ], 201);
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        $validated = $request->validate($this->rules($employee->id));
        $this->normalizeEmployeeNames($validated, $employee);
        $validated['needs_push'] = true;

        $employee->update($validated);

        $pushRes = $this->bioTime->pushSingleRecord('employees', $employee);
        $msg = ! empty($pushRes['synced'])
            ? 'Empleado actualizado y sincronizado en directo con BioTime Pro.'
            : 'Empleado actualizado localmente (pendiente de enviar a BioTime Pro).';

        return response()->json([
            'message' => $msg,
            'synced_to_biotime' => ! empty($pushRes['synced']),
            'employee' => $employee->fresh(),
        ]);
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $name = $employee->name;
        $this->bioTime->deleteRemoteRecord('employees', $employee->biotime_pk, $employee->biotime_id);
        $employee->delete();

        return response()->json([
            'message' => "Empleado {$name} eliminado en Control Biotime-Xis y en BioTime Pro.",
        ]);
    }
}
