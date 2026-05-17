<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class UsersController extends ApiController
{
    /**
     * @OA\Get(path="/users", tags={"Users"}, summary="Liste des users du workspace",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="OK"))
     */
    public function index(Request $r): JsonResponse
    {
        // Sprint 18.9 — defensive : retourne au minimum un tableau vide.
        if (! Schema::hasTable('users')) {
            return $this->ok(['data' => []]);
        }

        try {
            $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
            if (! $workspaceId) {
                return $this->ok(['data' => []]);
            }

            $users = User::query()
                ->where('current_workspace_id', $workspaceId)
                ->select(['id', 'email', 'name', 'current_workspace_id', 'first_login_completed_at', 'two_factor_enabled', 'last_login_at'])
                ->orderBy('name')
                ->limit(200)
                ->get();

            return $this->ok(['data' => $users]);
        } catch (\Throwable $e) {
            Log::error('users.index failed', ['exception' => $e->getMessage()]);
            report($e);
            return $this->ok(['data' => [], 'degraded' => true]);
        }
    }

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
