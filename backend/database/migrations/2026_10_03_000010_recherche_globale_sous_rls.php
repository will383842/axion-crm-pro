<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PALETTE ⌘K (`/search`) SOUS LA SÉCURITÉ PAR ESPACE — même défaut, même
 * remède que la #292.
 *
 * ── LE CONSTAT (prod, 2026-10-03, lecture seule, `SET ROLE axion_app`) ──────
 * Chaque frappe dans la palette lançait, sous la RLS forcée :
 *   - `companies` : `denomination_normalized ILIKE '%x%' OR siren LIKE '%x%'`
 *     → Parallel Seq Scan sur 4,3 M de lignes, 3,9 à 4,7 s ;
 *   - `contacts` : `last_name / first_name / email ILIKE '%x%'`
 *     → Parallel Seq Scan sur 1,3 M de lignes, ~1,25 s.
 * `ILIKE` et `LIKE` ne sont pas « leakproof » : sous `axion_app`, Postgres ne
 * peut les évaluer qu'APRÈS le filtre de la politique, et aucun index ne sert.
 * (`tags`, quelques centaines de lignes, < 7 ms : inchangé.)
 *
 * ── LE REMÈDE ───────────────────────────────────────────────────────────────
 *   - entreprises par NOM : `entreprises_choix_ids` (#292), RÉUTILISÉE telle
 *     quelle, via `App\Support\RechercheEntreprisesParNom` ;
 *   - entreprises par SIREN : `entreprises_siren_ids` (ci-dessous) — égalité
 *     sur 9 chiffres, PRÉFIXE sinon, servis par l'index unique
 *     `(workspace_id, siren)` ; jamais `%x%` ;
 *   - personnes : `contacts_recherche_ids` (ci-dessous) — nom et prénom par
 *     les index trigrammes, PUIS début d'e-mail : intervalle sur l'index
 *     `idx_contacts_email` (bornes castées en citext, comme la colonne),
 *     parcouru dans l'ordre de l'index avec LIMIT ; la sous-chaîne d'e-mail
 *     n'a aucun index, elle n'est plus cherchée.
 *
 * Mêmes garanties que `entreprises_choix_ids` : SECURITY DEFINER,
 * `search_path = pg_catalog, public`, EXECUTE retiré à PUBLIC et accordé au
 * seul rôle applicatif, `p_workspace` égal à l'espace du contexte de la
 * connexion (sinon rien), `workspace_id` posé explicitement, saisie en
 * paramètre (`EXECUTE … USING`), plafond serveur (50). L'appelant RELIT les
 * lignes sous la RLS.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.entreprises_siren_ids(
                p_workspace UUID,
                p_chiffres TEXT,
                p_limite INT
            )
            RETURNS SETOF BIGINT
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $fn$
            DECLARE
                v_limite INT := least(greatest(coalesce(p_limite, 10), 1), 50);
            BEGIN
                IF p_workspace IS NULL
                   OR p_workspace::TEXT IS DISTINCT FROM NULLIF(current_setting('app.current_workspace_id', true), '') THEN
                    RETURN;
                END IF;
                -- Que des chiffres, 2 à 9 : jamais de joker venu de la saisie.
                IF p_chiffres IS NULL OR p_chiffres !~ '^[0-9]{2,9}$' THEN
                    RETURN;
                END IF;

                IF length(p_chiffres) = 9 THEN
                    RETURN QUERY EXECUTE
                        'SELECT c.id FROM public.companies c WHERE c.workspace_id = $1 AND c.deleted_at IS NULL'
                        || ' AND c.siren = $2::bpchar ORDER BY c.siren, c.id LIMIT $3'
                        USING p_workspace, p_chiffres, v_limite;
                ELSE
                    -- Préfixe : un intervalle [début, début suivant), servi par
                    -- l'index unique (workspace_id, siren). Les bornes (dont ':'
                    -- après '9') ne sont exactes qu'en collation C — celle de la
                    -- base de production et de la CI.
                    RETURN QUERY EXECUTE
                        'SELECT c.id FROM public.companies c WHERE c.workspace_id = $1 AND c.deleted_at IS NULL'
                        || ' AND c.siren >= $2::bpchar AND c.siren < $3::bpchar ORDER BY c.siren, c.id LIMIT $4'
                        USING p_workspace,
                              rpad(p_chiffres, 9, '0'),
                              CASE WHEN p_chiffres ~ '^9+$' THEN ':'
                                   ELSE lpad(((p_chiffres)::NUMERIC + 1)::TEXT, length(p_chiffres), '0') END,
                              v_limite;
                END IF;
            END
            $fn$;
            REVOKE EXECUTE ON FUNCTION public.entreprises_siren_ids(UUID, TEXT, INT) FROM PUBLIC;

            CREATE OR REPLACE FUNCTION public.contacts_recherche_ids(
                p_workspace UUID,
                p_terme TEXT,
                p_limite INT
            )
            RETURNS SETOF BIGINT
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, public
            AS $fn$
            DECLARE
                v_limite INT := least(greatest(coalesce(p_limite, 10), 1), 50);
                v_motif  TEXT;
                v_bas    TEXT;
                v_haut   TEXT;
                v_dernier INT;
                v_rendus INT;
            BEGIN
                IF p_workspace IS NULL
                   OR p_workspace::TEXT IS DISTINCT FROM NULLIF(current_setting('app.current_workspace_id', true), '') THEN
                    RETURN;
                END IF;
                -- Au moins 3 caractères : sous trois, aucun trigramme ne
                -- s'extrait et l'index relirait la table entière.
                IF p_terme IS NULL OR length(p_terme) < 3 OR length(p_terme) > 200 THEN
                    RETURN;
                END IF;

                -- Saisie BRUTE : jokers `LIKE` neutralisés ici, pour le nom.
                v_motif := replace(replace(replace(p_terme, '\', '\\'), '%', '\%'), '_', '\_');

                -- 1. Nom et prénom : index trigrammes (BitmapOr).
                RETURN QUERY EXECUTE
                    'SELECT c.id FROM public.contacts c WHERE c.workspace_id = $1 AND c.deleted_at IS NULL AND ('
                    || ' c.last_name ILIKE (''%'' || $2 || ''%'')'
                    || ' OR c.first_name ILIKE (''%'' || $2 || ''%''))'
                    || ' ORDER BY c.last_name, c.id LIMIT $3'
                    USING p_workspace, v_motif, v_limite;
                GET DIAGNOSTICS v_rendus = ROW_COUNT;
                IF v_rendus >= v_limite THEN
                    RETURN;
                END IF;

                -- 2. Début d'e-mail : un INTERVALLE [début, début suivant) sur
                -- `idx_contacts_email`, parcouru DANS L'ORDRE de l'index (ORDER BY
                -- email + LIMIT : l'index s'arrête tôt, même pour « contact@ »).
                -- ⚠️ Les bornes sont castées en `citext`, comme la colonne : en
                -- `text`, Postgres compare `email::text` et l'index ne sert plus
                -- (relecture de la #294 : Parallel Seq Scan, ~1,2 s). L'intervalle
                -- n'est exact qu'en collation C (base de production et CI).
                v_bas := lower(p_terme);
                v_dernier := ascii(right(v_bas, 1));
                -- Pas de caractère « suivant » après U+D7FF ni U+10FFFF : borne
                -- haute ouverte plutôt qu'une erreur.
                IF v_dernier IN (55295, 1114111) THEN
                    v_haut := NULL;
                ELSE
                    v_haut := left(v_bas, -1) || chr(v_dernier + 1);
                END IF;

                RETURN QUERY EXECUTE
                    'SELECT c.id FROM public.contacts c WHERE c.workspace_id = $1 AND c.deleted_at IS NULL'
                    || ' AND c.email >= $2::public.citext AND ($3::text IS NULL OR c.email < $3::public.citext)'
                    || ' AND NOT (coalesce(c.last_name, '''') ILIKE (''%'' || $4 || ''%'')'
                    || '          OR coalesce(c.first_name, '''') ILIKE (''%'' || $4 || ''%''))'
                    || ' ORDER BY c.email, c.id LIMIT $5'
                    USING p_workspace, v_bas, v_haut, v_motif, v_limite - v_rendus;
            END
            $fn$;
            REVOKE EXECUTE ON FUNCTION public.contacts_recherche_ids(UUID, TEXT, INT) FROM PUBLIC;
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT EXECUTE ON FUNCTION public.entreprises_siren_ids(UUID, TEXT, INT) TO ' . $role);
            DB::statement('GRANT EXECUTE ON FUNCTION public.contacts_recherche_ids(UUID, TEXT, INT) TO ' . $role);
        }
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS public.entreprises_siren_ids(UUID, TEXT, INT)');
        DB::statement('DROP FUNCTION IF EXISTS public.contacts_recherche_ids(UUID, TEXT, INT)');
    }
};
