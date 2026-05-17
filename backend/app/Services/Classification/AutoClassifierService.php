<?php

declare(strict_types=1);

namespace App\Services\Classification;

use App\Models\Company;

/**
 * Denormalize pour une Company donnée :
 *  - region_code      depuis department_code (mapping table)
 *  - department_code  depuis postcode (premiers 2 chars normaux, ou 2A/2B pour Corse, ou 3 chars DOM)
 *  - commune_code     depuis insee ou signals.ban.insee_commune
 *  - city_name        depuis signals.ban.city ou city
 *  - size_category    depuis effectif_range INSEE (micro/tpe/pme/eti/grande)
 *  - sector_main      depuis NAF code (mapping 20 secteurs)
 *
 * Idempotent : ne touche que les colonnes vides ou changées.
 * Aucun appel externe — pure logique de mapping.
 */
class AutoClassifierService
{
    /**
     * Mapping département → région (code INSEE région 2-digit).
     * Source : https://www.insee.fr/fr/information/2114819
     *
     * @var array<string,string>
     */
    private const DEPT_TO_REGION = [
        // Auvergne-Rhône-Alpes (84)
        '01' => '84', '03' => '84', '07' => '84', '15' => '84', '26' => '84',
        '38' => '84', '42' => '84', '43' => '84', '63' => '84', '69' => '84',
        '73' => '84', '74' => '84',
        // Bourgogne-Franche-Comté (27)
        '21' => '27', '25' => '27', '39' => '27', '58' => '27', '70' => '27',
        '71' => '27', '89' => '27', '90' => '27',
        // Bretagne (53)
        '22' => '53', '29' => '53', '35' => '53', '56' => '53',
        // Centre-Val de Loire (24)
        '18' => '24', '28' => '24', '36' => '24', '37' => '24', '41' => '24', '45' => '24',
        // Corse (94)
        '2A' => '94', '2B' => '94',
        // Grand Est (44)
        '08' => '44', '10' => '44', '51' => '44', '52' => '44', '54' => '44',
        '55' => '44', '57' => '44', '67' => '44', '68' => '44', '88' => '44',
        // Hauts-de-France (32)
        '02' => '32', '59' => '32', '60' => '32', '62' => '32', '80' => '32',
        // Île-de-France (11)
        '75' => '11', '77' => '11', '78' => '11', '91' => '11', '92' => '11',
        '93' => '11', '94' => '11', '95' => '11',
        // Normandie (28)
        '14' => '28', '27' => '28', '50' => '28', '61' => '28', '76' => '28',
        // Nouvelle-Aquitaine (75)
        '16' => '75', '17' => '75', '19' => '75', '23' => '75', '24' => '75',
        '33' => '75', '40' => '75', '47' => '75', '64' => '75', '79' => '75',
        '86' => '75', '87' => '75',
        // Occitanie (76)
        '09' => '76', '11' => '76', '12' => '76', '30' => '76', '31' => '76',
        '32' => '76', '34' => '76', '46' => '76', '48' => '76', '65' => '76',
        '66' => '76', '81' => '76', '82' => '76',
        // Pays de la Loire (52)
        '44' => '52', '49' => '52', '53' => '52', '72' => '52', '85' => '52',
        // Provence-Alpes-Côte d'Azur (93)
        '04' => '93', '05' => '93', '06' => '93', '13' => '93', '83' => '93', '84' => '93',
        // DROM-COM
        '971' => '01', '972' => '02', '973' => '03', '974' => '04', '976' => '06',
    ];

    /**
     * Mapping effectif INSEE → catégorie de taille business.
     * Source : https://www.insee.fr/fr/metadonnees/definition/c1057
     *
     * @var array<string,string>
     */
    private const EFFECTIF_TO_SIZE = [
        '00' => 'micro',   // 0 salarié
        '01' => 'micro',   // 1-2 salariés
        '02' => 'micro',   // 3-5
        '03' => 'tpe',     // 6-9
        '11' => 'tpe',     // 10-19
        '12' => 'tpe',     // 20-49
        '21' => 'pme',     // 50-99
        '22' => 'pme',     // 100-199
        '31' => 'pme',     // 200-249
        '32' => 'pme',     // 250-499 (techniquement déjà ETI mais marge)
        '41' => 'eti',     // 500-999
        '42' => 'eti',     // 1000-1999
        '51' => 'eti',     // 2000-4999
        '52' => 'grande',  // 5000-9999
        '53' => 'grande',  // 10000+
        'NN' => 'micro',   // Non renseigné — défaut prudent
    ];

