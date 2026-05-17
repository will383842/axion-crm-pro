<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationsController extends ApiController
{
    /**
     * @OA\Get(path="/notifications", tags={"Notifications"}, summary="Liste notifications in-app non lues + récentes",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="OK"))
     */
    public function index(Request $r): JsonResponse { return $this->ok(['data' => []]); }

    /**
     * @OA\Post(path="/notifications/{n}/read", tags={"Notifications"}, summary="Marque comme lue",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="n", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=501, description="Not implemented (Sprint 10)"))
     */
    public function markRead(int $n): JsonResponse { return $this->notImplemented('10'); }

    /**
     * @OA\Post(path="/notifications/read-all", tags={"Notifications"}, summary="Marque toutes comme lues",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=501, description="Not implemented (Sprint 10)"))
     */
    public function markAllRead(): JsonResponse { return $this->notImplemented('10'); }
}
