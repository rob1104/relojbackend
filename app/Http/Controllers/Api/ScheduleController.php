<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\DepartmentSchedule;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\Shift;
use App\Models\Timetable;
use App\Services\BioTimeApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScheduleController extends Controller
{
    public function __construct(private readonly BioTimeApiService $bioTime)
    {
    }

    public function index(): JsonResponse
    {
        $timetables = Timetable::orderBy('name')->get();
        $ttByName = $timetables->keyBy(fn (Timetable $t) => strtolower(trim($t->name)));

        $shifts = Shift::orderBy('biotime_pk')->orderBy('name')->get()->map(function (Shift $s) use ($ttByName) {
            $ttNames = array_values(array_filter(array_map('trim', explode(',', (string) $s->timetable))));
            $firstTt = null;
            foreach ($ttNames as $tn) {
                if ($ttByName->has(strtolower($tn))) {
                    $firstTt = $ttByName->get(strtolower($tn));
                    break;
                }
            }
            if (! $firstTt && $ttByName->has(strtolower(trim($s->name)))) {
                $firstTt = $ttByName->get(strtolower(trim($s->name)));
            }

            return [
                'id' => $s->id,
                'biotime_pk' => $s->biotime_pk,
                'name' => $s->name,
                'timetable' => $s->timetable ?: ($firstTt?->name ?? $s->name),
                'unit' => $s->unit ?? 'Semana',
                'cycle' => (int) ($s->cycle ?? 1),
                'work_weekend' => (bool) ($s->work_weekend ?? true),
                'check_in' => $firstTt ? substr((string) $firstTt->check_in, 0, 5) : '08:00',
                'check_out' => $firstTt ? str_replace(':00+1', '+1', substr((string) $firstTt->check_out, 0, 8)) : '17:00',
                'work_time_minutes' => (int) ($firstTt?->work_time_minutes ?? 480),
                'break_minutes' => (int) ($firstTt?->break_minutes ?? 60),
            ];
        });

        $employeeSchedules = EmployeeSchedule::with('employee:id,biotime_id,name,department,position,area')
            ->orderByDesc('end_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (EmployeeSchedule $es) => [
                'id' => $es->id,
                'biotime_pk' => $es->biotime_pk,
                'needs_push' => (bool) $es->needs_push,
                'employee_id' => $es->employee_id,
                'biotime_emp_code' => $es->biotime_emp_code,
                'employee_name' => $es->employee?->name ?? $es->biotime_emp_code,
                'department' => $es->employee?->department ?? '',
                'position' => $es->employee?->position ?? '',
                'shift_id' => $es->shift_id,
                'biotime_shift_pk' => $es->biotime_shift_pk,
                'shift_name' => $es->shift_name,
                'timetable_name' => $es->timetable_name,
                'check_in' => $es->check_in,
                'check_out' => $es->check_out,
                'start_date' => $es->start_date?->format('Y-m-d'),
                'end_date' => $es->end_date?->format('Y-m-d'),
                'assigned_by_dept' => (bool) $es->assigned_by_dept,
                'notes' => $es->notes ?? '',
            ]);

        $latestScheduleByEmp = $employeeSchedules->groupBy('employee_id')->map->first();

        $employees = Employee::where('active', true)
            ->orderByRaw('CAST(biotime_id AS UNSIGNED), biotime_id')
            ->get()
            ->map(function (Employee $emp) use ($latestScheduleByEmp) {
                $curr = $latestScheduleByEmp->get($emp->id);

                return [
                    'id' => $emp->id,
                    'biotime_id' => (string) $emp->biotime_id,
                    'biotime_pk' => $emp->biotime_pk,
                    'name' => $emp->name,
                    'department' => $emp->department ?? '',
                    'position' => $emp->position ?? '',
                    'area' => $emp->area ?? '',
                    'current_schedule' => $curr,
                ];
            });

        $departmentSchedules = DepartmentSchedule::orderByDesc('id')->get()->map(fn (DepartmentSchedule $ds) => [
            'id' => $ds->id,
            'department_id' => $ds->department_id,
            'department_code' => $ds->department_code,
            'department_name' => $ds->department_name,
            'shift_id' => $ds->shift_id,
            'shift_name' => $ds->shift_name,
            'timetable_name' => $ds->timetable_name,
            'check_in' => $ds->check_in,
            'check_out' => $ds->check_out,
            'start_date' => $ds->start_date?->format('Y-m-d'),
            'end_date' => $ds->end_date?->format('Y-m-d'),
            'employees_assigned_count' => $ds->employees_assigned_count,
            'synced_count' => $ds->synced_count,
            'notes' => $ds->notes ?? '',
            'created_at' => $ds->created_at?->toIso8601String(),
        ]);

        $latestDeptSchedByDeptId = $departmentSchedules->groupBy('department_id')->map->first();

        $departments = Department::orderBy('name')->get()->map(function (Department $dept) use ($employees, $latestDeptSchedByDeptId) {
            $deptEmps = $employees->filter(
                fn ($e) => strcasecmp(trim((string) $e['department']), trim((string) $dept->name)) === 0
            )->values();

            $assignedCount = $deptEmps->filter(fn ($e) => ! empty($e['current_schedule']))->count();

            return [
                'id' => $dept->id,
                'code' => $dept->code,
                'name' => $dept->name,
                'employees_count' => $deptEmps->count(),
                'assigned_count' => $assignedCount,
                'latest_department_schedule' => $latestDeptSchedByDeptId->get($dept->id),
            ];
        });

        return response()->json([
            'shifts' => $shifts->values(),
            'timetables' => $timetables->values(),
            'departments' => $departments->values(),
            'employees' => $employees->values(),
            'employee_schedules' => $employeeSchedules->values(),
            'department_schedules' => $departmentSchedules->values(),
        ]);
    }

    public function syncWithBioTime(): JsonResponse
    {
        $token = $this->bioTime->authenticate();

        // 1. FASE IDA (PUSH): Procesar eliminaciones pendientes y envíos pendientes
        $deletedCount = 0;
        $pendingDeletes = DB::table('deleted_catalog_items')
            ->where('catalog_type', 'employee_schedules')
            ->get();
        foreach ($pendingDeletes as $del) {
            if ($this->bioTime->deleteRemoteSchedule((int) $del->biotime_pk, $token)) {
                $deletedCount++;
            }
            DB::table('deleted_catalog_items')->where('id', $del->id)->delete();
        }

        $pushedCount = 0;
        $pushErrors = [];
        $pendingPush = EmployeeSchedule::with(['employee:id,biotime_pk', 'shift:id,biotime_pk'])
            ->where('needs_push', true)
            ->get();
        foreach ($pendingPush as $sched) {
            if (! $sched->biotime_emp_pk && $sched->employee?->biotime_pk) {
                $sched->biotime_emp_pk = $sched->employee->biotime_pk;
                $sched->updateQuietly(['biotime_emp_pk' => $sched->biotime_emp_pk]);
            }
            if (! $sched->biotime_shift_pk && $sched->shift?->biotime_pk) {
                $sched->biotime_shift_pk = $sched->shift->biotime_pk;
                $sched->updateQuietly(['biotime_shift_pk' => $sched->biotime_shift_pk]);
            }

            $res = $this->bioTime->pushEmployeeSchedule($sched, $token);
            if (! empty($res['synced'])) {
                $pushedCount++;
            } else {
                $pushErrors[] = "Emp {$sched->biotime_emp_code}: ".($res['error'] ?? 'Error');
            }
        }

        // 2. FASE VUELTA (PULL): Descargar todas las asignaciones de /att/api/attschedules/
        $remoteSchedules = $this->bioTime->fetchAllPages($token, '/att/api/attschedules/', ['page_size' => 300]);

        $employeesByPk = Employee::whereNotNull('biotime_pk')->get()->keyBy('biotime_pk');
        $shiftsByPk = Shift::whereNotNull('biotime_pk')->get()->keyBy('biotime_pk');
        $timetables = Timetable::all();
        $ttByName = $timetables->keyBy(fn (Timetable $t) => strtolower(trim($t->name)));

        $inserted = 0;
        $updated = 0;
        $pruned = 0;
        $seenRemotePks = [];

        DB::transaction(function () use (
            $remoteSchedules,
            $employeesByPk,
            $shiftsByPk,
            $ttByName,
            &$inserted,
            &$updated,
            &$pruned,
            &$seenRemotePks
        ) {
            foreach ($remoteSchedules as $rs) {
                $remoteId = (int) ($rs['id'] ?? 0);
                $empPk = (int) ($rs['employee'] ?? 0);
                $shiftPk = (int) ($rs['shift'] ?? 0);
                $startDate = substr((string) ($rs['start_date'] ?? ''), 0, 10);
                $endDate = substr((string) ($rs['end_date'] ?? ''), 0, 10);

                if (! $remoteId || ! $empPk || ! $shiftPk || ! $startDate || ! $endDate) {
                    continue;
                }

                $seenRemotePks[] = $remoteId;

                $emp = $employeesByPk->get($empPk);
                if (! $emp) {
                    continue;
                }

                $shift = $shiftsByPk->get($shiftPk);
                $shiftMeta = $this->resolveShiftTimes($shift, $ttByName);

                $existing = EmployeeSchedule::where('biotime_pk', $remoteId)->first();
                if (! $existing) {
                    $existing = EmployeeSchedule::where('employee_id', $emp->id)
                        ->where('shift_id', $shift?->id)
                        ->where('start_date', $startDate)
                        ->where('end_date', $endDate)
                        ->first();
                }

                $attrs = [
                    'biotime_pk' => $remoteId,
                    'needs_push' => false,
                    'employee_id' => $emp->id,
                    'biotime_emp_pk' => $empPk,
                    'biotime_emp_code' => (string) $emp->biotime_id,
                    'shift_id' => $shift?->id,
                    'biotime_shift_pk' => $shiftPk,
                    'shift_name' => $shift?->name ?? "Turno #{$shiftPk}",
                    'timetable_name' => $shiftMeta['timetable_name'],
                    'check_in' => $shiftMeta['check_in'],
                    'check_out' => $shiftMeta['check_out'],
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ];

                if ($existing) {
                    $existing->update($attrs);
                    $updated++;
                } else {
                    EmployeeSchedule::create($attrs);
                    $inserted++;
                }

                $this->updateEmployeeRefSlots($emp, $shiftMeta['check_in'], $shiftMeta['check_out']);
            }

            // Limpiar registros locales que ya fueron eliminados directamente en BioTime Pro
            if (! empty($seenRemotePks)) {
                $pruned = EmployeeSchedule::where('needs_push', false)
                    ->whereNotNull('biotime_pk')
                    ->whereNotIn('biotime_pk', $seenRemotePks)
                    ->delete();
            }
        });

        return response()->json([
            'message' => "Sincronización bidireccional de horarios completada: ↑{$pushedCount} enviados, ↓+{$inserted} nuevos, {$updated} actualizados"
                .($pruned > 0 ? ", {$pruned} depurados." : '.'),
            'pushed' => $pushedCount,
            'deleted_remote' => $deletedCount,
            'inserted' => $inserted,
            'updated' => $updated,
            'pruned' => $pruned,
            'push_errors' => $pushErrors,
        ]);
    }

    public function assignEmployees(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $shift = Shift::findOrFail($validated['shift_id']);
        $ttByName = Timetable::all()->keyBy(fn (Timetable $t) => strtolower(trim($t->name)));
        $shiftMeta = $this->resolveShiftTimes($shift, $ttByName);

        $token = null;
        try {
            $token = $this->bioTime->authenticate();
        } catch (\Throwable) {
            // Si BioTime no responde, se guarda local con needs_push = true
        }

        $assignedCount = 0;
        $syncedCount = 0;
        $errors = [];

        $employees = Employee::whereIn('id', $validated['employee_ids'])->get();

        foreach ($employees as $emp) {
            $schedule = EmployeeSchedule::updateOrCreate(
                [
                    'employee_id' => $emp->id,
                    'start_date' => $validated['start_date'],
                    'end_date' => $validated['end_date'],
                ],
                [
                    'biotime_emp_pk' => $emp->biotime_pk,
                    'biotime_emp_code' => (string) $emp->biotime_id,
                    'shift_id' => $shift->id,
                    'biotime_shift_pk' => $shift->biotime_pk,
                    'shift_name' => $shift->name,
                    'timetable_name' => $shiftMeta['timetable_name'],
                    'check_in' => $shiftMeta['check_in'],
                    'check_out' => $shiftMeta['check_out'],
                    'assigned_by_dept' => false,
                    'notes' => $validated['notes'] ?? null,
                    'needs_push' => true,
                ]
            );

            $this->updateEmployeeRefSlots($emp, $shiftMeta['check_in'], $shiftMeta['check_out']);
            $assignedCount++;

            if ($token) {
                $pushRes = $this->bioTime->pushEmployeeSchedule($schedule, $token);
                if (! empty($pushRes['synced'])) {
                    $syncedCount++;
                } elseif (! empty($pushRes['error'])) {
                    $errors[] = "{$emp->name}: {$pushRes['error']}";
                }
            }
        }

        $msg = "Se asignó el turno \"{$shift->name}\" a {$assignedCount} empleado(s).";
        if ($syncedCount > 0) {
            $msg .= " Sincronizados en BioTime Pro: {$syncedCount}/{$assignedCount}.";
        } else {
            $msg .= ' Guardado localmente (pendiente de sincronizar con BioTime Pro).';
        }

        return response()->json([
            'message' => $msg,
            'assigned_count' => $assignedCount,
            'synced_count' => $syncedCount,
            'errors' => $errors,
        ], 201);
    }

    public function assignDepartment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $dept = Department::findOrFail($validated['department_id']);
        $shift = Shift::findOrFail($validated['shift_id']);
        $ttByName = Timetable::all()->keyBy(fn (Timetable $t) => strtolower(trim($t->name)));
        $shiftMeta = $this->resolveShiftTimes($shift, $ttByName);

        $employees = Employee::where('active', true)
            ->where(function ($q) use ($dept) {
                $q->where('department', $dept->name)
                    ->orWhere('department', $dept->code);
            })
            ->get();

        $token = null;
        try {
            $token = $this->bioTime->authenticate();
        } catch (\Throwable) {
            // Offline fallback
        }

        $assignedCount = 0;
        $syncedCount = 0;
        $errors = [];

        foreach ($employees as $emp) {
            $schedule = EmployeeSchedule::updateOrCreate(
                [
                    'employee_id' => $emp->id,
                    'start_date' => $validated['start_date'],
                    'end_date' => $validated['end_date'],
                ],
                [
                    'biotime_emp_pk' => $emp->biotime_pk,
                    'biotime_emp_code' => (string) $emp->biotime_id,
                    'shift_id' => $shift->id,
                    'biotime_shift_pk' => $shift->biotime_pk,
                    'shift_name' => $shift->name,
                    'timetable_name' => $shiftMeta['timetable_name'],
                    'check_in' => $shiftMeta['check_in'],
                    'check_out' => $shiftMeta['check_out'],
                    'assigned_by_dept' => true,
                    'notes' => $validated['notes'] ?? "Asignado por departamento: {$dept->name}",
                    'needs_push' => true,
                ]
            );

            $this->updateEmployeeRefSlots($emp, $shiftMeta['check_in'], $shiftMeta['check_out']);
            $assignedCount++;

            if ($token) {
                $pushRes = $this->bioTime->pushEmployeeSchedule($schedule, $token);
                if (! empty($pushRes['synced'])) {
                    $syncedCount++;
                } elseif (! empty($pushRes['error'])) {
                    $errors[] = "{$emp->name}: {$pushRes['error']}";
                }
            }
        }

        DepartmentSchedule::create([
            'department_id' => $dept->id,
            'department_code' => $dept->code,
            'department_name' => $dept->name,
            'shift_id' => $shift->id,
            'biotime_shift_pk' => $shift->biotime_pk,
            'shift_name' => $shift->name,
            'timetable_name' => $shiftMeta['timetable_name'],
            'check_in' => $shiftMeta['check_in'],
            'check_out' => $shiftMeta['check_out'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'employees_assigned_count' => $assignedCount,
            'synced_count' => $syncedCount,
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'message' => "Horario \"{$shift->name}\" asignado al departamento \"{$dept->name}\" ({$assignedCount} empleados, {$syncedCount} sincronizados con BioTime Pro).",
            'assigned_count' => $assignedCount,
            'synced_count' => $syncedCount,
            'errors' => $errors,
        ], 201);
    }

    public function destroyEmployeeSchedule(EmployeeSchedule $employeeSchedule): JsonResponse
    {
        $remotePk = $employeeSchedule->biotime_pk;
        $empName = $employeeSchedule->employee?->name ?? $employeeSchedule->biotime_emp_code;

        $syncedDelete = false;
        if ($remotePk) {
            $syncedDelete = $this->bioTime->deleteRemoteSchedule((int) $remotePk);
            if (! $syncedDelete) {
                DB::table('deleted_catalog_items')->insert([
                    'catalog_type' => 'employee_schedules',
                    'biotime_pk' => $remotePk,
                    'unique_key' => $employeeSchedule->biotime_emp_code.'|'.$employeeSchedule->start_date,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $employeeSchedule->delete();

        return response()->json([
            'message' => "Asignación de horario eliminada para {$empName}".($syncedDelete ? ' (también eliminada en BioTime Pro).' : '.'),
        ]);
    }

    private function resolveShiftTimes(?Shift $shift, $ttByName): array
    {
        if (! $shift) {
            return [
                'timetable_name' => 'Personalizado',
                'check_in' => '08:00',
                'check_out' => '17:00',
            ];
        }

        $ttNames = array_values(array_filter(array_map('trim', explode(',', (string) $shift->timetable))));
        $firstTt = null;
        foreach ($ttNames as $tn) {
            if ($ttByName->has(strtolower($tn))) {
                $firstTt = $ttByName->get(strtolower($tn));
                break;
            }
        }
        if (! $firstTt && $ttByName->has(strtolower(trim($shift->name)))) {
            $firstTt = $ttByName->get(strtolower(trim($shift->name)));
        }

        $checkIn = $firstTt ? substr((string) $firstTt->check_in, 0, 5) : '08:00';
        $checkOutRaw = $firstTt ? (string) $firstTt->check_out : '17:00:00';
        $hasPlusOne = str_contains($checkOutRaw, '+1');
        $checkOut = substr(str_replace('+1', '', $checkOutRaw), 0, 5).($hasPlusOne ? '+1' : '');

        return [
            'timetable_name' => $shift->timetable ?: ($firstTt?->name ?? $shift->name),
            'check_in' => $checkIn,
            'check_out' => $checkOut,
        ];
    }

    private function updateEmployeeRefSlots(Employee $emp, string $checkIn, string $checkOut): void
    {
        $inParts = explode(':', substr($checkIn, 0, 5));
        $outClean = str_replace('+1', '', $checkOut);
        $outParts = explode(':', substr($outClean, 0, 5));
        if (count($inParts) !== 2 || count($outParts) !== 2) {
            return;
        }

        $inMin = ((int) $inParts[0]) * 60 + ((int) $inParts[1]);
        $outMin = ((int) $outParts[0]) * 60 + ((int) $outParts[1]);
        if (str_contains($checkOut, '+1') || $outMin <= $inMin) {
            $outMin += 1440;
        }

        $mid = (int) round(($inMin + $outMin) / 2);
        $emp->updateQuietly([
            'custom_ref_slots' => [$inMin, $mid - 15, $mid + 15, $outMin],
        ]);
    }
}
