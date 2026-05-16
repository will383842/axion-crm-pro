<?php

namespace App\Http\Controllers\Api;

use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactsController extends ApiController
{
    public function index(Request $r): JsonResponse { return $this->ok(['data' => [], 'meta' => ['total' => 0]]); }
    public function show(Contact $contact): JsonResponse { return $this->ok($contact); }
    public function update(Request $r, Contact $contact): JsonResponse { return $this->notImplemented('5'); }
    public function destroy(Contact $contact): JsonResponse { return $this->notImplemented('5'); }
}
