<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drapeau de la console CRM v2 (lot L6).
 *
 * Tant que `crm.console_v2` est à false — le défaut —, toutes les routes
 * `/v1/crm/*` de la console répondent **404** : elles sont livrées, testées, et
 * inertes. C'est la condition de fusion progressive du chantier (ordre de
 * mission, § STRATÉGIE GIT).
 *
 * 404 et non 503 : un 503 est une promesse de réessai, adaptée à un canal
 * d'ingestion dont l'émetteur doit rejouer sa ligne (L2/L3). La console n'a
 * rien à rejouer — et un 404 ne divulgue pas l'existence d'une surface fermée.
 *
 * ⚠️ Le corps de la réponse est volontairement le 404 JSON standard de Laravel :
 * un message du genre « console désactivée » trahirait précisément ce que le
 * 404 est censé taire.
 */
class EnsureCrmConsoleV2
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! filter_var(config('crm.console_v2', false), FILTER_VALIDATE_BOOLEAN)) {
            abort(404);
        }

        return $next($request);
    }
}
