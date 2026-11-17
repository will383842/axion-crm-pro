<?php

namespace App\Envoi\Campagnes;

use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/**
 * Compteurs réels d'une campagne (lot L6-A, cahier L5-L6 § 6). LECTURE SEULE : aucune écriture, aucune migration.
 *
 * Population = les inscriptions de `envoi_campagnes.sequence_id`, tous statuts. UNE SEULE instruction SQL (CTE) par appel : instantané
 * cohérent. Filtre d'espace explicite sur CHAQUE table (la RLS ne protège pas le propriétaire). Toutes les clés « inscription » sont des
 * comptes DISTINCTS d'inscriptions : aucun double comptage ; `issues` range chaque inscription dans UNE seule case. « Ouverts » : `null`,
 * aucun suivi d'ouverture n'existe (`suivi_ouvertures` faux). Rattachement d'un retour à un message : les trois formes de
 * `RecepteurRetoursSequence` (`id_injection`, `id_injection_serveur`, clé `seq-<inscription>-<étape>` du registre local), recopiées en SQL.
 */
final class CompteursCampagne
{
    public const MOTIFS_OPPOSITION = ['opposition', 'garde:opposition', 'stop'];

    /** Colonnes entières de l'agrégat `g` (0 quand la campagne n'a aucune inscription). */
    private const CLES = ['inscrits', 'actives', 'envoyes', 'touches', 'refuses', 'reponses', 'rebonds_durs', 'rebonds_mous', 'oppositions', 'arretes_entreprise', 'arretes_campagne',
        'i_opposition', 'i_rebond_dur', 'i_reponse', 'i_rebond_mou', 'i_arretee_autre', 'i_terminee', 'i_active'];

