<?php

namespace App\Http\Controllers\Api\Crm;

use App\Support\WorkspaceContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CHOISIR UNE ENTREPRISE — le sélecteur de rattachement (lot 13, audit UX P1-8).
 *
 * Avant ce lot, rattacher une personne exigeait de TAPER l'identifiant interne
 * de l'entreprise (« ex. 1842 ») : il fallait ouvrir un autre onglet, chercher
 * la fiche, lire son numéro dans l'URL. Ce point d'entrée rend la liste courte
 * dans laquelle l'opérateur clique.
 *
 * ── CE QU'ON PEUT TAPER, ET L'INDEX QUI LE SERT ─────────────────────────────
 *
 *   - un SIREN (9 chiffres) .......... égalité sur `(workspace_id, siren)`,
 *                                      l'index UNIQUE de la table ;
 *   - un SIRET (14 chiffres) ......... ses 9 premiers chiffres SONT le SIREN :
 *                                      même index, aucune colonne à indexer ;
 *   - un nom, éventuellement suivi de la ville ou du code postal
 *     (« martin lyon », « martin 69003 ») :
 *        · chaque mot d'au moins 3 lettres doit se trouver dans le NOM ou dans
 *          la VILLE ;
 *        · au moins un mot doit se trouver dans le NOM — c'est cette condition
 *          (un OU de `denomination_normalized ILIKE`) qui sert de point
 *          d'entrée, par l'index trigrammes `idx_companies_denomination_trgm` ;
 *          la ville ne fait qu'AFFINER les lignes déjà trouvées ;
 *        · un code postal (5 chiffres) affine aussi, en égalité.
 *
 * ⚠️ Une ville SEULE ne trouve donc que les entreprises dont le nom la contient.
 * C'est délibéré : la ville ne porte aucun index trigrammes, et « toutes les
 * entreprises de Lyon » n'aide de toute façon personne à en choisir une.
 * Chercher sur la ville seule balaierait les 4,3 M de fiches.
 *
 * ── CE QU'ON NE FAIT PAS ────────────────────────────────────────────────────
 *
 *   - pas de `LIKE '%…%'` sur le SIREN (aucun index ne le sert) : un SIREN se
 *     tape en entier ;
 *   - jamais d'article ni de mot générique comme point d'entrée (cf.
 *     `ARTICLES_RETIRES` et `MOTS_GENERIQUES`) ;
 *   - pas de mot de moins de 3 lettres APRÈS normalisation :
 *     un trigramme ne s'extrait pas d'un mot de deux lettres, la condition
 *     relirait l'index entier ;
 *   - au-delà de 8 s (`delai-sql:8` sur la route), la requête est annulée et
 *     l'écran demande de préciser — une recherche trop large ne doit pas voler
 *     le processeur aux autres écrans.
 *
 * Cloisonnement : l'univers business de l'opérateur, le MÊME que celui où
 * `ArbitrageController::attach` et `PersonnesController::rattacher` vont
 * ensuite chercher l'entreprise choisie.
 */
class ChoixEntrepriseController extends ConsoleController
{
    /** Une liste à choisir tient sous les yeux. */
    private const PLAFOND = 10;

    /** Longueur minimale d'un mot APRÈS normalisation (cf. en-tête). */
    public const MOT_MINIMAL = 3;

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

