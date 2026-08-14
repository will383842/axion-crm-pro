<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Levée quand du code touche des données workspace-scopées SANS avoir posé le
 * contexte workspace.
 *
 * Raison d'être (piège identifié à la contre-vérification du 2026-08-13) :
 * le middleware SetCurrentWorkspace ne s'applique QU'AUX requêtes HTTP. Les
 * jobs Horizon, le scheduler et les commandes artisan n'ont aucun contexte.
 * Avec des policies RLS strictes, une purge qui « oublie » de poser
 * `app.current_workspace_id` voit ZÉRO ligne et ne purge donc RIEN — tout en
 * sortant en succès. Un cron vert qui ne fait pas son travail est le pire des
 * échecs (leçons EmailFinder et IndexNow).
 *
 * D'où la règle : sans contexte, on échoue BRUYAMMENT.
 */
class MissingWorkspaceContextException extends RuntimeException
{
    public static function for(string $what): self
    {
        return new self(
            "Contexte workspace absent pour « {$what} ». Tout code hors requête HTTP "
            . '(job Horizon, scheduler, commande artisan) doit poser explicitement le '
            . 'contexte : WorkspaceContext::run($workspaceId, fn () => ...). '
            . 'Un accès sans contexte renverrait zéro ligne en silence.',
        );
    }
}
