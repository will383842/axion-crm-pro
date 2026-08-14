<?php

namespace App\Console\Commands;

use App\Crm\Taxonomy;
use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * BACKFILL des tags de provenance `src:scraping-*` sur le STOCK (lot L3,
 * décision 2 de l'audit scraping — GO actée, spec d'exécution OBLIGATOIRE).
 *
 * Le stock : 4,29 M de companies dont `discovery_source` connaît la source
 * mais qu'aucun tag ne rend filtrable. Ce backfill DOUBLE la table
 * `company_tag` (3,2 M lignes existantes + ~4,29 M) sur un CPX22 en ligne :
 * la spec interdit le INSERT ensembliste unique.
 *
 * Spec appliquée à la lettre :
 *  (a) tranches d'`id` de 50 000 companies, UNE transaction par tranche,
 *      pause paramétrable entre tranches ;
 *  (b) fenêtre creuse choisie par l'OPÉRATEUR (la commande ne se lance pas
 *      toute seule : drapeau + invocation manuelle) ;
 *  (c) à surveiller entre tranches côté opérateur : disque, RAM, latence ;
 *  (d) `ANALYZE company_tag` à la fin ;
 *  (e) idempotent par construction (`ON CONFLICT DO NOTHING`) → rejouable ;
 *  (f) rollback par lot documenté : DELETE des company_tag posés par
 *      `assigned_by='backfill-src'` (marqueur DÉDIÉ, distinct d'auto-rule,
 *      pour que le rollback ne touche jamais les tags posés par l'ingestion) ;
 *  (g) compte final consigné et comparé au compte attendu.
 *
 * GATE : `CRM_BACKFILL_ENABLED=true` obligatoire (ordre de mission : backfill
 * en DERNIER, après le déploiement final). Sans le drapeau, la commande
 * refuse — même en dry-run le funnel de décision reste le même.
 */
class ScrapingBackfillSrcTags extends Command
{
    protected $signature = 'scraping:backfill-src-tags
        {--chunk=50000 : Taille de tranche (id de companies)}
        {--sleep=3 : Pause en secondes entre tranches}
        {--dry-run : Compte ce qui serait inséré, n\'écrit rien}';

    protected $description = 'Pose les tags src:scraping-* sur le stock, par tranches, selon la spec de l\'audit (décision 2)';

    public function handle(): int
    {
        if (! filter_var(config('crm.backfill_enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->error('CRM_BACKFILL_ENABLED n\'est pas à true — le backfill est le DERNIER acte de la mission (ordre de mission §5).');

            return self::FAILURE;
        }

        $chunk = max(1000, (int) $this->option('chunk'));
        $sleep = max(0, (int) $this->option('sleep'));
        $dryRun = (bool) $this->option('dry-run');

        // discovery_source → slug du registre. Les valeurs historiques de
        // discovery_source SONT des slugs du registre (vérifié : insee,
        // annuaire-entreprises, mentions-legales…) ; toute valeur hors registre
        // est ignorée et comptée — jamais un tag hors gouvernance.
        $sources = array_keys(ScrapingSourcesSeeder::referential());

        $workspaces = DB::table('workspaces')
            ->where('slug', '!=', Taxonomy::VIVIER_WORKSPACE_SLUG)
            ->whereNull('deleted_at')
            ->pluck('id');

        foreach ($workspaces as $workspaceId) {
            $this->backfillWorkspace((string) $workspaceId, $sources, $chunk, $sleep, $dryRun);
        }

        if (! $dryRun) {
            $this->info('ANALYZE company_tag…');
            DB::unprepared('ANALYZE company_tag;');
        }

        $this->info('Rollback par lot si nécessaire : DELETE FROM company_tag WHERE assigned_by = \'backfill-src\' (par tranches).');

        return self::SUCCESS;
    }

    /** @param  list<string>  $sources */
    private function backfillWorkspace(string $workspaceId, array $sources, int $chunk, int $sleep, bool $dryRun): void
    {
        $this->info("Workspace {$workspaceId} :");

        // Le tag de chaque source doit préexister (GovernedTagsSeeder les
        // dérive du registre) — on les pose ici s'ils manquent, verrouillés.
        $tagIds = [];
        foreach ($sources as $slug) {
            $tagSlug = 'src:scraping-' . $slug;
            $id = DB::table('tags')->where('workspace_id', $workspaceId)->where('slug', $tagSlug)->value('id');
            if ($id === null && ! $dryRun) {
                DB::table('tags')->insertOrIgnore([
                    'workspace_id' => $workspaceId,
                    'slug' => $tagSlug,
                    'name' => 'Collecte — ' . $slug,
                    'category' => 'intent',
                    'kind' => 'auto',
                    'rules' => '{}',
                    'is_locked' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $id = DB::table('tags')->where('workspace_id', $workspaceId)->where('slug', $tagSlug)->value('id');
            }
            if ($id !== null) {
                $tagIds[$slug] = (int) $id;
            }
        }

        $bounds = DB::table('companies')
            ->where('workspace_id', $workspaceId)
            ->selectRaw('min(id) AS lo, max(id) AS hi')
            ->first();

        if ($bounds === null || $bounds->lo === null) {
            $this->line('  (aucune company)');

            return;
        }

        $expected = (int) DB::table('companies')
            ->where('workspace_id', $workspaceId)
            ->whereIn('discovery_source', $sources)
            ->count();
        $this->line("  attendu : ~{$expected} associations (companies avec discovery_source au registre)");

        $inserted = 0;
        for ($lo = (int) $bounds->lo; $lo <= (int) $bounds->hi; $lo += $chunk) {
            $hi = $lo + $chunk - 1;

            if ($dryRun) {
                $count = (int) DB::table('companies')
                    ->where('workspace_id', $workspaceId)
                    ->whereBetween('id', [$lo, $hi])
                    ->whereIn('discovery_source', $sources)
                    ->count();
                $inserted += $count;
            } else {
                $affected = 0;
                DB::transaction(function () use ($workspaceId, $lo, $hi, $tagIds, &$affected): void {
                    foreach ($tagIds as $slug => $tagId) {
                        $affected += DB::affectingStatement(
                            <<<'SQL'
                                INSERT INTO company_tag (company_id, tag_id, workspace_id, assigned_by, assigned_at)
                                SELECT c.id, ?, c.workspace_id, 'backfill-src', now()
                                FROM companies c
                                WHERE c.workspace_id = ?::uuid
                                  AND c.id BETWEEN ? AND ?
                                  AND c.discovery_source = ?
                                ON CONFLICT DO NOTHING
                            SQL,
                            [$tagId, $workspaceId, $lo, $hi, $slug],
                        );
                    }
                });
                $inserted += $affected;

                if ($sleep > 0 && $hi < (int) $bounds->hi) {
                    sleep($sleep);
                }
            }

            $this->line("  tranche {$lo}-{$hi} : cumul {$inserted}");
        }

        $this->info(($dryRun ? '  [DRY-RUN] ' : '  ') . "total : {$inserted} (attendu ~{$expected})");
    }
}
