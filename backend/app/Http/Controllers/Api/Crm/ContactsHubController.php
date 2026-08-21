<?php

namespace App\Http\Controllers\Api\Crm;

use App\Crm\Console\CompteursHub;
use App\Crm\Taxonomy;
use App\Models\Company;
use App\Models\Contact;
use App\Support\CompanyQueryFilters;
use App\Support\MasquageCoordonnees;
use App\Support\RechercheDenomination;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * HUB DE CONTACTS — l'unique moteur de liste de l'univers business (conception
 * §3a, parti-pris n°2 : « les types ne sont pas des pages différentes, ce sont
 * des vues préréglées du même écran »).
 *
 * Unité de ligne : l'ENTREPRISE avec ses personnes jointes. Ce n'est pas un
 * choix d'affichage, c'est le modèle du plan §2.2a : le CRM Pro est
 * company-centrique et `relation_type` / `lifecycle_stage` vivent sur
 * `companies`. Lister des personnes aurait imposé de dupliquer le type sur
 * chaque contact — donc de le laisser diverger.
 *
 * L'univers VIVIER n'est pas atteignable ici, par construction : la requête est
 * bornée au workspace business courant, et `candidates` est une autre table
 * dans un autre workspace (`CandidatesController`).
 */
class ContactsHubController extends ConsoleController
{
    /**
     * Colonnes de tri exposées. Liste FERMÉE : un tri libre sur une colonne
     * arbitraire, c'est un scan séquentiel de 4,29 M de lignes à un caractère
     * près dans l'URL.
     *
     * @var array<string, string>
     */
    private const SORTS = [
        'recent' => 'updated_at',
        'created' => 'created_at',
        'quality' => 'quality_score',
        'denomination' => 'denomination',
    ];

    public function index(Request $request): JsonResponse
    {
        $workspaceId = $this->businessWorkspace($request);
        $perPage = $this->perPage($request);

        $validated = $request->validate([
            'relation_type' => ['nullable', 'string', 'in:' . implode(',', Taxonomy::BUSINESS_RELATION_TYPES)],
            'lifecycle_stage' => ['nullable', 'string', 'in:' . implode(',', Taxonomy::BUSINESS_LIFECYCLE_STAGES)],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:120'],
            'q' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'string', 'in:' . implode(',', array_keys(self::SORTS))],
            'temperature' => ['nullable', 'string', 'in:actifs,froids,tous'],
        ]);

        $query = $this->buildQuery($workspaceId, $validated);

        // Décidé UNE fois par requête : la permission ne change pas d'une
        // ligne à l'autre.
        $masquer = MasquageCoordonnees::requis();

        $page = $query->cursorPaginate($perPage);

