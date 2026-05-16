<?php

namespace App\Http\Controllers\Api;

use App\Services\Audit\AuditHashChain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogsController extends ApiController
{
    public function __construct(private readonly AuditHashChain $chain) {}

    public function index(Request $r): JsonResponse { return $this->ok(['data' => []]); }

    public function verifyChain(): JsonResponse
    {
        return $this->ok(['valid' => $this->chain->verifyChain()]);
    }
}
