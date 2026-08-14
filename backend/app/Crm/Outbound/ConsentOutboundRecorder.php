<?php

namespace App\Crm\Outbound;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PRODUCTEUR de la mini-outbox CRM → site (lot L5).
 *
 * Appelé aux endroits où une opposition NAÎT côté CRM — c'est-à-dire là où un
 * humain de la console décide (traitement d'une demande RGPD art. 17,
 * opposition d'un journaliste). Il écrit une ligne `crm_outbound_events` ;
 * l'émission réelle est le travail de `crm:flush-outbound`, gaté.
 *
 * ── CE QU'IL NE FAIT PAS, ET POURQUOI ───────────────────────────────────────
 * Il n'est JAMAIS branché sur SiteSyncIngestService ni SiteGdprService : ces
 * deux services inscrivent des oppositions venues DU SITE. Les renvoyer au site
 * serait une boucle. Le garde-fou n'est pas une convention d'appel mais un
 * refus explicite (`record()` n'accepte que `origin = 'crm'`), doublé d'une
 * contrainte CHECK en base.
 *
 * ── INERTIE ─────────────────────────────────────────────────────────────────
 * Le recorder n'est PAS gaté par `CRM_OUTBOUND_ENABLED` : il remplit un
 * journal local, ce qui ne change aucune réponse d'API et ne sort pas du
 * serveur. Le drapeau garde la seule chose qui soit observable de l'extérieur —
 * l'émission HTTP. Conséquence assumée : à l'activation, le backlog accumulé
 * part d'un coup ; `crm:flush-outbound --limit` le débite par lots.
 */
final class ConsentOutboundRecorder
{
    /** Vocabulaire FERMÉ, aligné sur la contrainte CHECK de la table. */
    public const EVENT_TYPES = ['consent_optout', 'consent_optin', 'erasure'];

    public const SCOPES = ['business', 'vivier'];

    /** Le CRM n'émet que ce dont il est l'origine. Voir OutboundRejection::originNotCrm(). */
    public const ORIGIN_CRM = 'crm';

    /**
     * Met en file un événement de consentement à destination du site.
     *
     * @param  array<string, mixed>  $payload  Contexte non identifiant (motif, surface console…).
     * @return string L'`event_id` (UUID) mis en file, ou celui d'un doublon déjà en attente.
     *
     * @throws OutboundRejection
     */
    public function record(
        string $eventType,
        string $emailHash,
        string $scope,
        ?string $personKey = null,
        array $payload = [],
        string $origin = self::ORIGIN_CRM,
    ): string {
        // ── Anti-boucle : le contrôle vient EN PREMIER ──────────────────────
        // Avant même la validation du type : un événement venu du site est
        // refusé quoi qu'il contienne par ailleurs.
        if ($origin !== self::ORIGIN_CRM) {
            throw OutboundRejection::originNotCrm($origin);
        }

        if (! in_array($eventType, self::EVENT_TYPES, true)) {
            throw OutboundRejection::unknownEventType($eventType);
        }

        if (! in_array($scope, self::SCOPES, true)) {
            throw OutboundRejection::unknownScope($scope);
        }

        $emailHash = trim($emailHash);
        if ($emailHash === '') {
            throw OutboundRejection::missingEmailHash();
        }

        // Anti-doublon : une même opposition cliquée deux fois dans la console
        // ne doit pas produire deux POST. On ne dédoublonne QUE contre les
        // lignes encore en attente — un événement déjà envoyé puis re-décidé
        // est un événement neuf, qui doit repartir.
        $pending = DB::table('crm_outbound_events')
            ->where('event_type', $eventType)
            ->where('email_hash', $emailHash)
            ->where('scope', $scope)
            ->whereIn('status', ['pending', 'failed'])
            ->value('event_id');

        if ($pending !== null) {
            return (string) $pending;
        }

        $eventId = (string) Str::uuid();

        DB::table('crm_outbound_events')->insert([
            'event_id' => $eventId,
            'event_type' => $eventType,
            'person_key' => $personKey,
            'email_hash' => $emailHash,
            'scope' => $scope,
            'origin' => self::ORIGIN_CRM,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'attempts' => 0,
            // Dû immédiatement : le premier essai n'attend pas un backoff.
            'next_attempt_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $eventId;
    }

    /**
     * Variante à partir de l'email en clair — le hash est calculé ici, et
     * l'email n'est JAMAIS stocké : la table de sortie ne porte pas de PII.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws OutboundRejection
     */
    public function recordForEmail(
        string $eventType,
        string $email,
        string $scope,
        ?string $personKey = null,
        array $payload = [],
        string $origin = self::ORIGIN_CRM,
    ): string {
        return $this->record($eventType, self::hashEmail($email), $scope, $personKey, $payload, $origin);
    }

    /**
     * sha256 de l'email normalisé, SANS sel. Définition partagée avec
     * `SiteSyncEvent::emailHash()` et `opt_out.email_hash` : les deux systèmes
     * doivent pouvoir la calculer indépendamment, sinon aucune comparaison
     * d'états d'opposition n'est possible.
     */
    public static function hashEmail(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }
}
