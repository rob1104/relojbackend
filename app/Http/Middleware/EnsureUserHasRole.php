<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Verifica que el usuario autenticado esté activo y tenga uno de los roles permitidos.
     */
    public function handle(Request $request, Closure $next, string ...$allowedRoles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'No autenticado.',
            ], 401);
        }

        if ($user->active === false) {
            $user->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'Tu cuenta de usuario ha sido suspendida por el administrador.',
            ], 403);
        }

        if (! empty($allowedRoles)) {
            $userRole = trim((string) ($user->role ?: 'Administrador'));
            if (! in_array($userRole, $allowedRoles, true)) {
                return response()->json([
                    'message' => "Permiso insuficiente: tu rol actual ({$userRole}) no está autorizado para realizar esta operación.",
                ], 403);
            }
        }

        return $next($request);
    }
}
