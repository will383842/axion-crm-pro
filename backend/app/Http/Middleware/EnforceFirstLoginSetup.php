<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force la finalisation du setup au 1er login : 2FA enrolment obligatoire + acceptation CGV interne.
 * Renvoie 403 first_login_required tant que `first_login_completed_at` est null.
 */
class EnforceFirstLoginSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        // Routes autorisées pour finaliser le setup
        $allowList = [
            'api/v1/auth/me',
            'api/v1/auth/logout',
            'api/v1/auth/2fa/setup',
            'api/v1/auth/2fa/confirm',
            'api/v1/auth/2fa/verify',
        ];

        if ($user->first_login_completed_at === null
            && ! in_array($request->path(), $allowList, true)) {
            return response()->json([
                'error' => 'first_login_required',
                'message' => 'Vous devez activer la double authentification avant utilisation.',
                'next_step' => '/auth/2fa/setup',
            ], 403);
        }

        return $next($request);
    }
}
