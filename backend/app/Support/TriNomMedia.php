<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\Sorts\Sort;

/**
 * Tri des MÉDIAS par nom « lisible » — finitions P2 de l'audit UX du 2026-10-02.
 *
 * Constat : trié par `name` brut, la liste des médias s'ouvrait sur des noms
 * qui commencent par un signe (« + Plus », « / Slash », « "Le Journal" »,
 * « 'Radio' ») : la collation place la ponctuation avant les lettres.
 *
 * La clé de tri est la fonction SQL `cle_tri_nom(name)` (migration
 * `2026_10_02_000030_media_tri_nom_index`) :
 *   1. `unaccent` D'ABORD — « École » devient « Ecole », « À la folie »
 *      « A la folie ». En production la base est en locale C, où `[:alnum:]`
 *      ne connaît que l'ASCII : retirer les signes AVANT d'ôter les accents
 *      effaçait aussi les lettres accentuées de tête (« École » triée
 *      « cole », avis A09 du 2026-10-02) ;
 *   2. retrait des signes de tête (tout ce qui n'est ni lettre ni chiffre) ;
 *   3. `lower` — en locale C, sans cela, toutes les minuscules passeraient
 *      après toutes les majuscules (« zoom » après « Zébra »).
 * Puis départage par le nom brut et l'identifiant : pagination stable. Les
 * chiffres restent en tête (« 20 Minutes ») : c'est l'ordre d'un annuaire.
 *
 * ⚠️ L'index `idx_media_tri_nom` est construit à partir de `expression()` :
 * une seule source pour l'ORDER BY et pour l'index. Le planificateur ne
 * reconnaît qu'une expression IDENTIQUE.
 */
final class TriNomMedia implements Sort
{
    /** Fonction SQL IMMUTABLE créée par la migration citée plus haut. */
    public const FONCTION = 'cle_tri_nom';

    /** L'expression de tri appliquée à une colonne (`media.name` ou `name`). */
    public static function expression(string $colonne): string
    {
        return self::FONCTION . '(' . $colonne . ')';
    }

    /** @param  Builder<Model>  $query */
    public function __invoke(Builder $query, bool $descending, string $property): void
    {
        $sens = $descending ? 'DESC' : 'ASC';

        $query->orderByRaw(self::expression('media.name') . ' ' . $sens)
            ->orderBy('media.name', $sens)
            ->orderBy('media.id', $sens);
    }
}
