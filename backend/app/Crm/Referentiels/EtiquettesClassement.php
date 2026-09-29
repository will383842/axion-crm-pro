<?php

namespace App\Crm\Referentiels;

/**
 * Les étiquettes AUTOMATIQUES qui reflètent le classement d'une fiche :
 * `sector-…`, `size-…`, `region-…`, et depuis le chantier 2 (2026-09-29)
 * `metier-…` (le métier lu dans la sous-classe NAF rév. 2, `Metiers`).
 *
 * Une seule définition, lue par la synchro fiche par fiche
 * (`AutoTaggerService`, à l'enrichissement) ET par le reclassement de masse
 * (`crm:referentiels:reclasser`) : les deux chemins posent donc exactement les
 * mêmes étiquettes, avec les mêmes noms. C'est leur divergence qui laissait
 * 772 k fiches « commerce » pour 147 k étiquettes.
 *
 * Le format des slugs (`sector-btp`, `metier-plombiers-chauffagistes` :
 * famille, tiret, valeur) est la règle des étiquettes AUTOMATIQUES
 * (`App\Crm\Etiquettes\FamillesEtiquettes`). Il n'est PAS renommé en
 * `sect:`/`taille:`/`geo:` : des audiences enregistrées citent ces slugs, et
 * les renommer sans migrer ces audiences les viderait (chantier 2, décision
 * écrite dans `FamillesEtiquettes`).
 */
final class EtiquettesClassement
{
    /** Préfixes des familles tenues par cette classe. */
    public const PREFIXES = ['sector-', 'size-', 'region-', 'metier-'];

    /** Préfixe des étiquettes de métier (lu aussi par l'écran, via l'export). */
    public const PREFIXE_METIER = 'metier-';

    public static function slugSecteur(string $secteur): string
    {
        return 'sector-' . self::segment($secteur);
    }

    public static function slugTaille(string $taille): string
    {
        return 'size-' . self::segment($taille);
    }

    public static function slugRegion(string $region): string
    {
        return 'region-' . self::segment($region);
    }

    public static function slugMetier(string $metier): string
    {
        return self::PREFIXE_METIER . self::segment($metier);
    }

    /**
     * Étiquettes désirées pour un classement donné.
     *
     * `$nafRev2` est OBLIGATOIRE (null quand la fiche n'en a pas) : un appelant
     * qui l'oublierait ne désirerait plus aucune étiquette `metier-`, et sa
     * synchro les RETIRERAIT toutes — c'est exactement l'écart
     * fiche/étiquette que ce référentiel unique supprime.
     *
     * @return array<string, array{name: string, category: string}>
     */
    public static function desirees(?string $secteur, ?string $taille, ?string $region, ?string $nafRev2): array
    {
        $tags = [];
        if (is_string($secteur) && $secteur !== '') {
            $tags[self::slugSecteur($secteur)] = [
                'name' => 'Secteur : ' . Classement::libelleSecteur($secteur),
                'category' => 'sector',
            ];
        }
        if (is_string($taille) && $taille !== '') {
            $tags[self::slugTaille($taille)] = [
                'name' => 'Taille : ' . Classement::libelleTaille($taille),
                'category' => 'size',
            ];
        }
        if (is_string($region) && $region !== '') {
            $tags[self::slugRegion($region)] = [
                'name' => 'Région : ' . Classement::libelleRegion($region),
                'category' => 'geo',
            ];
        }
        // Le métier : catégorie `sector`, la plus proche (c'est un secteur
        // plus fin). Une sous-classe sans métier n'en désire aucun.
        $metier = Metiers::pourNafRev2($nafRev2);
        if ($metier !== null) {
            $tags[self::slugMetier($metier)] = [
                'name' => 'Métier : ' . Metiers::libelle($metier),
                'category' => 'sector',
            ];
        }

        return $tags;
    }

    /** Le slug appartient-il à l'une des familles tenues ici ? */
    public static function estDeLaFamille(string $slug): bool
    {
        foreach (self::PREFIXES as $prefixe) {
            if (str_starts_with($slug, $prefixe)) {
                return true;
            }
        }

        return false;
    }

    private static function segment(string $valeur): string
    {
        return strtolower(str_replace('_', '-', trim($valeur)));
    }
}
