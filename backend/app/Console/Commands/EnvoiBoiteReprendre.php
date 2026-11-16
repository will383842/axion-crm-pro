<?php

namespace App\Console\Commands;

use App\Envoi\Smtp\DepotBoites;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Reprend une boîte suspendue à la main. Même règle que l'écran : une pause automatique ou de frein n'est PAS levée.
 */
class EnvoiBoiteReprendre extends Command
{
    protected $signature = 'envoi:boite-reprendre {workspace : identifiant du workspace} {boite : identifiant de la boîte}';

    protected $description = 'Lève la suspension manuelle d\'une boîte (une pause automatique ou de frein n\'est pas levée)';

    public function handle(DepotBoites $boites): int
    {
        $workspace = (string) $this->argument('workspace');
        $boite = (string) $this->argument('boite');
        if (! Str::isUuid($workspace) || ! Str::isUuid($boite)) {
            $this->error('Refusé : identifiant de workspace ou de boîte invalide.');

            return self::FAILURE;
        }
        $r = $boites->reactiverSuspensionManuelle($workspace, $boite);
        if ($r === 'absente') {
            $this->error('Boîte inconnue de cet espace.');

            return self::FAILURE;
        }
        if ($r === 'pause_automatique') {
            $this->error('Cette boîte est sous pause automatique ou de frein : elle ne peut pas être reprise à la main.');

            return self::FAILURE;
        }
        $this->info($r === 'reactivee' ? 'Boîte reprise.' : 'Boîte déjà active.');

        return self::SUCCESS;
    }
}
