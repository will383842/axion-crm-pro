<?php

namespace App\Crm\Opco;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L'IDCC ET L'OPCO D'UNE FICHE, EN LECTURE SEULE (lot O14) — jointure sur
 * `companies_opco`, sous la RLS de l'espace courant. Aucune écriture ici.
 */
final class LectureOpco
{
    /**
     * @return array{idcc: ?string, opco: ?string, opco_libelle: ?string, opco_gestion: ?string, opco_gestion_libelle: ?string, source: string, releve_le: ?string, mention: string}|null
     */
    public static function pourEntreprise(int $companyId, string $workspaceId): ?array
    {
        if (! Schema::hasTable('companies_opco')) {
            return null;
        }

        $ligne = DB::table('companies_opco')
            ->where('workspace_id', $workspaceId)
            ->where('company_id', $companyId)
            ->first(['idcc', 'opco', 'opco_gestion', 'source', 'releve_le']);

        return $ligne === null ? null : self::presenter($ligne);
    }

    /**
     * @return array{idcc: ?string, opco: ?string, opco_libelle: ?string, opco_gestion: ?string, opco_gestion_libelle: ?string, source: string, releve_le: ?string, mention: string}
     */
    public static function presenter(\stdClass $ligne): array
    {
        $releveLe = $ligne->releve_le !== null ? substr((string) $ligne->releve_le, 0, 10) : null;

        return [
            'idcc' => $ligne->idcc !== null ? (string) $ligne->idcc : null,
            'opco' => $ligne->opco,
            'opco_libelle' => $ligne->opco !== null ? (Opco::LIBELLES[$ligne->opco] ?? $ligne->opco) : null,
            'opco_gestion' => $ligne->opco_gestion,
            'opco_gestion_libelle' => $ligne->opco_gestion !== null ? (Opco::LIBELLES[$ligne->opco_gestion] ?? $ligne->opco_gestion) : null,
            'source' => (string) $ligne->source,
            'releve_le' => $releveLe,
            'mention' => self::mention((string) $ligne->source, $releveLe),
        ];
    }

    /** « source : France compétences (DSN de juillet 2026) » ou « source : saisie ». */
    public static function mention(string $source, ?string $releveLe): string
    {
        if ($source !== 'siro') {
            return 'source : saisie';
        }
        $mois = $releveLe !== null
            ? CarbonImmutable::parse($releveLe)->locale('fr')->isoFormat('MMMM YYYY')
            : 'mois inconnu';

        return "source : France compétences (DSN de {$mois})";
    }
}
