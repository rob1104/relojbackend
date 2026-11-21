<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WeekLock;
use App\Support\ActiveCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WeekLockController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'week_start' => ['required', 'date'],
            'week_end' => ['required', 'date', 'after_or_equal:week_start'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'notes' => ['nullable', 'string', 'max:191'],
        ]);

        $activeCompanyId = ActiveCompany::resolveId($request);
        $companyId = $activeCompanyId ?? ($validated['company_id'] ?? null);

        $existing = WeekLock::where('week_start', $validated['week_start'])
            ->where('week_end', $validated['week_end'])
            ->when(
                $companyId !== null,
                fn ($q) => $q->where('company_id', $companyId),
                fn ($q) => $q->whereNull('company_id')
            )
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Esta semana ya se encuentra cerrada para nómina.',
                'week_lock' => $this->formatLock($existing->load(['company:id,business_name', 'locker:id,name'])),
            ]);
        }

        $lock = WeekLock::create([
            'company_id' => $companyId,
            'week_start' => $validated['week_start'],
            'week_end' => $validated['week_end'],
            'locked_by' => $request->user()?->id,
            'locked_at' => now(),
            'notes' => $validated['notes'] ?? null,
        ]);

        $lock->load(['company:id,business_name', 'locker:id,name']);

        return response()->json([
            'message' => 'Semana cerrada para nómina. Las correcciones quedan bloqueadas.',
            'week_lock' => $this->formatLock($lock),
        ], 201);
    }

    public function destroy(Request $request, WeekLock $weekLock): JsonResponse
    {
        if ($request->user()?->role !== 'Administrador') {
            return response()->json([
                'message' => 'Solo el Administrador puede reabrir una semana cerrada para nómina.',
            ], 403);
        }

        $activeCompanyId = ActiveCompany::resolveId($request);
        if ($activeCompanyId !== null && $weekLock->company_id !== null && (int) $weekLock->company_id !== $activeCompanyId) {
            return response()->json([
                'message' => 'El cierre pertenece a otra razón social.',
            ], 403);
        }

        $id = $weekLock->id;
        $weekLock->delete();

        return response()->json([
            'message' => 'Semana reabierta correctamente. Ya se pueden realizar ajustes.',
            'deleted_id' => $id,
        ]);
    }

    private function formatLock(WeekLock $l): array
    {
        return [
            'id' => $l->id,
            'company_id' => $l->company_id,
            'company_name' => $l->company?->business_name ?? 'Todas las razones sociales',
            'week_start' => $l->week_start?->format('Y-m-d'),
            'week_end' => $l->week_end?->format('Y-m-d'),
            'locked_by' => $l->locked_by,
            'locked_by_name' => $l->locker?->name ?? 'Sistema',
            'locked_at' => $l->locked_at?->toIso8601String(),
            'notes' => $l->notes ?? '',
        ];
    }
}
