<?php

namespace App\Crm\Presse;

use App\Crm\Taxonomy;
use Illuminate\Support\Facades\DB;

/**
 * UN « MÉDIA » DÉDUIT DU SEUL CODE NAF N'EST PAS UN MÉDIA — une seule
 * définition (constat en production du 2026-09-30).
 *
 * `media:extract-from-companies` a versé dans `media` (source `naf-extract`)
 * toute fiche Sirene dont le code NAF ressemble à de l'édition : c'est juste
 * pour 58.11 / 58.13 / 58.14 (livres, journaux, revues), 60.10 / 60.20 (radio,
 * télévision), 63.91 (agences de presse). C'est FAUX pour deux codes
 * fourre-tout :
 *
 *   63.12Z « Portails Internet »   → 5 026 lignes `portail_web`
 *   58.19Z « Autres activités d'édition » → 1 542 lignes `presse_autre`
 *
 * Sur 213 fiches existantes passées en presse par le premier passage réel de
 * `crm:presse:harmoniser --limite=500`, 79 portaient 63.12Z et 16 58.19Z :
 * des sociétés web et informatiques, de vrais PROSPECTS.
 *
 * ── La règle ────────────────────────────────────────────────────────────
 * Une fiche est un MÉDIA INCERTAIN quand, à la fois :
 *   1. elle a au moins une ligne `media` vivante, et TOUTES ses lignes `media`
 *      vivantes viennent de `naf-extract` — une seule ligne d'une vraie source
 *      presse (`cppap`, `spel`, `agence`, `press-kit`, `liste-presse`, `arcom`,
 *      `wikidata`…) suffit à en faire un média ;
 *   2. son code NAF (`naf`, tel que l'INSEE l'a donné, ou `naf_rev2`) commence
 *      par 63.12 ou 58.19 — ou, faute de tout code NAF sur la fiche, toutes ses
 *      lignes sont de type `portail_web` / `presse_autre`.
 *
 * On se fonde sur le NAF de la FICHE (ce que dit l'INSEE aujourd'hui) et sur
 * la SOURCE des lignes (ce qui a fait croire que c'est un média), jamais sur
 * le seul `media_type`, que d'autres importeurs ont pu reclasser.
 *
 * Une telle fiche ne devient JAMAIS presse automatiquement : elle garde sa
 * nature et sa relation, et porte l'étiquette automatique
 * `media-possible:a-verifier` (dérivée, posée et retirée par
 * `AutoTaggerService::syncTags`) — l'entrée du chantier F, qui tranchera par
 * la lecture du site.
 */
final class MediaIncertain
{
    public const SOURCE_NAF = 'naf-extract';

    /** Codes NAF (normalisés, sans point) qui ne suffisent pas à faire un média. */
    public const NAF_INCERTAINS = ['6312', '5819'];

    /** Types que `naf-extract` donne à ces codes (fiche sans code NAF). */
    public const TYPES_INCERTAINS = ['portail_web', 'presse_autre'];

    public const ETIQUETTE = 'media-possible:a-verifier';

    public const NOM_ETIQUETTE = 'Média possible : à vérifier (lecture du site)';

    /** La fiche est-elle un média incertain (règle ci-dessus) ? */
    public static function fiche(int $companyId): bool
    {
        return DB::table('companies as c')->where('c.id', $companyId)
            ->whereRaw(self::conditionSql('c.id', 'c'))
            ->exists();
    }

    /**
     * SQL : la fiche `$aliasFiche` (dont l'identifiant est `$colonneId`) est un
     * média incertain. Alias INTERNES réservés : `mi_a`, `mi_b`, `mi_c`. Aucun
     * argument n'est une donnée utilisateur.
     */
    public static function conditionSql(string $colonneId = 'companies.id', string $aliasFiche = 'companies'): string
    {
        $source = self::SOURCE_NAF;
        $motif = '^(' . implode('|', self::NAF_INCERTAINS) . ')';
        $types = "'" . implode("','", self::TYPES_INCERTAINS) . "'";
        $naf = "regexp_replace(upper(coalesce({$aliasFiche}.naf, '')), '[^0-9A-Z]', '', 'g')";
        $nafRev2 = "regexp_replace(upper(coalesce({$aliasFiche}.naf_rev2, '')), '[^0-9A-Z]', '', 'g')";

        return "(EXISTS (SELECT 1 FROM media mi_a WHERE mi_a.company_id = {$colonneId} AND mi_a.deleted_at IS NULL)"
            . " AND NOT EXISTS (SELECT 1 FROM media mi_b WHERE mi_b.company_id = {$colonneId} AND mi_b.deleted_at IS NULL"
            . " AND mi_b.source IS DISTINCT FROM '{$source}')"
            . " AND ({$naf} ~ '{$motif}' OR {$nafRev2} ~ '{$motif}'"
            . " OR ({$naf} = '' AND {$nafRev2} = ''"
            . " AND NOT EXISTS (SELECT 1 FROM media mi_c WHERE mi_c.company_id = {$colonneId} AND mi_c.deleted_at IS NULL"
            . " AND mi_c.media_type NOT IN ({$types})))))";
    }

    /**
     * Une ligne `media` SANS fiche (sa fiche d'origine a disparu) venue de
     * `naf-extract` avec un type incertain : l'harmonisation ne lui crée pas de
     * fiche de presse.
     */
    public static function ligneSansFiche(\stdClass $media): bool
    {
        return $media->company_id === null
            && $media->source === self::SOURCE_NAF
            && in_array($media->media_type, self::TYPES_INCERTAINS, true);
    }

    /**
     * L'étiquette désirée par une fiche (vide si elle n'est pas un média
     * incertain) — pour `AutoTaggerService::computeDesiredTags`, qui sait déjà
     * si la fiche a des lignes `media` vivantes (`$aDesMedias`).
     *
     * @return array<string, array{name: string, category: string}>
     */
    public static function desirees(int $companyId, bool $aDesMedias = true): array
    {
        // Une fiche sans ligne `media` (4,3 M sur 4,3 M, ou presque) n'est
        // jamais un média incertain : pas de requête de plus à chaque synchro.
        if (! $aDesMedias || ! self::fiche($companyId)) {
            return [];
        }

        return [self::ETIQUETTE => ['name' => self::NOM_ETIQUETTE, 'category' => Taxonomy::TAG_NAMESPACES['media-possible']]];
    }
}
