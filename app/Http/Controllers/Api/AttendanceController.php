<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceCorrectionLog;
use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\ImportBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    public function state(): JsonResponse
    {
        $employees = Employee::withCount(['events', 'corrections'])
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

        $events = AttendanceEvent::orderBy('biotime_id')
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

        $corrections = [];
        $correctionRecords = AttendanceCorrection::with('updater:id,name')->get();
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

        $leaves = EmployeeLeave::orderByDesc('start_date')
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

        return response()->json([
            'employees' => $employees->values(),
            'events' => $events->values(),
            'corrections' => (object) $corrections,
            'correction_logs' => $correctionLogs->values(),
            'leaves' => $leaves->values(),
            'stats' => [
                'employees_count' => $employees->count(),
                'events_count' => $events->count(),
                'imports_count' => ImportBatch::count(),
                'correction_logs_count' => AttendanceCorrectionLog::count(),
                'leaves_count' => $leaves->count(),
            ],
        ]);
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

    public function clearAll(Request $request): JsonResponse
    {
        DB::transaction(function () {
            AttendanceCorrectionLog::query()->delete();
            AttendanceCorrection::query()->delete();
            AttendanceEvent::query()->delete();
            ImportBatch::query()->delete();
        });

        return response()->json([
            'message' => 'Datos de asistencia limpiados. El sistema está listo para generar una nueva consulta con BioTime Pro.',
        ]);
    }
}
