<?php

namespace App\Http\Controllers\Api;

use App\Jobs\EnrichCompanyJob;
use App\Models\Company;
use App\Services\Waterfall\WaterfallOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class CompaniesController extends ApiController
{
    public function __construct(private readonly WaterfallOrchestrator $waterfall) {}

    public function index(Request $r): JsonResponse
    {
        $perPage = min(100, max(1, (int) $r->query('per_page', 25)));
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
    }

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

    public function show(Company $company): JsonResponse
    {
        return $this->ok($company->load(['contacts', 'tags']));
    }

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

    public function destroy(Company $company): JsonResponse
    {
        $company->delete();
        return response()->json(null, 204);
    }

    public function enrich(Company $company): JsonResponse
    {
        $this->waterfall->enrich($company);
        return $this->ok($company->fresh()->load('contacts'));
    }

    public function bulkEnrich(Request $r): JsonResponse
    {
        $ids = $r->validate(['ids' => 'required|array|max:500', 'ids.*' => 'integer'])['ids'];
        foreach ($ids as $id) {
            EnrichCompanyJob::dispatch((int) $id);
        }
        return $this->ok(['queued' => count($ids)]);
    }

    public function recomputeScore(Company $company): JsonResponse
    {
        DB::statement('SELECT recompute_company_quality_score(?)', [$company->id]);
        return $this->ok($company->fresh());
    }
}
