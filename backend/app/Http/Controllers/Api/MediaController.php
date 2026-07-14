<?php

namespace App\Http\Controllers\Api;

use App\Models\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * API des MÉDIAS (TV, radio, presse, portails, agences, blogs, émissions).
 * Calquée sur {@see CompaniesController} : index paginé + query filtrée partagée
 * avec l'export CSV streamé, scoping workspace explicite, défensif (jamais 500).
 */
class MediaController extends ApiController
{
    public function index(Request $r): JsonResponse
    {
        $perPage = min(100, max(1, (int) $r->query('per_page', 25)));

        if (! Schema::hasTable('media')) {
            return $this->ok([
                'data' => [],
                'meta' => ['total' => 0, 'per_page' => $perPage, 'current_page' => 1, 'last_page' => 1],
            ]);
        }

        try {
            $page = $this->buildFilteredQuery()
                ->allowedSorts(['name', 'enriched_at', 'created_at', 'media_type'])
                ->defaultSort('name')
                ->paginate($perPage);

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
            Log::error('media.index failed', ['exception' => $e->getMessage()]);
            report($e);
            return $this->ok([
                'data' => [],
                'meta' => ['total' => 0, 'per_page' => $perPage, 'current_page' => 1, 'last_page' => 1],
                'degraded' => true,
            ]);
        }
    }

    /**
     * Query filtrée partagée liste (index) ↔ export → l'export = la liste affichée.
     */
    private function buildFilteredQuery(): QueryBuilder
    {
        return QueryBuilder::for(Media::query()->whereNull('deleted_at'))
            ->allowedFilters([
                AllowedFilter::exact('media_type'),
                AllowedFilter::exact('media_family'),
                AllowedFilter::exact('periodicity'),
                AllowedFilter::exact('editorial_theme'),
                AllowedFilter::exact('diffusion_zone'),
                AllowedFilter::exact('department_code'),
                AllowedFilter::exact('region_code'),
                AllowedFilter::exact('enrich_status'),
                AllowedFilter::exact('source'),
                AllowedFilter::partial('name'),
                AllowedFilter::callback('has_website', function ($query, $value) {
                    filter_var($value, FILTER_VALIDATE_BOOLEAN)
                        ? $query->whereNotNull('website')
                        : $query->whereNull('website');
                }),
                AllowedFilter::callback('has_email', function ($query, $value) {
                    filter_var($value, FILTER_VALIDATE_BOOLEAN)
                        ? $query->whereNotNull('email')
                        : $query->whereNull('email');
                }),
            ]);
    }

    public function export(Request $r): StreamedResponse
    {
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
        $filename = 'medias-' . now()->format('Y-m-d') . '.csv';
        $header = ['Nom', 'Type', 'Famille', 'Périodicité', 'Thème', 'Zone', 'Département', 'Région', 'Ville', 'Éditeur', 'Site web', 'Email rédaction', 'Téléphone', 'N° CPPAP', 'N° ARCOM'];

        if (! Schema::hasTable('media') || $workspaceId === null) {
            return response()->streamDownload(function () use ($header) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, $header);
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        // Scope EXPLICITE workspace (défense principale contre fuite inter-tenants).
        $query = $this->buildFilteredQuery()->where('workspace_id', $workspaceId);

        return response()->streamDownload(function () use ($query, $header) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 → Excel FR lit les accents
            fputcsv($out, $header);
            $query->chunkById(1000, function ($medias) use ($out) {
                foreach ($medias as $m) {
                    fputcsv($out, [
                        $m->name,
                        $m->media_type,
                        $m->media_family === 'audiovisual_production' ? 'Production audiovisuelle' : 'Rédactionnel',
                        $m->periodicity,
                        $m->editorial_theme,
                        $m->diffusion_zone,
                        $m->department_code,
                        $m->region_code,
                        $m->city,
                        $m->publisher,
                        $m->website,
                        $m->email,
                        $m->phone,
                        $m->cppap_number,
                        $m->arcom_id,
                    ]);
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function show(Media $media): JsonResponse
    {
        return $this->ok($media->load(['journalists', 'parent', 'children', 'company']));
    }
}
