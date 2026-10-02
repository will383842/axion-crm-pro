<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\Sorts\Sort;

/**
 * Tri des MÉDIAS par nom « lisible » — finitions P2 de l'audit UX du 2026-10-02.
 *
 * Constat : trié par `name` brut, la liste des médias s'ouvrait sur des noms
 * qui commencent par un signe (« + Plus », « / Slash », « "Le Journal" »,
 * « 'Radio' ») : la collation place la ponctuation avant les lettres. Ces
 * noms remontaient en tête alors qu'on les cherche à leur première lettre.
 *
 * Le tri ignore donc les signes de TÊTE (tout ce qui n'est ni lettre ni
 * chiffre), puis départage par le nom brut et l'identifiant pour une pagination
 * stable. Les chiffres restent en tête (« 20 Minutes », « 01net ») : c'est
 * l'ordre attendu d'un annuaire.
 *
 * ⚠️ L'EXPRESSION EST AUSSI CELLE DE L'INDEX
 * `idx_media_tri_nom` (migration `2026_10_02_000020_media_tri_nom_index`).
 * Changer l'une sans l'autre ne casse rien à l'écran mais fait perdre l'index :
 * le planificateur ne reconnaît qu'une expression IDENTIQUE.
 */
final class TriNomMedia implements Sort
{
    public const EXPRESSION = "regexp_replace(media.name, '^[^[:alnum:]]+', '')";

    /** @param  Builder<\Illuminate\Database\Eloquent\Model>  $query */
    public function __invoke(Builder $query, bool $descending, string $property): void
    {
        $sens = $descending ? 'DESC' : 'ASC';

        $query->orderByRaw(self::EXPRESSION . ' ' . $sens)
            ->orderBy('media.name', $sens)
            ->orderBy('media.id', $sens);
    }
}
