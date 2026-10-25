<?php

namespace App\Console\Commands;

use App\Crm\EspaceProspection;
use App\Crm\Pharmacies\ImportFiness;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Throwable;

/**
 * OFFICINES FINESS → FICHES MANQUANTES (dépôt public : totaux seulement).
 *
 *   php artisan crm:pharmacies:import-finess finess_pharma.csv            # essai à blanc
 *   php artisan crm:pharmacies:import-finess finess_pharma.csv --apply    # crée les fiches manquantes
 *
 * Règle complète : `App\Crm\Pharmacies\ImportFiness`. Rien n'est jamais
 * écrasé, supprimé ni purgé.
 */
class CrmPharmaciesImportFiness extends Command
{
    protected $signature = 'crm:pharmacies:import-finess
                            {fichier : CSV FINESS (finess_et, siret, categorie, etat, nom_long, code_postal, commune, dep, statut, ape)}
                            {--apply : Crée les fiches manquantes (sans cette option : essai à blanc, totaux par département seulement)}
                            {--workspace= : Identifiant ou slug de l espace (défaut : l espace de prospection)}
                            {--paquet=500 : Lignes par paquet (1 à 2000)}';

    protected $description = 'Compare les officines FINESS (620, état A) au CRM et, avec --apply, crée UNIQUEMENT les fiches manquantes — n écrase rien, ne supprime rien.';

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
        $this->warn($appliquer ? 'APPLICATION — seules les fiches manquantes sont créées.' : 'ESSAI À BLANC — rien n\'est écrit.');

        try {
            $b = (new ImportFiness)->executer($workspaceId, $fichier, $appliquer, $paquet);
        } catch (Throwable $e) {
            // Colonnes manquantes : message sans aucune donnée personnelle, affiché tel quel.
            if ($e instanceof InvalidArgumentException) {
                $this->error('Échec : ' . $e->getMessage());

                return self::FAILURE;
            }
            // Jamais getMessage() : un message d'erreur de base peut contenir du SQL avec ses valeurs.
            $sqlstate = $e instanceof QueryException ? ' (SQLSTATE ' . ($e->errorInfo[0] ?? '?') . ')' : '';
            $this->error('Échec : ' . $e::class . $sqlstate);

            return self::FAILURE;
        }

        $this->info('Bilan' . ($appliquer ? '' : ' (ESSAI À BLANC)'));
        $this->line(sprintf('  lignes lues : %d (inactives : %d)', $b['lignes'], $b['inactives']));
        $this->line(sprintf('  officines actives (620) : %d (sans SIRET : %d, doublons du fichier : %d)', $b['officines'], $b['sans_siret'], $b['doublons_fichier']));
        $this->line(sprintf('  déjà au CRM par SIRET : %d', $b['deja_par_siret']));
        $this->line(sprintf('  SIREN connu, autre SIRET (établissement ajouté, fiche intacte) : %d', $b['siren_autre_siret']));
        $this->line(sprintf('  SIREN à la corbeille (ignorées) : %d', $b['corbeille']));
        $this->line(sprintf('  fiches %s : %d', $appliquer ? 'créées' : 'à créer', $appliquer ? $b['creees'] : $b['a_creer']));
        $this->line(sprintf('  personnes physiques %d dont créées sans nom ni adresse', $b['personnes_physiques']));
        if ($appliquer) {
            $this->line(sprintf('  établissements notés : %d', $b['etablissements_ajoutes']));
        }
        $aPart = [];
        foreach ($b['autres_categories'] as $categorie => $n) {
            $aPart[] = $categorie . ' = ' . $n;
        }
        $this->line('  À PART (non créées) : ' . ($aPart === [] ? 'aucune' : implode(', ', $aPart)));
        $this->line('  fiches à créer par département :');
        foreach ($b['par_departement'] as $dep => $n) {
            $this->line(sprintf('    %s : %d', $dep, $n));
        }

        return self::SUCCESS;
    }
}
