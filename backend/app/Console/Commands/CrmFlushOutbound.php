<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use stdClass;

/**
 * ÉMETTEUR de la mini-outbox CRM → site (lot L5).
 *
 * POSTe les événements de consentement nés dans le CRM vers l'endpoint webhook
 * du site, signés HMAC-SHA256 avec le MÊME secret partagé que le canal
 * site → CRM (`SITE_SYNC_HMAC_SECRET`) : un seul secret à faire tourner, un
 * seul à ne pas perdre. Le corps signé est `<timestamp>.<corps>` — exactement
 * la convention que le CRM impose déjà à l'entrée (anti-rejeu).
 *
 * ── GATE (double verrou, motif des purges L4) ───────────────────────────────
 * `CRM_OUTBOUND_ENABLED` : la commande REFUSE et le scheduler la SAUTE. Deux
 * verrous plutôt qu'un, parce qu'un `skip()` de scheduler ne protège pas d'un
 * `artisan` lancé à la main sur le serveur.
 *
 * ── SÉMANTIQUE DES RÉPONSES (contrat avec le site) ──────────────────────────
 *   - 2xx : `sent`. Terminé.
 *   - 422  : `gave_up` IMMÉDIAT. Le site déclare le message définitivement
 *     invalide ; le rejouer échouera éternellement. On brûle le budget de
 *     retry pour rien et on masque le vrai problème derrière un compteur qui
 *     grimpe lentement. Symétrique du 422 que le CRM renvoie au site (L2).
 *   - 503 : on attend, SANS consommer d'essai. Le site est temporairement
 *     indisponible (drapeau à OFF, maintenance) — ce n'est pas un échec de CE
 *     message. Compter ces cycles comme des essais ferait abandonner des
 *     oppositions parfaitement valides après une nuit de maintenance ; or une
 *     opposition abandonnée, c'est une divergence RGPD durable.
 *   - autre / réseau : essai CONSOMMÉ, backoff exponentiel plafonné, puis
 *     `gave_up` au plafond d'essais (8 par défaut) — état terminal qui doit
 *     alerter (plan §2.9), jamais un silence.
 */