    public const SQL = <<<'SQL'
        WITH camp AS (
            SELECT c.id AS cid, c.sequence_id AS sid FROM envoi_campagnes c WHERE c.workspace_id = :ws AND c.id = ANY (:ids)
        ),
        ins AS (
            SELECT camp.cid, i.id, i.statut, i.motif_arret
            FROM camp JOIN envoi_inscriptions i ON i.workspace_id = :ws AND i.sequence_id = camp.sid
        ),
        msg AS (
            SELECT ins.id AS iid, m.etape, m.statut, m.id_injection, m.id_injection_serveur
            FROM ins JOIN envoi_messages m ON m.workspace_id = :ws AND m.inscription_id = ins.id
        ),
        lie AS (
            SELECT msg.iid, e.classe FROM msg
                JOIN envoi_evenements e ON e.workspace_id = :ws AND e.statut = 'rattache' AND e.id_injection::text = msg.id_injection
            UNION ALL
            SELECT msg.iid, e.classe FROM msg
                JOIN envoi_evenements e ON e.workspace_id = :ws AND e.statut = 'rattache' AND e.id_injection::text = msg.id_injection_serveur
            UNION ALL
            SELECT msg.iid, e.classe FROM msg
                JOIN envoi_injections_locales l ON l.workspace_id = :ws AND l.cle_idempotence = 'seq-' || msg.iid::text || '-' || msg.etape::text
                JOIN envoi_evenements e ON e.workspace_id = :ws AND e.statut = 'rattache' AND e.id_injection = l.id_injection
        ),
        mc AS (
            SELECT iid, count(*) FILTER (WHERE statut = 'envoye') AS env, count(*) FILTER (WHERE statut = 'refuse') AS ref FROM msg GROUP BY iid
        ),
        lc AS (
            SELECT iid, bool_or(classe = 'rebond_dur') AS dur, bool_or(classe = 'rebond_mou') AS mou, bool_or(classe = 'desinscription') AS des FROM lie GROUP BY iid
        ),
        tr AS (
            SELECT t.inscription_id AS iid, bool_or(t.evenement = 'reponse') AS rep, bool_or(t.evenement IN ('stop', 'opposition')) AS opp, bool_or(t.evenement = 'rebond_dur') AS dur
            FROM ins JOIN envoi_inscriptions_evenements t ON t.workspace_id = :ws AND t.inscription_id = ins.id GROUP BY t.inscription_id
        ),
        f AS (
            SELECT ins.cid, ins.statut, ins.motif_arret,
                COALESCE(mc.env, 0) AS env, COALESCE(mc.ref, 0) AS ref,
                COALESCE(tr.rep, false) AS rep,
                (COALESCE(tr.dur, false) OR COALESCE(lc.dur, false)) AS dur,
                COALESCE(lc.mou, false) AS mou,
                (COALESCE(tr.opp, false) OR COALESCE(lc.des, false) OR COALESCE(ins.motif_arret IN ('opposition', 'garde:opposition', 'stop'), false)) AS opp
            FROM ins LEFT JOIN mc ON mc.iid = ins.id LEFT JOIN lc ON lc.iid = ins.id LEFT JOIN tr ON tr.iid = ins.id
        ),
        g AS (
            SELECT cid,
                count(*) AS inscrits,
                count(*) FILTER (WHERE statut = 'active') AS actives,
                COALESCE(sum(env), 0) AS envoyes,
                count(*) FILTER (WHERE env > 0) AS touches,
                COALESCE(sum(ref), 0) AS refuses,
                count(*) FILTER (WHERE rep) AS reponses,
                count(*) FILTER (WHERE dur) AS rebonds_durs,
                count(*) FILTER (WHERE mou AND NOT dur) AS rebonds_mous,
                count(*) FILTER (WHERE opp) AS oppositions,
                count(*) FILTER (WHERE motif_arret = 'entreprise_arretee') AS arretes_entreprise,
                count(*) FILTER (WHERE motif_arret IN ('campagne_arretee', 'campagne_terminee')) AS arretes_campagne,
                count(*) FILTER (WHERE opp) AS i_opposition,
                count(*) FILTER (WHERE NOT opp AND dur) AS i_rebond_dur,
                count(*) FILTER (WHERE NOT opp AND NOT dur AND rep) AS i_reponse,
                count(*) FILTER (WHERE NOT opp AND NOT dur AND NOT rep AND mou) AS i_rebond_mou,
                count(*) FILTER (WHERE NOT opp AND NOT dur AND NOT rep AND NOT mou AND statut = 'arretee') AS i_arretee_autre,
                count(*) FILTER (WHERE NOT opp AND NOT dur AND NOT rep AND NOT mou AND statut = 'terminee') AS i_terminee,
                count(*) FILTER (WHERE NOT opp AND NOT dur AND NOT rep AND NOT mou AND statut = 'active') AS i_active
            FROM f GROUP BY cid
        )
        SELECT camp.cid AS campagne_id, g.*, mat.etat AS prep_etat, mat.motif AS prep_motif, mat.compteurs->>'inscrits' AS prep_inscrits,
            mat.compteurs->>'membres' AS prep_membres, mat.compteurs->>'retenus' AS prep_retenus
        FROM camp LEFT JOIN g ON g.cid = camp.cid
            LEFT JOIN envoi_campagnes_materialisations mat ON mat.campagne_id = camp.cid AND mat.workspace_id = :ws
        SQL;

