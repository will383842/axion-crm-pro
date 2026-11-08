<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L1 — durcissement du séquenceur (relecture d'exactitude). ADDITIF SEULEMENT.
 *
 *  1. `envoi_inscriptions_evenements` : trace des événements (réponse, STOP, opposition, rebond dur) même
 *     quand l'inscription est déjà « terminee » (dont le statut ne change pas). `entrepriseArretee` et
 *     `adresseArretee` la lisent : une réponse reçue après la dernière étape arrête aussi les collègues.
 *     AJOUT SEUL : déclencheur contre toute modification ou tout effacement.
 *  2. `envoi_inscriptions` : compteur d'échecs techniques et dernière erreur (une inscription fautive ne
 *     bloque plus la file ; alerte après 5 échecs).
 *  3. Une relance (rang ≥ 2) attend au moins 1 jour ouvré (NOT VALID : les lignes existantes ne sont pas touchées).
 *
 * `down()` inerte : rien n'est supprimé.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_inscriptions_evenements (
                id                UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                workspace_id      UUID        NOT NULL REFERENCES workspaces(id),
                inscription_id    UUID        NOT NULL,
                entreprise_id     BIGINT      NOT NULL,
                empreinte_adresse CHAR(64)    NOT NULL,
                evenement         TEXT        NOT NULL CHECK (evenement IN ('reponse', 'stop', 'opposition', 'rebond_dur')),
                recu_le           TIMESTAMPTZ NOT NULL DEFAULT now(),
                FOREIGN KEY (workspace_id, inscription_id) REFERENCES envoi_inscriptions (workspace_id, id),
                UNIQUE (inscription_id, evenement)
            )
            SQL,
        );
        DB::statement('CREATE INDEX IF NOT EXISTS idx_envoi_insc_evt_entreprise ON envoi_inscriptions_evenements (workspace_id, entreprise_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_envoi_insc_evt_adresse ON envoi_inscriptions_evenements (workspace_id, empreinte_adresse)');
        DB::statement(
            <<<'SQL'
            CREATE OR REPLACE FUNCTION envoi_inscriptions_evenements_ajout_seul() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'envoi_inscriptions_evenements est en ajout seul';
            END $$ LANGUAGE plpgsql SET search_path = pg_catalog, public
            SQL,
        );
        DB::statement('DROP TRIGGER IF EXISTS envoi_inscriptions_evenements_ajout_seul ON envoi_inscriptions_evenements');
        DB::statement('CREATE TRIGGER envoi_inscriptions_evenements_ajout_seul BEFORE UPDATE OR DELETE ON envoi_inscriptions_evenements FOR EACH ROW EXECUTE FUNCTION envoi_inscriptions_evenements_ajout_seul()');
        $t = 'envoi_inscriptions_evenements';
        DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS {$t}_workspace_isolation ON {$t}");
        DB::statement("CREATE POLICY {$t}_workspace_isolation ON {$t} FOR ALL
            USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
            WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))");
        DB::statement("COMMENT ON TABLE {$t} IS 'Envoi P0 L1 : evenements d inscription (reponse, stop, opposition, rebond dur). Ajout seul, aucune adresse en clair.'");
        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement("GRANT SELECT, INSERT ON public.{$t} TO {$role}");
        }

        DB::statement('ALTER TABLE envoi_inscriptions ADD COLUMN IF NOT EXISTS echecs SMALLINT NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE envoi_inscriptions ADD COLUMN IF NOT EXISTS derniere_erreur TEXT');
        DB::statement('ALTER TABLE envoi_inscriptions ADD COLUMN IF NOT EXISTS derniere_erreur_le TIMESTAMPTZ');

        DB::statement(
            <<<'SQL'
            DO $$ BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'envoi_sequence_etapes_delai_relance') THEN
                    ALTER TABLE envoi_sequence_etapes
                        ADD CONSTRAINT envoi_sequence_etapes_delai_relance CHECK (rang = 1 OR delai_jours_ouvres >= 1) NOT VALID;
                END IF;
            END $$
            SQL,
        );
    }

    /** Retour arrière INERTE : la trace des événements est conservée. */
    public function down(): void
    {
        DB::statement("COMMENT ON TABLE envoi_inscriptions_evenements IS 'Envoi P0 L1 : table conservee au retour arriere.'");
    }
};
