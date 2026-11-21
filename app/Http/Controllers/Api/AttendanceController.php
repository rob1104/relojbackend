<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceCorrectionLog;
use App\Models\AttendanceEvent;
use App\Models\BreakRule;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\EmployeeSchedule;
use App\Models\DepartmentSchedule;
use App\Models\Holiday;
use App\Models\ImportBatch;
use App\Models\Shift;
use App\Models\Timetable;
use App\Models\WeekLock;
use App\Support\ActiveCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    public function state(Request $request): JsonResponse
    {
        $activeCompanyId = ActiveCompany::resolveId($request);

        $employees = Employee::with('company:id,business_name,rfc,imss_employer_registration,workplace_address,device_identifier,week_start_day')
            ->when($activeCompanyId !== null, fn ($q) => $q->where('company_id', $activeCompanyId))
            ->withCount(['events', 'corrections'])
            ->orderByRaw('CAST(biotime_id AS UNSIGNED), biotime_id')
            ->get()
            ->map(fn (Employee $emp) => [
                'id' => $emp->id,
                'biotime_id' => $emp->biotime_id,
                'biotime_pk' => $emp->biotime_pk,
                'needs_push' => $emp->needs_push,
                'name' => $emp->name,
                'first_name' => $emp->first_name ?? '',
                'last_name' => $emp->last_name ?? '',
                'nickname' => $emp->nickname ?? '',
                'card_no' => $emp->card_no ?? '',
                'device_password' => $emp->device_password ?? '',
                'department' => $emp->department ?? '',
                'company_id' => $emp->company_id,
                'company' => $emp->company ? [
                    'id' => $emp->company->id,
                    'business_name' => $emp->company->business_name,
                    'rfc' => $emp->company->rfc ?? '',
                    'imss_employer_registration' => $emp->company->imss_employer_registration ?? '',
                    'workplace_address' => $emp->company->workplace_address ?? '',
                    'device_identifier' => $emp->company->device_identifier ?? 'Reloj Biométrico BioTime Pro · TimeXis',
                ] : null,
                'position' => $emp->position ?? '',
                'hire_date' => $emp->hire_date?->format('Y-m-d'),
                'gender' => $emp->gender ?? '',
                'birthday' => $emp->birthday?->format('Y-m-d'),
                'emp_type' => $emp->emp_type ?? 1,
                'verify_mode' => $emp->verify_mode ?? 0,
                'ssn' => $emp->ssn ?? '',
                'mobile' => $emp->mobile ?? '',
                'contact_tel' => $emp->contact_tel ?? '',
                'email' => $emp->email ?? '',
                'city' => $emp->city ?? '',
                'address' => $emp->address ?? '',
                'enable_att' => (bool) $emp->enable_att,
                'enable_overtime' => (bool) $emp->enable_overtime,
                'enable_holiday' => (bool) $emp->enable_holiday,
                'device_privilege' => $emp->device_privilege ?? 'Empleado',
                'area' => $emp->area ?? '',
                'app_status' => $emp->app_status ?? 'Deshabilitar',
                'fingerprint' => $emp->fingerprint ?? '-',
                'face' => $emp->face ?? '-',
                'palm' => $emp->palm ?? '-',
                'vl_face' => $emp->vl_face ?? '-',
                'notes' => $emp->notes ?? '',
                'custom_ref_slots' => $emp->custom_ref_slots,
                'active' => $emp->active,
                'events_count' => $emp->events_count,
                'corrections_count' => $emp->corrections_count,
            ]);

        $employeeMap = $employees->keyBy('id');
        $scopedEmployeeIds = $employees->pluck('id')->all();
        $scopedBiotimeIds = $employees->pluck('biotime_id')->filter()->values()->all();
        $userId = $request->user()?->id;

        $visibleBatches = ImportBatch::where('user_id', $userId)
            ->where('is_visible', true)
            ->when($activeCompanyId !== null, fn ($q) => $q->where('company_id', $activeCompanyId))
            ->get(['id', 'date_from', 'date_to']);

        if ($visibleBatches->isEmpty()) {
            $events = collect([]);
        } else {
            $visibleBatchIds = $visibleBatches->pluck('id')->all();
            $dateRanges = $visibleBatches
                ->filter(fn ($b) => $b->date_from && $b->date_to)
                ->map(fn ($b) => [$b->date_from->format('Y-m-d'), $b->date_to->format('Y-m-d')])
                ->values()
                ->all();

            $events = AttendanceEvent::where('is_visible', true)
                ->when($activeCompanyId !== null, fn ($q) => $q->whereIn('employee_id', $scopedEmployeeIds))
                ->where(function ($q) use ($visibleBatchIds, $dateRanges) {
                    $q->whereIn('import_batch_id', $visibleBatchIds);
                    foreach ($dateRanges as [$from, $to]) {
                        $q->orWhereBetween('event_date', [$from, $to]);
                    }
                })
                ->orderBy('biotime_id')
                ->orderBy('event_date')
                ->orderBy('event_time')
                ->get()
                ->map(function (AttendanceEvent $ev) use ($employeeMap) {
                    $emp = $employeeMap->get($ev->employee_id);
                    $dateStr = $ev->event_date instanceof \DateTimeInterface
                        ? $ev->event_date->format('Y-m-d')
                        : substr((string) $ev->event_date, 0, 10);

                    return [
                        'db_id' => $ev->id,
                        'id' => (string) $ev->biotime_id,
                        'name' => $emp['name'] ?? (string) $ev->biotime_id,
                        'dept' => $emp['department'] ?? '',
                        'date' => $dateStr,
                        'time' => substr((string) $ev->event_time, 0, 5),
                        'import_batch_id' => $ev->import_batch_id,
                    ];
                });
        }

        $corrections = [];
        $correctionRecords = AttendanceCorrection::with('updater:id,name')
            ->when($activeCompanyId !== null, fn ($q) => $q->whereIn('biotime_id', $scopedBiotimeIds))
            ->get();
        foreach ($correctionRecords as $corr) {
            $dateStr = $corr->work_date instanceof \DateTimeInterface
                ? $corr->work_date->format('Y-m-d')
                : substr((string) $corr->work_date, 0, 10);
            $key = $corr->biotime_id.'|'.$dateStr;
            $corrections[$key] = [
                'slots' => $corr->slots ?? ['', '', '', ''],
                'reason' => $corr->reason ?? '',
                'updated_by' => $corr->updater?->name ?? 'Sistema',
                'updated_at' => $corr->updated_at?->toIso8601String(),
            ];
        }

        $correctionLogs = AttendanceCorrectionLog::with('user:id,name')
            ->when($activeCompanyId !== null, fn ($q) => $q->whereIn('biotime_id', $scopedBiotimeIds))
            ->orderByDesc('created_at')
            ->limit(1500)
            ->get()
            ->map(function (AttendanceCorrectionLog $log) {
                $wDate = $log->work_date instanceof \DateTimeInterface
                    ? $log->work_date->format('Y-m-d')
                    : substr((string) $log->work_date, 0, 10);

                return [
                    'id' => $log->id,
                    'biotime_id' => (string) $log->biotime_id,
                    'work_date' => $wDate,
                    'action' => $log->action,
                    'previous_slots' => $log->previous_slots ?? [],
                    'new_slots' => $log->new_slots ?? [],
                    'original_events' => $log->original_events ?? [],
                    'reason' => $log->reason ?? '',
                    'user_name' => $log->user?->name ?? 'Sistema',
                    'created_at' => $log->created_at?->toIso8601String(),
                ];
            });

        $leaves = EmployeeLeave::query()
            ->when($activeCompanyId !== null, fn ($q) => $q->whereIn('employee_id', $scopedEmployeeIds))
            ->orderByDesc('start_date')
            ->get()
            ->map(fn (EmployeeLeave $l) => [
                'id' => $l->id,
                'biotime_id' => (string) $l->biotime_emp_code,
                'leave_type' => $l->leave_type,
                'category_name' => $l->category_name,
                'imss_folio' => $l->imss_folio ?? '',
                'incapacity_type' => $l->incapacity_type ?? '',
                'start_date' => $l->start_date?->format('Y-m-d'),
                'end_date' => $l->end_date?->format('Y-m-d'),
                'days_count' => (float) $l->days_count,
                'reason' => $l->reason ?? '',
            ]);

        $employeeSchedules = EmployeeSchedule::query()
            ->when($activeCompanyId !== null, fn ($q) => $q->whereIn('employee_id', $scopedEmployeeIds))
            ->orderByDesc('end_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (EmployeeSchedule $es) => [
                'id' => $es->id,
                'employee_id' => $es->employee_id,
                'biotime_id' => (string) $es->biotime_emp_code,
                'shift_id' => $es->shift_id,
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
            ]);

        $departmentSchedules = DepartmentSchedule::orderByDesc('id')
            ->get()
            ->map(fn (DepartmentSchedule $ds) => [
                'id' => $ds->id,
                'department_name' => $ds->department_name,
                'department_code' => $ds->department_code,
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
            ]);

        $companies = Company::where('active', true)
            ->orderBy('business_name')
            ->get()
            ->map(fn (Company $c) => [
                'id' => $c->id,
                'business_name' => $c->business_name,
                'rfc' => $c->rfc ?? '',
                'imss_employer_registration' => $c->imss_employer_registration ?? '',
                'workplace_address' => $c->workplace_address ?? '',
                'device_identifier' => $c->device_identifier ?? 'Reloj Biométrico BioTime Pro · TimeXis',
                'legal_representative' => $c->legal_representative ?? '',
                'week_start_day' => (int) ($c->week_start_day ?? 3),
            ]);

        $timetablesById = Timetable::all()->keyBy('id');
        $breakRulesById = BreakRule::all()->keyBy('id');
        $shiftDailySchedules = [];
        foreach (Shift::all() as $shift) {
            if (! is_array($shift->weekly_schedule)) {
                continue;
            }
            $dayMap = [];
            foreach (['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as $dayKey) {
                $ttId = $shift->weekly_schedule[$dayKey] ?? null;
                $tt = $ttId ? $timetablesById->get((int) $ttId) : null;
                if (! $tt) {
                    $dayMap[$dayKey] = null;
                    continue;
                }
                $checkOutRaw = (string) $tt->check_out;
                $hasPlusOne = str_contains($checkOutRaw, '+1');
                $br = $tt->break_rule_id ? $breakRulesById->get($tt->break_rule_id) : null;
                $bMode = $tt->break_mode ?: 'fixed';
                $bMins = $bMode === 'none' ? 0 : (int) ($tt->break_minutes ?: ($br?->duration_minutes ?? 60));

                $dayMap[$dayKey] = [
                    'timetable_name' => $tt->name,
                    'check_in' => substr((string) $tt->check_in, 0, 5),
                    'check_out' => substr(str_replace('+1', '', $checkOutRaw), 0, 5).($hasPlusOne ? '+1' : ''),
                    'break_rule_id' => $br?->id,
                    'break_rule_name' => $br?->name,
                    'break_mode' => $bMode,
                    'break_minutes' => $bMins,
                    'break_start_time' => ($br && $bMode === 'fixed') ? substr((string) $br->start_time, 0, 5) : null,
                    'break_end_time' => ($br && $bMode === 'fixed') ? substr((string) $br->end_time, 0, 5) : null,
                    'auto_deduct_break' => (bool) ($tt->auto_deduct_break ?? false),
                ];
            }
            $shiftDailySchedules[(string) $shift->id] = $dayMap;
            $shiftDailySchedules[strtolower(trim($shift->name))] = $dayMap;
        }

        $holidays = Holiday::orderBy('start_date')
            ->get()
            ->map(fn (Holiday $h) => [
                'id' => $h->id,
                'name' => $h->name,
                'start_date' => $h->start_date instanceof \DateTimeInterface
                    ? $h->start_date->format('Y-m-d')
                    : substr((string) $h->start_date, 0, 10),
                'duration_days' => (int) ($h->duration_days ?: 1),
                'department_name' => $h->department_name,
            ]);

        $weekLocks = WeekLock::with(['company:id,business_name', 'locker:id,name'])
            ->when(
                $activeCompanyId !== null,
                fn ($q) => $q->where(fn ($sq) => $sq->where('company_id', $activeCompanyId)->orWhereNull('company_id'))
            )
            ->orderByDesc('week_start')
            ->get()
            ->map(fn (WeekLock $l) => [
                'id' => $l->id,
                'company_id' => $l->company_id,
                'company_name' => $l->company?->business_name ?? 'Todas las razones sociales',
                'week_start' => $l->week_start?->format('Y-m-d'),
                'week_end' => $l->week_end?->format('Y-m-d'),
                'locked_by' => $l->locked_by,
                'locked_by_name' => $l->locker?->name ?? 'Sistema',
                'locked_at' => $l->locked_at?->toIso8601String(),
                'notes' => $l->notes ?? '',
            ]);

        return response()->json([
            'active_company_id' => $activeCompanyId,
            'employees' => $employees->values(),
            'companies' => $companies->values(),
            'week_locks' => $weekLocks->values(),
            'events' => $events->values(),
            'corrections' => (object) $corrections,
            'correction_logs' => $correctionLogs->values(),
            'leaves' => $leaves->values(),
            'holidays' => $holidays->values(),
            'employee_schedules' => $employeeSchedules->values(),
            'department_schedules' => $departmentSchedules->values(),
            'shift_daily_schedules' => (object) $shiftDailySchedules,
            'stats' => [
                'employees_count' => $employees->count(),
                'events_count' => $events->count(),
                'imports_count' => ImportBatch::when($request->user()?->role !== 'Administrador', fn ($q) => $q->where('user_id', $userId))
                    ->when($activeCompanyId !== null, fn ($q) => $q->where('company_id', $activeCompanyId))
                    ->count(),
                'correction_logs_count' => $correctionLogs->count(),
                'leaves_count' => $leaves->count(),
            ],
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function importEvents(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'events' => ['required', 'array', 'min:1'],
            'events.*.id' => ['required', 'string', 'max:64'],
            'events.*.name' => ['nullable', 'string', 'max:255'],
            'events.*.dept' => ['nullable', 'string', 'max:255'],
            'events.*.date' => ['required', 'date_format:Y-m-d'],
            'events.*.time' => ['required', 'string', 'max:8'],
            'events.*.status' => ['nullable', 'string', 'max:64'],
            'events.*.temperature' => ['nullable', 'string', 'max:32'],
            'events.*.mask' => ['nullable', 'string', 'max:32'],
            'events.*.source' => ['nullable', 'string', 'max:64'],
            'filenames' => ['required', 'array', 'min:1'],
            'filenames.*' => ['string', 'max:255'],
            'source_type' => ['nullable', 'string', 'in:csv,demo'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $rawEvents = $validated['events'];
        $totalRows = count($rawEvents);
        $inserted = 0;
        $duplicates = 0;
        $touchedBiotimeIds = [];
        $minDate = null;
        $maxDate = null;

        $batch = DB::transaction(function () use (
            $request,
            $validated,
            $rawEvents,
            $totalRows,
            &$inserted,
            &$duplicates,
            &$touchedBiotimeIds,
            &$minDate,
            &$maxDate
        ) {
            $batch = ImportBatch::create([
                'user_id' => $request->user()?->id,
                'filenames' => $validated['filenames'],
                'source_type' => $validated['source_type'] ?? 'csv',
                'total_rows' => $totalRows,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Cache employees by biotime_id
            $employeesByBiotime = Employee::all()->keyBy('biotime_id');

            foreach ($rawEvents as $item) {
                $biotimeId = trim((string) $item['id']);
                $name = trim((string) ($item['name'] ?? '')) ?: $biotimeId;
                $dept = trim((string) ($item['dept'] ?? ''));
                $date = $item['date'];
                $time = substr(trim((string) $item['time']), 0, 5);

                $touchedBiotimeIds[$biotimeId] = true;
                if ($minDate === null || $date < $minDate) {
                    $minDate = $date;
                }
                if ($maxDate === null || $date > $maxDate) {
                    $maxDate = $date;
                }

                $emp = $employeesByBiotime->get($biotimeId);
                if (! $emp) {
                    $emp = Employee::create([
                        'biotime_id' => $biotimeId,
                        'name' => $name,
                        'department' => $dept ?: null,
                        'active' => true,
                    ]);
                    $employeesByBiotime->put($biotimeId, $emp);
                } else {
                    $updates = [];
                    if ($emp->name === $emp->biotime_id && $name !== $biotimeId) {
                        $updates['name'] = $name;
                    }
                    if (! $emp->department && $dept) {
                        $updates['department'] = $dept;
                    }
                    if (! empty($updates)) {
                        $emp->update($updates);
                    }
                }

                $exists = AttendanceEvent::where('employee_id', $emp->id)
                    ->where('event_date', $date)
                    ->where('event_time', $time)
                    ->exists();

                if ($exists) {
                    $duplicates++;
                } else {
                    AttendanceEvent::create([
                        'employee_id' => $emp->id,
                        'biotime_id' => $biotimeId,
                        'import_batch_id' => $batch->id,
                        'event_date' => $date,
                        'event_time' => $time,
                        'attendance_status' => $item['status'] ?? null,
                        'temperature' => $item['temperature'] ?? null,
                        'mask' => $item['mask'] ?? null,
                        'data_source' => $item['source'] ?? null,
                    ]);
                    $inserted++;
                }
            }

            $batch->update([
                'inserted_events' => $inserted,
                'duplicate_events' => $duplicates,
                'employees_touched' => count($touchedBiotimeIds),
                'date_from' => $minDate,
                'date_to' => $maxDate,
            ]);

            return $batch;
        });

        return response()->json([
            'message' => "Se procesaron {$totalRows} eventos: {$inserted} marcas nuevas guardadas y {$duplicates} duplicados exactos ignorados.",
            'batch' => $batch->fresh('user:id,name,email'),
        ]);
    }

    public function saveCorrection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'biotime_id' => ['required', 'string', 'max:64'],
            'date' => ['required', 'date_format:Y-m-d'],
            'slots' => ['required', 'array', 'size:4'],
            'slots.*' => ['nullable', 'string'],
            'previous_slots' => ['nullable', 'array'],
            'original_events' => ['nullable', 'array'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $slots = array_map(fn ($s) => trim((string) ($s ?? '')), $validated['slots']);

        // Validate chronological order of non-empty slots
        $filled = array_values(array_filter($slots, fn ($s) => $s !== ''));
        for ($i = 1; $i < count($filled); $i++) {
            if ($filled[$i] < $filled[$i - 1]) {
                return response()->json([
                    'message' => 'Las fechas y horas capturadas deben estar en orden cronológico.',
                ], 422);
            }
        }

        $emp = Employee::where('biotime_id', $validated['biotime_id'])->firstOrFail();

        if ($lock = $this->findWeekLockForDate($validated['date'], $emp->company_id)) {
            return response()->json([
                'message' => "La semana del {$lock->week_start?->format('d/m/Y')} al {$lock->week_end?->format('d/m/Y')} está cerrada para nómina. Solo el Administrador puede reabrirla.",
            ], 423);
        }

        $existing = AttendanceCorrection::where('employee_id', $emp->id)
            ->where('work_date', $validated['date'])
            ->first();

        $previousSlots = $existing ? $existing->slots : ($validated['previous_slots'] ?? ['', '', '', '']);

        $correction = AttendanceCorrection::updateOrCreate(
            [
                'employee_id' => $emp->id,
                'work_date' => $validated['date'],
            ],
            [
                'biotime_id' => $emp->biotime_id,
                'slots' => $slots,
                'reason' => $validated['reason'] ?? null,
                'updated_by' => $request->user()?->id,
            ]
        );

        $logEntry = AttendanceCorrectionLog::create([
            'employee_id' => $emp->id,
            'biotime_id' => $emp->biotime_id,
            'user_id' => $request->user()?->id,
            'work_date' => $validated['date'],
            'action' => 'save',
            'previous_slots' => $previousSlots,
            'new_slots' => $slots,
            'original_events' => $validated['original_events'] ?? [],
            'reason' => $validated['reason'] ?? null,
        ]);

        return response()->json([
            'message' => 'Corrección guardada correctamente.',
            'correction' => [
                'key' => $emp->biotime_id.'|'.$validated['date'],
                'slots' => $correction->slots,
                'reason' => $correction->reason ?? '',
                'updated_by' => $request->user()?->name ?? 'Sistema',
                'updated_at' => $correction->updated_at?->toIso8601String(),
            ],
            'log' => [
                'id' => $logEntry->id,
                'biotime_id' => (string) $emp->biotime_id,
                'work_date' => $validated['date'],
                'action' => 'save',
                'previous_slots' => $previousSlots ?? [],
                'new_slots' => $slots,
                'original_events' => $validated['original_events'] ?? [],
                'reason' => $validated['reason'] ?? '',
                'user_name' => $request->user()?->name ?? 'Sistema',
                'created_at' => $logEntry->created_at?->toIso8601String(),
            ],
        ]);
    }

    public function resetCorrection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'biotime_id' => ['required', 'string', 'max:64'],
            'date' => ['required', 'date_format:Y-m-d'],
            'original_events' => ['nullable', 'array'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $emp = Employee::where('biotime_id', $validated['biotime_id'])->firstOrFail();

        if ($lock = $this->findWeekLockForDate($validated['date'], $emp->company_id)) {
            return response()->json([
                'message' => "La semana del {$lock->week_start?->format('d/m/Y')} al {$lock->week_end?->format('d/m/Y')} está cerrada para nómina. Solo el Administrador puede reabrirla.",
            ], 423);
        }

        $existing = AttendanceCorrection::where('employee_id', $emp->id)
            ->where('work_date', $validated['date'])
            ->first();

        $logPayload = null;
        if ($existing) {
            $prevSlots = $existing->slots;
            $existing->delete();

            $logEntry = AttendanceCorrectionLog::create([
                'employee_id' => $emp->id,
                'biotime_id' => $emp->biotime_id,
                'user_id' => $request->user()?->id,
                'work_date' => $validated['date'],
                'action' => 'reset',
                'previous_slots' => $prevSlots,
                'new_slots' => null,
                'original_events' => $validated['original_events'] ?? [],
                'reason' => $validated['reason'] ?? 'Restauración a valores originales',
            ]);

            $logPayload = [
                'id' => $logEntry->id,
                'biotime_id' => (string) $emp->biotime_id,
                'work_date' => $validated['date'],
                'action' => 'reset',
                'previous_slots' => $prevSlots ?? [],
                'new_slots' => [],
                'original_events' => $validated['original_events'] ?? [],
                'reason' => $validated['reason'] ?? 'Restauración a valores originales',
                'user_name' => $request->user()?->name ?? 'Sistema',
                'created_at' => $logEntry->created_at?->toIso8601String(),
            ];
        }

        return response()->json([
            'message' => 'Jornada restaurada a sus marcas originales.',
            'key' => $emp->biotime_id.'|'.$validated['date'],
            'log' => $logPayload,
        ]);
    }

    private function findWeekLockForDate(string $date, ?int $companyId): ?WeekLock
    {
        return WeekLock::where('week_start', '<=', $date)
            ->where('week_end', '>=', $date)
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id');
                if ($companyId !== null) {
                    $q->orWhere('company_id', $companyId);
                }
            })
            ->first();
    }

    public function clearAll(Request $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $activeCompanyId = ActiveCompany::resolveId($request);

        DB::transaction(function () use ($userId, $activeCompanyId) {
            ImportBatch::where('user_id', $userId)
                ->where('is_visible', true)
                ->when($activeCompanyId !== null, fn ($q) => $q->where('company_id', $activeCompanyId))
                ->update(['is_visible' => false]);
        });

        return response()->json([
            'message' => 'Vista limpiada para nueva consulta. Tus lotes anteriores, historial de sincronizaciones, auditoría y correcciones permanecen intactos.',
        ]);
    }
}
