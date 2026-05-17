<?php

namespace App\Http\Controllers\Api;

use App\Jobs\EnrichCompanyJob;
use App\Models\Company;
use App\Services\Waterfall\WaterfallOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class CompaniesController extends ApiController
{
    public function __construct(private readonly WaterfallOrchestrator $waterfall) {}

    /**
     * @OA\Get(
     *     path="/companies",
     *     tags={"Companies"},
     *     summary="Liste paginée des entreprises du workspace courant",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=25, maximum=100)),
     *     @OA\Parameter(name="filter[naf]", in="query", @OA\Schema(type="string", example="6201Z")),
     *     @OA\Parameter(name="filter[size_category]", in="query", @OA\Schema(type="string", enum={"tpe","pme","eti","ge"})),
     *     @OA\Parameter(name="filter[priority]", in="query", @OA\Schema(type="string", enum={"haute","moyenne","basse","gelee"})),
     *     @OA\Parameter(name="filter[denomination]", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="sort", in="query", @OA\Schema(type="string", example="-quality_score")),
     *     @OA\Response(response=200, description="Liste paginée"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     * )
     */
    public function index(Request $r): JsonResponse
    {
        $perPage = min(100, max(1, (int) $r->query('per_page', 25)));

        // Sprint 18.9 — defensive : table absente en env fraîche → liste vide
        if (! Schema::hasTable('companies')) {
            return $this->ok([
                'data' => [],
                'meta' => ['total' => 0, 'per_page' => $perPage, 'current_page' => 1, 'last_page' => 1],
            ]);
        }

        try {
            $query = QueryBuilder::for(Company::query()->whereNull('deleted_at'))
                ->allowedFilters([
                    AllowedFilter::exact('naf'),
                    AllowedFilter::exact('size_category'),
                    AllowedFilter::exact('priority'),
                    AllowedFilter::exact('discovery_source'),
                    AllowedFilter::partial('denomination'),
                    AllowedFilter::partial('postcode'),
                ])
                ->allowedSorts(['quality_score', 'enriched_at', 'denomination', 'created_at'])
                ->defaultSort('-quality_score');

            $page = $query->paginate($perPage);
            return $this->ok([
                'data' => $page->items(),
                'meta' => [
                    'total'        => $page->total(),
                    'per_page'     => $page->perPage(),
                    'current_page' => $page->currentPage(),
                    'last_page'    => $page->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('companies.index failed', ['exception' => $e->getMessage()]);
            report($e);
            return $this->ok([
                'data' => [],
                'meta' => ['total' => 0, 'per_page' => $perPage, 'current_page' => 1, 'last_page' => 1],
                'degraded' => true,
            ]);
        }
    }

    /**
     * @OA\Post(
     *     path="/companies",
     *     tags={"Companies"},
     *     summary="Crée une entreprise manuellement (SIREN obligatoire)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"siren"},
     *         @OA\Property(property="siren", type="string", example="123456789"),
     *         @OA\Property(property="denomination", type="string"),
     *         @OA\Property(property="discovery_source", type="string"),
     *     )),
     *     @OA\Response(response=201, description="Créée"),
     *     @OA\Response(response=422, description="Validation"),
     * )
     */
    public function store(Request $r): JsonResponse
    {
        $validated = $r->validate([
            'siren'            => ['required', 'string', 'size:9', 'regex:/^\d{9}$/'],
            'denomination'     => ['nullable', 'string', 'max:255'],
            'discovery_source' => ['nullable', 'string', 'max:64'],
        ]);
        $company = Company::create([
            'workspace_id'     => app()->bound('workspace.id') ? app('workspace.id') : null,
            'siren'            => $validated['siren'],
            'denomination'     => $validated['denomination'] ?? null,
            'discovery_source' => $validated['discovery_source'] ?? 'manual',
        ]);
        return $this->ok($company, 201);
    }

    /**
     * @OA\Get(
     *     path="/companies/{company}",
     *     tags={"Companies"},
     *     summary="Détail d'une entreprise (contacts + tags inclus)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="company", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK"),
     *     @OA\Response(response=404, description="Not found"),
     * )
     */
    public function show(Company $company): JsonResponse
    {
        return $this->ok($company->load(['contacts', 'tags']));
    }

    /**
     * @OA\Put(
     *     path="/companies/{company}",
     *     tags={"Companies"},
     *     summary="Met à jour une entreprise (champs éditables côté commercial)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="company", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(@OA\JsonContent(
     *         @OA\Property(property="priority", type="string", enum={"haute","moyenne","basse","gelee"}),
     *         @OA\Property(property="denomination", type="string"),
     *         @OA\Property(property="website", type="string", format="url"),
     *         @OA\Property(property="phone", type="string"),
     *         @OA\Property(property="linkedin_url", type="string", format="url"),
     *     )),
     *     @OA\Response(response=200, description="Updated"),
     * )
     */
    public function update(Request $r, Company $company): JsonResponse
    {
        $validated = $r->validate([
            'priority'     => ['nullable', Rule::in(['haute', 'moyenne', 'basse', 'gelee'])],
            'denomination' => ['nullable', 'string', 'max:255'],
            'website'      => ['nullable', 'url', 'max:255'],
            'phone'        => ['nullable', 'string', 'max:32'],
            'linkedin_url' => ['nullable', 'url', 'max:255'],
        ]);
        $company->update($validated);
        return $this->ok($company);
    }

    /**
     * @OA\Delete(
     *     path="/companies/{company}",
     *     tags={"Companies"},
     *     summary="Soft-delete une entreprise (deleted_at posé)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="company", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=204, description="No content"),
     * )
     */
    public function destroy(Company $company): JsonResponse
    {
        $company->delete();
        return response()->json(null, 204);
    }

    /**
     * @OA\Post(
     *     path="/companies/{company}/enrich",
     *     tags={"Companies"},
     *     summary="Déclenche l'enrichissement waterfall (NAF→SIRENE→LLM→scrape)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="company", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Entreprise enrichie"),
     * )
     */
    public function enrich(Company $company): JsonResponse
    {
        $this->waterfall->enrich($company);
        return $this->ok($company->fresh()->load('contacts'));
    }

    /**
     * @OA\Post(
     *     path="/companies/bulk-enrich",
     *     tags={"Companies"},
     *     summary="Enrichit en bulk jusqu'à 500 entreprises via job Horizon",
     *     security={{"sanctumCookie":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"ids"},
     *         @OA\Property(property="ids", type="array", maxItems=500, @OA\Items(type="integer")),
     *     )),
     *     @OA\Response(response=200, description="Jobs queués"),
     * )
     */
    public function bulkEnrich(Request $r): JsonResponse
    {
        $ids = $r->validate(['ids' => 'required|array|max:500', 'ids.*' => 'integer'])['ids'];
        foreach ($ids as $id) {
            EnrichCompanyJob::dispatch((int) $id);
        }
        return $this->ok(['queued' => count($ids)]);
    }

    /**
     * @OA\Post(
     *     path="/companies/{company}/recompute-score",
     *     tags={"Companies"},
     *     summary="Recalcule le quality_score (fonction Postgres)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="company", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Score recalculé"),
     * )
     */
    public function recomputeScore(Company $company): JsonResponse
    {
        DB::statement('SELECT recompute_company_quality_score(?)', [$company->id]);
        return $this->ok($company->fresh());
    }
}
