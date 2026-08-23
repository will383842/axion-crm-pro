<?php

namespace App\Console\Commands;

use App\Crm\Outbound\ConsentOutboundRecorder;
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
 *   - INDISPONIBILITÉ TEMPORAIRE : on attend, SANS consommer d'essai. Ce n'est
 *     pas un échec de CE message, c'est l'absence de l'interlocuteur. Compter
 *     ces cycles comme des essais ferait abandonner des oppositions
 *     parfaitement valides après une panne ; or une opposition abandonnée,
 *     c'est une divergence RGPD durable. Voir `STATUTS_INDISPONIBILITE`.
 *   - autre (500 applicatif…) : essai CONSOMMÉ, backoff exponentiel plafonné,
 *     puis `gave_up` au plafond d'essais (8 par défaut) — état terminal qui
 *     doit alerter (plan §2.9), jamais un silence.
 *
 * ── 🔴 CONSTAT B14-005 (S1), mesuré le 2026-08-20 ───────────────────────────
 * Cette classe n'a longtemps reconnu qu'UN SEUL visage de l'indisponibilité :
 * le 503 applicatif. Or un 503 suppose que le site soit DEBOUT et refuse
 * volontairement (drapeau fermé, maintenance annoncée). Les visages RÉELS
 * d'un site tombé sont tout autres :
 *
 *   - le conteneur est arrêté          → `ConnectionException` (rien n'écoute)
 *   - le reverse-proxy survit à l'app  → 502 / 504
 *   - un limiteur nous freine          → 429
 *
 * Ces trois-là tombaient dans `consumeAttempt()`. Avec `max_attempts = 8` et
 * le backoff plafonné (1, 2, 4, 8, 16, 32, 60, 60 min), il ne faut que
 * 2+4+8+16+32+60+60 = 182 minutes — TROIS HEURES DEUX — pour qu'une opposition
 * passe en `gave_up`, état TERMINAL que rien ne rejoue jamais. Une panne d'une
 * demi-journée effaçait donc définitivement des oppositions ; la garde « ne
 * consomme pas d'essai » n'attrapait que le cas où le site va bien.
 *
 * Le prix assumé du correctif : sur une indisponibilité, la ligne ne meurt
 * PLUS d'elle-même. C'est délibéré — perdre une opposition est pire qu'un
 * backlog. En contrepartie, le report cesse d'être muet : au-delà de
 * `HEURES_AVANT_ALERTE_REPORT`, chaque passage journalise en `error`.
 */
class CrmFlushOutbound extends Command
{
    protected $signature = 'crm:flush-outbound
        {--limit= : Nombre maximum d\'événements traités dans ce passage}
        {--dry-run : Liste les événements dus sans rien émettre}';

    protected $description = 'Émet vers le site les événements de consentement nés dans le CRM (mini-outbox L5)';

    /**
     * Codes qui disent « pas maintenant », et non « pas CE message ».
     *
     * Constat B14-005 : n'y voir que le 503 revenait à ne reconnaître
     * l'indisponibilité que lorsque le site est assez vivant pour l'annoncer.
     *
     *   408 — le site a coupé une requête trop lente ; rien n'a été appliqué.
     *   429 — un limiteur nous freine ; c'est une invitation explicite à
     *         revenir, la traiter en échec serait absurde.
     *   502 — le proxy est debout, l'application derrière ne répond pas.
     *   503 — indisponibilité annoncée (maintenance, drapeau du site fermé).
     *   504 — l'application n'a pas répondu dans le délai du proxy.
     *
     * Le 500 n'y est PAS, volontairement : il peut être le site qui casse sur
     * CE message précis. Le rejouer éternellement masquerait le défaut au lieu
     * de le faire remonter en `gave_up`.
     */
    private const STATUTS_INDISPONIBILITE = [408, 429, 502, 503, 504];

    /**
     * Au-delà de cet âge, un événement toujours reporté n'est plus une
     * maintenance : c'est une panne, et elle doit se voir.
     *
     * Puisqu'une indisponibilité ne consomme plus d'essai (B14-005), la ligne
     * ne finira JAMAIS en `gave_up` de son propre chef — le seul mécanisme
     * d'alerte qui existait pour ce chemin a donc disparu avec le défaut. Sans
     * ce seuil, le correctif échangerait une perte bruyante contre un silence,
     * ce qui est exactement le piège de B17-009 (purges RGPD sautées sans
     * trace pendant des mois).
     */
    private const HEURES_AVANT_ALERTE_REPORT = 6;

    public function handle(): int
    {
        if (! filter_var(config('crm.outbound_enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->error('CRM_OUTBOUND_ENABLED n\'est pas à true — outbox construite mais inerte (activation à la bascule finale). Aucun événement n\'a été émis.');

            return self::FAILURE;
        }

        // ── 🔴 CONSTAT B14-013 (S1) : LE CANAL À MOITIÉ OUVERT ──────────────
        //
        // Les deux branches ci-dessous décrivent le MÊME état : le drapeau
        // d'émission est ouvert, mais il manque de quoi émettre. Or, drapeau
        // ouvert, le `skip()` du planificateur ne retient plus rien
        // (`routes/console.php`) : la commande tourne toutes les 5 minutes et
        // sort en échec — dans une sortie standard que le planificateur jette.
        //
        // Le sens site → CRM, lui, n'a besoin que de `CRM_INGEST_ENABLED`
        // (`SiteSyncController`). L'ouverture des deux sens n'exige donc PAS
        // le même nombre de gestes : ouvrir le canal en croyant l'avoir ouvert
        // dans les deux sens laisse les oppositions décidées dans la console
        // s'empiler sans jamais partir, en silence. C'est exactement la forme
        // du constat. Le silence est ce qui est réparé ici : la mauvaise
        // configuration LAISSE UNE TRACE DATÉE à chaque passage.
        //
        // ⚠️ Le drapeau FERMÉ, lui, n'alerte pas (branche du dessus) : c'est
        // l'état nominal d'avant-bascule, une décision et non une panne. Une
        // alerte qui part toujours n'est plus une alerte.
        $url = trim((string) config('crm.outbound.site_webhook_url', ''));
        if ($url === '') {
            $message = 'crm.outbound.destination_absente : CRM_OUTBOUND_ENABLED est a true mais '
                . 'SITE_CRM_WEBHOOK_URL est vide. Le canal est ouvert dans le sens site -> CRM et '
                . 'ferme dans le sens CRM -> site : les oppositions decidees dans la console '
                . 's\'accumulent sans jamais partir.';

            $this->error($message);
            Log::error($message, ['en_attente' => $this->nombreEnAttente()]);

            return self::FAILURE;
        }

        $secret = (string) config('crm.ingest.hmac_secret', '');
        if ($secret === '') {
            $message = 'crm.outbound.secret_absent : CRM_OUTBOUND_ENABLED est a true mais '
                . 'SITE_SYNC_HMAC_SECRET est vide. Un webhook non signe serait refuse par le site '
                . '(et devrait l\'etre) : rien ne part, et rien ne le dit.';

            $this->error($message);
            Log::error($message, ['en_attente' => $this->nombreEnAttente()]);

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
        // 🔴 B14-008 : `schema_version` en tête, comme à l'entrée. Le contrat
        // était versionné dans un seul sens ; l'ajout mesuré du 2026-08-22 est
        // sans risque pour le récepteur actuel, dont le parseur
        // (`parseInboundPayload`, site : src/server/crm-sync/inbound.ts) lit les
        // sept clés attendues et IGNORE les autres — il n'y a aucun refus de
        // clé inconnue de ce côté-là. La signature HMAC porte sur le corps réel,
        // elle suit donc l'ajout sans coordination.
        $body = json_encode([
            'schema_version' => ConsentOutboundRecorder::SCHEMA_VERSION,
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
            // 🔴 B14-005 : CE cas — pas le 503 — est ce qui se produit quand le
            // site est réellement tombé (conteneur arrêté, DNS, TLS, délai
            // dépassé). Rien n'a atteint le site : le message n'a donc PAS
            // échoué, il n'a pas été présenté. Le compter comme un essai
            // revenait à abandonner l'opposition au bout de ~3 h de panne.
            return $this->reporter($row, 'site injoignable (' . $this->excerpt($e->getMessage()) . ')');
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

        if (in_array($status, self::STATUTS_INDISPONIBILITE, true)) {
            // Indisponibilité TEMPORAIRE du site : on repousse sans consommer
            // d'essai — `attempts` reste strictement inchangé.
            return $this->reporter(
                $row,
                'HTTP ' . $status . ' (site temporairement indisponible) : ' . $this->excerpt($response->body()),
            );
        }

        return $this->consumeAttempt($row, 'HTTP ' . $status . ' : ' . $this->excerpt($response->body()));
    }

    /**
     * REPORT sans consommer d'essai : l'interlocuteur est absent, ce message
     * n'a pas échoué.
     *
     * `attempts` n'est PAS incrémenté — c'est tout l'objet de la garde. La
     * contrepartie, c'est qu'aucun plafond ne fera plus mourir cette ligne :
     * l'alerte de report prolongé ci-dessous est donc le SEUL signal qui reste
     * sur ce chemin, et elle n'est pas facultative.
     *
     * @return 'deferred'
     */
    private function reporter(stdClass $row, string $raison): string
    {
        DB::table('crm_outbound_events')->where('id', $row->id)->update([
            'last_error' => $raison,
            'next_attempt_at' => now()->addMinutes($this->backoffMinutes((int) $row->attempts)),
            'updated_at' => now(),
        ]);

        $ageEnHeures = Carbon::parse((string) $row->created_at)->diffInHours(now());

        if ($ageEnHeures >= self::HEURES_AVANT_ALERTE_REPORT) {
            Log::error(
                'crm.outbound.deferred_long : un evenement de consentement attend depuis plus de '
                . self::HEURES_AVANT_ALERTE_REPORT . ' h sans pouvoir partir. Ce n\'est plus une '
                . 'maintenance du site : tant que ce report dure, le CRM et le site divergent sur '
                . 'une opposition RGPD.',
                [
                    'event_id' => (string) $row->event_id,
                    'event_type' => (string) $row->event_type,
                    'age_heures' => $ageEnHeures,
                    'attempts' => (int) $row->attempts,
                    'raison' => $raison,
                ],
            );
        }

        return 'deferred';
    }

    /** Combien d'événements attendent encore de partir (contexte d'alerte). */
    private function nombreEnAttente(): int
    {
        return (int) DB::table('crm_outbound_events')
            ->whereIn('status', ['pending', 'failed'])
            ->count();
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
