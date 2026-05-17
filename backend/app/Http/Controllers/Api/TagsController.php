<?php

namespace App\Http\Controllers\Api;

use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class TagsController extends ApiController
{
    /**
     * @OA\Get(path="/tags", tags={"Tags"}, summary="Liste des tags du workspace",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="OK"))
     */
    public function index(Request $r): JsonResponse
    {
        if (! Schema::hasTable('tags')) {
            return $this->ok(['data' => []]);
        }

        try {
            $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
            $q = Tag::query()->orderBy('name');
            if ($workspaceId) {
                $q->where('workspace_id', $workspaceId);
            }
            return $this->ok(['data' => $q->limit(500)->get()]);
        } catch (\Throwable $e) {
            Log::error('tags.index failed', ['exception' => $e->getMessage()]);
            report($e);
            return $this->ok(['data' => [], 'degraded' => true]);
        }
    }

    /**
     * @OA\Post(path="/tags", tags={"Tags"}, summary="Crée un tag (Sprint 10)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function store(Request $r): JsonResponse { return $this->notImplemented('10'); }

    /**
     * @OA\Put(path="/tags/{tag}", tags={"Tags"}, summary="Update tag (Sprint 10)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="tag", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function update(Request $r, Tag $tag): JsonResponse { return $this->notImplemented('10'); }

    /**
     * @OA\Delete(path="/tags/{tag}", tags={"Tags"}, summary="Delete tag (Sprint 10)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="tag", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function destroy(Tag $tag): JsonResponse { return $this->notImplemented('10'); }
}
