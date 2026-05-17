<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditLog;
use App\Services\Audit\AuditHashChain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AuditLogsController extends ApiController
{
    public function __construct(private readonly AuditHashChain $chain) {}

    /**
     * @OA\Get(path="/audit-logs", tags={"AuditLogs"}, summary="Audit logs paginés (hash-chained)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="OK"))
     */
    public function index(Request $r): JsonResponse
    {
        if (! Schema::hasTable('audit_logs')) {
            return $this->ok(['data' => []]);
        }

        try {
            $page = AuditLog::query()->orderByDesc('id')->paginate(50);
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
            Log::error('audit-logs.index failed', ['exception' => $e->getMessage()]);
            report($e);
            return $this->ok(['data' => [], 'degraded' => true]);
        }
    }

    /**
     * @OA\Get(path="/audit-logs/verify-chain", tags={"AuditLogs"}, summary="Vérifie l'intégrité de la chaîne de hashs SHA-256",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="Booléen valid"))
     */
    public function verifyChain(): JsonResponse
    {
        try {
            return $this->ok(['valid' => $this->chain->verifyChain()]);
        } catch (\Throwable $e) {
            Log::error('audit-logs.verify-chain failed', ['exception' => $e->getMessage()]);
            report($e);
            return $this->ok(['valid' => false, 'degraded' => true]);
        }
    }
}
