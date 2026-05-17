<?php

namespace App\Services\Email;

use App\Support\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Hunter.io email verification API wrapper.
 *
 * Sprint H2 (2026-05-17) — remplace probe SMTP direct depuis IP Hetzner
 * (cause bannissement Spamhaus quasi immédiat à >50 probes/h).
 *
 * Hunter.io plans (https://hunter.io/api-keys) :
 *   - Free : 25 vérifs/mois (suffisant tests + dev)
 *   - Starter $34/mo : 1000 vérifs/mois
 *   - Growth $134/mo : 5000 vérifs/mois
 *
 * Cache 30j local pour ne pas re-vérifier le même email + INSERT log dans
 * email_verification_logs (audit + tracking quota mensuel).
 *
 * Graceful : si HUNTER_API_KEY absent → renvoie status='unknown', pas de crash.
 */
class HunterEmailVerifier
{
    private const API_ENDPOINT = 'https://api.hunter.io/v2/email-verifier';

    private const CACHE_TTL_DAYS = 30;

    private const HTTP_TIMEOUT_SECONDS = 20;

    /**
     * @return array{
     *     status: string,
     *     score?: int,
     *     mx_records?: bool,
     *     smtp_check?: bool,
     *     webmail?: bool,
     *     disposable?: bool,
     *     reason?: string
     * }
     */
    public function verify(string $email, ?string $workspaceId = null): array
    {
        $email = strtolower(trim($email));

        $apiKey = config('services.hunter.api_key');
        if (! $apiKey) {
            return ['status' => 'unknown', 'reason' => 'no_api_key'];
        }

        return Cache::remember(
            "hunter:verify:{$email}",
            now()->addDays(self::CACHE_TTL_DAYS),
            fn () => $this->doVerify($email, (string) $apiKey, $workspaceId),
        );
    }

    /**
     * @return array{
     *     status: string,
     *     score?: int,
     *     mx_records?: bool,
     *     smtp_check?: bool,
     *     webmail?: bool,
     *     disposable?: bool,
     *     reason?: string
     * }
     */
    private function doVerify(string $email, string $apiKey, ?string $workspaceId): array
    {
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->retry(2, 1000, function (\Throwable $e) {
                    return $e instanceof \Illuminate\Http\Client\ConnectionException;
                })
                ->get(self::API_ENDPOINT, [
                    'email'   => $email,
                    'api_key' => $apiKey,
                ]);

            if (! $response->successful()) {
                if (class_exists(\Sentry\State\Hub::class)) {
                    \Sentry\captureMessage("Hunter API HTTP {$response->status()} for {$email}");
                }
                Log::warning('Hunter API error', ['status' => $response->status(), 'email' => $email]);
                return ['status' => 'unknown', 'reason' => 'http_error'];
            }

            $data = $response->json('data', []);
            if (! is_array($data)) {
                return ['status' => 'unknown', 'reason' => 'invalid_payload'];
            }

            $result = [
                'status'     => (string) ($data['status'] ?? 'unknown'),
                'score'      => (int) ($data['score'] ?? 0),
                'mx_records' => (bool) ($data['mx_records'] ?? false),
                'smtp_check' => (bool) ($data['smtp_check'] ?? false),
                'webmail'    => (bool) ($data['webmail'] ?? false),
                'disposable' => (bool) ($data['disposable'] ?? false),
            ];

            $this->logVerification($email, $workspaceId, $result, $data);

            // Sprint H4 — Audit business event (quota tracking + dashboard observability)
            if ($workspaceId) {
                AuditLogger::log('email.verified', [
                    'workspace_id'  => $workspaceId,
                    'resource_type' => 'email',
                    'resource_id'   => $email,
                    'status'        => $result['status'],
                    'score'         => $result['score'] ?? null,
                    'provider'      => 'hunter',
                ]);
            }

            return $result;
        } catch (\Throwable $e) {
            if (class_exists(\Sentry\State\Hub::class)) {
                \Sentry\captureException($e);
            }
            Log::warning('Hunter verify exception', ['email' => $email, 'error' => $e->getMessage()]);
            return ['status' => 'unknown', 'reason' => 'exception'];
        }
    }

    /**
     * Upsert dans email_verification_logs (audit + quota tracking).
     * Fail-open : si la table n'existe pas encore (rollback migration), on ne crash pas.
     *
     * @param  array{status: string, score?: int, mx_records?: bool, smtp_check?: bool, webmail?: bool, disposable?: bool}  $result
     * @param  array<string, mixed>  $raw
     */
    private function logVerification(string $email, ?string $workspaceId, array $result, array $raw): void
    {
        if (! $workspaceId) {
            return;
        }
        try {
            DB::table('email_verification_logs')->upsert(
                [[
                    'workspace_id' => $workspaceId,
                    'email'        => $email,
                    'status'       => $result['status'],
                    'score'        => $result['score'] ?? null,
                    'provider'     => 'hunter',
                    'raw_response' => json_encode($raw, JSON_UNESCAPED_UNICODE),
                    'verified_at'  => now(),
                ]],
                uniqueBy: ['workspace_id', 'email', 'provider'],
                update: ['status', 'score', 'raw_response', 'verified_at'],
            );
        } catch (\Throwable $e) {
            Log::debug('email_verification_logs upsert skipped', ['error' => $e->getMessage()]);
        }
    }
}
