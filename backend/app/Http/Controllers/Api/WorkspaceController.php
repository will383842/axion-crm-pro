<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceController extends ApiController
{
    /**
     * @OA\Get(path="/workspace", tags={"Workspace"}, summary="Workspace courant (settings + cost_cap)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="OK"))
     */
    public function show(Request $r): JsonResponse { return $this->ok($r->user()?->currentWorkspace); }

    /**
     * @OA\Put(path="/workspace", tags={"Workspace"}, summary="Update settings workspace (Sprint 3)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function update(Request $r): JsonResponse { return $this->notImplemented('3'); }
}
