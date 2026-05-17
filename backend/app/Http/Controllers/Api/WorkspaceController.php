<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WorkspaceController extends ApiController
{
    /**
     * @OA\Get(path="/workspace", tags={"Workspace"}, summary="Workspace courant (settings + cost_cap)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="OK"))
     */
    public function show(Request $r): JsonResponse
    {
        // Sprint 18.9 — defensive : si la relation currentWorkspace lance (FK manquante,
        // workspace soft-deleted, etc.) on retourne null plutôt que 500.
        try {
            $user = $r->user();
            if (! $user) {
                return $this->ok(null);
            }
            return $this->ok($user->currentWorkspace);
        } catch (\Throwable $e) {
            Log::error('workspace.show failed', [
                'user_id'   => optional($r->user())->id,
                'exception' => $e->getMessage(),
            ]);
            report($e);
            return $this->ok(null);
        }
    }

    /**
     * @OA\Put(path="/workspace", tags={"Workspace"}, summary="Update settings workspace (Sprint 3)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function update(Request $r): JsonResponse { return $this->notImplemented('3'); }
}
