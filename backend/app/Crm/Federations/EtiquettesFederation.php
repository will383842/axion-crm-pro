<?php

namespace App\Crm\Federations;

use App\Crm\Taxonomy;
use Illuminate\Support\Facades\DB;

/**
 * Les étiquettes AUTOMATIQUES d'une organisation professionnelle — une seule
 * définition (chantier 2, partie utile au chantier 3, 2026-09-29).
 *
 * Rangées par namespace gouverné (`Taxonomy::TAG_NAMESPACES`) :
 *
 *   famille:<famille>              catégorie custom
 *   niveau:<niveau>                catégorie geo
 *   secteur:<secteur représenté>   catégorie sector (1 à 3)
 *   taille-adherents:<taille>      catégorie size (0 à 4)
 *   pertinence:<pertinence>        catégorie custom
 *   contactabilite:<valeur>        catégorie custom
 *
 * Elles sont DÉRIVÉES de la ligne `federations` de la fiche, et c'est
 * `AutoTaggerService::syncTags()` qui les pose et les retire, comme `sector-`,
 * `size-` ou `region-` — donc :
 *   - une resynchro (enrichissement, import) ne les efface jamais tant que la
 *     ligne dit la même chose, et retire l'ancienne quand elle change ;
 *   - elle ne touche JAMAIS une étiquette manuelle, verrouillée ou `src:`,
 *     même si son slug a la forme `famille:…` (règle de `syncTags`) ;
 *   - une entreprise ordinaire n'a pas de ligne `federations` : aucune de ces
 *     étiquettes n'est désirée pour elle, ses `sector-`/`size-`/`region-`/`dept-`
 *     ne bougent pas.
 *
 * Le secteur REPRÉSENTÉ a son propre namespace (`secteur:`) : il ne se
 * confond ni avec `sector-…` (le secteur de la fiche, `sector_main`) ni avec le
 * namespace `sect:` réservé au code NAF.
 */
final class EtiquettesFederation
{
    /** @var list<string> */
    public const NAMESPACES = ['famille', 'niveau', 'secteur', 'taille-adherents', 'pertinence', 'contactabilite'];

    /**
     * La ligne `federations` d'une fiche, ou null.
     */
    public static function ligne(int $companyId): ?\stdClass
    {
        $ligne = DB::table('federations')->where('company_id', $companyId)->first();

        return $ligne instanceof \stdClass ? $ligne : null;
    }

    /**
     * Étiquettes désirées pour une ligne `federations` (null : aucune).
     *
     * @return array<string, array{name: string, category: string}>
     */
    public static function desirees(?\stdClass $ligne): array
    {
        if ($ligne === null) {
            return [];
        }

        $tags = [];
        self::ajouter($tags, 'famille', (string) $ligne->famille, 'Famille', Taxonomy::FEDERATION_FAMILLES);
        self::ajouter($tags, 'niveau', (string) $ligne->niveau, 'Niveau', Taxonomy::FEDERATION_NIVEAUX);
        foreach (self::tableau($ligne->secteurs ?? null) as $secteur) {
            self::ajouter($tags, 'secteur', $secteur, 'Secteur représenté', Taxonomy::SECTEURS);
        }
        foreach (self::tableau($ligne->tailles_adherents ?? null) as $taille) {
            self::ajouter($tags, 'taille-adherents', $taille, 'Adhérents', Taxonomy::TAILLES);
        }
        self::ajouter($tags, 'pertinence', (string) $ligne->pertinence, 'Pertinence', Taxonomy::FEDERATION_PERTINENCES);
        self::ajouter($tags, 'contactabilite', (string) $ligne->contactabilite, 'Contactabilité', Taxonomy::FEDERATION_CONTACTABILITES);

        return $tags;
    }

    public static function slug(string $namespace, string $valeur): string
    {
        return $namespace . ':' . strtolower(str_replace('_', '-', trim($valeur)));
    }

    /**
     * Un tableau PostgreSQL `TEXT[]` tel que PDO le rend (`{btp,sante}`), ou
     * déjà un tableau PHP.
     *
     * @return list<string>
     */
    public static function tableau(mixed $valeur): array
    {
        if (is_array($valeur)) {
            return array_values(array_filter(array_map('strval', $valeur), static fn (string $v): bool => $v !== ''));
        }
        if (! is_string($valeur) || $valeur === '' || $valeur === '{}') {
            return [];
        }

        // Les valeurs sont des clés de référentiel ([a-z_]) : jamais de
        // guillemet ni de virgule à échapper.
        $interieur = trim($valeur, '{}');

        return array_values(array_filter(
            array_map(static fn (string $v): string => trim($v, ' "'), explode(',', $interieur)),
            static fn (string $v): bool => $v !== '',
        ));
    }

    /**
     * Rend un tableau PHP de clés au format littéral PostgreSQL `{a,b}`.
     *
     * @param  list<string>  $valeurs
     */
    public static function litteral(array $valeurs): string
    {
        foreach ($valeurs as $v) {
            if (preg_match('/^[a-z0-9_]+$/', $v) !== 1) {
                // Garde : seules des clés de référentiel entrent ici.
                throw new \InvalidArgumentException('valeur_de_tableau_invalide');
            }
        }

        return '{' . implode(',', $valeurs) . '}';
    }

    /**
     * @param  array<string, array{name: string, category: string}>  $tags
     * @param  array<int|string, string>  $libelles
     */
    private static function ajouter(array &$tags, string $namespace, string $valeur, string $prefixe, array $libelles): void
    {
        if ($valeur === '') {
            return;
        }

        $tags[self::slug($namespace, $valeur)] = [
            'name' => $prefixe . ' : ' . ($libelles[$valeur] ?? $valeur),
            'category' => Taxonomy::TAG_NAMESPACES[$namespace],
        ];
    }
}
