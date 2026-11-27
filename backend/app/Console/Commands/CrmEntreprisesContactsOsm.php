<?php

namespace App\Console\Commands;

use App\Crm\Entreprises\ContactsOsmSiret;
use App\Crm\EspaceProspection;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * CONTACTS DES ENTREPRISES DEPUIS OPENSTREETMAP, PAR SIRET (dépôt public : totaux seulement).
 *
 *   php artisan crm:entreprises:contacts-osm --osm=osm.csv            # essai à blanc
 *   php artisan crm:entreprises:contacts-osm --osm=osm.csv --apply    # ajoute sur champs vides
 *
 * Règle complète : `App\Crm\Entreprises\ContactsOsmSiret`. Jamais d'écrasement ; le site reste un candidat.
 */
class CrmEntreprisesContactsOsm extends Command
{
    protected $signature = 'crm:entreprises:contacts-osm
                            {--osm= : CSV siret,telephone,email,site extrait d OpenStreetMap (ref:FR:SIRET)}
                            {--apply : Écrit les ajouts (sans cette option : essai à blanc, rien n est écrit)}
                            {--workspace= : Identifiant ou slug de l espace (défaut : l espace de prospection)}
                            {--paquet=500 : Fiches par paquet (1 à 2000)}';

    protected $description = 'Ajoute e-mail générique et téléphone OpenStreetMap UNIQUEMENT sur champ vide (SIRET exact) ; le site reste un candidat à prouver, jamais écrit dans website.';

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
            $b = (new ContactsOsmSiret)->executer($workspaceId, $osm, $appliquer, $paquet);
        } catch (Throwable $e) {
            $this->error($e instanceof InvalidArgumentException ? 'Échec : ' . $e->getMessage() : 'Échec : ' . $e::class);

            return self::FAILURE;
        }

        $v = $appliquer ? 'ajoutés' : 'à ajouter';
        $k = $appliquer ? 'ajoutes' : 'a_ajouter';
        $this->info('Bilan' . ($appliquer ? '' : ' (ESSAI À BLANC)'));
        $this->line(sprintf('  objets OSM lus : %d (SIRET invalides : %d, SIRET ambigus : %d)', $b['points'], $b['siret_invalides'], $b['siret_ambigus']));
        $this->line(sprintf('  rapprochés : %d (non rapprochés : %d)', $b['rapproches'], $b['non_rapproches']));
        $this->line(sprintf('  ambigus (rien écrit) : %d', $b['ambigus']));
        $this->line(sprintf('  exclues (fiche inéligible, rien écrit) : %d', $b['exclues']));
        $this->line(sprintf('  e-mails nominatifs ignorés : %d', $b['emails_nominatifs_ignores']));
        $this->line(sprintf('  écartés pour opposition : %d', $b['opposition']));
        $this->line(sprintf('  e-mails %s : %d', $v, $b['emails_' . $k]));
        $this->line(sprintf('  téléphones %s : %d', $v, $b['telephones_' . $k]));
        $this->line(sprintf('  sites candidats %s : %d', $appliquer ? 'notés' : 'à noter', $appliquer ? $b['sites_notes'] : $b['sites_a_noter']));
        $this->line(sprintf('  paquets en échec : %d', $b['lots_en_echec']));

        return $b['lots_en_echec'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
