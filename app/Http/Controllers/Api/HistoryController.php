<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrectionLog;
use App\Models\AttendanceEvent;
use App\Models\ImportBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HistoryController extends Controller
{
    public function imports(): JsonResponse
    {
        $batches = ImportBatch::with('user:id,name,email')
            ->withCount('events')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ImportBatch $b) => [
                'id' => $b->id,
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
                'user_name' => $b->user?->name ?? 'Sistema',
                'created_at' => $b->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'batches' => $batches,
        ]);
    }

    public function restoreImportBatch(ImportBatch $importBatch): JsonResponse
    {
        $restoredByBatch = AttendanceEvent::where('import_batch_id', $importBatch->id)
            ->update(['is_visible' => true]);

        $restoredByRange = 0;
        if ($importBatch->date_from && $importBatch->date_to) {
            $restoredByRange = AttendanceEvent::whereBetween('event_date', [
                $importBatch->date_from->format('Y-m-d'),
                $importBatch->date_to->format('Y-m-d'),
            ])->update(['is_visible' => true]);
        }

        $totalVisible = AttendanceEvent::where('is_visible', true)->count();

        return response()->json([
            'message' => "Lote #{$importBatch->id} restaurado en pantalla con todas sus correcciones guardadas ({$totalVisible} marcas activas).",
            'restored_count' => max($restoredByBatch, $restoredByRange),
            'date_from' => $importBatch->date_from?->format('Y-m-d'),
            'date_to' => $importBatch->date_to?->format('Y-m-d'),
        ]);
    }

    public function deleteImportBatch(ImportBatch $importBatch): JsonResponse
    {
        $eventsCount = $importBatch->events()->count();
        $importBatch->delete();

        return response()->json([
            'message' => "Lote #{$importBatch->id} eliminado junto con {$eventsCount} eventos asociados.",
        ]);
    }

    public function correctionLogs(Request $request): JsonResponse
    {
        $query = AttendanceCorrectionLog::with([
            'employee:id,biotime_id,name,department',
            'user:id,name,email',
        ])->orderByDesc('created_at');

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
