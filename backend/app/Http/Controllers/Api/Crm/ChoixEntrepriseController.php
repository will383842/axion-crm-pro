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
 *   - pas de mot de moins de 3 lettres APRÈS normalisation (« la », « sa ») :
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
    private const MOT_MINIMAL = 3;

    public function index(Request $request): JsonResponse
    {
        $workspaceId = $this->businessWorkspace($request);

        $saisie = trim((string) $request->query('q', ''));
        $codePostal = trim((string) $request->query('code_postal', ''));
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
     * @param  non-empty-list<string>  $mots  normalisés et échappés
     * @return list<\stdClass>
     */
    private function chercherParNom(string $workspaceId, array $mots, string $codePostal): array
    {
        $requete = $this->base($workspaceId);

        // Point d'entrée indexé : au moins un mot dans le nom.
        $requete->where(function ($groupe) use ($mots): void {
            foreach ($mots as $mot) {
                $groupe->orWhereRaw('companies.denomination_normalized ILIKE ?', ['%' . $mot . '%']);
            }
        });

        // Affinage : CHAQUE mot dans le nom ou dans la ville.
        foreach ($mots as $mot) {
            $requete->where(function ($groupe) use ($mot): void {
                $groupe->whereRaw('companies.denomination_normalized ILIKE ?', ['%' . $mot . '%'])
                    ->orWhereRaw("normalize_name(coalesce(companies.city_name, companies.city, '')) ILIKE ?", ['%' . $mot . '%']);
            });
        }

        if ($codePostal !== '') {
            $requete->where('postcode', $codePostal);
        }

        // Les noms qui COMMENCENT par le premier mot d'abord, puis les plus
        // courts : « Martin » avant « Boulangerie des frères Martin et fils ».
        $lignes = $requete
            ->orderByRaw('(companies.denomination_normalized ILIKE ?) DESC', [$mots[0] . '%'])
            ->orderByRaw('length(companies.denomination_normalized)')
            ->orderBy('companies.denomination_normalized')
            ->limit(self::PLAFOND)
            ->get()
            ->all();

        return array_values($lignes);
    }

    private function parNom(string $workspaceId, string $saisie, string $codePostal): JsonResponse
    {
        $morceaux = preg_split('/[\s,;]+/u', $saisie, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $mots = [];
        foreach ($morceaux as $morceau) {
            // Un code postal glissé dans la saisie (« martin 69003 ») affine.
            if (preg_match('/^\d{5}$/', $morceau) === 1) {
                $codePostal = $morceau;

                continue;
            }
            $mots[] = $morceau;
        }

        $mots = $this->normaliser(array_slice($mots, 0, 5));

        if ($mots === []) {
            return $this->ok(['data' => [], 'indice' => 'trop_court']);
        }

        return $this->ok([
            'data' => $this->projeter($this->chercherParNom($workspaceId, $mots, $codePostal)),
            'indice' => null,
        ]);
    }

    /**
     * Normalise les mots par la MÊME fonction SQL que la colonne
     * (`normalize_name` : minuscules, accents et articles retirés) — la
     * réimplémenter en PHP divergerait au premier changement de la fonction
     * (cf. `RechercheDenomination`). Échappe ensuite les jokers `LIKE` : un `%`
     * tapé ne doit pas rendre la table entière.
     *
     * @param  list<string>  $mots
     * @return list<string>
     */
    private function normaliser(array $mots): array
    {
        if ($mots === []) {
            return [];
        }

        $normalises = [];
        foreach ($mots as $mot) {
            $ligne = DB::selectOne('SELECT normalize_name(?) AS n', [$mot]);
            $n = trim(is_object($ligne) && is_string($ligne->n ?? null) ? $ligne->n : '');
            // On compte les LETTRES ET CHIFFRES, pas les caractères : « %%% »
            // ferait trois caractères et ne désignerait rien.
            if (preg_match_all('/[\p{L}\p{N}]/u', $n) < self::MOT_MINIMAL) {
                continue;
            }
            $normalises[] = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $n);
        }

        return array_values(array_unique($normalises));
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
