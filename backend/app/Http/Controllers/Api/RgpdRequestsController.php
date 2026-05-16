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

    public function index(Request $r): JsonResponse
    {
        $rows = RgpdRequest::query()
            ->when($r->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('requested_at')
            ->paginate(25);
        return $this->ok($rows);
    }

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
