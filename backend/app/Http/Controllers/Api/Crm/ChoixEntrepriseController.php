<?php

namespace App\Http\Controllers\Api\Crm;

use App\Support\RechercheEntreprisesParNom;
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
    public const MOT_MINIMAL = RechercheEntreprisesParNom::MOT_MINIMAL;

    /** Listes et seuils : la source est `RechercheEntreprisesParNom` (partagée avec la palette ⌘K). */
    public const ARTICLES_RETIRES = RechercheEntreprisesParNom::ARTICLES_RETIRES;

    public const MOTS_GENERIQUES = RechercheEntreprisesParNom::MOTS_GENERIQUES;

    public const MOTS_LUS = RechercheEntreprisesParNom::MOTS_LUS;

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

    private function parNom(string $workspaceId, string $saisie, string $codePostal): JsonResponse
    {
        [$ids, $indice] = RechercheEntreprisesParNom::identifiants($workspaceId, $saisie, $codePostal, self::PLAFOND);

        if ($indice !== null) {
            // Aucune requête : rien de significatif à chercher.
            return $this->ok(['data' => [], 'indice' => $indice]);
        }

        return $this->ok(['data' => $this->projeter($this->relire($workspaceId, $ids)), 'indice' => null]);
    }

    /**
     * Relit, SOUS LA RLS, les fiches dont `RechercheEntreprisesParNom` a rendu
     * les identifiants — double garde — dans l'ordre rendu.
     *
     * @param  list<int>  $ids
     * @return list<\stdClass>
     */
    private function relire(string $workspaceId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $parId = [];
        foreach ($this->base($workspaceId)->whereIn('companies.id', $ids)->get() as $ligne) {
            $parId[(int) $ligne->id] = $ligne;
        }

        $lignes = [];
        foreach ($ids as $id) {
            if (isset($parId[$id])) {
                $lignes[] = $parId[$id];
            }
        }

        return $lignes;
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
