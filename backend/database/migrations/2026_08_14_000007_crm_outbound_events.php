<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LOT L5 (autopilot CRM) — MINI-OUTBOX CRM → SITE (plan « Synchro
 * BIDIRECTIONNELLE des statuts de consentement »).
 *
 * Le flux principal est site → CRM. Les CONSENTEMENTS font exception : ils
 * doivent converger dans les DEUX sens, sinon les deux systèmes finissent par
 * se réécrire à des opposés (le site réabonne quelqu'un que le CRM a opposé, et
 * réciproquement). Le sens site → CRM est porté par l'outbox du site (L2) ; le
 * sens CRM → site est porté par CETTE table.
 *
 * ── POURQUOI UNE TABLE, ET PAS UN APPEL HTTP DIRECT ─────────────────────────
 * Un POST synchrone depuis un contrôleur console ferait dépendre la réussite
 * d'une action RGPD (art. 17) de la disponibilité du site. Le motif outbox
 * inverse la dépendance : la console écrit une LIGNE dans la même transaction
 * que l'opposition, et un flush asynchrone (`crm:flush-outbound`) porte le
 * retry. Une opposition n'est jamais perdue parce qu'un serveur était down.
 *
 * ── ÉVÉNEMENT, PAS ÉTAT ─────────────────────────────────────────────────────
 * Chaque changement est un ÉVÉNEMENT horodaté et identifié (`event_id` UUID),
 * pas un état écrasé. L'état courant se déduit du dernier événement. C'est ce
 * qui rend l'idempotence possible côté site (rejeu par `event_id`, même
 * mécanique que `activities.external_ref` côté CRM).
 *
 * ── ANTI-BOUCLE ─────────────────────────────────────────────────────────────
 * `origin` porte le système d'ORIGINE de l'événement. Une opposition née du
 * SITE (ingérée par SiteSyncIngestService ou SiteGdprService) ne doit JAMAIS
 * produire de ligne ici : elle repartirait vers son émetteur, qui la
 * ré-appliquerait, qui la ré-émettrait… Le garde-fou est applicatif
 * (ConsentOutboundRecorder refuse tout origin ≠ 'crm') ET structurel : la
 * contrainte CHECK ci-dessous n'accepte pas d'autre valeur que 'crm'. Une
 * régression future qui contournerait le recorder heurterait Postgres.
 *
 * ── TABLE GLOBALE ───────────────────────────────────────────────────────────
 * Pas de `workspace_id` : c'est de l'infrastructure de synchronisation, comme
 * `opt_out` et `scraping_sources`. Elle ne porte donc pas de RLS (la politique
 * dynamique de L0 ne scope que les tables à `workspace_id`, ce que le test
 * d'inventaire vérifie déjà). Elle ne contient AUCUN email en clair : le
 * `email_hash` (sha256 de l'email normalisé, sans sel — même définition que
 * `opt_out.email_hash`) suffit au site pour retrouver la personne.
 *
 * ── INERTIE ─────────────────────────────────────────────────────────────────
 * Purement additive : une table neuve, rien d'existant touché. L'émetteur qui
 * la consomme est derrière `CRM_OUTBOUND_ENABLED=false` (commande ET
 * scheduler, double verrou comme les purges de L4).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS crm_outbound_events (
                id              BIGSERIAL PRIMARY KEY,
                event_id        UUID NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                event_type      TEXT NOT NULL CHECK (event_type IN ('consent_optout', 'consent_optin', 'erasure')),
                person_key      TEXT,
                email_hash      TEXT NOT NULL,
                scope           TEXT NOT NULL CHECK (scope IN ('business', 'vivier')),
                origin          TEXT NOT NULL DEFAULT 'crm' CHECK (origin = 'crm'),
                payload         JSONB NOT NULL DEFAULT '{}',
                status          TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'sent', 'failed', 'gave_up')),
                attempts        INT NOT NULL DEFAULT 0 CHECK (attempts >= 0),
                last_error      TEXT,
                next_attempt_at TIMESTAMPTZ,
                sent_at         TIMESTAMPTZ,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            -- L'émetteur ne lit QUE les lignes dues : (status, next_attempt_at).
            CREATE INDEX IF NOT EXISTS idx_crm_outbound_due ON crm_outbound_events (status, next_attempt_at);

            -- Le compteur d'observabilité agrège par statut ; l'anti-doublon du
            -- recorder cherche un événement identique encore en attente.
            CREATE INDEX IF NOT EXISTS idx_crm_outbound_dedup ON crm_outbound_events (email_hash, scope, event_type);
        SQL);

        DB::statement("COMMENT ON TABLE crm_outbound_events IS 'Mini-outbox CRM → site (lot L5) : convergence BIDIRECTIONNELLE des consentements. Table globale (infrastructure), sans PII en clair. Inerte tant que CRM_OUTBOUND_ENABLED=false.'");
        DB::statement("COMMENT ON COLUMN crm_outbound_events.origin IS 'Système d''origine de l''événement. CONTRAINT à ''crm'' : une opposition née du SITE ne doit jamais repartir vers le site (boucle infinie). Garde-fou structurel doublant ConsentOutboundRecorder.'");
        DB::statement("COMMENT ON COLUMN crm_outbound_events.email_hash IS 'sha256 de l''email normalisé, SANS sel — même définition que opt_out.email_hash, calculable indépendamment par les deux systèmes.'");
        DB::statement("COMMENT ON COLUMN crm_outbound_events.status IS 'pending → sent | failed (rejouable) | gave_up (définitif : 422 du site, ou plafond d''essais atteint — alerte attendue).'");
        DB::statement("COMMENT ON COLUMN crm_outbound_events.attempts IS 'Essais CONSOMMÉS. Un 503 du site (indisponibilité temporaire) n''en consomme aucun : sinon une maintenance de quelques heures brûlerait le budget de retry et abandonnerait des oppositions valides.'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS crm_outbound_events');
    }
};
