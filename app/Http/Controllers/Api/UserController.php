<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::with('company:id,business_name,rfc,week_start_day')
            ->withCount(['correctionLogs', 'importBatches'])
            ->orderByDesc('active')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => $this->formatUser($u));

        return response()->json([
            'users' => $users,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:64', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6', 'max:120'],
            'role' => ['required', 'string', 'max:64'],
            'position' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:120'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'phone' => ['nullable', 'string', 'max:64'],
            'active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = User::create([
            'name' => trim($validated['name']),
            'username' => isset($validated['username']) && trim($validated['username']) !== '' ? trim($validated['username']) : null,
            'email' => strtolower(trim($validated['email'])),
            'password' => $validated['password'],
            'role' => $validated['role'] ?? 'Administrador',
            'position' => $validated['position'] ?? null,
            'department' => $validated['department'] ?? null,
            'company_id' => $validated['company_id'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'active' => $validated['active'] ?? true,
            'notes' => $validated['notes'] ?? null,
        ]);

        $user->load('company:id,business_name,rfc,week_start_day');
        $user->load('company:id,business_name,rfc,week_start_day');
        $user->loadCount(['correctionLogs', 'importBatches']);

        return response()->json([
            'message' => "Usuario \"{$user->name}\" creado correctamente.",
            'user' => $this->formatUser($user),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('users', 'username')->ignore($user->id),
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => ['nullable', 'string', 'min:6', 'max:120'],
            'role' => ['required', 'string', 'max:64'],
            'position' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:120'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'phone' => ['nullable', 'string', 'max:64'],
            'active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $currentUserId = $request->user()?->id;
        $newActive = array_key_exists('active', $validated) ? (bool) $validated['active'] : (bool) $user->active;

        if ($user->id === $currentUserId && ! $newActive) {
            return response()->json([
                'message' => 'No puedes desactivar tu propia cuenta mientras tienes sesión iniciada.',
            ], 422);
        }

        $payload = [
            'name' => trim($validated['name']),
            'username' => isset($validated['username']) && trim($validated['username']) !== '' ? trim($validated['username']) : null,
            'email' => strtolower(trim($validated['email'])),
            'role' => $validated['role'],
            'position' => $validated['position'] ?? null,
            'department' => $validated['department'] ?? null,
            'company_id' => array_key_exists('company_id', $validated) ? ($validated['company_id'] ?: null) : $user->company_id,
            'phone' => $validated['phone'] ?? null,
            'active' => $newActive,
            'notes' => $validated['notes'] ?? null,
        ];

        if (! empty($validated['password'])) {
            $payload['password'] = $validated['password'];
        }

        $user->update($payload);

        // Si el usuario fue deshabilitado, revocar todos sus tokens de acceso inmediatamente
        if (! $newActive) {
            $user->tokens()->delete();
        }

        $user->load('company:id,business_name,rfc,week_start_day');
        $user->loadCount(['correctionLogs', 'importBatches']);

        return response()->json([
            'message' => "Usuario \"{$user->name}\" actualizado correctamente.",
            'user' => $this->formatUser($user),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $currentUserId = $request->user()?->id;

        if ($user->id === $currentUserId) {
            return response()->json([
                'message' => 'No puedes eliminar tu propia cuenta activa.',
            ], 422);
        }

        $totalUsers = User::count();
        if ($totalUsers <= 1) {
            return response()->json([
                'message' => 'No es posible eliminar el único usuario del sistema.',
            ], 422);
        }

        $name = $user->name;
        $user->tokens()->delete();
        $user->delete();

        return response()->json([
            'message' => "El usuario \"{$name}\" ha sido eliminado y su acceso revocado.",
        ]);
    }

    private function formatUser(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'username' => $u->username ?? '',
            'email' => $u->email,
            'role' => $u->role ?? 'Administrador',
            'position' => $u->position ?? '',
            'department' => $u->department ?? '',
            'company_id' => $u->company_id ? (int) $u->company_id : null,
            'company' => $u->company ? [
                'id' => $u->company->id,
                'business_name' => $u->company->business_name,
                'rfc' => $u->company->rfc ?? '',
                'week_start_day' => (int) ($u->company->week_start_day ?? 3),
            ] : null,
            'phone' => $u->phone ?? '',
            'active' => (bool) ($u->active ?? true),
            'last_login_at' => $u->last_login_at?->toIso8601String(),
            'notes' => $u->notes ?? '',
            'correction_logs_count' => $u->correction_logs_count ?? 0,
            'import_batches_count' => $u->import_batches_count ?? 0,
            'created_at' => $u->created_at?->toIso8601String(),
        ];
    }
}
