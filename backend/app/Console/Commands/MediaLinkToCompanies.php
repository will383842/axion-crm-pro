<?php

namespace App\Console\Commands;

use App\Crm\Doublons\FusionFiches;
use App\Crm\Doublons\Rapprochement;
use App\Crm\Doublons\RefusFusion;
use App\Crm\Presse\QualificationPresse;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rattachement SÛR des médias AUTONOMES (company_id IS NULL) à leur entreprise
 * éditrice, dans la base des ~4,3M companies.
 *
 * Beaucoup de médias (titres CPPAP, agences, blogs, portails) existent sans
 * company_id : ils ont été créés depuis un registre presse, pas depuis companies.
 * Or nombre d'entre eux SONT édités par une société présente dans la base. Les
 * rattacher permet ensuite à `media:sync-from-companies` de leur faire hériter
 * site/email/téléphone — sans jamais réinventer la donnée.
 *
 * ⚠️ ZÉRO faux lien : on ne rattache QUE sur correspondance CERTAINE.
 *   (a) SIREN exact — media.siren = companies.siren (même workspace). La contrainte
 *       UNIQUE(workspace_id, siren) garantit l'unicité → aucune ambiguïté.
 *   (b) Nom exact UNIQUE — normalize_name(publisher||name) = denomination_normalized,
 *       MAIS uniquement si EXACTEMENT UNE company porte ce nom normalisé dans le
 *       workspace (garde-fou `HAVING count(*) = 1`). 0 ou >1 match → on NE rattache PAS.
 *
 * On réutilise la MÊME normalisation que la colonne générée
 * `companies.denomination_normalized` : la fonction SQL IMMUTABLE `normalize_name()`
 * (cf. migration 2026_05_16_000001) appliquée directement en base → normalisation
 * strictement identique côté media et côté companies (pas de dérive PHP↔SQL).
 *
 * Ne touche QUE `company_id` (l'héritage des contacts est délégué à
 * `media:sync-from-companies`). Idempotent : ne retraite que company_id IS NULL.
 * Requêtes 100 % ENSEMBLISTES + indexées (pas de N+1 sur 4,3M lignes).
 *
 * `--dry-run` compte matchés SIREN / matchés nom-unique / ambigus / sans match.
 *
 * ── Les médias portés par une fiche PROVISOIRE `media:<id>` (2026-09-30) ──
 * L'harmonisation de la presse donne une fiche (`foreign_id` = `media:<id>`)
 * aux titres qui n'en avaient pas. Ils restent AUTONOMES au sens de cette
 * commande : dès que leur SIREN désigne la vraie fiche de leur éditeur, la
 * fiche provisoire est FUSIONNÉE dans la fiche SIREN par le mécanisme des
 * doublons (`FusionFiches`, motif `meme_siren`, journalisée et annulable par
 * `crm:doublons:fusionner --annuler=<n>`) : le média, ses journalistes, les
 * contacts et les étiquettes passent sur la fiche de l'éditeur, la fiche
 * provisoire va à la CORBEILLE avec un renvoi — jamais supprimée, jamais deux
 * fiches durables. Par SIREN exact seulement : une fiche ne se fusionne pas
 * sur un nom. Une fiche provisoire dont les titres désignent PLUSIEURS
 * éditeurs n'est pas touchée (comptée).
 */
class MediaLinkToCompanies extends Command
{
    protected $signature = 'media:link-to-companies {--dry-run : Compte les rattachements sans écrire en base}';

