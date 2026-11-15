<?php

namespace App\Console\Commands;

use App\Envoi\Depot\RetentionTentatives;
use Illuminate\Console\Command;

/** D16 — agrège puis retire le détail de `envoi_tentatives` de plus de 400 jours. Ne touche aucune donnée de personne. */
class EnvoiTentativesArchiver extends Command
{
    public const SIGNATURE_PLANIFIEE = 'envoi:tentatives-archiver';

    protected $signature = self::SIGNATURE_PLANIFIEE;

    protected $description = 'Agrège par jour puis retire les tentatives d\'envoi de plus de 400 jours (aucune donnée de personne)';

    public function handle(RetentionTentatives $retention): int
    {
        $b = $retention->archiver();
        $this->info("espaces={$b['espaces']} lignes_archivées={$b['lignes']} erreurs={$b['erreurs']}");

        return $b['erreurs'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
