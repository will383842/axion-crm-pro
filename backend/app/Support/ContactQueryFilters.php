<?php

namespace App\Support;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
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

            // Pays et statut de prospection vivent sur l'ENTREPRISE, pas sur la
            // personne. Sans ces deux filtres, les 605 contacts roumains
            // restent noyés dans 1,3 M de fiches françaises et rien ne permet
            // de les isoler — l'asymétrie signalée par Will le 2026-08-15.
            //
            // `whereExists` plutôt que `whereHas` : même sémantique, mais on
            // choisit explicitement la sous-requête et les colonnes, ce qui
            // laisse le planificateur attaquer par `companies` (446 fiches RO)
            // au lieu de balayer les contacts.
            AllowedFilter::callback('country_code', function (Builder $query, mixed $value): void {
                self::surEntreprise($query, 'country_code', $value);
            }),
            AllowedFilter::callback('prospection_status', function (Builder $query, mixed $value): void {
                self::surEntreprise($query, 'prospection_status', $value);
            }),

            // « Joignable » au sens des campagnes, appliqué à l'adresse de la
            // PERSONNE. 410 481 contacts en portent une, contre 255 290
            // génériques d'entreprise : sans ce filtre, la majorité des envois
            // possibles échappait à toute vérification d'opposition et de
            // rebond. Même définition que les entreprises, écrite une seule
            // fois dans `EligibiliteCampagne`.
            AllowedFilter::callback('joignable', function (Builder $query, mixed $value): void {
                $demande = is_string($value) ? trim($value) : '';
                if ($demande === '' || in_array($demande, ['0', 'false', 'non'], true)) {
                    return;
                }

                EligibiliteCampagne::appliquerContacts($query);
            }),

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

    /**
     * Filtre une colonne portée par l'ENTREPRISE rattachée.
     *
     * Le scope workspace n'est PAS repris ici : la requête appelante le porte
     * déjà sur `contacts`, et `contacts.company_id` ne peut pointer que vers
     * une entreprise du même workspace (RLS + contrainte). L'ajouter
     * doublerait la condition sans rien garder de plus.
     *
     * @param  Builder<Contact>  $query
     */
    private static function surEntreprise(Builder $query, string $colonne, mixed $value): void
    {
        $valeur = is_string($value) ? trim($value) : '';
        if ($valeur === '') {
            return;
        }

        $query->whereExists(function (\Illuminate\Database\Query\Builder $sub) use ($colonne, $valeur): void {
            $sub->select(DB::raw('1'))
                ->from('companies')
                ->whereColumn('companies.id', 'contacts.company_id')
                ->where('companies.' . $colonne, $valeur);
        });
    }
}
