<?php

namespace App\Console\Commands;

use App\Contracts\InseeClient;
use App\Crm\EspaceProspection;
use App\Crm\Referentiels\Classement;
use App\Crm\Referentiels\NomenclatureNaf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Récupère (DÉCOUVERTE SEULE, sans enrichissement) toutes les entreprises d'un
 * département via l'API INSEE Sirene, en sauvegardant au fil de l'eau (résilient
 * aux timeouts). L'enrichissement se lance séparément ensuite.
 *
 * Ex : `php artisan prospection:collect 38`            → tout l'Isère
 *      `php artisan prospection:collect 38 --limit=500`→ 500 premières
 *      `php artisan prospection:collect 38 --req-delay=150` → plan authentifié 500/min
 */
class ProspectionCollect extends Command
{
    protected $signature = 'prospection:collect '
        . '{department : code département (ex 38, 2A, 971)} '
        . '{--limit=0 : nombre max (0 = tout le département)} '
        . '{--workspace= : UUID du workspace cible (défaut = 1er)} '
        . '{--req-delay=2100 : ms entre requêtes INSEE (2100≈30/min plan public ; 150≈500/min plan authentifié)}';

    protected $description = 'Récupère toutes les entreprises d\'un département (découverte INSEE seule, sans enrichissement).';

    public function handle(InseeClient $insee): int
    {
        $dept = trim((string) $this->argument('department'));
        if ($dept === '') {
            $this->error('Département requis (ex : 38).');

            return self::FAILURE;
        }
        // Un code commune à 5 chiffres (ex. arrondissement de Paris 75101) déclenche une
        // collecte par COMMUNE — utile pour les zones trop denses à collecter en dépt entier
        // (Paris a dépassé la limite mémoire PHP en une seule passe).
        $isCommune = (bool) preg_match('/^\d{5}$/', $dept);
        $deptCode = $isCommune
            ? (str_starts_with($dept, '97') ? substr($dept, 0, 3) : substr($dept, 0, 2))
            : $dept;
        $limit = (int) $this->option('limit');
        $delay = (int) $this->option('req-delay');

        // Même résolution que `crm:referentiels:reclasser` (EspaceProspection) :
        // la collecte et le reclassement visent le MÊME espace.
        $workspaceId = $this->option('workspace') ?: EspaceProspection::parDefaut();
        if (! $workspaceId) {
            $this->error('Aucun workspace cible (--workspace=UUID).');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Récupération INSEE — département %s (limite %s) → workspace %s…',
            $dept,
            $limit > 0 ? $limit : 'tout',
            substr((string) $workspaceId, 0, 8),
        ));

        $count = 0;
        $start = microtime(true);
        $buffer = [];

        // Insertion par LOTS (upsert 500 lignes à la fois) — bien plus rapide que
        // ligne par ligne. Clé de conflit : (workspace_id, siren).
        $flush = function () use (&$buffer): void {
            if ($buffer === []) {
                return;
            }
            DB::table('companies')->upsert(
                $buffer,
                ['workspace_id', 'siren'],
                // `entity_nature` est posée à la CRÉATION seulement : une nature
                // déjà décidée (à la main, par un import) n'est jamais réécrite.
                [
                    'denomination', 'naf', 'legal_form', 'effectif_range', 'size_category',
                    'naf_nomenclature', 'naf_rev2', 'address', 'postcode', 'city', 'city_name', 'insee',
                    'siret', 'enseigne', 'metadata', 'discovery_source', 'department_code', 'region_code', 'updated_at',
                    // Même règle que `Classement::secteurRetenu()` : un code NAF
                    // muet (`non_classe`) ne remplace pas un secteur valide déjà
                    // posé (`interprofessionnel`, secteur représenté).
                    'sector_main' => DB::raw(
                        "CASE WHEN excluded.sector_main = 'non_classe' AND companies.sector_main IN ("
                        . Classement::secteursConservables() . ') THEN companies.sector_main ELSE excluded.sector_main END',
                    ),
                ],
            );
            $buffer = [];
        };

