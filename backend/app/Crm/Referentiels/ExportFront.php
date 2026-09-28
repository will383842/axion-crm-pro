<?php

namespace App\Crm\Referentiels;

use App\Crm\Taxonomy;

/**
 * Le référentiel, tel que l'écran le lit : `frontend/src/lib/referentiels.generated.ts`.
 *
 * Le frontend recopiait ces listes À LA MAIN — et elles avaient divergé :
 * le filtre Nature proposait « Autres » (valeur inexistante en base) et en
 * oubliait cinq ; le constructeur d'audiences ne connaissait que 10 secteurs
 * sur 15 et des tailles que la collecte ne produisait pas. Le fichier est
 * désormais GÉNÉRÉ depuis `Taxonomy` (`php artisan crm:referentiels:generer-front`)
 * et la garde `ReferentielsFrontTest` rougit s'il n'est plus à jour.
 *
 * Pourquoi un fichier généré plutôt qu'une route d'API : ces listes sont
 * CONSTANTES entre deux déploiements, et l'écran en a besoin de façon
 * synchrone (types TypeScript, pastilles, filtres) — une requête de plus à
 * chaque écran n'apporterait rien, sinon un état « chargement » à gérer.
 */
final class ExportFront
{
    public const CHEMIN_RELATIF = 'frontend/src/lib/referentiels.generated.ts';

    public static function chemin(): string
    {
        // backend/app/Crm/Referentiels → racine du dépôt.
        return dirname(__DIR__, 4) . '/' . self::CHEMIN_RELATIF;
    }

    public static function contenu(): string
    {
        $blocs = [
            self::liste('SECTEURS', 'CleSecteur', Taxonomy::SECTEURS, 'Secteurs d\'activité — `companies.sector_main`.'),
            self::liste('TAILLES', 'CleTaille', Taxonomy::TAILLES, 'Tailles — `companies.size_category`.'),
            self::liste('NATURES', 'CleNature', Taxonomy::ENTITY_NATURES, 'Natures d\'entité — `companies.entity_nature`.'),
            self::liste('REGIONS', 'CodeRegion', Taxonomy::REGIONS, 'Régions (code INSEE) — `companies.region_code`, `events.region`.'),
        ];

        return <<<'TS'
            /**
             * FICHIER GÉNÉRÉ — NE PAS MODIFIER À LA MAIN.
             *
             * Source : `backend/app/Crm/Taxonomy.php` (référentiel unique, chantier 1).
             * Régénérer : `php artisan crm:referentiels:generer-front` (depuis `backend/`).
             * Garde : `backend/tests/Unit/Crm/ReferentielsFrontTest.php` rougit si ce
             * fichier diffère de ce que la commande produirait.
             */

            export interface EntreeReferentiel {
              readonly code: string;
              readonly libelle: string;
            }

            TS . "\n" . implode("\n", $blocs);
    }

    /**
     * @param  array<int|string, string>  $valeurs
     */
    private static function liste(string $nom, string $type, array $valeurs, string $doc): string
    {
        $lignes = [];
        foreach ($valeurs as $code => $libelle) {
            $lignes[] = '  { code: ' . self::chaine((string) $code) . ', libelle: ' . self::chaine($libelle) . ' },';
        }

        return "/** {$doc} */\n"
            . "export const {$nom} = [\n"
            . implode("\n", $lignes) . "\n"
            . "] as const satisfies readonly EntreeReferentiel[];\n\n"
            . "export type {$type} = (typeof {$nom})[number]['code'];\n";
    }

    private static function chaine(string $valeur): string
    {
        return (string) json_encode($valeur, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
