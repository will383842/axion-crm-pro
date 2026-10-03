<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Support\Partners\ConfigurationCanalPartners;
use App\Support\Partners\IdempotencePartners;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/internal/partners/v1/ping` — lot N11, SEULE route du futur canal
 * Axion Partners. Route TECHNIQUE : elle valide le canal de bout en bout
 * (signature, anti-rejeu, idempotence) et n'écrit RIEN d'autre que sa ligne
 * d'idempotence. Aucune route métier n'existe tant que le contrat (API 4,
 * INT-T69-P) n'est pas figé côté Partners.
 *
 * ⛔ INTERDIT comme sonde de supervision : chaque appel accepté laisse une
 * ligne (sans purge) dans la table tenue par `IdempotencePartners`. Le ping sert à valider le
 * canal à la main, lors d'une mise en service ou d'une rotation de clé.
 *
 * L'authentification est portée par `VerificateurCanalPartners` ; ce
 * contrôleur ne voit que des requêtes authentifiées. La réponse rejouée
 * annonce le mode D'ORIGINE (résumé stocké), pas le mode courant.
 */
class PartnersPingController extends Controller
{
    public const ROUTE = 'ping';

    public function __invoke(Request $request): JsonResponse
    {
        $mode = ConfigurationCanalPartners::depuisConfig()->mode;

        return IdempotencePartners::executer(
            $request,
            self::ROUTE,
            fn (): array => [200, $mode],
            fn (int $code, ?string $resume): array => $code === 200
                ? ['ok' => true, 'mode' => $resume]
                : ['ok' => false],
        );
    }
}