    /** @return array<string, int|bool|null|array> une campagne ; toutes clés présentes ; zéros si la campagne n'a pas de séquence */
    public function pour(string $ws, string $campagneId): array
    {
        return $this->pourListe($ws, [$campagneId])[$campagneId] ?? self::zeros();
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array<string, int|bool|null|array>> une requête pour toute une page
     */
    public function pourListe(string $ws, array $ids): array
    {
        $sortie = [];
        foreach ($ids as $id) {
            $sortie[$id] = self::zeros();
        }
        $valides = array_values(array_unique(array_filter($ids, fn ($i) => is_string($i) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $i) === 1)));
        if ($valides === []) {
            return $sortie;
        }
        // Sous le rôle applicatif, la politique RLS rend les estimations du planificateur aveugles (1 ligne attendue) : il choisit alors des boucles
        // imbriquées sur des milliers de lignes (minutes). Pour CETTE instruction seulement (portée de transaction), on lui retire les boucles imbriquées :
        // jointures par hachage, linéaires en lignes de la population, identiques dans les deux rôles.
        $lignes = WorkspaceContext::run($ws, fn () => DB::transaction(function () use ($ws, $valides): array {
            DB::select("SELECT set_config('enable_nestloop', 'off', true)");
            try {
                return DB::select(...self::preparer($ws, $valides));
            } finally {
                DB::select("SELECT set_config('enable_nestloop', 'on', true)");
            }
        }));
        foreach ($lignes as $l) {
            foreach ($ids as $id) {
                if (strcasecmp($id, (string) $l->campagne_id) === 0) {
                    $sortie[$id] = self::exposer($l);
                }
            }
        }

        return $sortie;
    }

    /**
     * Le SQL à placeholders positionnels et ses liaisons, dans l'ordre : l'espace sur CHAQUE table (index utilisables), puis les identifiants.
     *
     * @param  list<string>  $ids
     * @return array{0: string, 1: list<string>}
     */
    public static function preparer(string $ws, array $ids): array
    {
        $liaisons = [];
        $sql = preg_replace_callback('/:(ws|ids)\b/', function (array $m) use (&$liaisons, $ws, $ids): string {
            $liaisons[] = $m[1] === 'ws' ? $ws : '{' . implode(',', $ids) . '}';

            return $m[1] === 'ws' ? '?::uuid' : '?::uuid[]';
        }, self::SQL);

        return [(string) $sql, $liaisons];
    }

    /** @return array<string, int|bool|null|array> */
    private static function zeros(): array
    {
        return self::forme(array_fill_keys(self::CLES, 0), null);
    }

    private static function exposer(object $l): array
    {
        $preparation = $l->prep_etat === null ? null : ['etat' => $l->prep_etat, 'motif' => $l->prep_motif, 'inscrits' => (int) $l->prep_inscrits, 'membres' => (int) $l->prep_membres];
        $n = [];
        foreach (self::CLES as $k) {
            $n[$k] = (int) ($l->{$k} ?? 0);
        }

        return self::forme($n, $preparation, max((int) $l->prep_membres - (int) $l->prep_retenus, 0));
    }

    /** @param array<string, int> $n */
    private static function forme(array $n, ?array $preparation, int $exclus = 0): array
    {
        return [
            'inscrits' => $n['inscrits'], 'actives' => $n['actives'], 'envoyes' => $n['envoyes'], 'touches' => $n['touches'], 'refuses' => $n['refuses'],
            'ouverts' => null, 'suivi_ouvertures' => false,
            'reponses' => $n['reponses'], 'rebonds_durs' => $n['rebonds_durs'], 'rebonds_mous' => $n['rebonds_mous'], 'oppositions' => $n['oppositions'],
            'arretes_entreprise' => $n['arretes_entreprise'], 'arretes_campagne' => $n['arretes_campagne'],
            'exclus_preparation' => $exclus, 'preparation' => $preparation,
            'issues' => ['opposition' => $n['i_opposition'], 'rebond_dur' => $n['i_rebond_dur'], 'reponse' => $n['i_reponse'], 'rebond_mou' => $n['i_rebond_mou'],
                'arretee_autre' => $n['i_arretee_autre'], 'terminee' => $n['i_terminee'], 'active' => $n['i_active']],
            'taux_refus' => round($n['refuses'] / max($n['envoyes'] + $n['refuses'], 1), 4),
            'taux_desinscription' => round($n['oppositions'] / max($n['touches'], 1), 4),
        ];
    }
}
