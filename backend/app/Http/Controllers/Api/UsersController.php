<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsersController extends ApiController
{
    public function index(Request $r): JsonResponse { return $this->ok(['data' => []]); }
    public function store(Request $r): JsonResponse { return $this->notImplemented('3'); }
    public function update(Request $r, User $user): JsonResponse { return $this->notImplemented('3'); }
    public function destroy(User $user): JsonResponse { return $this->notImplemented('3'); }
}