        return $this->ok([
            'data' => array_map(
                fn (Company $company): array => $this->present($company, $masquer),
                $page->items(),
            ),
            'meta' => [
                'per_page' => $page->perPage(),
                'next_cursor' => $page->nextCursor()?->encode(),
                'prev_cursor' => $page->previousCursor()?->encode(),
                'has_more' => $page->hasMorePages(),
            ],
        ]);
    }

    /**
     * Compteurs par type de relation — ce sont les pastilles de la navigation
     * (conception §2.2). Le calcul, son cache et son invalidation vivent dans
     * `App\Crm\Console\CompteursHub` : mesuré à 337-476 ms sur 300 000 fiches
     * et de l'ordre de 3 s sur les 4,29 M de la production, il n'a rien à faire
     * en ligne dans un contrôleur d'affichage
     * (`_REPORTS/2026-08-18_MESURE-PERFORMANCE-REFERENCE.md` §4 n°1).
     *
     * `fresh_for_seconds` est rendu au client pour qu'un écran puisse dire
     * « chiffres arrêtés à … » : un compteur mis en cache qui se présente comme
     * instantané est un mensonge d'interface.
     */
    public function counts(Request $request): JsonResponse
    {
        $workspaceId = $this->businessWorkspace($request);

        return $this->ok(CompteursHub::pour($workspaceId) + [
            'fresh_for_seconds' => CompteursHub::FRAIS_SECONDES,
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return QueryBuilder<Company>
     */
    private function buildQuery(string $workspaceId, array $validated): QueryBuilder
    {
        $base = Company::query()
            ->whereNull('deleted_at')
            // Scope EXPLICITE, en plus de la RLS : la défense en profondeur du
            // lot L0 vaut pour les deux couches, pas pour l'une OU l'autre.
            ->where('workspace_id', $workspaceId)
            ->with(['contacts', 'tags']);

        $relationType = $validated['relation_type'] ?? null;
        if (is_string($relationType)) {
            $base->where('relation_type', $relationType);
        }

        $lifecycleStage = $validated['lifecycle_stage'] ?? null;
        if (is_string($lifecycleStage)) {
            $base->where('lifecycle_stage', $lifecycleStage);
        }

        $this->applyTags($base, $validated['tags'] ?? null);
        // Le workspace est passé EXPLICITEMENT : `applyTemperature` doit
        // résoudre les étiquettes de provenance du workspace courant, et une
        // résolution qui les prendrait tous mélangerait deux univers.
        $this->applyTemperature($base, $validated['temperature'] ?? null, $workspaceId);
        $this->applySearch($base, $validated['q'] ?? null);

        $sortKey = is_string($validated['sort'] ?? null) ? $validated['sort'] : 'recent';
        $column = self::SORTS[$sortKey] ?? 'updated_at';

        $builder = QueryBuilder::for($base)
            ->allowedFilters(...CompanyQueryFilters::allowedWithTaxonomy());

        // La pagination par curseur EXIGE un ordre total : sans `id` en
        // départage, deux lignes de même `updated_at` peuvent s'échanger entre
        // deux pages — une fiche vue deux fois, une autre jamais.
        $builder->orderByDesc($column)->orderByDesc('id');

        return $builder;
    }

    /**
     * Filtre par tags, en ET : une fiche doit porter TOUS les tags demandés.
     * Le OU se fait en changeant de vue, pas en cochant des cases — c'est ce
     * qui rend les vues préréglées lisibles (conception §3a).
     *
     * @param  Builder<Company>  $query
     */
    private function applyTags(Builder $query, mixed $tags): void
    {
        if (! is_array($tags) || $tags === []) {
            return;
        }

        $slugs = array_values(array_unique(array_filter(
            array_map(static fn (mixed $t): string => is_string($t) ? $t : '', $tags),
            static fn (string $t): bool => $t !== '',
        )));

        foreach ($slugs as $slug) {
            $query->whereExists(function (\Illuminate\Database\Query\Builder $sub) use ($slug): void {
                $sub->select(DB::raw('1'))
                    ->from('company_tag')
                    ->join('tags', 'tags.id', '=', 'company_tag.tag_id')
                    ->whereColumn('company_tag.company_id', 'companies.id')
                    ->where('tags.slug', $slug);
            });
        }
    }

    /**
     * Chaud / froid — la définition vient de l'audit d'harmonisation §B.2, ce
     * n'est PAS un choix d'interface :
     *   - ACTIF  = étape au-delà de « nouveau », OU une provenance autre que le
     *              scraping (`src:site-*`, `src:calendly`, `src:newsletter`…) ;
     *   - FROID  = étape « nouveau » ET uniquement des tags `src:scraping-*`.
     *
     * Défaut : `actifs`. La masse froide (4,29 M) ne doit jamais noyer le
     * quotidien par simple omission d'un paramètre.
     *
     * ── `G41-001` (S0) : « 3 minutes, et l'application gelée pendant » ───────
     *
     * La définition ci-dessus n'a PAS changé. Sa mise en œuvre, si — elle
     * joignait `tags` À L'INTÉRIEUR du sous-select corrélé :
     *
     *     EXISTS (SELECT 1 FROM company_tag JOIN tags ON …
     *              WHERE company_tag.company_id = companies.id
     *                AND tags.slug NOT LIKE 'src:scraping-%'
     *                AND tags.slug LIKE 'src:%')
     *
     * 🔴 Postgres « hashe » ce sous-plan : il le dé-corrèle et **balaie
     * `company_tag` EN ENTIER** pour construire la table de hachage. Mesuré sur
     * 150 000 fiches / 300 300 étiquettes : `Seq Scan on company_tag`, 67 ms.
     *
     * Et ce n'est pas une pente, c'est une FALAISE. En production `company_tag`
     * porte plusieurs étiquettes par fiche au-dessus de 4,29 M de fiches : la
     * table de hachage finit par déborder `work_mem`, et Postgres retombe alors
     * en **ré-exécution du sous-select ligne par ligne**. D'où les 3 minutes, et
     * d'où le gel : l'écran d'accueil tient une connexion du pool pendant tout
     * ce temps, à chaque session ouverte.
     *
     * ── LE CORRECTIF A DEUX MOITIÉS, ET UNE SEULE NE SUFFIT PAS ─────────────
     *
     * Mesuré aujourd'hui, même volume, requête de l'écran d'accueil :
     *
     *   forme d'origine (jointure dans le sous-select) . Seq Scan     67,3 ms
     *   ids en sous-requête `IN (SELECT …)` + index .... Seq Scan     73,8 ms
     *   ids LITTÉRAUX, sans index ...................... Seq Scan     80,3 ms
     *   ids LITTÉRAUX + index (tag_id, company_id) ..... Index Only    0,90 ms
     *
     *   1. **Résoudre les étiquettes en amont**, en une requête, et passer des
     *      identifiants. `tags` compte quelques dizaines de lignes ; la garder
     *      dans le sous-select oblige Postgres à raisonner sur une jointure
     *      dont il estime très mal la sélectivité (225 225 lignes estimées
     *      contre 300 réelles) — d'où le balayage. Une liste d'entiers, elle,
     *      s'appuie sur les statistiques réelles de `tag_id`.
     *      ⚠️ `IN (SELECT …)` NE SUFFIT PAS : mesuré ci-dessus, même défaut.
     *
     *   2. **Un index `company_tag (tag_id, company_id)`**
     *      (`idx_company_tag_tag`, migration 2026_08_20_110000). Sans lui, même
     *      avec des identifiants littéraux, on reste en balayage (80,3 ms) :
     *      la clé primaire est `(company_id, tag_id)` et ne sert donc PAS une
     *      recherche PAR étiquette.
     *      → Patron `A-011` : la table jumelle `candidate_tag` porte cet index
     *        depuis le 2026-08-14 (`idx_candidate_tag_tag`). Il n'avait jamais
     *        été porté sur `company_tag`, la seule des deux qui porte 4,29 M.
     *
     * `namespace` plutôt que `slug LIKE 'src:%'` : c'est une colonne GENERATED
     * (`NULLIF(split_part(slug, ':', 1), slug)`) strictement équivalente au
     * motif, et couverte par `idx_tags_workspace_namespace`.
     *
     * Garde : `tests/Feature/Infra/VolumeDeProductionHubConsoleTest.php`.
     *
     * @param  Builder<Company>  $query
     */
    private function applyTemperature(Builder $query, mixed $temperature, string $workspaceId): void
    {
        $mode = is_string($temperature) ? $temperature : 'actifs';

        if ($mode === 'tous') {
            return;
        }

        $etiquettesHumaines = $this->etiquettesDeProvenanceHumaine($workspaceId);

        // « La fiche porte-t-elle une provenance AUTRE que le scraping ? »
        // Plus AUCUNE jointure ici : une sonde sur `company_tag`, servie par
        // `idx_company_tag_tag`.
        $hasHumanSource = static function (\Illuminate\Database\Query\Builder $sub) use ($etiquettesHumaines): void {
            $sub->select(DB::raw('1'))
                ->from('company_tag')
                ->whereColumn('company_tag.company_id', 'companies.id')
                ->whereIn('company_tag.tag_id', $etiquettesHumaines);
        };

        // AUCUNE étiquette de provenance humaine dans ce workspace : la
        // condition est alors constante, et on l'ÉCRIT plutôt que de faire
        // évaluer 4,29 M de fois un `EXISTS` dont on sait qu'il est faux.
        // Mesuré : 0,39 ms au lieu de 80 ms.
        if ($etiquettesHumaines === []) {
            $query->where(
                'lifecycle_stage',
                $mode === 'froids' ? '=' : '!=',
                'nouveau',
            );

            return;
        }

        if ($mode === 'froids') {
            $query->where('lifecycle_stage', 'nouveau')->whereNotExists($hasHumanSource);

            return;
        }

        $query->where(function (Builder $q) use ($hasHumanSource): void {
            $q->where('lifecycle_stage', '!=', 'nouveau')->orWhereExists($hasHumanSource);
        });
    }

    /**
     * Les identifiants des étiquettes de provenance NON-scraping du workspace.
     *
     * Une requête, sur une table de quelques dizaines de lignes, servie par
     * `idx_tags_workspace_namespace` — mesurée à 0,056 ms. C'est le prix à
     * payer pour retirer la jointure du sous-select corrélé, et il est sans
     * commune mesure avec ce qu'il fait économiser (cf. `applyTemperature`).
     *
     * @return list<int>
     */
    private function etiquettesDeProvenanceHumaine(string $workspaceId): array
    {
        $ids = DB::table('tags')
            ->where('workspace_id', $workspaceId)
            // `namespace` = partie avant le « : », NULL s'il n'y en a pas.
            // Strictement équivalent à `slug LIKE 'src:%'`, mais indexé.
            ->where('namespace', 'src')
            ->where('slug', 'not like', 'src:scraping-%')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        // `array_values` : `pluck()` ne garantit pas des clés contiguës, et une
        // `list<int>` est ce que le type de retour promet.
        return array_values(array_map(static fn ($id): int => (int) $id, $ids));
    }

    /**
     * Recherche texte : dénomination, SIREN, ou personne rattachée.
     *
     * ── `G41-002`, ET LE COMMENTAIRE QUI MENTAIT ────────────────────────────
     *
     * Ce bloc portait jusqu'au 2026-08-20 la justification suivante :
     *
     *   « Volontairement un préfixe (`ILIKE 'x%'`) et non `%x%` : `denomination`
     *     porte un index B-tree (migration 2026_07_09_000004) et 4,29 M de
     *     lignes ne se scannent pas pour un caractère tapé. »
     *
     * 🔴 **Les deux moitiés sont fausses, et le schéma le dit.**
     *
     *   1. `denomination` NE PORTE AUCUN INDEX — vérifié à `\d companies` le
     *      2026-08-20. La migration 2026_07_09_000004 crée
     *      `idx_companies_denom_btree` sur `(workspace_id,
     *      denomination_normalized)` : une AUTRE colonne. C'est mot pour mot le
     *      patron de `G41-003`, « l'index censé le servir porte sur une autre
     *      colonne ».
     *   2. Même s'il existait, un B-tree ne servirait PAS un `ILIKE` : la
     *      comparaison insensible à la casse casse l'ordre sur lequel l'arbre
     *      est construit.
     *
     * Le préfixe ne rachetait donc rien. Mesuré sur 150 000 fiches :
     * `denomination ILIKE 'x%'` → **Parallel Seq Scan, 103 ms**, à chaque
     * caractère tapé. C'est le constat `G41-002`.
     *
     * ── CE QU'ON FAIT À LA PLACE ────────────────────────────────────────────
     *
     * On interroge `denomination_normalized`, couverte par l'index trigrammes
     * `idx_companies_denomination_trgm` depuis le 2026-05-16 — via
     * `RechercheDenomination`, PARTAGÉE avec le filtre `filter[denomination]`
     * qui souffrait du MÊME défaut (`G41-003`).
     *
     * On y gagne le `%x%` : la contrainte de préfixe n'était qu'une rustine
     * pour un index imaginaire, et personne ne saisit le premier mot d'une
     * raison sociale. « Martin » trouve désormais « Boulangerie Martin ». On y
     * gagne surtout la JUSTESSE : accents et articles, que `normalize_name()`
     * gomme des deux côtés (cf. `RechercheDenomination`).
     *
     * ── ⚠️ CE QUI N'EST **PAS** RÉGLÉ, ET IL FAUT LE SAVOIR ─────────────────
     *
     * Le constat `G41-002` n'est fermé QU'À MOITIÉ, et prétendre l'inverse
     * ferait croire le problème résolu.
     *
     * Cette recherche porte sur TROIS champs en OU (dénomination, SIREN, et un
     * `EXISTS` corrélé sur `contacts`). Postgres ne sait pas combiner un index
     * trigrammes avec un sous-select corrélé dans un `BitmapOr` : il retombe
     * sur un parcours de `idx_companies_ws_updated_id` avec filtre. Le plan est
     * donc **structurellement identique avant et après ce correctif** —
     * mesuré sur 150 000 fiches :
     *
     *     avant (denomination brute + OU contacts) ..... 354,3 ms
     *     après (colonne normalisée + OU contacts) ..... 157,7 ms
     *     branche dénomination SEULE ...................   2,0 ms
     *     terme sans aucun résultat, avec le OU ........ 531,7 ms
     *
     * Le gain 354 → 158 ms vient de ce qu'un `%x%` trouve ses 26 lignes plus
     * tôt qu'un préfixe, PAS d'un index.
     *
     * La piste mesurée : résoudre la branche `contacts` en amont, en une liste
     * d'identifiants littéraux — Postgres rend alors un vrai `BitmapOr`
     * (8,9 ms). Elle suppose un plafond sur cette liste (changement de
     * sémantique) ou un repli, et un index sur `lower(email)` qui n'existe pas.
     * Aucun constat de ce lot ne nomme `contacts` : arbitrage à Will.
     *
     * ⚠️ Les `contacts` restent donc en PRÉFIXE et en l'état, délibérément.
     *
     * Garde : `tests/Feature/Infra/VolumeDeProductionHubConsoleTest.php`, test
     * « G41-002 — RESTE OUVERT », marqué INCOMPLET et non vert.
     *
     * @param  Builder<Company>  $query
     */
    private function applySearch(Builder $query, mixed $q): void
    {
        if (! is_string($q) || trim($q) === '') {
            return;
        }

        $term = trim($q);

        $query->where(function (Builder $inner) use ($term): void {
            RechercheDenomination::et($inner, $term);

            if (preg_match('/^\d{3,9}$/', $term) === 1) {
                $inner->orWhere('siren', 'like', $term . '%');
            }

            $inner->orWhereHas('contacts', function (BuilderContract $c) use ($term): void {
                $c->where('last_name', 'ilike', $term . '%')
                    ->orWhere('email', 'ilike', $term . '%');
            });
        });
    }

    /**
     * Projection EXPLICITE. Renvoyer le modèle brut exposerait au fil de l'eau
     * toute colonne ajoutée par une migration future — y compris des colonnes
     * qui n'ont rien à faire dans une liste.
     *
     * @return array<string, mixed>
     */
    private function present(Company $company, bool $masquer): array
    {
        return [
            'id' => $company->id,
            'siren' => $company->siren,
            'denomination' => $company->denomination,
            'relation_type' => $company->relation_type,
            'lifecycle_stage' => $company->lifecycle_stage,
            'legal_basis' => $company->legal_basis,
            'city_name' => $company->city_name ?? $company->city,
            'department_code' => $company->department_code,
            'size_category' => $company->size_category,
            'email_generic' => $masquer
                ? MasquageCoordonnees::email($company->email_generic)
                : $company->email_generic,
            'updated_at' => $company->updated_at,
            'tags' => $company->tags->pluck('slug')->values()->all(),
            'contacts' => $company->contacts->map(static fn (Contact $contact): array => [
                'id' => $contact->id,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                'email' => $masquer ? MasquageCoordonnees::email($contact->email) : $contact->email,
                'phone' => $masquer ? MasquageCoordonnees::telephone($contact->phone) : $contact->phone,
                'person_key' => $contact->person_key,
            ])->values()->all(),
        ];
    }
}
