<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BreakRule;
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
        $breakRules = BreakRule::orderBy('name')->get();
        $breakRulesById = $breakRules->keyBy('id');

        $timetables = Timetable::orderBy('name')->get()->map(function (Timetable $t) use ($breakRulesById) {
            $br = $t->break_rule_id ? $breakRulesById->get($t->break_rule_id) : null;
            $breakMins = (int) ($t->break_minutes ?: ($br?->duration_minutes ?? 60));

            return array_merge($t->toArray(), [
                'break_rule_name' => $br?->name,
                'break_mode' => $t->break_mode ?: 'fixed',
                'break_minutes' => $breakMins,
                'break_start_time' => $br ? substr((string) $br->start_time, 0, 5) : null,
                'break_end_time' => $br ? substr((string) $br->end_time, 0, 5) : null,
                'auto_deduct_break' => (bool) ($t->auto_deduct_break ?? false),
            ]);
        });
        $ttByName = $timetables->keyBy(fn (array $t) => strtolower(trim((string) $t['name'])));

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
                'timetable_id' => $firstTt['id'] ?? null,
                'timetable' => $s->timetable ?: ($firstTt['name'] ?? $s->name),
                'unit' => $s->unit ?? 'Semana',
                'cycle' => (int) ($s->cycle ?? 1),
                'auto_shift' => in_array($s->auto_shift, [true, 'True', 'true', '1', 1], true),
                'work_weekend' => (bool) ($s->work_weekend ?? true),
                'weekly_schedule' => $s->weekly_schedule,
                'check_in' => $firstTt ? substr((string) $firstTt['check_in'], 0, 5) : '08:00',
                'check_out' => $firstTt ? str_replace(':00+1', '+1', substr((string) $firstTt['check_out'], 0, 8)) : '17:00',
                'work_time_minutes' => (int) ($firstTt['work_time_minutes'] ?? 480),
                'break_rule_id' => $firstTt['break_rule_id'] ?? null,
                'break_rule_name' => $firstTt['break_rule_name'] ?? null,
                'break_mode' => $firstTt['break_mode'] ?? 'fixed',
                'break_minutes' => (int) ($firstTt['break_minutes'] ?? 60),
                'break_start_time' => $firstTt['break_start_time'] ?? null,
                'break_end_time' => $firstTt['break_end_time'] ?? null,
                'auto_deduct_break' => (bool) ($firstTt['auto_deduct_break'] ?? false),
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
                'break_rule_id' => $es->break_rule_id,
                'break_rule_name' => $es->break_rule_name,
                'break_mode' => $es->break_mode ?: 'fixed',
                'break_minutes' => (int) ($es->break_minutes ?? 60),
                'break_start_time' => $es->break_start_time,
                'break_end_time' => $es->break_end_time,
                'auto_deduct_break' => (bool) ($es->auto_deduct_break ?? false),
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
            'break_rule_id' => $ds->break_rule_id,
            'break_rule_name' => $ds->break_rule_name,
            'break_mode' => $ds->break_mode ?: 'fixed',
            'break_minutes' => (int) ($ds->break_minutes ?? 60),
            'break_start_time' => $ds->break_start_time,
            'break_end_time' => $ds->break_end_time,
            'auto_deduct_break' => (bool) ($ds->auto_deduct_break ?? false),
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
            'break_rules' => $breakRules->values(),
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

        // 2. FASE VUELTA (PULL): Sincronizar Descansos, Horarios y Turnos desde BioTime Pro
        $rawBreaks = $this->bioTime->fetchAllPages($token, '/att/api/breaktimes/', ['page_size' => 200]);
        foreach ($rawBreaks as $b) {
            $bName = trim((string) ($b['alias'] ?? ''));
            if ($bName === '') {
                continue;
            }
            $dur = (int) ($b['duration'] ?? 60);
            BreakRule::updateOrCreate(
                ['name' => $bName],
                [
                    'biotime_pk' => (int) ($b['id'] ?? 0) ?: null,
                    'needs_push' => false,
                    'start_time' => (string) ($b['period_start'] ?? '00:00:00'),
                    'end_time' => (string) ($b['period_end'] ?? '00:00:00'),
                    'duration_minutes' => $dur,
                    'end_margin' => (int) ($b['end_margin'] ?? $dur),
                    'minimum_duration' => (int) ($b['minimum_duration'] ?? $dur),
                    'multiple_punch' => (int) ($b['multiple_punch'] ?? 1),
                    'calculation_type' => (string) ($b['calc_type'] ?? 'Checada obligatoria'),
                ]
            );
        }

        $rawTimetables = $this->bioTime->fetchAllPages($token, '/att/api/timeintervals/', ['page_size' => 200]);
        foreach ($rawTimetables as $ti) {
            $tName = trim((string) ($ti['alias'] ?? ''));
            if ($tName === '') {
                continue;
            }
            $outTime = (string) ($ti['out_time'] ?? '18:00:00');
            if (! empty($ti['cross_day']) && ! str_contains($outTime, '+')) {
                $outTime .= '+1';
            }
            $existingTt = Timetable::where('name', $tName)->first();
            $remoteBreakMins = (int) ($ti['break_time_duration'] ?? 0);
            $remoteBreakPks = is_array($ti['break_time'] ?? null) ? $ti['break_time'] : [];
            $matchedBreakIds = ! empty($remoteBreakPks)
                ? BreakRule::whereIn('biotime_pk', $remoteBreakPks)->pluck('id')->map(fn ($v) => (int) $v)->values()->all()
                : ($existingTt?->break_rule_ids ?: ($existingTt?->break_rule_id ? [$existingTt->break_rule_id] : []));
            if (empty($matchedBreakIds) && $remoteBreakMins > 0) {
                $autoBr = BreakRule::where('duration_minutes', $remoteBreakMins)->value('id');
                if ($autoBr) {
                    $matchedBreakIds = [(int) $autoBr];
                }
            }
            Timetable::updateOrCreate(
                ['name' => $tName],
                [
                    'biotime_pk' => (int) ($ti['id'] ?? 0) ?: null,
                    'needs_push' => false,
                    'type' => (string) ($ti['use_mode'] ?? 'Normal'),
                    'is_24h' => ! empty($ti['is_24h']),
                    'check_in' => (string) ($ti['in_time'] ?? '08:00:00'),
                    'check_out' => $outTime,
                    'cross_day' => (int) ($ti['cross_day'] ?? 0),
                    'work_time_minutes' => (int) ($ti['work_time_duration'] ?? $ti['duration'] ?? 0),
                    'break_minutes' => $remoteBreakMins > 0 ? $remoteBreakMins : (int) ($existingTt?->break_minutes ?? 60),
                    'break_rule_id' => $matchedBreakIds[0] ?? null,
                    'break_rule_ids' => $matchedBreakIds,
                    'work_day' => (float) ($ti['work_day'] ?? 1.0),
                    'work_type' => ((int) ($ti['work_type'] ?? 0)) === 1 ? 'Día de Descanso' : 'Trabajo Normal',
                ]
            );
        }

        $rawShifts = $this->bioTime->fetchAllPages($token, '/att/api/attshifts/', ['page_size' => 200]);
        foreach ($rawShifts as $sh) {
            $sName = trim((string) ($sh['alias'] ?? ''));
            if ($sName === '') {
                continue;
            }
            Shift::updateOrCreate(
                ['name' => $sName],
                [
                    'biotime_pk' => (int) ($sh['id'] ?? 0) ?: null,
                    'needs_push' => false,
                    'timetable' => (string) ($sh['shift_timetable'] ?? ''),
                    'unit' => (string) ($sh['cycle_unit'] ?? 'Semana'),
                    'cycle' => (int) ($sh['shift_cycle'] ?? 1),
                    'auto_shift' => ! empty($sh['auto_shift']) ? 'True' : 'False',
                    'work_weekend' => (bool) ($sh['work_weekend'] ?? true),
                ]
            );
        }

        // 3. Descargar todas las asignaciones de /att/api/attschedules/
        $remoteSchedules = $this->bioTime->fetchAllPages($token, '/att/api/attschedules/', ['page_size' => 300]);

        $employeesByPk = Employee::whereNotNull('biotime_pk')->get()->keyBy('biotime_pk');
        $shiftsByPk = Shift::whereNotNull('biotime_pk')->get()->keyBy('biotime_pk');
        $timetables = Timetable::all();
        $ttByName = $timetables->keyBy(fn (Timetable $t) => strtolower(trim($t->name)));
        $breakRulesById = BreakRule::all()->keyBy('id');

        $inserted = 0;
        $updated = 0;
        $pruned = 0;
        $seenRemotePks = [];

        DB::transaction(function () use (
            $remoteSchedules,
            $employeesByPk,
            $shiftsByPk,
            $ttByName,
            $breakRulesById,
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
                $shiftMeta = $this->resolveShiftTimes($shift, $ttByName, $breakRulesById);

                $existing = EmployeeSchedule::where('biotime_pk', $remoteId)->first();
                if (! $existing) {
                    $existing = EmployeeSchedule::where('employee_id', $emp->id)
                        ->where('shift_id', $shift?->id)
                        ->where('start_date', $startDate)
                        ->where('end_date', $endDate)
                        ->first();
                }

                $breakPayload = [
                    'break_rule_id' => $existing?->break_rule_id ?? $shiftMeta['break_rule_id'],
                    'break_rule_name' => $existing?->break_rule_name ?? $shiftMeta['break_rule_name'],
                    'break_mode' => $existing?->break_mode ?: $shiftMeta['break_mode'],
                    'break_minutes' => $existing ? (int) $existing->break_minutes : $shiftMeta['break_minutes'],
                    'break_start_time' => $existing?->break_start_time ?? $shiftMeta['break_start_time'],
                    'break_end_time' => $existing?->break_end_time ?? $shiftMeta['break_end_time'],
                    'auto_deduct_break' => $existing ? (bool) $existing->auto_deduct_break : $shiftMeta['auto_deduct_break'],
                ];

                $attrs = array_merge([
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
                ], $breakPayload);

                if ($existing) {
                    $existing->update($attrs);
                    $updated++;
                } else {
                    EmployeeSchedule::create($attrs);
                    $inserted++;
                }

                $this->updateEmployeeRefSlots(
                    $emp,
                    $shiftMeta['check_in'],
                    $shiftMeta['check_out'],
                    $breakPayload['break_minutes'],
                    $breakPayload['break_start_time'],
                    $breakPayload['break_end_time']
                );
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
            'break_rule_id' => ['nullable', 'integer', 'exists:break_rules,id'],
            'break_mode' => ['nullable', 'in:fixed,dynamic,none'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:360'],
            'break_start_time' => ['nullable', 'string', 'max:8'],
            'break_end_time' => ['nullable', 'string', 'max:8'],
            'auto_deduct_break' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $shift = Shift::findOrFail($validated['shift_id']);
        $ttByName = Timetable::all()->keyBy(fn (Timetable $t) => strtolower(trim($t->name)));
        $breakRulesById = BreakRule::all()->keyBy('id');
        $shiftMeta = $this->resolveShiftTimes($shift, $ttByName, $breakRulesById);
        $breakConfig = $this->resolveBreakConfig($validated, $shiftMeta, $breakRulesById);

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
                array_merge([
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
                ], $breakConfig)
            );

            $this->updateEmployeeRefSlots(
                $emp,
                $shiftMeta['check_in'],
                $shiftMeta['check_out'],
                $breakConfig['break_minutes'],
                $breakConfig['break_start_time'],
                $breakConfig['break_end_time']
            );
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
            'break_rule_id' => ['nullable', 'integer', 'exists:break_rules,id'],
            'break_mode' => ['nullable', 'in:fixed,dynamic,none'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:360'],
            'break_start_time' => ['nullable', 'string', 'max:8'],
            'break_end_time' => ['nullable', 'string', 'max:8'],
            'auto_deduct_break' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $dept = Department::findOrFail($validated['department_id']);
        $shift = Shift::findOrFail($validated['shift_id']);
        $ttByName = Timetable::all()->keyBy(fn (Timetable $t) => strtolower(trim($t->name)));
        $breakRulesById = BreakRule::all()->keyBy('id');
        $shiftMeta = $this->resolveShiftTimes($shift, $ttByName, $breakRulesById);
        $breakConfig = $this->resolveBreakConfig($validated, $shiftMeta, $breakRulesById);

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
                array_merge([
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
                ], $breakConfig)
            );

            $this->updateEmployeeRefSlots(
                $emp,
                $shiftMeta['check_in'],
                $shiftMeta['check_out'],
                $breakConfig['break_minutes'],
                $breakConfig['break_start_time'],
                $breakConfig['break_end_time']
            );
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

        DepartmentSchedule::create(array_merge([
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
        ], $breakConfig));

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

    public function updateTimetableBreak(Request $request, Timetable $timetable): JsonResponse
    {
        $validated = $request->validate([
            'break_rule_id' => ['nullable', 'integer', 'exists:break_rules,id'],
            'break_mode' => ['required', 'in:fixed,dynamic,none'],
            'break_minutes' => ['required', 'integer', 'min:0', 'max:360'],
            'auto_deduct_break' => ['boolean'],
        ]);

        $br = ! empty($validated['break_rule_id']) ? BreakRule::find($validated['break_rule_id']) : null;
        $breakMinutes = $validated['break_mode'] === 'none'
            ? 0
            : (int) ($validated['break_minutes'] ?? ($br?->duration_minutes ?? 60));

        $timetable->update([
            'break_rule_id' => $validated['break_mode'] === 'none' ? null : ($br?->id),
            'break_rule_ids' => $validated['break_mode'] === 'none' ? [] : ($br ? [$br->id] : []),
            'break_mode' => $validated['break_mode'],
            'break_minutes' => $breakMinutes,
            'auto_deduct_break' => (bool) ($validated['auto_deduct_break'] ?? false),
            'needs_push' => true,
        ]);

        $this->bioTime->pushSingleRecord('timetables', $timetable->fresh());

        // Propagar a asignaciones activas/vigentes que usen este horario si aún no tienen una regla distinta
        $breakStart = $br && $validated['break_mode'] === 'fixed' ? substr((string) $br->start_time, 0, 5) : null;
        $breakEnd = $br && $validated['break_mode'] === 'fixed' ? substr((string) $br->end_time, 0, 5) : null;

        EmployeeSchedule::where('timetable_name', 'like', "%{$timetable->name}%")
            ->update([
                'break_rule_id' => $timetable->break_rule_id,
                'break_rule_name' => $br?->name,
                'break_mode' => $timetable->break_mode,
                'break_minutes' => $breakMinutes,
                'break_start_time' => $breakStart,
                'break_end_time' => $breakEnd,
                'auto_deduct_break' => $timetable->auto_deduct_break,
            ]);

        return response()->json([
            'message' => "Regla de comida actualizada para \"{$timetable->name}\" ({$breakMinutes} min · "
                .($validated['break_mode'] === 'dynamic' ? 'Dinámica' : ($validated['break_mode'] === 'none' ? 'Sin descanso' : 'Horario fijo')).').',
            'timetable' => $timetable->fresh(),
        ]);
    }

    public function updateShift(Request $request, Shift $shift): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'unit' => ['required', 'string', 'max:40'],
            'cycle' => ['required', 'integer', 'min:1', 'max:366'],
            'auto_shift' => ['boolean'],
            'work_weekend' => ['boolean'],
            'weekly_schedule' => ['nullable', 'array'],
            'weekly_schedule.sun' => ['nullable', 'integer', 'exists:timetables,id'],
            'weekly_schedule.mon' => ['nullable', 'integer', 'exists:timetables,id'],
            'weekly_schedule.tue' => ['nullable', 'integer', 'exists:timetables,id'],
            'weekly_schedule.wed' => ['nullable', 'integer', 'exists:timetables,id'],
            'weekly_schedule.thu' => ['nullable', 'integer', 'exists:timetables,id'],
            'weekly_schedule.fri' => ['nullable', 'integer', 'exists:timetables,id'],
            'weekly_schedule.sat' => ['nullable', 'integer', 'exists:timetables,id'],
        ]);

        $days = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
        $weekly = [];
        $ttIds = [];
        foreach ($days as $d) {
            $val = $validated['weekly_schedule'][$d] ?? null;
            $weekly[$d] = $val ? (int) $val : null;
            if ($val) {
                $ttIds[] = (int) $val;
            }
        }

        $ttNames = Timetable::whereIn('id', array_unique($ttIds))
            ->pluck('name', 'id');

        $orderedUniqueNames = [];
        foreach ($days as $d) {
            $tid = $weekly[$d];
            if ($tid && $ttNames->has($tid)) {
                $tName = $ttNames->get($tid);
                if (! in_array($tName, $orderedUniqueNames, true)) {
                    $orderedUniqueNames[] = $tName;
                }
            }
        }

        $shift->update([
            'name' => trim($validated['name']),
            'unit' => $validated['unit'],
            'cycle' => (int) $validated['cycle'],
            'auto_shift' => ! empty($validated['auto_shift']) ? 'True' : 'False',
            'work_weekend' => (bool) ($validated['work_weekend'] ?? ($weekly['sat'] || $weekly['sun'])),
            'weekly_schedule' => $weekly,
            'timetable' => count($orderedUniqueNames) ? implode(',', $orderedUniqueNames) : $shift->timetable,
            'needs_push' => true,
        ]);

        return response()->json([
            'message' => "Turno \"{$shift->name}\" actualizado correctamente.",
            'shift' => $shift->fresh(),
        ]);
    }

    private function resolveBreakConfig(array $validated, array $shiftMeta, $breakRulesById): array
    {
        $breakMode = $validated['break_mode'] ?? ($shiftMeta['break_mode'] ?? 'fixed');
        $breakRuleId = array_key_exists('break_rule_id', $validated)
            ? $validated['break_rule_id']
            : ($shiftMeta['break_rule_id'] ?? null);
        $br = $breakRuleId ? $breakRulesById->get($breakRuleId) : null;

        if ($breakMode === 'none') {
            return [
                'break_rule_id' => null,
                'break_rule_name' => null,
                'break_mode' => 'none',
                'break_minutes' => 0,
                'break_start_time' => null,
                'break_end_time' => null,
                'auto_deduct_break' => false,
            ];
        }

        $breakMinutes = array_key_exists('break_minutes', $validated) && $validated['break_minutes'] !== null
            ? (int) $validated['break_minutes']
            : (int) ($br?->duration_minutes ?? $shiftMeta['break_minutes'] ?? 60);

        $breakStart = $breakMode === 'fixed'
            ? ($validated['break_start_time'] ?? ($br ? substr((string) $br->start_time, 0, 5) : ($shiftMeta['break_start_time'] ?? null)))
            : null;
        $breakEnd = $breakMode === 'fixed'
            ? ($validated['break_end_time'] ?? ($br ? substr((string) $br->end_time, 0, 5) : ($shiftMeta['break_end_time'] ?? null)))
            : null;

        return [
            'break_rule_id' => $br?->id,
            'break_rule_name' => $br?->name,
            'break_mode' => $breakMode,
            'break_minutes' => $breakMinutes,
            'break_start_time' => $breakStart,
            'break_end_time' => $breakEnd,
            'auto_deduct_break' => array_key_exists('auto_deduct_break', $validated)
                ? (bool) $validated['auto_deduct_break']
                : (bool) ($shiftMeta['auto_deduct_break'] ?? false),
        ];
    }

    private function resolveShiftTimes(?Shift $shift, $ttByName, $breakRulesById = null): array
    {
        if (! $shift) {
            return [
                'timetable_name' => 'Personalizado',
                'check_in' => '08:00',
                'check_out' => '17:00',
                'break_rule_id' => null,
                'break_rule_name' => null,
                'break_mode' => 'fixed',
                'break_minutes' => 60,
                'break_start_time' => null,
                'break_end_time' => null,
                'auto_deduct_break' => false,
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

        $br = ($firstTt && $firstTt->break_rule_id && $breakRulesById)
            ? $breakRulesById->get($firstTt->break_rule_id)
            : null;
        $breakMode = $firstTt?->break_mode ?: 'fixed';
        $breakMinutes = $breakMode === 'none'
            ? 0
            : (int) ($firstTt?->break_minutes ?: ($br?->duration_minutes ?? 60));

        return [
            'timetable_name' => $shift->timetable ?: ($firstTt?->name ?? $shift->name),
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'break_rule_id' => $br?->id,
            'break_rule_name' => $br?->name,
            'break_mode' => $breakMode,
            'break_minutes' => $breakMinutes,
            'break_start_time' => ($br && $breakMode === 'fixed') ? substr((string) $br->start_time, 0, 5) : null,
            'break_end_time' => ($br && $breakMode === 'fixed') ? substr((string) $br->end_time, 0, 5) : null,
            'auto_deduct_break' => (bool) ($firstTt?->auto_deduct_break ?? false),
        ];
    }

    private function updateEmployeeRefSlots(
        Employee $emp,
        string $checkIn,
        string $checkOut,
        int $breakMinutes = 60,
        ?string $breakStartTime = null,
        ?string $breakEndTime = null
    ): void {
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

        $bStart = null;
        $bEnd = null;
        if ($breakStartTime && $breakEndTime) {
            $bsParts = explode(':', substr($breakStartTime, 0, 5));
            $beParts = explode(':', substr($breakEndTime, 0, 5));
            if (count($bsParts) === 2 && count($beParts) === 2) {
                $bStart = ((int) $bsParts[0]) * 60 + ((int) $bsParts[1]);
                $bEnd = ((int) $beParts[0]) * 60 + ((int) $beParts[1]);
                if ($bEnd <= $bStart) {
                    $bEnd += 1440;
                }
            }
        }

        if ($bStart === null || $bEnd === null) {
            $mid = (int) round(($inMin + $outMin) / 2);
            $half = (int) round(max(0, $breakMinutes) / 2);
            $bStart = $mid - $half;
            $bEnd = $mid + $half;
        }

        $emp->updateQuietly([
            'custom_ref_slots' => [$inMin, $bStart, $bEnd, $outMin],
        ]);
    }
}
