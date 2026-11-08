<?php

namespace App\Console\Commands;

use App\Envoi\Sante\DepotSante;
use App\Envoi\Sante\SuperviseurSante;
use Illuminate\Console\Command;

/** K15 : un passage de supervision pour chaque serveur d'envoi actif. N'envoie aucun message. */
class EnvoiSupervisionSante extends Command
{
    protected $signature = 'envoi:supervision-sante';

    protected $description = 'Supervise les serveurs d’envoi (seuils, homme mort, DNS, listes noires) : alerte et pause';

    public function handle(DepotSante $depot, SuperviseurSante $superviseur): int
    {
        if (config('envoi.reel_actif') !== true || config('envoi.k1_actif') !== true || config('envoi.sante.actif') !== true) {
            $this->info('Supervision inerte (ENVOI_REEL_ACTIF, ENVOI_K1_ACTIF et ENVOI_SANTE_ACTIF doivent tous être vrais).');

            return self::SUCCESS;
        }
        $maintenant = now()->toDateTimeImmutable();
        $echecs = 0;
        foreach ($depot->serveursActifs() as [$ws, $source]) {
            try {
                $this->line($ws . ' ' . $source . ' : ' . count($superviseur->superviser($ws, $source, $maintenant)) . ' constat(s) en cours.');
            } catch (\Throwable $e) {
                $echecs++;
                report($e);
                $this->error($ws . ' ' . $source . ' — supervision impossible : ' . $e::class);
            }
        }

        return $echecs > 0 ? self::FAILURE : self::SUCCESS;
    }
}
