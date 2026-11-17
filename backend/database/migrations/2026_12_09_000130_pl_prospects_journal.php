<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PONT-LINKEDIN PL-M0B — module de prospection LinkedIn (voie S, le fondateur clique lui-même) : tables `pl_prospects`
 * et `pl_journal`. Rien ne les lit ni ne les écrit encore. ADDITIF SEULEMENT. `down()` inerte.
 *
 * - `pl_prospects` : DELETE/TRUNCATE refusés ; `ecarte` est DÉFINITIF (rien ne change plus, sauf `maj_le`) ; `linkedin_slug`,
 *   `workspace_id`, `expediteur_user_id` jamais modifiés. Le rôle applicatif ne peut mettre à jour que des colonnes nommées.
 * - `pl_journal` : ajout seul (UPDATE/DELETE/TRUNCATE refusés), clé d'idempotence unique par espace, aucune adresse.
 * - Le profil est un identifiant public normalisé ; `expediteur_user_id` est un UUID (`users.id` est un UUID).
 */
return new class extends Migration
{
    public function up(): void
    {
        $etats = "'candidat','qualifie','controle','en_file','invite','connecte','message_envoye','repondu','humain','rdv','clos','ecarte','en_pause','sans_suite','invitation_retiree','plus_tard'";
        $evenements = "'ajout','controle_refuse','arret','reprise','plafonds','alerte','invitation_faite','invitation_acceptee','message_fait','a_repondu','invitation_retiree','plus_tard','interesse','rdv_pris','non','hors_cible','opposition'";

        DB::statement(<<<SQL
            CREATE TABLE IF NOT EXISTS pl_prospects (
                id                 UUID        PRIMARY KEY,
                workspace_id       UUID        NOT NULL REFERENCES workspaces(id),
                expediteur_user_id UUID        NOT NULL REFERENCES users(id),
                linkedin_slug      TEXT        NOT NULL CHECK (linkedin_slug ~ '^[a-z0-9%][a-z0-9%._-]{1,99}$'),
                contact_id         BIGINT      NULL,
                entreprise         TEXT        NULL CHECK (entreprise IS NULL OR length(entreprise) <= 200),
                siren              CHAR(9)     NULL CHECK (siren ~ '^[0-9]{9}$'),
                source             TEXT        NOT NULL CHECK (source IN ('recherche','commentaire_post','evenement','apporteur','base_crm')),
                source_ref         TEXT        NULL CHECK (source_ref IS NULL OR (length(source_ref) <= 120 AND position('@' IN source_ref) = 0)),
                source_le          DATE        NOT NULL,
                score              SMALLINT    NOT NULL DEFAULT 0 CHECK (score BETWEEN 0 AND 100),
                etat               TEXT        NOT NULL DEFAULT 'candidat' CHECK (etat IN ({$etats})),
                variante           TEXT        NULL CHECK (variante IS NULL OR length(variante) <= 60),
                relance_le         DATE        NULL,
                cree_le            TIMESTAMPTZ NOT NULL DEFAULT now(),
                maj_le             TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT pl_prospects_un_profil_par_expediteur UNIQUE (workspace_id, expediteur_user_id, linkedin_slug),
                CONSTRAINT pl_prospects_espace_id UNIQUE (workspace_id, id)
            )
            SQL);
        DB::statement('CREATE INDEX IF NOT EXISTS pl_prospects_file ON pl_prospects (workspace_id, expediteur_user_id, etat, score DESC)');

        DB::statement(<<<SQL
            CREATE TABLE IF NOT EXISTS pl_journal (
                id              BIGSERIAL   PRIMARY KEY,
                workspace_id    UUID        NOT NULL REFERENCES workspaces(id),
                prospect_id     UUID        NULL,
                evenement       TEXT        NOT NULL CHECK (evenement IN ({$evenements})),
                origine         TEXT        NOT NULL CHECK (origine IN ('humain','systeme')),
                auteur          TEXT        NOT NULL,
                cle_idempotence TEXT        NOT NULL CHECK (length(cle_idempotence) BETWEEN 16 AND 128),
                details         JSONB       NOT NULL DEFAULT '{}' CHECK (octet_length(details::text) <= 2048 AND position('@' IN details::text) = 0),
                etat_avant      TEXT        NULL CHECK (etat_avant IS NULL OR etat_avant IN ({$etats})),
                etat_apres      TEXT        NULL CHECK (etat_apres IS NULL OR etat_apres IN ({$etats})),
                sans_effet      BOOLEAN     NOT NULL DEFAULT false,
                recu_le         TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT pl_journal_une_cle_par_espace UNIQUE (workspace_id, cle_idempotence),
                CONSTRAINT pl_journal_prospect_fk FOREIGN KEY (workspace_id, prospect_id) REFERENCES pl_prospects (workspace_id, id)
            )
            SQL);
        DB::statement('CREATE INDEX IF NOT EXISTS pl_journal_prospect ON pl_journal (workspace_id, prospect_id, id)');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION pl_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION '% : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)', TG_TABLE_NAME;
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp
            SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION pl_prospects_ecarte_definitif() RETURNS trigger AS $f$
            BEGIN
                IF NEW.linkedin_slug IS DISTINCT FROM OLD.linkedin_slug
                   OR NEW.workspace_id IS DISTINCT FROM OLD.workspace_id
                   OR NEW.expediteur_user_id IS DISTINCT FROM OLD.expediteur_user_id THEN
                    RAISE EXCEPTION 'pl_prospects : profil, espace et expéditeur ne changent jamais';
                END IF;
                IF OLD.etat = 'ecarte' AND (to_jsonb(NEW) - 'maj_le') IS DISTINCT FROM (to_jsonb(OLD) - 'maj_le') THEN
                    RAISE EXCEPTION 'pl_prospects : un prospect écarté est définitif';
                END IF;
                RETURN NEW;
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp
            SQL);
        DB::statement('DROP TRIGGER IF EXISTS pl_prospects_ecarte_definitif ON pl_prospects');
        DB::statement('CREATE TRIGGER pl_prospects_ecarte_definitif BEFORE UPDATE ON pl_prospects FOR EACH ROW EXECUTE FUNCTION pl_prospects_ecarte_definitif()');
        DB::statement('DROP TRIGGER IF EXISTS pl_prospects_pas_de_suppression ON pl_prospects');
        DB::statement('CREATE TRIGGER pl_prospects_pas_de_suppression BEFORE DELETE ON pl_prospects FOR EACH ROW EXECUTE FUNCTION pl_ajout_seul()');
        DB::statement('DROP TRIGGER IF EXISTS pl_prospects_pas_de_truncate ON pl_prospects');
        DB::statement('CREATE TRIGGER pl_prospects_pas_de_truncate BEFORE TRUNCATE ON pl_prospects FOR EACH STATEMENT EXECUTE FUNCTION pl_ajout_seul()');
        DB::statement('DROP TRIGGER IF EXISTS pl_journal_ajout_seul ON pl_journal');
        DB::statement('CREATE TRIGGER pl_journal_ajout_seul BEFORE UPDATE OR DELETE ON pl_journal FOR EACH ROW EXECUTE FUNCTION pl_ajout_seul()');
        DB::statement('DROP TRIGGER IF EXISTS pl_journal_pas_de_truncate ON pl_journal');
        DB::statement('CREATE TRIGGER pl_journal_pas_de_truncate BEFORE TRUNCATE ON pl_journal FOR EACH STATEMENT EXECUTE FUNCTION pl_ajout_seul()');

        foreach (['pl_prospects', 'pl_journal'] as $t) {
            DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS {$t}_workspace_isolation ON {$t}");
            DB::statement("CREATE POLICY {$t}_workspace_isolation ON {$t} FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))");
        }
        DB::statement("COMMENT ON TABLE pl_prospects IS 'PONT-LINKEDIN : prospects LinkedIn (voie S) ; écarté définitif, jamais supprimé.'");
        DB::statement("COMMENT ON TABLE pl_journal IS 'PONT-LINKEDIN : journal des gestes, ajout seul, idempotent.'");

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE ALL ON public.pl_prospects, public.pl_journal FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.pl_prospects, public.pl_journal TO ' . $role);
            DB::statement('GRANT UPDATE (etat, score, variante, relance_le, contact_id, maj_le) ON public.pl_prospects TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.pl_journal_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : tables et journal conservés (ajout seul). */
    public function down(): void
    {
        // Volontairement vide (aucun effacement, aucune protection retirée).
    }
};
