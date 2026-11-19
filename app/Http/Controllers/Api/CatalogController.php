<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\BreakRule;
use App\Models\Company;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\ImportBatch;
use App\Models\Position;
use App\Models\Shift;
use App\Models\Timetable;
use App\Services\BioTimeApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogController extends Controller
{
    public function __construct(
        private readonly BioTimeApiService $bioTime
    ) {}
    public function index(): JsonResponse
    {
        $employees = Employee::withCount(['events', 'corrections'])
            ->orderByRaw('CAST(biotime_id AS UNSIGNED), biotime_id')
            ->get();

        $areas = Area::orderByRaw('CAST(code AS UNSIGNED), code')->get();
        $positions = Position::orderByRaw('CAST(code AS UNSIGNED), code')->get();
        $departments = Department::orderByRaw('CAST(code AS UNSIGNED), code')->get();
        $breakRules = BreakRule::orderBy('name')->get();
        $devices = Device::orderBy('name')->get();
        $timetables = Timetable::orderBy('name')->get();
        $shifts = Shift::orderBy('name')->get();
        $holidays = Holiday::orderBy('start_date')->get();
        $companies = Company::withCount('employees')->orderBy('business_name')->get();

        return response()->json([
            'employees' => $employees,
            'areas' => $areas,
            'positions' => $positions,
            'departments' => $departments,
            'break_rules' => $breakRules,
            'devices' => $devices,
            'timetables' => $timetables,
            'shifts' => $shifts,
            'holidays' => $holidays,
            'companies' => $companies,
            'counts' => [
                'employees' => $employees->count(),
                'areas' => $areas->count(),
                'positions' => $positions->count(),
                'departments' => $departments->count(),
                'break_rules' => $breakRules->count(),
                'devices' => $devices->count(),
                'timetables' => $timetables->count(),
                'shifts' => $shifts->count(),
                'holidays' => $holidays->count(),
                'companies' => $companies->count(),
            ],
        ]);
    }

    public function importCsv(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*.filename' => ['required', 'string', 'max:255'],
            'files.*.content' => ['required', 'string'],
            'files.*.catalog_type' => ['nullable', 'string'],
            'update_existing' => ['nullable', 'boolean'],
        ]);

        $updateExisting = $validated['update_existing'] ?? true;
        $results = [];
        $totalInserted = 0;
        $totalUpdated = 0;
        $totalDuplicates = 0;
        $totalRead = 0;
        $filenames = [];

        DB::transaction(function () use (
            $validated,
            $updateExisting,
            &$results,
            &$totalInserted,
            &$totalUpdated,
            &$totalDuplicates,
            &$totalRead,
            &$filenames
        ) {
            foreach ($validated['files'] as $fileItem) {
                $filename = $fileItem['filename'];
                $filenames[] = $filename;
                $rows = $this->parseCsvString($fileItem['content']);

                if (count($rows) < 2) {
                    throw ValidationException::withMessages([
                        'files' => ["El archivo '{$filename}' está vacío o no contiene filas válidas."],
                    ]);
                }

                $headers = array_shift($rows);
                $normHeaders = array_map([$this, 'normalizeHeader'], $headers);

                $detectedType = $this->detectCatalogType($normHeaders, $fileItem['catalog_type'] ?? null);
                if (! $detectedType) {
                    throw ValidationException::withMessages([
                        'files' => ["No se pudo identificar el tipo de catálogo para '{$filename}'. Verifica las columnas del CSV."],
                    ]);
                }

                $summary = $this->importRowsForType($detectedType, $normHeaders, $rows, $updateExisting);
                $totalRead += $summary['total'];
                $totalInserted += $summary['inserted'];
                $totalUpdated += $summary['updated'];
                $totalDuplicates += $summary['duplicates'];

                $results[] = [
                    'filename' => $filename,
                    'catalog_type' => $detectedType,
                    'catalog_label' => $this->catalogLabel($detectedType),
                    ...$summary,
                ];
            }
        });

        return response()->json([
            'message' => "Importación completada: {$totalInserted} nuevos, {$totalUpdated} actualizados y {$totalDuplicates} duplicados sin cambios (de {$totalRead} filas leídas).",
            'results' => $results,
            'totals' => [
                'read' => $totalRead,
                'inserted' => $totalInserted,
                'updated' => $totalUpdated,
                'duplicates' => $totalDuplicates,
            ],
        ]);
    }

    public function store(Request $request, string $type): JsonResponse
    {
        if ($type === 'timetables') {
            $this->assertNoOverlappingBreakRules($request->input('break_rule_ids', []));
        }

        $record = match ($type) {
            'areas' => Area::create($request->validate([
                'code' => ['required', 'string', 'max:64', 'unique:areas,code'],
                'name' => ['required', 'string', 'max:255'],
                'parent_area' => ['nullable', 'string', 'max:255'],
                'device_count' => ['nullable', 'integer'],
                'employee_count' => ['nullable', 'integer'],
                'resigned_count' => ['nullable', 'integer'],
            ])),
            'positions' => Position::create($request->validate([
                'code' => ['required', 'string', 'max:64', 'unique:positions,code'],
                'name' => ['required', 'string', 'max:255'],
                'parent_position' => ['nullable', 'string', 'max:255'],
                'employee_count' => ['nullable', 'integer'],
                'terminated_count' => ['nullable', 'integer'],
            ])),
            'departments' => Department::create($request->validate([
                'code' => ['required', 'string', 'max:64', 'unique:departments,code'],
                'name' => ['required', 'string', 'max:255'],
                'parent_department' => ['nullable', 'string', 'max:255'],
                'employee_count' => ['nullable', 'integer'],
                'resigned_count' => ['nullable', 'integer'],
            ])),
            'break_rules' => BreakRule::create($request->validate([
                'name' => ['required', 'string', 'max:150', 'unique:break_rules,name'],
                'start_time' => ['required', 'string', 'max:32'],
                'end_time' => ['required', 'string', 'max:32'],
                'duration_minutes' => ['required', 'integer'],
                'end_margin' => ['nullable', 'integer'],
                'minimum_duration' => ['nullable', 'integer'],
                'multiple_punch' => ['nullable', 'integer'],
                'calculation_type' => ['nullable', 'string', 'max:100'],
            ])),
            'devices' => Device::create($request->validate([
                'serial_number' => ['required', 'string', 'max:100', 'unique:devices,serial_number'],
                'name' => ['required', 'string', 'max:255'],
                'terminal_name' => ['nullable', 'string', 'max:100'],
                'area' => ['nullable', 'string', 'max:255'],
                'ip_address' => ['nullable', 'string', 'max:64'],
                'real_ip' => ['nullable', 'string', 'max:64'],
                'state' => ['nullable', 'integer'],
                'terminal_tz' => ['nullable', 'integer'],
                'fw_ver' => ['nullable', 'string', 'max:100'],
                'push_ver' => ['nullable', 'string', 'max:100'],
                'transfer_time' => ['nullable', 'string', 'max:64'],
                'transfer_interval' => ['nullable', 'integer'],
                'last_activity' => ['nullable', 'string', 'max:64'],
                'users_count' => ['nullable', 'integer'],
                'fingerprints_count' => ['nullable', 'integer'],
                'faces_count' => ['nullable', 'integer'],
                'palms_count' => ['nullable', 'integer'],
                'events_count' => ['nullable', 'integer'],
                'cmd_count' => ['nullable', 'integer'],
            ])),
            'timetables' => Timetable::create($request->validate([
                'name' => ['required', 'string', 'max:150', 'unique:timetables,name'],
                'type' => ['nullable', 'string', 'max:64'],
                'is_24h' => ['nullable', 'boolean'],
                'check_in' => ['nullable', 'string', 'max:32'],
                'check_out' => ['nullable', 'string', 'max:32'],
                'cross_day' => ['nullable', 'integer', 'min:0', 'max:2'],
                'in_ahead_margin' => ['nullable', 'integer'],
                'in_above_margin' => ['nullable', 'integer'],
                'out_ahead_margin' => ['nullable', 'integer'],
                'out_above_margin' => ['nullable', 'integer'],
                'work_time_minutes' => ['nullable', 'integer'],
                'break_minutes' => ['nullable', 'integer'],
                'break_rule_id' => ['nullable', 'integer', 'exists:break_rules,id'],
                'break_rule_ids' => ['nullable', 'array'],
                'break_rule_ids.*' => ['integer', 'exists:break_rules,id'],
                'break_mode' => ['nullable', 'in:fixed,dynamic,none'],
                'auto_deduct_break' => ['nullable', 'boolean'],
                'work_day' => ['nullable', 'numeric'],
                'work_type' => ['nullable', 'string', 'max:100'],
                'in_required' => ['nullable', 'string', 'max:16'],
                'out_required' => ['nullable', 'string', 'max:16'],
                'allow_late' => ['nullable', 'integer'],
                'allow_leave_early' => ['nullable', 'integer'],
                'early_in_mode' => ['nullable', 'string', 'max:32'],
                'early_in_minutes' => ['nullable', 'integer', 'min:0'],
                'late_out_mode' => ['nullable', 'string', 'max:32'],
                'late_out_minutes' => ['nullable', 'integer', 'min:0'],
                'overtime_level' => ['nullable', 'string', 'max:32'],
                'overtime_level1_hours' => ['nullable', 'numeric', 'min:0'],
                'overtime_level2_hours' => ['nullable', 'numeric', 'min:0'],
                'overtime_level3_hours' => ['nullable', 'numeric', 'min:0'],
            ])),
            'shifts' => Shift::create($request->validate([
                'name' => ['required', 'string', 'max:150', 'unique:shifts,name'],
                'timetable' => ['nullable', 'string'],
                'unit' => ['nullable', 'string', 'max:64'],
                'cycle' => ['nullable', 'integer'],
                'auto_shift' => ['nullable', 'string', 'max:32'],
                'work_weekend' => ['nullable', 'boolean'],
            ])),
            'holidays' => Holiday::create($request->validate([
                'name' => ['required', 'string', 'max:191'],
                'start_date' => ['required', 'date'],
                'duration_days' => ['nullable', 'integer', 'min:1', 'max:31'],
                'department_name' => ['nullable', 'string', 'max:191'],
                'notes' => ['nullable', 'string', 'max:1000'],
            ])),
            default => abort(404, 'Catálogo no válido'),
        };

        $record->updateQuietly(['needs_push' => true]);
        $pushRes = $this->bioTime->pushSingleRecord($type, $record);

        return response()->json([
            'message' => ! empty($pushRes['synced'])
                ? 'Registro creado y sincronizado en directo con BioTime Pro.'
                : 'Registro guardado localmente (pendiente de enviar a BioTime Pro).',
            'synced_to_biotime' => ! empty($pushRes['synced']),
            'record' => $record->fresh(),
        ], 201);
    }

    public function update(Request $request, string $type, int $id): JsonResponse
    {
        if ($type === 'timetables') {
            $this->assertNoOverlappingBreakRules($request->input('break_rule_ids', []));
        }

        $record = match ($type) {
            'areas' => tap(Area::findOrFail($id))->update($request->validate([
                'code' => ['required', 'string', 'max:64', 'unique:areas,code,'.$id],
                'name' => ['required', 'string', 'max:255'],
                'parent_area' => ['nullable', 'string', 'max:255'],
                'device_count' => ['nullable', 'integer'],
                'employee_count' => ['nullable', 'integer'],
                'resigned_count' => ['nullable', 'integer'],
            ])),
            'positions' => tap(Position::findOrFail($id))->update($request->validate([
                'code' => ['required', 'string', 'max:64', 'unique:positions,code,'.$id],
                'name' => ['required', 'string', 'max:255'],
                'parent_position' => ['nullable', 'string', 'max:255'],
                'employee_count' => ['nullable', 'integer'],
                'terminated_count' => ['nullable', 'integer'],
            ])),
            'departments' => tap(Department::findOrFail($id))->update($request->validate([
                'code' => ['required', 'string', 'max:64', 'unique:departments,code,'.$id],
                'name' => ['required', 'string', 'max:255'],
                'parent_department' => ['nullable', 'string', 'max:255'],
                'employee_count' => ['nullable', 'integer'],
                'resigned_count' => ['nullable', 'integer'],
            ])),
            'break_rules' => tap(BreakRule::findOrFail($id))->update($request->validate([
                'name' => ['required', 'string', 'max:150', 'unique:break_rules,name,'.$id],
                'start_time' => ['required', 'string', 'max:32'],
                'end_time' => ['required', 'string', 'max:32'],
                'duration_minutes' => ['required', 'integer'],
                'end_margin' => ['nullable', 'integer'],
                'minimum_duration' => ['nullable', 'integer'],
                'multiple_punch' => ['nullable', 'integer'],
                'calculation_type' => ['nullable', 'string', 'max:100'],
            ])),
            'devices' => tap(Device::findOrFail($id))->update($request->validate([
                'serial_number' => ['required', 'string', 'max:100', 'unique:devices,serial_number,'.$id],
                'name' => ['required', 'string', 'max:255'],
                'terminal_name' => ['nullable', 'string', 'max:100'],
                'area' => ['nullable', 'string', 'max:255'],
                'ip_address' => ['nullable', 'string', 'max:64'],
                'real_ip' => ['nullable', 'string', 'max:64'],
                'state' => ['nullable', 'integer'],
                'terminal_tz' => ['nullable', 'integer'],
                'fw_ver' => ['nullable', 'string', 'max:100'],
                'push_ver' => ['nullable', 'string', 'max:100'],
                'transfer_time' => ['nullable', 'string', 'max:64'],
                'transfer_interval' => ['nullable', 'integer'],
                'last_activity' => ['nullable', 'string', 'max:64'],
                'users_count' => ['nullable', 'integer'],
                'fingerprints_count' => ['nullable', 'integer'],
                'faces_count' => ['nullable', 'integer'],
                'palms_count' => ['nullable', 'integer'],
                'events_count' => ['nullable', 'integer'],
                'cmd_count' => ['nullable', 'integer'],
            ])),
            'timetables' => tap(Timetable::findOrFail($id))->update($request->validate([
                'name' => ['required', 'string', 'max:150', 'unique:timetables,name,'.$id],
                'type' => ['nullable', 'string', 'max:64'],
                'is_24h' => ['nullable', 'boolean'],
                'check_in' => ['nullable', 'string', 'max:32'],
                'check_out' => ['nullable', 'string', 'max:32'],
                'cross_day' => ['nullable', 'integer', 'min:0', 'max:2'],
                'in_ahead_margin' => ['nullable', 'integer'],
                'in_above_margin' => ['nullable', 'integer'],
                'out_ahead_margin' => ['nullable', 'integer'],
                'out_above_margin' => ['nullable', 'integer'],
                'work_time_minutes' => ['nullable', 'integer'],
                'break_minutes' => ['nullable', 'integer'],
                'break_rule_id' => ['nullable', 'integer', 'exists:break_rules,id'],
                'break_rule_ids' => ['nullable', 'array'],
                'break_rule_ids.*' => ['integer', 'exists:break_rules,id'],
                'break_mode' => ['nullable', 'in:fixed,dynamic,none'],
                'auto_deduct_break' => ['nullable', 'boolean'],
                'work_day' => ['nullable', 'numeric'],
                'work_type' => ['nullable', 'string', 'max:100'],
                'in_required' => ['nullable', 'string', 'max:16'],
                'out_required' => ['nullable', 'string', 'max:16'],
                'allow_late' => ['nullable', 'integer'],
                'allow_leave_early' => ['nullable', 'integer'],
                'early_in_mode' => ['nullable', 'string', 'max:32'],
                'early_in_minutes' => ['nullable', 'integer', 'min:0'],
                'late_out_mode' => ['nullable', 'string', 'max:32'],
                'late_out_minutes' => ['nullable', 'integer', 'min:0'],
                'overtime_level' => ['nullable', 'string', 'max:32'],
                'overtime_level1_hours' => ['nullable', 'numeric', 'min:0'],
                'overtime_level2_hours' => ['nullable', 'numeric', 'min:0'],
                'overtime_level3_hours' => ['nullable', 'numeric', 'min:0'],
            ])),
            'shifts' => tap(Shift::findOrFail($id))->update($request->validate([
                'name' => ['required', 'string', 'max:150', 'unique:shifts,name,'.$id],
                'timetable' => ['nullable', 'string'],
                'unit' => ['nullable', 'string', 'max:64'],
                'cycle' => ['nullable', 'integer'],
                'auto_shift' => ['nullable', 'string', 'max:32'],
                'work_weekend' => ['nullable', 'boolean'],
            ])),
            'holidays' => tap(Holiday::findOrFail($id))->update($request->validate([
                'name' => ['required', 'string', 'max:191'],
                'start_date' => ['required', 'date'],
                'duration_days' => ['nullable', 'integer', 'min:1', 'max:31'],
                'department_name' => ['nullable', 'string', 'max:191'],
                'notes' => ['nullable', 'string', 'max:1000'],
            ])),
            default => abort(404, 'Catálogo no válido'),
        };

        $record->updateQuietly(['needs_push' => true]);
        $pushRes = $this->bioTime->pushSingleRecord($type, $record);

        return response()->json([
            'message' => ! empty($pushRes['synced'])
                ? 'Registro actualizado y sincronizado en directo con BioTime Pro.'
                : 'Registro actualizado localmente (se enviará a BioTime Pro en la próxima sincronización).',
            'synced_to_biotime' => ! empty($pushRes['synced']),
            'record' => $record->fresh(),
        ]);
    }

    public function destroy(string $type, int $id): JsonResponse
    {
        $record = match ($type) {
            'areas' => Area::findOrFail($id),
            'positions' => Position::findOrFail($id),
            'departments' => Department::findOrFail($id),
            'break_rules' => BreakRule::findOrFail($id),
            'devices' => Device::findOrFail($id),
            'timetables' => Timetable::findOrFail($id),
            'shifts' => Shift::findOrFail($id),
            'holidays' => Holiday::findOrFail($id),
            default => abort(404, 'Catálogo no válido'),
        };

        $uniqueKey = (string) ($record->code ?? $record->serial_number ?? $record->name ?? $record->id);
        $this->bioTime->deleteRemoteRecord($type, $record->biotime_pk, $uniqueKey);
        $record->delete();

        return response()->json([
            'message' => 'Registro eliminado en Control Biotime-Xis y en BioTime Pro.',
        ]);
    }

    public function seedLftHolidays(Request $request): JsonResponse
    {
        $year = (int) $request->input('year', date('Y'));
        if ($year < 2020 || $year > 2100) {
            $year = (int) date('Y');
        }

        $nthMonday = static function (int $y, int $month, int $nth): string {
            $dt = new \DateTimeImmutable(sprintf('%04d-%02d-01', $y, $month));
            $firstMonday = ((int) $dt->format('N') === 1) ? $dt : $dt->modify('next monday');
            return $firstMonday->modify('+'.($nth - 1).' weeks')->format('Y-m-d');
        };

        $lftHolidays = [
            ['name' => 'Año Nuevo (Art. 74 I LFT)', 'start_date' => "{$year}-01-01"],
            ['name' => 'Día de la Constitución (Art. 74 II LFT)', 'start_date' => $nthMonday($year, 2, 1)],
            ['name' => 'Natalicio de Benito Juárez (Art. 74 III LFT)', 'start_date' => $nthMonday($year, 3, 3)],
            ['name' => 'Día del Trabajo (Art. 74 IV LFT)', 'start_date' => "{$year}-05-01"],
            ['name' => 'Día de la Independencia (Art. 74 V LFT)', 'start_date' => "{$year}-09-16"],
            ['name' => 'Revolución Mexicana (Art. 74 VI LFT)', 'start_date' => $nthMonday($year, 11, 3)],
            ['name' => 'Navidad (Art. 74 VIII LFT)', 'start_date' => "{$year}-12-25"],
        ];

        $created = 0;
        foreach ($lftHolidays as $item) {
            $holiday = Holiday::firstOrCreate(
                ['start_date' => $item['start_date'], 'name' => $item['name']],
                ['duration_days' => 1, 'needs_push' => true]
            );
            if ($holiday->wasRecentlyCreated) {
                $created++;
            }
        }

        return response()->json([
            'message' => "Se precargaron {$created} día(s) festivo(s) oficiales LFT {$year}.",
            'created' => $created,
            'year' => $year,
        ]);
    }

    private function assertNoOverlappingBreakRules(mixed $breakRuleIds): void
    {
        if (! is_array($breakRuleIds) || count($breakRuleIds) < 2) {
            return;
        }

        $rules = BreakRule::whereIn('id', array_map('intval', $breakRuleIds))->get()->values();
        $toMins = static function (?string $t): ?int {
            $parts = explode(':', (string) $t);
            if (count($parts) < 2) {
                return null;
            }
            return ((int) $parts[0]) * 60 + ((int) $parts[1]);
        };

        for ($i = 0; $i < $rules->count(); $i++) {
            for ($j = $i + 1; $j < $rules->count(); $j++) {
                $a = $rules[$i];
                $b = $rules[$j];
                $s1 = $toMins($a->start_time);
                $e1 = $toMins($a->end_time);
                $s2 = $toMins($b->start_time);
                $e2 = $toMins($b->end_time);
                if ($s1 === null || $e1 === null || $s2 === null || $e2 === null) {
                    continue;
                }
                if ($e1 <= $s1) {
                    $e1 += 1440;
                }
                if ($e2 <= $s2) {
                    $e2 += 1440;
                }
                if ($s1 < $e2 && $s2 < $e1) {
                    throw ValidationException::withMessages([
                        'break_rule_ids' => [
                            "Las reglas de descanso \"{$a->name}\" ({$a->start_time}-{$a->end_time}) y \"{$b->name}\" ({$b->start_time}-{$b->end_time}) se traslapan.",
                        ],
                    ]);
                }
            }
        }
    }

    private function normalizeHeader(string $header): string
    {
        $s = trim($header);
        $trans = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
        ];
        $s = strtr($s, $trans);
        $s = strtolower($s);

        return preg_replace('/[^a-z0-9]/', '', $s) ?? '';
    }

    private function detectCatalogType(array $normHeaders, ?string $hint = null): ?string
    {
        $has = fn (string ...$names) => count(array_intersect($normHeaders, $names)) > 0;

        if ($has('codigodearea') && $has('nombredearea')) {
            return 'areas';
        }
        if ($has('codigodecargo') && $has('nombredelcargo')) {
            return 'positions';
        }
        if ($has('codigodepartamento') && $has('nombredepartamento')) {
            return 'departments';
        }
        if ($has('duraciondescanso') && $has('tipodecalculo')) {
            return 'break_rules';
        }
        if ($has('numerodeserie') && $has('ipdedispositivo')) {
            return 'devices';
        }
        if ($has('iddelempleado') && ($has('privilegiodedispositivo') || $has('estatusdeapp'))) {
            return 'employees';
        }
        if ($has('tiempodetrabajo') && $has('jornadadetrabajo')) {
            return 'timetables';
        }
        if ($has('nombredelturno') && $has('turnoautomatico')) {
            return 'shifts';
        }

        $validTypes = ['areas', 'positions', 'departments', 'break_rules', 'devices', 'employees', 'timetables', 'shifts'];
        if ($hint && in_array($hint, $validTypes, true)) {
            return $hint;
        }

        return null;
    }

    private function catalogLabel(string $type): string
    {
        return match ($type) {
            'employees' => 'Empleados',
            'areas' => 'Áreas',
            'positions' => 'Cargos',
            'departments' => 'Departamentos',
            'break_rules' => 'Descansos',
            'devices' => 'Dispositivos',
            'timetables' => 'Horarios',
            'shifts' => 'Turnos',
            default => $type,
        };
    }

    private function colIndex(array $normHeaders, string ...$candidates): int
    {
        foreach ($candidates as $c) {
            $idx = array_search($this->normalizeHeader($c), $normHeaders, true);
            if ($idx !== false) {
                return (int) $idx;
            }
        }

        return -1;
    }

    private function val(array $row, int $index, string $default = ''): string
    {
        if ($index < 0 || ! isset($row[$index])) {
            return $default;
        }

        return trim((string) $row[$index]);
    }

    private function importRowsForType(string $type, array $h, array $rows, bool $updateExisting): array
    {
        $inserted = 0;
        $updated = 0;
        $duplicates = 0;
        $total = 0;
        $seenKeys = [];

        foreach ($rows as $r) {
            if (empty(array_filter($r, fn ($cell) => trim((string) $cell) !== ''))) {
                continue;
            }

            if ($type === 'areas') {
                $code = $this->val($r, $this->colIndex($h, 'Código de área', 'codigoarea', 'code'));
                $name = $this->val($r, $this->colIndex($h, 'Nombre de área', 'nombrearea', 'name'));
                if ($code === '' || $name === '') {
                    continue;
                }
                $total++;
                if (isset($seenKeys[$code])) {
                    $duplicates++;
                    continue;
                }
                $seenKeys[$code] = true;

                $payload = [
                    'name' => $name,
                    'parent_area' => $this->val($r, $this->colIndex($h, 'Área superior', 'areasuperior')) ?: null,
                    'device_count' => (int) $this->val($r, $this->colIndex($h, 'Conteo de dispositivos'), '0'),
                    'employee_count' => (int) $this->val($r, $this->colIndex($h, 'Conteo de empleados'), '0'),
                    'resigned_count' => (int) $this->val($r, $this->colIndex($h, 'Cantidad en Renuncia'), '0'),
                ];
                $this->upsertCatalogRecord(Area::class, ['code' => $code], $payload, $updateExisting, $inserted, $updated, $duplicates);
            } elseif ($type === 'positions') {
                $code = $this->val($r, $this->colIndex($h, 'Código de cargo', 'codigocargo', 'code'));
                $name = $this->val($r, $this->colIndex($h, 'Nombre del cargo', 'nombrecargo', 'name'));
                if ($code === '' || $name === '') {
                    continue;
                }
                $total++;
                if (isset($seenKeys[$code])) {
                    $duplicates++;
                    continue;
                }
                $seenKeys[$code] = true;

                $payload = [
                    'name' => $name,
                    'parent_position' => $this->val($r, $this->colIndex($h, 'Cargo superior')) ?: null,
                    'employee_count' => (int) $this->val($r, $this->colIndex($h, 'Conteo de empleados'), '0'),
                    'terminated_count' => (int) $this->val($r, $this->colIndex($h, 'Conteo de Bajas'), '0'),
                ];
                $this->upsertCatalogRecord(Position::class, ['code' => $code], $payload, $updateExisting, $inserted, $updated, $duplicates);
            } elseif ($type === 'departments') {
                $code = $this->val($r, $this->colIndex($h, 'Código departamento', 'codigodedepartamento', 'code'));
                $name = $this->val($r, $this->colIndex($h, 'Nombre departamento', 'nombredepto', 'name'));
                if ($code === '' || $name === '') {
                    continue;
                }
                $total++;
                if (isset($seenKeys[$code])) {
                    $duplicates++;
                    continue;
                }
                $seenKeys[$code] = true;

                $payload = [
                    'name' => $name,
                    'parent_department' => $this->val($r, $this->colIndex($h, 'Departamento superior')) ?: null,
                    'employee_count' => (int) $this->val($r, $this->colIndex($h, 'Conteo de empleados'), '0'),
                    'resigned_count' => (int) $this->val($r, $this->colIndex($h, 'Conteo de Resignados'), '0'),
                ];
                $this->upsertCatalogRecord(Department::class, ['code' => $code], $payload, $updateExisting, $inserted, $updated, $duplicates);
            } elseif ($type === 'break_rules') {
                $name = $this->val($r, $this->colIndex($h, 'Nombre', 'name'));
                if ($name === '') {
                    continue;
                }
                $total++;
                if (isset($seenKeys[$name])) {
                    $duplicates++;
                    continue;
                }
                $seenKeys[$name] = true;

                $payload = [
                    'start_time' => $this->val($r, $this->colIndex($h, 'Hora de Inicio'), '00:00:00'),
                    'end_time' => $this->val($r, $this->colIndex($h, 'Hora Final'), '00:00:00'),
                    'duration_minutes' => (int) $this->val($r, $this->colIndex($h, 'Duración Descanso'), '0'),
                    'calculation_type' => $this->val($r, $this->colIndex($h, 'Tipo de Cálculo')) ?: null,
                ];
                $this->upsertCatalogRecord(BreakRule::class, ['name' => $name], $payload, $updateExisting, $inserted, $updated, $duplicates);
            } elseif ($type === 'devices') {
                $sn = $this->val($r, $this->colIndex($h, 'Número de serie', 'numeroserie', 'serialnumber'));
                $name = $this->val($r, $this->colIndex($h, 'Nombre del dispositivo', 'nombredispositivo', 'name'));
                if ($sn === '' || $name === '') {
                    continue;
                }
                $total++;
                if (isset($seenKeys[$sn])) {
                    $duplicates++;
                    continue;
                }
                $seenKeys[$sn] = true;

                $payload = [
                    'name' => $name,
                    'area' => $this->val($r, $this->colIndex($h, 'Área', 'area')) ?: null,
                    'ip_address' => $this->val($r, $this->colIndex($h, 'IP de Dispositivo', 'ip')) ?: null,
                    'last_activity' => $this->val($r, $this->colIndex($h, 'Última actividad')) ?: null,
                    'users_count' => (int) $this->val($r, $this->colIndex($h, 'Usuarios'), '0'),
                    'fingerprints_count' => (int) $this->val($r, $this->colIndex($h, 'Huellas'), '0'),
                    'faces_count' => (int) $this->val($r, $this->colIndex($h, 'Rostros'), '0'),
                    'palms_count' => (int) $this->val($r, $this->colIndex($h, 'Palmas'), '0'),
                    'events_count' => (int) $this->val($r, $this->colIndex($h, 'Eventos'), '0'),
                    'cmd_count' => (int) $this->val($r, $this->colIndex($h, 'Cmd'), '0'),
                ];
                $this->upsertCatalogRecord(Device::class, ['serial_number' => $sn], $payload, $updateExisting, $inserted, $updated, $duplicates);
            } elseif ($type === 'employees') {
                $biotimeId = $this->val($r, $this->colIndex($h, 'ID del Empleado', 'ID de Empleado', 'idempleado', 'id'));
                $name = $this->val($r, $this->colIndex($h, 'Nombre', 'name'));
                if ($biotimeId === '') {
                    continue;
                }
                $total++;
                if (isset($seenKeys[$biotimeId])) {
                    $duplicates++;
                    continue;
                }
                $seenKeys[$biotimeId] = true;

                $payload = [
                    'name' => $name ?: $biotimeId,
                    'department' => $this->val($r, $this->colIndex($h, 'Departamento')) ?: null,
                    'device_privilege' => $this->val($r, $this->colIndex($h, 'Privilegio de dispositivo')) ?: null,
                    'area' => $this->val($r, $this->colIndex($h, 'Área', 'area')) ?: null,
                    'app_status' => $this->val($r, $this->colIndex($h, 'Estatus de APP')) ?: null,
                    'fingerprint' => $this->val($r, $this->colIndex($h, 'Huella')) ?: null,
                    'face' => $this->val($r, $this->colIndex($h, 'Rostro')) ?: null,
                    'palm' => $this->val($r, $this->colIndex($h, 'Palma')) ?: null,
                    'vl_face' => $this->val($r, $this->colIndex($h, 'Rostro VL')) ?: null,
                ];
                $this->upsertCatalogRecord(Employee::class, ['biotime_id' => $biotimeId], $payload, $updateExisting, $inserted, $updated, $duplicates);
            } elseif ($type === 'timetables') {
                $name = $this->val($r, $this->colIndex($h, 'Nombre', 'name'));
                if ($name === '') {
                    continue;
                }
                $total++;
                if (isset($seenKeys[$name])) {
                    $duplicates++;
                    continue;
                }
                $seenKeys[$name] = true;

                $payload = [
                    'type' => $this->val($r, $this->colIndex($h, 'Tipo')) ?: null,
                    'check_in' => $this->val($r, $this->colIndex($h, 'Entrada')) ?: null,
                    'check_out' => $this->val($r, $this->colIndex($h, 'Salida')) ?: null,
                    'work_time_minutes' => (int) $this->val($r, $this->colIndex($h, 'Tiempo de Trabajo'), '0'),
                    'break_minutes' => (int) $this->val($r, $this->colIndex($h, 'Descanso'), '0'),
                    'work_day' => (float) $this->val($r, $this->colIndex($h, 'Jornada de Trabajo'), '1.0'),
                    'work_type' => $this->val($r, $this->colIndex($h, 'Tipo de Trabajo')) ?: null,
                ];
                $this->upsertCatalogRecord(Timetable::class, ['name' => $name], $payload, $updateExisting, $inserted, $updated, $duplicates);
            } elseif ($type === 'shifts') {
                $name = $this->val($r, $this->colIndex($h, 'Nombre del Turno', 'nombreturno', 'name'));
                if ($name === '') {
                    continue;
                }
                $total++;
                if (isset($seenKeys[$name])) {
                    $duplicates++;
                    continue;
                }
                $seenKeys[$name] = true;

                $payload = [
                    'timetable' => $this->val($r, $this->colIndex($h, 'Horario')) ?: null,
                    'unit' => $this->val($r, $this->colIndex($h, 'Unidad')) ?: null,
                    'cycle' => (int) $this->val($r, $this->colIndex($h, 'Ciclo'), '1'),
                    'auto_shift' => $this->val($r, $this->colIndex($h, 'Turno Automático'), 'False'),
                ];
                $this->upsertCatalogRecord(Shift::class, ['name' => $name], $payload, $updateExisting, $inserted, $updated, $duplicates);
            }
        }

        return [
            'total' => $total,
            'inserted' => $inserted,
            'updated' => $updated,
            'duplicates' => $duplicates,
        ];
    }

    private function upsertCatalogRecord(
        string $modelClass,
        array $uniqueCriteria,
        array $payload,
        bool $updateExisting,
        int &$inserted,
        int &$updated,
        int &$duplicates
    ): void {
        $existing = $modelClass::where($uniqueCriteria)->first();
        if (! $existing) {
            $modelClass::create(array_merge($uniqueCriteria, $payload));
            $inserted++;

            return;
        }

        if (! $updateExisting) {
            $duplicates++;

            return;
        }

        $existing->fill($payload);
        if ($existing->isDirty()) {
            $existing->save();
            $updated++;
        } else {
            $duplicates++;
        }
    }

    private function parseCsvString(string $s): array
    {
        $s = preg_replace('/^\xEF\xBB\xBF/', '', $s) ?? $s;
        $lines = preg_split('/\r\n|\r|\n/', $s);
        $nonEmptyLines = array_values(array_filter($lines, fn ($l) => trim($l) !== ''));
        if (empty($nonEmptyLines)) {
            return [];
        }

        $firstLine = $nonEmptyLines[0];
        $delim = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $out = [];
        $field = '';
        $row = [];
        $quoted = false;
        $len = strlen($s);

        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '"') {
                if ($quoted && ($i + 1 < $len) && $s[$i + 1] === '"') {
                    $field .= '"';
                    $i++;
                } else {
                    $quoted = ! $quoted;
                }
            } elseif ($c === $delim && ! $quoted) {
                $row[] = $field;
                $field = '';
            } elseif (($c === "\r" || $c === "\n") && ! $quoted) {
                if ($c === "\r" && ($i + 1 < $len) && $s[$i + 1] === "\n") {
                    $i++;
                }
                $row[] = $field;
                if (count(array_filter($row, fn ($x) => trim($x) !== '')) > 0) {
                    $out[] = $row;
                }
                $row = [];
                $field = '';
            } else {
                $field .= $c;
            }
        }
        $row[] = $field;
        if (count(array_filter($row, fn ($x) => trim($x) !== '')) > 0) {
            $out[] = $row;
        }

        return $out;
    }
}
