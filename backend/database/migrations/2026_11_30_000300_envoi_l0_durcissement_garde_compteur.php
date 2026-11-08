<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi L0 — durcissement (réserves d'audit). AJOUTS SEULS : aucune donnée n'est touchée.
 *
 * 1. `set_config('app.envoi_ecriture_gardee','oui')` ne suffit plus : le trigger de `envoi_drapeaux`
 *    exige AUSSI une ligne de journal du même espace, posée dans la MÊME transaction
 *    (`cree_le = now()`, qui vaut le début de transaction) : `temoins_off` pour éteindre le mode
 *    témoins ou écrire feu_vert_*, `reprise` pour désarmer « tout arrêter ».
 * 2. « Tout arrêter » : UPDATE true->false et DELETE de la ligne armée passent par la même garde.
 *    Le rôle applicatif perd TRUNCATE sur `envoi_drapeaux`.
 * 3. `envoi_compteur_global` : le rôle applicatif perd INSERT et UPDATE ; seule la fonction
 *    SECURITY DEFINER `envoi_compteur_global_incrementer` incrémente (de 1, jamais autrement).
 *
 * Limite assumée : un SQL arbitraire écrivant lui-même la ligne de journal passe la garde, mais
 * laisse cette trace ; le trigger arrête le code involontaire, pas un attaquant.
 * down() restaure l'état de la migration 000013 (aucune donnée effacée).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION envoi_drapeaux_trace(p_ws UUID, p_commande TEXT) RETURNS boolean AS $f$
                SELECT EXISTS (
                    SELECT 1 FROM public.envoi_journal_commandes
                    WHERE workspace_id = p_ws AND commande = p_commande AND cree_le = now()
                );
            $f$ LANGUAGE sql STABLE SET search_path = pg_catalog, public;

            CREATE OR REPLACE FUNCTION envoi_drapeaux_garde() RETURNS trigger AS $f$
            DECLARE
                gardee BOOLEAN := COALESCE(current_setting('app.envoi_ecriture_gardee', true), '') = 'oui';
                temoins_ok BOOLEAN;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.tout_arreter AND NOT (gardee AND envoi_drapeaux_trace(OLD.workspace_id, 'reprise')) THEN
                        RAISE EXCEPTION 'envoi_drapeaux : « tout arrêter » ne se désarme que par envoi:reprendre (journal « reprise » requis)';
                    END IF;
                    RETURN OLD;
                END IF;
                temoins_ok := gardee AND envoi_drapeaux_trace(NEW.workspace_id, 'temoins_off');
                IF TG_OP = 'INSERT' THEN
                    IF (NEW.mode_temoins = false OR NEW.feu_vert_date IS NOT NULL OR NEW.feu_vert_signature IS NOT NULL) AND NOT temoins_ok THEN
                        RAISE EXCEPTION 'envoi_drapeaux : mode_temoins=false et feu_vert_* réservés à la commande locale (journal temoins_off requis)';
                    END IF;
                ELSE
                    IF OLD.tout_arreter = true AND NEW.tout_arreter = false AND NOT (gardee AND envoi_drapeaux_trace(NEW.workspace_id, 'reprise')) THEN
                        RAISE EXCEPTION 'envoi_drapeaux : « tout arrêter » ne se désarme que par envoi:reprendre (journal « reprise » requis)';
                    END IF;
                    IF OLD.mode_temoins = true AND NEW.mode_temoins = false AND NOT temoins_ok THEN
                        RAISE EXCEPTION 'envoi_drapeaux : le mode témoins ne s''éteint que par envoi:temoins-off (journal temoins_off requis)';
                    END IF;
                    IF (NEW.feu_vert_date IS DISTINCT FROM OLD.feu_vert_date
                        OR NEW.feu_vert_signature IS DISTINCT FROM OLD.feu_vert_signature) AND NOT temoins_ok THEN
                        RAISE EXCEPTION 'envoi_drapeaux : feu_vert_* réservé à la commande locale (journal temoins_off requis)';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;

            DROP TRIGGER IF EXISTS envoi_drapeaux_garde_suppression ON envoi_drapeaux;
            CREATE TRIGGER envoi_drapeaux_garde_suppression
                BEFORE DELETE ON envoi_drapeaux
                FOR EACH ROW EXECUTE FUNCTION envoi_drapeaux_garde();

            CREATE OR REPLACE FUNCTION envoi_compteur_global_incrementer(p_jour DATE) RETURNS integer AS $f$
                INSERT INTO public.envoi_compteur_global AS c (jour, n) VALUES (p_jour, 1)
                ON CONFLICT (jour) DO UPDATE SET n = c.n + 1
                RETURNING c.n;
            $f$ LANGUAGE sql SECURITY DEFINER SET search_path = pg_catalog, public;
            REVOKE ALL ON FUNCTION envoi_compteur_global_incrementer(DATE) FROM PUBLIC;
        SQL);

        $role = $this->roleApplicatif();
        if ($role !== null) {
            DB::statement('GRANT EXECUTE ON FUNCTION public.envoi_compteur_global_incrementer(DATE) TO ' . $role);
            DB::statement('REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON public.envoi_compteur_global FROM ' . $role);
            DB::statement('GRANT SELECT ON public.envoi_compteur_global TO ' . $role);
            DB::statement('REVOKE TRUNCATE ON public.envoi_drapeaux FROM ' . $role);
        }
    }

    public function down(): void
    {
        // Retour à l'état de 2026_11_10_000013 ; aucune donnée n'est effacée.
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS envoi_drapeaux_garde_suppression ON envoi_drapeaux;
            CREATE OR REPLACE FUNCTION envoi_drapeaux_garde() RETURNS trigger AS $f$
            BEGIN
                IF COALESCE(current_setting('app.envoi_ecriture_gardee', true), '') = 'oui' THEN
                    RETURN NEW;
                END IF;
                IF TG_OP = 'INSERT' THEN
                    IF NEW.mode_temoins = false OR NEW.feu_vert_date IS NOT NULL OR NEW.feu_vert_signature IS NOT NULL THEN
                        RAISE EXCEPTION 'envoi_drapeaux : mode_temoins=false et feu_vert_* réservés à la commande locale';
                    END IF;
                ELSE
                    IF OLD.mode_temoins = true AND NEW.mode_temoins = false THEN
                        RAISE EXCEPTION 'envoi_drapeaux : le mode témoins ne s''éteint que par envoi:temoins-off';
                    END IF;
                    IF NEW.feu_vert_date IS DISTINCT FROM OLD.feu_vert_date
                       OR NEW.feu_vert_signature IS DISTINCT FROM OLD.feu_vert_signature THEN
                        RAISE EXCEPTION 'envoi_drapeaux : feu_vert_* réservé à la commande locale';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
        SQL);
        $role = $this->roleApplicatif();
        if ($role !== null) {
            DB::statement('GRANT INSERT, UPDATE ON public.envoi_compteur_global TO ' . $role);
        }
    }

    /** Rôle applicatif entre guillemets, ou null s'il n'existe pas (base de dev à rôle unique). */
    private function roleApplicatif(): ?string
    {
        $nom = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($nom === '' || DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$nom]) === null) {
            return null;
        }

        return '"' . str_replace('"', '""', $nom) . '"';
    }
};
