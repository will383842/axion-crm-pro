<?php

namespace App\Envoi\Serveur;

use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/** Journal des commande_id (unique) : ajout seul, jamais d'UPDATE ni de DELETE. */
final class JournalCommandes
{
    public function enregistrer(string $workspaceId, string $commandeId, string $commande): bool
    {
        return WorkspaceContext::run($workspaceId, fn (): bool => DB::affectingStatement(
            'INSERT INTO envoi_commandes (workspace_id, commande_id, commande) VALUES (?, ?, ?) ON CONFLICT (commande_id) DO NOTHING',
            [$workspaceId, $commandeId, $commande],
        ) === 1);
    }
}