        $criteria = $isCommune
            ? ['commune' => $dept, 'req_delay_ms' => $delay]
            : ['department' => $dept, 'req_delay_ms' => $delay];
        foreach ($insee->iterateByCriteria($criteria) as $data) {
            if ($data->siren === '') {
                continue;
            }
            $extra = $this->extraInseeFields($data->raw);
            // LE calcul unique (`App\Crm\Referentiels`) : la collecte,
            // l'enrichissement et le reclassement de masse rangent une même
            // entreprise dans le même secteur et la même taille.
            $naf = NomenclatureNaf::classer($data->naf);
            $categorie = is_array($data->raw['uniteLegale'] ?? null) ? ($data->raw['uniteLegale']['categorieEntreprise'] ?? null) : null;
            $buffer[] = [
                'workspace_id' => $workspaceId,
                'siren' => $data->siren,
                'denomination' => $data->denomination,
                'naf' => $data->naf,
                'legal_form' => $data->legalForm,
                'effectif_range' => $data->effectifRange,
                'size_category' => Classement::tailleDepuisInsee(
                    $data->effectifRange,
                    is_string($categorie) ? $categorie : null,
                ),
                'sector_main' => $naf->secteur,
                'naf_nomenclature' => $naf->nomenclature,
                'naf_rev2' => $naf->codeRev2,
                'entity_nature' => 'entreprise',
                'region_code' => Classement::regionDuDepartement($deptCode),
                'address' => $data->address,
                'postcode' => $data->postcode,
                'city' => $data->city,
                'city_name' => $data->city,
                'insee' => $data->insee,
                'siret' => is_string($data->raw['siret'] ?? null) ? $data->raw['siret'] : null,
                'enseigne' => $extra['enseigne'] ?? null,
                'metadata' => json_encode($extra, JSON_UNESCAPED_UNICODE),
                'discovery_source' => 'insee',
                'department_code' => $deptCode,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $count++;

            if (count($buffer) >= 500) {
                $flush();
                if ($count % 5000 === 0) {
                    $elapsed = round(microtime(true) - $start);
                    $this->info("  … {$count} traitées — {$elapsed}s");
                }
            }
            if ($limit > 0 && $count >= $limit) {
                break;
            }
        }
        $flush();

        $elapsed = round(microtime(true) - $start);
        $this->info("✅ Terminé : {$count} entreprises pour le dépt {$dept} en {$elapsed}s.");
        $this->line('Enrichissement (emails/tél/dirigeants) à lancer séparément.');

        return self::SUCCESS;
    }

    /**
     * Champs INSEE supplémentaires utiles (stockés en metadata JSONB) : SIRET,
     * enseigne, catégorie officielle (TPE/PME/ETI/GE), date de création, forme
     * juridique, ESS, coordonnées GPS Lambert.
     *
     * @param  array<string,mixed>  $raw  établissement INSEE brut
     * @return array<string,mixed>
     */
    private function extraInseeFields(array $raw): array
    {
        $u = is_array($raw['uniteLegale'] ?? null) ? $raw['uniteLegale'] : [];
        $periode = is_array($raw['periodesEtablissement'][0] ?? null) ? $raw['periodesEtablissement'][0] : [];
        $adr = is_array($raw['adresseEtablissement'] ?? null) ? $raw['adresseEtablissement'] : [];

        return array_filter([
            'siret' => $raw['siret'] ?? null,
            'sigle' => $u['sigleUniteLegale'] ?? null,
            'enseigne' => $periode['enseigne1Etablissement'] ?? null,
            'categorie_entreprise' => $u['categorieEntreprise'] ?? null,      // TPE/PME/ETI/GE officiel INSEE
            'date_creation' => $u['dateCreationUniteLegale'] ?? null,
            'forme_juridique' => $u['categorieJuridiqueUniteLegale'] ?? null,
            'employeur' => $u['caractereEmployeurUniteLegale'] ?? null,   // O = a des salariés
            'ess' => $u['economieSocialeSolidaireUniteLegale'] ?? null,
            'gps_lambert_x' => $adr['coordonneeLambertAbscisseEtablissement'] ?? null,
            'gps_lambert_y' => $adr['coordonneeLambertOrdonneeEtablissement'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
