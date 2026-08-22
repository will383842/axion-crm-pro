<?php

namespace App\Http\Controllers\Api\Phase2;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ⚠️ CLASSE MORTE — constat B12-017. À SUPPRIMER, ce fichier ne devrait plus
 * exister.
 *
 * Mesure du 2026-08-22 : `routes/api.php` n'importe que ses deux voisins
 * (`ColdEmailController`, `LinkedInController`) ; une recherche de
 * `Phase2\CampaignsController` sur tout `backend/` ne rend AUCUNE occurrence.
 * Aucune route ne désigne cette classe, et `/campaigns` est servi depuis le
 * Sprint 19.7 par le contrôleur réel des campagnes de collecte.
 *
 * CE QUI A ÉTÉ FERMÉ ICI : l'annotation `@OA\Get(path="/campaigns", …)` que
 * portait `__invoke()`. Elle publiait ce chemin fantôme dans la spécification
 * OpenAPI servie par Swagger — un `/campaigns` documenté en « 501 Not
 * implemented » à côté du vrai `/campaigns`, qui répond. C'était la moitié
 * NUISIBLE du défaut : elle trompait un lecteur de la documentation d'API.
 *
 * CE QUI RESTE OUVERT : la suppression du fichier lui-même. L'agent qui a fermé
 * l'annotation le 2026-08-22 n'avait pas le droit d'effacer un fichier dans son
 * environnement. Le geste restant est un `rm` de ce fichier — rien ne le
 * référence, rien ne casse.
 *
 * Garde : `tests/Feature/Phase2SansCheminFantomeTest.php`.
 */
class CampaignsController extends ApiController
{
    public function __invoke(Request $r): JsonResponse
    {
        return $this->notImplemented('Phase 2');
    }
}
