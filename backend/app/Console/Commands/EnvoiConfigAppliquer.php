<?php

namespace App\Console\Commands;

use App\Envoi\Configuration\AlerteDeriveLog;
use App\Envoi\Configuration\Applicateur;
use App\Envoi\Configuration\CibleRepertoire;
use App\Envoi\Configuration\DepotVersionsDb;
use App\Envoi\Configuration\Paquet;
use App\Envoi\Configuration\SourceConfigurationDb;
use App\Envoi\Configuration\StatutVersion;
use Illuminate\Console\Command;

/** Commande LOCALE (jamais d'API) : génère la configuration depuis la base, la valide, l'applique, rétablit si échec. */
class EnvoiConfigAppliquer extends Command
{
    protected $signature = 'envoi:config-appliquer {workspace} {repertoire : dossier de config du serveur} {parametres : fichier JSON local (hors dépôt)}';

    protected $description = 'Applique la configuration du serveur d\'envoi (K18), avec retour arrière automatique';

    public function handle(): int
    {
        $parametres = json_decode((string) @file_get_contents((string) $this->argument('parametres')), true);
        if (! is_array($parametres) || ! is_dir((string) $this->argument('repertoire'))) {
            $this->error('Paramètres ou répertoire illisibles.');

            return self::FAILURE;
        }
        $ws = (string) $this->argument('workspace');
        $paquet = Paquet::depuis(new SourceConfigurationDb($ws, $parametres));
        $statut = (new Applicateur(new CibleRepertoire((string) $this->argument('repertoire')), new DepotVersionsDb, new AlerteDeriveLog))
            ->appliquer($ws, $paquet->contenu);
        $this->line($statut->value . ' ' . $paquet->empreinte());

        return $statut === StatutVersion::Appliquee ? self::SUCCESS : self::FAILURE;
    }
}
