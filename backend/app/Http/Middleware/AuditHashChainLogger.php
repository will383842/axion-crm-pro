<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\Audit\AuditHashChain;

/**
 * Post-handle middleware : journalise les requêtes mutatives (POST/PUT/PATCH/DELETE) dans
 * la table `audit_logs` (chaîne cryptographique append-only).
 * Le hash de chaque ligne = sha256(prev_hash || canonical_row).
 */
class AuditHashChainLogger
{
    public function __construct(private readonly AuditHashChain $chain) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! in_array($request->method(), ['POST','PUT','PATCH','DELETE'], true)) {
            return $response;
        }

        // Évite les boucles : pas d'audit sur l'audit lui-même.
        if (str_starts_with($request->path(), 'api/v1/audit-logs')) {
            return $response;
        }

        try {
            $userId = optional($request->user())->id;
            $wsId   = app()->bound('workspace.id') ? app('workspace.id') : null;

            $this->chain->record([
                // audit_logs.workspace_id et user_id sont typés UUID en Postgres.
                // Si la valeur reçue n'est pas un UUID valide (legacy users en INT,
                // workspace mocké en INT, etc.), on stocke null pour ne pas planter
                // l'insert. La requête principale reste auditée par path/method/ip.
                'workspace_id' => self::asUuidOrNull($wsId),
                'user_id'      => self::asUuidOrNull($userId),
                'method'       => $request->method(),
                'path'         => $request->path(),
                'status'       => $response->getStatusCode(),
                'ip'           => $request->ip(),
                'user_agent'   => substr((string) $request->userAgent(), 0, 255),
                'payload_hash' => hash('sha256', json_encode($request->all(), JSON_THROW_ON_ERROR)),
            ]);
        } catch (\Throwable $e) {
            // Ne pas casser la requête sur erreur d'audit ; logger sentry/glitchtip.
            // report() peut lui-même lever si Sentry mal configuré : on swallow tout.
            try { report($e); } catch (\Throwable) { /* swallow */ }
        }

        return $response;
    }

    /**
     * Retourne la valeur si elle ressemble à un UUID (v1-v8) ou un ULID (26 chars Crockford base32).
     * Sinon null — utile quand l'ID user/workspace est legacy INT en attente de migration.
     */
    private static function asUuidOrNull(mixed $v): ?string
    {
        if ($v === null || $v === '') return null;
        $s = (string) $v;
        // UUID classique : 8-4-4-4-12 hex
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s)) {
            return $s;
        }
        // ULID Crockford base32 : 26 caractères [0-9A-HJKMNP-TV-Z]
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $s)) {
            return $s;
        }
        return null;
    }
}
