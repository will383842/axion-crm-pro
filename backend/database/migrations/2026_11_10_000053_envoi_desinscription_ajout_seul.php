<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L5 — AJOUT SEUL pour les deux tables de la désinscription
 * (convention de 2026_11_10_000011_envoi_journal_commandes.php, fonctions
 * avec `SET search_path` figé).
 *
 *  - envoi_oppositions : seul passage permis = `effets_termines_le` de NULL à
 *    une valeur, toutes les autres colonnes inchangées. Aucun DELETE, aucun TRUNCATE.
 *  - envoi_liens_desinscription : aucun DELETE, aucun TRUNCATE.
 *
 * Le rôle applicatif n'a déjà pas ces droits : ceci protège aussi contre le
 * propriétaire par inadvertance. `down()` INERTE : la protection reste.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION envoi_oppositions_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                IF TG_OP = 'UPDATE'
                   AND OLD.effets_termines_le IS NULL
                   AND NEW.effets_termines_le IS NOT NULL
                   AND NEW.id = OLD.id
                   AND NEW.workspace_id = OLD.workspace_id
                   AND NEW.lien_id = OLD.lien_id
                   AND NEW.empreinte_adresse = OLD.empreinte_adresse
                   AND NEW.canal = OLD.canal
                   AND NEW.enregistree_le = OLD.enregistree_le THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'envoi_oppositions : ajout seulement (seul passage permis : effets_termines_le de NULL a une valeur)';
            END;
            $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;

            CREATE OR REPLACE FUNCTION envoi_liens_desinscription_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_liens_desinscription : ajout seulement (DELETE/TRUNCATE refuses)';
            END;
            $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;

            DROP TRIGGER IF EXISTS envoi_oppositions_ajout_seul ON envoi_oppositions;
            CREATE TRIGGER envoi_oppositions_ajout_seul
                BEFORE UPDATE OR DELETE ON envoi_oppositions
                FOR EACH ROW EXECUTE FUNCTION envoi_oppositions_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_oppositions_pas_de_truncate ON envoi_oppositions;
            CREATE TRIGGER envoi_oppositions_pas_de_truncate
                BEFORE TRUNCATE ON envoi_oppositions
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_oppositions_ajout_seul();

            DROP TRIGGER IF EXISTS envoi_liens_desinscription_pas_de_delete ON envoi_liens_desinscription;
            CREATE TRIGGER envoi_liens_desinscription_pas_de_delete
                BEFORE DELETE ON envoi_liens_desinscription
                FOR EACH ROW EXECUTE FUNCTION envoi_liens_desinscription_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_liens_desinscription_pas_de_truncate ON envoi_liens_desinscription;
            CREATE TRIGGER envoi_liens_desinscription_pas_de_truncate
                BEFORE TRUNCATE ON envoi_liens_desinscription
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_liens_desinscription_ajout_seul();
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE DELETE, TRUNCATE ON public.envoi_liens_desinscription FROM ' . $role);
            DB::statement('REVOKE DELETE, TRUNCATE ON public.envoi_oppositions FROM ' . $role);
        }
    }

    /** Retour arrière INERTE : la protection « ajout seul » est conservée. */
    public function down(): void
    {
        // Rien n'est retiré : seule une note est posée (même convention que 000050 à 000052).
        DB::statement("COMMENT ON TABLE envoi_oppositions IS 'Envoi P0 L5 : oppositions, ajout seul (triggers conserves au retour arriere).'");
    }
};
