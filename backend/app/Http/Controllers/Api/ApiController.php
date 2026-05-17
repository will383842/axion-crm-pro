<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * @OA\Info(
 *     version="1.0.0",
 *     title="Axion CRM Pro API",
 *     description="Plateforme B2B de prospection automatisée — API v1 Sanctum SPA cookie.",
 *     @OA\Contact(email="contact@axion-ia.com")
 * )
 * @OA\Server(url="https://api.localhost/api/v1", description="Local dev")
 * @OA\Server(url="https://api.axion-crm-pro.com/api/v1", description="Production")
 *
 * @OA\SecurityScheme(
 *     securityScheme="sanctumCookie",
 *     type="apiKey",
 *     in="cookie",
 *     name="axion_crm_session"
 * )
 *
 * @OA\Tag(name="Auth", description="Login + 2FA + magic-link + password reset")
 * @OA\Tag(name="Companies", description="Entreprises scrapées et enrichies")
 * @OA\Tag(name="Contacts", description="Décideurs / personnes")
 * @OA\Tag(name="Coverage", description="Carte de couverture France + sélection zones")
 * @OA\Tag(name="Scraping", description="Scraper runs Horizon/BullMQ")
 * @OA\Tag(name="LLM", description="LLM Router 9 use cases + 5 providers")
 * @OA\Tag(name="RGPD", description="Requêtes RGPD art. 15-22 + AI Act register")
 * @OA\Tag(name="Phase 2", description="Campaigns / Cold email / LinkedIn / CRM / Analytics (501)")
 */
abstract class ApiController extends Controller
{
    protected function ok(mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json($data ?? ['ok' => true], $status);
    }

    protected function notImplemented(string $sprint): JsonResponse
    {
        return response()->json([
            'error'    => 'not_implemented',
            'message'  => "Endpoint à implémenter en Sprint $sprint.",
            'sprint'   => $sprint,
        ], 501);
    }
}