    /**
     * Mapping NAF (préfixe code 2-digit) → secteur business unifié.
     * Source : nomenclature NAF rev.2 (INSEE).
     *
     * @var array<string,string>
     */
    private const NAF_TO_SECTOR = [
        '62' => 'it_saas',
        '63' => 'it_saas',
        '58' => 'media_edition',
        '59' => 'media_edition',
        '60' => 'media_edition',
        '41' => 'btp', '42' => 'btp', '43' => 'btp',
        '86' => 'sante', '87' => 'sante', '88' => 'sante',
        '10' => 'agro_alimentaire', '11' => 'agro_alimentaire', '12' => 'agro_alimentaire',
        '01' => 'agriculture', '02' => 'agriculture', '03' => 'agriculture',
        '47' => 'commerce', '46' => 'commerce', '45' => 'commerce',
        '55' => 'hotellerie_restauration', '56' => 'hotellerie_restauration',
        '64' => 'finance_assurance', '65' => 'finance_assurance', '66' => 'finance_assurance',
        '85' => 'enseignement',
        '49' => 'transport', '50' => 'transport', '51' => 'transport', '52' => 'transport', '53' => 'transport',
        '69' => 'services_pro', '70' => 'services_pro', '71' => 'services_pro', '72' => 'services_pro',
        '73' => 'services_pro', '74' => 'services_pro', '75' => 'services_pro',
        '13' => 'industrie', '14' => 'industrie', '15' => 'industrie', '16' => 'industrie',
        '17' => 'industrie', '18' => 'industrie', '19' => 'industrie', '20' => 'industrie',
        '21' => 'industrie', '22' => 'industrie', '23' => 'industrie', '24' => 'industrie',
        '25' => 'industrie', '26' => 'industrie', '27' => 'industrie', '28' => 'industrie',
        '29' => 'industrie', '30' => 'industrie', '31' => 'industrie', '32' => 'industrie',
        '33' => 'industrie',
        '35' => 'energie',
        '36' => 'environnement', '37' => 'environnement', '38' => 'environnement', '39' => 'environnement',
        '68' => 'immobilier',
        '77' => 'services_aux_entreprises', '78' => 'services_aux_entreprises',
        '79' => 'services_aux_entreprises', '80' => 'services_aux_entreprises',
        '81' => 'services_aux_entreprises', '82' => 'services_aux_entreprises',
        '84' => 'administration',
        '90' => 'arts_culture', '91' => 'arts_culture', '92' => 'arts_culture', '93' => 'arts_culture',
        '94' => 'associatif', '95' => 'associatif',
        '96' => 'services_perso', '97' => 'services_perso', '98' => 'services_perso',
    ];

    /**
     * Classifie une company. Retourne true si au moins une colonne a été mise à jour.
     */
    public function classify(Company $company): bool
    {
        $changed = false;

        // Département depuis postcode
        $dept = $this->extractDepartmentCode($company->postcode);
        if ($dept !== null && $company->department_code !== $dept) {
            $company->department_code = $dept;
            $changed = true;
        }

        // Région depuis département
        if ($company->department_code) {
            $region = self::DEPT_TO_REGION[$company->department_code] ?? null;
            if ($region !== null && $company->region_code !== $region) {
                $company->region_code = $region;
                $changed = true;
            }
        }

        // Commune code depuis insee ou signals.ban
        $commune = $this->extractCommuneCode($company);
        if ($commune !== null && $company->commune_code !== $commune) {
            $company->commune_code = $commune;
            $changed = true;
        }

        // City name canonique
        $cityName = $this->extractCityName($company);
        if ($cityName !== null && $company->city_name !== $cityName) {
            $company->city_name = $cityName;
            $changed = true;
        }

        // Size category depuis effectif_range
        if ($company->effectif_range) {
            $size = self::EFFECTIF_TO_SIZE[$company->effectif_range] ?? null;
            if ($size !== null && $company->size_category !== $size) {
                $company->size_category = $size;
                $changed = true;
            }
        }

        // Sector main depuis NAF (2 premiers digits)
        if ($company->naf && strlen($company->naf) >= 2) {
            $nafPrefix = substr($company->naf, 0, 2);
            $sector = self::NAF_TO_SECTOR[$nafPrefix] ?? 'autre';
            if ($company->sector_main !== $sector) {
                $company->sector_main = $sector;
                $changed = true;
            }
        }

        if ($changed) {
            $company->save();
        }

        return $changed;
    }

    private function extractDepartmentCode(?string $postcode): ?string
    {
        if ($postcode === null || strlen($postcode) < 2) {
            return null;
        }
        $first2 = substr($postcode, 0, 2);
        // Corse : 20 → 2A/2B (insee distinct), distinction via postcode 3e char
        if ($first2 === '20') {
            $third = (int) ($postcode[2] ?? '0');
            // 200xx, 201xx = 2A (Corse-du-Sud), 202xx = 2B (Haute-Corse)
            return $third <= 1 ? '2A' : '2B';
        }
        // DOM : 97x/98x → 971/972/973/974/976 (3 chars)
        if ($first2 === '97' || $first2 === '98') {
            if (strlen($postcode) < 3) {
                return null;
            }
            return substr($postcode, 0, 3);
        }
        return $first2;
    }

    private function extractCommuneCode(Company $company): ?string
    {
        if ($company->insee && strlen($company->insee) === 5) {
            return $company->insee;
        }
        $signals = $company->signals ?? [];
        $banCommune = $signals['ban']['insee_commune'] ?? null;
        if (is_string($banCommune) && strlen($banCommune) === 5) {
            return $banCommune;
        }
        return null;
    }

    private function extractCityName(Company $company): ?string
    {
        $signals = $company->signals ?? [];
        $banCity = $signals['ban']['city'] ?? null;
        if (is_string($banCity) && $banCity !== '') {
            return mb_substr($banCity, 0, 120);
        }
        if ($company->city) {
            return mb_substr($company->city, 0, 120);
        }
        return null;
    }
}
