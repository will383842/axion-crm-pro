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
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            $query = $this->buildFilteredQuery()
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
     * Query filtrée partagée entre la liste (index) et l'export.
     * Applique les MÊMES filtres → l'export = exactement la liste affichée.
     * (Les 4 derniers filtres étaient envoyés par le front mais absents ici → ignorés.)
     */
    private function buildFilteredQuery(): QueryBuilder
    {
        return QueryBuilder::for(Company::query()->whereNull('deleted_at'))
            ->allowedFilters([
                AllowedFilter::exact('naf'),
                AllowedFilter::exact('size_category'),
                AllowedFilter::exact('effectif', 'effectif_range'),
                AllowedFilter::exact('priority'),
                AllowedFilter::exact('discovery_source'),
                AllowedFilter::exact('prospection_status'),
                AllowedFilter::exact('department_code'),
                AllowedFilter::exact('region_code'),
                AllowedFilter::exact('sector_main'),
                AllowedFilter::exact('quality', 'quality_badge'),
                AllowedFilter::partial('denomination'),
                AllowedFilter::partial('postcode'),
            ]);
    }

    /**
     * @OA\Get(
     *     path="/companies/export",
     *     tags={"Companies"},
     *     summary="Exporte en CSV la liste filtrée (emails, téléphones, dirigeants) pour transfert/emailing",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="Fichier CSV (streamé)"),
     * )
     */
    public function export(Request $r): StreamedResponse
    {
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
        $filename = 'entreprises-' . now()->format('Y-m-d') . '.csv';
        $header = ['SIREN', 'Dénomination', 'NAF', 'Taille', 'Département', 'Ville', 'Email', 'Téléphone', 'Site web', 'Contacts / dirigeants', 'Spécialité(s) santé'];
        $hasSante = Schema::hasTable('health_practitioners');

        // Table absente ou pas de workspace → CSV vide (jamais 500, jamais de fuite).
        if (! Schema::hasTable('companies') || $workspaceId === null) {
            return response()->streamDownload(function () use ($header) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, $header);
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        // Scope EXPLICITE par workspace (défense principale : pas de fuite entre
        // tenants, indépendamment de l'état RLS pendant le streaming).
        $query = $this->buildFilteredQuery()
            ->where('workspace_id', $workspaceId)
            ->with($hasSante ? ['contacts', 'healthPractitioners'] : ['contacts']);

        return response()->streamDownload(function () use ($query, $header, $hasSante) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 → Excel FR lit les accents
            fputcsv($out, $header);
            // chunkById(id) = pagination stable en mémoire bornée (gros volumes OK).
            $query->chunkById(1000, function ($companies) use ($out) {
                foreach ($companies as $c) {
                    $contacts = $c->contacts
                        ->map(function ($ct) {
                            $name = trim(($ct->first_name ?? '') . ' ' . ($ct->last_name ?? ''));
                            $bits = array_filter([
                                $name,
                                $ct->role ? "({$ct->role})" : '',
                                $ct->email ?? '',
                                $ct->phone ?? '',
                            ]);
                            return trim(implode(' ', $bits));
                        })
                        ->filter()
                        ->implode(' | ');
                    $specialites = $hasSante
                        ? $c->healthPractitioners->pluck('specialite')->filter()->unique()->implode(', ')
                        : '';
                    fputcsv($out, [
                        $c->siren,
                        $c->denomination,
                        $c->naf,
                        $c->size_category,
                        $c->department_code,
                        $c->city_name,
                        $c->email_generic,
                        $c->phone,
                        $c->website,
                        $contacts,
                        $specialites,
                    ]);
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
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
        $relations = ['contacts', 'tags'];
        if (Schema::hasTable('health_practitioners')) {
            $relations[] = 'healthPractitioners';
        }
        return $this->ok($company->load($relations));
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
