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
    /** La table existe (vu une fois par processus : plus de requête catalogue ensuite). */
    private static bool $tablePresente = false;

    /**
     * @return array{idcc: ?string, opco: ?string, opco_libelle: ?string, opco_gestion: ?string, opco_gestion_libelle: ?string, source: string, releve_le: ?string, mention: string}|null
     */
    public static function pourEntreprise(int $companyId, string $workspaceId): ?array
    {
        if (! self::$tablePresente) {
            if (! Schema::hasTable('companies_opco')) {
                return null;
            }
            self::$tablePresente = true;
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
        $mois = 'mois inconnu';
        if ($releveLe !== null) {
            // `locale()` est typé `static|string` : vérifié avant le formatage.
            $date = CarbonImmutable::parse($releveLe);
            $fr = $date->locale('fr');
            $mois = ($fr instanceof CarbonImmutable ? $fr : $date)->isoFormat('MMMM YYYY');
        }

        return "source : France compétences (DSN de {$mois})";
    }
}
