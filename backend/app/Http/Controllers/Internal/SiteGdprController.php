<?php

namespace App\Http\Controllers\Internal;

use App\Crm\Rgpd\SiteGdprService;
use App\Http\Controllers\Api\ApiController;
use App\Support\HmacSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `POST /api/internal/site-sync/gdpr` — art. 15 / art. 17 en UNE action sur
 * les deux systèmes (lot L4, plan §2.8.2).
 *
 * Même canal, même authentification, même drapeau que `/internal/site-sync`
 * (lot L2) : signature HMAC d'abord (un appelant non authentifié n'apprend
 * rien), puis drapeau maître → 503 tant que le canal n'est pas ouvert. Le
 * site garde sa demande RGPD en file et la rejouera à la bascule — d'ici là,
 * le traitement CRM reste manuel via `rgpd_requests` (console), comme
 * aujourd'hui : rien ne régresse.
 *
 * Contrat STRICT :
 *   { action: export|erase, person_key: sha256 hex, email: string,
 *     scope?: both|business|vivier }
 * L'email voyage EN CLAIR (TLS + HMAC) : indispensable pour atteindre les
 * fiches nées de la COLLECTE, qui n'ont pas de person_key (sel côté site).
 */
class SiteGdprController extends ApiController
{
    private const ALLOWED_KEYS = ['action', 'person_key', 'email', 'scope'];

    public function __construct(private readonly SiteGdprService $gdpr) {}

    public function store(Request $request): JsonResponse
    {
        $body = $request->getContent();
        $timestamp = $request->header('X-Site-Timestamp');
        $secret = (string) config('crm.ingest.hmac_secret', '');
        $maxSkew = (int) config('crm.ingest.max_clock_skew_seconds', 300);

        $signedPayload = HmacSignature::signedPayload((string) $timestamp, $body);

        if (! HmacSignature::verify($secret, $signedPayload, $request->header('X-Site-Signature'))) {
            Log::warning('site-sync/gdpr rejeté (signature invalide)', ['ip' => $request->ip()]);

            return response()->json(['error' => 'bad_signature'], 401);
        }

        if (! HmacSignature::timestampWithinWindow(is_string($timestamp) ? $timestamp : null, $maxSkew)) {
            Log::warning('site-sync/gdpr rejeté (horodatage hors fenêtre)', ['ip' => $request->ip()]);

            return response()->json(['error' => 'stale_signature'], 401);
        }

        if (! filter_var(config('crm.ingest.enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            return response()->json([
                'error' => 'ingest_disabled',
                'message' => 'Canal site→CRM fermé (CRM_INGEST_ENABLED) — la demande sera rejouée à la bascule.',
            ], 503);
        }

        $payload = $request->json()->all();

        $unknown = array_diff(array_keys($payload), self::ALLOWED_KEYS);
        if ($unknown !== []) {
            return response()->json([
                'error' => 'unknown_field',
                'message' => 'Champ(s) inconnu(s) : ' . implode(', ', array_map('strval', $unknown)) . '.',
            ], 422);
        }

        $action = $payload['action'] ?? null;
        $personKey = $payload['person_key'] ?? null;
        $email = $payload['email'] ?? null;
        $scope = $payload['scope'] ?? 'both';

        if (! in_array($action, ['export', 'erase'], true)) {
            return response()->json(['error' => 'invalid_action', 'message' => 'action doit être export ou erase.'], 422);
        }
        if (! is_string($personKey) || preg_match('/^[0-9a-f]{64}$/', $personKey) !== 1) {
            return response()->json(['error' => 'invalid_person_key', 'message' => 'person_key doit être un sha256 hexadécimal de 64 caractères.'], 422);
        }
        if (! is_string($email) || ! str_contains($email, '@')) {
            return response()->json(['error' => 'invalid_email', 'message' => 'email est obligatoire (il atteint les fiches nées de la collecte).'], 422);
        }
        if (! in_array($scope, ['both', 'business', 'vivier'], true)) {
            return response()->json(['error' => 'invalid_scope', 'message' => 'scope doit être both, business ou vivier.'], 422);
        }

        try {
            $result = $action === 'export'
                ? $this->gdpr->export($personKey, $email)
                : $this->gdpr->erase($personKey, $email, $scope);
        } catch (Throwable $e) {
            Log::error('site-sync/gdpr en erreur', ['exception' => $e->getMessage()]);

            return response()->json(['error' => 'gdpr_failed'], 500);
        }

        return $this->ok(['ok' => true, 'action' => $action, 'result' => $result]);
    }
}
