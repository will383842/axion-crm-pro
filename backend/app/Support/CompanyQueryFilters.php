<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
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

            // FENÊTRE DE DATES sur l'entrée de la fiche dans le CRM.
            // Adossées à l'index `(workspace_id, created_at DESC)` posé par la
            // migration 000006 : sans lui, la même requête met 3 487 ms
            // (scan séquentiel de 4,29 M de lignes pour en trouver 150) — un
            // filtre plus lent que pas de filtre paraît cassé, et on cherche
            // la cause ailleurs.
            //
            // Bornes SÉPARÉES plutôt qu'un intervalle unique : « tout ce qui
            // est arrivé depuis lundi » est la question la plus fréquente, et
            // elle n'a pas de borne haute.
            AllowedFilter::callback('cree_apres', function (Builder $query, mixed $value): void {
                self::borneDate($query, 'created_at', $value, '>=');
            }),
            AllowedFilter::callback('cree_avant', function (Builder $query, mixed $value): void {
                self::borneDate($query, 'created_at', $value, '<=');
            }),

            // « Éligible campagne » — la définition UNIQUE (`EligibiliteCampagne`),
            // celle que le futur moteur d'envoi reprendra telle quelle. On ne
            // sépare pas les fiches complètes dans un bac : on les CALCULE, sans
            // quoi l'ensemble périmerait au premier enrichissement et
            // continuerait d'arroser une adresse qui s'est opposée entre-temps.
            // `filter[eligible_campagne]=1` → prêtes · `=0` → le reste à faire.
            AllowedFilter::callback('eligible_campagne', function (Builder $query, mixed $value): void {
                $demande = is_string($value) ? trim($value) : '';
                if ($demande === '') {
                    return;
                }

                if (in_array($demande, ['0', 'false', 'non'], true)) {
                    EligibiliteCampagne::appliquerNonEligibles($query);

                    return;
                }

                EligibiliteCampagne::appliquer($query);
            }),
            // ── `G41-003` / `G41-004` (S1) : 65 s au volume de production ───
            //
            // Ce filtre était `AllowedFilter::partial('denomination')`, qui
            // émet `LOWER(denomination) LIKE LOWER('%x%')`.
            //
            // 🔴 Aucun index ne peut servir cette condition, et le constat le
            // dit avec précision : « l'index de 110 Mo censé le servir porte
            // sur une AUTRE colonne ». Vérifié au schéma le 2026-08-20 :
            // `denomination` ne porte AUCUN index ; c'est
            // `denomination_normalized` — colonne GENERATED — que couvre
            // `idx_companies_denomination_trgm`, et ce depuis le 2026-05-16.
            //
            // Mesuré sur 150 000 lignes (la production en porte 4,29 M) :
            //     sur `denomination` .............. Seq Scan          145,7 ms
            //     sur `denomination_normalized` ... Bitmap Index Scan   2,7 ms
            //
            // ⚠️ Le remède n'est PAS d'ajouter un index : il existe déjà. C'est
            // de chercher sur la colonne qu'il couvre. Un second index
            // trigrammes sur `denomination` coûterait 110 Mo de plus pour
            // dupliquer celui-là — et sous le même nom, un
            // `CREATE INDEX IF NOT EXISTS` serait un SILENCE, pas une
            // idempotence (c'est l'accident du 2026-08-20, cf.
            // `IndexServentLesRequetesTest`).
            //
            // La condition vit dans `RechercheDenomination`, PARTAGÉE avec
            // `ContactsHubController::applySearch` (`G41-002`, même défaut) —
            // patron `A-011` : on l'écrit une fois, les deux sites l'appellent.
            // Cette liste sert LA LISTE ENTREPRISES *et* LE HUB, donc les deux
            // sont corrigés d'un coup ici.
            AllowedFilter::callback('denomination', function (Builder $query, mixed $value): void {
                RechercheDenomination::appliquer($query, $value);
            }),
            // ⚠️ `postcode` reste en `partial` : il n'a ni colonne normalisée
            // ni index trigrammes, donc il balaie lui aussi. Réparer ça exige
            // une migration hors du périmètre de ce lot — c'est SIGNALÉ dans le
            // rapport, pas corrigé au passage.
            AllowedFilter::partial('postcode'),
            // Prospection internationale : cibler « les associations de
            // Roumanie » sans les mélanger aux 4,29 M de fiches françaises.
            AllowedFilter::exact('country_code'),
            AllowedFilter::exact('entity_nature'),
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

    /**
     * Applique une borne de date, en refusant SILENCIEUSEMENT ce qui n'est pas
     * une date.
     *
     * Pourquoi ne pas lever une erreur : ces filtres viennent de l'URL. Une
     * valeur abîmée (copier-coller, lien partagé, robot) doit rendre la liste
     * NON FILTRÉE, jamais une 500. Mais elle ne doit pas non plus filtrer au
     * hasard — d'où le rejet plutôt qu'une interprétation approximative.
     *
     * Une borne HAUTE porte sur la journée ENTIÈRE : « avant le 15 » inclut le
     * 15 au soir. L'inverse surprendrait tout le monde et ferait disparaître
     * les fiches du jour demandé.
     */
    private static function borneDate(Builder $query, string $colonne, mixed $value, string $operateur): void
    {
        $brut = is_string($value) ? trim($value) : '';
        if ($brut === '') {
            return;
        }

        try {
            $date = Carbon::parse($brut);
        } catch (\Throwable) {
            return;
        }

        $query->where($colonne, $operateur, $operateur === '<=' ? $date->endOfDay() : $date->startOfDay());
    }
}
