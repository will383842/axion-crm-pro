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
            $this->chain->record([
                'workspace_id' => app()->bound('workspace.id') ? app('workspace.id') : null,
                'user_id'      => optional($request->user())->id,
                'method'       => $request->method(),
                'path'         => $request->path(),
                'status'       => $response->getStatusCode(),
                'ip'           => $request->ip(),
                'user_agent'   => substr((string) $request->userAgent(), 0, 255),
                'payload_hash' => hash('sha256', json_encode($request->all(), JSON_THROW_ON_ERROR)),
            ]);
        } catch (\Throwable $e) {
            // Ne pas casser la requête sur erreur d'audit ; logger sentry/glitchtip.
            report($e);
        }

        return $response;
    }
}