    public function index(Request $request): JsonResponse
    {
        $workspaceId = $this->businessWorkspace($request);

        // Type et longueur bornés : `?q[]=x` rend un 422 propre (et non une
        // conversion tableau → chaîne en 500), et une saisie de plusieurs
        // kilo-octets ne fabrique pas de motifs trigrammes démesurés.
        $valide = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'code_postal' => ['nullable', 'string', 'max:10'],
        ]);

        $saisie = trim(is_string($valide['q'] ?? null) ? $valide['q'] : '');
        $codePostal = trim(is_string($valide['code_postal'] ?? null) ? $valide['code_postal'] : '');
        if (preg_match('/^\d{5}$/', $codePostal) !== 1) {
            $codePostal = '';
        }

        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $saisie, $codePostal): JsonResponse {
            $chiffres = preg_replace('/[\s.\-]/u', '', $saisie) ?? '';

            if ($chiffres !== '' && ctype_digit($chiffres)) {
                return $this->parNumero($workspaceId, $chiffres);
            }

            return $this->parNom($workspaceId, $saisie, $codePostal);
        });
    }

    private function parNumero(string $workspaceId, string $chiffres): JsonResponse
    {
        $longueur = strlen($chiffres);
        if ($longueur !== 9 && $longueur !== 14) {
            return $this->ok(['data' => [], 'indice' => 'numero_incomplet']);
        }

        $lignes = $this->base($workspaceId)
            ->where('siren', substr($chiffres, 0, 9))
            ->limit(self::PLAFOND)
            ->get();

        return $this->ok(['data' => $this->projeter($lignes), 'indice' => null]);
    }

    /**
     * @param  non-empty-list<string>  $entrees  les mots SIGNIFICATIFS, normalisés et échappés
     * @param  non-empty-list<string>  $filtres  tous les mots tapés (hors articles), dans l'ordre
     * @return list<\stdClass>
     */
    private function chercherParNom(string $workspaceId, array $entrees, array $filtres, string $codePostal): array
    {
        $requete = $this->base($workspaceId);

        // Point d'entrée indexé (trigrammes) : un mot SIGNIFICATIF au moins
        // dans le nom — jamais un article ni un mot générique. Un OU plutôt
        // qu'un seul mot : dans « lac annecy », le mot le plus long est la
        // VILLE, et l'exiger dans le nom perdrait « Les Jardins du Lac ».
        $requete->where(function ($groupe) use ($entrees): void {
            foreach ($entrees as $mot) {
                $groupe->orWhereRaw('companies.denomination_normalized ILIKE ?', ['%' . $mot . '%']);
            }
        });

        // Filtrage : CHAQUE mot tapé (générique compris) dans le nom ou la ville.
        foreach ($filtres as $mot) {
            $requete->where(function ($groupe) use ($mot): void {
                $groupe->whereRaw('companies.denomination_normalized ILIKE ?', ['%' . $mot . '%'])
                    ->orWhereRaw("normalize_name(coalesce(companies.city_name, companies.city, '')) ILIKE ?", ['%' . $mot . '%']);
            });
        }

        if ($codePostal !== '') {
            $requete->where('postcode', $codePostal);
        }

        // D'abord les noms qui contiennent TOUS les mots tapés : un mot peut
        // être accepté par la VILLE (« france » de Fort-de-France), et sans ce
        // critère « Air France » rendait AIR 24 / AIR CLIM devant AIR FRANCE.
        // Puis les noms qui COMMENCENT par le premier mot tapé, puis les plus
        // courts : « Société Générale » avant « Banque Société Générale … ».
        $tousDansLeNom = implode(' AND ', array_fill(0, count($filtres), 'companies.denomination_normalized ILIKE ?'));
        $lignes = $requete
            ->orderByRaw('(' . $tousDansLeNom . ') DESC', array_map(static fn (string $mot): string => '%' . $mot . '%', $filtres))
            ->orderByRaw('(companies.denomination_normalized ILIKE ?) DESC', [$filtres[0] . '%'])
            ->orderByRaw('length(companies.denomination_normalized)')
            ->orderBy('companies.denomination_normalized')
            ->limit(self::PLAFOND)
            ->get()
            ->all();

        return array_values($lignes);
    }

    private function parNom(string $workspaceId, string $saisie, string $codePostal): JsonResponse
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

        [$entrees, $filtres, $nonSignificatifs] = $this->analyser(array_slice($mots, 0, self::MOTS_LUS));

        if ($entrees === [] || $filtres === []) {
            // Aucune requête : rien de significatif à chercher.
            return $this->ok(['data' => [], 'indice' => $nonSignificatifs ? 'mots_vides' : 'trop_court']);
        }

        return $this->ok([
            'data' => $this->projeter($this->chercherParNom($workspaceId, $entrees, $filtres, $codePostal)),
            'indice' => null,
        ]);
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
    private function analyser(array $mots): array
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

    private function base(string $workspaceId): Builder
    {
        return DB::table('companies')
            ->where('companies.workspace_id', $workspaceId)
            ->whereNull('companies.deleted_at')
            ->select([
                'companies.id',
                'companies.denomination',
                'companies.siren',
                'companies.siret',
                'companies.postcode',
                DB::raw('coalesce(companies.city_name, companies.city) AS ville'),
            ]);
    }

    /**
     * @param  iterable<\stdClass>  $lignes
     * @return list<array{id: int, denomination: ?string, siren: ?string, siret: ?string, code_postal: ?string, ville: ?string}>
     */
    private function projeter(iterable $lignes): array
    {
        $resultat = [];
        foreach ($lignes as $l) {
            $resultat[] = [
                'id' => (int) ($l->id ?? 0),
                'denomination' => $this->texte($l->denomination ?? null),
                'siren' => $this->texte($l->siren ?? null),
                'siret' => $this->texte($l->siret ?? null),
                'code_postal' => $this->texte($l->postcode ?? null),
                'ville' => $this->texte($l->ville ?? null),
            ];
        }

        return $resultat;
    }

    private function texte(mixed $valeur): ?string
    {
        if (! is_string($valeur)) {
            return null;
        }
        $valeur = trim($valeur);

        return $valeur === '' ? null : $valeur;
    }
}
