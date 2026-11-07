<?php

namespace App\Envoi\Serveur;

use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/** Commande_id (unique) dans envoi_journal_commandes (ADR K0) : ajout seul, jamais d'UPDATE ni de DELETE. */
final class JournalCommandes
{
    public function enregistrer(string $workspaceId, string $commandeId, string $commande): bool
    {
        return WorkspaceContext::run($workspaceId, fn (): bool => DB::affectingStatement(
            'INSERT INTO envoi_journal_commandes (workspace_id, commande_id, commande, auteur) VALUES (?, ?, ?, ?) ON CONFLICT (commande_id) DO NOTHING',
            [$workspaceId, $commandeId, $commande, 'serveur-k1'],
        ) === 1);
    }
}
