<?php

namespace App\Services;

use App\Models\Area;
use App\Models\BreakRule;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Shift;
use App\Models\Timetable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class BioTimeApiService
{
    public function getBaseUrl(): string
    {
        return rtrim((string) config('services.biotime.url', 'http://relojesingenieria.myftp.biz:8088'), '/');
    }

    public function authenticate(?string $customUser = null, ?string $customPass = null): string
    {
        $baseUrl = $this->getBaseUrl();
        $primaryUser = $customUser ?: (string) config('services.biotime.username', 'Ingenieria');
        $password = $customPass ?: (string) config('services.biotime.password', 'Ingenieria89');

        $candidates = array_unique([$primaryUser, 'Ingenieria', 'Ingeniera']);

        foreach ($candidates as $user) {
            $response = Http::timeout(10)
                ->acceptJson()
                ->post("{$baseUrl}/api-token-auth/", [
                    'username' => $user,
                    'password' => $password,
                ]);

            if ($response->successful() && $response->json('token')) {
                return (string) $response->json('token');
            }
        }

        throw new \RuntimeException('No se pudo autenticar con el servidor BioTime Pro.');
    }

    public function fetchAllPages(string $token, string $endpoint, array $query = [], int $maxPages = 50): array
    {
        $baseUrl = $this->getBaseUrl();
        $page = 1;
        $all = [];
        $pageSize = $query['page_size'] ?? 300;

        while ($page <= $maxPages) {
            $response = Http::timeout(20)
                ->withHeaders([
                    'Authorization' => "Token {$token}",
                    'Accept' => 'application/json',
                ])
                ->get("{$baseUrl}{$endpoint}", array_merge($query, [
                    'page' => $page,
                    'page_size' => $pageSize,
                ]));

            if (! $response->successful()) {
                break;
            }

            $json = $response->json();
            $data = $json['data'] ?? [];
            if (! is_array($data) || empty($data)) {
                break;
            }

            foreach ($data as $item) {
                $all[] = $item;
            }

            if (empty($json['next'])) {
                break;
            }
            $page++;
        }

        return $all;
    }

    public function endpointForType(string $type): string
    {
        return match ($type) {
            'departments' => '/personnel/api/departments/',
            'areas' => '/personnel/api/areas/',
            'positions' => '/personnel/api/positions/',
            'devices' => '/iclock/api/terminals/',
            'break_rules' => '/att/api/breaktimes/',
            'timetables' => '/att/api/timeintervals/',
            'shifts' => '/att/api/attshifts/',
            'holidays' => '/att/api/holidays/',
            'employees' => '/personnel/api/employees/',
            default => throw new \InvalidArgumentException("Catálogo no soportado: {$type}"),
        };
    }

    private function normalizeTimeHms(?string $t, string $default = '08:00:00'): string
    {
        $s = trim(str_replace('+1', '', (string) ($t ?? '')));
        if ($s === '') {
            return $default;
        }
        if (preg_match('/^\d{1,2}:\d{2}$/', $s)) {
            $parts = explode(':', $s);

            return str_pad($parts[0], 2, '0', STR_PAD_LEFT).':'.$parts[1].':00';
        }
        if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $s)) {
            $parts = explode(':', $s);

            return str_pad($parts[0], 2, '0', STR_PAD_LEFT).':'.$parts[1].':'.$parts[2];
        }

        return $default;
    }

    private function diffMinutesBetweenTimes(string $startHms, string $endHms, int $fallback = 60): int
    {
        $sParts = array_map('intval', explode(':', $startHms));
        $eParts = array_map('intval', explode(':', $endHms));
        if (count($sParts) < 2 || count($eParts) < 2) {
            return $fallback;
        }
        $sMin = $sParts[0] * 60 + $sParts[1];
        $eMin = $eParts[0] * 60 + $eParts[1];
        $diff = $eMin - $sMin;
        if ($diff <= 0) {
            $diff += 1440;
        }

        return $diff > 0 ? $diff : $fallback;
    }

    public function buildRemotePayload(string $type, Model $record): array
    {
        if ($type === 'departments') {
            $parentPk = null;
            if (! empty($record->parent_department)) {
                $parentPk = Department::where('name', $record->parent_department)
                    ->orWhere('code', $record->parent_department)
                    ->value('biotime_pk');
            }

            return [
                'dept_code' => (string) $record->code,
                'dept_name' => (string) $record->name,
                'parent_dept' => $parentPk ? (int) $parentPk : null,
            ];
        }

        if ($type === 'areas') {
            $parentPk = null;
            if (! empty($record->parent_area)) {
                $parentPk = Area::where('name', $record->parent_area)
                    ->orWhere('code', $record->parent_area)
                    ->value('biotime_pk');
            }

            return [
                'area_code' => (string) $record->code,
                'area_name' => (string) $record->name,
                'parent_area' => $parentPk ? (int) $parentPk : null,
            ];
        }

        if ($type === 'positions') {
            $parentPk = null;
            if (! empty($record->parent_position)) {
                $parentPk = Position::where('name', $record->parent_position)
                    ->orWhere('code', $record->parent_position)
                    ->value('biotime_pk');
            }

            return [
                'position_code' => (string) $record->code,
                'position_name' => (string) $record->name,
                'parent_position' => $parentPk ? (int) $parentPk : null,
            ];
        }

        if ($type === 'devices') {
            $areaPk = 1;
            if (! empty($record->area)) {
                $foundArea = Area::where('name', $record->area)
                    ->orWhere('code', $record->area)
                    ->value('biotime_pk');
                if ($foundArea) {
                    $areaPk = (int) $foundArea;
                }
            }

            return [
                'sn' => (string) $record->serial_number,
                'alias' => (string) $record->name,
                'ip_address' => (string) ($record->ip_address ?: '192.168.1.200'),
                'area' => $areaPk,
                'terminal_tz' => (int) ($record->terminal_tz ?? -6),
                'transfer_interval' => (int) ($record->transfer_interval ?? 1),
            ];
        }

        if ($type === 'break_rules') {
            $startHms = $this->normalizeTimeHms($record->start_time, '13:00:00');
            $endHms = $this->normalizeTimeHms($record->end_time, '14:00:00');
            $dur = (int) ($record->duration_minutes ?: 60);
            $endMargin = (int) ($record->end_margin ?: $this->diffMinutesBetweenTimes($startHms, $endHms, $dur));
            $minDur = (int) ($record->minimum_duration ?: $dur);

            $calcStr = strtolower((string) ($record->calculation_type ?? ''));
            $calcInt = (str_contains($calcStr, 'auto') || $calcStr === '0') ? 0 : 1;

            return [
                'alias' => (string) $record->name,
                'period_start' => $startHms,
                'duration' => $dur,
                'end_margin' => $endMargin,
                'minimum_duration' => $minDur,
                'multiple_punch' => (int) ($record->multiple_punch ?? 1),
                'calc_type' => $calcInt,
                'company' => 1,
            ];
        }

        if ($type === 'timetables') {
            $inHms = $this->normalizeTimeHms($record->check_in, '08:00:00');
            $outHms = $this->normalizeTimeHms($record->check_out, '18:00:00');
            $spanDuration = $this->diffMinutesBetweenTimes($inHms, $outHms, 540);
            $workMins = (int) ($record->work_time_minutes ?: $spanDuration);

            $modeStr = strtolower((string) ($record->type ?? 'normal'));
            $useModeInt = str_contains($modeStr, 'flex') ? 1 : 0;

            $breakIds = is_array($record->break_rule_ids) && count($record->break_rule_ids) > 0
                ? $record->break_rule_ids
                : ($record->break_rule_id ? [$record->break_rule_id] : []);

            $breakPks = ($record->break_mode === 'none' || empty($breakIds))
                ? []
                : BreakRule::whereIn('id', $breakIds)
                    ->whereNotNull('biotime_pk')
                    ->pluck('biotime_pk')
                    ->map(fn ($v) => (int) $v)
                    ->values()
                    ->all();

            return [
                'alias' => (string) $record->name,
                'use_mode' => $useModeInt,
                'use_24_mode' => ! empty($record->is_24h) ? 1 : 0,
                'in_time' => $inHms,
                'in_ahead_margin' => (int) ($record->in_ahead_margin ?? 0),
                'in_above_margin' => (int) ($record->in_above_margin ?? 120),
                'out_ahead_margin' => (int) ($record->out_ahead_margin ?? 0),
                'out_above_margin' => (int) ($record->out_above_margin ?? 120),
                'duration' => $spanDuration,
                'work_time_duration' => $workMins,
                'work_day' => (float) ($record->work_day ?: 1.0),
                'work_type' => str_contains(strtolower((string) $record->work_type), 'descanso') ? 1 : 0,
                'in_required' => strtolower((string) ($record->in_required ?? 'si')) === 'no' ? 0 : 1,
                'out_required' => strtolower((string) ($record->out_required ?? 'si')) === 'no' ? 0 : 1,
                'allow_late' => (int) ($record->allow_late ?? 0),
                'allow_leave_early' => (int) ($record->allow_leave_early ?? 0),
                'early_in' => ($record->early_in_mode ?? 'ignore') === 'count' ? 1 : 0,
                'min_early_in' => (int) ($record->early_in_minutes ?? 60),
                'late_out' => ($record->late_out_mode ?? 'ignore') === 'count' ? 1 : 0,
                'min_late_out' => (int) ($record->late_out_minutes ?? 60),
                'overtime_lv' => ($record->overtime_level ?? 'ignore') === 'levels' ? 1 : 0,
                'overtime_lv1' => (float) ($record->overtime_level1_hours ?? 0),
                'overtime_lv2' => (float) ($record->overtime_level2_hours ?? 0),
                'overtime_lv3' => (float) ($record->overtime_level3_hours ?? 0),
                'break_time' => $breakPks,
                'company' => 1,
            ];
        }

        if ($type === 'shifts') {
            $unitStr = strtolower((string) ($record->unit ?? 'semana'));
            $unitInt = match (true) {
                str_contains($unitStr, 'mes') => 2,
                str_contains($unitStr, 'dia') || str_contains($unitStr, 'día') => 0,
                default => 1,
            };

            return [
                'alias' => (string) $record->name,
                'cycle_unit' => $unitInt,
                'shift_cycle' => (int) ($record->cycle ?: 1),
                'auto_shift' => strtolower((string) $record->auto_shift) === 'true',
                'work_weekend' => (bool) ($record->work_weekend ?? true),
                'company' => 1,
            ];
        }

        if ($type === 'holidays') {
            $deptPk = null;
            if (! empty($record->department_name)) {
                $foundDept = Department::where('name', $record->department_name)
                    ->orWhere('code', $record->department_name)
                    ->value('biotime_pk');
                if ($foundDept) {
                    $deptPk = (int) $foundDept;
                }
            }

            $payload = [
                'alias' => (string) $record->name,
                'start_date' => $record->start_date instanceof \DateTimeInterface
                    ? $record->start_date->format('Y-m-d')
                    : substr((string) $record->start_date, 0, 10),
                'duration_day' => (int) ($record->duration_days ?: 1),
            ];
            if ($deptPk) {
                $payload['department'] = $deptPk;
            }

            return $payload;
        }

        if ($type === 'employees') {
            $deptPk = 1;
            if (! empty($record->department)) {
                $foundDept = Department::where('name', $record->department)
                    ->orWhere('code', $record->department)
                    ->value('biotime_pk');
                if ($foundDept) {
                    $deptPk = (int) $foundDept;
                } else {
                    $firstDept = Department::whereNotNull('biotime_pk')->value('biotime_pk');
                    if ($firstDept) {
                        $deptPk = (int) $firstDept;
                    }
                }
            }

            $areaPks = [];
            if (! empty($record->area)) {
                $areaNames = array_values(array_filter(array_map('trim', explode(',', (string) $record->area))));
                if (! empty($areaNames)) {
                    $areaPks = Area::whereIn('name', $areaNames)
                        ->whereNotNull('biotime_pk')
                        ->pluck('biotime_pk')
                        ->map(fn ($v) => (int) $v)
                        ->all();
                }
            }
            if (empty($areaPks)) {
                $defaultAreaPk = Area::whereNotNull('biotime_pk')->value('biotime_pk') ?: 1;
                $areaPks = [(int) $defaultAreaPk];
            }

            $posPk = null;
            if (! empty($record->position)) {
                $foundPos = Position::where('name', $record->position)
                    ->orWhere('code', $record->position)
                    ->value('biotime_pk');
                if ($foundPos) {
                    $posPk = (int) $foundPos;
                }
            }

            $privLabel = strtolower(trim((string) ($record->device_privilege ?? 'empleado')));
            $privCode = match (true) {
                str_contains($privLabel, 'super') => 14,
                str_contains($privLabel, 'admin') => 6,
                str_contains($privLabel, 'reg') => 2,
                default => 0,
            };

            $appStatus = str_contains(strtolower((string) $record->app_status), 'hab') && ! str_contains(strtolower((string) $record->app_status), 'deshab')
                ? 1
                : 0;

            $firstName = trim((string) ($record->first_name ?? ''));
            $lastName = trim((string) ($record->last_name ?? ''));
            if ($firstName === '' && $lastName === '') {
                $words = preg_split('/\s+/', trim((string) $record->name)) ?: [];
                if (count($words) >= 3) {
                    $firstName = implode(' ', array_slice($words, 0, -2));
                    $lastName = implode(' ', array_slice($words, -2));
                } elseif (count($words) === 2) {
                    $firstName = $words[0];
                    $lastName = $words[1];
                } else {
                    $firstName = trim((string) $record->name);
                    $lastName = '';
                }
            }

            $payload = [
                'emp_code' => (string) $record->biotime_id,
                'first_name' => $firstName,
                'last_name' => $lastName ?: null,
                'nickname' => $record->nickname ?: null,
                'card_no' => $record->card_no ?: null,
                'device_password' => $record->device_password ?: null,
                'department' => $deptPk,
                'area' => $areaPks,
                'hire_date' => $record->hire_date instanceof \DateTimeInterface
                    ? $record->hire_date->format('Y-m-d')
                    : ($record->hire_date ?: null),
                'gender' => $record->gender ?: null,
                'birthday' => $record->birthday instanceof \DateTimeInterface
                    ? $record->birthday->format('Y-m-d')
                    : ($record->birthday ?: null),
                'emp_type' => (int) ($record->emp_type ?? 1),
                'verify_mode' => (int) ($record->verify_mode ?? 0),
                'ssn' => $record->ssn ?: null,
                'mobile' => $record->mobile ?: null,
                'contact_tel' => $record->contact_tel ?: null,
                'email' => $record->email ?: null,
                'city' => $record->city ?: null,
                'address' => $record->address ?: null,
                'enable_att' => (bool) ($record->enable_att ?? true),
                'enable_overtime' => (bool) ($record->enable_overtime ?? false),
                'enable_holiday' => (bool) ($record->enable_holiday ?? true),
                'dev_privilege' => $privCode,
                'app_status' => $appStatus,
            ];
            if ($posPk) {
                $payload['position'] = $posPk;
            }

            return $payload;
        }

        return [];
    }

    private function resolveRemotePkFromApi(string $type, Model $record, string $token): ?int
    {
        $baseUrl = $this->getBaseUrl();
        $endpoint = $this->endpointForType($type);

        [$queryParam, $localVal, $remoteField] = match ($type) {
            'departments' => ['dept_code', (string) $record->code, 'dept_code'],
            'areas' => ['area_code', (string) $record->code, 'area_code'],
            'positions' => ['position_code', (string) $record->code, 'position_code'],
            'devices' => ['sn', (string) $record->serial_number, 'sn'],
            'break_rules', 'timetables', 'shifts' => ['alias', (string) $record->name, 'alias'],
            'employees' => ['emp_code', (string) $record->biotime_id, 'emp_code'],
            default => [null, '', ''],
        };

        if (! $queryParam || $localVal === '') {
            return null;
        }

        $res = Http::timeout(8)
            ->withHeaders(['Authorization' => "Token {$token}", 'Accept' => 'application/json'])
            ->get("{$baseUrl}{$endpoint}", [$queryParam => $localVal, 'page_size' => 50]);

        if (! $res->successful()) {
            return null;
        }

        foreach (($res->json('data') ?? []) as $item) {
            if (trim((string) ($item[$remoteField] ?? '')) === trim($localVal) && ! empty($item['id'])) {
                return (int) $item['id'];
            }
        }

        return null;
    }

    /**
     * Envía (POST o PATCH/PUT) un registro local inmediatamente a BioTime Pro.
     */
    public function pushSingleRecord(string $type, Model $record, ?string $token = null): array
    {
        try {
            $token = $token ?: $this->authenticate();
            $baseUrl = $this->getBaseUrl();
            $endpoint = $this->endpointForType($type);
            $payload = $this->buildRemotePayload($type, $record);

            $headers = [
                'Authorization' => "Token {$token}",
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ];

            if (! $record->biotime_pk) {
                $foundPk = $this->resolveRemotePkFromApi($type, $record, $token);
                if ($foundPk) {
                    $record->biotime_pk = $foundPk;
                    $record->updateQuietly(['biotime_pk' => $foundPk]);
                }
            }

            if ($record->biotime_pk) {
                $usePutFirst = in_array($type, ['departments', 'areas', 'positions'], true);
                $primaryMethod = $usePutFirst ? 'put' : 'patch';
                $fallbackMethod = $usePutFirst ? 'patch' : 'put';

                $res = Http::timeout(12)
                    ->withHeaders($headers)
                    ->{$primaryMethod}("{$baseUrl}{$endpoint}{$record->biotime_pk}/", $payload);

                if (! $res->successful()) {
                    $res = Http::timeout(12)
                        ->withHeaders($headers)
                        ->{$fallbackMethod}("{$baseUrl}{$endpoint}{$record->biotime_pk}/", $payload);
                }

                if ($res->successful()) {
                    $record->updateQuietly(['needs_push' => false]);

                    return ['synced' => true, 'action' => 'updated', 'biotime_pk' => $record->biotime_pk];
                }
            }

            // Crear (POST) en BioTime Pro
            $res = Http::timeout(12)
                ->withHeaders($headers)
                ->post("{$baseUrl}{$endpoint}", $payload);

            if ($res->successful()) {
                $remoteId = (int) ($res->json('id') ?? 0);
                if (! $remoteId) {
                    $remoteId = $this->resolveRemotePkFromApi($type, $record, $token) ?? 0;
                }
                $record->updateQuietly([
                    'biotime_pk' => $remoteId ?: $record->biotime_pk,
                    'needs_push' => false,
                ]);

                return ['synced' => true, 'action' => 'created', 'biotime_pk' => $remoteId ?: null];
            }

            $record->updateQuietly(['needs_push' => true]);

            return [
                'synced' => false,
                'error' => $res->body(),
            ];
        } catch (\Throwable $e) {
            $record->updateQuietly(['needs_push' => true]);

            return [
                'synced' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Elimina un registro en BioTime Pro o lo encola en deleted_catalog_items si el servidor no responde.
     */
    public function deleteRemoteRecord(string $type, ?int $biotimePk, ?string $uniqueKey = null): bool
    {
        if (! $biotimePk) {
            return false;
        }

        try {
            $token = $this->authenticate();
            $baseUrl = $this->getBaseUrl();
            $endpoint = $this->endpointForType($type);

            $res = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => "Token {$token}",
                    'Accept' => 'application/json',
                ])
                ->delete("{$baseUrl}{$endpoint}{$biotimePk}/");

            if ($res->successful() || $res->status() === 404) {
                return true;
            }
        } catch (\Throwable) {
            // Falló conexión; encolar para el próximo sync
        }

        DB::table('deleted_catalog_items')->insert([
            'catalog_type' => $type,
            'biotime_pk' => $biotimePk,
            'unique_key' => $uniqueKey,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return false;
    }

    /**
     * Envía (POST o PATCH/PUT) una asignación de horario de empleado a /att/api/attschedules/ en BioTime Pro.
     */
    public function pushEmployeeSchedule(Model $schedule, ?string $token = null): array
    {
        try {
            if (! $schedule->biotime_emp_pk || ! $schedule->biotime_shift_pk) {
                $schedule->updateQuietly(['needs_push' => true]);

                return [
                    'synced' => false,
                    'error' => 'Falta biotime_pk del empleado o del turno para sincronizar con BioTime Pro.',
                ];
            }

            $token = $token ?: $this->authenticate();
            $baseUrl = $this->getBaseUrl();
            $headers = [
                'Authorization' => "Token {$token}",
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ];

            $payload = [
                'employee' => (int) $schedule->biotime_emp_pk,
                'shift' => (int) $schedule->biotime_shift_pk,
                'start_date' => $schedule->start_date instanceof \DateTimeInterface
                    ? $schedule->start_date->format('Y-m-d')
                    : substr((string) $schedule->start_date, 0, 10),
                'end_date' => $schedule->end_date instanceof \DateTimeInterface
                    ? $schedule->end_date->format('Y-m-d')
                    : substr((string) $schedule->end_date, 0, 10),
            ];

            if ($schedule->biotime_pk) {
                $res = Http::timeout(12)
                    ->withHeaders($headers)
                    ->patch("{$baseUrl}/att/api/attschedules/{$schedule->biotime_pk}/", $payload);

                if (! $res->successful()) {
                    $res = Http::timeout(12)
                        ->withHeaders($headers)
                        ->put("{$baseUrl}/att/api/attschedules/{$schedule->biotime_pk}/", $payload);
                }

                if ($res->successful()) {
                    $schedule->updateQuietly(['needs_push' => false]);

                    return ['synced' => true, 'action' => 'updated', 'biotime_pk' => $schedule->biotime_pk];
                }
            }

            $res = Http::timeout(12)
                ->withHeaders($headers)
                ->post("{$baseUrl}/att/api/attschedules/", $payload);

            if ($res->successful()) {
                $remoteId = (int) ($res->json('id') ?? $res->json('data.id') ?? 0);
                if (! $remoteId) {
                    // Buscar el ID recién creado en /att/api/attschedules/
                    $listRes = Http::timeout(10)
                        ->withHeaders(['Authorization' => "Token {$token}", 'Accept' => 'application/json'])
                        ->get("{$baseUrl}/att/api/attschedules/", [
                            'employee' => (int) $schedule->biotime_emp_pk,
                            'page_size' => 50,
                        ]);
                    if ($listRes->successful()) {
                        foreach (($listRes->json('data') ?? []) as $item) {
                            if (
                                (int) ($item['employee'] ?? 0) === (int) $schedule->biotime_emp_pk &&
                                (int) ($item['shift'] ?? 0) === (int) $schedule->biotime_shift_pk &&
                                substr((string) ($item['start_date'] ?? ''), 0, 10) === $payload['start_date']
                            ) {
                                $remoteId = (int) ($item['id'] ?? 0);
                                break;
                            }
                        }
                    }
                }

                $schedule->updateQuietly([
                    'biotime_pk' => $remoteId ?: $schedule->biotime_pk,
                    'needs_push' => false,
                ]);

                return ['synced' => true, 'action' => 'created', 'biotime_pk' => $remoteId ?: null];
            }

            $schedule->updateQuietly(['needs_push' => true]);

            return ['synced' => false, 'error' => $res->body()];
        } catch (\Throwable $e) {
            $schedule->updateQuietly(['needs_push' => true]);

            return ['synced' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Elimina una asignación de horario en /att/api/attschedules/{id}/ en BioTime Pro.
     */
    public function deleteRemoteSchedule(?int $biotimePk, ?string $token = null): bool
    {
        if (! $biotimePk) {
            return false;
        }

        try {
            $token = $token ?: $this->authenticate();
            $baseUrl = $this->getBaseUrl();

            $res = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => "Token {$token}",
                    'Accept' => 'application/json',
                ])
                ->delete("{$baseUrl}/att/api/attschedules/{$biotimePk}/");

            return $res->successful() || $res->status() === 404;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Envía una ausencia/incapacidad/vacación a /att/api/leaves/ en BioTime Pro.
     */
    public function pushEmployeeLeave(Model $leave, ?string $token = null): array
    {
        try {
            if (! $leave->biotime_emp_pk || ! $leave->biotime_category_id) {
                $leave->updateQuietly(['needs_push' => true]);

                return [
                    'synced' => false,
                    'error' => 'Falta biotime_pk del empleado o categoría para BioTime Pro.',
                ];
            }

            $token = $token ?: $this->authenticate();
            $baseUrl = $this->getBaseUrl();
            $headers = [
                'Authorization' => "Token {$token}",
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ];

            $startDate = $leave->start_date instanceof \DateTimeInterface
                ? $leave->start_date->format('Y-m-d')
                : substr((string) $leave->start_date, 0, 10);
            $endDate = $leave->end_date instanceof \DateTimeInterface
                ? $leave->end_date->format('Y-m-d')
                : substr((string) $leave->end_date, 0, 10);

            $payload = [
                'employee' => (int) $leave->biotime_emp_pk,
                'category' => (int) $leave->biotime_category_id,
                'start_time' => "{$startDate} 00:00:00",
                'end_time' => "{$endDate} 23:59:59",
                'apply_reason' => (string) ($leave->reason ?: $leave->category_name ?: 'Registrado en TimeXis'),
            ];

            $res = Http::timeout(12)
                ->withHeaders($headers)
                ->post("{$baseUrl}/att/api/leaves/", $payload);

            if ($res->successful()) {
                $remoteId = (int) ($res->json('id') ?? $res->json('data.id') ?? 0);
                $leave->updateQuietly([
                    'biotime_pk' => $remoteId ?: $leave->biotime_pk,
                    'needs_push' => false,
                ]);

                return ['synced' => true, 'biotime_pk' => $remoteId ?: null];
            }

            $leave->updateQuietly(['needs_push' => true]);

            return ['synced' => false, 'error' => $res->body()];
        } catch (\Throwable $e) {
            $leave->updateQuietly(['needs_push' => true]);

            return ['synced' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Elimina una ausencia/incapacidad/vacación en /att/api/leaves/{id}/ en BioTime Pro.
     */
    public function deleteRemoteLeave(?int $biotimePk, ?string $token = null): bool
    {
        if (! $biotimePk) {
            return false;
        }

        try {
            $token = $token ?: $this->authenticate();
            $baseUrl = $this->getBaseUrl();

            $res = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => "Token {$token}",
                    'Accept' => 'application/json',
                ])
                ->delete("{$baseUrl}/att/api/leaves/{$biotimePk}/");

            return $res->successful() || $res->status() === 404;
        } catch (\Throwable) {
            return false;
        }
    }
}
