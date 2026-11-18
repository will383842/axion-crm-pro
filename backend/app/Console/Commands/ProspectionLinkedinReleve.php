<?php

namespace App\Console\Commands;

use App\ProspectionLinkedIn\Alertes;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * PONT-LINKEDIN LI-E — veille quotidienne de la voie S (rappels, arrêt si acceptation basse, relevé du lundi).
 * Drapeau `PROSPECTION_LINKEDIN_ACTIVE` éteint : la commande sort sans lire aucune table. N'envoie rien à une personne.
 */
class ProspectionLinkedinReleve extends Command
{
    protected $signature = 'prospection-linkedin:releve';

    protected $description = 'Voie S : rappels de relance, arrêt si acceptation basse, relevé hebdomadaire (alertes au fondateur seul).';

    public function handle(Alertes $alertes): int
    {
        if (config('prospection_linkedin.active') !== true) {
            $this->info('Prospection LinkedIn éteinte : rien à faire.');

            return self::SUCCESS;
        }
        $r = $alertes->executer(CarbonImmutable::now((string) config('app.timezone')));
        $this->info("Arrêts : {$r['arrets']} · rappels : {$r['rappels']} · relevés : {$r['releves']} · espaces en erreur : {$r['erreurs']}");

        return $r['erreurs'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
