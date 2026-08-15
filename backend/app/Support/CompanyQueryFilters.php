<?php

namespace App\Support;

use Spatie\QueryBuilder\AllowedFilter;

/**
 * Filtres `filter[…]` autorisés sur les entreprises — extraits de
 * `CompaniesController` (lot L6) pour être PARTAGÉS avec le hub de contacts de
 * la console v2.
 *
 * Le plan (§2.11) impose de « répliquer le motif déjà en place sur la liste
 * entreprises ». Répliquer une liste de filtres, c'est se garantir qu'un jour
 * l'une des deux aura un filtre que l'autre n'a pas — et l'écart se découvre
 * toujours par un export qui ne correspond pas à la liste affichée (le défaut
 * précisément corrigé sur `CompaniesController::export`). On la partage donc.
 */
final class CompanyQueryFilters
{
    /**
     * @return list<AllowedFilter|string>
     */
    public static function allowed(): array
    {
        return [
            AllowedFilter::exact('naf'),
            AllowedFilter::exact('size_category'),
            AllowedFilter::exact('effectif', 'effectif_range'),
            AllowedFilter::exact('priority'),
            AllowedFilter::exact('discovery_source'),
            AllowedFilter::exact('prospection_status'),
            AllowedFilter::exact('department_code'),
            AllowedFilter::exact('region_code'),
            AllowedFilter::exact('sector_main'),
            AllowedFilter::exact('quality', 'quality_badge'),
            AllowedFilter::exact('best_email_confidence'),
            AllowedFilter::partial('denomination'),
            AllowedFilter::partial('postcode'),
            // `filter[tag]=implantation-ro` (multi : `filter[tag]=a,b` = ET
            // logique) — indispensable pour retrouver un SEGMENT taggé (ex.
            // campagne « implantations Roumanie ») dans la liste, l'export ET
            // le hub, qui partagent cette même liste de filtres.
            AllowedFilter::callback('tag', function ($query, $value): void {
                foreach (array_filter(array_map('strval', (array) $value)) as $slug) {
                    $query->whereHas('tags', fn ($q) => $q->where('slug', $slug));
                }
            }),
        ];
    }

    /**
     * Filtres de la console v2 : les mêmes, plus les deux axes de la taxonomie
     * du lot L1 (`relation_type`, `lifecycle_stage`).
     *
     * @return list<AllowedFilter|string>
     */
    public static function allowedWithTaxonomy(): array
    {
        return array_merge(self::allowed(), [
            AllowedFilter::exact('relation_type'),
            AllowedFilter::exact('lifecycle_stage'),
            AllowedFilter::exact('legal_basis'),
        ]);
    }
}
