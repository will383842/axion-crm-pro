<?php

namespace App\Http\Controllers\Api;

use App\Models\Journalist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * API des JOURNALISTES (contacts rédaction). ⚠️ Données personnelles (RGPD).
 * Le champ `opt_out` reste visible (transparence) ; l'effacement = soft-delete.
 */
class JournalistsController extends ApiController
{
    public function index(Request $r): JsonResponse
    {
        $perPage = min(100, max(1, (int) $r->query('per_page', 25)));

        if (! Schema::hasTable('journalists')) {
            return $this->ok([
                'data' => [],
                'meta' => ['total' => 0, 'per_page' => $perPage, 'current_page' => 1, 'last_page' => 1],
            ]);
        }

        try {
            $page = $this->buildFilteredQuery()
                ->allowedIncludes(['media'])
                ->allowedSorts(['last_name', 'created_at'])
                ->defaultSort('last_name')
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
            Log::error('journalists.index failed', ['exception' => $e->getMessage()]);
            report($e);
            return $this->ok([
                'data' => [],
                'meta' => ['total' => 0, 'per_page' => $perPage, 'current_page' => 1, 'last_page' => 1],
                'degraded' => true,
            ]);
        }
    }

    private function buildFilteredQuery(): QueryBuilder
    {
        return QueryBuilder::for(Journalist::query()->whereNull('deleted_at'))
            ->allowedFilters([
                AllowedFilter::exact('media_id'),
                AllowedFilter::exact('beat'),
                AllowedFilter::exact('opt_out'),
                AllowedFilter::partial('last_name'),
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
        $filename = 'journalistes-' . now()->format('Y-m-d') . '.csv';
        $header = ['Prénom', 'Nom', 'Rôle', 'Rubrique', 'Email', 'Téléphone', 'Média', 'Opt-out', 'Source'];

        if (! Schema::hasTable('journalists') || $workspaceId === null) {
            return response()->streamDownload(function () use ($header) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, $header);
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        // Export = seulement ceux qui ne se sont PAS opposés (RGPD).
        $query = $this->buildFilteredQuery()
            ->where('workspace_id', $workspaceId)
            ->where('opt_out', false)
            ->with('media');

        return response()->streamDownload(function () use ($query, $header) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            $query->chunkById(1000, function ($journalists) use ($out) {
                foreach ($journalists as $j) {
                    fputcsv($out, [
                        $j->first_name,
                        $j->last_name,
                        $j->role,
                        $j->beat,
                        $j->email,
                        $j->phone,
                        $j->media?->name,
                        $j->opt_out ? 'oui' : 'non',
                        $j->source,
                    ]);
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function show(Journalist $journalist): JsonResponse
    {
        return $this->ok($journalist->load('media'));
    }

    /**
     * Droit d'opposition RGPD : bascule opt_out (le contact reste en base mais
     * exclu des exports/campagnes).
     */
    public function optOut(Journalist $journalist): JsonResponse
    {
        $journalist->update(['opt_out' => true]);
        return $this->ok($journalist);
    }

    /** Droit à l'effacement RGPD : soft-delete. */
    public function destroy(Journalist $journalist): JsonResponse
    {
        $journalist->delete();
        return response()->json(null, 204);
    }
}
