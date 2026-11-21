<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    private const SUBJECT_LABELS = [
        'App\\Models\\User' => 'Usuario',
        'App\\Models\\Employee' => 'Empleado',
        'App\\Models\\Company' => 'Empresa / Razón Social',
        'App\\Models\\AttendanceCorrection' => 'Corrección Asistencia',
        'App\\Models\\AttendanceCorrectionLog' => 'Bitácora de Corrección',
        'App\\Models\\EmployeeLeave' => 'Incidencia / Permiso',
        'App\\Models\\EmployeeSchedule' => 'Horario Empleado',
        'App\\Models\\DepartmentSchedule' => 'Horario Depto.',
        'App\\Models\\Shift' => 'Turno',
        'App\\Models\\Timetable' => 'Horario',
        'App\\Models\\BreakRule' => 'Regla Descanso',
        'App\\Models\\Department' => 'Departamento',
        'App\\Models\\Area' => 'Área',
        'App\\Models\\Position' => 'Cargo / Puesto',
        'App\\Models\\Device' => 'Dispositivo',
        'App\\Models\\Holiday' => 'Día Festivo',
        'App\\Models\\ImportBatch' => 'Lote Importación',
        'App\\Models\\WeekLock' => 'Cierre de Semana',
    ];

    private function buildFilteredQuery(Request $request)
    {
        $query = Activity::with('causer:id,name,email,role')
            ->orderByDesc('id');

        if ($search = trim((string) $request->input('search', ''))) {
            $like = "%{$search}%";
            $query->where(function ($q) use ($like) {
                $q->where('description', 'like', $like)
                    ->orWhere('log_name', 'like', $like)
                    ->orWhere('event', 'like', $like)
                    ->orWhere('subject_type', 'like', $like)
                    ->orWhere('properties', 'like', $like)
                    ->orWhereHasMorph('causer', [User::class], function ($cq) use ($like) {
                        $cq->where('name', 'like', $like)
                            ->orWhere('email', 'like', $like);
                    });
            });
        }

        if ($logName = trim((string) $request->input('log_name', ''))) {
            $query->where('log_name', $logName);
        }

        if ($event = trim((string) $request->input('event', ''))) {
            $query->where('event', $event);
        }

        if ($causerId = $request->input('causer_id')) {
            $query->where('causer_type', User::class)->where('causer_id', (int) $causerId);
        }

        if ($subjectType = trim((string) $request->input('subject_type', ''))) {
            $query->where('subject_type', $subjectType);
        }

        if ($dateFrom = trim((string) $request->input('date_from', ''))) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo = trim((string) $request->input('date_to', ''))) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        return $query;
    }

    private function formatActivity(Activity $a): array
    {
        $props = $a->properties ? $a->properties->toArray() : [];
        $subjectType = (string) ($a->subject_type ?? '');

        return [
            'id' => $a->id,
            'log_name' => $a->log_name ?: 'default',
            'event' => $a->event ?: 'custom',
            'description' => $a->description,
            'subject_type' => $subjectType,
            'subject_label' => self::SUBJECT_LABELS[$subjectType] ?? ($subjectType ? class_basename($subjectType) : 'Sistema'),
            'subject_id' => $a->subject_id,
            'causer' => $a->causer ? [
                'id' => $a->causer->id,
                'name' => $a->causer->name,
                'email' => $a->causer->email,
                'role' => $a->causer->role ?? '',
            ] : null,
            'properties' => $props,
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->buildFilteredQuery($request);

        $perPage = min(100, max(10, (int) $request->input('per_page', 25)));
        $paginator = $query->paginate($perPage);

        $logs = collect($paginator->items())->map(fn (Activity $a) => $this->formatActivity($a));

        $stats = [
            'total' => Activity::count(),
            'today' => Activity::whereDate('created_at', now()->toDateString())->count(),
            'auth_failed' => Activity::where('log_name', 'auth')->where('event', 'login_failed')->count(),
            'auth_success' => Activity::where('log_name', 'auth')->where('event', 'login')->count(),
            'model_changes' => Activity::whereIn('event', ['created', 'updated', 'deleted'])->count(),
        ];

        $logNames = Activity::query()->whereNotNull('log_name')->distinct()->orderBy('log_name')->pluck('log_name');
        $events = Activity::query()->whereNotNull('event')->distinct()->orderBy('event')->pluck('event');
        $subjectTypes = Activity::query()
            ->whereNotNull('subject_type')
            ->distinct()
            ->orderBy('subject_type')
            ->pluck('subject_type')
            ->map(fn ($st) => [
                'value' => $st,
                'label' => self::SUBJECT_LABELS[$st] ?? class_basename($st),
            ]);
        $causers = User::orderBy('name')->get(['id', 'name', 'email']);

        return response()->json([
            'logs' => $logs,
            'meta' => [
                'total' => $paginator->total(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
            ],
            'stats' => $stats,
            'filter_options' => [
                'log_names' => $logNames,
                'events' => $events,
                'subject_types' => $subjectTypes,
                'causers' => $causers,
            ],
        ]);
    }

    public function export(Request $request): JsonResponse
    {
        $logs = $this->buildFilteredQuery($request)
            ->limit(10000)
            ->get()
            ->map(fn (Activity $a) => $this->formatActivity($a));

        return response()->json([
            'logs' => $logs,
            'total_exported' => $logs->count(),
        ]);
    }

    public function purge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'log_name' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', 'string', 'max:100'],
            'subject_type' => ['nullable', 'string', 'max:191'],
            'causer_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:191'],
            'export_first' => ['nullable', 'boolean'],
        ]);

        if (empty($validated['date_from']) && empty($validated['date_to'])) {
            return response()->json([
                'message' => 'Debes indicar al menos una fecha inicial o final para depurar registros del log.',
            ], 422);
        }

        if (! empty($validated['date_from']) && ! empty($validated['date_to']) && $validated['date_from'] > $validated['date_to']) {
            return response()->json([
                'message' => 'La fecha inicial no puede ser mayor que la fecha final.',
            ], 422);
        }

        $query = $this->buildFilteredQuery($request);
        $exportFirst = (bool) ($validated['export_first'] ?? false);
        $exportedLogs = [];

        if ($exportFirst) {
            $exportedLogs = (clone $query)
                ->limit(10000)
                ->get()
                ->map(fn (Activity $a) => $this->formatActivity($a))
                ->values()
                ->all();
        }

        $deletedCount = (clone $query)->delete();

        activity('system')
            ->causedBy($request->user())
            ->event('purged')
            ->withProperties([
                'deleted_count' => $deletedCount,
                'exported_before_delete' => $exportFirst,
                'date_from' => $validated['date_from'] ?? null,
                'date_to' => $validated['date_to'] ?? null,
                'log_name' => $validated['log_name'] ?? null,
                'event' => $validated['event'] ?? null,
                'ip' => $request->ip(),
            ])
            ->log("Se depuraron {$deletedCount} registro(s) de bitácora");

        return response()->json([
            'message' => "Se eliminaron {$deletedCount} registro(s) antiguos de la bitácora.",
            'deleted_count' => $deletedCount,
            'exported_logs' => $exportedLogs,
        ]);
    }
}
