<?php

namespace App\Http\Controllers\Api;

use App\Support\MasquageCoordonnees;
use App\Support\RechercheEntreprisesParNom;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * LA PALETTE ⌘K — constat P6-UI-002 (S0).
 *
 * 🔴 CE QU'IL Y AVAIT AVANT, ET C'ETAIT DEUX FOIS LE MEME VIDE. `GET /search`
 * était déclarée **deux fois** dans `routes/api.php` : une closure rendant trois
 * tableaux vides, et cette classe-ci, qui rendait **elle aussi** trois tableaux
 * vides. La closure venait en premier, donc elle gagnait — et ce contrôleur
 * était du code mort qui donnait l'apparence d'une implémentation. La palette
 * est présente sur tous les écrans et vantée par la visite guidée : elle ne
 * pouvait rien trouver.
 *
 * Et **le test e2e mocke l'endpoint**, donc il restait vert. *Un test qui mocke
 * précisément la pièce qui n'existe pas certifie son existence.* C'est le patron
 * `A-011` transposé aux tests, et il n'était pas au registre sous cette forme.
 *
 * ── TROIS PRÉCAUTIONS, ET AUCUNE N'EST DÉCORATIVE ──────────────────────────
 *
 * 1. **Cloisonnée.** Une palette de recherche est le pire endroit où fuir : elle
 *    balaie tout, sur une saisie libre. Sans contexte d'espace, elle ne rend
 *    RIEN — jamais les fiches de tout le monde.
 *
 * 2. **Deux caractères minimum, côté SERVEUR.** Le frontend s'en garde déjà
 *    (`if (search.length < 2)`), mais une garde côté client n'est pas une garde :
 *    l'API est appelable directement. Sans ce plancher, une requête sur « a »
 *    balaierait 4,29 millions de lignes.
 *
 * 3. **Plafonnée, et le plafond est dit.** `G41-007` établit qu'un export sans
 *    plafond gèle l'application deux minutes au volume de production. Une
 *    palette qui se déclenche à chaque frappe ne peut pas se le permettre.
 *
 * ⚠️ CE QUE CETTE RECHERCHE N'EST PAS. Elle emploie `ILIKE 'terme%'` — un
 * préfixe, pas une sous-chaîne. `%terme%` ne peut utiliser aucun index B-tree :
 * `G41-003` a mesuré 65 s au volume de production sur exactement ce motif. Une
 * vraie recherche plein texte (trigrammes, `pg_trgm`) est un choix de conception
 * avec sa migration d'index ; elle ne se prend pas au détour d'un correctif.
 * **Le jour où on la voudra, c'est ici qu'il faudra revenir.**
 */
class GlobalSearchController extends ApiController
{
    /** En dessous, on ne balaie pas la base. */
    private const LONGUEUR_MINIMALE = 2;

    /** Une palette rend ce qui tient sous les yeux, pas un export. */
    private const PLAFOND = 10;

    /**
     * @OA\Get(path="/search", tags={"Workspace"}, summary="Recherche globale ⌘K (companies + contacts + tags)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="q", in="query", required=true, @OA\Schema(type="string", minLength=2)),
     *
     *     @OA\Response(response=200, description="Résultats groupés"))
     */
    public function index(Request $r): JsonResponse
    {
        $vide = ['companies' => [], 'contacts' => [], 'tags' => []];

        $terme = trim((string) $r->query('q', ''));
        if (mb_strlen($terme) < self::LONGUEUR_MINIMALE) {
            return response()->json($vide);
        }

        $espace = $this->espaceCourantOuNull();
        if ($espace === null) {
            return response()->json($vide);
        }

        // 🔴 SITE JUMEAU de B12-002 / F36-006, et le plus accessible des six :
        // la palette est presente sur TOUS les ecrans, et `chercherPersonnes`
        // selectionne `email` — puis cherche DEDANS. Un compte en lecture
        // seule pouvait donc, sans meme ouvrir une fiche, taper `@` puis un
        // nom de domaine et lire les adresses en clair, ligne apres ligne.
        // C'est un export deguise en champ de recherche.
        //
        // Le masquage porte sur la charge ENTIERE, pas sur `contacts` seul :
        // une famille de resultats ajoutee demain sera couverte sans qu'on ait
        // a y penser.
        // Le contexte d'espace est posé EXPLICITEMENT : les fonctions de
        // recherche (SECURITY DEFINER) ne rendent rien si l'espace demandé
        // n'est pas celui de la connexion.
        return WorkspaceContext::run($espace, fn (): JsonResponse => response()->json(MasquageCoordonnees::masquerTableauSiRequis([
            'companies' => $this->chercherEntreprises($espace, $terme),
            'contacts' => $this->chercherPersonnes($espace, $terme),
            'tags' => $this->chercherEtiquettes($espace, $terme),
        ])));
    }

