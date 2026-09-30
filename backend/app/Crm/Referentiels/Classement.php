<?php

namespace App\Crm\Referentiels;

use App\Crm\Taxonomy;
use Illuminate\Support\Str;

/**
 * LE calcul du classement d'une organisation — secteur, taille, nature, région.
 *
 * Une seule définition, appelée par TOUS les chemins qui écrivent ces colonnes :
 *
 *   - la collecte INSEE (`prospection:collect`) ;
 *   - l'enrichissement (`AutoClassifierService`, étape 10b du waterfall) ;
 *   - le reclassement de masse (`crm:referentiels:reclasser`) ;
 *   - l'import des événements (région).
 *
 * C'est ce qui garantit que l'enrichissement ne « rebascule » plus une fiche
 * dans un autre secteur que celui de la collecte : ils font le même calcul, sur
 * la même donnée. Les listes de valeurs sont dans `Taxonomy`.
 */
final class Classement
{
    /** Tranches d'effectif INSEE de 10 à 249 salariés. */
    private const TRANCHES_PME = ['11', '12', '21', '22', '31'];

    /** Tranches d'effectif INSEE de 250 à 4 999 salariés. */
    private const TRANCHES_ETI = ['32', '41', '42', '51'];

    /** Tranches d'effectif INSEE de 5 000 salariés et plus. */
    private const TRANCHES_GRAND_GROUPE = ['52', '53'];

    public static function secteur(?string $naf): string
    {
        return NomenclatureNaf::secteur($naf);
    }

    /**
     * Taille depuis les données INSEE : la catégorie OFFICIELLE (calculée sur
     * tout le groupe : effectif, chiffre d'affaires, bilan) d'abord, la tranche
     * d'effectif du siège ensuite. Sans aucune des deux : TPE — c'est le cas
     * de l'immense majorité des unités sans salarié renseigné.
     *
     * Reprend à l'identique la règle qu'appliquait la collecte depuis août ;
     * l'enrichissement, lui, rangeait 10-49 salariés en « tpe » et 250-499 en
     * « pme » : c'était faux au sens INSEE, et c'est cette divergence qui
     * disparaît ici.
     */
    public static function tailleDepuisInsee(?string $tranche, ?string $categorie): string
    {
        $cat = strtoupper(trim((string) $categorie));
        $t = trim((string) $tranche);

        if ($cat === 'GE') {
            return 'grand_groupe';
        }
        if ($cat === 'ETI') {
            return 'eti';
        }
        $avecSalaries = in_array($t, self::TRANCHES_PME, true);
        if ($cat === 'PME') {
            return $avecSalaries ? 'pme' : 'tpe';
        }

        return match (true) {
            $avecSalaries => 'pme',
            in_array($t, self::TRANCHES_ETI, true) => 'eti',
            in_array($t, self::TRANCHES_GRAND_GROUPE, true) => 'grand_groupe',
            default => 'tpe',
        };
    }

    /**
     * Taille d'une fiche existante. Avec une donnée INSEE (tranche ou
     * catégorie) : le calcul INSEE. Sans : la valeur actuelle, traduite dans
     * le vocabulaire unique — JAMAIS « tpe » par défaut, sinon une CCI ou une
     * association importée sans effectif deviendrait une TPE.
     */
    public static function taille(?string $tranche, ?string $categorie, ?string $actuelle): ?string
    {
        if (self::renseigne($tranche) || self::renseigne($categorie)) {
            return self::tailleDepuisInsee($tranche, $categorie);
        }

        // Une fiche venue du site peut porter une tranche déclarée (`2-5`) :
        // on la traduit plutôt que de la perdre.
        return self::tailleDeclaree($actuelle);
    }

    /**
     * Traduit une valeur de taille (y compris les anciens vocabulaires) dans
     * le référentiel ; null si elle n'y correspond pas.
     */
    public static function normaliserTaille(?string $valeur): ?string
    {
        $v = strtolower(trim((string) $valeur));
        if ($v === '') {
            return null;
        }
        if (array_key_exists($v, Taxonomy::TAILLES)) {
            return $v;
        }

        return Taxonomy::TAILLES_ANCIENNES[$v] ?? null;
    }

