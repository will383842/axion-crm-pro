<?php

namespace App\Console\Commands;

use App\Envoi\ListesNoires\SourceRessources;
use App\Envoi\ListesNoires\StatutListe;
use App\Envoi\ListesNoires\SurveillanceListes;
use Illuminate\Console\Command;

class EnvoiListesNoiresControler extends Command
{
    public const SIGNATURE_PLANIFIEE = 'envoi:listes-noires:controler';

    protected $signature = self::SIGNATURE_PLANIFIEE;

    protected $description = 'Contrôle les listes noires (IP, domaines d\'envoi) via le résolveur DNS local ; pause + alerte si listé.';

    public function handle(SurveillanceListes $s, SourceRessources $source): int
    {
        $bilan = $s->controler($source);
        $n = fn (StatutListe $x): int => count(array_filter($bilan, static fn (StatutListe $v): bool => $v === $x));
        $this->info(sprintf('listés=%d non_fiables=%d non_listés=%d', $n(StatutListe::Liste), $n(StatutListe::NonFiable), $n(StatutListe::NonListe)));

        return self::SUCCESS;
    }
}
