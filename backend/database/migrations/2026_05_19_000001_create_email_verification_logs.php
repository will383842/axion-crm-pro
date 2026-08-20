<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint H2 (2026-05-17) — Table de log des vérifications email Hunter.io.
 *
 * Sert à :
 *   - audit (qui a vérifié quel email quand)
 *   - tracking quota mensuel (count par workspace + alert UI si > 80%)
 *   - dédoublonnage côté DB (UNIQUE workspace_id+email+provider)
 *
 * RLS appliquée — chaque workspace ne voit que ses propres logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_verification_logs')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE TABLE email_verification_logs (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                email         VARCHAR(255) NOT NULL,
                status        VARCHAR(32) NOT NULL,
                score         INTEGER,
                provider      VARCHAR(32) NOT NULL DEFAULT 'hunter',
                raw_response  JSONB,
                verified_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT email_verif_unique UNIQUE (workspace_id, email, provider)
            );
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_email_verif_workspace_status
                ON email_verification_logs (workspace_id, status);
        SQL);

        // Sprint H2 verif fix (2026-05-18) : date_trunc(timestamptz) n'est pas IMMUTABLE
        // sous PG (résultat dépend du TimeZone session) → refusé dans CREATE INDEX.
        // On indexe verified_at brut : la query countHunterMonth utilise un BETWEEN
        // sur start/end du mois courant (ObservabilityController) qui profite de
        // l'index range scan tout aussi efficacement.
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_email_verif_workspace_verified_at
                ON email_verification_logs (workspace_id, verified_at);
        SQL);

        // RLS — chaque workspace ne voit que ses propres logs
        //
        // 🔴 A07-002 (constaté le 2026-08-20) — LA POLICY CI-DESSOUS EST UN REPLI
        // PERMISSIF, et elle a SURVÉCU au durcissement du lot L0. Sans contexte
        // workspace, `NULLIF(...)` rend NULL, le `COALESCE` retombe donc sur
        // `workspace_id::TEXT`, et le prédicat devient
        // `workspace_id::TEXT = workspace_id::TEXT` — TOUJOURS VRAI.
        // Mesuré : 2 lignes appartenant à 2 espaces distincts, visibles au lieu de 0.
        //
        // `2026_08_14_000001_harden_workspace_isolation.php` fait pourtant bien un
        // `DROP POLICY IF EXISTS <table>_workspace_isolation`. Mais le nom ci-dessous
        // est RACCOURCI (`email_verif_…` et non `email_verification_logs_…`) : le
        // DROP l'a manquée, en silence, et la policy stricte s'est ajoutée à côté.
        //
        // Elle est supprimée par la migration
        // `2026_08_20_100000_supprimer_policy_permissive_survivante_email_verification_logs.php`.
        // On ne réécrit PAS l'histoire ici : cette migration est déjà jouée partout,
        // la corriger sur place ne changerait rien aux bases existantes et ferait
        // diverger le source de ce qui a réellement été appliqué.
        //
        // ⚠️ Toute NOUVELLE policy d'isolation doit s'appeler
        // `<table>_workspace_isolation`, faute de quoi elle échappera au
        // remplacement du durcissement. Garde :
        // tests/Feature/Rgpd/CloisonnementJournauxVerificationEmailTest.php
        DB::statement('ALTER TABLE email_verification_logs ENABLE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY email_verif_workspace_isolation
                ON email_verification_logs
                FOR ALL
                USING (
                    workspace_id::TEXT = COALESCE(
                        NULLIF(current_setting('app.current_workspace_id', true), ''),
                        workspace_id::TEXT
                    )
                );
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_logs');
    }
};
