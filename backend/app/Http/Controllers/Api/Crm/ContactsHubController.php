<?php

namespace App\Http\Controllers\Api\Crm;

use App\Crm\Taxonomy;
use App\Models\Company;
use App\Models\Contact;
use App\Support\CompanyQueryFilters;
use App\Support\MasquageCoordonnees;
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
     * (conception §2.2). Une seule requête agrégée : un compteur par entrée de
     * menu aurait fait huit requêtes à chaque rendu de page.
     */
    public function counts(Request $request): JsonResponse
    {
        $workspaceId = $this->businessWorkspace($request);

        /** @var array<string, int> $byType */
        $byType = array_fill_keys(Taxonomy::BUSINESS_RELATION_TYPES, 0);
        /** @var array<string, int> $byStage */
        $byStage = array_fill_keys(Taxonomy::BUSINESS_LIFECYCLE_STAGES, 0);

        $rows = DB::table('companies')
            ->selectRaw('relation_type, lifecycle_stage, count(*) AS total')
            ->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at')
            ->groupBy('relation_type', 'lifecycle_stage')
            ->get();

        $total = 0;
        foreach ($rows as $row) {
            $count = (int) $row->total;
            $total += $count;

            $type = is_string($row->relation_type) ? $row->relation_type : '';
            if (array_key_exists($type, $byType)) {
                $byType[$type] += $count;
            }

            $stage = is_string($row->lifecycle_stage) ? $row->lifecycle_stage : '';
            if (array_key_exists($stage, $byStage)) {
                $byStage[$stage] += $count;
            }
        }

        return $this->ok([
            'total' => $total,
            'by_relation_type' => $byType,
            'by_lifecycle_stage' => $byStage,
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
        $this->applyTemperature($base, $validated['temperature'] ?? null);
        $this->applySearch($base, $validated['q'] ?? null);

        $sortKey = is_string($validated['sort'] ?? null) ? $validated['sort'] : 'recent';
        $column = self::SORTS[$sortKey] ?? 'updated_at';

        $builder = QueryBuilder::for($base)
            ->allowedFilters(CompanyQueryFilters::allowedWithTaxonomy());

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
     * @param  Builder<Company>  $query
     */
    private function applyTemperature(Builder $query, mixed $temperature): void
    {
        $mode = is_string($temperature) ? $temperature : 'actifs';

        if ($mode === 'tous') {
            return;
        }

        // « La fiche porte-t-elle une provenance AUTRE que le scraping ? »
        $hasHumanSource = static function (\Illuminate\Database\Query\Builder $sub): void {
            $sub->select(DB::raw('1'))
                ->from('company_tag')
                ->join('tags', 'tags.id', '=', 'company_tag.tag_id')
                ->whereColumn('company_tag.company_id', 'companies.id')
                ->where('tags.slug', 'not like', 'src:scraping-%')
                ->where('tags.slug', 'like', 'src:%');
        };

        if ($mode === 'froids') {
            $query->where('lifecycle_stage', 'nouveau')->whereNotExists($hasHumanSource);

            return;
        }

        $query->where(function (Builder $q) use ($hasHumanSource): void {
            $q->where('lifecycle_stage', '!=', 'nouveau')->orWhereExists($hasHumanSource);
        });
    }

    /**
     * Recherche texte : dénomination, SIREN, ou personne rattachée. Volontairement
     * un préfixe (`ILIKE 'x%'`) et non `%x%` : `denomination` porte un index
     * B-tree (migration 2026_07_09_000004) et 4,29 M de lignes ne se scannent
     * pas pour un caractère tapé.
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
            $inner->where('denomination', 'ilike', $term . '%');

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
