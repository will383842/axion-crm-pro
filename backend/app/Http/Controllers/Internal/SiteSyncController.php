<?php

namespace App\Http\Controllers\Internal;

use App\Crm\Ingest\SiteSyncEvent;
use App\Crm\Ingest\SiteSyncIngestService;
use App\Crm\Ingest\SiteSyncRejection;
use App\Http\Controllers\Api\ApiController;
use App\Support\HmacSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `POST /api/internal/site-sync` — porte d'entrée UNIQUE des événements du site
 * axion-ia.com dans le CRM (lot L2).
 *
 * Authentification : HMAC-SHA256, patron de `/internal/scraper-result` (hors
 * Sanctum, rate limiter `internal` déjà défini à 600/min).
 * ⚠️ Le contrôleur modèle, lui, ne fait que LOGGER : c'est un patron
 * d'AUTHENTIFICATION, pas un patron d'ingestion. Ici, on persiste.
 *
 * ORDRE DES CONTRÔLES, volontairement dans cet ordre :
 *   1. signature (avant tout, y compris avant le drapeau : un appelant non
 *      authentifié ne doit rien apprendre de l'état du système) ;
 *   2. drapeau maître `CRM_INGEST_ENABLED` → 503 tant qu'il est à OFF ;
 *   3. contrat d'entrée strict → 422 ;
 *   4. ingestion.
 *
 * INERTIE : drapeau à OFF ⇒ 503 et AUCUNE écriture. La route n'existait pas
 * avant ce lot, aucun appelant existant ne la connaît : le comportement
 * observable du CRM est inchangé.
 */
class SiteSyncController extends ApiController
{
    public function __construct(private readonly SiteSyncIngestService $ingest) {}

    public function store(Request $request): JsonResponse
    {
        $body = $request->getContent();
        $timestamp = $request->header('X-Site-Timestamp');
        $secret = (string) config('crm.ingest.hmac_secret', '');
        $maxSkew = (int) config('crm.ingest.max_clock_skew_seconds', 300);

        $signedPayload = HmacSignature::signedPayload((string) $timestamp, $body);

        if (! HmacSignature::verify($secret, $signedPayload, $request->header('X-Site-Signature'))) {
            Log::warning('site-sync rejeté (signature invalide)', ['ip' => $request->ip()]);

            return response()->json(['error' => 'bad_signature'], 401);
        }

        if (! HmacSignature::timestampWithinWindow(is_string($timestamp) ? $timestamp : null, $maxSkew)) {
            Log::warning('site-sync rejeté (horodatage hors fenêtre)', ['ip' => $request->ip()]);

            return response()->json(['error' => 'stale_signature'], 401);
        }

        if (! filter_var(config('crm.ingest.enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            // 503 et non 404/200 : l'outbox du site doit GARDER la ligne en
            // attente et la rejouer le jour de la bascule, pas la solder.
            return response()->json([
                'error' => 'ingest_disabled',
                'message' => 'Ingestion site→CRM désactivée (CRM_INGEST_ENABLED).',
            ], 503);
        }

        try {
            /** @var array<mixed> $raw */
            $raw = $request->json()->all();
            $event = SiteSyncEvent::fromArray($raw);
            $outcome = $this->ingest->ingest($event);
        } catch (SiteSyncRejection $rejection) {
            Log::warning('site-sync refusé', [
                'code' => $rejection->errorCode,
                'message' => $rejection->getMessage(),
            ]);

            return response()->json([
                'error' => $rejection->errorCode,
                'message' => $rejection->getMessage(),
                'details' => $rejection->details,
            ], $rejection->status);
        } catch (Throwable $e) {
            Log::error('site-sync en erreur', ['exception' => $e->getMessage()]);

            // 500 : l'outbox rejouera. Une erreur inattendue n'est jamais un
            // motif de solder une ligne.
            return response()->json(['error' => 'ingest_failed'], 500);
        }

        return $this->ok(['ok' => true, 'result' => $outcome->toArray()]);
    }
}
