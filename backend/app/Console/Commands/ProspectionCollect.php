<?php

namespace App\Console\Commands;

use App\Contracts\InseeClient;
use App\Models\Company;
use App\Services\Prospection\SectorClassifier;
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

        $workspaceId = $this->option('workspace')
            ?: DB::table('workspaces')->orderBy('created_at')->value('id');
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
                [
                    'denomination', 'naf', 'legal_form', 'effectif_range', 'size_category',
                    'sector_main', 'address', 'postcode', 'city', 'city_name', 'insee',
                    'siret', 'enseigne', 'metadata', 'discovery_source', 'department_code', 'updated_at',
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
            $buffer[] = [
                'workspace_id'     => $workspaceId,
                'siren'            => $data->siren,
                'denomination'     => $data->denomination,
                'naf'              => $data->naf,
                'legal_form'       => $data->legalForm,
                'effectif_range'   => $data->effectifRange,
                'size_category'    => $this->sizeFrom(
                    $data->effectifRange,
                    is_array($data->raw['uniteLegale'] ?? null) ? ($data->raw['uniteLegale']['categorieEntreprise'] ?? null) : null,
                ),
                'sector_main'      => SectorClassifier::fromNaf($data->naf),
                'address'          => $data->address,
                'postcode'         => $data->postcode,
                'city'             => $data->city,
                'city_name'        => $data->city,
                'insee'            => $data->insee,
                'siret'            => is_string($data->raw['siret'] ?? null) ? $data->raw['siret'] : null,
                'enseigne'         => $extra['enseigne'] ?? null,
                'metadata'         => json_encode($extra, JSON_UNESCAPED_UNICODE),
                'discovery_source' => 'insee',
                'department_code'  => $deptCode,
                'created_at'       => now(),
                'updated_at'       => now(),
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
        $this->line("Enrichissement (emails/tél/dirigeants) à lancer séparément.");
        return self::SUCCESS;
    }

    /**
     * Dérive la catégorie de taille (tpe/pme/eti/grande_entreprise) depuis la
     * tranche d'effectif INSEE (`trancheEffectifsUniteLegale`). Les tranches
     * non renseignées (NN/null) et 00–03 → TPE (micro, cas le plus fréquent).
     */
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
            'siret'                => $raw['siret'] ?? null,
            'sigle'                => $u['sigleUniteLegale'] ?? null,
            'enseigne'             => $periode['enseigne1Etablissement'] ?? null,
            'categorie_entreprise' => $u['categorieEntreprise'] ?? null,      // TPE/PME/ETI/GE officiel INSEE
            'date_creation'        => $u['dateCreationUniteLegale'] ?? null,
            'forme_juridique'      => $u['categorieJuridiqueUniteLegale'] ?? null,
            'employeur'            => $u['caractereEmployeurUniteLegale'] ?? null,   // O = a des salariés
            'ess'                  => $u['economieSocialeSolidaireUniteLegale'] ?? null,
            'gps_lambert_x'        => $adr['coordonneeLambertAbscisseEtablissement'] ?? null,
            'gps_lambert_y'        => $adr['coordonneeLambertOrdonneeEtablissement'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Catégorie de taille : priorité à la catégorie OFFICIELLE INSEE
     * (calculée sur tout le groupe), repli sur la tranche d'effectif du siège.
     */
    private function sizeFrom(?string $tranche, ?string $categorie): string
    {
        $cat = strtoupper(trim((string) $categorie));
        if ($cat === 'GE') {
            return 'grande_entreprise';
        }
        if ($cat === 'ETI') {
            return 'eti';
        }
        $t = trim((string) $tranche);
        $hasStaff = in_array($t, ['11', '12', '21', '22', '31'], true);
        if ($cat === 'PME') {
            return $hasStaff ? 'pme' : 'tpe';
        }
        return match (true) {
            $hasStaff                                    => 'pme',               // 10–249
            in_array($t, ['32', '41', '42', '51'], true) => 'eti',               // 250–4999
            in_array($t, ['52', '53'], true)             => 'grande_entreprise',  // 5000+
            default                                      => 'tpe',               // 00–03, NN, null
        };
    }
}
