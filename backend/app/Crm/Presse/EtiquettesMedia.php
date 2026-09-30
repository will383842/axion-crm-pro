<?php

namespace App\Crm\Presse;

use App\Crm\Taxonomy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Les étiquettes AUTOMATIQUES d'une fiche de MÉDIA — une seule définition
 * (harmonisation des contacts presse, 2026-09-30).
 *
 * Rangées par namespace gouverné (`Taxonomy::TAG_NAMESPACES`) :
 *
 *   media-type:<type>     catégorie custom   (`Taxonomy::MEDIA_TYPES_ETIQUETTE`)
 *   media-zone:<zone>     catégorie geo      (`Taxonomy::MEDIA_ZONES`)
 *   media-theme:<thème>   catégorie custom   (thème éditorial, s'il est connu)
 *
 * Elles sont DÉRIVÉES des lignes `media` VIVANTES rattachées à la fiche
 * (`media.company_id`, `deleted_at IS NULL` — B10-016), et c'est
 * `AutoTaggerService::syncTags()` qui les pose et les retire, comme les
 * étiquettes des fédérations — donc :
 *   - une resynchro (enrichissement, import) ne les efface jamais tant que le
 *     média dit la même chose ;
 *   - elle ne touche JAMAIS une étiquette manuelle, verrouillée ou `src:` ;
 *   - une fiche sans média n'en désire aucune.
 *
 * ── La zone de diffusion : on n'invente RIEN ─────────────────────────────
 *
 * La zone vient de `media.diffusion_zone` SEULEMENT, telle que la source l'a
 * posée (catégorie ARCOM des radios, émissions nationales de Wikidata, kits
 * presse, listes importées). Une fiche dont aucun média n'a de zone porte
 * `media-zone:inconnue`.
 *
 * Écartés à dessein, parce qu'ils fabriqueraient une zone fausse :
 *   - le DÉPARTEMENT d'un titre CPPAP/SPEL ou d'une fiche Sirene est celui du
 *     SIÈGE de l'éditeur (un quotidien national édité à Paris porte « 75 ») :
 *     en déduire « départemental » classerait la presse nationale en presse
 *     locale. Le département du siège reste visable par l'étiquette `dept-XX`
 *     de la fiche ;
 *   - l'agrément CPPAP d'une agence est NATIONAL, sa couverture ne l'est pas
 *     forcément ;
 *   - une chaîne de télévision ARCOM n'a pas de catégorie de zone (seules les
 *     radios en ont une).
 */
final class EtiquettesMedia
{
    /** @var list<string> */
    public const NAMESPACES = ['media-type', 'media-zone', 'media-theme'];

    /** Longueur maximale de la valeur d'un thème dans un slug. */
    public const THEME_MAX = 40;

    /**
     * Les lignes `media` vivantes d'une fiche.
     *
     * @return list<\stdClass>
     */
    public static function lignes(int $companyId): array
    {
        return array_values(DB::table('media')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'media_type', 'diffusion_zone', 'editorial_theme'])
            ->all());
    }

    /**
     * Étiquettes désirées pour les médias d'une fiche (aucun média : aucune).
     *
     * @param  list<\stdClass>  $lignes
     * @return array<string, array{name: string, category: string}>
     */
    public static function desirees(array $lignes): array
    {
        if ($lignes === []) {
            return [];
        }

        $tags = [];
        $zones = [];
        foreach ($lignes as $m) {
            $type = self::typeEtiquette((string) ($m->media_type ?? ''));
            if ($type !== null) {
                $tags['media-type:' . $type] = [
                    'name' => 'Type de média : ' . (Taxonomy::MEDIA_TYPES_ETIQUETTE[$type] ?? $type),
                    'category' => Taxonomy::TAG_NAMESPACES['media-type'],
                ];
            }

            $zone = self::zone(is_string($m->diffusion_zone ?? null) ? $m->diffusion_zone : null);
            if ($zone !== 'inconnue') {
                $zones[$zone] = true;
            }

            $theme = is_string($m->editorial_theme ?? null) ? trim($m->editorial_theme) : '';
            $slugTheme = self::theme($theme);
            if ($slugTheme !== null) {
                $tags['media-theme:' . $slugTheme] = [
                    'name' => 'Thème éditorial : ' . mb_substr($theme, 0, 80),
                    'category' => Taxonomy::TAG_NAMESPACES['media-theme'],
                ];
            }
        }

        // `inconnue` seulement si AUCUN média de la fiche n'a de zone connue :
        // « nationale ET inconnue » ne dirait rien de plus que « nationale ».
        foreach ($zones === [] ? ['inconnue'] : array_keys($zones) as $zone) {
            $tags['media-zone:' . $zone] = [
                'name' => 'Zone de diffusion : ' . (Taxonomy::MEDIA_ZONES[$zone] ?? $zone),
                'category' => Taxonomy::TAG_NAMESPACES['media-zone'],
            ];
        }

        return $tags;
    }

    /** La valeur d'étiquette d'un `media.media_type`, ou null s'il est inconnu. */
    public static function typeEtiquette(string $mediaType): ?string
    {
        return Taxonomy::MEDIA_TYPE_VERS_ETIQUETTE[$mediaType] ?? null;
    }

    /**
     * La zone d'un `media.diffusion_zone` tel que les sources l'écrivent
     * (`national`, `régional`, `départemental`, `local`, avec ou sans accent,
     * au masculin ou au féminin) — `inconnue` pour tout le reste.
     */
    public static function zone(?string $diffusionZone): string
    {
        if ($diffusionZone === null) {
            return 'inconnue';
        }
        $v = Str::ascii(mb_strtolower(trim($diffusionZone)));

        return match ($v) {
            'national', 'nationale' => 'national',
            'regional', 'regionale' => 'regional',
            'departemental', 'departementale' => 'departemental',
            'local', 'locale' => 'local',
            default => 'inconnue',
        };
    }

    /**
     * La forme stockée dans `media.diffusion_zone` d'une zone du référentiel,
     * celle qu'écrivent déjà les importeurs (ARCOM, kits presse) : accentuée.
     */
    public static function zoneStockee(string $zone): ?string
    {
        return match ($zone) {
            'national' => 'national',
            'regional' => 'régional',
            'departemental' => 'départemental',
            'local' => 'local',
            default => null,
        };
    }

    /** Le slug d'un thème éditorial (`Économie` → `economie`), ou null. */
    public static function theme(string $theme): ?string
    {
        $slug = Str::slug($theme, '-');
        if ($slug === '') {
            return null;
        }

        return rtrim(substr($slug, 0, self::THEME_MAX), '-');
    }
}
