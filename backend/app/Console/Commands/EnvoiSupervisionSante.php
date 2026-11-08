<?php

namespace App\Console\Commands;

use App\Envoi\Sante\CouplageSante;
use App\Envoi\Sante\DepotSante;
use App\Envoi\Sante\SuperviseurSante;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * K15 : un passage de supervision pour chaque serveur d'envoi actif. N'envoie aucun message.
 *
 * À ne pas confondre avec `envoi:sante` (INF-025) : celle-ci appelle `sante()` du serveur d'envoi pour battre le cœur de
 * l'homme mort (si le planificateur s'arrête, le SERVEUR se met en pause à 15 min). `envoi:supervision-sante` lit les
 * mesures détaillées, compare aux seuils, alerte et pause CÔTÉ CRM. Elles se complètent ; aucune ne remplace l'autre.
 */
class EnvoiSupervisionSante extends Command
{
    protected $signature = 'envoi:supervision-sante';

    protected $description = 'Supervise les serveurs d’envoi (seuils, homme mort, DNS, listes noires) : alerte et pause';

    public function handle(DepotSante $depot, SuperviseurSante $superviseur): int
    {
        if (CouplageSante::supervisionManquante()) {
            // Envoi réel + K1 allumés SANS supervision : ADR K0 (homme mort) veut un serveur supervisé. Ni silence, ni succès.
            CouplageSante::signaler(true);
            $this->error(CouplageSante::MESSAGE);

            return self::FAILURE;
        }
        if (config('envoi.reel_actif') !== true || config('envoi.k1_actif') !== true || config('envoi.sante.actif') !== true) {
            $this->info('Supervision inerte (ENVOI_REEL_ACTIF, ENVOI_K1_ACTIF et ENVOI_SANTE_ACTIF doivent tous être vrais).');

            return self::SUCCESS;
        }
        $echecs = 0;
        foreach ($depot->serveursActifs() as [$ws, $source]) {
            try {
                // L'heure est prise JUSTE AVANT chaque sonde (5 s de connexion + 10 s de réponse par serveur) : sinon l'écart d'horloge du Nᵉ serveur est faussé.
                $maintenant = now()->toDateTimeImmutable();
                $this->line($ws . ' ' . $source . ' : ' . count($superviseur->superviser($ws, $source, $maintenant)) . ' constat(s) en cours.');
            } catch (\Throwable $e) {
                $echecs++;
                // Pas de report($e) : le message d'une exception réseau peut porter une URL ou une IP. Classe seulement.
                Log::error('[ENVOI] supervision impossible pour un serveur.', ['exception' => $e::class, 'source' => $source]);
                $this->error($ws . ' ' . $source . ' — supervision impossible : ' . $e::class);
            }
        }

        return $echecs > 0 ? self::FAILURE : self::SUCCESS;
    }
}
