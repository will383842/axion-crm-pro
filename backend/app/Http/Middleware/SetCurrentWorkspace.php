<?php

namespace App\Http\Middleware;

use App\Support\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Workspace scoping middleware.
 * - lit l'utilisateur Sanctum
 * - exporte le workspace courant dans une session var Postgres
 *   (`app.current_workspace_id`, lue par les policies RLS) et dans le
 *   container Laravel (`workspace.id`) pour les services.
 *
 * Sprint 18.9 — defensive : on ne crashe jamais le hot-path sur cet appel.
 *
 * Lot L0 (2026-08-14) — deux corrections :
 *
 * 1. La variable Postgres était posée avec `is_local = true` (portée
 *    transaction). Hors transaction explicite — le cas de la quasi-totalité
 *    des requêtes —, Postgres restreint alors la valeur au STATEMENT courant :
 *    elle était donc effacée juste après avoir été posée, et les policies RLS
 *    ne la voyaient jamais. On pose désormais au niveau SESSION.
 *
 * 2. Symétriquement, on NETTOIE le contexte en fin de requête (terminate) :
 *    une session Postgres peut survivre à la requête (pooling, connexions
 *    persistantes), et un contexte résiduel serait pire que pas de contexte.
 */
class SetCurrentWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        // workspaces.id est UUID en Postgres — ne PAS caster en int (PHP (int)"1db1..." = 1).
        // On accepte string (UUID/ULID) ou null. Le format est validé pour rejeter
        // les valeurs corrompues.
        $workspaceId = WorkspaceContext::validIdOrNull($user->current_workspace_id ?? null);

        if ($workspaceId !== null) {
            try {
                WorkspaceContext::set($workspaceId);
            } catch (\Throwable $e) {
                // RLS dépend de cette session var, mais on ne crashe pas la requête —
                // les controllers utilisent app('workspace.id') en complément.
                Log::warning('SetCurrentWorkspace: set_config failed', [
                    'workspace_id' => $workspaceId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            WorkspaceContext::clear();
        } catch (\Throwable $e) {
            Log::warning('SetCurrentWorkspace: clear failed', ['exception' => $e->getMessage()]);
        }
    }
}
