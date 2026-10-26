<?php

namespace App\Console\Commands;

use App\Crm\EspaceProspection;
use App\Crm\Pharmacies\TelephonesFiness;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * TÉLÉPHONES DES OFFICINES FINESS → companies.phone VIDE (dépôt public : totaux seulement).
 *
 *   php artisan crm:pharmacies:telephones-finess etablissements.csv           # essai à blanc
 *   php artisan crm:pharmacies:telephones-finess etablissements.csv --apply   # ajoute les numéros manquants
 *
 * Règle complète : `App\Crm\Pharmacies\TelephonesFiness`. Jamais d'écrasement.
 */
class CrmPharmaciesTelephonesFiness extends Command
{
    protected $signature = 'crm:pharmacies:telephones-finess
                            {fichier : etablissements.csv FINESS brut (séparateur point-virgule)}
                            {--apply : Ajoute les téléphones (sans cette option : essai à blanc, rien n est écrit)}
                            {--workspace= : Identifiant ou slug de l espace (défaut : l espace de prospection)}
                            {--paquet=500 : Lignes par paquet (1 à 2000)}';

    protected $description = 'Ajoute le téléphone FINESS des officines (620, état A) dans companies.phone UNIQUEMENT s il est vide, hors opposition, numéros invalides et personnes physiques.';

    public function handle(): int
    {
        $fichier = (string) $this->argument('fichier');
        if (! is_file($fichier) || ! is_readable($fichier)) {
            $this->error('Fichier illisible.');

            return self::FAILURE;
        }
        $paquet = (int) $this->option('paquet');
        if ($paquet < 1 || $paquet > 2000) {
            $this->error('--paquet doit être compris entre 1 et 2000.');

            return self::FAILURE;
        }
        $designation = $this->option('workspace');
        $workspaceId = EspaceProspection::resoudre(is_string($designation) ? $designation : null);
        if ($workspaceId === null) {
            $this->error('Aucun espace cible (--workspace=UUID ou slug).');

            return self::FAILURE;
        }

        $appliquer = (bool) $this->option('apply');
        $this->warn($appliquer ? 'APPLICATION — téléphones ajoutés sur champ vide seulement.' : 'ESSAI À BLANC — rien n\'est écrit.');

        try {
            $b = (new TelephonesFiness)->executer($workspaceId, $fichier, $appliquer, $paquet);
        } catch (Throwable $e) {
            if ($e instanceof InvalidArgumentException) {
                $this->error('Échec : ' . $e->getMessage());

                return self::FAILURE;
            }
            $this->error('Échec : ' . $e::class);

            return self::FAILURE;
        }

        $this->info('Bilan' . ($appliquer ? '' : ' (ESSAI À BLANC)'));
        $this->line(sprintf('  lignes lues : %d (officines actives 620 : %d, sans téléphone : %d)', $b['lignes'], $b['officines'], $b['sans_telephone']));
        $this->line(sprintf('  fiches trouvées : %d (introuvables : %d)', $b['trouvees'], $b['introuvables']));
        $this->line(sprintf('  personnes physiques écartées : %d', $b['personnes_physiques']));
        $this->line(sprintf('  déjà renseignées : %d', $b['deja_renseignees']));
        $this->line(sprintf('  numéros invalides : %d', $b['invalides']));
        $this->line(sprintf('  écartés pour opposition : %d', $b['opposition']));
        $this->line(sprintf('  téléphones %s : %d', $appliquer ? 'ajoutés' : 'à ajouter', $appliquer ? $b['ajoutees'] : $b['a_ajouter']));
        $this->line(sprintf('  paquets en échec : %d', $b['lots_en_echec']));

        return $b['lots_en_echec'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
