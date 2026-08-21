<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreEmailAudienceRequest;
use App\Http\Resources\EmailAudienceResource;
use App\Models\AudienceMember;
use App\Models\Contact;
use App\Models\EmailAudience;
use App\Services\Audiences\AudienceBuilderService;
use App\Support\MasquageCoordonnees;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AudiencesController extends ApiController
{
    public function __construct(private readonly AudienceBuilderService $builder) {}

    /**
     * @OA\Get(path="/audiences", tags={"Audiences"}, summary="Liste audiences",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="OK"))
     */
    public function index(Request $r): JsonResponse
    {
        if (! Schema::hasTable('email_audiences')) {
            return $this->ok(['data' => []]);
        }
        try {
            $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
            $q = EmailAudience::query()->whereNull('deleted_at')->orderByDesc('created_at');
            if ($workspaceId) {
                $q->where('workspace_id', $workspaceId);
            }
            return $this->ok(['data' => EmailAudienceResource::collection($q->limit(200)->get())]);
        } catch (\Throwable $e) {
            Log::error('audiences.index failed', ['error' => $e->getMessage()]);
            return $this->ok(['data' => [], 'degraded' => true]);
        }
    }

    public function store(StoreEmailAudienceRequest $request): JsonResponse
    {
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
        if (! $workspaceId) {
            return $this->ok(['error' => 'workspace required'], 422);
        }
        $data = $request->validated();

        $audience = EmailAudience::create([
            'workspace_id' => $workspaceId,
            'name'         => $data['name'],
            'description'  => $data['description'] ?? null,
            'criteria'     => $data['criteria'],
            'is_active'    => $data['is_active'] ?? true,
            'auto_refresh' => $data['auto_refresh'] ?? true,
            'created_by'   => optional($request->user())->id,
        ]);

        // First refresh inline (rapide pour audience nouvelle)
        try {
            $this->builder->refresh($audience);
        } catch (\Throwable $e) {
            Log::warning('audience initial refresh failed', ['id' => $audience->id, 'error' => $e->getMessage()]);
        }

        return $this->ok(['data' => new EmailAudienceResource($audience->fresh())], 201);
    }

    public function show(EmailAudience $audience): JsonResponse
    {
        $this->assertWorkspace($audience);
        return $this->ok(['data' => new EmailAudienceResource($audience)]);
    }

    public function update(Request $request, EmailAudience $audience): JsonResponse
    {
        $this->assertWorkspace($audience);
        $data = $request->validate([
            'name'         => ['sometimes', 'required', 'string', 'max:160'],
            'description'  => ['sometimes', 'nullable', 'string', 'max:1000'],
            'criteria'     => ['sometimes', 'required', 'array'],
            'is_active'    => ['sometimes', 'boolean'],
            'auto_refresh' => ['sometimes', 'boolean'],
        ]);
        $audience->update($data);
        return $this->ok(['data' => new EmailAudienceResource($audience->fresh())]);
    }

    public function destroy(EmailAudience $audience): JsonResponse
    {
        $this->assertWorkspace($audience);
        $audience->delete();
        return $this->ok(['ok' => true]);
    }

    /**
     * POST /audiences/preview — count companies/contacts pour criteria donnés (sans persist).
     */
    public function preview(Request $request): JsonResponse
    {
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
        if (! $workspaceId) {
            return $this->ok(['companies' => 0, 'contacts' => 0]);
        }
        $request->validate(['criteria' => ['required', 'array']]);
        try {
            $result = $this->builder->preview($workspaceId, $request->input('criteria', []));
        } catch (\Throwable $e) {
            Log::warning('audiences.preview failed', ['error' => $e->getMessage()]);
            return $this->ok(['companies' => 0, 'contacts' => 0, 'error' => 'preview_failed']);
        }
        return $this->ok($result);
    }

    public function refresh(EmailAudience $audience): JsonResponse
    {
        $this->assertWorkspace($audience);
        try {
            $this->builder->refresh($audience);
        } catch (\Throwable $e) {
            Log::error('audiences.refresh failed', ['id' => $audience->id, 'error' => $e->getMessage()]);
            return $this->ok(['error' => 'refresh failed'], 500);
        }
        return $this->ok(['data' => new EmailAudienceResource($audience->fresh())]);
    }

    public function members(EmailAudience $audience, Request $request): JsonResponse
    {
        $this->assertWorkspace($audience);
        $limit = max(1, min(500, (int) $request->query('limit', 50)));

        $rows = DB::table('audience_members as am')
            ->leftJoin('companies as c', 'c.id', '=', 'am.company_id')
            ->leftJoin('contacts as ct', 'ct.id', '=', 'am.contact_id')
            ->where('am.audience_id', $audience->id)
            ->select(
                'am.id', 'am.added_at',
                'c.id as company_id', 'c.denomination', 'c.department_code', 'c.size_category', 'c.sector_main',
                'ct.id as contact_id', 'ct.first_name', 'ct.last_name', 'ct.email',
            )
            ->orderByDesc('am.added_at')
            ->limit($limit)
            ->get();

        // 🔴 SITE JUMEAU de B12-002 / F36-006, et le plus volumineux : `limit`
        // monte jusqu'a 500, et une audience est PRECISEMENT une selection de
        // personnes a demarcher. Un compte en lecture seule y lisait
        // `ct.email` en clair, 500 par appel, sans plafond d'export ni trace.
        //
        // `DB::table(...)->get()` rend des `stdClass`, pas des modeles : c'est
        // la branche `stdClass` de `masquer()` qui travaille ici. Sans elle,
        // cet appel aurait traverse la collection SANS RIEN FAIRE, et le vert
        // aurait ete un vert de facade.
        return $this->ok(['data' => MasquageCoordonnees::masquerSiRequis($rows)]);
    }

    /**
     * 🔴 CONSTAT B12-001 / F36-005. Cette methode etait FAIL-OPEN : `if ($workspaceId && ...)`
     * ne refusait rien quand le contexte manquait (« tolerant en test/dev »).
     * Rien ne distingue un test d'une production, et la tolerance devenait la
     * regle des qu'un appel arrivait avant le middleware.
     *
     * Elle delegue desormais a `ApiController::refuserHorsEspace()`, qui repond
     * 404 quand elle ne sait pas -- et 404, non 403 : « interdit » confirmerait
     * l'existence de la fiche a qui balaie les identifiants.
     */
    private function assertWorkspace(EmailAudience $audience): void
    {
        $this->refuserHorsEspace($audience);
    }
}
