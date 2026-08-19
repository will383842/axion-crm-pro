<?php

use App\Crm\ActivitesEtMotifs;
use Database\Seeders\ActivitesEtMotifsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ÉTAPE 1a, pièce 2 — ACTIVITÉS et MOTIFS D'ÉCHANGE (§2.3).
 *
 * L'inventaire du 2026-08-19 (`_REPORTS/2026-08-19_INVENTAIRE-ETAPE-1A.md` §2)
 * l'a mesuré : ces deux notions n'existent NULLE PART — aucune table, aucune
 * colonne, aucune constante. Le critère de réussite de l'étape 1a est pourtant
 * « un appel se consigne en 1 clic **avec le bon motif** » : sans ces deux
 * tables, il n'y a pas de bon motif à choisir.
 *
 * ── Deux tables, pas un CHECK — et pourquoi ────────────────────────────────
 * Tout le reste de la taxonomie CRM est FERMÉ par des `CHECK` SQL comparés à
 * `App\Crm\Taxonomy` par `Feature\Crm\SocleCrmTest`. Ici, non, et c'est
 * délibéré : le §2.3 dit « extensibles depuis la console du CRM » et le §29
 * critère 2 exige qu'« aucun paramétrage courant n'exige d'intervention
 * technique ». Un `CHECK` rendrait l'ajout d'un motif impossible sans
 * migration — donc sans développeur, donc sans déploiement.
 *
 * La seule liste fermée ici est `espace` : elle décrit le cloisonnement
 * business / vivier, qui est structurel (triggers SQL de la migration L1), pas
 * un réglage.
 *
 * ── Tables GLOBALES, sans `workspace_id` ───────────────────────────────────
 * Comme `scraping_sources` : c'est du paramétrage produit, pas de la donnée
 * client. Aucune PII. Elles ne portent donc pas de RLS — la politique dynamique
 * du lot L0 ne scope que les tables à `workspace_id`, ce que le test
 * d'inventaire d'isolation vérifie déjà. Le cloisonnement des ÉCHANGES reste
 * porté par `activities.workspace_id`, qui ne bouge pas.
 *
 * ── `actif` plutôt que suppression ──────────────────────────────────────────
 * Un motif qui a servi à classer des échanges ne peut pas être supprimé sans
 * casser leur historique. La console le désactive ; il disparaît des listes
 * déroulantes et reste lisible sur les fiches anciennes.
 *
 * ── Semis par la migration ─────────────────────────────────────────────────
 * Les seeders ne tournent PAS au déploiement (l'entrypoint ne fait que
 * `migrate deploy`) : sans ce semis, la production démarrerait avec deux tables
 * vides et aucun motif sélectionnable. Idempotent, en `insertOrIgnore` — voir
 * l'en-tête du seeder pour la raison, qui est importante.
 *
 * PUREMENT ADDITIVE : deux tables neuves, rien d'existant touché. `down()` rend
 * exactement l'état d'avant.
 */
return new class extends Migration
{
    public function up(): void
    {
        $espaces = ActivitesEtMotifs::ESPACES;
        $listeEspaces = implode(', ', array_map(
            static fn (string $v): string => "'" . str_replace("'", "''", $v) . "'",
            $espaces,
        ));

        DB::unprepared(<<<SQL
            CREATE TABLE IF NOT EXISTS crm_activites (
                id          BIGSERIAL PRIMARY KEY,
                slug        TEXT NOT NULL UNIQUE,
                label       TEXT NOT NULL,
                qualiopi    BOOLEAN NOT NULL DEFAULT false,
                ordre       INT NOT NULL DEFAULT 500,
                actif       BOOLEAN NOT NULL DEFAULT true,
                description TEXT,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT crm_activites_slug_non_vide CHECK (length(btrim(slug)) > 0),
                CONSTRAINT crm_activites_label_non_vide CHECK (length(btrim(label)) > 0)
            );

            CREATE TABLE IF NOT EXISTS crm_motifs (
                id          BIGSERIAL PRIMARY KEY,
                slug        TEXT NOT NULL UNIQUE,
                label       TEXT NOT NULL,
                espace      TEXT NOT NULL DEFAULT 'business',
                ordre       INT NOT NULL DEFAULT 500,
                actif       BOOLEAN NOT NULL DEFAULT true,
                description TEXT,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT crm_motifs_espace_check CHECK (espace IN ({$listeEspaces})),
                CONSTRAINT crm_motifs_slug_non_vide CHECK (length(btrim(slug)) > 0),
                CONSTRAINT crm_motifs_label_non_vide CHECK (length(btrim(label)) > 0)
            );

            -- Les listes déroulantes ne montrent que l'actif, trié. Index
            -- partiels : les lignes désactivées n'ont pas à peser dessus.
            CREATE INDEX IF NOT EXISTS idx_crm_activites_actives
                ON crm_activites (ordre, slug) WHERE actif;
            CREATE INDEX IF NOT EXISTS idx_crm_motifs_actifs
                ON crm_motifs (espace, ordre, slug) WHERE actif;
        SQL);

        (new ActivitesEtMotifsSeeder)->run();
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS crm_motifs CASCADE;
            DROP TABLE IF EXISTS crm_activites CASCADE;
        SQL);
    }
};
