<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\TagResource;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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
            $q = Tag::query()->orderBy('category')->orderBy('name');
            if ($workspaceId) {
                $q->where('workspace_id', $workspaceId);
            }
            if ($category = $r->query('category')) {
                $q->where('category', $category);
            }
            if ($kind = $r->query('kind')) {
                $q->where('kind', $kind);
            }

            // Ajoute count companies par tag (left join optimisé)
            $tags = $q->limit(500)->get();
            $tagIds = $tags->pluck('id')->all();
            $counts = empty($tagIds)
                ? collect()
                : DB::table('company_tag')
                    ->whereIn('tag_id', $tagIds)
                    ->select('tag_id', DB::raw('COUNT(*) as c'))
                    ->groupBy('tag_id')
                    ->pluck('c', 'tag_id');

            return $this->ok([
                'data' => TagResource::collection($tags->map(function ($t) use ($counts) {
                    $t->companies_count = $counts->get($t->id, 0);
                    return $t;
                })),
            ]);
        } catch (\Throwable $e) {
            Log::error('tags.index failed', ['exception' => $e->getMessage()]);
            report($e);
            return $this->ok(['data' => [], 'degraded' => true]);
        }
    }

    /**
     * @OA\Post(path="/tags", tags={"Tags"}, summary="Crée un tag manuel",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=201, description="Created"))
     */
    public function store(Request $r): JsonResponse
    {
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
        if (! $workspaceId) {
            return $this->ok(['error' => 'workspace required'], 422);
        }

        $data = $r->validate([
            'slug'        => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9\-]+$/'],
            'name'        => ['required', 'string', 'max:120'],
            'color'       => ['nullable', 'string', 'max:20'],
            'category'    => ['nullable', 'string', 'in:geo,sector,size,intent,custom'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $slug = $data['slug'] ?? Str::slug($data['name'], '-');
        $existing = Tag::where('workspace_id', $workspaceId)->where('slug', $slug)->first();
        if ($existing) {
            return $this->ok(['error' => 'slug already exists', 'tag' => new TagResource($existing)], 409);
        }

        $tag = Tag::create([
            'workspace_id' => $workspaceId,
            'slug'         => $slug,
            'name'         => $data['name'],
            'color'        => $data['color'] ?? 'slate',
            'category'     => $data['category'] ?? 'custom',
            'kind'         => 'manual',
            'description'  => $data['description'] ?? null,
            'rules'        => [],
        ]);

        return $this->ok(['data' => new TagResource($tag)], 201);
    }

    /**
     * @OA\Put(path="/tags/{tag}", tags={"Tags"}, summary="Update tag",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="OK"))
     */
    public function update(Request $r, Tag $tag): JsonResponse
    {
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
        if ($workspaceId && $tag->workspace_id !== $workspaceId) {
            return $this->ok(['error' => 'not found'], 404);
        }
        // Garde-fou : on ne modifie pas les tags auto/llm (générés par le système)
        if ($tag->kind !== 'manual') {
            return $this->ok(['error' => 'cannot update auto/llm tag'], 403);
        }

        $data = $r->validate([
            'name'        => ['sometimes', 'required', 'string', 'max:120'],
            'color'       => ['sometimes', 'nullable', 'string', 'max:20'],
            'category'    => ['sometimes', 'nullable', 'string', 'in:geo,sector,size,intent,custom'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $tag->update($data);
        return $this->ok(['data' => new TagResource($tag->fresh())]);
    }

    /**
     * @OA\Delete(path="/tags/{tag}", tags={"Tags"}, summary="Delete tag",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="OK"))
     */
    public function destroy(Tag $tag): JsonResponse
    {
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
        if ($workspaceId && $tag->workspace_id !== $workspaceId) {
            return $this->ok(['error' => 'not found'], 404);
        }
        if ($tag->kind !== 'manual') {
            return $this->ok(['error' => 'cannot delete auto/llm tag (will be re-created by AutoTagger)'], 403);
        }
        $tag->delete();
        return $this->ok(['ok' => true]);
    }
}
