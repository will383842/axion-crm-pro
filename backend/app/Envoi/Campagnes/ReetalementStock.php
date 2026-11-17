<?php

namespace App\Envoi\Campagnes;

use App\Envoi\Depot\DepotSocle;
use App\Envoi\Orchestration\PorteEnvoiReelle;
use App\Envoi\PasserelleLocale;
use App\Envoi\Sequence\FenetreHoraire;
use App\Envoi\Sequence\JoursOuvres;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Lot X1-L4-C : le jour où la porte s'ouvre sur un stock. Porte fermée, le cycle ne tourne pas et les échéances des inscriptions nées d'une
 * campagne passent dans le passé ; sans re-étalement tout le stock serait dû d'un coup, puis reporté « le lendemain à la même minute ».
 * On ne déplace que les inscriptions JAMAIS touchées (étape 1, aucun message, aucun report, aucun échec) d'une séquence née d'une campagne.
 * Seule la colonne `prochaine_echeance` est écrite. Aucune suppression, aucun envoi.
 */
final class ReetalementStock
{
    public const SEUIL_PORTE_OUVERTE_MINUTES = 30;

    public const LIMITE = 500;

    public function __construct(private readonly PorteEnvoiReelle $porte, private readonly DepotSocle $socle) {}

    /** @return int nombre d'échéances déplacées */
    public function reetaler(string $ws, DateTimeImmutable $maintenant): int
    {
        $p = min((int) config('envoi.plafond_jour'), $this->socle->plafondEspace($ws), PasserelleLocale::PLAFOND_MAX);
        if ($p <= 0) {
            return 0;
        }
        // Porte ouverte : on ne vole jamais au séquenceur une inscription qui vient d'échoir.
        $seuil = $this->porte->ouverte() ? $maintenant->modify('-' . self::SEUIL_PORTE_OUVERTE_MINUTES . ' minutes') : $maintenant;

        return WorkspaceContext::run($ws, fn (): int => DB::transaction(fn (): int => $this->deplacer($ws, $maintenant, $seuil, $p)));
    }

    private function deplacer(string $ws, DateTimeImmutable $maintenant, DateTimeImmutable $seuil, int $p): int
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '15000'");
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['envoi_etalement:' . $ws]);
        $lignes = DB::select(
            "SELECT i.id, i.sequence_id, i.version_figee->'fenetre' AS fenetre
               FROM envoi_inscriptions i
               JOIN envoi_sequences s ON s.workspace_id = i.workspace_id AND s.id = i.sequence_id AND s.campagne_id IS NOT NULL
               JOIN envoi_campagnes c ON c.workspace_id = s.workspace_id AND c.id = s.campagne_id AND c.statut IN ('programmee', 'en_cours', 'en_pause')
              WHERE i.workspace_id = ? AND i.statut = 'active' AND i.etape_courante = 1 AND i.reports = 0 AND i.echecs = 0
                AND i.prochaine_echeance < ?
                AND NOT EXISTS (SELECT 1 FROM envoi_messages m WHERE m.workspace_id = i.workspace_id AND m.inscription_id = i.id)
              ORDER BY i.prochaine_echeance, i.id
              LIMIT " . self::LIMITE . '
                FOR UPDATE OF i SKIP LOCKED',
            [$ws, $seuil->format('c')],
        );
        if ($lignes === []) {
            return 0;
        }
        $parSequence = [];
        foreach ($lignes as $l) {
            $parSequence[(string) $l->sequence_id][] = $l;
        }
        $deja = $this->dejaParJour($ws, $maintenant, array_values(array_map(static fn ($l): string => (string) $l->id, $lignes)));
        $apresHorizon = $this->premierJourApresHorizon($maintenant);
        $deplacees = 0;
        foreach ($parSequence as $sequence => $inscriptions) {
            $f = json_decode((string) $inscriptions[0]->fenetre, true);
            $fenetre = new FenetreHoraire((int) ($f['debut'] ?? 9), (int) ($f['fin'] ?? 17));
            $ids = array_map(static fn ($l): string => (string) $l->id, $inscriptions);
            $nouvelles = EtalementEcheances::repartir($fenetre, $maintenant, $maintenant, $p, $deja, $ids, $sequence);
            foreach ($ids as $id) {
                // Hors horizon : premier jour ouvré APRÈS l'horizon, à une heure irrégulière (elles seront reprises).
                $quand = $nouvelles[$id] ?? $this->heureIrreguliere($fenetre, $apresHorizon, $sequence . '|' . $id);
                $deplacees += DB::update("UPDATE envoi_inscriptions SET prochaine_echeance = ? WHERE workspace_id = ? AND id = ? AND statut = 'active'", [$quand->format('c'), $ws, $id]);
                $jour = $quand->setTimezone(new DateTimeZone(JoursOuvres::FUSEAU))->format('Y-m-d');
                $deja[$jour] = ($deja[$jour] ?? 0) + 1;
            }
        }

        return $deplacees;
    }

    /**
     * Inscriptions actives déjà prévues par jour (Paris), toutes séquences, SAUF celles qu'on déplace.
     *
     * @param  list<string>  $exclues
     * @return array<string, int>
     */
    private function dejaParJour(string $ws, DateTimeImmutable $maintenant, array $exclues): array
    {
        $debut = $maintenant->setTimezone(new DateTimeZone(JoursOuvres::FUSEAU))->setTime(0, 0);
        $par = [];
        $lignes = DB::select(
            "SELECT to_char(prochaine_echeance AT TIME ZONE 'Europe/Paris', 'YYYY-MM-DD') AS jour, count(*) AS n
               FROM envoi_inscriptions WHERE workspace_id = ? AND statut = 'active' AND prochaine_echeance >= ? AND prochaine_echeance < ?
                AND NOT (id = ANY (?::uuid[])) GROUP BY 1",
            [$ws, $debut->format('c'), $debut->modify('+60 days')->format('c'), '{' . implode(',', $exclues) . '}'],
        );
        foreach ($lignes as $l) {
            $par[(string) $l->jour] = (int) $l->n;
        }

        return $par;
    }

    private function premierJourApresHorizon(DateTimeImmutable $maintenant): DateTimeImmutable
    {
        $jour = $maintenant->modify('+' . EtalementEcheances::MARGE_MINUTES . ' minutes')->setTimezone(new DateTimeZone(JoursOuvres::FUSEAU))->setTime(0, 0);
        for ($ouvres = 0; $ouvres < EtalementEcheances::HORIZON_JOURS_OUVRES; $jour = $jour->modify('+1 day')) {
            $ouvres += JoursOuvres::estOuvre($jour) ? 1 : 0;
        }
        while (! JoursOuvres::estOuvre($jour)) {
            $jour = $jour->modify('+1 day');
        }

        return $jour;
    }

    private function heureIrreguliere(FenetreHoraire $fenetre, DateTimeImmutable $jour, string $cle): DateTimeImmutable
    {
        $fraction = EtalementEcheances::ALEA_MIN + (EtalementEcheances::ALEA_MAX - EtalementEcheances::ALEA_MIN) * (hexdec(substr(hash('sha256', $cle), 0, 12)) / (16 ** 12));
        $secondes = (int) floor($fenetre->debut * 3600 + ($fenetre->fin - $fenetre->debut) * 3600 * $fraction);

        return $jour->setTime(intdiv($secondes, 3600), intdiv($secondes % 3600, 60), $secondes % 60);
    }
}
