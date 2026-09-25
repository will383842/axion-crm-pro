<?php

namespace App\Console\Commands;

use App\Console\Concerns\RefuseUneSuppressionMassive;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * PURGE DES PERSONNES INACTIVES (lot L4-C) — 3 ans, doctrine CNIL prospection.
 *
 * Visées : les personnes NON RATTACHÉES à une entreprise, SANS abonnement
 * actif à la lettre, sans interaction depuis 3 ans. Une personne rattachée
 * relève de la purge des prospects business (`rgpd:purge-business-prospects`) ;
 * un abonné confirmé reste tant que dure son consentement — c'est le site qui
 * en tient la preuve, et un désabonnement le fera sortir de cette exception.
 *
 * Comme la purge du vivier : une ÉCHÉANCE, pas une opposition. Rien n'est
 * inscrit en liste d'opposition ; la personne peut revenir demain. Sa timeline
 * part avec elle (les charges portent des données personnelles).
 *
 * GATE : `CRM_PURGE_ENABLED` (config `crm.purges_enabled`), le même que les
 * deux autres purges RGPD. Plafond de proportion (B15-008) hérité du trait.
 */
class RgpdPurgePersonnes extends Command
{
    use RefuseUneSuppressionMassive;

    protected $signature = 'rgpd:purge-personnes {--dry-run : Compte sans supprimer} {--force : Passe outre le plafond de proportion}';

    protected $description = 'Purge les personnes (lettre et guide) non rattachées et inactives depuis 3 ans';

    public function handle(AuditHashChain $audit): int
    {
        if (! filter_var(config('crm.purges_enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->error('CRM_PURGE_ENABLED n\'est pas à true — purge construite mais inerte.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $slug = (string) config('crm.ingest.business_workspace', 'axion-ia');
        $workspaceId = DB::table('workspaces')->where('slug', $slug)->whereNull('deleted_at')->value('id');
        if ($workspaceId === null) {
            $this->error("Espace business introuvable : « {$slug} ».");

            return self::FAILURE;
        }
        $workspaceId = (string) $workspaceId;

        $bilan = WorkspaceContext::run($workspaceId, function () use ($workspaceId, $dryRun): array {
            $expirees = DB::table('personnes')
                ->where('workspace_id', $workspaceId)
                ->whereNull('contact_id')
                ->whereRaw('COALESCE(derniere_interaction_at, premiere_source_at) < ?', [now()->subYears(3)])
                ->whereNotExists(function ($q): void {
                    $q->select(DB::raw('1'))
                        ->from('abonnements')
                        ->whereColumn('abonnements.personne_id', 'personnes.id')
                        ->where('abonnements.statut', 'abonne');
                })
                // Une clé partagée avec une fiche contact : la timeline est
                // AUSSI celle du contact. Elle relève de la purge business, pas
                // de celle-ci — on n'y touche pas.
                ->whereNotExists(function ($q): void {
                    $q->select(DB::raw('1'))
                        ->from('contacts')
                        ->whereColumn('contacts.workspace_id', 'personnes.workspace_id')
                        ->whereColumn('contacts.person_key', 'personnes.person_key');
                });

            $visees = (clone $expirees)->count();

            if ($dryRun) {
                return ['personnes' => $visees, 'activities' => 0];
            }

            $total = DB::table('personnes')->where('workspace_id', $workspaceId)->count();
            if (! $this->ecritureAutoriseeSansOperateur('personnes', $visees, $total, 'purger')) {
                return ['personnes' => 0, 'activities' => 0];
            }

            return DB::transaction(function () use ($workspaceId, $expirees): array {
                $cles = (clone $expirees)->pluck('person_key')->filter()->unique()->values()->all();
                $ids = (clone $expirees)->pluck('id')->all();

                // `abonnements` part en cascade avec la personne.
                $personnes = DB::table('personnes')->whereIn('id', $ids)->delete();

                $activities = $cles === [] ? 0 : DB::table('activities')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('person_key', $cles)
                    ->delete();

                return ['personnes' => $personnes, 'activities' => $activities];
            });
        });

        if (! $dryRun) {
            $audit->record([
                'workspace_id' => $workspaceId,
                'user_id' => null,
                'method' => 'GDPR_PURGE_PERSONNES',
                'path' => 'artisan rgpd:purge-personnes',
                'status' => 200,
                'ip' => null,
                'user_agent' => null,
                'payload_hash' => hash('sha256', json_encode($bilan, JSON_THROW_ON_ERROR)),
            ]);
        }

        $this->info(($dryRun ? '[À BLANC] ' : '') . sprintf(
            'Personnes purgées : %d (non rattachées, sans abonnement actif, inactives depuis 3 ans) · activités : %d',
            $bilan['personnes'],
            $bilan['activities'],
        ));

        return self::SUCCESS;
    }
}