    /**
     * Taille DÉCLARÉE par un formulaire du site. Deux formes y circulent :
     * les clés `tpe`/`pme`/`eti`/`grande_entreprise` (formulaire de contact) et
     * des tranches d'effectif `1`, `2-5`, `6-10`, `11-20`, `21-50`, `51-100`,
     * `100+` (simulateur de gains). Une tranche se range par sa BORNE BASSE,
     * aux seuils INSEE (10, 250, 5 000). Tout le reste : null — la valeur
     * n'est pas écrite plutôt que de créer un vocabulaire de plus.
     */
    public static function tailleDeclaree(?string $valeur): ?string
    {
        $connue = self::normaliserTaille($valeur);
        if ($connue !== null) {
            return $connue;
        }
        if (preg_match('/^\s*(\d{1,6})\s*(?:[-–]\s*\d{1,6}|\+)?\s*$/u', (string) $valeur, $m) !== 1) {
            return null;
        }
        $basse = (int) $m[1];

        return match (true) {
            $basse < 10 => 'tpe',
            $basse < 250 => 'pme',
            $basse < 5000 => 'eti',
            default => 'grand_groupe',
        };
    }

    /**
     * Nature d'une fiche : celle qu'elle porte ; à défaut, `entreprise` pour
     * une fiche venue de l'INSEE (la collecte ne ramène que des sociétés
     * commerciales, formes juridiques 5xxx). Rien d'autre n'est deviné.
     */
    public static function nature(?string $actuelle, ?string $source): ?string
    {
        if (is_string($actuelle) && array_key_exists($actuelle, Taxonomy::ENTITY_NATURES)) {
            return $actuelle;
        }
        if ($actuelle === null && $source === 'insee') {
            return 'entreprise';
        }

        return $actuelle;
    }

    /**
     * Code INSEE de région depuis ce qu'on nous donne : un code (« 84 »), un
     * sigle (« AURA », « IDF ») ou un libellé (« Auvergne-Rhône-Alpes »,
     * accents et casse indifférents). Null si rien ne correspond.
     */
    public static function region(?string $valeur): ?string
    {
        $v = trim((string) $valeur);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d{2}$/', $v) === 1 && array_key_exists($v, Taxonomy::REGIONS)) {
            return $v;
        }
        // Code à un chiffre (« 1 » pour la Guadeloupe) : zéro de tête perdu.
        if (preg_match('/^\d$/', $v) === 1 && array_key_exists('0' . $v, Taxonomy::REGIONS)) {
            return '0' . $v;
        }
        $sigle = strtoupper($v);
        if (array_key_exists($sigle, Taxonomy::REGIONS_SIGLES)) {
            return Taxonomy::REGIONS_SIGLES[$sigle];
        }
        $cle = Str::slug($v);
        foreach (Taxonomy::REGIONS as $code => $libelle) {
            if (Str::slug($libelle) === $cle) {
                // Clé « 84 » devenue entier par PHP : on rend TOUJOURS une chaîne.
                return (string) $code;
            }
        }

