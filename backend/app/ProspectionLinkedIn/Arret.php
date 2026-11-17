<?php

namespace App\ProspectionLinkedIn;

use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * PONT-LINKEDIN LI-B2 — l'arrêt de la voie S est-il actif ? LECTURE SEULE de `pl_arret` (ajout seul) : la dernière ligne, de
 * l'expéditeur ou « tous » (expéditeur nul), fait foi. Échec fermé : toute erreur de lecture ⇒ arrêt considéré actif.
 */
final class Arret
{
    public function actif(string $workspaceId, string $expediteurId): bool
    {
        try {
            $dernier = DB::transaction(static fn () => WorkspaceContext::run($workspaceId, static fn () => DB::table('pl_arret')
                ->where('workspace_id', $workspaceId)
                ->where(static fn ($q) => $q->where('expediteur_user_id', $expediteurId)->orWhereNull('expediteur_user_id'))
                ->orderByDesc('id')->value('geste')));

            return $dernier === 'arret';
        } catch (Throwable) {
            return true;
        }
    }
}
