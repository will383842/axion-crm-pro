<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi S1-A (D7) — butée de plafond PAR ESPACE et PAR JOUR, en base (en plus du code). AJOUTS SEULS.
 *
 * - Table `envoi_compteur_espace` (espace, jour, n) : SANS clé étrangère vers `workspaces` (comme `envoi_journal_commandes`),
 *   RLS forcée. Le rôle applicatif n'a que SELECT : seule la fonction écrit.
 * - `envoi_compteur_espace_reserver(p_ws, p_jour, p_plafond)` (SECURITY DEFINER) : refuse (faux, rien d'écrit) si `p_ws` diffère
 *   de l'espace du contexte, y compris sans aucun contexte ; plafond effectif LEAST(p_plafond, 300) < 1 ⇒ faux sans écrire ;
 *   sinon une seule instruction d'incrément atomique. Le propriétaire des fonctions contourne la RLS s'il est SUPERUSER ou BYPASSRLS (cas actuel, à ne pas supposer) : le filtre `workspace_id = p_ws` est donc explicite.
 *   Aucun CHECK (n <= 300) : la borne vit dans la fonction (relevable par CREATE OR REPLACE, jamais par DROP CONSTRAINT).
 * - `envoi_injection_liberer` est remplacée (CREATE OR REPLACE) : elle décrémente aussi le compteur d'espace, jamais sous 0,
 *   une seule fois par réservation (la conclusion `liberee` est unique par réservation).
 * Aucune ligne existante n'est modifiée. down() inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_compteur_espace (
                workspace_id UUID    NOT NULL,
                jour         DATE    NOT NULL,
                n            INTEGER NOT NULL DEFAULT 0 CHECK (n >= 0),
                PRIMARY KEY (workspace_id, jour)
            );
            ALTER TABLE envoi_compteur_espace ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_compteur_espace FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_compteur_espace_workspace_isolation ON envoi_compteur_espace;
            CREATE POLICY envoi_compteur_espace_workspace_isolation ON envoi_compteur_espace FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));

            CREATE OR REPLACE FUNCTION envoi_compteur_espace_reserver(p_ws UUID, p_jour DATE, p_plafond INTEGER) RETURNS boolean AS $f$
            DECLARE
                v_max INTEGER;
                v_n INTEGER;
            BEGIN
                -- SECURITY DEFINER : ne sert que l'espace du contexte courant (aussi refusé sans aucun contexte : NULL).
                IF p_ws IS NULL OR p_jour IS NULL OR p_ws::TEXT IS DISTINCT FROM NULLIF(current_setting('app.current_workspace_id', true), '') THEN
                    RETURN false;
                END IF;
                -- LEAST ignore NULL : un plafond NULL s'ouvrirait à 300 ; on le refuse explicitement.
                IF p_plafond IS NULL OR LEAST(p_plafond, 300) < 1 THEN
                    RETURN false; -- rien d'écrit : un premier INSERT donnerait sinon une place avec un plafond de 0
                END IF;
                v_max := LEAST(p_plafond, 300);
                INSERT INTO public.envoi_compteur_espace AS c (workspace_id, jour, n)
                    VALUES (p_ws, p_jour, 1)
                    ON CONFLICT (workspace_id, jour) DO UPDATE SET n = c.n + 1 WHERE c.n < v_max
                    RETURNING c.n INTO v_n;
                RETURN v_n IS NOT NULL;
            END;
            $f$ LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public;
            REVOKE ALL ON FUNCTION envoi_compteur_espace_reserver(UUID, DATE, INTEGER) FROM PUBLIC;

            CREATE OR REPLACE FUNCTION envoi_injection_liberer(p_ws UUID, p_cle TEXT) RETURNS boolean AS $f$
            DECLARE
                v_jour DATE;
                v_n INTEGER;
            BEGIN
                -- Fonction SECURITY DEFINER : elle ne libère que pour l'espace du contexte courant (jamais celui d'un autre).
                IF p_ws::TEXT IS DISTINCT FROM NULLIF(current_setting('app.current_workspace_id', true), '') THEN
                    RETURN false;
                END IF;
                SELECT jour INTO v_jour FROM public.envoi_journal_commandes
                    WHERE workspace_id = p_ws AND commande = 'injection' AND cle_idempotence = p_cle;
                IF v_jour IS NULL THEN
                    RETURN false;
                END IF;
                INSERT INTO public.envoi_journal_commandes (workspace_id, commande, auteur, details, jour, cle_idempotence, statut)
                    VALUES (p_ws, 'injection_issue', 'passerelle', '{}', v_jour, p_cle, 'liberee')
                    ON CONFLICT (workspace_id, cle_idempotence) WHERE commande = 'injection_issue' AND cle_idempotence IS NOT NULL DO NOTHING;
                GET DIAGNOSTICS v_n = ROW_COUNT;
                IF v_n > 0 THEN
                    UPDATE public.envoi_compteur_global SET n = n - 1 WHERE jour = v_jour AND n > 0;
                    -- S1-A : la place d'espace réservée est rendue aussi (explicitement pour p_ws, jamais sous 0).
                    UPDATE public.envoi_compteur_espace SET n = n - 1 WHERE workspace_id = p_ws AND jour = v_jour AND n > 0;
                END IF;
                RETURN v_n > 0;
            END;
            $f$ LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public;
            REVOKE ALL ON FUNCTION envoi_injection_liberer(UUID, TEXT) FROM PUBLIC;
        SQL);

        $nom = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($nom !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$nom]) !== null) {
            $role = '"' . str_replace('"', '""', $nom) . '"';
            DB::statement('REVOKE ALL ON public.envoi_compteur_espace FROM ' . $role);
            DB::statement('GRANT SELECT ON public.envoi_compteur_espace TO ' . $role);
            DB::statement('GRANT EXECUTE ON FUNCTION public.envoi_compteur_espace_reserver(UUID, DATE, INTEGER) TO ' . $role);
            DB::statement('GRANT EXECUTE ON FUNCTION public.envoi_injection_liberer(UUID, TEXT) TO ' . $role);
        }
    }

    /** Retour arrière INERTE : table, compteurs et fonctions sont conservés. */
    public function down(): void {}
};
