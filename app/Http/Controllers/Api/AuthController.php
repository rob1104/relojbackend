<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $loginInput = trim($credentials['email']);

        $user = User::where('email', $loginInput)
            ->orWhere('username', $loginInput)
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            $log = activity('auth')
                ->event('login_failed')
                ->withProperties([
                    'email' => $loginInput,
                    'ip' => $request->ip(),
                    'user_agent' => (string) $request->userAgent(),
                    'reason' => $user ? 'Contraseña incorrecta' : 'Usuario no encontrado',
                ]);
            if ($user) {
                $log->performedOn($user);
            }
            $log->log("Intento fallido de inicio de sesión ({$loginInput})");

            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

        if ($user->active === false) {
            activity('auth')
                ->event('login_failed')
                ->performedOn($user)
                ->withProperties([
                    'email' => $loginInput,
                    'ip' => $request->ip(),
                    'user_agent' => (string) $request->userAgent(),
                    'reason' => 'Cuenta de usuario deshabilitada',
                ])
                ->log("Intento de acceso con cuenta deshabilitada ({$user->email})");

            throw ValidationException::withMessages([
                'email' => ['Esta cuenta de usuario se encuentra deshabilitada. Contacta al administrador del sistema.'],
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        activity('auth')
            ->event('login')
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties([
                'email' => $user->email,
                'role' => $user->role ?? 'Administrador',
                'ip' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ])
            ->log("Inicio de sesión exitoso de {$user->name}");

        $user->load('company:id,business_name,rfc,week_start_day');
        $token = $user->createToken('xis-reloj-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username ?? '',
                'email' => $user->email,
                'role' => $user->role ?? 'Administrador',
                'position' => $user->position ?? '',
                'department' => $user->department ?? '',
                'company_id' => $user->company_id ? (int) $user->company_id : null,
                'company' => $user->company ? [
                    'id' => $user->company->id,
                    'business_name' => $user->company->business_name,
                    'rfc' => $user->company->rfc ?? '',
                    'week_start_day' => (int) ($user->company->week_start_day ?? 3),
                ] : null,
                'active' => (bool) $user->active,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load('company:id,business_name,rfc,week_start_day');

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username ?? '',
                'email' => $user->email,
                'role' => $user->role ?? 'Administrador',
                'position' => $user->position ?? '',
                'department' => $user->department ?? '',
                'company_id' => $user->company_id ? (int) $user->company_id : null,
                'company' => $user->company ? [
                    'id' => $user->company->id,
                    'business_name' => $user->company->business_name,
                    'rfc' => $user->company->rfc ?? '',
                    'week_start_day' => (int) ($user->company->week_start_day ?? 3),
                ] : null,
                'active' => (bool) $user->active,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user) {
            activity('auth')
                ->event('logout')
                ->causedBy($user)
                ->performedOn($user)
                ->withProperties([
                    'email' => $user->email,
                    'ip' => $request->ip(),
                    'user_agent' => (string) $request->userAgent(),
                ])
                ->log("Cierre de sesión de {$user->name}");
        }

        $user?->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }
}
