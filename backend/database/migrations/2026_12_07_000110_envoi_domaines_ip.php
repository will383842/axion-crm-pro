<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FINI-M0A (X5, X6) — IP d'envoi d'un domaine. MIGRATION SEULE : rien ne la lit encore. AJOUT SEUL : colonne NULL, clé composite.
 * `envoi_domaines` a `GRANT UPDATE` par colonnes (S1-B, D11) : AUCUN `GRANT` sur `ip_id`. La seule voie pour le rôle applicatif est
 * `envoi_domaine_ip_poser` (SECURITY DEFINER) : espace = contexte courant, IP existante, non retirée et du MÊME flux que le domaine
 * (domaine sans flux ⇒ refus). Journal `envoi_journal_commandes` `domaine_ip`, sans adresse de personne. `down()` inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE envoi_domaines ADD COLUMN IF NOT EXISTS ip_id UUID NULL;
            COMMENT ON COLUMN envoi_domaines.ip_id IS 'FINI-M0A : IP d''envoi du domaine (envoi_ips). Posée seulement par envoi_domaine_ip_poser.';
            ALTER TABLE envoi_domaines DROP CONSTRAINT IF EXISTS envoi_domaines_ip_fk;
            ALTER TABLE envoi_domaines ADD CONSTRAINT envoi_domaines_ip_fk
                FOREIGN KEY (workspace_id, ip_id) REFERENCES envoi_ips (workspace_id, id) NOT VALID;
            ALTER TABLE envoi_domaines VALIDATE CONSTRAINT envoi_domaines_ip_fk;

            CREATE OR REPLACE FUNCTION envoi_domaine_ip_poser(p_ws UUID, p_domaine TEXT, p_ip_ref TEXT, p_auteur TEXT) RETURNS boolean AS $f$
            DECLARE
                v_flux_domaine TEXT;
                v_ip_id        UUID;
                v_ip_flux      TEXT;
                v_retiree      TIMESTAMPTZ;
                v_avant        UUID;
            BEGIN
                IF p_ws::TEXT IS DISTINCT FROM NULLIF(current_setting('app.current_workspace_id', true), '') THEN
                    RAISE EXCEPTION 'envoi_domaines : espace différent du contexte courant (ou aucun contexte), pose de l''IP refusée';
                END IF;
                IF p_auteur IS NULL OR char_length(p_auteur) NOT BETWEEN 1 AND 100 OR position('@' IN p_auteur) > 0 THEN
                    RAISE EXCEPTION 'envoi_domaines : auteur invalide';
                END IF;
                SELECT flux, ip_id INTO v_flux_domaine, v_avant FROM public.envoi_domaines WHERE workspace_id = p_ws AND nom = p_domaine FOR UPDATE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'envoi_domaines : domaine inconnu pour cet espace';
                END IF;
                IF v_flux_domaine IS NULL THEN
                    RAISE EXCEPTION 'envoi_domaines : domaine sans flux, pose de l''IP refusée';
                END IF;
                SELECT id, flux, retiree_le INTO v_ip_id, v_ip_flux, v_retiree FROM public.envoi_ips WHERE workspace_id = p_ws AND ref = p_ip_ref;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'envoi_domaines : IP inconnue pour cet espace';
                END IF;
                IF v_retiree IS NOT NULL THEN
                    RAISE EXCEPTION 'envoi_domaines : IP retirée, pose refusée';
                END IF;
                IF v_ip_flux IS DISTINCT FROM v_flux_domaine THEN
                    RAISE EXCEPTION 'envoi_domaines : flux de l''IP différent du flux du domaine, pose refusée';
                END IF;
                IF v_avant IS NOT DISTINCT FROM v_ip_id THEN
                    RETURN false;
                END IF;
                UPDATE public.envoi_domaines SET ip_id = v_ip_id WHERE workspace_id = p_ws AND nom = p_domaine;
                INSERT INTO public.envoi_journal_commandes (workspace_id, commande, auteur, details, jour, statut)
                    VALUES (p_ws, 'domaine_ip', p_auteur,
                            json_build_object('domaine', p_domaine, 'ip', p_ip_ref, 'flux', v_ip_flux)::TEXT, CURRENT_DATE, 'acceptee');
                RETURN true;
            END $f$ LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public;
            REVOKE ALL ON FUNCTION envoi_domaine_ip_poser(UUID, TEXT, TEXT, TEXT) FROM PUBLIC;
        SQL);

        $nom = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($nom !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$nom]) !== null) {
            $role = '"' . str_replace('"', '""', $nom) . '"';
            DB::statement('GRANT EXECUTE ON FUNCTION public.envoi_domaine_ip_poser(UUID, TEXT, TEXT, TEXT) TO ' . $role);
        }
    }

    /** Retour arrière INERTE : colonne, clé et fonction sont conservées. */
    public function down(): void {}
};
