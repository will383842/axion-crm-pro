<?php

use App\Crm\Taxonomy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lot L4-C — PERSONNES (la lettre et le guide), plan v2 du 2026-09-24.
 *
 * ── Le problème : quatre verrous, pas un ────────────────────────────────────
 *
 * Un abonné à la lettre n'est jamais devenu un contact :
 *   1. sans SIREN, l'ingestion ne crée rien (`upsertBusiness` → `pending_match`) ;
 *   2. sans nom, `ContactUpserter` renonce à la fiche personne, même avec SIREN ;
 *   3. `contacts.company_id`, `contacts.last_name` et
 *      `audience_members.company_id` sont NOT NULL : une personne ne peut
 *      exister que sous une entreprise ;
 *   4. le consentement de la lettre s'écrivait sur l'ENTREPRISE.
 *
 * ── La réponse : une table PERSONNE autonome, rattachable plus tard ─────────
 *
 * Le jumeau business de `candidates`, qui vit déjà sans entreprise dans le
 * vivier. PUREMENT ADDITIVE : aucun `ALTER` sur `contacts`, `companies` ni
 * `audience_members` (1,3 M et 4,29 M de lignes, une colonne générée unique
 * qui dépend de `company_id`). Assouplir `contacts` aurait cassé l'invariant
 * qui empêche les doublons sur la table la plus chargée ; créer une entreprise
 * factice par personne aurait pollué le hub, ses compteurs et le type de
 * relation.
 *
 * ── Pas de `deleted_at`, délibérément ───────────────────────────────────────
 *
 * Une corbeille suppose que toutes les lectures la filtrent : la garde
 * `B10-016-PORTEE` compte celles qui l'oublient, table par table. Ici, les
 * deux seules sorties sont l'effacement RGPD et la purge des 3 ans, et toutes
 * deux sont des suppressions FERMES. Une colonne que personne n'écrit serait
 * une promesse de corbeille que rien ne tient.
 *
 * ── RLS stricte et fermée par défaut ───────────────────────────────────────
 *
 * Même politique que `contacts` et `candidates` : sans contexte d'espace, ZÉRO
 * ligne. Surtout pas la politique permissive de `email_audiences`
 * (migration `2026_05_18_000008`), qui voit tout quand le contexte est vide.
 * Les privilèges du rôle applicatif sont couverts par l'`ALTER DEFAULT
 * PRIVILEGES` du lot L0.
 *
 * ── Deux vocabulaires fermés étendus ───────────────────────────────────────
 *
 *   - `activities_kind_check`, reconstruit depuis `Taxonomy::ACTIVITY_KINDS`
 *     (`lead_magnet_requested`, `email_hard_bounced`, `task`) ;
 *   - `opt_out_scope_check` : `lettre`, une portée de CANAL (cf.
 *     `Taxonomy::OPT_OUT_SCOPES_CANAL`). Se désabonner de la lettre ne ferme
 *     plus le CRM à la personne.
 *
 * INERTIE : deux tables vides. Rien ne les écrit tant que
 * `CRM_INGEST_PERSONNES_ENABLED` est fermé.
 */
return new class extends Migration
{
    /**
     * Les 22 valeurs de `ACTIVITY_KINDS` D'AVANT cette migration, recopiées en
     * dur — même raison que la migration du 2026-08-25 : `down()` ne peut pas
     * relire `Taxonomy`, qui porte désormais les valeurs qu'on retire.
     *
     * @var list<string>
     */
    private const ACTIVITY_KINDS_AVANT = [
        'form_submission', 'calendly_booked', 'calendly_completed', 'calendly_no_show',
        'calendly_canceled', 'review_posted', 'newsletter_optin', 'newsletter_optout',
        'application_submitted', 'stage_changed', 'reclassified', 'scraped',
        'enriched', 'opt_out', 'gdpr_export', 'gdpr_erasure',
        'press_release_sent', 'press_followup', 'press_reply', 'press_coverage',
        'linkedin_message', 'call',
    ];

    /** @var list<string> */
    private const OPT_OUT_SCOPES_AVANT = ['business', 'vivier'];

    public function up(): void
    {
        $this->createPersonnes();
        $this->createAbonnements();
        $this->applyRls();
        $this->extendVocabularies();
    }

    public function down(): void
    {
        // Une opposition de canal déjà consignée ne se détruit pas dans un
        // `down()` : c'est la trace d'une volonté. On REFUSE plutôt que de
        // l'effacer ou de la requalifier en opposition générale (ce qui
        // fermerait le CRM à la personne — le défaut même que ce lot répare).
        $lettre = (int) DB::table('opt_out')->whereIn('scope', Taxonomy::OPT_OUT_SCOPES_CANAL)->count();
        if ($lettre > 0) {
            throw new RuntimeException(
                "Retour arrière refusé : {$lettre} opposition(s) de canal (`lettre`) sont consignées. "
                . 'Les traiter à la main avant de défaire cette migration.',
            );
        }

        DB::statement('ALTER TABLE opt_out DROP CONSTRAINT IF EXISTS opt_out_scope_check');
        DB::statement(
            'ALTER TABLE opt_out ADD CONSTRAINT opt_out_scope_check
             CHECK (scope IN (' . Taxonomy::sqlList(self::OPT_OUT_SCOPES_AVANT) . '))',
        );

        // Même geste que la migration du 2026-08-25 : on neutralise le `kind`
        // (colonne nullable) plutôt que de supprimer une trace de timeline.
        DB::statement(
            'UPDATE activities SET kind = NULL
             WHERE kind IS NOT NULL AND kind NOT IN (' . Taxonomy::sqlList(self::ACTIVITY_KINDS_AVANT) . ')',
        );
        DB::statement('ALTER TABLE activities DROP CONSTRAINT IF EXISTS activities_kind_check');
        DB::statement(
            'ALTER TABLE activities ADD CONSTRAINT activities_kind_check
             CHECK (kind IS NULL OR kind IN (' . Taxonomy::sqlList(self::ACTIVITY_KINDS_AVANT) . '))',
        );

        DB::statement('DROP TABLE IF EXISTS abonnements');
        DB::statement('DROP TABLE IF EXISTS personnes');
    }

    private function createPersonnes(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS personnes (
                id                       BIGSERIAL PRIMARY KEY,
                workspace_id             UUID        NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,

                -- HMAC calculé CÔTÉ SITE (`hashEmailForLookup`) : la clé qui
                -- traverse les deux systèmes. Jamais confondue avec `email_hash`.
                person_key               TEXT        NOT NULL,
                email                    CITEXT,
                -- sha256 NON salé de l'adresse normalisée : clé de `opt_out` et
                -- `email_suppressions`, et plus tard clé d'échange avec l'outil
                -- d'envoi. Calculable par les deux systèmes indépendamment.
                email_hash               TEXT,
                email_nature             TEXT        NOT NULL DEFAULT 'inconnue',

                -- DÉCLARÉS seulement. On ne fabrique jamais un nom depuis une
                -- adresse électronique (même règle que `ContactUpserter`).
                first_name               TEXT,
                last_name                TEXT,
                locale                   TEXT,

                -- Jamais réécrites après le premier passage.
                premiere_source          TEXT        NOT NULL,
                premiere_source_at       TIMESTAMPTZ NOT NULL,
                derniere_interaction_at  TIMESTAMPTZ,

                legal_basis              TEXT        NOT NULL,

                -- Le RATTACHEMENT, plus tard : par `ContactUpserter` (même
                -- `person_key`) ou par l'action « Rattacher à une entreprise ».
                contact_id               BIGINT      REFERENCES contacts(id) ON DELETE SET NULL,
                company_id               BIGINT      REFERENCES companies(id) ON DELETE SET NULL,
                rattachee_at             TIMESTAMPTZ,

                external_ref             TEXT,
                field_origins            JSONB       NOT NULL DEFAULT '{}'::jsonb,
                created_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at               TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            SQL
        );

        DB::statement('ALTER TABLE personnes DROP CONSTRAINT IF EXISTS personnes_email_nature_check');
        DB::statement(
            'ALTER TABLE personnes ADD CONSTRAINT personnes_email_nature_check
             CHECK (email_nature IN (' . Taxonomy::sqlList(Taxonomy::PERSONNE_EMAIL_NATURES) . '))',
        );

        DB::statement('ALTER TABLE personnes DROP CONSTRAINT IF EXISTS personnes_legal_basis_check');
        DB::statement(
            'ALTER TABLE personnes ADD CONSTRAINT personnes_legal_basis_check
             CHECK (legal_basis IN (' . Taxonomy::sqlList(Taxonomy::LEGAL_BASES) . '))',
        );

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS personnes_workspace_person_key_key ON personnes (workspace_id, person_key)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS personnes_workspace_email_key ON personnes (workspace_id, email) WHERE email IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_personnes_workspace_email_hash ON personnes (workspace_id, email_hash) WHERE email_hash IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_personnes_workspace_interaction ON personnes (workspace_id, derniere_interaction_at DESC)');
        // Une suppression de contact (ON DELETE SET NULL) doit trouver ses
        // personnes sans balayer la table.
        DB::statement('CREATE INDEX IF NOT EXISTS idx_personnes_contact ON personnes (contact_id) WHERE contact_id IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_personnes_company ON personnes (company_id) WHERE company_id IS NOT NULL');

        DB::statement("COMMENT ON TABLE personnes IS 'Personnes sans entreprise (lettre et guide). Rattachables plus tard a un contact. Le site garde la PREUVE du consentement ; cette table en est le miroir exploitable.'");
        DB::statement("COMMENT ON COLUMN personnes.person_key IS 'HMAC du site (hashEmailForLookup). Ne jamais confondre avec email_hash.'");
        DB::statement("COMMENT ON COLUMN personnes.email_hash IS 'sha256 non sale de l''adresse normalisee : cle de opt_out et email_suppressions.'");
    }

    private function createAbonnements(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS abonnements (
                id                     BIGSERIAL PRIMARY KEY,
                workspace_id           UUID        NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                personne_id            BIGINT      NOT NULL REFERENCES personnes(id) ON DELETE CASCADE,
                canal                  TEXT        NOT NULL,
                statut                 TEXT        NOT NULL,

                -- Recopiés du site, qui garde la preuve (texte, horodatage, IP
                -- hachée, double opt-in). Une copie exploitable, jamais la preuve.
                consent_version        TEXT,
                consent_at             TIMESTAMPTZ,
                consent_text_ref       TEXT,

                -- Placement d'origine (`guide-ia-haut`, `article-…`).
                source_slug            TEXT,
                abonne_at              TIMESTAMPTZ,
                desabonne_at           TIMESTAMPTZ,
                motif_desabonnement    TEXT,

                -- GARDE CONTRE LE DÉSORDRE : le backoff du site peut atteindre
                -- 6 h, un désabonnement émis à T2 peut arriver avant une
                -- inscription de T1 qu'on retente. On n'applique que le plus
                -- récent.
                dernier_evenement_at   TIMESTAMPTZ NOT NULL,

                -- Réservé à l'outil d'envoi (identifiants de liste et
                -- d'abonné). Aucun code d'envoi n'existe : il est vide.
                identifiants_externes  JSONB       NOT NULL DEFAULT '{}'::jsonb,

                created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at             TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            SQL
        );

        DB::statement('ALTER TABLE abonnements DROP CONSTRAINT IF EXISTS abonnements_canal_check');
        DB::statement(
            'ALTER TABLE abonnements ADD CONSTRAINT abonnements_canal_check
             CHECK (canal IN (' . Taxonomy::sqlList(Taxonomy::ABONNEMENT_CANAUX) . '))',
        );
        DB::statement('ALTER TABLE abonnements DROP CONSTRAINT IF EXISTS abonnements_statut_check');
        DB::statement(
            'ALTER TABLE abonnements ADD CONSTRAINT abonnements_statut_check
             CHECK (statut IN (' . Taxonomy::sqlList(Taxonomy::ABONNEMENT_STATUTS) . '))',
        );

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS abonnements_personne_canal_key ON abonnements (personne_id, canal)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_abonnements_workspace_canal_statut ON abonnements (workspace_id, canal, statut)');

        DB::statement("COMMENT ON COLUMN abonnements.dernier_evenement_at IS 'Garde de desordre : seul un evenement plus recent change le statut.'");
        DB::statement("COMMENT ON COLUMN abonnements.identifiants_externes IS 'Reserve a l''outil d''envoi (liste, abonne). Vide tant qu''aucun envoi n''existe.'");
    }

    private function applyRls(): void
    {
        foreach (['personnes', 'abonnements'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS {$table}_workspace_isolation ON {$table}");
            DB::statement(
                "CREATE POLICY {$table}_workspace_isolation ON {$table} FOR ALL
                 USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                 WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))",
            );
        }
    }

    private function extendVocabularies(): void
    {
        DB::statement('ALTER TABLE activities DROP CONSTRAINT IF EXISTS activities_kind_check');
        DB::statement(
            'ALTER TABLE activities ADD CONSTRAINT activities_kind_check
             CHECK (kind IS NULL OR kind IN (' . Taxonomy::sqlList(Taxonomy::ACTIVITY_KINDS) . '))',
        );

        DB::statement('ALTER TABLE opt_out DROP CONSTRAINT IF EXISTS opt_out_scope_check');
        DB::statement(
            'ALTER TABLE opt_out ADD CONSTRAINT opt_out_scope_check
             CHECK (scope IN (' . Taxonomy::sqlList(array_merge(self::OPT_OUT_SCOPES_AVANT, Taxonomy::OPT_OUT_SCOPES_CANAL)) . '))',
        );
    }
};
