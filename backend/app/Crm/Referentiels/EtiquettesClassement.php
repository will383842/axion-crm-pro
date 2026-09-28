<?php

namespace App\Crm\Referentiels;

/**
 * Les étiquettes AUTOMATIQUES qui reflètent le classement d'une fiche :
 * `sector-…`, `size-…`, `region-…`.
 *
 * Une seule définition, lue par la synchro fiche par fiche
 * (`AutoTaggerService`, à l'enrichissement) ET par le reclassement de masse
 * (`crm:referentiels:reclasser`) : les deux chemins posent donc exactement les
 * mêmes étiquettes, avec les mêmes noms. C'est leur divergence qui laissait
 * 772 k fiches « commerce » pour 147 k étiquettes.
 *
 * Le format des slugs (`sector-btp`, sans namespace) est celui qui existe en
 * base ; le ranger sous les namespaces gouvernés (`sect:`, `taille:`, `geo:`)
 * est le chantier 2 (« étiquettes rangées »), pas celui-ci.
 */
final class EtiquettesClassement
{
    /** Préfixes des familles tenues par cette classe. */
    public const PREFIXES = ['sector-', 'size-', 'region-'];

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

    /**
     * Étiquettes désirées pour un classement donné.
     *
     * @return array<string, array{name: string, category: string}>
     */
    public static function desirees(?string $secteur, ?string $taille, ?string $region): array
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
