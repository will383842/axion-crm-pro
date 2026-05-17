<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsersController extends ApiController
{
    /**
     * @OA\Get(path="/users", tags={"Users"}, summary="Liste des users du workspace",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="OK"))
     */
    public function index(Request $r): JsonResponse { return $this->ok(['data' => []]); }

    /**
     * @OA\Post(path="/users", tags={"Users"}, summary="Invite un user (Sprint 3)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function store(Request $r): JsonResponse { return $this->notImplemented('3'); }

    /**
     * @OA\Put(path="/users/{user}", tags={"Users"}, summary="Update user (rôle, locale) (Sprint 3)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="user", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function update(Request $r, User $user): JsonResponse { return $this->notImplemented('3'); }

    /**
     * @OA\Delete(path="/users/{user}", tags={"Users"}, summary="Supprime user (Sprint 3)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="user", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function destroy(User $user): JsonResponse { return $this->notImplemented('3'); }
}
