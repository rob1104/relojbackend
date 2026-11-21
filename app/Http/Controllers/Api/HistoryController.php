<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrectionLog;
use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Models\ImportBatch;
use App\Support\ActiveCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HistoryController extends Controller
{
    public function imports(Request $request): JsonResponse
    {
        $activeCompanyId = ActiveCompany::resolveId($request);
        $user = $request->user();
        $isAdmin = $user?->role === 'Administrador';

        $batches = ImportBatch::with(['user:id,name,email', 'company:id,business_name'])
            ->when(!$isAdmin, fn ($q) => $q->where('user_id', $user?->id))
            ->when($activeCompanyId !== null, fn ($q) => $q->where('company_id', $activeCompanyId))
            ->withCount('events')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ImportBatch $b) => [
                'id' => $b->id,
                'user_id' => $b->user_id,
                'filenames' => $b->filenames ?? [],
                'source_type' => $b->source_type,
                'total_rows' => $b->total_rows,
                'inserted_events' => $b->inserted_events,
                'duplicate_events' => $b->duplicate_events,
                'active_events_count' => $b->events_count,
                'employees_touched' => $b->employees_touched,
                'date_from' => $b->date_from?->format('Y-m-d'),
                'date_to' => $b->date_to?->format('Y-m-d'),
                'notes' => $b->notes,
                'company_id' => $b->company_id,
                'company_name' => $b->company?->business_name ?? 'General',
                'user_name' => $b->user?->name ?? 'Sistema',
                'created_at' => $b->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'batches' => $batches,
        ]);
    }

    public function restoreImportBatch(Request $request, ImportBatch $importBatch): JsonResponse
    {
        $user = $request->user();
        if ((int) $importBatch->user_id !== (int) $user?->id) {
            return response()->json([
                'message' => 'No tienes permiso para cargar o manipular lotes creados por otros usuarios.',
            ], 403);
        }

        $importBatch->update(['is_visible' => true]);

        $restoredByBatch = AttendanceEvent::where('import_batch_id', $importBatch->id)
            ->update(['is_visible' => true]);

        $restoredByRange = 0;
        if ($importBatch->date_from && $importBatch->date_to) {
            $rangeQuery = AttendanceEvent::whereBetween('event_date', [
                $importBatch->date_from->format('Y-m-d'),
                $importBatch->date_to->format('Y-m-d'),
            ]);
            if ($importBatch->company_id) {
                $scopedIds = Employee::where('company_id', $importBatch->company_id)->pluck('biotime_id')->all();
                $rangeQuery->whereIn('biotime_id', $scopedIds);
            }
            $restoredByRange = $rangeQuery->update(['is_visible' => true]);
        }

        $totalVisible = AttendanceEvent::where('is_visible', true)->count();

        return response()->json([
            'message' => "Lote #{$importBatch->id} restaurado en pantalla con todas sus correcciones guardadas ({$totalVisible} marcas activas).",
            'restored_count' => max($restoredByBatch, $restoredByRange),
            'date_from' => $importBatch->date_from?->format('Y-m-d'),
            'date_to' => $importBatch->date_to?->format('Y-m-d'),
        ]);
    }

    public function deleteImportBatch(Request $request, ImportBatch $importBatch): JsonResponse
    {
        if ($request->user()?->role !== 'Administrador') {
            return response()->json([
                'message' => 'Solo el Administrador tiene permiso para deshacer lotes de sincronización.',
            ], 403);
        }

        $eventsCount = $importBatch->events()->count();
        $importBatch->delete();

        return response()->json([
            'message' => "Lote #{$importBatch->id} eliminado junto con {$eventsCount} eventos asociados.",
        ]);
    }

    public function correctionLogs(Request $request): JsonResponse
    {
        $activeCompanyId = ActiveCompany::resolveId($request);
        $query = AttendanceCorrectionLog::with([
            'employee:id,biotime_id,name,department,company_id',
            'user:id,name,email',
        ])->orderByDesc('created_at');

        if ($activeCompanyId !== null) {
            $scopedBiotimeIds = Employee::where('company_id', $activeCompanyId)->pluck('biotime_id')->all();
            $query->whereIn('biotime_id', $scopedBiotimeIds);
        }

        if ($request->filled('biotime_id')) {
            $query->where('biotime_id', $request->string('biotime_id'));
        }

        if ($request->filled('action')) {
            $query->where('action', $request->string('action'));
        }

        if ($request->filled('from')) {
            $query->whereDate('work_date', '>=', $request->string('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('work_date', '<=', $request->string('to'));
        }

        $logs = $query->limit(500)->get()->map(fn (AttendanceCorrectionLog $log) => [
            'id' => $log->id,
            'biotime_id' => $log->biotime_id,
            'employee_name' => $log->employee?->name ?? $log->biotime_id,
            'department' => $log->employee?->department ?? '',
            'work_date' => $log->work_date instanceof \DateTimeInterface
                ? $log->work_date->format('Y-m-d')
                : substr((string) $log->work_date, 0, 10),
            'action' => $log->action,
            'previous_slots' => $log->previous_slots ?? [],
            'new_slots' => $log->new_slots ?? [],
            'original_events' => $log->original_events ?? [],
            'reason' => $log->reason ?? '',
            'user_name' => $log->user?->name ?? 'Sistema',
            'created_at' => $log->created_at?->toIso8601String(),
        ]);

        return response()->json([
            'logs' => $logs,
        ]);
    }
}
