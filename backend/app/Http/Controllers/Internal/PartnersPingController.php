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
 * L'authentification est portée par `VerificateurCanalPartners` (404 en mode
 * `off`, 401 uniforme sinon) ; ce contrôleur ne voit que des requêtes
 * authentifiées. Il ne lit pas le corps : son contenu n'a aucun sens ici, seule
 * son empreinte compte pour l'idempotence.
 */
class PartnersPingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $mode = ConfigurationCanalPartners::depuisConfig()->mode;

        return IdempotencePartners::executer(
            $request,
            fn (): int => 200,
            fn (int $code): array => $code === 200 ? ['ok' => true, 'mode' => $mode] : ['ok' => false],
        );
    }
}
