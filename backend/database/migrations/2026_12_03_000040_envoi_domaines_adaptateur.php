<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi S1-B (D11) — adaptateur par domaine d'envoi. AJOUT SEUL : colonne `envoi_domaines.adaptateur` (`faux` par défaut ; aucune ligne
 * existante n'est modifiée : tout domaine existant vaut `faux`).
 *
 * - Déclencheur BEFORE INSERT OR UPDATE : à l'INSERT, `adaptateur` doit valoir `faux` ; à l'UPDATE, tout changement de `adaptateur`
 *   est refusé (même pour le propriétaire) hors de la fonction journalisée. Le déclencheur reconnaît la fonction par la variable de
 *   session `envoi.domaine_adaptateur`, posée comme clause SET de la fonction (remise à off à la sortie, même sur erreur). La variable
 *   n'est JAMAIS une barrière à elle seule : le rôle applicatif ne peut pas changer la colonne (voir le GRANT de colonnes ci-dessous).
 * - `GRANT UPDATE` était au niveau de la TABLE (2026_11_10_000021) : un REVOKE de colonne seul ne retirerait rien. REVOKE UPDATE de table,
 *   puis GRANT UPDATE sur les seules colonnes mises à jour par le code (DepotControlesDnsDb::marquerVerifie) : spf_verifie_le,
 *   dkim_verifie_le, dmarc_verifie_le. `flux` n'est jamais mis à jour par le rôle applicatif (inséré à la création seulement).
 * - `envoi_domaine_adaptateur_changer(p_ws, p_domaine, p_adaptateur, p_auteur)` : SECURITY DEFINER, refuse si p_ws diffère de l'espace
 *   courant (ou sans contexte), filtre explicitement `workspace_id = p_ws`, journalise dans `envoi_journal_commandes`.
 * - `envoi_adaptateurs_reels_portes()` : SECURITY DEFINER, sans espace, ne rend que des noms d'adaptateur (exception écrite à la règle
 *   « refus sans contexte » : `envoi_domaines` est en FORCE RLS, une lecture directe sans contexte rend 0 ligne).
 * down() inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE envoi_domaines ADD COLUMN IF NOT EXISTS adaptateur TEXT NOT NULL DEFAULT 'faux';
            ALTER TABLE envoi_domaines DROP CONSTRAINT IF EXISTS envoi_domaines_adaptateur_valide;
            ALTER TABLE envoi_domaines ADD CONSTRAINT envoi_domaines_adaptateur_valide CHECK (adaptateur IN ('faux', 'kumo', 'smtp'));
            COMMENT ON COLUMN envoi_domaines.adaptateur IS 'S1-B (D11) : serveur d''envoi du domaine (faux, kumo, smtp). Changé seulement par envoi_domaine_adaptateur_changer.';

            CREATE OR REPLACE FUNCTION envoi_domaines_adaptateur_garde() RETURNS trigger AS $f$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.adaptateur IS DISTINCT FROM 'faux' THEN
                        RAISE EXCEPTION 'envoi_domaines : un domaine est créé sur le faux serveur (adaptateur = faux), pas sur %', NEW.adaptateur;
                    END IF;
                ELSIF NEW.adaptateur IS DISTINCT FROM OLD.adaptateur
                      AND COALESCE(current_setting('envoi.domaine_adaptateur', true), 'off') <> 'on' THEN
                    RAISE EXCEPTION 'envoi_domaines : adaptateur modifiable seulement par envoi_domaine_adaptateur_changer';
                END IF;
                RETURN NEW;
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_domaines_adaptateur_garde ON envoi_domaines;
            CREATE TRIGGER envoi_domaines_adaptateur_garde BEFORE INSERT OR UPDATE ON envoi_domaines
                FOR EACH ROW EXECUTE FUNCTION envoi_domaines_adaptateur_garde();

            CREATE OR REPLACE FUNCTION envoi_domaine_adaptateur_changer(p_ws UUID, p_domaine TEXT, p_adaptateur TEXT, p_auteur TEXT) RETURNS boolean AS $f$
            DECLARE
                v_avant TEXT;
            BEGIN
                IF p_ws::TEXT IS DISTINCT FROM NULLIF(current_setting('app.current_workspace_id', true), '') THEN
                    RAISE EXCEPTION 'envoi_domaines : espace différent du contexte courant (ou aucun contexte), changement refusé';
                END IF;
                IF p_adaptateur IS NULL OR p_adaptateur NOT IN ('faux', 'kumo', 'smtp') THEN
                    RAISE EXCEPTION 'envoi_domaines : adaptateur hors liste (faux, kumo, smtp)';
                END IF;
                IF p_auteur IS NULL OR char_length(p_auteur) NOT BETWEEN 1 AND 100 OR position('@' IN p_auteur) > 0 THEN
                    RAISE EXCEPTION 'envoi_domaines : auteur invalide';
                END IF;
                SELECT adaptateur INTO v_avant FROM public.envoi_domaines WHERE workspace_id = p_ws AND nom = p_domaine FOR UPDATE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'envoi_domaines : domaine inconnu pour cet espace';
                END IF;
                IF v_avant = p_adaptateur THEN
                    RETURN false;
                END IF;
                UPDATE public.envoi_domaines SET adaptateur = p_adaptateur WHERE workspace_id = p_ws AND nom = p_domaine;
                INSERT INTO public.envoi_journal_commandes (workspace_id, commande, auteur, details, jour, statut)
                    VALUES (p_ws, 'domaine-adaptateur', p_auteur,
                            json_build_object('domaine', p_domaine, 'avant', v_avant, 'apres', p_adaptateur)::TEXT, CURRENT_DATE, 'acceptee');
                RETURN true;
            END $f$ LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public SET envoi.domaine_adaptateur = 'on';
            REVOKE ALL ON FUNCTION envoi_domaine_adaptateur_changer(UUID, TEXT, TEXT, TEXT) FROM PUBLIC;

            CREATE OR REPLACE FUNCTION envoi_adaptateurs_reels_portes() RETURNS SETOF TEXT AS $f$
                SELECT DISTINCT adaptateur FROM public.envoi_domaines WHERE adaptateur <> 'faux';
            $f$ LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public;
            REVOKE ALL ON FUNCTION envoi_adaptateurs_reels_portes() FROM PUBLIC;
        SQL);

        $nom = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($nom !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$nom]) !== null) {
            $role = '"' . str_replace('"', '""', $nom) . '"';
            DB::statement('REVOKE UPDATE ON public.envoi_domaines FROM ' . $role);
            DB::statement('GRANT UPDATE (spf_verifie_le, dkim_verifie_le, dmarc_verifie_le) ON public.envoi_domaines TO ' . $role);
            DB::statement('GRANT EXECUTE ON FUNCTION public.envoi_domaine_adaptateur_changer(UUID, TEXT, TEXT, TEXT) TO ' . $role);
            DB::statement('GRANT EXECUTE ON FUNCTION public.envoi_adaptateurs_reels_portes() TO ' . $role);
        }
    }

    /** Retour arrière INERTE : colonne, déclencheur et fonctions sont conservés (retirer la garde rouvrirait le changement direct). */
    public function down(): void {}
};
