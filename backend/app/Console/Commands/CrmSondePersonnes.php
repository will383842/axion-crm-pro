<?php

namespace App\Console\Commands;

use App\Crm\Ingest\PersonnesIngestService;
use App\Crm\Taxonomy;
use App\Services\Alertes\AlerteTelegram;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SENTINELLE du flux « personnes » (lot L4-C).
 *
 * Le défaut qu'elle guette est celui qui a déjà coûté cher ailleurs : un flux
 * ouvert qui reçoit des événements et n'écrit RIEN de ce qu'on attend, en
 * restant vert. Ici : des inscriptions ou des demandes du guide arrivent, mais
 * aucune n'est rattachée à une personne (drapeau mal relu après un
 * déploiement, contexte d'espace perdu, branche court-circuitée…).
 *
 * Fenêtre : les dernières 24 h. Drapeau FERMÉ, elle ne crie pas — le chemin
 * historique est alors le comportement attendu — mais elle le DIT.
 *
 *   FAILURE + alerte  drapeau ouvert, événements reçus > 0, rattachés à une
 *                     personne = 0
 *   SUCCESS           sinon, en disant sur quoi elle a compté
 *
 * Elle n'imprime que des comptes.
 */
class CrmSondePersonnes extends Command
{
    public const SIGNATURE_PLANIFIEE = 'crm:sonde-personnes';

    public const PREFIXE_ALERTE = '[L4-C] le flux personnes recoit sans rien ecrire';

    protected $signature = self::SIGNATURE_PLANIFIEE;

    protected $description = 'Verifie que les evenements lettre/guide des dernieres 24 h ont bien produit des personnes.';

    public function handle(): int
    {
        $slug = (string) config('crm.ingest.business_workspace', 'axion-ia');
        $workspaceId = DB::table('workspaces')->where('slug', $slug)->whereNull('deleted_at')->value('id');
        if ($workspaceId === null) {
            $this->info("Espace business « {$slug} » absent : rien a mesurer.");

            return self::SUCCESS;
        }
        $workspaceId = (string) $workspaceId;
        $depuis = now()->subDay();

        // Les rebonds ne créent jamais de personne (décision D1) : ils ne
        // comptent ni au numérateur ni au dénominateur.
        $types = array_values(array_diff(Taxonomy::PERSONNES_EVENT_TYPES, ['email_hard_bounced']));

        [$recus, $rattaches, $creees] = WorkspaceContext::run($workspaceId, static fn (): array => [
            (int) DB::table('activities')
                ->where('workspace_id', $workspaceId)
                ->whereIn('kind', $types)
                ->where('created_at', '>=', $depuis)
                ->count(),
            (int) DB::table('activities')
                ->where('workspace_id', $workspaceId)
                ->whereIn('kind', $types)
                ->where('subject_type', 'personne')
                ->where('created_at', '>=', $depuis)
                ->count(),
            (int) DB::table('personnes')
                ->where('workspace_id', $workspaceId)
                ->where('created_at', '>=', $depuis)
                ->count(),
        ]);

        $ouvert = PersonnesIngestService::drapeauOuvert();

        if (! $ouvert || $recus === 0 || $rattaches > 0) {
            $this->info(sprintf(
                'Flux personnes %s · 24 h : %d evenement(s) lettre/guide recu(s), %d rattache(s) a une personne, %d personne(s) creee(s).',
                $ouvert ? 'OUVERT' : 'FERME (chemin historique attendu)',
                $recus,
                $rattaches,
                $creees,
            ));

            return self::SUCCESS;
        }

        $message = self::PREFIXE_ALERTE . ' : ' . $recus . ' evenement(s) lettre/guide recu(s) en 24 h, '
            . 'AUCUN rattache a une personne (' . $creees . ' personne(s) creee(s)), alors que '
            . 'CRM_INGEST_PERSONNES_ENABLED est ouvert. GESTE : verifier que le drapeau est bien lu '
            . 'par le conteneur api (un restart ne relit pas l environnement : il faut un deploiement), '
            . 'puis compter les activites en attente de rapprochement. En cas de doute, refermer le '
            . 'drapeau (par un deploiement) : le site garde ses lignes en attente.';

        Log::critical($message, ['recus' => $recus, 'rattaches' => $rattaches, 'creees' => $creees]);
        $this->error($message);

        app(AlerteTelegram::class)->envoyer(
            '🔴 CRM — flux lettre et guide muet',
            $message,
            ['constat' => 'L4-C', 'recus' => $recus, 'rattaches' => $rattaches, 'creees' => $creees],
        );

        return self::FAILURE;
    }
}
