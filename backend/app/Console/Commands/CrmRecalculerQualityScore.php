<?php

namespace App\Console\Commands;

use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 🔴 C21-004 (S1) — REPRISE DU STOCK DE `companies.quality_score`.
 *
 * Mesure du 2026-08-19 en production : **3 546 986 fiches sur 4 295 349
 * (82,58 %)** portent un `quality_score` que
 * `public.recompute_company_quality_score()` ne rend pas — 3 484 663
 * SOUS-évaluées, 62 323 sur-évaluées. La cause est corrigée par la migration
 * `2026_08_20_000001_quality_score_declencheur_complet` (le déclencheur
 * n'écoutait pas l'`INSERT`, et sa liste de colonnes n'avait pas suivi
 * l'élargissement du barème de juillet). Cette commande reprend le stock qui a
 * été écrit AVANT ce correctif.
 *
 * ── POURQUOI PAS DANS LA MIGRATION, NI EN UN SEUL `UPDATE` ──────────────────
 *
 * `UPDATE companies SET quality_score = …` sur 4,3 M de lignes prend un verrou
 * de ligne sur chacune, fait enfler le WAL, et réécrit les **1 491 Mo d'index**
 * de la table (B10-014) en une seule transaction. Une coupure au milieu laisse
 * un travail à moitié fait qu'il faut recommencer en entier, et un déploiement
 * qui embarquerait cet `UPDATE` dans `migrate` bloquerait le démarrage du
 * conteneur. Ici :
 *
 *   · par LOTS bornés (`--taille-lot`, 5 000 par défaut), une transaction par
 *     lot ;
 *   · par PAGINATION SUR LA CLÉ (`id > curseur`), pas par `OFFSET` — un OFFSET
 *     re-parcourt tout ce qui précède à chaque lot ;
 *   · REJOUABLE : chaque lot ne réécrit QUE les lignes dont le score stocké
 *     diffère de celui du barème. Une exécution interrompue se reprend en la
 *     relançant ; une exécution complète relancée écrit **zéro** ligne ;
 *   · ARRÊTABLE : `--max-lots` borne le travail, et le reste est annoncé.
 *
 * ── L'ESSAI À BLANC ────────────────────────────────────────────────────────
 *
 * `--simulation` parcourt EXACTEMENT les mêmes fenêtres, avec la MÊME formule,
 * et rend le même décompte — sans un seul `UPDATE`. Ce n'est pas une
 * approximation : c'est la requête d'écriture privée de son écriture.
 *
 * ── POURQUOI UNE BOUCLE PAR WORKSPACE ──────────────────────────────────────
 *
 * `companies` porte `FORCE ROW LEVEL SECURITY` : sans `app.current_workspace_id`,
 * un rôle non-propriétaire voit ZÉRO ligne et la commande annoncerait « 0 fiche
 * corrigée » sur 3,5 M à reprendre. On pose donc le contexte, espace par espace.
 *
 * ⚠️ Le `SET quality_score = …` ne cite AUCUNE des neuf colonnes écoutées par
 * `companies_recompute_score` : le déclencheur ne se rallume pas sur cette
 * reprise. C'est voulu — la valeur écrite ici sort déjà du barème.
 */
class CrmRecalculerQualityScore extends Command
{
    protected $signature = 'crm:recalculer-quality-score
        {--taille-lot=5000 : nombre de fiches examinees par lot}
        {--max-lots=0 : borne le nombre total de lots (0 = jusqu au bout)}
        {--simulation : ne rien ecrire, compter seulement ce qui serait corrige}';

    protected $description = 'Realigne companies.quality_score sur le bareme (par lots, rejouable, essai a blanc possible).';

    public function handle(): int
    {
        // La reprise n'a aucun sens sans le barème : s'il manque, on refuse
        // bruyamment plutôt que d'annoncer « 0 fiche corrigée ».
        $bareme = DB::selectOne(
            "SELECT count(*) AS n FROM pg_proc WHERE proname = 'company_quality_score_calcul'",
        );

        if ((int) $bareme->n === 0) {
            $this->error(
                'REFUS : la fonction company_quality_score_calcul() est absente de la base. '
                . 'La migration 2026_08_20_000001_quality_score_declencheur_complet n\'a pas ete jouee. '
                . 'Sans elle, cette commande ne saurait pas quoi comparer et rendrait « 0 fiche corrigee » '
                . 'sur un stock qui diverge. Jouer `php artisan migrate`, puis relancer.',
            );

            return self::FAILURE;
        }

        $taille = max(1, (int) $this->option('taille-lot'));
        $maxLots = max(0, (int) $this->option('max-lots'));
        $simulation = (bool) $this->option('simulation');

        $espaces = DB::table('workspaces')->orderBy('id')->pluck('id');

        $vues = 0;
        $corrigees = 0;
        $sous = 0;
        $sur = 0;
        $lotsJoues = 0;

        foreach ($espaces as $espace) {
            $restant = $maxLots === 0 ? null : max(0, $maxLots - $lotsJoues);
            if ($restant === 0) {
                break;
            }

            $bilan = WorkspaceContext::run(
                (string) $espace,
                fn (): array => $this->traiterUnEspace((string) $espace, $taille, $restant, $simulation),
            );

            $vues += $bilan['vues'];
            $corrigees += $bilan['corrigees'];
            $sous += $bilan['sous'];
            $sur += $bilan['sur'];
            $lotsJoues += $bilan['lots'];
        }

        $verbe = $simulation ? 'a corriger' : 'corrigee(s)';
        $this->info(
            "Recalcul termine : {$vues} fiche(s) examinee(s), {$corrigees} {$verbe} "
            . "({$sous} sous-evaluee(s), {$sur} sur-evaluee(s)) en {$lotsJoues} lot(s), sur "
            . $espaces->count() . ' espace(s) de travail.',
        );

        if ($simulation) {
            $this->warn('ESSAI A BLANC : aucune ligne n\'a ete ecrite. Relancer sans --simulation pour appliquer.');
        }

        // Le RESTE, dit explicitement : une commande bornée par `--max-lots` ne
        // doit pas laisser croire que le stock est traité.
        //
        // ⚠️ Ce décompte parcourt `companies` EN ENTIER, avec un appel de
        // fonction par ligne (et cette fonction porte elle-même un `EXISTS` sur
        // `contacts`). C'est la requête la plus chère de la commande. En essai à
        // blanc son résultat n'est jamais affiché : on ne le paie donc pas.
        if (! $simulation) {
            $reste = $this->compterDivergentes(array_values($espaces->all()));
            if ($reste > 0) {
                $this->warn("Il reste {$reste} fiche(s) dont le score contredit le bareme. Relancer la commande "
                    . '(elle est rejouable et reprend la ou elle en etait).');
            }
        }

        return self::SUCCESS;
    }

    /**
     * Un lot = une fenêtre de `$taille` fiches ordonnées par `id`. On n'écrit
     * que les divergentes, mais le curseur avance de TOUTE la fenêtre : le coût
     * d'un lot est donc borné même quand le stock est déjà propre, et la boucle
     * se termine dans tous les cas.
     *
     * @return array{vues: int, corrigees: int, sous: int, sur: int, lots: int}
     */
    private function traiterUnEspace(string $espace, int $taille, ?int $lotsRestants, bool $simulation): array
    {
        $curseur = 0;
        $bilan = ['vues' => 0, 'corrigees' => 0, 'sous' => 0, 'sur' => 0, 'lots' => 0];

        while ($lotsRestants === null || $bilan['lots'] < $lotsRestants) {
            $ligne = $simulation
                ? $this->fenetreSeche($espace, $curseur, $taille)
                : $this->fenetreEcrite($espace, $curseur, $taille);

            $vues = $ligne['vues'];
            if ($vues === 0) {
                break;
            }

            $bilan['vues'] += $vues;
            $bilan['corrigees'] += $ligne['corrigees'];
            $bilan['sous'] += $ligne['sous'];
            $bilan['sur'] += $ligne['sur'];
            $bilan['lots']++;
            $curseur = $ligne['curseur'];

            $this->line("  espace {$espace} : lot {$bilan['lots']}, {$vues} vue(s), "
                . $ligne['corrigees'] . " a corriger, curseur id={$curseur}");

            if ($vues < $taille) {
                break;
            }
        }

        return $bilan;
    }

    /**
     * @return array{vues: int, curseur: int, corrigees: int, sous: int, sur: int}
     */
    private function fenetreEcrite(string $espace, int $curseur, int $taille): array
    {
        return $this->bilanDeFenetre(DB::selectOne(
            <<<SQL
            WITH candidat AS (
                SELECT c.id,
                       c.quality_score                   AS stocke,
                       company_quality_score_calcul(c)   AS attendu
                FROM   companies c
                WHERE  c.workspace_id = ?
                  AND  c.id > ?
                ORDER  BY c.id
                LIMIT  {$taille}
            ),
            maj AS (
                UPDATE companies c
                SET    quality_score = candidat.attendu
                FROM   candidat
                WHERE  c.id = candidat.id
                  AND  candidat.stocke IS DISTINCT FROM candidat.attendu
                RETURNING c.id
            )
            SELECT (SELECT count(*) FROM candidat)                                                  AS vues,
                   (SELECT coalesce(max(id), 0) FROM candidat)                                      AS curseur,
                   (SELECT count(*) FROM maj)                                                       AS corrigees,
                   (SELECT count(*) FROM candidat WHERE stocke < attendu)                           AS sous,
                   (SELECT count(*) FROM candidat WHERE stocke > attendu)                           AS sur
            SQL,
            [$espace, $curseur],
        ));
    }

    /**
     * @return array{vues: int, curseur: int, corrigees: int, sous: int, sur: int}
     */
    private function fenetreSeche(string $espace, int $curseur, int $taille): array
    {
        return $this->bilanDeFenetre(DB::selectOne(
            <<<SQL
            WITH candidat AS (
                SELECT c.id,
                       c.quality_score                   AS stocke,
                       company_quality_score_calcul(c)   AS attendu
                FROM   companies c
                WHERE  c.workspace_id = ?
                  AND  c.id > ?
                ORDER  BY c.id
                LIMIT  {$taille}
            )
            SELECT count(*)                                                        AS vues,
                   coalesce(max(id), 0)                                            AS curseur,
                   count(*) FILTER (WHERE stocke IS DISTINCT FROM attendu)         AS corrigees,
                   count(*) FILTER (WHERE stocke < attendu)                        AS sous,
                   count(*) FILTER (WHERE stocke > attendu)                        AS sur
            FROM   candidat
            SQL,
            [$espace, $curseur],
        ));
    }

    /**
     * Postgres rend des `bigint` (donc des chaînes en PHP) et un `stdClass`
     * anonyme : on le ramène à un tableau typé, une fois, ici.
     *
     * @return array{vues: int, curseur: int, corrigees: int, sous: int, sur: int}
     */
    private function bilanDeFenetre(?object $ligne): array
    {
        $valeurs = (array) $ligne;

        return [
            'vues' => (int) ($valeurs['vues'] ?? 0),
            'curseur' => (int) ($valeurs['curseur'] ?? 0),
            'corrigees' => (int) ($valeurs['corrigees'] ?? 0),
            'sous' => (int) ($valeurs['sous'] ?? 0),
            'sur' => (int) ($valeurs['sur'] ?? 0),
        ];
    }

    /** @param  list<mixed>  $espaces */
    private function compterDivergentes(array $espaces): int
    {
        $reste = 0;

        foreach ($espaces as $espace) {
            $reste += WorkspaceContext::run((string) $espace, static fn (): int => (int) DB::selectOne(
                <<<'SQL'
                SELECT count(*) AS n
                FROM   companies c
                WHERE  c.workspace_id = ?
                  AND  c.quality_score IS DISTINCT FROM company_quality_score_calcul(c)
                SQL,
                [$espace],
            )->n);
        }

        return $reste;
    }
}
