<?php

namespace App\Console\Commands;

use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Depot\DepotSocle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Lever une pause est une décision HUMAINE (la ligne reste, datée). */
class EnvoiLeverPause extends Command
{
    protected $signature = 'envoi:lever-pause {workspace} {pause : identifiant de la pause} {--auteur= : qui lève}';

    protected $description = 'Lève une pause d’envoi (journalisé, la ligne est conservée)';

    public function handle(DepotChauffe $depot, DepotSocle $socle): int
    {
        $ws = (string) $this->argument('workspace');
        $auteur = (string) ($this->option('auteur') ?: get_current_user());
        $pause = (int) $this->argument('pause');
        // La levée et son journal sont UNE seule transaction : pas de pause levée sans trace.
        try {
            $levee = DB::transaction(function () use ($depot, $socle, $ws, $pause, $auteur): bool {
                // Le journal d'abord : le trigger de `envoi_pauses` refuse toute levée sans cette trace (même transaction).
                $socle->journaliser($ws, 'pause_levee', $auteur, ['pause' => $pause]);
                if (! $depot->leverPause($ws, $pause, $auteur)) {
                    throw new \RuntimeException('aucune pause active'); // annule aussi la trace d'une levée qui n'a pas eu lieu
                }

                return true;
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== 'aucune pause active') {
                throw $e;
            }
            $levee = false;
        }
        if (! $levee) {
            $this->error('Aucune pause active avec cet identifiant.');

            return self::FAILURE;
        }
        $this->info('Pause levée (journalisé).');

        return self::SUCCESS;
    }
}
