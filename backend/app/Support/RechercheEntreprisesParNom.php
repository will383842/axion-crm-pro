<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * RECHERCHE D'ENTREPRISE PAR NOM — extraite de `ChoixEntrepriseController`
 * (#287, #292) pour être PARTAGÉE avec la palette ⌘K (`GlobalSearchController`).
 * Deux implémentations de la même recherche finissent toujours par diverger.
 */
final class RechercheEntreprisesParNom
{
    /**
     * DEUX NOTIONS, ET LES CONFONDRE A COÛTÉ DEUX RELECTURES (PR #287).
     *
     * (a) ARTICLES_RETIRES — EXACTEMENT les mots que `normalize_name` retire en
     *     base (migration `2026_05_16_000001_create_extensions_and_helpers`,
     *     motif `\m(de|du|la|le|les|d|l)\M\s+`). Ils n'existent pas dans le nom
     *     stocké (« Les Jardins du Lac » → `jardins lac`) : on les ignore
     *     PARTOUT, point d'entrée et filtrage. Normalisé SEUL, « les » restait
     *     `les`, et « les jardins du lac » ne trouvait rien.
     *
     * (b) MOTS_GENERIQUES — formes juridiques, petits mots et termes courants
     *     que `normalize_name` CONSERVE (« Société Générale » → `societe
     *     generale`). Ils ne servent JAMAIS de point d'entrée trigramme (trop
     *     fréquents : `sarl` > 20 s en production) mais restent EXIGÉS au
     *     filtrage quand on les a tapés. Les retirer aussi du filtrage rendait
     *     10 × « LA GENERALE » pour « Société Générale », sans elle.
     *
     * ⚠️ Ces deux listes sont aussi celles de l'écran, qui les lit dans
     * `frontend/src/features/crm-console/motsRechercheEntreprise.json`. Un test
     * Pest compare les deux : une liste modifiée d'un seul côté rougit.
     */
    public const ARTICLES_RETIRES = ['de', 'du', 'la', 'le', 'les', 'd', 'l'];

    public const MOTS_GENERIQUES = [
        'des', 'et', 'au', 'aux', 'en', 'sur', 'sous', 'par', 'pour', 'chez', 'un', 'une', 'a', 'the', 'and',
        'sarl', 'sas', 'sasu', 'sa', 'eurl', 'sci', 'snc', 'scop', 'scp', 'scm', 'sel', 'selarl', 'selas',
        'ei', 'eirl', 'gie', 'gaec', 'earl', 'scea', 'association', 'asso',
        'societe', 'ste', 'ets', 'etablissement', 'etablissements', 'cie', 'compagnie', 'groupe', 'france',
        'entreprise', 'entreprises',
    ];

    /** Mots lus dans la saisie, au plus (le même plafond que l'écran). */
    public const MOTS_LUS = 8;

    /** Longueur minimale d'un mot significatif APRÈS normalisation. */
    public const MOT_MINIMAL = 3;

    /**
     * LA recherche d'entreprise par nom, partagée par le sélecteur
     * « Entreprise » (#287) et la palette ⌘K (`/search`).
     *
     * 🔴 SOUS LA RLS, L'INDEX TRIGRAMMES N'EST PAS UTILISABLE (prod,
     * 2026-10-02) : `ILIKE` n'est pas « leakproof », et sous `axion_app`
     * Postgres ne peut l'évaluer qu'APRÈS le filtre de la politique, donc en
     * parcourant 4,3 M de lignes. Les IDENTIFIANTS sont donc cherchés par
     * `entreprises_choix_ids` (SECURITY DEFINER, cloisonnée à l'espace du
     * contexte, migration `2026_10_02_000050`), puis l'appelant les RELIT sous
     * la RLS — double garde.
     *
     * Rend les identifiants dans l'ordre d'affichage (au plus `$plafond`), ou
     * un indice (`trop_court`, `mots_vides`) quand rien n'est cherché.
     *
     * @return array{0: list<int>, 1: ?string}
     */
    public static function identifiants(string $workspaceId, string $saisie, string $codePostal, int $plafond): array
    {
        // L'apostrophe sépare aussi : « l'atelier » → « l » (article) et « atelier ».
        $morceaux = preg_split("/[\s,;'’]+/u", $saisie, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $mots = [];
        foreach ($morceaux as $morceau) {
            // Un code postal glissé dans la saisie (« martin 69003 ») affine.
            if (preg_match('/^\d{5}$/', $morceau) === 1) {
                $codePostal = $morceau;

                continue;
            }
            $mots[] = $morceau;
        }

        [$entrees, $filtres, $nonSignificatifs] = self::analyser(array_slice($mots, 0, self::MOTS_LUS));

        if ($entrees === [] || $filtres === []) {
            return [[], $nonSignificatifs ? 'mots_vides' : 'trop_court'];
        }

        // `WITH ORDINALITY … ORDER BY` : l'ordre rendu par la fonction est
        // l'ordre affiché ; on ne s'en remet pas à l'ordre implicite du SELECT.
        $ids = array_map(
            static fn ($l): int => (int) (is_object($l) ? ($l->id ?? 0) : 0),
            DB::select(
                'SELECT t.id FROM public.entreprises_choix_ids(?::uuid, ?::text[], ?::text[], ?, ?)'
                . ' WITH ORDINALITY AS t(id, rang) ORDER BY t.rang',
                [$workspaceId, self::tableauPg($entrees), self::tableauPg($filtres), $codePostal, $plafond],
            ),
        );

        // La fonction plafonne déjà ; on ne lui fait pas confiance pour la
        // taille de la liste affichée.
        return [array_values(array_slice($ids, 0, $plafond)), null];
    }

    /**
     * Normalise les mots par la MÊME fonction SQL que la colonne
     * (`normalize_name` : minuscules, accents retirés) — la réimplémenter en
     * PHP divergerait au premier changement de la fonction (cf.
     * `RechercheDenomination`). Échappe ensuite les jokers `LIKE` : un `%`
     * tapé ne doit pas rendre la table entière.
     *
     * Rend : les mots d'ENTRÉE (significatifs : ni article, ni mot générique,
     * au moins 3 lettres ou chiffres), les mots à
     * FILTRER (tous, hors articles retirés en base et hors pure ponctuation),
     * et si la saisie ne portait que des articles ou mots génériques — pour
     * dire « ajoutez un mot du nom », pas « trop court ».
     *
     * @param  list<string>  $mots
     * @return array{0: list<string>, 1: list<string>, 2: bool}
     */
    private static function analyser(array $mots): array
    {
        $entrees = [];
        $filtres = [];
        $nonSignificatifs = 0;

        foreach ($mots as $mot) {
            $ligne = DB::selectOne('SELECT normalize_name(?) AS n', [$mot]);
            $n = trim(is_object($ligne) && is_string($ligne->n ?? null) ? $ligne->n : '');

            if (in_array($n, self::ARTICLES_RETIRES, true)) {
                $nonSignificatifs++;

                continue;
            }

            // On compte les LETTRES ET CHIFFRES, pas les caractères : « %%% »
            // ferait trois caractères et ne désignerait rien.
            $lettres = preg_match_all('/[\p{L}\p{N}]/u', $n);
            if ($lettres === 0 || $lettres === false) {
                continue;
            }

            $echappe = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $n);
            $filtres[] = $echappe;

            if (in_array($n, self::MOTS_GENERIQUES, true)) {
                $nonSignificatifs++;

                continue;
            }

            if ($lettres >= self::MOT_MINIMAL) {
                $entrees[] = $echappe;
            }
        }

        $entrees = array_values(array_unique($entrees));
        $filtres = array_values(array_unique($filtres));

        return [$entrees, $filtres, $entrees === [] && $nonSignificatifs > 0];
    }

    /**
     * Littéral de tableau Postgres (`{"a","b"}`) : guillemets et barres
     * obliques inverses échappés — les mots portent déjà l'échappement `LIKE`.
     *
     * @param  list<string>  $mots
     */
    private static function tableauPg(array $mots): string
    {
        return '{' . implode(',', array_map(
            static fn (string $m): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $m) . '"',
            $mots,
        )) . '}';
    }
}
