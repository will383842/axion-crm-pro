<?php

use App\Crm\Taxonomy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lot L1 — SOCLE DE DONNÉES, volet VIVIER CANDIDATS (plan CRM §2.1 et §2.3).
 *
 * ── Pourquoi une table DÉDIÉE et pas `contacts` ──────────────────────────────
 *
 * `contacts.company_id` est NOT NULL avec CASCADE : un contact y est un
 * décideur RATTACHÉ à une entreprise, et la dédup repose sur
 * `sha256(nom_normalisé || company_id)`. Un candidat n'a pas d'entreprise ;
 * l'y forcer via une « entreprise fantôme » casserait à la fois la sémantique
 * et la dédup. Le CRM sait déjà accueillir une entité « personne » spécialisée
 * — `journalists`, `health_practitioners` : on suit ce précédent.
 *
 * ── Étanchéité : trois barrières, pas une convention ─────────────────────────
 *
 * 1. WORKSPACE dédié `vivier-candidats` + RLS FORCÉE et stricte (lot L0).
 * 2. CHECK SQL : `relation_type` ne peut prendre QUE des valeurs `candidat_*`
 *    ici, et ne peut JAMAIS les prendre sur `companies`. Une fiche ne peut donc
 *    pas « glisser » d'un univers à l'autre par simple édition.
 * 3. TRIGGER : une ligne `candidates` ne peut exister que dans un workspace de
 *    vivier. Sans lui, un `workspace_id` business suffirait à faire entrer un
 *    candidat dans la base commerciale — le CHECK ne peut pas voir la table
 *    `workspaces`, un trigger si.
 *
 * Les campagnes/audiences sont workspace-scopées : un candidat ne peut
 * mécaniquement pas entrer dans une audience commerciale. Les contrôleurs
 * d'envoi étant encore des stubs 501, la barrière est posée AVANT leur
 * activation — cette fenêtre ne se représentera pas.
 *
 * ── INERTIE ─────────────────────────────────────────────────────────────────
 * Création de tables vides + d'un workspace vide dont personne n'est membre.
 * Aucun code de production ne les lit. Comportement observable inchangé.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createVivierWorkspace();
        $this->createCandidates();
        $this->createCandidateTag();
        $this->applyRls();
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS candidates_enforce_vivier ON candidates');
        DB::statement('DROP FUNCTION IF EXISTS candidates_enforce_vivier_workspace()');
        DB::statement('DROP TABLE IF EXISTS candidate_tag');
        DB::statement('DROP TABLE IF EXISTS candidates');
        // Le workspace `vivier-candidats` n'est PAS supprimé : le retirer
        // effacerait en cascade des données si le down est joué après un import.
    }

    private function createVivierWorkspace(): void
    {
        $slug = Taxonomy::VIVIER_WORKSPACE_SLUG;

        DB::statement(
            'INSERT INTO workspaces (slug, name, settings, cost_cap_eur, is_active)
             VALUES (?, ?, ?::jsonb, 0, true)
             ON CONFLICT (slug) DO NOTHING',
            [$slug, 'Vivier candidats', json_encode(['univers' => 'vivier', 'campagnes_commerciales' => false])],
        );
    }

    private function createCandidates(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS candidates (
                id                       BIGSERIAL PRIMARY KEY,
                workspace_id             UUID        NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,

                -- Idempotence de la synchro : rejouer un import ne crée jamais de doublon.
                external_ref             TEXT,
                -- sha256(email normalisé + sel), calculé CÔTÉ SITE avant chiffrement.
                -- LIE les fiches des deux univers sans créer de pont de données.
                person_key               TEXT,

                first_name               TEXT,
                last_name                TEXT        NOT NULL,
                email                    CITEXT,
                phone                    TEXT,

                relation_type            TEXT        NOT NULL,
                lifecycle_stage          TEXT        NOT NULL DEFAULT 'nouveau',

                source                   TEXT,
                offer_slug               TEXT,

                -- RGPD : base légale et PREUVE du consentement, fiche par fiche.
                legal_basis              TEXT        NOT NULL DEFAULT 'precontractual',
                consent_version          TEXT,
                consent_at               TIMESTAMPTZ,
                consent_text_ref         TEXT,
                -- Horloge CNIL « CVthèque » : 2 ans après la dernière interaction.
                consent_vivier_at        TIMESTAMPTZ,
                derniere_interaction_at  TIMESTAMPTZ,
                -- Stock antérieur : email d'information + opposition 30 jours.
                vivier_info_sent_at      TIMESTAMPTZ,

                -- Attributs structurés du wizard (b2b, ia, zone, dispo, mobilité).
                attributes               JSONB       NOT NULL DEFAULT '{}'::jsonb,
                experiences              JSONB       NOT NULL DEFAULT '[]'::jsonb,
                -- Référence au CV stocké CÔTÉ SITE. Jamais le fichier : on ne
                -- duplique pas des pièces PII dans un second système.
                cv_ref                   TEXT,

                opt_out                  BOOLEAN     NOT NULL DEFAULT false,
                opt_out_at               TIMESTAMPTZ,
                anonymized_at            TIMESTAMPTZ,

                created_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at               TIMESTAMPTZ
            )
            SQL
        );

        DB::statement('ALTER TABLE candidates DROP CONSTRAINT IF EXISTS candidates_relation_type_check');
        DB::statement(
            'ALTER TABLE candidates ADD CONSTRAINT candidates_relation_type_check
             CHECK (relation_type IN (' . Taxonomy::sqlList(Taxonomy::CANDIDATE_RELATION_TYPES) . '))',
        );

        DB::statement('ALTER TABLE candidates DROP CONSTRAINT IF EXISTS candidates_lifecycle_stage_check');
        DB::statement(
            'ALTER TABLE candidates ADD CONSTRAINT candidates_lifecycle_stage_check
             CHECK (lifecycle_stage IN (' . Taxonomy::sqlList(Taxonomy::CANDIDATE_LIFECYCLE_STAGES) . '))',
        );

        DB::statement('ALTER TABLE candidates DROP CONSTRAINT IF EXISTS candidates_legal_basis_check');
        DB::statement(
            "ALTER TABLE candidates ADD CONSTRAINT candidates_legal_basis_check
             CHECK (legal_basis IN ('precontractual', 'consent'))",
        );

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS candidates_workspace_external_ref_key ON candidates (workspace_id, external_ref) WHERE external_ref IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_candidates_workspace_relation ON candidates (workspace_id, relation_type)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_candidates_workspace_stage ON candidates (workspace_id, lifecycle_stage)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_candidates_workspace_person_key ON candidates (workspace_id, person_key) WHERE person_key IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_candidates_email ON candidates (email) WHERE email IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_candidates_purge_clock ON candidates (derniere_interaction_at) WHERE anonymized_at IS NULL');

        // ── Barrière n°3 : un candidat ne peut vivre que dans un workspace de vivier.
        DB::statement(
            <<<'SQL'
            CREATE OR REPLACE FUNCTION candidates_enforce_vivier_workspace()
            RETURNS TRIGGER AS $$
            DECLARE ws_slug TEXT;
            BEGIN
                SELECT slug INTO ws_slug FROM workspaces WHERE id = NEW.workspace_id;
                IF ws_slug IS NULL OR ws_slug NOT LIKE 'vivier%' THEN
                    RAISE EXCEPTION
                        'Étanchéité vivier : une fiche candidate ne peut pas vivre dans le workspace « % » (slug attendu : vivier*).',
                        COALESCE(ws_slug, NEW.workspace_id::text)
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL
        );
        DB::statement('DROP TRIGGER IF EXISTS candidates_enforce_vivier ON candidates');
        DB::statement(
            'CREATE TRIGGER candidates_enforce_vivier
             BEFORE INSERT OR UPDATE OF workspace_id ON candidates
             FOR EACH ROW EXECUTE FUNCTION candidates_enforce_vivier_workspace()',
        );

        DB::statement("COMMENT ON TABLE candidates IS 'Univers VIVIER — étanche par construction : workspace dédié + RLS forcée + CHECK relation_type candidat_* + trigger de workspace. Aucune fusion avec l''univers business : les fiches sont LIÉES par person_key, jamais fusionnées.'");
    }

    private function createCandidateTag(): void
    {
        // Le pivot `company_tag` ne tague que des ENTREPRISES. Même mécanique,
        // mêmes namespaces gouvernés, pour les candidats.
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS candidate_tag (
                candidate_id BIGINT      NOT NULL REFERENCES candidates(id) ON DELETE CASCADE,
                tag_id       BIGINT      NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
                workspace_id UUID        NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                assigned_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                assigned_by  TEXT        NOT NULL DEFAULT 'user',
                PRIMARY KEY (candidate_id, tag_id)
            )
            SQL
        );

        DB::statement('ALTER TABLE candidate_tag DROP CONSTRAINT IF EXISTS candidate_tag_assigned_by_check');
        DB::statement("ALTER TABLE candidate_tag ADD CONSTRAINT candidate_tag_assigned_by_check CHECK (assigned_by IN ('auto-rule', 'llm', 'user'))");
        DB::statement('CREATE INDEX IF NOT EXISTS idx_candidate_tag_workspace ON candidate_tag (workspace_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_candidate_tag_tag ON candidate_tag (tag_id)');
    }

    /**
     * Mêmes règles que la migration L0 : RLS activée ET forcée, policy stricte
     * sans fallback permissif. Les privilèges du rôle applicatif sont déjà
     * couverts par l'`ALTER DEFAULT PRIVILEGES` posé en L0.
     */
    private function applyRls(): void
    {
        foreach (['candidates', 'candidate_tag'] as $table) {
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
};
