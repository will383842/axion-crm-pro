<?php

namespace App\Socle\Espace;

use App\Crm\Campagnes\Segments;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Les fonctions sensibles ouvertes, ESPACE PAR ESPACE (table `fonctions_espace`, ADR-08).
 *
 * Règle d'or : une fonction sans ligne est FERMÉE. Un espace inconnu, une
 * ligne absente, une valeur douteuse : on ferme, on n'ouvre jamais.
 */
final class FonctionsEspace
{
    private const PREFIXE_SEGMENT = 'segment.';

    /** Lecture des origines (API `/crm/origines/*`) : fermée sans ligne, ouverte espace par espace. */
    public const ORIGINES_LECTURE = 'origines.lecture';

    /** La fonction est-elle ouverte pour cet espace ? Sans ligne : non. */
    public static function ouverte(string $espaceId, string $fonction): bool
    {
        return DB::table('fonctions_espace')
            ->where('espace_id', $espaceId)
            ->where('fonction', $fonction)
            ->where('ouverte', true)
            ->exists();
    }

    /**
     * Les segments de campagne OUVERTS pour cet espace, dans l'ordre de `Segments::CONNUS`.
     *
     * @return list<string>
     */
    public static function segmentsOuverts(string $espaceId): array
    {
        /** @var list<string> $fonctions */
        $fonctions = DB::table('fonctions_espace')
            ->where('espace_id', $espaceId)
            ->where('ouverte', true)
            ->pluck('fonction')
            ->map(static fn ($f): string => (string) $f)
            ->all();

        return array_values(array_filter(
            Segments::CONNUS,
            static fn (string $segment): bool => in_array(self::PREFIXE_SEGMENT . $segment, $fonctions, true),
        ));
    }

    /**
     * Ouvre ou ferme un segment pour un espace. Un segment inconnu est refusé :
     * la liste des segments reste FERMÉE (`Segments::CONNUS`).
     */
    public static function regler(string $espaceId, string $segment, bool $ouvert): void
    {
        if (! in_array($segment, Segments::CONNUS, true)) {
            throw new InvalidArgumentException("Segment inconnu : « {$segment} ».");
        }

        DB::table('fonctions_espace')->updateOrInsert(
            ['espace_id' => $espaceId, 'fonction' => self::PREFIXE_SEGMENT . $segment],
            ['ouverte' => $ouvert, 'updated_at' => now()],
        );
    }
}
