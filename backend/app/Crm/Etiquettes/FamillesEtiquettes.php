<?php

namespace App\Crm\Etiquettes;

use App\Crm\Taxonomy;

/**
 * LA RÈGLE DE NOMMAGE DES ÉTIQUETTES (chantier 2, 2026-09-29).
 *
 * Toute étiquette appartient à UNE famille, et une seule. Quatre types :
 *
 *  1. GOUVERNÉE — `namespace:valeur`, namespace de la liste fermée
 *     `Taxonomy::TAG_NAMESPACES` (`src:`, `svc:`, `taille:`, `famille:`,
 *     `secteur:`…). Entre par le référentiel versionné (seeder) ou par une
 *     synchro qui la dérive d'une table (`EtiquettesFederation`).
 *  2. AUTOMATIQUE — `famille-valeur`, famille de la liste fermée `AUTOMATIQUES`
 *     ci-dessous (`sector-btp`, `metier-plombiers-chauffagistes`, `dept-38`…).
 *     Posée et retirée par la synchro (`AutoTaggerService`,
 *     `crm:referentiels:reclasser`) : elle REFLÈTE la fiche.
 *  3. IA — `kind = llm`, slug libre proposé par le modèle, catégorie `ia`.
 *  4. MANUELLE — `kind = manual` : le nom choisi à la main n'a pas de règle.
 *
 * Ce qui ne rentre dans aucune est « sans famille » : l'inventaire
 * (`crm:etiquettes:inventaire`) les compte, et la garde `FamillesEtiquettesTest`
 * rougit si l'automate en produit une.
 *
 * ── POURQUOI LES AUTOMATIQUES NE SONT PAS RENOMMÉES `sect:`/`geo:` ─────────
 *
 * Le référentiel de gouvernance annonçait `sect:btp`, `geo:dept-38` ; l'automate
 * a toujours produit `sector-btp`, `dept-38` (audit du 2026-09-28). Les
 * AUDIENCES enregistrées (`email_audiences.criteria`, champ `tags`) et la
 * console citent ces slugs par leur NOM : renommer 4,3 M de liens sans réécrire
 * ces audiences en même temps les ferait viser PERSONNE — ou, pour une audience
 * d'exclusion, TOUT LE MONDE. La règle retenue écrit donc ce qui existe
 * (famille-valeur pour l'automatique) au lieu de le déplacer ; un renommage
 * vers `sect:` reste possible plus tard, par une migration QUI RÉÉCRIT LES
 * AUDIENCES et qui est testée — jamais au passage d'un reclassement.
 */
final class FamillesEtiquettes
{
    /**
     * Familles AUTOMATIQUES : préfixe du slug => catégorie (`tags.category`).
     *
     * @var array<string, string>
     */
    public const AUTOMATIQUES = [
        'sector-' => 'sector',
        'metier-' => 'sector',
        'size-' => 'size',
        'region-' => 'geo',
        'dept-' => 'geo',
        'pays-' => 'geo',
        'implantation-' => 'geo',
        'nature-' => 'custom',
    ];

    public const TYPE_GOUVERNEE = 'gouvernee';

    public const TYPE_AUTOMATIQUE = 'automatique';

    public const TYPE_IA = 'ia';

    public const TYPE_MANUELLE = 'manuelle';

    public const TYPE_SANS_FAMILLE = 'sans_famille';

    /** Catégorie des étiquettes proposées par l'IA. */
    public const CATEGORIE_IA = 'ia';

    /**
     * La famille d'une étiquette.
     *
     * L'ordre compte : une étiquette MANUELLE reste manuelle même si son slug
     * ressemble à une famille automatique (c'est l'utilisateur qui l'a
     * nommée) ; une étiquette IA reste IA même si le modèle a proposé
     * « size-matters ».
     *
     * @return array{type: string, famille: ?string}
     */
    public static function famille(string $slug, string $kind): array
    {
        if ($kind === 'manual') {
            return ['type' => self::TYPE_MANUELLE, 'famille' => null];
        }
        if ($kind === 'llm') {
            return ['type' => self::TYPE_IA, 'famille' => 'ia'];
        }
        $namespace = self::namespace($slug);
        if ($namespace !== null && array_key_exists($namespace, Taxonomy::TAG_NAMESPACES)) {
            return ['type' => self::TYPE_GOUVERNEE, 'famille' => $namespace];
        }
        if ($namespace === null) {
            foreach (array_keys(self::AUTOMATIQUES) as $prefixe) {
                if (str_starts_with($slug, $prefixe) && strlen($slug) > strlen($prefixe)) {
                    return ['type' => self::TYPE_AUTOMATIQUE, 'famille' => rtrim($prefixe, '-')];
                }
            }
        }

        return ['type' => self::TYPE_SANS_FAMILLE, 'famille' => null];
    }

    /**
     * La catégorie ATTENDUE d'une étiquette de famille connue (null : pas de
     * règle — manuelle ou sans famille).
     */
    public static function categorieAttendue(string $slug, string $kind): ?string
    {
        $f = self::famille($slug, $kind);

        return match ($f['type']) {
            self::TYPE_IA => self::CATEGORIE_IA,
            self::TYPE_GOUVERNEE => Taxonomy::TAG_NAMESPACES[(string) $f['famille']] ?? null,
            self::TYPE_AUTOMATIQUE => self::AUTOMATIQUES[$f['famille'] . '-'] ?? null,
            default => null,
        };
    }

    /** Le namespace du slug (partie avant le premier « : »), ou null. */
    public static function namespace(string $slug): ?string
    {
        $position = strpos($slug, ':');

        return $position === false || $position === 0 ? null : substr($slug, 0, $position);
    }
}
