<?php

namespace App\Envoi\Sequence;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * RR-4C — absence du bureau : la prochaine relance est repoussée à 7 jours après l'auto-réponse (au plus tôt).
 * Aucun arrêt, aucun nouveau statut : seules les inscriptions `active` du MÊME espace sont touchées (jamais rouvertes).
 * `GREATEST` : plusieurs absences ne s'additionnent pas, une échéance déjà plus lointaine n'est jamais avancée.
 * La fenêtre horaire reste celle du séquenceur. Ajout seul, aucun envoi.
 */
final class ReportAbsence
{
    public const JOURS = 7;

    /**
     * @param  list<string>  $ids  inscriptions rattachées au retour
     * @return int nombre d'inscriptions dont l'échéance a été lue pour report
     */
    public function reporter(string $ws, array $ids, DateTimeImmutable $maintenant): int
    {
        if ($ids === []) {
            return 0;
        }
        $marqueurs = implode(',', array_fill(0, count($ids), '?'));

        return DB::update(
            "UPDATE envoi_inscriptions SET prochaine_echeance = GREATEST(prochaine_echeance, ?::timestamptz + interval '" . self::JOURS . " days')
             WHERE workspace_id = ? AND statut = 'active' AND id IN ({$marqueurs})",
            [$maintenant->format('c'), $ws, ...array_values($ids)],
        );
    }
}
