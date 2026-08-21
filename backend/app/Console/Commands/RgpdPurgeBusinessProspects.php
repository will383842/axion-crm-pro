<?php

namespace App\Console\Commands;

use App\Console\Concerns\RefuseUneSuppressionMassive;
use App\Crm\Taxonomy;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * PURGE BUSINESS (lot L4, plan §2.8.3) — standard CNIL prospection : les
 * données d'un prospect qui n'a JAMAIS interagi se périment 3 ANS après le
 * dernier contact émanant de lui (ou, à défaut de toute interaction, après
 * leur collecte).
 *
 * Ce qui est purgé : les FICHES PERSONNES (`contacts`) froides — base légale
 * `legitimate_interest_b2b`, aucune activité entrante rattachée à leur
 * personne, plus vieilles que 3 ans. Les `companies` restent : une personne
 * morale n'est pas une donnée personnelle (ses signaux non plus) ; seuls ses
 * humains le sont.
 *
 * Un contact qui a interagi (person_key présent dans la timeline, ou base
 * légale devenue precontractual/consent via l'ingestion L2) N'EST PAS touché :
 * son horloge est contractuelle/relationnelle, pas celle de la prospection.
 *
 * GATE : `CRM_PURGE_ENABLED` — construit inerte.
 */
class RgpdPurgeBusinessProspects extends Command
{
    use RefuseUneSuppressionMassive;

    protected $signature = 'rgpd:purge-business-prospects {--dry-run : Compte sans supprimer} {--force : Passe outre le plafond de proportion}';

    protected $description = 'Purge les fiches personnes froides (intérêt légitime, sans interaction) au-delà de 3 ans (CNIL prospection)';

    public function handle(AuditHashChain $audit): int
    {
        if (! filter_var(config('crm.purges_enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->error('CRM_PURGE_ENABLED n\'est pas à true — purge construite mais inerte (activation à la bascule finale).');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $businessIds = DB::table('workspaces')
            ->where('slug', '!=', Taxonomy::VIVIER_WORKSPACE_SLUG)
            ->whereNull('deleted_at')
            ->pluck('id');

        $total = 0;
        foreach ($businessIds as $workspaceId) {
            $workspaceId = (string) $workspaceId;

            $count = WorkspaceContext::run($workspaceId, function () use ($workspaceId, $dryRun): int {
                $stale = DB::table('contacts')
                    ->where('workspace_id', $workspaceId)
                    // Seule la prospection froide se périme par cette horloge.
                    ->where(function ($q): void {
                        $q->where('legal_basis', 'legitimate_interest_b2b')
                            ->orWhereNull('legal_basis');
                    })
                    ->where('created_at', '<', now()->subYears(3))
                    // Jamais une personne qui a interagi : sa timeline en fait foi.
                    ->whereNotExists(function ($q) use ($workspaceId): void {
                        $q->selectRaw('1')
                            ->from('activities')
                            ->whereColumn('activities.person_key', 'contacts.person_key')
                            ->where('activities.workspace_id', $workspaceId)
                            ->whereNotNull('contacts.person_key');
                    });

                // ── PLAFOND DE PROPORTION (B15-008) ──────────────────────────────
                //
                // 🔑 POURQUOI ON BLOQUE, MEME QUAND L'EFFACEMENT EST UNE OBLIGATION.
                //
                // Refuser une purge de retention retarde une echeance ; laisser passer
                // une purge ERRONEE detruit des donnees qui ne reviennent pas. Un jour
                // de retard se rattrape en relancant la commande a la main ; un vivier
                // efface par une condition trop large ne se rattrape pas.
                // **L'irreversible l'emporte.** Et le refus n'est pas silencieux : il
                // nomme la proportion, la table, et le geste qui debloque.
                //
                // B15-004 est la preuve que ce risque n'est pas theorique :
                // `prospection:purge-non-commercial` supprimait sur
                // `legal_form IS NULL OR ...` — c'est-a-dire presque tout — sans
                // qu'aucune barriere ni aucun test ne le dise.
                //
                // `contacts` porte le stock commercial : 4,29 M de fiches au
                // 2026-08-19. Une purge qui en viserait le tiers n'est pas une
                // echeance de retention, c'est un defaut de condition.
                if ($dryRun) {
                    return $stale->count();
                }

                $vises = (clone $stale)->count();
                // `contacts` porte `deleted_at` : une fiche deja en corbeille
                // fausserait le rapport dans les deux sens.
                $totalEspace = DB::table('contacts')
                    ->whereNull('deleted_at')
                    ->where('workspace_id', $workspaceId)
                    ->count();

                if (! $this->ecritureAutoriseeSansOperateur('contacts', $vises, $totalEspace, 'purger')) {
                    return 0;
                }

                return $stale->delete();
            });

            $this->line(($dryRun ? '[DRY-RUN] ' : '') . "workspace {$workspaceId} : {$count} fiches personnes purgées");
            $total += $count;

            if (! $dryRun && $count > 0) {
                $audit->record([
                    'workspace_id' => $workspaceId,
                    'user_id' => null,
                    'method' => 'GDPR_PURGE_BUSINESS',
                    'path' => 'artisan rgpd:purge-business-prospects',
                    'status' => 200,
                    'ip' => null,
                    'user_agent' => null,
                    'payload_hash' => hash('sha256', $workspaceId . '|' . $count),
                ]);
            }
        }

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . "Total : {$total}");

        return self::SUCCESS;
    }
}
