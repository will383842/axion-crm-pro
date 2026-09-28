<?php

declare(strict_types=1);

namespace App\Services\Classification;

use App\Crm\Referentiels\Classement;
use App\Models\Company;

/**
 * Denormalize pour une Company donnée :
 *  - department_code  depuis postcode (premiers 2 chars normaux, ou 2A/2B pour Corse, ou 3 chars DOM)
 *  - commune_code     depuis insee ou signals.ban.insee_commune
 *  - city_name        depuis signals.ban.city ou city
 *  - sector_main, naf_nomenclature, naf_rev2, size_category, entity_nature,
 *    region_code      par LE calcul unique `Classement::pourFiche()`
 *
 * ⚠️ 2026-09-28 (chantier « référentiels ») — ce service portait SA PROPRE
 * table NAF → secteur (20 secteurs) et SA PROPRE table effectif → taille
 * (`micro`, `grande`…), différentes de celles de la collecte INSEE. Il
 * réécrivait donc le secteur posé par la collecte à chaque enrichissement :
 * une même entreprise changeait de secteur en étant enrichie. Il appelle
 * désormais le même calcul que la collecte et le reclassement de masse : il ne
 * peut plus réécrire un secteur que si le code NAF lui-même a changé.
 *
 * Idempotent : ne touche que les colonnes vides ou changées.
 * Aucun appel externe — pure logique de mapping.
 */
class AutoClassifierService
{
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

        $metadata = $company->metadata ?? [];
        $categorie = $metadata['categorie_entreprise'] ?? null;

        $classement = Classement::pourFiche([
            'naf' => $company->naf,
            'effectif_range' => $company->effectif_range,
            'categorie_entreprise' => is_string($categorie) ? $categorie : null,
            'size_category' => $company->size_category,
            'entity_nature' => $company->entity_nature,
            'discovery_source' => $company->discovery_source,
            'department_code' => $company->department_code,
            'region_code' => $company->region_code,
            'country_code' => $company->country_code,
        ]);

        foreach (['sector_main', 'naf_nomenclature', 'naf_rev2', 'size_category', 'entity_nature', 'region_code'] as $colonne) {
            $valeur = $classement[$colonne];
            // Une valeur que le calcul ne sait pas établir (null) n'efface
            // jamais une valeur déjà posée.
            if ($valeur !== null && $company->{$colonne} !== $valeur) {
                $company->{$colonne} = $valeur;
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
