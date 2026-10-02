<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RECHERCHE D'ENTREPRISE PAR NOM SOUS LA SÉCURITÉ PAR ESPACE (suite de la #287).
 *
 * ── LE SYMPTÔME (prod, 2026-10-02 ~22 h) ────────────────────────────────────
 * Toute recherche par NOM du sélecteur « Entreprise » (« societe generale »,
 * et même le mot rare « sciado ») tombait en 503 « trop large » : délai SQL
 * de 8 s dépassé. La recherche par SIREN, elle, était instantanée.
 *
 * ── LA CAUSE, PROUVÉE PAR `EXPLAIN` SUR LA PROD (lecture seule) ──────────────
 * `companies` porte une RLS FORCÉE (`companies_workspace_isolation`). Sous le
 * rôle applicatif `axion_app` (ni superuser, ni BYPASSRLS), Postgres n'a pas
 * le droit d'évaluer un prédicat NON « leakproof » AVANT le filtre de la
 * politique — or `ILIKE` (`~~*`) ne l'est pas. L'index trigrammes
 * `idx_companies_denomination_trgm` devient inutilisable :
 *
 *   sous `axion` (propriétaire, superuser) :
 *     Bitmap Index Scan on idx_companies_denomination_trgm — « sciado » 70 ms
 *   sous `axion_app` (RLS) :
 *     Parallel Seq Scan on companies (4,3 M de lignes) — > 8 s, 503
 *
 * Les mesures de la relecture avaient été faites sous `axion` : elles ne
 * voyaient pas la barrière.
 *
 * ── LE CORRECTIF ────────────────────────────────────────────────────────────
 * Une fonction `SECURITY DEFINER` minimale, propriété du propriétaire, qui ne
 * rend QUE des identifiants (au plus 50), cherchés dans UN espace :
 *   - `workspace_id = p_workspace` est posé EXPLICITEMENT dans la requête ;
 *   - et `p_workspace` doit être l'espace du contexte de la connexion
 *     (`app.current_workspace_id`) — sinon, aucune ligne (même garde que
 *     `contacts_retires_contient_ancre`) : un appelant ne peut pas viser un
 *     autre espace que celui où il est déjà ;
 *   - `search_path` fixé, EXECUTE retiré à PUBLIC, donné au seul rôle
 *     applicatif ;
 *   - les mots sont des PARAMÈTRES (`EXECUTE … USING`), jamais du texte
 *     concaténé : le SQL dynamique ne porte que des positions `$3[1]`, `$3[2]`.
 * Le contrôleur RELIT ensuite ces lignes par une requête ordinaire, sous la
 * RLS : double garde.
 *
 * Deux passes, toutes deux servies par l'index :
 *   1. les noms qui contiennent TOUS les mots tapés (ET de trigrammes, très
 *      sélectif : « air france » 225 ms, « societe generale » 176 ms à froid) ;
 *   2. seulement si la passe 1 n'a pas rempli la liste : un mot significatif
 *      dans le nom, les autres dans le nom ou la ville (« martin lyon »).
 * L'ordre rendu est celui de la #287 : tous les mots dans le nom d'abord, puis
 * le nom qui commence par le premier mot, puis le plus court.
 *
 * Interdits respectés : la RLS n'est ni désactivée ni contournée pour le rôle
 * applicatif (pas de BYPASSRLS, pas de LEAKPROOF).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.entreprises_choix_ids(
                p_workspace UUID,
                p_entrees TEXT[],
                p_filtres TEXT[],
                p_code_postal TEXT,
                p_limite INT
            )
            RETURNS SETOF BIGINT
            LANGUAGE plpgsql
            STABLE
            SECURITY DEFINER
            SET search_path = public, pg_catalog
            AS $fn$
            DECLARE
                n_e      INT := coalesce(array_length(p_entrees, 1), 0);
                n_f      INT := coalesce(array_length(p_filtres, 1), 0);
                v_limite INT := least(greatest(coalesce(p_limite, 10), 1), 50);
                v_tous   TEXT;
                v_entree TEXT;
                v_filtre TEXT;
                v_cp     TEXT := '';
                v_ordre  TEXT;
                v_rendus INT;
            BEGIN
                -- L'espace demandé doit être CELUI du contexte de la connexion.
                IF p_workspace IS NULL
                   OR p_workspace::TEXT IS DISTINCT FROM NULLIF(current_setting('app.current_workspace_id', true), '') THEN
                    RETURN;
                END IF;
                IF n_e = 0 OR n_f = 0 OR n_e > 8 OR n_f > 8 THEN
                    RETURN;
                END IF;

                SELECT string_agg(format('c.denomination_normalized ILIKE (''%%'' || $3[%s] || ''%%'')', i), ' AND ')
                  INTO v_tous FROM generate_series(1, n_f) AS i;
                SELECT string_agg(format('c.denomination_normalized ILIKE (''%%'' || $2[%s] || ''%%'')', i), ' OR ')
                  INTO v_entree FROM generate_series(1, n_e) AS i;
                SELECT string_agg(format(
                         '(c.denomination_normalized ILIKE (''%%'' || $3[%1$s] || ''%%'') '
                         || 'OR public.normalize_name(coalesce(c.city_name, c.city, '''')) ILIKE (''%%'' || $3[%1$s] || ''%%''))', i), ' AND ')
                  INTO v_filtre FROM generate_series(1, n_f) AS i;

                IF p_code_postal IS NOT NULL AND p_code_postal <> '' THEN
                    v_cp := ' AND c.postcode = $4';
                END IF;

                v_ordre := ' ORDER BY (c.denomination_normalized ILIKE ($3[1] || ''%'')) DESC,'
                        || ' length(c.denomination_normalized), c.denomination_normalized, c.id LIMIT $5';

                -- Passe 1 : tous les mots dans le nom.
                RETURN QUERY EXECUTE
                    'SELECT c.id FROM public.companies c WHERE c.workspace_id = $1 AND c.deleted_at IS NULL AND ('
                    || v_tous || ')' || v_cp || v_ordre
                    USING p_workspace, p_entrees, p_filtres, p_code_postal, v_limite;
                GET DIAGNOSTICS v_rendus = ROW_COUNT;

                IF v_rendus >= v_limite THEN
                    RETURN;
                END IF;

                -- Passe 2 : un mot significatif dans le nom, chaque mot dans le
                -- nom ou la ville — hors lignes déjà rendues par la passe 1.
                RETURN QUERY EXECUTE
                    'SELECT c.id FROM public.companies c WHERE c.workspace_id = $1 AND c.deleted_at IS NULL AND ('
                    || v_entree || ') AND ' || v_filtre || ' AND NOT (' || v_tous || ')' || v_cp || v_ordre
                    USING p_workspace, p_entrees, p_filtres, p_code_postal, v_limite - v_rendus;
            END
            $fn$;
            REVOKE EXECUTE ON FUNCTION public.entreprises_choix_ids(UUID, TEXT[], TEXT[], TEXT, INT) FROM PUBLIC;
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT EXECUTE ON FUNCTION public.entreprises_choix_ids(UUID, TEXT[], TEXT[], TEXT, INT) TO ' . $role);
        }
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS public.entreprises_choix_ids(UUID, TEXT[], TEXT[], TEXT, INT)');
    }
};
