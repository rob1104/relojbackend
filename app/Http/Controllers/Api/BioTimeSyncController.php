<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\AttendanceEvent;
use App\Models\BreakRule;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\Position;
use App\Models\Shift;
use App\Models\Timetable;
use App\Services\BioTimeApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class BioTimeSyncController extends Controller
{
    public function __construct(
        private readonly BioTimeApiService $bioTime
    ) {}

    public function status(): JsonResponse
    {
        try {
            $token = $this->bioTime->authenticate();
            $baseUrl = $this->bioTime->getBaseUrl();

            $empRes = Http::timeout(8)
                ->withHeaders(['Authorization' => "Token {$token}", 'Accept' => 'application/json'])
                ->get("{$baseUrl}/personnel/api/employees/?page_size=1");

            $termRes = Http::timeout(8)
                ->withHeaders(['Authorization' => "Token {$token}", 'Accept' => 'application/json'])
                ->get("{$baseUrl}/iclock/api/terminals/?page_size=1");

            $txRes = Http::timeout(8)
                ->withHeaders(['Authorization' => "Token {$token}", 'Accept' => 'application/json'])
                ->get("{$baseUrl}/iclock/api/transactions/?page_size=1");

            return response()->json([
                'online' => true,
                'server_url' => $baseUrl,
                'remote_counts' => [
                    'employees' => $empRes->json('count') ?? 0,
                    'terminals' => $termRes->json('count') ?? 0,
                    'transactions' => $txRes->json('count') ?? 0,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'online' => false,
                'server_url' => $this->bioTime->getBaseUrl(),
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Sincronización Bidireccional (Ida y Vuelta) de los 8 Catálogos:
     * 1) IDA (Push): Envía a BioTime Pro las altas, ediciones (needs_push=true) y bajas locales.
     * 2) VUELTA (Pull): Descarga los registros desde BioTime Pro y actualiza MariaDB con su biotime_pk.
     */
    public function syncCatalogs(Request $request): JsonResponse
    {
        $token = $this->bioTime->authenticate();
        $baseUrl = $this->bioTime->getBaseUrl();

        // 0. Procesar bajas pendientes encoladas en deleted_catalog_items
        $pendingDeletes = DB::table('deleted_catalog_items')->get();
        foreach ($pendingDeletes as $del) {
            if ($del->biotime_pk) {
                try {
                    $ep = $this->bioTime->endpointForType($del->catalog_type);
                    Http::timeout(8)
                        ->withHeaders(['Authorization' => "Token {$token}", 'Accept' => 'application/json'])
                        ->delete("{$baseUrl}{$ep}{$del->biotime_pk}/");
                } catch (\Throwable) {
                    // ignore
                }
            }
            DB::table('deleted_catalog_items')->where('id', $del->id)->delete();
        }

        // 1. Obtener estado actual de BioTime Pro para vincular biotime_pk antes del Push
        $rawDepartments = $this->bioTime->fetchAllPages($token, '/personnel/api/departments/', ['page_size' => 200]);
        $rawAreas = $this->bioTime->fetchAllPages($token, '/personnel/api/areas/', ['page_size' => 200]);
        $rawPositions = $this->bioTime->fetchAllPages($token, '/personnel/api/positions/', ['page_size' => 200]);
        $rawTerminals = $this->bioTime->fetchAllPages($token, '/iclock/api/terminals/', ['page_size' => 200]);
        $rawBreaks = $this->bioTime->fetchAllPages($token, '/att/api/breaktimes/', ['page_size' => 200]);
        $rawTimetables = $this->bioTime->fetchAllPages($token, '/att/api/timeintervals/', ['page_size' => 200]);
        $rawShifts = $this->bioTime->fetchAllPages($token, '/att/api/attshifts/', ['page_size' => 200]);
        $rawEmployees = $this->bioTime->fetchAllPages($token, '/personnel/api/employees/', ['page_size' => 300]);

        $this->linkRemotePks(Department::class, 'code', $rawDepartments, 'dept_code');
        $this->linkRemotePks(Area::class, 'code', $rawAreas, 'area_code');
        $this->linkRemotePks(Position::class, 'code', $rawPositions, 'position_code');
        $this->linkRemotePks(Device::class, 'serial_number', $rawTerminals, 'sn');
        $this->linkRemotePks(BreakRule::class, 'name', $rawBreaks, 'alias');
        $this->linkRemotePks(Timetable::class, 'name', $rawTimetables, 'alias');
        $this->linkRemotePks(Shift::class, 'name', $rawShifts, 'alias');
        $this->linkRemotePks(Employee::class, 'biotime_id', $rawEmployees, 'emp_code');

        // 2. FASE IDA (PUSH): Enviar registros locales nuevos (biotime_pk IS NULL) o editados (needs_push = true)
        $pushedTotal = 0;
        $pushedByType = [];
        $catalogMap = [
            'departments' => Department::class,
            'areas' => Area::class,
            'positions' => Position::class,
            'break_rules' => BreakRule::class,
            'timetables' => Timetable::class,
            'shifts' => Shift::class,
            'devices' => Device::class,
            'employees' => Employee::class,
        ];

        foreach ($catalogMap as $type => $modelClass) {
            $pushedByType[$type] = 0;
            $toPush = $modelClass::where('needs_push', true)
                ->orWhereNull('biotime_pk')
                ->get();

            foreach ($toPush as $localRecord) {
                $pushRes = $this->bioTime->pushSingleRecord($type, $localRecord, $token);
                if (! empty($pushRes['synced'])) {
                    $pushedTotal++;
                    $pushedByType[$type]++;
                }
            }
        }

        // Si se enviaron cambios hacia BioTime Pro, refrescar las listas remotas para el Pull
        if ($pushedTotal > 0) {
            $rawDepartments = $this->bioTime->fetchAllPages($token, '/personnel/api/departments/', ['page_size' => 200]);
            $rawAreas = $this->bioTime->fetchAllPages($token, '/personnel/api/areas/', ['page_size' => 200]);
            $rawPositions = $this->bioTime->fetchAllPages($token, '/personnel/api/positions/', ['page_size' => 200]);
            $rawTerminals = $this->bioTime->fetchAllPages($token, '/iclock/api/terminals/', ['page_size' => 200]);
            $rawBreaks = $this->bioTime->fetchAllPages($token, '/att/api/breaktimes/', ['page_size' => 200]);
            $rawTimetables = $this->bioTime->fetchAllPages($token, '/att/api/timeintervals/', ['page_size' => 200]);
            $rawShifts = $this->bioTime->fetchAllPages($token, '/att/api/attshifts/', ['page_size' => 200]);
            $rawEmployees = $this->bioTime->fetchAllPages($token, '/personnel/api/employees/', ['page_size' => 300]);
        }

        // 3. FASE VUELTA (PULL): Actualizar MariaDB con los datos de BioTime Pro
        $empCountByDeptCode = [];
        $empCountByPosCode = [];
        $empCountByAreaCode = [];
        foreach ($rawEmployees as $emp) {
            $deptCode = (string) ($emp['department']['dept_code'] ?? '');
            if ($deptCode !== '') {
                $empCountByDeptCode[$deptCode] = ($empCountByDeptCode[$deptCode] ?? 0) + 1;
            }
            $posCode = (string) ($emp['position']['position_code'] ?? '');
            if ($posCode !== '') {
                $empCountByPosCode[$posCode] = ($empCountByPosCode[$posCode] ?? 0) + 1;
            }
            foreach (($emp['area'] ?? []) as $ar) {
                $arCode = (string) ($ar['area_code'] ?? '');
                if ($arCode !== '') {
                    $empCountByAreaCode[$arCode] = ($empCountByAreaCode[$arCode] ?? 0) + 1;
                }
            }
        }

        $devCountByAreaCode = [];
        foreach ($rawTerminals as $term) {
            $arCode = (string) ($term['area']['area_code'] ?? '');
            if ($arCode !== '') {
                $devCountByAreaCode[$arCode] = ($devCountByAreaCode[$arCode] ?? 0) + 1;
            }
        }

        $results = [];
        $totalInserted = 0;
        $totalUpdated = 0;
        $totalDuplicates = 0;

        DB::transaction(function () use (
            $rawDepartments,
            $rawAreas,
            $rawPositions,
            $rawTerminals,
            $rawBreaks,
            $rawTimetables,
            $rawShifts,
            $rawEmployees,
            $empCountByDeptCode,
            $empCountByAreaCode,
            $empCountByPosCode,
            $devCountByAreaCode,
            $pushedByType,
            &$results,
            &$totalInserted,
            &$totalUpdated,
            &$totalDuplicates
        ) {
            // 1. Departamentos
            $ins = $upd = $dup = 0;
            foreach ($rawDepartments as $d) {
                $code = trim((string) ($d['dept_code'] ?? ''));
                $name = trim((string) ($d['dept_name'] ?? ''));
                if ($code === '' || $name === '') {
                    continue;
                }
                $parent = is_array($d['parent_dept'] ?? null)
                    ? ($d['parent_dept']['dept_name'] ?? null)
                    : ($d['parent_dept_name'] ?? null);
                $this->upsertRecord(Department::class, ['code' => $code], [
                    'biotime_pk' => (int) ($d['id'] ?? 0) ?: null,
                    'needs_push' => false,
                    'name' => $name,
                    'parent_department' => $parent ?: null,
                    'employee_count' => $empCountByDeptCode[$code] ?? 0,
                ], $ins, $upd, $dup);
            }
            $results[] = ['catalog_type' => 'departments', 'catalog_label' => 'Departamentos', 'pushed' => $pushedByType['departments'], 'inserted' => $ins, 'updated' => $upd, 'duplicates' => $dup];
            $totalInserted += $ins;
            $totalUpdated += $upd;
            $totalDuplicates += $dup;

            // 2. Áreas
            $ins = $upd = $dup = 0;
            foreach ($rawAreas as $a) {
                $code = trim((string) ($a['area_code'] ?? ''));
                $name = trim((string) ($a['area_name'] ?? ''));
                if ($code === '' || $name === '') {
                    continue;
                }
                $parent = is_array($a['parent_area'] ?? null)
                    ? ($a['parent_area']['area_name'] ?? null)
                    : ($a['parent_area_name'] ?? null);
                $this->upsertRecord(Area::class, ['code' => $code], [
                    'biotime_pk' => (int) ($a['id'] ?? 0) ?: null,
                    'needs_push' => false,
                    'name' => $name,
                    'parent_area' => $parent ?: null,
                    'device_count' => $devCountByAreaCode[$code] ?? 0,
                    'employee_count' => $empCountByAreaCode[$code] ?? 0,
                ], $ins, $upd, $dup);
            }
            $results[] = ['catalog_type' => 'areas', 'catalog_label' => 'Áreas', 'pushed' => $pushedByType['areas'], 'inserted' => $ins, 'updated' => $upd, 'duplicates' => $dup];
            $totalInserted += $ins;
            $totalUpdated += $upd;
            $totalDuplicates += $dup;

            // 3. Cargos
            $ins = $upd = $dup = 0;
            foreach ($rawPositions as $p) {
                $code = trim((string) ($p['position_code'] ?? ''));
                $name = trim((string) ($p['position_name'] ?? ''));
                if ($code === '' || $name === '') {
                    continue;
                }
                $this->upsertRecord(Position::class, ['code' => $code], [
                    'biotime_pk' => (int) ($p['id'] ?? 0) ?: null,
                    'needs_push' => false,
                    'name' => $name,
                    'parent_position' => ($p['parent_position_name'] ?? null) ?: null,
                    'employee_count' => $empCountByPosCode[$code] ?? 0,
                ], $ins, $upd, $dup);
            }
            $results[] = ['catalog_type' => 'positions', 'catalog_label' => 'Cargos', 'pushed' => $pushedByType['positions'], 'inserted' => $ins, 'updated' => $upd, 'duplicates' => $dup];
            $totalInserted += $ins;
            $totalUpdated += $upd;
            $totalDuplicates += $dup;

            // 4. Dispositivos
            $ins = $upd = $dup = 0;
            foreach ($rawTerminals as $t) {
                $sn = trim((string) ($t['sn'] ?? ''));
                $name = trim((string) ($t['alias'] ?? $t['terminal_name'] ?? $sn));
                if ($sn === '') {
                    continue;
                }
                $areaName = $t['area_name'] ?? ($t['area']['area_name'] ?? null);
                $this->upsertRecord(Device::class, ['serial_number' => $sn], [
                    'biotime_pk' => (int) ($t['id'] ?? 0) ?: null,
                    'needs_push' => false,
                    'name' => $name,
                    'terminal_name' => $t['terminal_name'] ?? null,
                    'area' => $areaName ?: null,
                    'ip_address' => $t['ip_address'] ?? null,
                    'real_ip' => $t['real_ip'] ?? null,
                    'state' => (int) ($t['state'] ?? 1),
                    'terminal_tz' => (int) ($t['terminal_tz'] ?? -6),
                    'fw_ver' => $t['fw_ver'] ?? null,
                    'push_ver' => $t['push_ver'] ?? null,
                    'transfer_time' => $t['transfer_time'] ?? null,
                    'transfer_interval' => (int) ($t['transfer_interval'] ?? 1),
                    'last_activity' => $t['last_activity'] ?? null,
                    'users_count' => (int) ($t['user_count'] ?? 0),
                    'fingerprints_count' => (int) ($t['fp_count'] ?? 0),
                    'faces_count' => (int) ($t['face_count'] ?? 0),
                    'palms_count' => (int) ($t['palm_count'] ?? 0),
                    'events_count' => (int) ($t['transaction_count'] ?? 0),
                ], $ins, $upd, $dup);
            }
            $results[] = ['catalog_type' => 'devices', 'catalog_label' => 'Dispositivos', 'pushed' => $pushedByType['devices'], 'inserted' => $ins, 'updated' => $upd, 'duplicates' => $dup];
            $totalInserted += $ins;
            $totalUpdated += $upd;
            $totalDuplicates += $dup;

            // 5. Descansos
            $ins = $upd = $dup = 0;
            foreach ($rawBreaks as $b) {
                $name = trim((string) ($b['alias'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $dur = (int) ($b['duration'] ?? 60);
                $this->upsertRecord(BreakRule::class, ['name' => $name], [
                    'biotime_pk' => (int) ($b['id'] ?? 0) ?: null,
                    'needs_push' => false,
                    'start_time' => (string) ($b['period_start'] ?? '00:00:00'),
                    'end_time' => (string) ($b['period_end'] ?? '00:00:00'),
                    'duration_minutes' => $dur,
                    'end_margin' => (int) ($b['end_margin'] ?? $dur),
                    'minimum_duration' => (int) ($b['minimum_duration'] ?? $dur),
                    'multiple_punch' => (int) ($b['multiple_punch'] ?? 1),
                    'calculation_type' => (string) ($b['calc_type'] ?? 'Checada obligatoria'),
                ], $ins, $upd, $dup);
            }
            $results[] = ['catalog_type' => 'break_rules', 'catalog_label' => 'Descansos', 'pushed' => $pushedByType['break_rules'], 'inserted' => $ins, 'updated' => $upd, 'duplicates' => $dup];
            $totalInserted += $ins;
            $totalUpdated += $upd;
            $totalDuplicates += $dup;

            // 6. Horarios
            $ins = $upd = $dup = 0;
            foreach ($rawTimetables as $ti) {
                $name = trim((string) ($ti['alias'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $outTime = (string) ($ti['out_time'] ?? '18:00:00');
                if (! empty($ti['cross_day']) && ! str_contains($outTime, '+')) {
                    $outTime .= '+1';
                }
                $workType = ((int) ($ti['work_type'] ?? 0)) === 1 ? 'Día de Descanso' : 'Trabajo Normal';
                $existingTt = Timetable::where('name', $name)->first();
                $remoteBreakMins = (int) ($ti['break_time_duration'] ?? 0);
                $this->upsertRecord(Timetable::class, ['name' => $name], [
                    'biotime_pk' => (int) ($ti['id'] ?? 0) ?: null,
                    'needs_push' => false,
                    'type' => (string) ($ti['use_mode'] ?? 'Normal'),
                    'check_in' => (string) ($ti['in_time'] ?? '08:00:00'),
                    'check_out' => $outTime,
                    'in_ahead_margin' => (int) ($ti['in_ahead_margin'] ?? 0),
                    'in_above_margin' => (int) ($ti['in_above_margin'] ?? 120),
                    'out_ahead_margin' => (int) ($ti['out_ahead_margin'] ?? 0),
                    'out_above_margin' => (int) ($ti['out_above_margin'] ?? 120),
                    'work_time_minutes' => (int) ($ti['work_time_duration'] ?? $ti['duration'] ?? 0),
                    'break_minutes' => $remoteBreakMins > 0 ? $remoteBreakMins : (int) ($existingTt?->break_minutes ?? 0),
                    'work_day' => (float) ($ti['work_day'] ?? 1.0),
                    'work_type' => $workType,
                    'in_required' => (string) ($ti['in_required'] ?? 'Si'),
                    'out_required' => (string) ($ti['out_required'] ?? 'Si'),
                    'allow_late' => (int) ($ti['allow_late'] ?? 0),
                    'allow_leave_early' => (int) ($ti['allow_leave_early'] ?? 0),
                ], $ins, $upd, $dup);
            }
            $results[] = ['catalog_type' => 'timetables', 'catalog_label' => 'Horarios', 'pushed' => $pushedByType['timetables'], 'inserted' => $ins, 'updated' => $upd, 'duplicates' => $dup];
            $totalInserted += $ins;
            $totalUpdated += $upd;
            $totalDuplicates += $dup;

            // 7. Turnos
            $ins = $upd = $dup = 0;
            foreach ($rawShifts as $sh) {
                $name = trim((string) ($sh['alias'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $this->upsertRecord(Shift::class, ['name' => $name], [
                    'biotime_pk' => (int) ($sh['id'] ?? 0) ?: null,
                    'needs_push' => false,
                    'timetable' => (string) ($sh['shift_timetable'] ?? ''),
                    'unit' => (string) ($sh['cycle_unit'] ?? 'Semana'),
                    'cycle' => (int) ($sh['shift_cycle'] ?? 1),
                    'auto_shift' => ! empty($sh['auto_shift']) ? 'True' : 'False',
                    'work_weekend' => (bool) ($sh['work_weekend'] ?? true),
                ], $ins, $upd, $dup);
            }
            $results[] = ['catalog_type' => 'shifts', 'catalog_label' => 'Turnos', 'pushed' => $pushedByType['shifts'], 'inserted' => $ins, 'updated' => $upd, 'duplicates' => $dup];
            $totalInserted += $ins;
            $totalUpdated += $upd;
            $totalDuplicates += $dup;

            // 8. Empleados
            $ins = $upd = $dup = 0;
            foreach ($rawEmployees as $emp) {
                $empCode = trim((string) ($emp['emp_code'] ?? ''));
                if ($empCode === '') {
                    continue;
                }
                $firstName = trim((string) ($emp['first_name'] ?? ''));
                $lastName = trim((string) ($emp['last_name'] ?? ''));
                $fullName = trim(preg_replace('/\s+/', ' ', "{$firstName} {$lastName}")) ?: $empCode;

                $deptName = $emp['department']['dept_name'] ?? null;
                $posName = $emp['position']['position_name'] ?? null;

                $areasList = [];
                foreach (($emp['area'] ?? []) as $ar) {
                    if (! empty($ar['area_name'])) {
                        $areasList[] = $ar['area_name'];
                    }
                }
                $areasStr = implode(',', $areasList);

                $privCode = (int) ($emp['dev_privilege'] ?? 0);
                $privLabel = match ($privCode) {
                    14 => 'Super administrador',
                    6 => 'Administrador',
                    2 => 'Registrar',
                    default => 'Empleado',
                };

                $appStatus = ((int) ($emp['app_status'] ?? 0)) === 1 ? 'Habilitar' : 'Deshabilitar';

                $payload = [
                    'biotime_pk' => (int) ($emp['id'] ?? 0) ?: null,
                    'needs_push' => false,
                    'name' => $fullName,
                    'first_name' => $firstName ?: $fullName,
                    'last_name' => $lastName ?: null,
                    'nickname' => $emp['nickname'] ?? null,
                    'card_no' => $emp['card_no'] ?? null,
                    'device_password' => $emp['device_password'] ?? null,
                    'department' => $deptName ?: null,
                    'position' => $posName ?: null,
                    'hire_date' => ! empty($emp['hire_date']) ? substr((string) $emp['hire_date'], 0, 10) : null,
                    'gender' => $emp['gender'] ?? null,
                    'birthday' => ! empty($emp['birthday']) ? substr((string) $emp['birthday'], 0, 10) : null,
                    'emp_type' => (int) ($emp['emp_type'] ?? 1),
                    'verify_mode' => (int) ($emp['verify_mode'] ?? 0),
                    'ssn' => $emp['ssn'] ?? null,
                    'mobile' => $emp['mobile'] ?? null,
                    'contact_tel' => $emp['contact_tel'] ?? null,
                    'email' => $emp['email'] ?? null,
                    'city' => $emp['city'] ?? null,
                    'address' => $emp['address'] ?? null,
                    'enable_att' => (bool) ($emp['enable_att'] ?? true),
                    'enable_overtime' => (bool) ($emp['enable_overtime'] ?? false),
                    'enable_holiday' => (bool) ($emp['enable_holiday'] ?? true),
                    'device_privilege' => $privLabel,
                    'area' => $areasStr ?: null,
                    'app_status' => $appStatus,
                ];

                $this->upsertRecord(Employee::class, ['biotime_id' => $empCode], $payload, $ins, $upd, $dup);
            }
            $results[] = ['catalog_type' => 'employees', 'catalog_label' => 'Empleados', 'pushed' => $pushedByType['employees'], 'inserted' => $ins, 'updated' => $upd, 'duplicates' => $dup];
            $totalInserted += $ins;
            $totalUpdated += $upd;
            $totalDuplicates += $dup;
        });

        return response()->json([
            'message' => "Sincronización Ida y Vuelta completada: {$pushedTotal} enviados a BioTime Pro, {$totalInserted} recibidos nuevos, {$totalUpdated} actualizados y {$totalDuplicates} sin cambios.",
            'results' => $results,
            'totals' => [
                'pushed' => $pushedTotal,
                'inserted' => $totalInserted,
                'updated' => $totalUpdated,
                'duplicates' => $totalDuplicates,
            ],
        ]);
    }

    public function syncTransactions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'emp_code' => ['nullable', 'string', 'max:64'],
            'departments' => ['nullable', 'array'],
            'departments.*' => ['string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $selectedDepts = array_values(array_filter(array_map('trim', $validated['departments'] ?? [])));
        $selectedDeptsLower = array_map('mb_strtolower', $selectedDepts);

        $token = $this->bioTime->authenticate();

        $dateFrom = $validated['date_from'];
        $dateTo = $validated['date_to'];
        $nextDayAfterTo = date('Y-m-d', strtotime($dateTo . ' +1 day'));

        $query = [
            'start_time' => "{$dateFrom} 00:00:00",
            'end_time' => "{$nextDayAfterTo} 08:00:00",
            'page_size' => 500,
        ];
        if (! empty($validated['emp_code'])) {
            $query['emp_code'] = trim($validated['emp_code']);
        }

        $rawTransactions = $this->bioTime->fetchAllPages($token, '/iclock/api/transactions/', $query, 80);

        if (empty($rawTransactions)) {
            $dFromDmy = date('d/m/Y', strtotime($dateFrom));
            $dToDmy = date('d/m/Y', strtotime($dateTo));

            return response()->json([
                'message' => "No se encontraron checadas en el servidor BioTime Pro para el periodo {$dFromDmy} al {$dToDmy}.",
                'inserted' => 0,
                'duplicates' => 0,
                'total_fetched' => 0,
            ]);
        }

        $employeesByBiotime = Employee::all()->keyBy('biotime_id');
        if ($employeesByBiotime->count() < 10) {
            $this->syncCatalogs($request);
            $employeesByBiotime = Employee::all()->keyBy('biotime_id');
        }

        $inserted = 0;
        $duplicates = 0;
        $touchedBiotimeIds = [];
        $minDate = null;
        $maxDate = null;
        $validRead = 0;

        $dFromDmy = date('d/m/Y', strtotime($dateFrom));
        $dToDmy = date('d/m/Y', strtotime($dateTo));

        $batch = DB::transaction(function () use (
            $request,
            $validated,
            $dateFrom,
            $dateTo,
            $dFromDmy,
            $dToDmy,
            $rawTransactions,
            $employeesByBiotime,
            $selectedDepts,
            $selectedDeptsLower,
            &$inserted,
            &$duplicates,
            &$touchedBiotimeIds,
            &$minDate,
            &$maxDate,
            &$validRead
        ) {
            $deptSuffix = ! empty($selectedDepts)
                ? ' · Deptos: '.(count($selectedDepts) <= 2 ? implode(', ', $selectedDepts) : count($selectedDepts).' seleccionados')
                : '';
            $batchLabel = "API BioTime Pro ({$dFromDmy} al {$dToDmy})"
                .(! empty($validated['emp_code']) ? " · ID {$validated['emp_code']}" : '')
                .$deptSuffix;

            $batch = ImportBatch::create([
                'user_id' => $request->user()?->id,
                'filenames' => [$batchLabel],
                'source_type' => 'api',
                'total_rows' => count($rawTransactions),
                'notes' => $validated['notes'] ?: "Sincronización directa desde API BioTime Pro ({$dFromDmy} al {$dToDmy}){$deptSuffix}",
            ]);

            foreach ($rawTransactions as $tx) {
                $biotimeId = trim((string) ($tx['emp_code'] ?? ''));
                $punchTime = trim((string) ($tx['punch_time'] ?? ''));
                if ($biotimeId === '' || strlen($punchTime) < 16) {
                    continue;
                }

                $emp = $employeesByBiotime->get($biotimeId);
                if (! empty($selectedDeptsLower)) {
                    $empDept = mb_strtolower(trim((string) ($emp?->department ?? $tx['department'] ?? '')));
                    if ($empDept === '' || ! in_array($empDept, $selectedDeptsLower, true)) {
                        continue;
                    }
                }

                $datePart = substr($punchTime, 0, 10);
                $timePart = substr($punchTime, 11, 5);

                if ($datePart < $dateFrom) {
                    continue;
                }
                if ($datePart > $dateTo && $timePart >= '08:00') {
                    continue;
                }

                $validRead++;
                $touchedBiotimeIds[$biotimeId] = true;
                if ($minDate === null || $datePart < $minDate) {
                    $minDate = $datePart;
                }
                if ($maxDate === null || $datePart > $maxDate) {
                    $maxDate = $datePart;
                }

                if (! $emp) {
                    $emp = Employee::create([
                        'biotime_id' => $biotimeId,
                        'name' => "Empleado {$biotimeId}",
                        'department' => $tx['area_alias'] ?? null,
                        'active' => true,
                    ]);
                    $employeesByBiotime->put($biotimeId, $emp);
                }

                $existingEvent = AttendanceEvent::where('employee_id', $emp->id)
                    ->where('event_date', $datePart)
                    ->where('event_time', $timePart)
                    ->first();

                if ($existingEvent) {
                    if (! $existingEvent->is_visible) {
                        $existingEvent->update(['is_visible' => true]);
                    }
                    $duplicates++;
                } else {
                    AttendanceEvent::create([
                        'employee_id' => $emp->id,
                        'biotime_id' => $biotimeId,
                        'import_batch_id' => $batch->id,
                        'event_date' => $datePart,
                        'event_time' => $timePart,
                        'attendance_status' => (string) ($tx['punch_state'] ?? '0'),
                        'temperature' => isset($tx['temperature']) ? (string) $tx['temperature'] : null,
                        'mask' => isset($tx['is_mask']) ? (string) $tx['is_mask'] : null,
                        'data_source' => $tx['terminal_alias'] ?? $tx['terminal_sn'] ?? 'API BioTime',
                        'is_visible' => true,
                    ]);
                    $inserted++;
                }
            }

            $batch->update([
                'total_rows' => $validRead,
                'inserted_events' => $inserted,
                'duplicate_events' => $duplicates,
                'employees_touched' => count($touchedBiotimeIds),
                'date_from' => $minDate ?? $dateFrom,
                'date_to' => $maxDate ?? $dateTo,
            ]);

            return $batch;
        });

        return response()->json([
            'message' => "Sincronización API BioTime Pro completada ({$dFromDmy} al {$dToDmy}): {$inserted} checadas nuevas guardadas y {$duplicates} duplicadas omitidas (de {$validRead} leídas en el servidor).",
            'batch' => $batch,
            'inserted' => $inserted,
            'duplicates' => $duplicates,
            'total_fetched' => $validRead,
        ]);
    }

    private function linkRemotePks(string $modelClass, string $localKey, array $remoteItems, string $remoteKey): void
    {
        $remoteMap = [];
        foreach ($remoteItems as $item) {
            $k = trim((string) ($item[$remoteKey] ?? ''));
            if ($k !== '' && ! empty($item['id'])) {
                $remoteMap[$k] = (int) $item['id'];
            }
        }

        if (empty($remoteMap)) {
            return;
        }

        $unlinked = $modelClass::whereNull('biotime_pk')->get();
        foreach ($unlinked as $rec) {
            $val = trim((string) ($rec->{$localKey} ?? ''));
            if ($val !== '' && isset($remoteMap[$val])) {
                $rec->updateQuietly(['biotime_pk' => $remoteMap[$val]]);
            }
        }
    }

    private function upsertRecord(string $modelClass, array $unique, array $payload, int &$ins, int &$upd, int &$dup): void
    {
        $existing = $modelClass::where($unique)->first();
        if (! $existing) {
            $modelClass::create(array_merge($unique, $payload));
            $ins++;

            return;
        }

        // Si el registro local aún tiene needs_push = true (p.ej. falló su envío), no sobreescribir sus datos locales
        if ($existing->needs_push) {
            if (! $existing->biotime_pk && ! empty($payload['biotime_pk'])) {
                $existing->updateQuietly(['biotime_pk' => $payload['biotime_pk']]);
            }
            $dup++;

            return;
        }

        $existing->fill($payload);
        if ($existing->isDirty()) {
            $existing->save();
            $upd++;
        } else {
            $dup++;
        }
    }
}