class CrmFlushOutbound extends Command
{
    protected $signature = 'crm:flush-outbound
        {--limit= : Nombre maximum d\'événements traités dans ce passage}
        {--dry-run : Liste les événements dus sans rien émettre}';

    protected $description = 'Émet vers le site les événements de consentement nés dans le CRM (mini-outbox L5)';

    public function handle(): int
    {
        if (! filter_var(config('crm.outbound_enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->error('CRM_OUTBOUND_ENABLED n\'est pas à true — outbox construite mais inerte (activation à la bascule finale). Aucun événement n\'a été émis.');

            return self::FAILURE;
        }

        $url = trim((string) config('crm.outbound.site_webhook_url', ''));
        if ($url === '') {
            $this->error('SITE_CRM_WEBHOOK_URL est vide — impossible de savoir où pousser les consentements.');

            return self::FAILURE;
        }

        $secret = (string) config('crm.ingest.hmac_secret', '');
        if ($secret === '') {
            $this->error('SITE_SYNC_HMAC_SECRET est vide — un webhook non signé serait refusé par le site (et devrait l\'être).');

            return self::FAILURE;
        }

        $limit = (int) ($this->option('limit') ?? config('crm.outbound.batch_size', 100));
        $limit = max(1, $limit);

        /** @var list<stdClass> $due */
        $due = DB::table('crm_outbound_events')
            ->whereIn('status', ['pending', 'failed'])
            ->where(function ($q): void {
                $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->all();

        if ($due === []) {
            $this->info('Outbox CRM → site : rien à émettre.');

            return self::SUCCESS;
        }

        if ((bool) $this->option('dry-run')) {
            $this->info(sprintf('[DRY-RUN] %d événement(s) dus, aucun POST émis.', count($due)));

            return self::SUCCESS;
        }

        $counts = ['sent' => 0, 'deferred' => 0, 'failed' => 0, 'gave_up' => 0];

        foreach ($due as $row) {
            $counts[$this->dispatchOne($row, $url, $secret)]++;
        }

        $this->info(sprintf(
            'Outbox CRM → site : %d envoyés, %d différés (site indisponible), %d en échec rejouable, %d abandonnés.',
            $counts['sent'],
            $counts['deferred'],
            $counts['failed'],
            $counts['gave_up'],
        ));

        return self::SUCCESS;
    }

    /**
     * @return 'sent'|'deferred'|'failed'|'gave_up'
     */
    private function dispatchOne(stdClass $row, string $url, string $secret): string
    {
        $body = json_encode([
            'event_id' => (string) $row->event_id,
            'event_type' => (string) $row->event_type,
            'person_key' => $row->person_key === null ? null : (string) $row->person_key,
            'email_hash' => (string) $row->email_hash,
            'scope' => (string) $row->scope,
            'origin' => (string) $row->origin,
            'occurred_at' => Carbon::parse((string) $row->created_at)->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        $timestamp = (string) time();

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'X-Crm-Timestamp' => $timestamp,
                'X-Crm-Signature' => hash_hmac('sha256', $timestamp . '.' . $body, $secret),
            ])
                ->timeout((int) config('crm.outbound.timeout_seconds', 10))
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (ConnectionException $e) {
            return $this->consumeAttempt($row, 'connexion : ' . $e->getMessage());
        }

        $status = $response->status();

        if ($response->successful()) {
            DB::table('crm_outbound_events')->where('id', $row->id)->update([
                'status' => 'sent',
                'attempts' => (int) $row->attempts + 1,
                'last_error' => null,
                'next_attempt_at' => null,
                'sent_at' => now(),
                'updated_at' => now(),
            ]);

            return 'sent';
        }

        if ($status === 422) {
            // Définitivement invalide : on abandonne TOUT DE SUITE plutôt que
            // d'user 8 essais sur un message que le site refusera toujours.
            DB::table('crm_outbound_events')->where('id', $row->id)->update([
                'status' => 'gave_up',
                'attempts' => (int) $row->attempts + 1,
                'last_error' => 'HTTP 422 (refus définitif du site) : ' . $this->excerpt($response->body()),
                'next_attempt_at' => null,
                'updated_at' => now(),
            ]);

            Log::warning('crm.outbound.gave_up', ['event_id' => $row->event_id, 'status' => 422]);

            return 'gave_up';
        }

        if ($status === 503) {
            // Indisponibilité TEMPORAIRE du site : on repousse sans consommer
            // d'essai — `attempts` reste strictement inchangé.
            DB::table('crm_outbound_events')->where('id', $row->id)->update([
                'last_error' => 'HTTP 503 (site temporairement indisponible) : ' . $this->excerpt($response->body()),
                'next_attempt_at' => now()->addMinutes($this->backoffMinutes((int) $row->attempts)),
                'updated_at' => now(),
            ]);

            return 'deferred';
        }

        return $this->consumeAttempt($row, 'HTTP ' . $status . ' : ' . $this->excerpt($response->body()));
    }

    /**
     * @return 'failed'|'gave_up'
     */
    private function consumeAttempt(stdClass $row, string $error): string
    {
        $attempts = (int) $row->attempts + 1;
        $max = max(1, (int) config('crm.outbound.max_attempts', 8));

        if ($attempts >= $max) {
            DB::table('crm_outbound_events')->where('id', $row->id)->update([
                'status' => 'gave_up',
                'attempts' => $attempts,
                'last_error' => $error,
                'next_attempt_at' => null,
                'updated_at' => now(),
            ]);

            Log::warning('crm.outbound.gave_up', ['event_id' => $row->event_id, 'attempts' => $attempts]);

            return 'gave_up';
        }

        DB::table('crm_outbound_events')->where('id', $row->id)->update([
            'status' => 'failed',
            'attempts' => $attempts,
            'last_error' => $error,
            'next_attempt_at' => now()->addMinutes($this->backoffMinutes($attempts)),
            'updated_at' => now(),
        ]);

        return 'failed';
    }

    /** Backoff exponentiel PLAFONNÉ à 1 h : 1, 2, 4, 8, 16, 32, 60, 60 min. */
    private function backoffMinutes(int $attempts): int
    {
        return (int) min(60, 2 ** max(0, min($attempts, 6)));
    }

    private function excerpt(string $body): string
    {
        return mb_substr(trim($body), 0, 300);
    }
}
