<?php

namespace App\Crm\Referentiels;

use App\Crm\Joignabilite\Joignabilite;
use App\Crm\Relations\RelationsProspection;
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
            // Fédérations (chantier 3, 2026-09-29) — table `federations`.
            self::liste('FAMILLES_FEDERATION', 'CleFamilleFederation', Taxonomy::FEDERATION_FAMILLES, 'Familles d\'organisation professionnelle — `federations.famille`.'),
            self::liste('NIVEAUX_FEDERATION', 'CleNiveauFederation', Taxonomy::FEDERATION_NIVEAUX, 'Niveaux — `federations.niveau`.'),
            self::liste('CONTACTABILITES', 'CleContactabilite', Taxonomy::FEDERATION_CONTACTABILITES, 'Contactabilité — `federations.contactabilite`.'),
            self::liste('CERTITUDES', 'CleCertitude', Taxonomy::FEDERATION_CERTITUDES, 'Certitude du classement — `federations.certitude`.'),
            self::liste('PERTINENCES', 'ClePertinence', Taxonomy::FEDERATION_PERTINENCES, 'Pertinence — `federations.pertinence`.'),
            self::liste('PARTENARIATS', 'ClePartenariat', Taxonomy::FEDERATION_PARTENARIATS, 'Démarche « partenariat » — `federations.partenariat`.'),
            // Métiers (chantier 2, 2026-09-29) — étiquette `metier-<code>`.
            self::liste('METIERS', 'CleMetier', Metiers::liste(), 'Métiers — étiquette automatique `metier-<code>` (depuis la sous-classe NAF rév. 2).'),
            "/** Préfixe du slug de l'étiquette d'un métier : `metier-` + code. */\n"
                . 'export const PREFIXE_ETIQUETTE_METIER = ' . self::chaine(EtiquettesClassement::PREFIXE_METIER) . ";\n",
            // Joignabilité (chantier D, 2026-10-01) — `companies.joignabilite`, `contacts.joignabilite`.
            self::liste('JOIGNABILITES', 'CleJoignabilite', Joignabilite::LIBELLES, 'Joignabilité calculée — `companies.joignabilite`, `contacts.joignabilite`.'),
            // Chantier B (2026-10-01) — le défaut d'exclusion des audiences de prospection.
            "/** Types de relation qu'une audience de PROSPECTION exclut par défaut (`RelationsProspection`). */\n"
                . 'export const RELATIONS_HORS_PROSPECTION = ['
                . implode(', ', array_map(static fn (string $v): string => self::chaine($v), RelationsProspection::HORS_PROSPECTION))
                . "] as const;\n",
            // Presse (harmonisation des contacts, 2026-09-30) — étiquettes `media-type:` / `media-zone:`.
            self::liste('TYPES_MEDIA', 'CleTypeMedia', Taxonomy::MEDIA_TYPES_ETIQUETTE, 'Types de média — étiquette automatique `media-type:<code>`.'),
            self::liste('ZONES_MEDIA', 'CleZoneMedia', Taxonomy::MEDIA_ZONES, 'Zones de diffusion — étiquette automatique `media-zone:<code>`.'),
            "/** Préfixes des étiquettes d'un média : type et zone de diffusion. */\n"
                . "export const PREFIXE_ETIQUETTE_TYPE_MEDIA = \"media-type:\";\n"
                . "export const PREFIXE_ETIQUETTE_ZONE_MEDIA = \"media-zone:\";\n",
            // Classement des médias (chantier F, 2026-10-01) — `media-theme:` / `media-public:` / `media-format:`.
            self::liste('THEMES_MEDIA', 'CleThemeMedia', Taxonomy::MEDIA_THEMES_CLASSES, 'Thèmes de média (lecture du site) — étiquette automatique `media-theme:<code>`.'),
            self::liste('PUBLICS_MEDIA', 'ClePublicMedia', Taxonomy::MEDIA_PUBLICS, 'Publics de média — étiquette automatique `media-public:<code>`.'),
            self::liste('FORMATS_MEDIA', 'CleFormatMedia', Taxonomy::MEDIA_FORMATS, 'Formats TV — étiquette automatique `media-format:<code>`.'),
            "/** Préfixes des étiquettes du classement d'un média : thème, public, format TV. */\n"
                . "export const PREFIXE_ETIQUETTE_THEME_MEDIA = \"media-theme:\";\n"
                . "export const PREFIXE_ETIQUETTE_PUBLIC_MEDIA = \"media-public:\";\n"
                . "export const PREFIXE_ETIQUETTE_FORMAT_MEDIA = \"media-format:\";\n",
        ];

        return <<<'TS'
            /**
             * FICHIER GÉNÉRÉ — NE PAS MODIFIER À LA MAIN.
             *
             * Source : `backend/app/Crm/Taxonomy.php` (référentiel unique, chantier 1),
             * et, pour les métiers, `backend/resources/referentiels/metiers.csv` (chantier 2).
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
