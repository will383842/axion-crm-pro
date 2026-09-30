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
 * actions de masse refusent de retirer.
 *
 * Depuis le 2026-09-28, `prospection:reclassify-size` et
 * `prospection:reclassify-sector` n'existent plus : le reclassement de masse
 * est `crm:referentiels:reclasser`, qui EXCLUT ces fiches (secteur, taille,
 * nature, région et étiquettes) — sauf `--inclure-protegees` (2026-09-29),
 * levée EXPLICITE et limitée au classement : les six colonnes de classement
 * et les étiquettes `sector-`/`size-`/`region-`, jamais un contact, une
 * coordonnée ni une fiche.
 *
 * Non couvert, sans gravité aujourd'hui :
 * `AudienceBuilderService::evaluateForCompany` (protégé via le waterfall).
 * `/coverage/enrich` et `bulk-enrich` empilent des jobs que le waterfall
 * refuse : la garde est au waterfall, pas en double dans chaque sélecteur.
 *
 * Les CONTACTS de ces fiches sont aussi hors de la purge de rétention
 * (`rgpd:purge-business-prospects`, 2026-09-29) : c'est l'ordre de Will du
 * 27/09, et la purge les visait sans le savoir (constat de l'audit du 28/09).
 *
 * RGPD — la protection ne fait JAMAIS obstacle au droit d'une personne :
 *  - ses fiches `contacts` s'effacent par les chemins habituels (non gardés) ;
 *  - une coordonnée nominative portée par la fiche ELLE-MÊME (`email_generic`,
 *    `phone` d'un président de club) s'efface par un UPDATE à NULL, que rien
 *    ici ne bloque ;
 *  - supprimer la fiche entière passe par la levée volontaire du déclencheur
 *    (`SET LOCAL app.autoriser_suppression_protegee = 'on'`, cf. migration
 *    `2026_09_27_000001`), plus une opposition dans `opt_out`.
 *
 * Lever la protection pour un traitement précis (enrichir ces fiches, les
 * cibler dans une campagne) est une DÉCISION de Will : elle se code alors
 * explicitement dans le chemin concerné, jamais en retirant le tag.
 */
final class FichesProtegees
{
    /** Organisateurs d'événements (source `evenements-pro`, 2026-09-27). */
    public const TAG_ORGANISATEURS = 'src:scraping-evenements-pro';

    /**
     * Fédérations et organisations professionnelles (source `federations-2026`,
     * chantier 3, 2026-09-29). Même règle que les organisateurs : Will a
     * INTERDIT de supprimer leurs contacts (27/09) — ni purge, ni
     * enrichissement automatique, ni reclassement, ni audience par défaut.
     */
    public const TAG_FEDERATIONS = 'src:scraping-federations-2026';

    /**
     * Participants du salon GOFAB 2026 (source `gofab-2026`, 2026-09-29). Will :
     * « il ne faut surtout pas perdre ces contacts » — même régime que les
     * organisateurs et les fédérations.
     */
    public const TAG_GOFAB = 'src:scraping-gofab-2026';

    /**
     * ⚠️ Chaque slug ajouté ici exige une NOUVELLE migration qui réinstalle le
     * déclencheur de la base (sa liste est figée) — `FichesProtegeesTest` lit la
     * fonction installée et rougit sinon.
     *
     * @var list<string>
     */
    public const TAGS = [
        self::TAG_ORGANISATEURS,
        self::TAG_FEDERATIONS,
        self::TAG_GOFAB,
    ];

    /**
     * Les fiches protégées dont la NATURE n'est jamais devinée : organisateurs
     * et fédérations, qui ne sont pas des sociétés commerciales même rattachés
     * à une fiche INSEE (chantier C, 2026-10-01).
     *
     * Les participants GOFAB n'y sont PAS : leur source les décrit comme des
     * « entreprises ordinaires » (migration `2026_09_30_000010`). Ils étaient
     * pris dans la règle par accident, parce qu'elle lisait `TAGS` entier quand
     * le tag GOFAB y a été ajouté — c'est l'une des deux raisons pour
     * lesquelles `--inclure-protegees` ne posait pas leur nature (l'autre :
     * ces fiches ne viennent pas de l'INSEE, et ne portent ni code NAF ni
     * catégorie juridique ; `crm:referentiels:combler-trous` s'en charge).
     *
     * @var list<string>
     */
    public const TAGS_NATURE_NON_DEVINEE = [
        self::TAG_ORGANISATEURS,
        self::TAG_FEDERATIONS,
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
     *
     * ⚠️ Les alias INTERNES (`fp_ct`, `fp_t`) sont réservés à cette condition.
     * Ils valaient `ct` et `t` jusqu'au 2026-09-28 : appelée avec
     * `'ct.company_id'` depuis une requête qui nomme elle aussi `company_tag`
     * `ct`, la sous-requête lisait SON propre `ct` — la condition devenait
     * `ct.company_id = ct.company_id`, c'est-à-dire « aucune fiche protégée
     * dans l'espace », et elle écartait TOUTES les lignes dès qu'une seule
     * fiche protégée existait (garde `ReclassementReferentielsTest`, S1).
     */
    /**
     * @param  list<string>|null  $tags  sous-ensemble de `TAGS` (défaut : tous)
     */
    public static function conditionSql(string $colonneId = 'companies.id', ?array $tags = null): string
    {
        $slugs = implode(', ', array_map(
            static fn (string $slug): string => "'" . str_replace("'", "''", $slug) . "'",
            $tags ?? self::TAGS,
        ));

        return 'NOT EXISTS (SELECT 1 FROM company_tag fp_ct JOIN tags fp_t ON fp_t.id = fp_ct.tag_id'
            . " WHERE fp_ct.company_id = {$colonneId} AND fp_t.slug IN ({$slugs}))";
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
