<?php

namespace App\Console\Commands;

use App\Crm\EspaceProspection;
use App\Crm\Sante\EnrichissementFinessSante;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * ENRICHISSEMENT DES ÉTABLISSEMENTS DE SANTÉ FINESS → companies, champs vides (dépôt public : totaux seulement).
 *
 *   php artisan crm:sante:enrichir-finess etablissements.csv                          # essai à blanc, toutes catégories sauf 620
 *   php artisan crm:sante:enrichir-finess etablissements.csv --categories=101,106 --apply
 *
 * Ajoute, sur champ vide seulement : téléphone, adresse, GPS. Jamais d'écrasement, jamais de fiche créée,
 * ni fax, ni courriel, ni date. La catégorie 620 (officines) a sa propre commande : refusée ici.
 * Règle complète : `App\Crm\Sante\EnrichissementFinessSante`.
 */
class CrmSanteEnrichirFiness extends Command
{
    protected $signature = 'crm:sante:enrichir-finess
                            {fichier : etablissements.csv FINESS brut (séparateur point-virgule)}
                            {--apply : Ajoute les informations (sans cette option : essai à blanc, rien n est écrit)}
                            {--workspace= : Identifiant ou slug de l espace (défaut : l espace de prospection)}
                            {--categories=toutes : « toutes » (sauf 620) ou codes FINESS séparés par des virgules (ex. 101,106)}
                            {--paquet=500 : SIREN par paquet (1 à 2000)}';

    protected $description = 'Ajoute téléphone, adresse et GPS FINESS des établissements de santé (hors 620, état A) dans companies, sur champ vide seulement.';

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
        $brut = trim((string) $this->option('categories'));
        $categories = [];
        if ($brut !== '' && $brut !== 'toutes') {
            if (preg_match('/^\d{3}(,\d{3})*$/', $brut) !== 1) {
                $this->error('--categories doit valoir « toutes » ou une liste de codes à trois chiffres séparés par des virgules.');

                return self::FAILURE;
            }
            $categories = array_values(array_unique(explode(',', $brut)));
            if (in_array('620', $categories, true)) {
                $this->error('La catégorie 620 (officines) est refusée ici : elle a sa propre commande.');

                return self::FAILURE;
            }
        }
        $designation = $this->option('workspace');
        $workspaceId = EspaceProspection::resoudre(is_string($designation) ? $designation : null);
        if ($workspaceId === null) {
            $this->error('Aucun espace cible (--workspace=UUID ou slug).');

            return self::FAILURE;
        }

        $appliquer = (bool) $this->option('apply');
        $this->warn($appliquer ? 'APPLICATION — informations ajoutées sur champ vide seulement.' : 'ESSAI À BLANC — rien n\'est écrit.');

        try {
            $b = (new EnrichissementFinessSante)->executer($workspaceId, $fichier, $appliquer, $categories, $paquet);
        } catch (Throwable $e) {
            if ($e instanceof InvalidArgumentException) {
                $this->error('Échec : ' . $e->getMessage());

                return self::FAILURE;
            }
            $this->error('Échec : ' . $e::class);

            return self::FAILURE;
        }

        $v = $appliquer ? 'ajoutés' : 'à ajouter';
        $this->info('Bilan' . ($appliquer ? '' : ' (ESSAI À BLANC)'));
        $this->line(sprintf('  lignes lues : %d (établissements actifs : %d, sans SIRET valide : %d)', $b['lignes'], $b['actives'], $b['sans_siret']));
        $this->line(sprintf('  fiches trouvées par SIRET (N1) : %d, par SIREN (N2) : %d', $b['trouvees_n1'], $b['trouvees_n2']));
        $this->line(sprintf('  SIREN sans fiche utilisable (absente, fermée, protégée, corbeille) : %d', $b['exclues']));
        $this->line(sprintf('  SIREN ambigus : %d, SIRET partagés : %d, autre SIRET sur la fiche : %d, code postal divergent : %d', $b['siren_ambigu'], $b['siret_partage'], $b['siret_different'], $b['cp_divergent']));
        $this->line(sprintf('  personnes physiques écartées : %d', $b['personnes_physiques_ecartees']));
        $this->line(sprintf('  écartées pour opposition : %d', $b['opposition']));
        $this->line(sprintf('  numéros invalides : %d, GPS hors France écartés : %d', $b['invalides'], $b['gps_hors_france']));
        $this->line(sprintf('  téléphones %s : %d, adresses %s : %d, GPS %s : %d', $v, $b['tel'], $v, $b['adr'], $v, $b['gps']));
        $this->line(sprintf('  fiches enrichies : %d', $b['fiches_enrichies']));
        $this->line(sprintf('  paquets en échec : %d', $b['lots_en_echec']));
        foreach ($b['par_categorie'] as $code => $c) {
            $this->line(sprintf(
                '  catégorie %s : actives %d, sans_siret %d, n1 %d, n2 %d, ambigu %d, partagé %d, autre_siret %d, cp_divergent %d, exclues %d, pp %d, opposition %d, tel %d, adr %d, gps %d, enrichies %d',
                $code,
                $c['actives'],
                $c['sans_siret'],
                $c['trouvees_n1'],
                $c['trouvees_n2'],
                $c['siren_ambigu'],
                $c['siret_partage'],
                $c['siret_different'],
                $c['cp_divergent'],
                $c['exclues'],
                $c['pp'],
                $c['opposition'],
                $c['tel'],
                $c['adr'],
                $c['gps'],
                $c['fiches_enrichies'],
            ));
        }

        return $b['lots_en_echec'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
