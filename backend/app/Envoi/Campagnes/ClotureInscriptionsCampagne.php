<?php

namespace App\Envoi\Campagnes;

use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ferme les inscriptions ACTIVES d'une campagne arrêtée ou terminée (L5-B) : statut « arretee » + motif propre, rien n'est supprimé.
 * Une inscription déjà arrêtée (réponse, opposition, rebond) ou terminée garde son statut et son motif : on ne fait que fermer, jamais rouvrir.
 * Aucune ligne d'événement de personne n'est écrite (arrêter une campagne n'est pas une opposition) ; les motifs n'entrent dans aucune
 * liste du séquenceur. Les lignes tenues par le séquenceur sont sautées (SKIP LOCKED) et reprises au tour suivant de la commande.
 */
final class ClotureInscriptionsCampagne
{
    public const MOTIF_ARRETEE = 'campagne_arretee';

    public const MOTIF_TERMINEE = 'campagne_terminee';

    public const LIMITE = 1000;

    /** Ferme au plus $limite inscriptions actives de la séquence de la campagne ; rend le nombre fermé. Utilisable dans une transaction ouverte. */
    public function clore(string $ws, string $campagneId, DateTimeImmutable $maintenant, int $limite = self::LIMITE): int
    {
        return WorkspaceContext::run($ws, fn (): int => DB::transaction(function () use ($ws, $campagneId, $maintenant, $limite): int {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("SET LOCAL statement_timeout = '15000'");
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['envoi_etalement:' . $ws]);   // toujours le dernier verrou
            $c = DB::selectOne('SELECT statut, sequence_id FROM envoi_campagnes WHERE workspace_id = ? AND id = ? FOR SHARE', [$ws, $campagneId]);
            if ($c === null || ! in_array($c->statut, ['arretee', 'terminee'], true)) {
                throw new \LogicException('On ne ferme jamais les inscriptions d\'une campagne vivante.');
            }
            if ($c->sequence_id === null) {
                return 0;
            }

            // CTE matérialisée : avec « id IN (sous-requête LIMIT … FOR UPDATE) » le planificateur peut réévaluer la sous-requête et fermer PLUS que la limite.
            return DB::update(
                "WITH choisies AS MATERIALIZED (
                    SELECT i.id FROM envoi_inscriptions i
                     WHERE i.workspace_id = ? AND i.sequence_id = ? AND i.statut = 'active'
                     ORDER BY i.id LIMIT " . max(1, $limite) . " FOR UPDATE SKIP LOCKED)
                 UPDATE envoi_inscriptions SET statut = 'arretee', motif_arret = ?, arretee_le = ?
                  WHERE workspace_id = ? AND statut = 'active' AND id IN (SELECT id FROM choisies)",
                [$ws, $c->sequence_id, $c->statut === 'arretee' ? self::MOTIF_ARRETEE : self::MOTIF_TERMINEE, $maintenant->format('c'), $ws],
            );
        }));
    }
}
