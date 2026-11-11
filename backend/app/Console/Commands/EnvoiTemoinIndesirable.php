<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LitAdresseSansTrace;
use App\Envoi\Chauffe\GardeChauffe;
use App\Envoi\Exceptions\EnvoiRefuse;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Un message de test est arrivé en indésirables chez un témoin : pause vers CE fournisseur seulement.
 * L'adresse n'est JAMAIS un argument : invite masquée, ou une ligne sur l'entrée standard.
 */
class EnvoiTemoinIndesirable extends Command
{
    use LitAdresseSansTrace;

    protected $signature = 'envoi:temoin-indesirable {workspace : identifiant du workspace} {--auteur= : qui signale}';

    protected $description = 'Signale un message en indésirables chez un témoin (adresse lue en invite masquée ou sur STDIN) : pause vers le fournisseur du témoin (journalisé)';

    public function handle(GardeChauffe $garde): int
    {
        $workspace = (string) $this->argument('workspace');
        if (! Str::isUuid($workspace)) {
            $this->error('Workspace refusé : identifiant invalide.');

            return self::FAILURE;
        }
        $adresse = $this->lireAdresse('Adresse du témoin (saisie masquée)');
        if ($adresse === null) {
            $this->error('Adresse absente : fournissez-la par l\'invite masquée ou sur l\'entrée standard (une ligne).');

            return self::FAILURE;
        }

        try {
            $ouverte = $garde->indesirableChezTemoin($workspace, $adresse, null, (string) ($this->option('auteur') ?: 'freins'));
        } catch (EnvoiRefuse) {
            $this->error('Refus : configuration de l\'empreinte absente.');

            return self::FAILURE;
        }
        $this->warn($ouverte ? 'Pause ouverte vers le fournisseur du témoin (journalisé).' : 'Une pause identique est déjà active.');

        return self::SUCCESS;
    }
}
