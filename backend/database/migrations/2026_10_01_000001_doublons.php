<?php

use App\Crm\Doublons\Rapprochement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DOUBLONS (chantier 5, 2026-09-30) — la file de vérification, le journal des
 * fusions, les adresses partagées, et le verrou qui rend toute fusion
 * RÉVERSIBLE.
 *
 * ── `duplicate_flags` (table existante, jamais lue ni écrite jusqu'ici) ─────
 *
 * Une ligne par PAIRE de fiches : `entity_a_id` = la fiche à GARDER,
 * `entity_b_id` = la fiche à ABSORBER, `similarity` = le score du motif.
 * Elle gagne :
 *  - `motif` (liste fermée, `Rapprochement::MOTIFS`) ;
 *  - `fusion_auto` : la preuve est certaine, `crm:doublons:fusionner` peut
 *    fusionner sans relecture — et il RE-VÉRIFIE la preuve sur les données du
 *    moment, il ne se fie pas à ce drapeau seul ;
 *  - `detected_at`.
 * `resolution` garde son vocabulaire : `merge` (fusionnée), `keep_both` (« ce
 * ne sont pas des doublons » — la paire n'est jamais reproposée).
 *
 * ── `fusions_fiches` : le journal, et ce qui permet d'annuler ──────────────
 *
 * Une ligne par fusion : les deux fiches, le motif, le mode (auto / manuel),
 * et TOUT ce qui a bougé, dans `journal` : `deplacements` (les identifiants
 * des lignes rattachées à la fiche gardée, table par table), `champs` (les
 * coordonnées recopiées sur la fiche gardée) et `jumeaux` (les personnes
 * homonymes restées sur la fiche absorbée, et ce qui a été recopié sur leur
 * homonyme). `crm:doublons:fusionner --annuler=<id>` le rejoue à l'envers.
 *
 * AUCUNE coordonnée en clair dans le journal : des identifiants, des noms de
 * colonnes, et l'EMPREINTE SALÉE (`doublons_empreinte`, ci-dessous) de chaque
 * valeur recopiée — assez pour ne la retirer que si personne ne l'a changée
 * depuis. Sans la clé, une empreinte ne se retrouve pas en essayant des
 * adresses connues ; avec la clé (qui vit dans la base), elle le peut : c'est
 * une donnée pseudonymisée, pas anonyme.
 *
 * Pas de clé étrangère vers `companies` : le journal doit survivre à tout.
 *
 * ── Le verrou : les deux fiches d'une fusion ne se suppriment JAMAIS en dur ─
 *
 * La fiche absorbée va à la CORBEILLE (`deleted_at`), jamais plus loin. Or
 * `prospection:purge-non-commercial` supprime physiquement toute fiche à
 * `legal_form` NULL — ce qu'est une fiche sans SIREN — et tourne dans un
 * workflow. Une fiche absorbée y passerait, et l'annulation deviendrait
 * impossible ; la fiche GARDÉE aussi (ses personnes rattachées partiraient en
 * cascade). Les purges écartent donc les deux (`FusionFiches::conditionSql`),
 * et ce déclencheur refuse, en dernier recours, toute suppression PHYSIQUE
 * d'une fiche absorbée OU gardée par une fusion non annulée — même patron que
 * `refuser_suppression_fiche_protegee` (`SECURITY DEFINER`, `search_path`
 * fixé, tables en `public.`, levée volontaire par
 * `SET LOCAL app.autoriser_suppression_absorbee = 'on'`, qu'aucun chemin
 * applicatif ne pose).
 *
 * ── `adresses_partagees` ───────────────────────────────────────────────────
 *
 * Une ligne par adresse générique (`companies.email_generic`) portée par
 * PLUSIEURS fiches : son EMPREINTE SALÉE (jamais l'adresse en clair), son
 * domaine, le nombre de fiches et sa nature probable. Table DÉRIVÉE,
 * recalculée par `crm:doublons:detecter` ; lue par
 * `crm:campagne:destinataires`. L'effacement d'une personne (site et
 * console, `EffacementCoordonneesFiches`) retire la ligne de son adresse.
 *
 * ── `doublons_empreinte()` : l'empreinte salée ─────────────────────────────
 *
 * HMAC-SHA256 avec une clé tirée au hasard par la migration, dans
 * `doublons_cle` — même mécanisme que `contacts_retires_cle` (#255) : la clé
 * n'est lisible par personne (REVOKE, y compris au rôle applicatif), seule la
 * fonction `SECURITY DEFINER` la lit. Le rôle applicatif EXÉCUTE la fonction :
 * il voit déjà les adresses en clair dans `companies`, l'empreinte ne protège
 * pas de lui mais d'une copie de ces tables sortie de la base. Ne jamais
 * changer la clé : toutes les empreintes deviendraient orphelines.
 *
 * RLS forcée sur les deux tables neuves, comme sur toute table d'espace.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '30s'");

        $motifs = $this->liste(array_keys(Rapprochement::MOTIFS));
        $natures = $this->liste(array_keys(Rapprochement::NATURES_ADRESSE));

        // ── duplicate_flags ─────────────────────────────────────────────────
        DB::statement('ALTER TABLE duplicate_flags ADD COLUMN IF NOT EXISTS motif TEXT');
        DB::statement('ALTER TABLE duplicate_flags ADD COLUMN IF NOT EXISTS fusion_auto BOOLEAN NOT NULL DEFAULT false');
        DB::statement('ALTER TABLE duplicate_flags ADD COLUMN IF NOT EXISTS detected_at TIMESTAMPTZ');
        DB::statement('ALTER TABLE duplicate_flags DROP CONSTRAINT IF EXISTS duplicate_flags_motif_check');
        DB::statement("ALTER TABLE duplicate_flags ADD CONSTRAINT duplicate_flags_motif_check CHECK (motif IS NULL OR motif IN ({$motifs}))");
        // La file lue par l'écran et par la fusion automatique.
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_dup_flags_file_fusion_auto ON duplicate_flags (workspace_id, id)
             WHERE reviewed_at IS NULL AND fusion_auto',
        );
        // Une paire, dans un sens ou dans l'autre, se cherche par ses deux fiches.
        DB::statement('CREATE INDEX IF NOT EXISTS idx_dup_flags_entite_b ON duplicate_flags (workspace_id, entity_type, entity_b_id, entity_a_id)');

        // ── fusions_fiches ──────────────────────────────────────────────────
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS fusions_fiches (
                id                     BIGSERIAL   PRIMARY KEY,
                workspace_id           UUID        NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                flag_id                BIGINT      REFERENCES duplicate_flags(id) ON DELETE SET NULL,
                garde_id               BIGINT      NOT NULL,
                absorbee_id            BIGINT      NOT NULL,
                motif                  TEXT        NOT NULL,
                mode                   TEXT        NOT NULL,
                journal                JSONB       NOT NULL DEFAULT '{}'::jsonb,
                absorbee_supprimee_le  TIMESTAMPTZ NOT NULL,
                fait_par               UUID        REFERENCES users(id) ON DELETE SET NULL,
                operateur              TEXT,
                created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
                annulee_at             TIMESTAMPTZ,
                annulee_par            TEXT,
                CONSTRAINT fusions_fiches_deux_fiches CHECK (garde_id <> absorbee_id),
                CONSTRAINT fusions_fiches_mode_check CHECK (mode IN ('auto', 'manuel'))
            )
        SQL);
        DB::statement('ALTER TABLE fusions_fiches DROP CONSTRAINT IF EXISTS fusions_fiches_motif_check');
        DB::statement("ALTER TABLE fusions_fiches ADD CONSTRAINT fusions_fiches_motif_check CHECK (motif IN ({$motifs}))");
        // Le déclencheur et les purges : « cette fiche est-elle absorbée ? »
        DB::statement('CREATE INDEX IF NOT EXISTS idx_fusions_fiches_absorbee ON fusions_fiches (absorbee_id) WHERE annulee_at IS NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_fusions_fiches_garde ON fusions_fiches (garde_id) WHERE annulee_at IS NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_fusions_fiches_flag ON fusions_fiches (workspace_id, flag_id) WHERE flag_id IS NOT NULL');
        DB::statement("COMMENT ON TABLE fusions_fiches IS 'Journal des fusions de fiches : ce qui a bouge, pour annuler (crm:doublons:fusionner --annuler). La fiche absorbee reste a la corbeille, jamais supprimee.'");

        // ── adresses_partagees ──────────────────────────────────────────────
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS adresses_partagees (
                id              BIGSERIAL   PRIMARY KEY,
                workspace_id    UUID        NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                email_empreinte TEXT        NOT NULL,
                domaine         TEXT,
                nb_fiches       INT         NOT NULL,
                nature          TEXT        NOT NULL,
                calculee_le     TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT adresses_partagees_plusieurs_fiches CHECK (nb_fiches > 1),
                CONSTRAINT adresses_partagees_empreinte_check CHECK (email_empreinte ~ '^[0-9a-f]{64}$')
            )
        SQL);
        DB::statement('ALTER TABLE adresses_partagees DROP CONSTRAINT IF EXISTS adresses_partagees_nature_check');
        DB::statement("ALTER TABLE adresses_partagees ADD CONSTRAINT adresses_partagees_nature_check CHECK (nature IN ({$natures}))");
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS adresses_partagees_cle ON adresses_partagees (workspace_id, email_empreinte)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_adresses_partagees_calcul ON adresses_partagees (workspace_id, calculee_le)');
        DB::statement("COMMENT ON TABLE adresses_partagees IS 'Adresses generiques portees par plusieurs fiches : empreinte sha256 (jamais l adresse), nombre de fiches, nature probable. Derivee, recalculee par crm:doublons:detecter.'");

        foreach (['fusions_fiches', 'adresses_partagees'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS {$table}_workspace_isolation ON {$table}");
            DB::statement(
                "CREATE POLICY {$table}_workspace_isolation ON {$table} FOR ALL
                 USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                 WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))",
            );
        }

        // ── Le verrou de la base ────────────────────────────────────────────
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.refuser_suppression_fiche_absorbee()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_catalog
            AS $fn$
            BEGIN
                IF COALESCE(current_setting('app.autoriser_suppression_absorbee', true), '') = 'on' THEN
                    RETURN OLD;
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM   public.fusions_fiches ff
                    WHERE  ff.absorbee_id = OLD.id
                    AND    ff.annulee_at IS NULL
                ) OR EXISTS (
                    SELECT 1
                    FROM   public.fusions_fiches fg
                    WHERE  fg.garde_id = OLD.id
                    AND    fg.annulee_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'fiche_absorbee : suppression refusee (company_id=%)', OLD.id
                        USING HINT = 'Fiche absorbee ou gardee par une fusion : elle reste en base pour que la fusion reste annulable (crm:doublons:fusionner --annuler).';
                END IF;

                RETURN OLD;
            END
            $fn$;

            DROP TRIGGER IF EXISTS companies_refuser_suppression_absorbee ON public.companies;

            CREATE TRIGGER companies_refuser_suppression_absorbee
                BEFORE DELETE ON public.companies
                FOR EACH ROW EXECUTE FUNCTION public.refuser_suppression_fiche_absorbee();
        SQL);

        // ── L'empreinte salée ───────────────────────────────────────────────
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS public.doublons_cle (
                id   SMALLINT PRIMARY KEY DEFAULT 1 CHECK (id = 1),
                cle  BYTEA    NOT NULL
            );
            INSERT INTO public.doublons_cle (id, cle) VALUES (1, gen_random_bytes(32)) ON CONFLICT (id) DO NOTHING;
            REVOKE ALL ON public.doublons_cle FROM PUBLIC;
            COMMENT ON TABLE public.doublons_cle IS 'Cle HMAC des empreintes des doublons (adresses_partagees, journal des fusions). Lue par doublons_empreinte() seulement. Ne jamais la changer ni l exporter.';

            CREATE OR REPLACE FUNCTION public.doublons_empreinte(p_valeur TEXT)
            RETURNS TEXT
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = public, pg_catalog
            AS $fn$
                SELECT encode(hmac(convert_to(coalesce(p_valeur, ''), 'UTF8'), k.cle, 'sha256'), 'hex')
                FROM   public.doublons_cle k
                WHERE  k.id = 1
            $fn$;
            REVOKE EXECUTE ON FUNCTION public.doublons_empreinte(TEXT) FROM PUBLIC;
        SQL);
        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            // Les privilèges par défaut du schéma lui donneraient la table.
            DB::statement('REVOKE ALL ON public.doublons_cle FROM ' . $role);
            DB::statement('GRANT EXECUTE ON FUNCTION public.doublons_empreinte(TEXT) TO ' . $role);
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS companies_refuser_suppression_absorbee ON public.companies;
            DROP FUNCTION IF EXISTS public.refuser_suppression_fiche_absorbee();
            DROP FUNCTION IF EXISTS public.doublons_empreinte(TEXT);
            DROP TABLE IF EXISTS public.doublons_cle;
            DROP TABLE IF EXISTS adresses_partagees;
            DROP TABLE IF EXISTS fusions_fiches;
            DROP INDEX IF EXISTS idx_dup_flags_entite_b;
            DROP INDEX IF EXISTS idx_dup_flags_file_fusion_auto;
            ALTER TABLE duplicate_flags DROP CONSTRAINT IF EXISTS duplicate_flags_motif_check;
            ALTER TABLE duplicate_flags DROP COLUMN IF EXISTS detected_at;
            ALTER TABLE duplicate_flags DROP COLUMN IF EXISTS fusion_auto;
            ALTER TABLE duplicate_flags DROP COLUMN IF EXISTS motif;
        SQL);
    }

    /** @param  list<string>  $valeurs */
    private function liste(array $valeurs): string
    {
        return implode(', ', array_map(static fn (string $v): string => "'" . str_replace("'", "''", $v) . "'", $valeurs));
    }
};
