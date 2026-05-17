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

        // workspaces.id est UUID en Postgres — ne PAS caster en int (PHP (int)"1db1..." = 1).
        // On accepte string (UUID/ULID) ou null. On valide le format pour rejeter les
        // valeurs corrompues (anti-injection avant le set_config string-interpolated).
        $raw = $user->current_workspace_id ?? null;
        $workspaceId = self::validIdOrNull($raw);

        if ($workspaceId !== null) {
            app()->instance('workspace.id', $workspaceId);
            try {
                // set_config() est paramétrable (contrairement à SET LOCAL) → safe.
                // 3e arg `true` = local à la transaction courante.
                DB::select('SELECT set_config(?, ?, true)', ['app.current_workspace_id', $workspaceId]);
            } catch (\Throwable $e) {
                // RLS dépend de cette session var, mais on ne crashe pas la requête —
                // les controllers utilisent app('workspace.id') en complément.
                Log::warning('SetCurrentWorkspace: set_config failed', [
                    'workspace_id' => $workspaceId,
                    'exception'    => $e->getMessage(),
                ]);
            }
        }

        return $next($request);
    }

    /**
     * Accepte UUID v1-v8, ULID Crockford base32 (26 chars), ou bigint legacy (>0).
     * Sinon null. Garde-fou anti-corruption + anti-injection.
     */
    private static function validIdOrNull(mixed $v): ?string
    {
        if ($v === null || $v === '') return null;
        $s = (string) $v;
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s)) return $s;
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $s)) return $s;
        if (preg_match('/^[1-9][0-9]*$/', $s)) return $s; // bigint legacy
        return null;
    }
}
