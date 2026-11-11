<?php

namespace App\Console\Commands;

use App\Envoi\Depot\DepotSocle;
use App\Envoi\PasserelleLocale;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Balaie TOUS les espaces : toute réservation d'injection restée sans issue depuis plus de 60 minutes passe en
 * `a_verifier` (elle compte toujours dans les plafonds) et déclenche une alerte critique. N'envoie rien, aucun réseau.
 * Aucune planification ici : à lancer à la main ou par l'ordonnanceur existant.
 */
class EnvoiPerimerReservations extends Command
{
    protected $signature = 'envoi:perimer-reservations';

    protected $description = 'Passe en « à vérifier » les réservations d’injection sans issue (tous les espaces ; aucun envoi, aucun réseau)';

    public function handle(DepotSocle $depot): int
    {
        $avant = now()->toDateTimeImmutable()->modify('-' . PasserelleLocale::DELAI_RESERVATION_MINUTES . ' minutes');
        $faites = $depot->perimerToutesLesReservations($avant);
        foreach ($faites as [$ws, $cle]) {
            Log::critical('envoi.injection_a_verifier : réservation restée sans issue, intervention requise.', ['workspace_id' => $ws, 'cle' => $cle]);
        }
        $this->info(count($faites) . ' réservation(s) passée(s) en « à vérifier ».');

        return self::SUCCESS;
    }
}
