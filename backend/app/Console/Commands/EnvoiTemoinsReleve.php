<?php

namespace App\Console\Commands;

use App\Envoi\Temoins\HotesTemoins;
use App\Envoi\Temoins\ReleveTemoins;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * R16 : relève automatique des dossiers d'indésirables des témoins. Inerte (code 0) tant que `ENVOI_REEL_ACTIF` ET
 * `ENVOI_TEMOINS_RELEVE_ACTIF` ne sont pas vrais : AVANT toute résolution du lecteur, donc aucune connexion. Lecture seule
 * des boîtes ; n'écrit que des ajouts (journée `releve_auto`, pause). Jamais d'adresse ni de mot de passe en sortie.
 */
class EnvoiTemoinsReleve extends Command
{
    public const SIGNATURE_PLANIFIEE = 'envoi:temoins-releve';

    protected $signature = self::SIGNATURE_PLANIFIEE;

    protected $description = 'Envoi : relève des indésirables des témoins (frein automatique ; lecture seule, ajouts seulement)';

    public function handle(): int
    {
        if (config('envoi.reel_actif') !== true || config('envoi.temoins_releve_actif') !== true) {
            $this->info('Relève des témoins inerte (ENVOI_REEL_ACTIF et ENVOI_TEMOINS_RELEVE_ACTIF doivent tous deux être vrais).');

            return self::SUCCESS;
        }
        $releve = app(ReleveTemoins::class);
        $pannes = 0;
        $limites = [];
        foreach (DB::table('workspaces')->whereNull('deleted_at')->pluck('id') as $ws) {
            $bilan = $releve->relever((string) $ws, now()->toDateTimeImmutable());
            $pannes += $bilan['pannes'];
            $this->info(sprintf('Témoins lus : %d ; messages en indésirables reconnus : %d ; pannes : %d.', $bilan['temoins'], $bilan['indesirables'], $bilan['pannes']));
            foreach ($bilan['fournisseurs'] as $f) {
                $pause = HotesTemoins::pourPause($f);
                if ($pause === 'autre') {
                    $limites[$f] = true;
                }
            }
        }
        foreach (array_keys($limites) as $f) {
            $this->warn(sprintf('Limite : %s est rattaché à « autre » pour la pause (pas de pause dédiée).', $f));
        }

        return $pannes > 0 ? self::FAILURE : self::SUCCESS;
    }
}