    protected $description = 'Rattache les médias autonomes à leur entreprise éditrice (SIREN exact ou nom exact UNIQUE).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            return $this->reportDryRun();
        }

        // (a) SIREN exact — match unique garanti par UNIQUE(workspace_id, siren).
        $bySiren = DB::affectingStatement(<<<'SQL'
            UPDATE media m
            SET company_id = c.id,
                updated_at = now()
            FROM companies c
            WHERE m.company_id IS NULL
              AND m.deleted_at IS NULL
              AND m.siren IS NOT NULL
              AND c.siren = m.siren
              AND c.workspace_id = m.workspace_id
              AND c.deleted_at IS NULL
        SQL);

        // (b) Nom exact UNIQUE — groupé HAVING count(*)=1 (anti-ambiguïté), ensembliste.
        //     Le prefiltre `IN (noms des médias encore autonomes)` borne l'agrégat aux
        //     seuls noms réellement candidats (media est petit vs 4,3M companies).
        $byName = DB::affectingStatement(<<<'SQL'
            UPDATE media m
            SET company_id = u.company_id,
                updated_at = now()
            FROM (
                SELECT c.workspace_id,
                       c.denomination_normalized AS norm,
                       min(c.id)                 AS company_id
                FROM companies c
                WHERE c.deleted_at IS NULL
                  AND c.denomination_normalized IS NOT NULL
                  AND c.denomination_normalized <> ''
                  AND c.denomination_normalized IN (
                        SELECT normalize_name(COALESCE(NULLIF(m2.publisher, ''), m2.name))
                        FROM media m2
                        WHERE m2.company_id IS NULL
                          AND m2.deleted_at IS NULL
                  )
                GROUP BY c.workspace_id, c.denomination_normalized
                HAVING count(*) = 1
            ) u
            WHERE m.company_id IS NULL
              AND m.deleted_at IS NULL
              AND m.workspace_id = u.workspace_id
              AND normalize_name(COALESCE(NULLIF(m.publisher, ''), m.name)) = u.norm
        SQL);

        [$fusionnees, $ambigues, $refusees] = $this->fusionnerFichesProvisoires(false);

        $this->info("✓ Rattachement média→entreprise : {$bySiren} par SIREN exact, {$byName} par nom exact unique, {$fusionnees} fiche(s) provisoire(s) fusionnée(s) dans la fiche de l'éditeur ({$ambigues} ambiguë(s), {$refusees} refus de fusion).");

        return self::SUCCESS;
    }

    /**
     * Les fiches PROVISOIRES `media:<id>` dont un titre porte le SIREN d'une
     * fiche d'éditeur vivante : fusionnées dans celle-ci (ou comptées, à blanc).
     *
     * @return array{0: int, 1: int, 2: int} fusionnées (ou à fusionner), ambiguës, refusées
     */
    private function fusionnerFichesProvisoires(bool $aBlanc): array
    {
        $paires = DB::select(
            "SELECT f.workspace_id, f.id AS provisoire, array_agg(DISTINCT c.id) AS editeurs
               FROM media m
               JOIN companies f ON f.id = m.company_id AND f.deleted_at IS NULL
                               AND f.foreign_id LIKE '" . QualificationPresse::PREFIXE_ANCRE_MEDIA . "%'
               JOIN companies c ON c.siren = m.siren AND c.workspace_id = m.workspace_id
                               AND c.deleted_at IS NULL AND c.id <> f.id
              WHERE m.deleted_at IS NULL AND m.siren IS NOT NULL
              GROUP BY f.workspace_id, f.id
              ORDER BY f.id",
        );

        $fusionnees = 0;
        $ambigues = 0;
        $refusees = 0;
        $fusion = app(FusionFiches::class);
        foreach ($paires as $p) {
            $editeurs = array_values(array_filter(array_map('intval', explode(',', trim((string) $p->editeurs, '{}')))));
            if (count($editeurs) !== 1) {
                $ambigues++;

                continue;
            }
            if ($aBlanc) {
                $fusionnees++;

                continue;
            }
            try {
                WorkspaceContext::run((string) $p->workspace_id, fn (): int => $fusion->fusionner(
                    (string) $p->workspace_id,
                    $editeurs[0],
                    (int) $p->provisoire,
                    Rapprochement::MEME_SIREN,
                    FusionFiches::MODE_MANUEL,
                    operateur: 'media:link-to-companies',
                ));
                $fusionnees++;
            } catch (RefusFusion $e) {
                $refusees++;
                $this->line("  fiche provisoire non fusionnée : {$e->getMessage()}");
            }
        }

        return [$fusionnees, $ambigues, $refusees];
    }

    /**
     * Compte, sans écrire, les 4 catégories : SIREN / nom-unique / ambigus / sans match.
     * Reproduit la logique séquentielle réelle (SIREN d'abord, nom sur le reliquat).
     */
    private function reportDryRun(): int
    {
        $this->warn('DRY-RUN : aucune écriture en base.');

        $total = (int) DB::table('media')
            ->whereNull('company_id')
            ->whereNull('deleted_at')
            ->count();

        // (a) médias qui matcheraient par SIREN exact (unique par contrainte).
        $bySiren = (int) DB::table('media as m')
            ->whereNull('m.company_id')
            ->whereNull('m.deleted_at')
            ->whereNotNull('m.siren')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('companies as c')
                    ->whereColumn('c.siren', 'm.siren')
                    ->whereColumn('c.workspace_id', 'm.workspace_id')
                    ->whereNull('c.deleted_at');
            })
            ->count();

        // (b) sur le RELIQUAT (non matché par SIREN), ventilation nom-unique / ambigu.
        //     `cand` = médias autonomes non-SIREN, avec leur nom normalisé.
        //     `grp`  = companies groupées par nom normalisé (count par workspace).
        $row = DB::selectOne(<<<'SQL'
            WITH cand AS (
                SELECT m.workspace_id,
                       normalize_name(COALESCE(NULLIF(m.publisher, ''), m.name)) AS norm
                FROM media m
                WHERE m.company_id IS NULL
                  AND m.deleted_at IS NULL
                  AND NOT (
                      m.siren IS NOT NULL AND EXISTS (
                          SELECT 1 FROM companies c
                          WHERE c.siren = m.siren
                            AND c.workspace_id = m.workspace_id
                            AND c.deleted_at IS NULL
                      )
                  )
            ),
            grp AS (
                SELECT c.workspace_id, c.denomination_normalized AS norm, count(*) AS n
                FROM companies c
                WHERE c.deleted_at IS NULL
                  AND c.denomination_normalized <> ''
                  AND c.denomination_normalized IN (SELECT norm FROM cand WHERE norm <> '')
                GROUP BY c.workspace_id, c.denomination_normalized
            )
            SELECT
                count(*) FILTER (WHERE g.n = 1)              AS unique_match,
                count(*) FILTER (WHERE g.n > 1)              AS ambiguous,
                count(*) FILTER (WHERE g.n IS NULL)          AS no_match
            FROM cand
            LEFT JOIN grp g ON g.workspace_id = cand.workspace_id AND g.norm = cand.norm
            WHERE cand.norm <> ''
        SQL);

        $uniqueMatch = (int) ($row->unique_match ?? 0);
        $ambiguous = (int) ($row->ambiguous ?? 0);
        $noMatch = (int) ($row->no_match ?? 0);

        $this->info("→ {$total} médias autonomes.");
        $this->line("   • SIREN exact         : {$bySiren}");
        $this->line("   • Nom exact UNIQUE     : {$uniqueMatch}  (seraient rattachés)");
        $this->line("   • Nom AMBIGU (>1)      : {$ambiguous}  (NON rattachés, garde-fou)");
        $this->line("   • Sans correspondance  : {$noMatch}");
        [$aFusionner, $ambigues] = $this->fusionnerFichesProvisoires(true);
        $this->line("   • Fiches provisoires media:<id> à fusionner dans l'éditeur (SIREN) : {$aFusionner} ({$ambigues} ambiguë(s), non touchées)");

        return self::SUCCESS;
    }
}
