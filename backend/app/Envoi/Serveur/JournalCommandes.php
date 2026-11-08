<?php

namespace App\Envoi\Serveur;

use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Journal des commandes vers le serveur d'envoi (ADR K0) dans envoi_journal_commandes : AJOUT SEUL, jamais
 * d'UPDATE ni de DELETE. Deux sortes de lignes : l'INTENTION (commande_id renseigné, UNIQUE par espace) posée
 * AVANT l'appel réseau, et l'ACQUITTEMENT (ligne distincte « acquittement-serveur ») posé seulement quand le
 * serveur a répondu correctement. Une commande sans acquittement peut être renvoyée avec le même commande_id.
 */
final class JournalCommandes
{
    public const ACQUITTEMENT = 'acquittement-serveur';

    /** Pose l'intention. Vrai = nouvelle ligne ; faux = déjà connue (même corps). Corps différent = refus + alerte. */
    public function enregistrer(string $workspaceId, string $commandeId, string $commande, string $source): bool
    {
        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $commandeId, $commande, $source): bool {
            $pose = DB::affectingStatement(
                'INSERT INTO envoi_journal_commandes (workspace_id, commande_id, commande, source, auteur, details) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT (workspace_id, commande_id) DO NOTHING',
                [$workspaceId, $commandeId, $commande, $source, 'serveur-k1', json_encode(['corps_sha256' => hash('sha256', $commande)], JSON_THROW_ON_ERROR)],
            ) === 1;
            if ($pose) {
                return true;
            }
            $existante = DB::table('envoi_journal_commandes')->where('workspace_id', $workspaceId)->where('commande_id', $commandeId)->value('commande');
            if ($existante !== $commande) {
                Log::critical('envoi.commande_id_reutilise_avec_autre_corps', ['workspace_id' => $workspaceId, 'commande_id' => $commandeId]);
                throw ServeurEnvoiRefuse::motif('commande_id déjà utilisé avec un autre corps (refus, alerte levée).');
            }

            return false;
        });
    }

    public function estAcquittee(string $workspaceId, string $commandeId): bool
    {
        return WorkspaceContext::run($workspaceId, fn (): bool => DB::table('envoi_journal_commandes')
            ->where('workspace_id', $workspaceId)->where('commande', self::ACQUITTEMENT)->where('cle_idempotence', $commandeId)->exists());
    }

    public function acquitter(string $workspaceId, string $commandeId): void
    {
        // ON CONFLICT : deux rejeux concurrents ne produisent qu'UN acquittement (index unique partiel).
        WorkspaceContext::run($workspaceId, fn () => DB::affectingStatement(
            'INSERT INTO envoi_journal_commandes (workspace_id, commande, auteur, details, cle_idempotence) VALUES (?, ?, ?, ?, ?) ON CONFLICT (workspace_id, cle_idempotence) WHERE commande = \'acquittement-serveur\' DO NOTHING',
            [$workspaceId, self::ACQUITTEMENT, 'serveur-k1', json_encode(['commande_id' => $commandeId], JSON_THROW_ON_ERROR), $commandeId],
        ));
    }

    /**
     * Intentions SANS acquittement de l'espace (les plus anciennes d'abord), à rejouer avec le même commande_id.
     *
     * @return list<array{commande_id: string, commande: string, source: string}>
     */
    public function nonAcquittees(string $workspaceId, int $limite): array
    {
        return WorkspaceContext::run($workspaceId, fn (): array => array_values(array_map(
            fn ($l): array => ['commande_id' => (string) $l->commande_id, 'commande' => (string) $l->commande, 'source' => (string) $l->source],
            DB::select(
                'SELECT i.commande_id, i.commande, i.source FROM envoi_journal_commandes i
                 WHERE i.workspace_id = ? AND i.commande_id IS NOT NULL AND i.source IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM envoi_journal_commandes a WHERE a.workspace_id = i.workspace_id AND a.commande = ? AND a.cle_idempotence = i.commande_id)
                 ORDER BY i.id LIMIT ?',
                [$workspaceId, self::ACQUITTEMENT, $limite],
            ),
        )));
    }
}
