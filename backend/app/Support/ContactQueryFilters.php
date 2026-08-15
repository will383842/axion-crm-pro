<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * Filtres `filter[…]` autorisés sur les contacts.
 *
 * Liste FERMÉE, comme pour les entreprises : un filtre libre sur une colonne
 * arbitraire, c'est un scan séquentiel de 1,3 M de lignes à un caractère près
 * dans l'URL.
 */
final class ContactQueryFilters
{
    /**
     * @return list<AllowedFilter|string>
     */
    public static function allowed(): array
    {
        return [
            AllowedFilter::exact('email_status'),
            AllowedFilter::exact('discovery_source'),
            AllowedFilter::exact('company_id'),

            // Recherche par nom : PRÉFIXE (`x%`), jamais `%x%`.
            // Un `%x%` interdit tout index et impose la lecture des 1,3 M de
            // lignes à chaque frappe. Le préfixe s'appuie sur l'index
            // fonctionnel `lower(last_name)` posé par la migration
            // 2026_08_15_000003 — sans lui, ce filtre serait un piège.
            AllowedFilter::callback('last_name', function (Builder $query, mixed $value): void {
                $terme = is_string($value) ? trim($value) : '';
                if ($terme === '') {
                    return;
                }

                $query->whereRaw('lower(last_name) LIKE ?', [mb_strtolower($terme) . '%']);
            }),
        ];
    }
}
