<?php

namespace App\Http\Controllers\Api;

use App\Services\Audit\AuditHashChain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogsController extends ApiController
{
    public function __construct(private readonly AuditHashChain $chain) {}

    /**
     * @OA\Get(path="/audit-logs", tags={"AuditLogs"}, summary="Audit logs paginés (hash-chained)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="OK"))
     */
    public function index(Request $r): JsonResponse { return $this->ok(['data' => []]); }

    /**
     * @OA\Get(path="/audit-logs/verify-chain", tags={"AuditLogs"}, summary="Vérifie l'intégrité de la chaîne de hashs SHA-256",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="Booléen valid"))
     */
    public function verifyChain(): JsonResponse
    {
        return $this->ok(['valid' => $this->chain->verifyChain()]);
    }
}
