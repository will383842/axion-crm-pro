<?php

namespace App\Http\Controllers\Api;

use App\Crm\Console\ConsoleAccess;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DRAPEAUX D'INTERFACE, lus au RUNTIME.
 *
 * Pourquoi pas une variable `VITE_*` : une variable Vite est figée au BUILD.
 * Or l'image frontend est construite une fois et déployée partout ; activer la
 * console v2 supposerait alors de reconstruire et redéployer le frontend, donc
 * de ne PAS pouvoir revenir en arrière en une minute. Le drapeau vit côté API,
 * il se bascule par une variable d'environnement et un redémarrage — c'est la
 * même poignée que celle qui ouvre les routes, et il n'y en a qu'une.
 *
 * Cette route reste accessible drapeau FERMÉ (elle répond alors
 * `console_v2: false`) : c'est précisément son rôle. La mettre derrière le
 * drapeau qu'elle annonce aurait été circulaire.
 *
 * `universes` dit au frontend ce qu'il a le droit d'AFFICHER, pour qu'une
 * entrée de navigation menant à un 403 n'existe simplement pas — l'étanchéité
 * se lit dans la navigation elle-même (conception §2.2), elle ne se découvre
 * pas au clic.
 */
class FeaturesController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $consoleV2 = filter_var(config('crm.console_v2', false), FILTER_VALIDATE_BOOLEAN);

        $user = $request->user();
        $vivier = false;
        $business = false;

        if ($user instanceof User) {
            $vivier = ConsoleAccess::canAccessVivier($user);
            $business = ConsoleAccess::businessWorkspaceId($user) !== null;
        }

        return $this->ok([
            'console_v2' => $consoleV2,
            'universes' => [
                'business' => $consoleV2 && $business,
                'vivier' => $consoleV2 && $vivier,
            ],
        ]);
    }
}
