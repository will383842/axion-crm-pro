<?php

namespace App\Crm;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * LES FICHES QU'AUCUN AUTOMATISME NE TOUCHE — une SEULE définition.
 *
 * Les organisateurs d'événements (source `evenements-pro`) sont des
 * associations, des CCI, des clubs, des salons : aucun n'a de forme juridique
 * `5xxx`, la plupart n'ont pas de SIREN. Mesuré sur `main` le 2026-09-27 :
 *
 *  - `prospection:purge-non-commercial` les supprimait TOUTES
 *    (`legal_form IS NULL`), contacts compris par cascade — et 1 700 fiches
 *    pèsent trop peu pour déclencher son plafond de 30 % ;
 *  - `prospection:find-websites` les ramassait (`website_status` vaut
 *    `pending` par défaut), `prospection:enrich` aussi (`enriched_at IS NULL`),
 *    ainsi que tout chemin qui passe par `EnrichCompanyJob` (bulk-enrich,
 *    coverage, re-scrape mensuel, Google Places) ;
 *  - `prospection:reclassify-size` les classait « TPE » (`ELSE 'tpe'`) ;
 *  - le triage les passait `ready_for_outreach`, donc dans les audiences.
 *
 * Une consigne « ne pas lancer telle commande » ne protège rien : ce prédicat
 * est posé DANS chacun de ces chemins. Une fiche est protégée dès qu'elle
 * porte l'un des tags ci-dessous — posé verrouillé par l'ingestion, et que les
 * actions de masse refusent de retirer (`src:*`).
 *
 * Lever la protection pour un traitement précis (enrichir ces fiches, les
 * cibler dans une campagne) est une DÉCISION de Will : elle se code alors
 * explicitement dans le chemin concerné, jamais en retirant le tag.
 */
final class FichesProtegees
{
    /** @var list<string> */
    public const TAGS = [
        'src:scraping-evenements-pro',
    ];

    /**
     * Exclut les fiches protégées d'une requête sur `companies` (modifie la
     * requête en place).
     */
    public static function exclure(EloquentBuilder|QueryBuilder $query, string $colonneId = 'companies.id'): void
    {
        $query->whereNotExists(function (QueryBuilder $sub) use ($colonneId): void {
            $sub->selectRaw('1')
                ->from('company_tag')
                ->join('tags', 'tags.id', '=', 'company_tag.tag_id')
                ->whereColumn('company_tag.company_id', $colonneId)
                ->whereIn('tags.slug', self::TAGS);
        });
    }

    /**
     * La même condition, en SQL brut, pour les `UPDATE … WHERE` écrits à la
     * main. `$colonneId` n'est jamais une donnée utilisateur.
     */
    public static function conditionSql(string $colonneId = 'companies.id'): string
    {
        $slugs = implode(', ', array_map(
            static fn (string $slug): string => "'" . str_replace("'", "''", $slug) . "'",
            self::TAGS,
        ));

        return 'NOT EXISTS (SELECT 1 FROM company_tag ct JOIN tags t ON t.id = ct.tag_id'
            . " WHERE ct.company_id = {$colonneId} AND t.slug IN ({$slugs}))";
    }

    public static function estProtegee(int $companyId): bool
    {
        return DB::table('company_tag')
            ->join('tags', 'tags.id', '=', 'company_tag.tag_id')
            ->where('company_tag.company_id', $companyId)
            ->whereIn('tags.slug', self::TAGS)
            ->exists();
    }
}
