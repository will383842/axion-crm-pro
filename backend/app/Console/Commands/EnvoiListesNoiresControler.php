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

    protected $description = 'Contrôle les listes noires (IP, domaines d\'envoi) via le résolveur DNS local ; pause + alerte si listé ; code 1 si non fiable.';

    public function handle(SurveillanceListes $s, SourceRessources $source): int
    {
        $bilan = $s->controler($source);
        $n = fn (StatutListe $x): int => count(array_filter($bilan, static fn (StatutListe $v): bool => $v === $x));
        $rejets = $source->rejets();
        $this->info(sprintf('listés=%d non_fiables=%d non_listés=%d rejetés=%d', $n(StatutListe::Liste), $n(StatutListe::NonFiable), $n(StatutListe::NonListe), count($rejets)));

        // Un contrôle « non fiable » ou une entrée de configuration rejetée n'est PAS un vert :
        // le code de sortie le dit (le planificateur le relaie via onFailure).
        if ($n(StatutListe::NonFiable) > 0 || $rejets !== []) {
            $this->error('Contrôle des listes noires non fiable ou incomplet : voir le journal.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
