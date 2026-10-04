<?php

namespace App\Http\Controllers\Api;

use App\Support\EntreprisesFermees;
use App\Support\MasquageCoordonnees;
use App\Support\RechercheEntreprisesParNom;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
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
 * ⚠️ COMMENT ELLE CHERCHE (2026-10-03). Plus aucun `ILIKE` direct sur
 * `companies` ni `contacts` : sous la RLS forcée, il ne peut utiliser aucun
 * index (parcours complet à chaque frappe). Les identifiants viennent de
 * fonctions SECURITY DEFINER cloisonnées (`entreprises_choix_ids`,
 * `entreprises_siren_ids`, `contacts_recherche_ids`), puis les lignes sont
 * relues sous la RLS. Entreprises : nom par mots significatifs (trigrammes),
 * SIREN par égalité ou début de numéro. Personnes : nom et prénom en
 * sous-chaîne (trigrammes), e-mail par son DÉBUT seulement. Les étiquettes
 * (petite table) gardent un `ILIKE '%terme%'` direct.
 */
class GlobalSearchController extends ApiController
{
    /** En dessous, on ne balaie pas la base. */
    private const LONGUEUR_MINIMALE = 2;

    /** Une palette rend ce qui tient sous les yeux, pas un export. */
    private const PLAFOND = 10;

    /** Renseigné par `chercherEntreprises` quand la recherche par nom n'a pas eu lieu. */
    private ?string $indiceEntreprises = null;

    /**
     * @OA\Get(path="/search", tags={"Workspace"}, summary="Recherche globale ⌘K (companies + contacts + tags)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="q", in="query", required=true, @OA\Schema(type="string", minLength=2)),
     *     @OA\Parameter(name="fermees", in="query", description="Entreprises fermées selon l'INSEE : masquées par défaut", @OA\Schema(type="string", enum={"inclure","seules"})),
     *
     *     @OA\Response(response=200, description="Résultats groupés"))
     */
    public function index(Request $r): JsonResponse
    {
        // Le contrôleur peut être réutilisé d'une requête à l'autre (instance
        // gardée par la route) : l'indice repart de zéro à chaque recherche.
        $this->indiceEntreprises = null;
        $vide = ['companies' => [], 'contacts' => [], 'tags' => [], 'indice_entreprises' => null];

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
        // 04/10/2026 — les entreprises FERMÉES selon l'INSEE sont masquées par
        // défaut, comme dans la liste (`fermees=inclure|seules` pour les voir).
        $fermees = EntreprisesFermees::mode($r->query('fermees'));

        return WorkspaceContext::run($espace, function () use ($espace, $terme, $fermees): JsonResponse {
            $charge = MasquageCoordonnees::masquerTableauSiRequis([
                'companies' => $this->chercherEntreprises($espace, $terme, $fermees),
                'contacts' => $this->chercherPersonnes($espace, $terme),
                'tags' => $this->chercherEtiquettes($espace, $terme),
            ]);

            // Pourquoi AUCUNE entreprise n'a été cherchée par son nom
            // (`mots_vides` : « SARL » seul ; `trop_court`), pour que la palette
            // le dise au lieu d'un « aucun résultat » muet. `null` sinon.
            $charge['indice_entreprises'] = $this->indiceEntreprises;

            return response()->json($charge);
        });
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
    private function chercherEntreprises(string $espace, string $terme, string $fermees = EntreprisesFermees::MASQUER): array
    {
        return $this->sur('companies', function () use ($espace, $terme, $fermees): array {
            $chiffres = preg_replace('/[\s.\-]/u', '', $terme) ?? '';

            $ids = [];
            if ($chiffres !== '' && ctype_digit($chiffres)) {
                // Un SIRET (14 chiffres) commence par son SIREN.
                $debut = strlen($chiffres) === 14 ? substr($chiffres, 0, 9) : $chiffres;
                if (strlen($debut) >= 2 && strlen($debut) <= 9) {
                    $ids = $this->identifiants(
                        'SELECT t.id FROM public.entreprises_siren_ids(?::uuid, ?, ?) WITH ORDINALITY AS t(id, rang) ORDER BY t.rang',
                        [$espace, $debut, self::PLAFOND],
                    );
                }
            }

            // Le NOM est cherché AUSSI pour une saisie chiffrée : une entreprise
            // peut s'appeler « 1664 », et 10 à 13 chiffres ne sont pas un SIREN.
            // Les SIREN trouvés passent d'abord.
            if (count($ids) < self::PLAFOND) {
                [$parNom, $indice] = RechercheEntreprisesParNom::identifiants($espace, $terme, '', self::PLAFOND);
                $ids = array_slice(array_unique(array_merge($ids, $parNom)), 0, self::PLAFOND);
                if ($ids === []) {
                    // Une saisie de chiffres qui ne trouve rien parle de SIREN,
                    // pas de « lettres du nom ».
                    $enChiffres = $chiffres !== '' && ctype_digit($chiffres);
                    $this->indiceEntreprises = $enChiffres ? ($indice !== null ? 'siren_inconnu' : null) : $indice;
                }
            }

            // Le masquage des fermées se fait à la RELECTURE (sous la RLS) :
            // les fonctions de recherche ne le connaissent pas. Une fermée
            // retirée laisse sa place vide (au plus `PLAFOND` résultats).
            return $this->relire(
                'companies',
                $espace,
                $ids,
                ['id', 'siren', 'denomination'],
                static fn (\Illuminate\Database\Query\Builder $q) => EntreprisesFermees::appliquer($q, $fermees),
            );
        });
    }

    /**
     * Personnes : nom et prénom par les index trigrammes, PUIS début d'e-mail
     * (intervalle sur `idx_contacts_email`, bornes en citext, dans l'ordre de
     * l'index). La sous-chaîne d'e-mail (« @domaine ») n'a aucun index : elle
     * n'est plus cherchée — c'était un parcours complet de 1,3 M de lignes à
     * chaque frappe. Moins de 3 caractères : rien.
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

        return array_values(array_slice($ids, 0, self::PLAFOND));
    }

    /**
     * Relit SOUS LA RLS les lignes dont une fonction a rendu les identifiants,
     * dans l'ordre rendu.
     *
     * @param  list<int>  $ids
     * @param  list<string>  $colonnes
     * @param  (callable(\Illuminate\Database\Query\Builder): mixed)|null  $affiner
     * @return list<array<string, mixed>>
     */
    private function relire(string $table, string $espace, array $ids, array $colonnes, ?callable $affiner = null): array
    {
        if ($ids === []) {
            return [];
        }

        $requete = DB::table($table)->where('workspace_id', $espace)->whereNull('deleted_at')->whereIn('id', $ids);
        if ($affiner !== null) {
            $affiner($requete);
        }

        $parId = [];
        foreach ($requete->get($colonnes) as $ligne) {
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
     * Le motif de recherche des ÉTIQUETTES (seule famille qui garde un `ILIKE`
     * direct : la table est minuscule) : une SOUS-CHAINE. Entreprises et
     * personnes passent par leurs fonctions SECURITY DEFINER (cf. en-tête).
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
            //
            // Ni le message ni le SQL : celui d'une `QueryException` porte les
            // liaisons, donc la SAISIE (un nom, un début d'adresse). La classe
            // et le code SQLSTATE suffisent à diagnostiquer.
            Log::warning('search: famille indisponible', [
                'table' => $table,
                'exception' => $e::class,
                'sqlstate' => $e instanceof QueryException ? (string) $e->getCode() : null,
            ]);

            return [];
        }
    }
}
