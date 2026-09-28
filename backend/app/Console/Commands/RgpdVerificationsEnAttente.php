<?php

namespace App\Console\Commands;

use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LES EFFACEMENTS DONT LA PREUVE N'A PAS CONCLU (relecture R7, 2026-09-29).
 *
 * La preuve d'un effacement est différée (`VerifierEffacementRgpd`). Si la
 * file ne tourne pas, ou si la preuve échoue, la demande reste « En
 * traitement » : visible dans la console, mais seulement pour qui la regarde.
 * Cette commande, planifiée chaque matin, SIGNALE (journal `error`, sortie en
 * échec) toute demande d'effacement dont la vérification est `en_attente` ou
 * `echec` depuis plus de 24 h. Elle ne cite que des identifiants de demande,
 * jamais une adresse.
 *
 * Espace par espace, dans le contexte de chacun : `rgpd_requests` porte une
 * RLS forcée.
 */
class RgpdVerificationsEnAttente extends Command
{
    public const SIGNATURE_PLANIFIEE = 'rgpd:verifications-en-attente';

    protected $signature = 'rgpd:verifications-en-attente {--heures=24 : Âge au-delà duquel une vérification est signalée}';

    protected $description = 'Signale les effacements RGPD dont la vérification différée est en attente ou en échec depuis plus de 24 h';

    public function handle(): int
    {
        $heures = max(1, (int) $this->option('heures'));
        $limite = now()->subHours($heures);

        $enRetard = [];
        foreach (DB::table('workspaces')->pluck('id')->all() as $espace) {
            $ids = WorkspaceContext::run((string) $espace, static fn (): array => DB::table('rgpd_requests')
                ->where('workspace_id', (string) $espace)
                ->where('type', 'erasure')
                ->where('status', 'processing')
                ->whereRaw("metadata->>'verification' IN ('en_attente', 'echec')")
                ->where('updated_at', '<', $limite)
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all());
            $enRetard = array_merge($enRetard, $ids);
        }

        if ($enRetard === []) {
            $this->info("Aucune verification d'effacement en attente depuis plus de {$heures} h.");

            return self::SUCCESS;
        }

        Log::error('GDPR erasure verification en attente depuis plus de ' . $heures . ' h', ['demandes' => $enRetard]);
        $this->error(count($enRetard) . " effacement(s) sans verification depuis plus de {$heures} h : demandes #" . implode(', #', $enRetard) . '.');

        return self::FAILURE;
    }
}