    /**
     * 🔴 SOUS LA RLS, AUCUN `ILIKE` / `LIKE '%x%'` DIRECT (prod, 2026-10-03).
     *
     * Sous `axion_app` (RLS forcée), `ILIKE` n'est pas « leakproof » : Postgres
     * ne peut l'évaluer qu'APRÈS le filtre de la politique, et aucun index ne
     * sert. Mesuré : `companies` en Parallel Seq Scan 3,9 à 4,7 s, `contacts`
     * ~1,25 s, À CHAQUE FRAPPE. Les IDENTIFIANTS viennent donc de fonctions
     * SECURITY DEFINER cloisonnées à l'espace du contexte (migrations
     * `2026_10_02_000050` et `2026_10_03_000010`), et les lignes sont RELUES
     * ici sous la RLS — double garde.
     *
     *   - que des chiffres : SIREN, égalité sur 9 chiffres, préfixe sinon ;
     *   - sinon : la recherche par nom du sélecteur « Entreprise » (#287),
     *     mots significatifs d'au moins 3 lettres.
     *
     * @return list<array<string, mixed>>
     */
    private function chercherEntreprises(string $espace, string $terme): array
    {
        return $this->sur('companies', function () use ($espace, $terme): array {
            $chiffres = preg_replace('/[\s.\-]/u', '', $terme) ?? '';

            if ($chiffres !== '' && ctype_digit($chiffres)) {
                // Un SIRET (14 chiffres) commence par son SIREN.
                $debut = strlen($chiffres) === 14 ? substr($chiffres, 0, 9) : $chiffres;
                $ids = strlen($debut) <= 9 ? $this->identifiants(
                    'SELECT t.id FROM public.entreprises_siren_ids(?::uuid, ?, ?) WITH ORDINALITY AS t(id, rang) ORDER BY t.rang',
                    [$espace, $debut, self::PLAFOND],
                ) : [];
            } else {
                [$ids] = RechercheEntreprisesParNom::identifiants($espace, $terme, '', self::PLAFOND);
            }

            return $this->relire('companies', $espace, $ids, ['id', 'siren', 'denomination']);
        });
    }

    /**
     * Personnes : nom et prénom par les index trigrammes, e-mail par PRÉFIXE
     * (intervalle sur `idx_contacts_email`). La sous-chaîne d'e-mail (« @domaine ») n'a
     * aucun index : elle n'est plus cherchée — c'était un parcours complet de
     * 1,3 M de lignes à chaque frappe. Moins de 3 caractères : rien.
     *
     * @return list<array<string, mixed>>
     */
    private function chercherPersonnes(string $espace, string $terme): array
    {
        return $this->sur('contacts', function () use ($espace, $terme): array {
            $ids = $this->identifiants(
                'SELECT t.id FROM public.contacts_recherche_ids(?::uuid, ?, ?) WITH ORDINALITY AS t(id, rang) ORDER BY t.rang',
                // Saisie BRUTE : la fonction neutralise elle-même les jokers.
                [$espace, $terme, self::PLAFOND],
            );

            return $this->relire('contacts', $espace, $ids, ['id', 'first_name', 'last_name', 'email', 'company_id']);
        });
    }

    /**
     * @param  list<mixed>  $liaisons
     * @return list<int>
     */
    private function identifiants(string $sql, array $liaisons): array
    {
        $ids = array_map(
            static fn ($l): int => (int) (is_object($l) ? ($l->id ?? 0) : 0),
            DB::select($sql, $liaisons),
        );

        return array_slice($ids, 0, self::PLAFOND);
    }

