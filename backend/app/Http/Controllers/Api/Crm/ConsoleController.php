<?php

namespace App\Http\Controllers\Api\Crm;

use App\Crm\Console\ConsoleAccess;
use App\Http\Controllers\Api\ApiController;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Socle commun des contrôleurs de la console CRM v2 (lot L6).
 *
 * Il ne porte QUE la résolution d'univers, parce que c'est la seule chose qui
 * doit être identique partout : un écart de garde entre deux contrôleurs est
 * exactement la façon dont une frontière RGPD se perce.
 */
abstract class ConsoleController extends ApiController
{
    protected function currentUser(Request $request): User
    {
        $user = $request->user();

        // `auth:sanctum` a déjà tranché ; ce garde-fou existe pour le typage
        // statique (PHPStan niveau 8) et pour qu'un jour où la route serait
        // déplacée hors du groupe protégé, l'échec soit un 401 franc.
        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }

    /**
     * Workspace BUSINESS de travail, ou 403.
     *
     * On refuse explicitement quand le workspace courant EST le vivier : les
     * deux univers ont des navigations distinctes (conception §2.2), et
     * répondre « liste vide » à quelqu'un qui regarde le mauvais univers est le
     * meilleur moyen de lui faire croire que la base est vide.
     */
    protected function businessWorkspace(Request $request): string
    {
        $workspaceId = ConsoleAccess::businessWorkspaceId($this->currentUser($request));

        if ($workspaceId === null) {
            abort(403, "Aucun univers business courant : bascule d'univers requise.");
        }

        return $workspaceId;
    }

    /**
     * Workspace VIVIER, ou 403 — l'appartenance est exigée, et elle se lit dans
     * `user_workspaces`, jamais dans `users.current_workspace_id` (pointeur
     * d'affichage que l'utilisateur modifie lui-même).
     */
    protected function vivierWorkspace(Request $request): string
    {
        $user = $this->currentUser($request);
        $vivier = ConsoleAccess::vivierWorkspaceId();

        if ($vivier === null || ! ConsoleAccess::isMemberOf($user, $vivier)) {
            abort(403, "Accès refusé à l'univers vivier candidats.");
        }

        return $vivier;
    }

    /**
     * Borne de pagination commune. 25 par défaut, 200 au plafond : au-delà, la
     * console n'affiche plus, elle exporte (et l'export a son propre chemin).
     */
    protected function perPage(Request $request): int
    {
        return min(200, max(1, (int) $request->query('per_page', '25')));
    }
}
