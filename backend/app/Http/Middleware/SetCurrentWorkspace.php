<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Workspace scoping middleware.
 * - lit l'utilisateur Sanctum
 * - exporte le workspace courant dans une session var Postgres (`SET LOCAL app.current_workspace_id`)
 *   pour les policies RLS, et dans le container Laravel (`workspace.id`) pour les services.
 *
 * Sprint 18.9 — defensive : SET LOCAL hors transaction émet un WARNING (pas une
 * exception), mais sur certaines configs PG/pool ça peut throw. On wrappe pour
 * ne jamais 500 sur ce hot-path. Le binding container.workspace.id reste OK
 * pour la majorité des controllers qui se basent dessus.
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
            app()->instance('workspace.id', $workspaceId);
            try {
                DB::statement('SET LOCAL app.current_workspace_id = ' . $workspaceId);
            } catch (\Throwable $e) {
                // RLS dépend de cette session var, mais on ne crashe pas la requête —
                // les controllers utilisent app('workspace.id') en complément.
                Log::warning('SetCurrentWorkspace: SET LOCAL failed', [
                    'workspace_id' => $workspaceId,
                    'exception'    => $e->getMessage(),
                ]);
            }
        }

        return $next($request);
    }
}
