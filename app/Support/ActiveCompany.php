<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Http\Request;

class ActiveCompany
{
    /**
     * Resuelve el company_id activo para el usuario en sesión.
     * - Usuario no-Administrador: SIEMPRE queda fijo en $user->company_id.
     * - Administrador: puede elegir una razón social activa vía header X-Company-Id o query ?company_id=.
     */
    public static function resolveId(Request $request): ?int
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }

        if (($user->role ?? '') !== 'Administrador') {
            return $user->company_id ? (int) $user->company_id : null;
        }

        $raw = $request->header('X-Company-Id') ?? $request->input('company_id');
        if ($raw === 'all' || $raw === '0') {
            return null;
        }
        if ($raw !== null && $raw !== '') {
            $id = (int) $raw;
            if ($id > 0 && Company::where('id', $id)->exists()) {
                return $id;
            }
        }

        return $user->company_id ? (int) $user->company_id : null;
    }
}
