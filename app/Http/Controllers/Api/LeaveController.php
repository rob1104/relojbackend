<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Services\BioTimeApiService;
use App\Support\ActiveCompany;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaveController extends Controller
{
    public function __construct(private readonly BioTimeApiService $bioTime)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $currentYear = now()->year;
        $activeCompanyId = ActiveCompany::resolveId($request);
        $scopedEmpIds = $activeCompanyId !== null
            ? Employee::where('company_id', $activeCompanyId)->pluck('id')->all()
            : null;

        $leaves = EmployeeLeave::with(['employee:id,biotime_id,name,department,position,hire_date', 'creator:id,name'])
            ->when($scopedEmpIds !== null, fn ($q) => $q->whereIn('employee_id', $scopedEmpIds))
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (EmployeeLeave $l) => $this->formatLeave($l));

        $leavesByEmp = $leaves->groupBy('employee_id');

        $balances = Employee::where('active', true)
            ->when($activeCompanyId !== null, fn ($q) => $q->where('company_id', $activeCompanyId))
            ->orderByRaw('CAST(biotime_id AS UNSIGNED), biotime_id')
            ->get()
            ->map(function (Employee $emp) use ($leavesByEmp, $currentYear) {
                $empLeaves = $leavesByEmp->get($emp->id, collect());
                $yearsService = 0;
                if ($emp->hire_date) {
                    $yearsService = max(0, (int) Carbon::parse($emp->hire_date)->diffInYears(now()));
                }

                $entitled = EmployeeLeave::lftVacationDaysForYears($yearsService);

                $usedVacations = $empLeaves
                    ->filter(fn ($l) => $l['leave_type'] === 'vacaciones' && substr((string) $l['start_date'], 0, 4) === (string) $currentYear)
                    ->sum('days_count');

                $incapacityDays = $empLeaves
                    ->filter(fn ($l) => $l['leave_type'] === 'incapacidad' && substr((string) $l['start_date'], 0, 4) === (string) $currentYear)
                    ->sum('days_count');

                $permissionDays = $empLeaves
                    ->filter(fn ($l) => $l['leave_type'] === 'permiso' && substr((string) $l['start_date'], 0, 4) === (string) $currentYear)
                    ->sum('days_count');

                return [
                    'employee_id' => $emp->id,
                    'biotime_id' => (string) $emp->biotime_id,
                    'name' => $emp->name,
                    'department' => $emp->department ?? '',
                    'position' => $emp->position ?? '',
                    'hire_date' => $emp->hire_date?->format('Y-m-d'),
                    'years_of_service' => $yearsService,
                    'entitled_vacation_days' => $entitled,
                    'used_vacation_days' => round((float) $usedVacations, 1),
                    'remaining_vacation_days' => max(0, round($entitled - (float) $usedVacations, 1)),
                    'incapacity_days_count' => round((float) $incapacityDays, 1),
                    'permission_days_count' => round((float) $permissionDays, 1),
                ];
            });

        return response()->json([
            'leaves' => $leaves->values(),
            'balances' => $balances->values(),
            'categories' => $this->defaultCategories(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'leave_type' => ['required', 'string', 'in:vacaciones,incapacidad,permiso'],
            'biotime_category_id' => ['nullable', 'integer'],
            'category_name' => ['nullable', 'string', 'max:120'],
            'imss_folio' => ['nullable', 'string', 'max:80'],
            'incapacity_type' => ['nullable', 'string', 'max:80'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'days_count' => ['nullable', 'numeric', 'min:0.5', 'max:365'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $emp = Employee::findOrFail($validated['employee_id']);

        $daysCount = $validated['days_count'] ?? $this->calculateDaysBetween(
            $validated['start_date'],
            $validated['end_date'],
            $validated['leave_type'] === 'vacaciones'
        );

        $defaultCatId = match ($validated['leave_type']) {
            'vacaciones' => 7,
            'incapacidad' => str_contains(strtolower((string) ($validated['incapacity_type'] ?? '')), 'maternidad') ? 3 : 1,
            default => 5,
        };

        $defaultCatName = match ($validated['leave_type']) {
            'vacaciones' => 'Vacaciones (Art. 76 LFT)',
            'incapacidad' => 'Incapacidad IMSS'.(! empty($validated['incapacity_type']) ? " ({$validated['incapacity_type']})" : ''),
            default => 'Permiso con/sin Goce',
        };

        $leave = EmployeeLeave::create([
            'needs_push' => true,
            'employee_id' => $emp->id,
            'biotime_emp_pk' => $emp->biotime_pk,
            'biotime_emp_code' => (string) $emp->biotime_id,
            'leave_type' => $validated['leave_type'],
            'biotime_category_id' => $validated['biotime_category_id'] ?? $defaultCatId,
            'category_name' => $validated['category_name'] ?? $defaultCatName,
            'imss_folio' => $validated['imss_folio'] ?? null,
            'incapacity_type' => $validated['incapacity_type'] ?? null,
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'days_count' => $daysCount,
            'status' => 'Aprobada',
            'reason' => $validated['reason'] ?? $defaultCatName,
            'created_by' => $request->user()?->id,
        ]);

        $syncRes = $this->bioTime->pushEmployeeLeave($leave);
        $leave->load(['employee:id,biotime_id,name,department,position,hire_date', 'creator:id,name']);

        $msg = "Registro guardado para {$emp->name} ({$daysCount} día(s)).";
        if (! empty($syncRes['synced'])) {
            $msg .= ' Sincronizado en BioTime Pro.';
        }

        return response()->json([
            'message' => $msg,
            'leave' => $this->formatLeave($leave),
            'synced' => ! empty($syncRes['synced']),
        ], 201);
    }

    public function destroy(EmployeeLeave $employeeLeave): JsonResponse
    {
        $remotePk = $employeeLeave->biotime_pk;
        $empName = $employeeLeave->employee?->name ?? $employeeLeave->biotime_emp_code;

        $syncedDelete = false;
        if ($remotePk) {
            $syncedDelete = $this->bioTime->deleteRemoteLeave((int) $remotePk);
        }

        $employeeLeave->delete();

        return response()->json([
            'message' => "Registro de ausencia eliminado para {$empName}".($syncedDelete ? ' (también eliminado en BioTime Pro).' : '.'),
        ]);
    }

    public function syncWithBioTime(): JsonResponse
    {
        $token = $this->bioTime->authenticate();

        $pushedCount = 0;
        $pendingPush = EmployeeLeave::with('employee:id,biotime_pk')
            ->where('needs_push', true)
            ->get();

        foreach ($pendingPush as $leave) {
            if (! $leave->biotime_emp_pk && $leave->employee?->biotime_pk) {
                $leave->biotime_emp_pk = $leave->employee->biotime_pk;
                $leave->updateQuietly(['biotime_emp_pk' => $leave->biotime_emp_pk]);
            }
            $res = $this->bioTime->pushEmployeeLeave($leave, $token);
            if (! empty($res['synced'])) {
                $pushedCount++;
            }
        }

        $remoteLeaves = $this->bioTime->fetchAllPages($token, '/att/api/leaves/', ['page_size' => 200]);
        $employeesByPk = Employee::whereNotNull('biotime_pk')->get()->keyBy('biotime_pk');

        $inserted = 0;
        $updated = 0;

        DB::transaction(function () use ($remoteLeaves, $employeesByPk, &$inserted, &$updated) {
            foreach ($remoteLeaves as $rl) {
                $remoteId = (int) ($rl['id'] ?? 0);
                $empPk = (int) ($rl['employee'] ?? 0);
                $startDate = substr((string) ($rl['start_time'] ?? ''), 0, 10);
                $endDate = substr((string) ($rl['end_time'] ?? ''), 0, 10);

                if (! $remoteId || ! $empPk || ! $startDate || ! $endDate) {
                    continue;
                }

                $emp = $employeesByPk->get($empPk);
                if (! $emp) {
                    continue;
                }

                $catId = (int) ($rl['category'] ?? 5);
                $leaveType = $catId === 7 ? 'vacaciones' : (in_array($catId, [1, 3], true) ? 'incapacidad' : 'permiso');

                $existing = EmployeeLeave::where('biotime_pk', $remoteId)->first();
                $attrs = [
                    'biotime_pk' => $remoteId,
                    'needs_push' => false,
                    'employee_id' => $emp->id,
                    'biotime_emp_pk' => $empPk,
                    'biotime_emp_code' => (string) $emp->biotime_id,
                    'leave_type' => $leaveType,
                    'biotime_category_id' => $catId,
                    'category_name' => (string) ($rl['category_name'] ?? ($leaveType === 'vacaciones' ? 'Vacaciones' : 'Ausencia BioTime')),
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'days_count' => $this->calculateDaysBetween($startDate, $endDate, $leaveType === 'vacaciones'),
                    'status' => 'Aprobada',
                    'reason' => (string) ($rl['apply_reason'] ?? 'Sincronizado de BioTime Pro'),
                ];

                if ($existing) {
                    $existing->update($attrs);
                    $updated++;
                } else {
                    EmployeeLeave::create($attrs);
                    $inserted++;
                }
            }
        });

        return response()->json([
            'message' => "Sincronización de Vacaciones e Incapacidades completada: ↑{$pushedCount} enviados, ↓+{$inserted} nuevos, {$updated} actualizados.",
            'pushed' => $pushedCount,
            'inserted' => $inserted,
            'updated' => $updated,
        ]);
    }

    private function calculateDaysBetween(string $startDate, string $endDate, bool $excludeSundays = true): float
    {
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        if ($end->lt($start)) {
            return 1;
        }

        $days = 0;
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            if (! $excludeSundays || $cursor->dayOfWeek !== Carbon::SUNDAY) {
                $days++;
            }
            $cursor->addDay();
        }

        return max(1, (float) $days);
    }

    private function formatLeave(EmployeeLeave $l): array
    {
        return [
            'id' => $l->id,
            'biotime_pk' => $l->biotime_pk,
            'needs_push' => (bool) $l->needs_push,
            'employee_id' => $l->employee_id,
            'biotime_emp_code' => $l->biotime_emp_code,
            'employee_name' => $l->employee?->name ?? $l->biotime_emp_code,
            'department' => $l->employee?->department ?? '',
            'position' => $l->employee?->position ?? '',
            'leave_type' => $l->leave_type,
            'biotime_category_id' => $l->biotime_category_id,
            'category_name' => $l->category_name,
            'imss_folio' => $l->imss_folio ?? '',
            'incapacity_type' => $l->incapacity_type ?? '',
            'start_date' => $l->start_date?->format('Y-m-d'),
            'end_date' => $l->end_date?->format('Y-m-d'),
            'days_count' => (float) $l->days_count,
            'status' => $l->status,
            'reason' => $l->reason ?? '',
            'created_by_name' => $l->creator?->name ?? 'Sistema',
            'created_at' => $l->created_at?->toIso8601String(),
        ];
    }

    private function defaultCategories(): array
    {
        return [
            ['id' => 7, 'leave_type' => 'vacaciones', 'name' => 'Vacaciones (Art. 76 LFT)'],
            ['id' => 1, 'leave_type' => 'incapacidad', 'name' => 'Incapacidad IMSS (Enfermedad General / Riesgo)'],
            ['id' => 3, 'leave_type' => 'incapacidad', 'name' => 'Incapacidad IMSS (Maternidad)'],
            ['id' => 5, 'leave_type' => 'permiso', 'name' => 'Permiso Especial / Con Goce'],
            ['id' => 2, 'leave_type' => 'permiso', 'name' => 'Permiso Ocasional / Sin Goce'],
        ];
    }
}
