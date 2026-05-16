<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

abstract class ApiController extends Controller
{
    protected function ok(mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json($data ?? ['ok' => true], $status);
    }

    protected function notImplemented(string $sprint): JsonResponse
    {
        return response()->json([
            'error'    => 'not_implemented',
            'message'  => "Endpoint à implémenter en Sprint $sprint.",
            'sprint'   => $sprint,
        ], 501);
    }
}
