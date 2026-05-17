<?php

namespace App\Http\Controllers\Api;

use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactsController extends ApiController
{
    /**
     * @OA\Get(path="/contacts", tags={"Contacts"}, summary="Liste paginée des contacts",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="OK"))
     */
    public function index(Request $r): JsonResponse { return $this->ok(['data' => [], 'meta' => ['total' => 0]]); }

    /**
     * @OA\Get(path="/contacts/{contact}", tags={"Contacts"}, summary="Détail contact",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="contact", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK"))
     */
    public function show(Contact $contact): JsonResponse { return $this->ok($contact); }

    /**
     * @OA\Put(path="/contacts/{contact}", tags={"Contacts"}, summary="Update contact (Sprint 5)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="contact", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function update(Request $r, Contact $contact): JsonResponse { return $this->notImplemented('5'); }

    /**
     * @OA\Delete(path="/contacts/{contact}", tags={"Contacts"}, summary="Delete contact (Sprint 5)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="contact", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function destroy(Contact $contact): JsonResponse { return $this->notImplemented('5'); }
}