    /**
     * Relit SOUS LA RLS les lignes dont une fonction a rendu les identifiants,
     * dans l'ordre rendu.
     *
     * @param  list<int>  $ids
     * @param  list<string>  $colonnes
     * @return list<array<string, mixed>>
     */
    private function relire(string $table, string $espace, array $ids, array $colonnes): array
    {
        if ($ids === []) {
            return [];
        }

        $parId = [];
        foreach (DB::table($table)->where('workspace_id', $espace)->whereNull('deleted_at')->whereIn('id', $ids)->get($colonnes) as $ligne) {
            /** @var array<string, mixed> $tableau */
            $tableau = (array) $ligne;
            $parId[(int) ($tableau['id'] ?? 0)] = $tableau;
        }

        $lignes = [];
        foreach ($ids as $id) {
            if (isset($parId[$id])) {
                $lignes[] = $parId[$id];
            }
        }

        return $lignes;
    }

    /** @return list<array<string, mixed>> */
    private function chercherEtiquettes(string $espace, string $terme): array
    {
        return $this->sur('tags', function () use ($espace, $terme): array {
            // `(array) $ligne` rend, pour l'analyse statique, un `array` sans
            // clefs ni valeurs typees, et `->all()` un `array<int, ...>` et non
            // une `list`. `sur()` promet pourtant
            // `list<array<string, mixed>>` a ses appelants. On NOMME donc le
            // resultat au lieu de laisser la promesse non tenue : sans cela,
            // tout ce qui consomme la recherche globale travaille sur un type
            // que personne ne verifie.
            /** @var list<array<string, mixed>> $lignes */
            $lignes = DB::table('tags')
                ->where('workspace_id', $espace)
                ->where(function ($q) use ($terme) {
                    $q->where('name', 'ILIKE', $this->motif($terme))
                        ->orWhere('slug', 'ILIKE', $this->motif($terme));
                })
                ->orderBy('name')
                ->limit(self::PLAFOND)
                ->get(['id', 'slug', 'name'])
                ->map(fn ($l) => (array) $l)
                ->all();

            return $lignes;
        });
    }

    /**
     * Le motif de recherche : une SOUS-CHAINE, pas un prefixe.
     *
     * 🔑 C'est le coeur du sujet, et il a ete tranche explicitement. Une palette
     * doit trouver « Boulangerie Martin » quand on tape « Martin » : personne ne
     * saisit le premier mot d'une raison sociale. Un prefixe (`terme%`) serait
     * plus rapide et ne servirait a rien.
     *
     * Mais `ILIKE '%martin%'` **n'utilise aucun index B-tree**, et `G41-003` a
     * mesure exactement ce motif a **65 secondes** au volume de production.
     * C'est pourquoi ce correctif est INDISSOCIABLE de sa migration --
     * `2026_08_20_090000_index_trigrammes_pour_la_palette_de_recherche.php` --
     * qui pose des index GIN `gin_trgm_ops`, lesquels servent precisement ce
     * motif.
     *
     * **Livrer l'un sans l'autre remplacerait « la palette ne trouve rien » par
     * « la palette gele l'application a chaque frappe ».**
     *
     * Les jokers de la saisie sont echappes AVANT qu'on y colle les notres :
     * sans cela, un `%` tape par l'utilisateur donne `ILIKE '%%%'` et remonte la
     * table entiere. Ce n'est pas une injection -- la valeur reste liee -- mais
     * c'est un balayage complet declenche par un caractere.
     */
    private function motif(string $terme): string
    {
        $echappe = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $terme);

        return '%' . $echappe . '%';
    }

    /**
     * @param  callable(): list<array<string, mixed>>  $requete
     * @return list<array<string, mixed>>
     */
    private function sur(string $table, callable $requete): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        try {
            return $requete();
        } catch (\Throwable $e) {
            // Une palette qui casse ne doit pas emporter les deux autres
            // familles de résultats avec elle. Mais elle le journalise : un
            // silence ici redonnerait exactement le défaut qu'on répare.
            Log::warning('search: famille indisponible', [
                'table' => $table, 'exception' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
