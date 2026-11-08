<?php

namespace App\Console\Commands;

use App\Envoi\Desinscription\TerminerEffetsOpposition;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Relance les effets (retrait de la file, arrêt par entreprise) des oppositions
 * dont `effets_termines_le` est NULL — sans qu'il faille un nouveau clic.
 * Idempotente, ne supprime rien.
 */
class EnvoiRelancerEffetsOpposition extends Command
{
    public const SIGNATURE = 'envoi:relancer-effets-opposition';

    protected $signature = self::SIGNATURE;

    protected $description = 'Relance les effets des oppositions de désinscription non terminés (rien n\'est supprimé)';

    public function handle(TerminerEffetsOpposition $effets): int
    {
        $relancees = 0;
        foreach (DB::table('workspaces')->whereNull('deleted_at')->pluck('id') as $workspaceId) {
            $ids = WorkspaceContext::run((string) $workspaceId, fn () => DB::table('envoi_oppositions')
                ->whereNull('effets_termines_le')->orderBy('id')->limit(500)->pluck('id'));
            foreach ($ids as $id) {
                if ($effets->pour((string) $workspaceId, (int) $id)) {
                    $relancees++;
                }
            }
        }
        $this->info("Effets d'opposition terminés : {$relancees}.");

        return self::SUCCESS;
    }
}
