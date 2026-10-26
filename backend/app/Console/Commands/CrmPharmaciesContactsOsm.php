<?php

namespace App\Console\Commands;

use App\Crm\EspaceProspection;
use App\Crm\Pharmacies\ContactsOsm;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * E-MAILS, SITES ET TÉLÉPHONES DES OFFICINES DEPUIS OPENSTREETMAP (dépôt public : totaux seulement).
 *
 *   php artisan crm:pharmacies:contacts-osm --osm=osm.csv            # essai à blanc
 *   php artisan crm:pharmacies:contacts-osm --osm=osm.csv --apply    # ajoute sur champs vides
 *
 * Règle complète : `App\Crm\Pharmacies\ContactsOsm`. Jamais d'écrasement, jamais d'adresse devinée.
 */
class CrmPharmaciesContactsOsm extends Command
{
    protected $signature = 'crm:pharmacies:contacts-osm
                            {--osm= : CSV lat,lon,cp,telephone,email,site extrait d OpenStreetMap}
                            {--apply : Écrit les ajouts (sans cette option : essai à blanc, rien n est écrit)}
                            {--workspace= : Identifiant ou slug de l espace (défaut : l espace de prospection)}
                            {--paquet=500 : Fiches par paquet (1 à 2000)}';

    protected $description = 'Ajoute e-mail, site et téléphone OpenStreetMap des pharmacies UNIQUEMENT sur champ vide (rapprochement par téléphone ou GPS 80 m + code postal), hors opposition et fiches protégées.';

    public function handle(): int
    {
        $osm = $this->option('osm');
        if (! is_string($osm) || ! is_file($osm) || ! is_readable($osm)) {
            $this->error('Fichier OSM illisible (--osm=chemin).');

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
        $this->warn($appliquer ? 'APPLICATION — ajouts sur champ vide seulement.' : 'ESSAI À BLANC — rien n\'est écrit.');

        try {
            $b = (new ContactsOsm)->executer($workspaceId, $osm, $appliquer, $paquet);
        } catch (Throwable $e) {
            $this->error($e instanceof InvalidArgumentException ? 'Échec : ' . $e->getMessage() : 'Échec : ' . $e::class);

            return self::FAILURE;
        }

        $k = $appliquer ? 'ajoutes' : 'a_ajouter';
        $v = $appliquer ? 'ajoutés' : 'à ajouter';
        $this->info('Bilan' . ($appliquer ? '' : ' (ESSAI À BLANC)'));
        $this->line(sprintf('  points OSM lus : %d', $b['points']));
        $this->line(sprintf('  rapprochés : %d (non rapprochés : %d)', $b['rapproches'], $b['non_rapproches']));
        $this->line(sprintf('  ambigus (rien écrit) : %d', $b['ambigus']));
        $this->line(sprintf('  écartés pour opposition : %d', $b['opposition']));
        $this->line(sprintf('  e-mails %s : %d', $v, $b['emails_' . $k]));
        $this->line(sprintf('  sites %s : %d', $v, $b['sites_' . $k]));
        $this->line(sprintf('  téléphones %s : %d', $v, $b['telephones_' . $k]));
        $this->line(sprintf('  paquets en échec : %d', $b['lots_en_echec']));

        return $b['lots_en_echec'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
