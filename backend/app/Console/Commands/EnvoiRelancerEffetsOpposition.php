<?php

namespace App\Console\Commands;

use App\Envoi\Desinscription\TerminerEffetsOpposition;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Relance les effets (retrait de la file, arrêt par entreprise) des oppositions
 * dont `effets_termines_le` est NULL — sans qu'il faille un nouveau clic.
 * Idempotente, ne supprime rien. Une opposition qui échoue est retentée avec un délai
 * CROISSANT (2, 4, 8… min, plafonné à 60) ; après 10 échecs une alerte critique est émise
 * (une fois) mais on ne renonce jamais : l'opposition est un droit. Les oppositions d'un
 * espace supprimé sont journalisées (avertissement), jamais ignorées en silence.
 */
class EnvoiRelancerEffetsOpposition extends Command
{
    public const SIGNATURE = 'envoi:relancer-effets-opposition';

    protected $signature = self::SIGNATURE;

    protected $description = 'Relance les effets des oppositions de désinscription non terminés (rien n\'est supprimé)';

    private const ALERTE_APRES = 10;

    private const DELAI_MAX_MIN = 60;

    public function handle(TerminerEffetsOpposition $effets): int
    {
        $relancees = 0;
        foreach (DB::table('workspaces')->whereNotNull('deleted_at')->pluck('id') as $supprime) {
            $n = WorkspaceContext::run((string) $supprime, fn () => DB::table('envoi_oppositions')->where('workspace_id', $supprime)->whereNull('effets_termines_le')->count());
            if ($n > 0) {
                Log::warning("Opposition(s) à terminer dans un espace supprimé : {$n}.", ['workspace_id' => (string) $supprime]);
            }
        }
        foreach (DB::table('workspaces')->whereNull('deleted_at')->pluck('id') as $workspaceId) {
            $ids = WorkspaceContext::run((string) $workspaceId, fn () => DB::table('envoi_oppositions')
                ->where('workspace_id', $workspaceId)->whereNull('effets_termines_le')->orderBy('id')->limit(500)->pluck('id'));
            foreach ($ids as $id) {
                $cle = "envoi:relance-opposition:{$workspaceId}:{$id}";
                $etat = Cache::get($cle);
                if (is_array($etat) && $etat['pas_avant'] > Carbon::now()->getTimestamp()) {
                    continue; // délai croissant : pas encore l'heure
                }
                if ($effets->pour((string) $workspaceId, (int) $id)) {
                    $relancees++;
                    Cache::forget($cle);

                    continue;
                }
                $essais = (is_array($etat) ? $etat['essais'] : 0) + 1;
                $delai = min(2 ** $essais, self::DELAI_MAX_MIN);
                Cache::put($cle, ['essais' => $essais, 'pas_avant' => Carbon::now()->getTimestamp() + $delai * 60], 86400 * 7);
                if ($essais === self::ALERTE_APRES) {
                    Log::critical("Opposition {$id} : effets toujours non terminés après {$essais} relances.", ['workspace_id' => (string) $workspaceId]);
                }
            }
        }
        $this->info("Effets d'opposition terminés : {$relancees}.");

        return self::SUCCESS;
    }
}
