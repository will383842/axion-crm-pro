<?php

namespace App\Http\Controllers\Api;

use App\Models\RgpdRequest;
use App\Services\Rgpd\GdprErasureService;
use App\Services\Rgpd\GdprPortabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RgpdRequestsController extends ApiController
{
    public function __construct(
        private readonly GdprErasureService $erasure,
        private readonly GdprPortabilityService $portability,
    ) {}

    /**
     * @OA\Get(path="/rgpd/requests", tags={"RGPD"}, summary="Liste demandes RGPD (art. 15-22)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="status", in="query", @OA\Schema(type="string", enum={"pending","done","rejected"})),
     *     @OA\Response(response=200, description="OK"))
     */
    public function index(Request $r): JsonResponse
    {
        $rows = RgpdRequest::query()
            ->when($r->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('requested_at')
            ->paginate(25);
        return $this->ok($rows);
    }

    /**
     * @OA\Post(path="/rgpd/requests", tags={"RGPD"}, summary="Crée une demande RGPD",
     *     security={{"sanctumCookie":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"type","subject_email"},
     *         @OA\Property(property="type", type="string", enum={"access","portability","erasure","rectification","opposition"}),
     *         @OA\Property(property="subject_email", type="string", format="email"),
     *         @OA\Property(property="metadata", type="object"))),
     *     @OA\Response(response=201, description="Créée"))
     */
    public function store(Request $r): JsonResponse
    {
        $validated = $r->validate([
            'type'          => ['required', Rule::in(['access', 'portability', 'erasure', 'rectification', 'opposition'])],
            'subject_email' => ['required', 'email', 'max:254'],
            'metadata'      => ['nullable', 'array'],
        ]);

        $req = RgpdRequest::create([
            'workspace_id'  => app()->bound('workspace.id') ? app('workspace.id') : null,
            'type'          => $validated['type'],
            'status'        => 'pending',
            'subject_email' => $validated['subject_email'],
            'requested_at'  => now(),
            'metadata'      => $validated['metadata'] ?? [],
        ]);
        return $this->ok($req, 201);
    }

    /**
     * @OA\Post(path="/rgpd/requests/{req}/process", tags={"RGPD"}, summary="Traite une demande (erasure / portability)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="req", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Traité"),
     *     @OA\Response(response=409, description="Déjà traité"))
     */
    public function process(Request $r, RgpdRequest $req): JsonResponse
    {
        if ($req->status === 'done') {
            return response()->json(['error' => 'already_processed'], 409);
        }

        $result = match ($req->type) {
            'erasure'     => $this->erasure->erase($req->subject_email),
            'portability' => $this->portability->export($req->subject_email),
            default       => ['noop' => true],
        };

        $req->update([
            'status'       => 'done',
            'processed_at' => now(),
            'processed_by' => $r->user()?->id,
            'metadata'     => array_merge((array) $req->metadata, ['result' => $result]),
        ]);

        return $this->ok(['request' => $req->fresh(), 'result' => $result]);
    }

    /**
     * @OA\Get(path="/rgpd/export/{token}", tags={"RGPD"}, summary="Télécharge l'export RGPD via token signé",
     *     @OA\Parameter(name="token", in="path", required=true, @OA\Schema(type="string", maxLength=64)),
     *     @OA\Response(response=200, description="JSON export"),
     *     @OA\Response(response=404, description="Token invalide/expiré"))
     */
    public function export(string $token): JsonResponse
    {
        $json = $this->portability->retrieve($token);
        if (! $json) {
            return response()->json(['error' => 'invalid_or_expired_token'], 404);
        }
        return response()->json(json_decode($json, true), 200, [
            'Content-Disposition' => 'attachment; filename="gdpr-export.json"',
        ]);
    }
}
