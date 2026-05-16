<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Workspace scoping middleware.
 * - lit l'utilisateur Sanctum
 * - exporte le workspace courant dans une session var Postgres (`SET LOCAL app.current_workspace_id`)
 *   pour les policies RLS, et dans le container Laravel (`workspace.id`) pour les services.
 */
class SetCurrentWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $workspaceId = (int) ($user->current_workspace_id ?? 0);
        if ($workspaceId > 0) {
            DB::statement('SET LOCAL app.current_workspace_id = ' . $workspaceId);
            app()->instance('workspace.id', $workspaceId);
        }

        return $next($request);
    }
}