        return null;
    }

    /**
     * Les notes d'un événement, complétées de la région lue mais non reconnue
     * (« England »). Idempotent : une note qui porte déjà la ligne n'en reçoit
     * pas une seconde — la migration et chaque réimport peuvent la rejouer.
     */
    public static function noteRegionDOrigine(?string $notes, string $regionLue): string
    {
        $ligne = 'Région d\'origine : ' . trim($regionLue);
        $notes = trim((string) $notes);
        if ($notes === '') {
            return $ligne;
        }

        return str_contains($notes, $ligne) ? $notes : $notes . "\n" . $ligne;
    }

    public static function regionDuDepartement(?string $departement): ?string
    {
        $d = strtoupper(trim((string) $departement));

        return $d === '' ? null : (Taxonomy::REGION_PAR_DEPARTEMENT[$d] ?? null);
    }

    /**
     * Département d'un code postal FRANÇAIS à 5 chiffres (null sinon) — la
     * règle de `AutoClassifierService` : Corse 200xx-201xx = 2A, 202xx-206xx =
     * 2B ; outre-mer (97x, 98x) sur trois chiffres ; ailleurs les deux premiers.
     *
     * Un code postal peut desservir une commune d'un département voisin : le
     * département ainsi lu ne sert qu'à en déduire la RÉGION, jamais à être
     * écrit comme département.
     */
    public static function departementDuCodePostal(?string $codePostal): ?string
    {
        $cp = preg_replace('/\s+/', '', (string) $codePostal) ?? '';
        if (preg_match('/^\d{5}$/', $cp) !== 1) {
            return null;
        }
        $deux = substr($cp, 0, 2);
        if ($deux === '20') {
            return (int) $cp[2] <= 1 ? '2A' : '2B';
        }
        if ($deux === '97' || $deux === '98') {
            return substr($cp, 0, 3);
        }

        return $deux;
    }

    /**
     * Région d'une fiche FRANÇAISE depuis ses données : le département, sinon
     * le code postal. Null si ni l'un ni l'autre ne la donne — rien n'est
     * deviné au-delà (une collectivité d'outre-mer sans région, un code
     * inconnu, une fiche sans adresse restent sans région).
     *
     * @return array{0: ?string, 1: ?string} [région, 'departement'|'code_postal'|null]
     */
    public static function regionFrancaise(?string $departement, ?string $codePostal): array
    {
        $parDepartement = self::regionDuDepartement($departement);
        if ($parDepartement !== null) {
            return [$parDepartement, 'departement'];
        }
        $parCodePostal = self::regionDuDepartement(self::departementDuCodePostal($codePostal));

        return $parCodePostal !== null ? [$parCodePostal, 'code_postal'] : [null, null];
    }

    /**
     * Nature DÉDUITE d'une fiche qui n'en a pas (chantier C, 2026-10-01), avec
     * le motif — ou [null, null] : rien n'est deviné. Dans cet ordre :
     *
     *  1. la catégorie juridique INSEE (`legal_form`) quand elle tranche :
     *     `92xx` (associations loi 1901) → `association` ; `5xxx` (sociétés
     *     commerciales) et `1xxx` (entrepreneur individuel) → `entreprise` ;
     *  2. le code NAF quand il désigne une organisation : 94.11Z (organisations
     *     patronales et consulaires), 94.12Z (professionnelles), 94.20Z
     *     (syndicats de salariés) → `federation` ; 94.99Z (organisations
     *     fonctionnant par adhésion volontaire) → `association` ; 84.xx
     *     (administration publique) → `institution` ;
     *  3. un SIREN, sans catégorie juridique qui dise autre chose, et sans code
     *     NAF d'organisation (84, 94, 99) → `entreprise` : une fiche immatriculée
     *     au répertoire SIRENE dont rien ne dit qu'elle n'est pas une société.
     *
     * Une catégorie juridique CONNUE et non tranchée (droit public 7xxx, autre
     * personne morale 6xxx, 8xxx, 9xxx hors 92) n'est pas surchargée par la
     * règle du SIREN : la fiche reste sans nature, et elle est comptée.
     *
     * N'est PAS appelée par la collecte, l'enrichissement ni le reclassement
     * (`nature()` y reste la règle « INSEE → entreprise ») : seulement par
     * `crm:referentiels:combler-trous`, sur demande, essai à blanc d'abord.
     *
     * @return array{0: ?string, 1: ?string} [nature, 'forme_juridique'|'naf'|'siren'|null]
     */
    public static function natureDeduite(?string $formeJuridique, ?string $naf, ?string $nafRev2, ?string $siren): array
    {
        $forme = preg_replace('/\D/', '', (string) $formeJuridique) ?? '';
        if ($forme !== '') {
            if (str_starts_with($forme, '92')) {
                return ['association', 'forme_juridique'];
            }
            if ($forme[0] === '5' || $forme[0] === '1') {
                return ['entreprise', 'forme_juridique'];
            }
        }

        $code = trim((string) $nafRev2);
        if ($code === '' && trim((string) $naf) !== '') {
            $code = (string) (NomenclatureNaf::classer($naf)->codeRev2 ?? '');
        }
        $parNaf = match (true) {
            in_array($code, ['94.11Z', '94.12Z', '94.20Z'], true) => 'federation',
            $code === '94.99Z' => 'association',
            str_starts_with($code, '84.') => 'institution',
            default => null,
        };
        if ($parNaf !== null) {
            return [$parNaf, 'naf'];
        }

        $nafOrganisation = str_starts_with($code, '84.') || str_starts_with($code, '94.') || str_starts_with($code, '99.');
        $sirenValide = preg_match('/^\d{9}$/', trim((string) $siren)) === 1;
        if ($sirenValide && $forme === '' && ! $nafOrganisation) {
            return ['entreprise', 'siren'];
        }

        return [null, null];
    }

    public static function libelleSecteur(string $cle): string
    {
        return Taxonomy::SECTEURS[$cle] ?? $cle;
    }

    public static function libelleTaille(string $cle): string
    {
        return Taxonomy::TAILLES[$cle] ?? $cle;
    }

    public static function libelleRegion(string $code): string
    {
        return Taxonomy::REGIONS[$code] ?? $code;
    }

    /**
     * Le classement COMPLET d'une fiche, tel que le reclassement de masse et
     * l'enrichissement l'écrivent.
     *
     * Une chaîne vide (`''`) vaut « absent » partout : elle n'est ni un code
     * NAF, ni une taille, ni une région. Le calcul rend alors null, et c'est à
     * l'appelant de décider s'il remplace `''` par NULL (le reclassement le
     * fait : `''` n'est pas une valeur du référentiel).
     *
     * @param  array{naf?: ?string, effectif_range?: ?string, categorie_entreprise?: ?string,
     *               size_category?: ?string, entity_nature?: ?string, discovery_source?: ?string,
     *               department_code?: ?string, region_code?: ?string, country_code?: ?string,
     *               sector_main?: ?string}  $fiche
     * @return array{sector_main: string, naf_nomenclature: ?string, naf_rev2: ?string,
     *               size_category: ?string, entity_nature: ?string, region_code: ?string, methode_secteur: string}
     */
    public static function pourFiche(array $fiche): array
    {
        $lire = static function (string $cle) use ($fiche): ?string {
            $v = trim((string) ($fiche[$cle] ?? ''));

            return $v === '' ? null : $v;
        };

        $naf = NomenclatureNaf::classer($lire('naf'));

        $regionActuelle = $lire('region_code');
        $pays = strtoupper($lire('country_code') ?? 'FR');
        // Le département n'est un code INSEE que pour une fiche française : un
        // « 01 » roumain n'est pas l'Ain.
        $region = $pays === 'FR'
            ? (self::regionDuDepartement($lire('department_code')) ?? self::region($regionActuelle) ?? $regionActuelle)
            : $regionActuelle;

        return [
            'sector_main' => self::secteurRetenu($naf->secteur, $lire('sector_main')),
            'naf_nomenclature' => $naf->nomenclature,
            'naf_rev2' => $naf->codeRev2,
            'size_category' => self::taille($lire('effectif_range'), $lire('categorie_entreprise'), $lire('size_category')),
            'entity_nature' => self::nature($lire('entity_nature'), $lire('discovery_source')),
            'region_code' => $region,
            'methode_secteur' => $naf->methode,
        ];
    }

    /**
     * Le secteur à écrire, connaissant celui que porte déjà la fiche.
     *
     * Le code NAF décide TOUJOURS, sauf quand il ne dit rien (`non_classe` :
     * pas de code, code d'attente, organisation professionnelle NAF 94). Dans
     * ce cas seulement, un secteur déjà posé qui est une clé VALIDE du
     * référentiel est conservé : c'est lui qui porte `interprofessionnel` (posé
     * par le modèle fédérations) ou le secteur représenté d'un syndicat. Un
     * secteur vide ou hors référentiel (`it_saas`, `autre`…) devient
     * `non_classe`.
     */
    public static function secteurRetenu(string $depuisNaf, ?string $actuel): string
    {
        if ($depuisNaf !== Taxonomy::SECTEUR_NON_CLASSE) {
            return $depuisNaf;
        }
        if ($actuel !== null && $actuel !== Taxonomy::SECTEUR_NON_CLASSE && array_key_exists($actuel, Taxonomy::SECTEURS)) {
            return $actuel;
        }

        return Taxonomy::SECTEUR_NON_CLASSE;
    }

    /**
     * La valeur à écrire dans une colonne de classement, connaissant le calcul
     * et la valeur actuelle — règle COMMUNE à l'enrichissement et au
     * reclassement de masse :
     *
     *  - le calcul a une valeur : elle est écrite. Pour le SECTEUR, qui n'est
     *    jamais null, c'est `secteurRetenu()` qui protège un secteur valide
     *    quand le code NAF ne dit rien ;
     *  - le calcul ne sait pas (null) : la valeur actuelle est gardée — sauf
     *    une chaîne vide, qui n'est pas une valeur du référentiel et devient
     *    NULL.
     */
    public static function valeurAEcrire(?string $calcule, mixed $actuel): ?string
    {
        if ($calcule !== null) {
            return $calcule;
        }
        if (! is_scalar($actuel)) {
            return null;
        }
        $actuel = (string) $actuel;

        return trim($actuel) === '' ? null : $actuel;
    }

    /** Les clés de secteur qu'un code NAF « muet » ne remplace pas, pour SQL. */
    public static function secteursConservables(): string
    {
        return Taxonomy::sqlList(array_values(array_diff(array_keys(Taxonomy::SECTEURS), [Taxonomy::SECTEUR_NON_CLASSE])));
    }

    private static function renseigne(?string $valeur): bool
    {
        return trim((string) $valeur) !== '';
    }
}
