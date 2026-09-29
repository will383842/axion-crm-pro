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
 * fonction la lit. Comme `contacts_retires_empreinte`, le rôle applicatif
 * N'EXÉCUTE PAS `doublons_empreinte` : il ne peut pas fabriquer d'empreinte
 * pour tester un dictionnaire. Il n'a que des gestes BORNÉS À L'ESPACE de son
 * contexte (`SECURITY DEFINER`, refus hors contexte) :
 *  - `doublons_inscrire_adresses`   : inscrire les adresses qu'il a trouvées ;
 *  - `doublons_adresses_non_revues` : compter celles qu'un parcours n'a pas revues ;
 *  - `doublons_adresses_exclues`    : oui/non, pour SES adresses, écartée d'une campagne ;
 *  - `doublons_journaliser`         : poser les empreintes du journal d'UNE fusion ;
 *  - `doublons_valeur_inchangee`    : oui/non, la valeur recopiée n'a pas bougé ;
 *  - `doublons_effacer`             : retirer une adresse effacée (art. 17) des
 *    adresses partagées ET du journal des fusions, avec ses numéros.
 * Une empreinte est une donnée PSEUDONYMISÉE, pas anonyme : avec la clé, on
 * retrouve une valeur connue — et la clé part dans les sauvegardes de la base
 * avec les tables. Ne jamais changer la clé : toutes les empreintes
 * deviendraient orphelines.
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
        DB::unprepared(<<<'SQL'
            -- La forme d'une valeur avant empreinte : une adresse en minuscules,
            -- un numéro en chiffres — pour que l'effacement retrouve l'empreinte.
            CREATE OR REPLACE FUNCTION public.doublons_normaliser(p_colonne TEXT, p_valeur TEXT)
            RETURNS TEXT LANGUAGE sql IMMUTABLE SET search_path = public, pg_catalog AS $fn$
                SELECT CASE
                    WHEN p_valeur IS NULL THEN ''
                    WHEN p_colonne IN ('email', 'email_generic') THEN lower(btrim(p_valeur))
                    WHEN p_colonne = 'phone' THEN regexp_replace(p_valeur, '[^0-9]', '', 'g')
                    ELSE p_valeur
                END
            $fn$;

            -- Refus hors du contexte d'espace : la même borne que
            -- `contacts_retires_contient`.
            CREATE OR REPLACE FUNCTION public.doublons_verifier_espace(p_ws UUID)
            RETURNS VOID LANGUAGE plpgsql STABLE SET search_path = public, pg_catalog AS $fn$
            BEGIN
                IF p_ws IS NULL OR p_ws::TEXT IS DISTINCT FROM NULLIF(current_setting('app.current_workspace_id', true), '') THEN
                    RAISE EXCEPTION 'doublons_hors_contexte : espace demande hors du contexte courant';
                END IF;
            END
            $fn$;

            CREATE OR REPLACE FUNCTION public.doublons_inscrire_adresses(p_ws UUID, p_lignes JSONB)
            RETURNS INTEGER LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_catalog AS $fn$
            DECLARE n INTEGER;
            BEGIN
                PERFORM public.doublons_verifier_espace(p_ws);
                INSERT INTO public.adresses_partagees (workspace_id, email_empreinte, domaine, nb_fiches, nature, calculee_le)
                SELECT p_ws, public.doublons_empreinte(lower(btrim(l->>'email'))), l->>'domaine', (l->>'nb')::INT, l->>'nature', clock_timestamp()
                FROM   jsonb_array_elements(p_lignes) AS l
                ON CONFLICT (workspace_id, email_empreinte) DO UPDATE
                   SET domaine = EXCLUDED.domaine, nb_fiches = EXCLUDED.nb_fiches,
                       nature = EXCLUDED.nature, calculee_le = EXCLUDED.calculee_le;
                GET DIAGNOSTICS n = ROW_COUNT;
                RETURN n;
            END
            $fn$;

            CREATE OR REPLACE FUNCTION public.doublons_adresses_non_revues(p_ws UUID, p_emails JSONB)
            RETURNS INTEGER LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_catalog AS $fn$
            DECLARE n INTEGER;
            BEGIN
                PERFORM public.doublons_verifier_espace(p_ws);
                SELECT count(*) INTO n
                FROM   public.adresses_partagees ap
                WHERE  ap.workspace_id = p_ws
                AND    ap.email_empreinte NOT IN (
                           SELECT public.doublons_empreinte(lower(btrim(e))) FROM jsonb_array_elements_text(p_emails) AS e
                       );
                RETURN n;
            END
            $fn$;

            CREATE OR REPLACE FUNCTION public.doublons_adresses_exclues(p_ws UUID, p_emails JSONB, p_natures TEXT[], p_seuil INTEGER)
            RETURNS SETOF TEXT LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_catalog AS $fn$
            BEGIN
                PERFORM public.doublons_verifier_espace(p_ws);
                RETURN QUERY
                    SELECT e
                    FROM   jsonb_array_elements_text(p_emails) AS e
                    WHERE  EXISTS (
                        SELECT 1 FROM public.adresses_partagees ap
                        WHERE  ap.workspace_id = p_ws
                        AND    ap.email_empreinte = public.doublons_empreinte(lower(btrim(e)))
                        AND    ap.nature = ANY (p_natures)
                        AND    ap.nb_fiches >= p_seuil
                    );
            END
            $fn$;

            -- Les empreintes du journal d'UNE fusion, calculées sur les valeurs
            -- écrites (colonnes en liste fermée).
            CREATE OR REPLACE FUNCTION public.doublons_journaliser(p_ws UUID, p_fusion BIGINT)
            RETURNS VOID LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_catalog AS $fn$
            DECLARE
                j   JSONB;
                gid BIGINT;
                k   TEXT;
                v   TEXT;
                i   INTEGER;
            BEGIN
                PERFORM public.doublons_verifier_espace(p_ws);
                SELECT ff.journal, ff.garde_id INTO j, gid FROM public.fusions_fiches ff
                WHERE  ff.id = p_fusion AND ff.workspace_id = p_ws AND ff.annulee_at IS NULL FOR UPDATE;
                IF j IS NULL THEN
                    RAISE EXCEPTION 'doublons_fusion_introuvable';
                END IF;
                FOR k IN SELECT jsonb_object_keys(COALESCE(j->'champs', '{}'::jsonb)) LOOP
                    IF k NOT IN ('email_generic', 'phone', 'website', 'linkedin_url', 'first_info_at') THEN
                        RAISE EXCEPTION 'doublons_colonne_refusee';
                    END IF;
                    EXECUTE format('SELECT CAST(%I AS TEXT) FROM public.companies WHERE id = $1 AND workspace_id = $2', k) INTO v USING gid, p_ws;
                    j := jsonb_set(j, ARRAY['champs', k, 'empreinte'], to_jsonb(public.doublons_empreinte(public.doublons_normaliser(k, v))));
                END LOOP;
                FOR i IN 0 .. COALESCE(jsonb_array_length(j->'jumeaux'), 0) - 1 LOOP
                    FOR k IN SELECT jsonb_object_keys(COALESCE(j->'jumeaux'->i->'champs', '{}'::jsonb)) LOOP
                        IF k NOT IN ('email', 'email_status', 'phone', 'linkedin_url') THEN
                            RAISE EXCEPTION 'doublons_colonne_refusee';
                        END IF;
                        EXECUTE format('SELECT CAST(%I AS TEXT) FROM public.contacts WHERE id = $1 AND workspace_id = $2', k)
                            INTO v USING (j->'jumeaux'->i->>'garde_contact')::BIGINT, p_ws;
                        j := jsonb_set(j, ARRAY['jumeaux', i::TEXT, 'champs', k, 'empreinte'], to_jsonb(public.doublons_empreinte(public.doublons_normaliser(k, v))));
                    END LOOP;
                END LOOP;
                UPDATE public.fusions_fiches SET journal = j WHERE id = p_fusion;
            END
            $fn$;

            -- Oui/non : la valeur actuelle est-elle encore celle que la fusion a
            -- recopiée ? (lue au journal de CETTE fusion, dans CET espace)
            CREATE OR REPLACE FUNCTION public.doublons_valeur_inchangee(p_ws UUID, p_fusion BIGINT, p_table TEXT, p_id BIGINT, p_colonne TEXT, p_chemin TEXT[])
            RETURNS BOOLEAN LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_catalog AS $fn$
            DECLARE
                attendue TEXT;
                v        TEXT;
            BEGIN
                PERFORM public.doublons_verifier_espace(p_ws);
                IF NOT ((p_table = 'companies' AND p_colonne IN ('email_generic', 'phone', 'website', 'linkedin_url', 'first_info_at'))
                     OR (p_table = 'contacts' AND p_colonne IN ('email', 'email_status', 'phone', 'linkedin_url'))) THEN
                    RAISE EXCEPTION 'doublons_colonne_refusee';
                END IF;
                SELECT ff.journal #>> p_chemin INTO attendue FROM public.fusions_fiches ff
                WHERE  ff.id = p_fusion AND ff.workspace_id = p_ws;
                IF attendue IS NULL THEN
                    RETURN false;
                END IF;
                EXECUTE format('SELECT CAST(%I AS TEXT) FROM public.%I WHERE id = $1 AND workspace_id = $2', p_colonne, p_table) INTO v USING p_id, p_ws;
                RETURN attendue = public.doublons_empreinte(public.doublons_normaliser(p_colonne, v));
            END
            $fn$;

            -- Art. 17 : l'adresse et les numéros effacés quittent les adresses
            -- partagées ET le journal des fusions (les entrées qui les
            -- recopiaient sont retirées : l'annulation n'y touchera plus).
            CREATE OR REPLACE FUNCTION public.doublons_effacer(p_ws UUID, p_email TEXT, p_telephones JSONB)
            RETURNS INTEGER LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_catalog AS $fn$
            DECLARE
                h   TEXT[];
                n   INTEGER := 0;
                m   INTEGER;
                r   RECORD;
                j   JSONB;
                c   JSONB;
                jj  JSONB;
            BEGIN
                PERFORM public.doublons_verifier_espace(p_ws);
                SELECT array_agg(x) INTO h FROM (
                    SELECT public.doublons_empreinte(lower(btrim(p_email))) AS x WHERE COALESCE(btrim(p_email), '') <> ''
                    UNION
                    SELECT public.doublons_empreinte(regexp_replace(t, '[^0-9]', '', 'g'))
                    FROM   jsonb_array_elements_text(COALESCE(p_telephones, '[]'::jsonb)) AS t
                    WHERE  regexp_replace(t, '[^0-9]', '', 'g') <> ''
                ) e;
                IF h IS NULL THEN
                    RETURN 0;
                END IF;

                DELETE FROM public.adresses_partagees ap WHERE ap.workspace_id = p_ws AND ap.email_empreinte = ANY (h);
                GET DIAGNOSTICS m = ROW_COUNT;
                n := n + m;

                FOR r IN SELECT ff.id, ff.journal FROM public.fusions_fiches ff WHERE ff.workspace_id = p_ws LOOP
                    j := r.journal;
                    SELECT COALESCE(jsonb_object_agg(ck, cv), '{}'::jsonb) INTO c
                    FROM   jsonb_each(COALESCE(j->'champs', '{}'::jsonb)) AS ce(ck, cv)
                    WHERE  NOT (COALESCE(cv->>'empreinte', '') = ANY (h));
                    SELECT COALESCE(jsonb_agg(
                               jsonb_set(je.x, '{champs}', (
                                   SELECT COALESCE(jsonb_object_agg(jk, jv), '{}'::jsonb)
                                   FROM   jsonb_each(COALESCE(je.x->'champs', '{}'::jsonb)) AS jc(jk, jv)
                                   WHERE  NOT (COALESCE(jv->>'empreinte', '') = ANY (h))
                               )) ORDER BY je.o), '[]'::jsonb) INTO jj
                    FROM   jsonb_array_elements(COALESCE(j->'jumeaux', '[]'::jsonb)) WITH ORDINALITY AS je(x, o);
                    IF c IS DISTINCT FROM COALESCE(j->'champs', '{}'::jsonb) OR jj IS DISTINCT FROM COALESCE(j->'jumeaux', '[]'::jsonb) THEN
                        UPDATE public.fusions_fiches SET journal = jsonb_set(jsonb_set(j, '{champs}', c), '{jumeaux}', jj) WHERE id = r.id;
                        n := n + 1;
                    END IF;
                END LOOP;
                RETURN n;
            END
            $fn$;
        SQL);

        $fonctions = [
            'doublons_inscrire_adresses(UUID, JSONB)',
            'doublons_adresses_non_revues(UUID, JSONB)',
            'doublons_adresses_exclues(UUID, JSONB, TEXT[], INTEGER)',
            'doublons_journaliser(UUID, BIGINT)',
            'doublons_valeur_inchangee(UUID, BIGINT, TEXT, BIGINT, TEXT, TEXT[])',
            'doublons_effacer(UUID, TEXT, JSONB)',
        ];
        foreach ($fonctions as $f) {
            DB::statement("REVOKE EXECUTE ON FUNCTION public.{$f} FROM PUBLIC");
        }
        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            // Les privilèges par défaut du schéma lui donneraient la table ET la
            // fonction d'empreinte (`GRANT EXECUTE ON FUNCTIONS`) : on les lui
            // RETIRE, comme `contacts_retires_empreinte` (#255).
            DB::statement('REVOKE ALL ON public.doublons_cle FROM ' . $role);
            DB::statement('REVOKE EXECUTE ON FUNCTION public.doublons_empreinte(TEXT) FROM ' . $role);
            foreach ($fonctions as $f) {
                DB::statement("GRANT EXECUTE ON FUNCTION public.{$f} TO " . $role);
            }
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS companies_refuser_suppression_absorbee ON public.companies;
            DROP FUNCTION IF EXISTS public.refuser_suppression_fiche_absorbee();
            DROP FUNCTION IF EXISTS public.doublons_effacer(UUID, TEXT, JSONB);
            DROP FUNCTION IF EXISTS public.doublons_valeur_inchangee(UUID, BIGINT, TEXT, BIGINT, TEXT, TEXT[]);
            DROP FUNCTION IF EXISTS public.doublons_journaliser(UUID, BIGINT);
            DROP FUNCTION IF EXISTS public.doublons_adresses_exclues(UUID, JSONB, TEXT[], INTEGER);
            DROP FUNCTION IF EXISTS public.doublons_adresses_non_revues(UUID, JSONB);
            DROP FUNCTION IF EXISTS public.doublons_inscrire_adresses(UUID, JSONB);
            DROP FUNCTION IF EXISTS public.doublons_verifier_espace(UUID);
            DROP FUNCTION IF EXISTS public.doublons_normaliser(TEXT, TEXT);
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
