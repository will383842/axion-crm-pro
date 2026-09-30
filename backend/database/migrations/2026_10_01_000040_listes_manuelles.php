<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LISTES MANUELLES ET RÉGLAGE DES DESTINATAIRES D'UNE AUDIENCE (2026-09-30).
 *
 * Demande de Will : « pour mes campagnes, je dois pouvoir sélectionner une
 * liste selon un ou plusieurs types, ou seulement certains contacts ».
 *
 * ── `listes_manuelles` ─────────────────────────────────────────────────────
 *
 * Une liste NOMMÉE de fiches choisies à la main (« Invités salon GOFAB ») :
 * cochées dans les listes Entreprises / Contacts, depuis une fiche, ou
 * rapprochées d'un fichier importé. Elle ne porte AUCUNE adresse : elle
 * désigne des fiches qui existent déjà dans le CRM. Une ligne de fichier qui
 * ne se rapproche d'aucune fiche est comptée et REJETÉE — jamais créée.
 *
 * Une liste ne se supprime pas : elle part à la corbeille (`deleted_at`) et
 * en revient. Aucun `DELETE` n'est écrit nulle part (ordre de Will).
 *
 * ── `listes_manuelles_membres` ─────────────────────────────────────────────
 *
 * Un membre est UNE organisation (`company_id`) OU UNE personne
 * (`contact_id`), jamais les deux : cocher une personne, c'est vouloir écrire
 * à CETTE personne. La personne suit sa fiche (y compris après une fusion de
 * doublons) par `contacts.company_id` — on ne recopie pas son organisation
 * ici, une copie divergerait.
 *
 * Retirer une fiche d'une liste ne supprime RIEN : la ligne reçoit
 * `retire_le` (qui, quand) et peut être réactivée. La fiche elle-même n'est
 * jamais touchée.
 *
 * Clés étrangères : `liste_id` en RESTRICT (une liste ne disparaît jamais par
 * cascade) ; `company_id` et `contact_id` en CASCADE — un effacement légal
 * (art. 17) d'une personne ne doit JAMAIS être bloqué par une liste de
 * ciblage, et l'appartenance d'une personne effacée n'a plus d'objet.
 *
 * ── `email_audiences.destinataires_*` ──────────────────────────────────────
 *
 * À qui écrire DANS chaque organisation retenue (REQ-CAM-007, 079, 082) :
 * l'adresse générique, les personnes nommées, les deux, ou (défaut repris de
 * #253) la personne nommée d'abord, sinon l'adresse générique. Colonnes
 * typées plutôt qu'un JSONB : le réglage se valide par la base.
 *
 * RLS : ENABLE + FORCE, politique stricte (pas de repli permissif).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '30s'");

        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS listes_manuelles (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                nom           VARCHAR(160) NOT NULL CHECK (btrim(nom) <> ''),
                description   TEXT CHECK (description IS NULL OR length(description) <= 1000),
                created_by    UUID REFERENCES users(id) ON DELETE SET NULL,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at    TIMESTAMPTZ
            )
            SQL,
        );
        // Deux listes vivantes ne portent pas le même nom dans un espace (la
        // casse et les espaces autour ne comptent pas) ; une liste à la
        // corbeille libère son nom.
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_listes_manuelles_nom
             ON listes_manuelles (workspace_id, lower(btrim(nom))) WHERE deleted_at IS NULL',
        );

        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS listes_manuelles_membres (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                liste_id      BIGINT NOT NULL REFERENCES listes_manuelles(id) ON DELETE RESTRICT,
                company_id    BIGINT REFERENCES companies(id) ON DELETE CASCADE,
                contact_id    BIGINT REFERENCES contacts(id) ON DELETE CASCADE,
                origine       TEXT NOT NULL CHECK (origine IN ('coche', 'import')),
                ajoute_le     TIMESTAMPTZ NOT NULL DEFAULT now(),
                ajoute_par    UUID REFERENCES users(id) ON DELETE SET NULL,
                retire_le     TIMESTAMPTZ,
                retire_par    UUID REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT listes_manuelles_membres_une_fiche CHECK ((company_id IS NULL) <> (contact_id IS NULL))
            )
            SQL,
        );
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_listes_membres_organisation
             ON listes_manuelles_membres (liste_id, company_id) WHERE company_id IS NOT NULL',
        );
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_listes_membres_personne
             ON listes_manuelles_membres (liste_id, contact_id) WHERE contact_id IS NOT NULL',
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_listes_membres_company
             ON listes_manuelles_membres (company_id) WHERE company_id IS NOT NULL',
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_listes_membres_contact
             ON listes_manuelles_membres (contact_id) WHERE contact_id IS NOT NULL',
        );

        // Un membre appartient au MÊME espace que sa liste et que sa fiche. La
        // RLS garantit l'espace de la LIGNE ; ce déclencheur garantit celui
        // des lignes qu'elle DÉSIGNE. Il lit sous l'identité de l'appelant
        // (pas de SECURITY DEFINER) : une fiche d'un autre espace lui est
        // invisible, et il refuse.
        DB::unprepared(
            <<<'SQL'
            CREATE OR REPLACE FUNCTION public.listes_membres_meme_espace() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM listes_manuelles l
                               WHERE l.id = NEW.liste_id AND l.workspace_id = NEW.workspace_id) THEN
                    RAISE EXCEPTION 'listes_manuelles_membres : la liste % n est pas de cet espace', NEW.liste_id
                        USING ERRCODE = '23514';
                END IF;
                IF NEW.company_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM companies c WHERE c.id = NEW.company_id AND c.workspace_id = NEW.workspace_id) THEN
                    RAISE EXCEPTION 'listes_manuelles_membres : la fiche % n est pas de cet espace', NEW.company_id
                        USING ERRCODE = '23514';
                END IF;
                IF NEW.contact_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM contacts ct WHERE ct.id = NEW.contact_id AND ct.workspace_id = NEW.workspace_id) THEN
                    RAISE EXCEPTION 'listes_manuelles_membres : la personne % n est pas de cet espace', NEW.contact_id
                        USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;

            DROP TRIGGER IF EXISTS listes_membres_meme_espace ON listes_manuelles_membres;
            CREATE TRIGGER listes_membres_meme_espace
                BEFORE INSERT OR UPDATE OF workspace_id, liste_id, company_id, contact_id ON listes_manuelles_membres
                FOR EACH ROW EXECUTE FUNCTION public.listes_membres_meme_espace();
            SQL,
        );

        foreach (['listes_manuelles', 'listes_manuelles_membres'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS {$table}_workspace_isolation ON {$table}");
            DB::statement(
                "CREATE POLICY {$table}_workspace_isolation ON {$table} FOR ALL
                 USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                 WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))",
            );
        }

        // Le réglage « à qui écrire dans chaque organisation ». Valeurs par
        // défaut constantes : ajout instantané (catalogue seulement).
        DB::statement(
            "ALTER TABLE email_audiences
                ADD COLUMN IF NOT EXISTS destinataires_mode TEXT NOT NULL DEFAULT 'personne_sinon_generique',
                ADD COLUMN IF NOT EXISTS destinataires_fonctions TEXT[] NOT NULL DEFAULT '{}',
                ADD COLUMN IF NOT EXISTS destinataires_personnes_listees BOOLEAN NOT NULL DEFAULT false,
                ADD COLUMN IF NOT EXISTS destinataires_avec_adresses_partagees BOOLEAN NOT NULL DEFAULT false",
        );
        DB::statement('ALTER TABLE email_audiences DROP CONSTRAINT IF EXISTS email_audiences_destinataires_mode_check');
        DB::statement(
            "ALTER TABLE email_audiences ADD CONSTRAINT email_audiences_destinataires_mode_check
             CHECK (destinataires_mode IN ('personne_sinon_generique', 'generique', 'nominatives', 'les_deux'))",
        );
        DB::statement('ALTER TABLE email_audiences DROP CONSTRAINT IF EXISTS email_audiences_destinataires_fonctions_check');
        DB::statement(
            'ALTER TABLE email_audiences ADD CONSTRAINT email_audiences_destinataires_fonctions_check
             CHECK (cardinality(destinataires_fonctions) <= 20)',
        );
    }

    public function down(): void
    {
        $membres = DB::selectOne("SELECT to_regclass('public.listes_manuelles') IS NOT NULL AS existe");
        if ($membres !== null && (bool) $membres->existe) {
            $n = (int) (DB::selectOne('SELECT count(*) AS n FROM listes_manuelles')->n ?? 0);
            if ($n > 0) {
                // Rien n'est jamais supprimé en masse : on refuse de jeter des
                // listes saisies à la main par un retour arrière.
                throw new RuntimeException(
                    "Retour arrière refusé : {$n} liste(s) manuelle(s) existent. Les exporter et décider à la main d'abord.",
                );
            }
        }

        DB::statement('ALTER TABLE email_audiences DROP CONSTRAINT IF EXISTS email_audiences_destinataires_fonctions_check');
        DB::statement('ALTER TABLE email_audiences DROP CONSTRAINT IF EXISTS email_audiences_destinataires_mode_check');
        DB::statement(
            'ALTER TABLE email_audiences
                DROP COLUMN IF EXISTS destinataires_avec_adresses_partagees,
                DROP COLUMN IF EXISTS destinataires_personnes_listees,
                DROP COLUMN IF EXISTS destinataires_fonctions,
                DROP COLUMN IF EXISTS destinataires_mode',
        );
        DB::statement('DROP TABLE IF EXISTS listes_manuelles_membres');
        DB::statement('DROP FUNCTION IF EXISTS public.listes_membres_meme_espace()');
        DB::statement('DROP TABLE IF EXISTS listes_manuelles');
    }
};
