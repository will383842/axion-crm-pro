<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceController extends ApiController
{
    public function show(Request $r): JsonResponse { return $this->ok($r->user()?->currentWorkspace); }
    public function update(Request $r): JsonResponse { return $this->notImplemented('3'); }
}
