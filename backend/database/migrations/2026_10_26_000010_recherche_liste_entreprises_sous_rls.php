<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RECHERCHE PAR NOM DE LA LISTE « ENTREPRISES » SOUS LA SÉCURITÉ PAR ESPACE
 * (26/10/2026 — `docs/DIAG-LISTE-ENTREPRISES-2026-10-26.md`, §2.4 et §5.5).
 *
 * ── LE SYMPTÔME ─────────────────────────────────────────────────────────────
 * `/companies?search=OURY` : squelettes indéfiniment. `filter[denomination]`
 * passait par `RechercheDenomination` (`denomination_normalized ILIKE …`).
 *
 * ── LA CAUSE (même barrière que la #292) ────────────────────────────────────
 * `companies` porte une RLS FORCÉE. Sous `axion_app`, `ILIKE` (non
 * « leakproof ») ne peut être évalué qu'APRÈS le filtre de la politique :
 * l'index trigrammes `idx_companies_denomination_trgm` est inutilisable,
 * Parallel Seq Scan sur ≈ 4,4 M de lignes, pour la page ET pour le total.
 * Le correctif `SECURITY DEFINER` existait pour le sélecteur
 * (`2026_10_02_000050`, `entreprises_choix_ids`) ; il n'avait jamais été porté
 * à la liste (patron `A-011`).
 *
 * ── LE CORRECTIF : le patron `entreprises_choix_ids`, sans plafond ─────────
 * La liste est paginée et COMPTÉE (`TotalListe`) : un plafond de 50 fausserait
 * le total. La fonction rend donc TOUS les identifiants qui satisfont la
 * condition, dans UN espace, et la liste les RELIT par une semi-jointure
 * ordinaire (`companies.id IN (…)`), sous la RLS et avec tous ses autres
 * filtres — double garde :
 *   - `workspace_id = p_workspace` posé EXPLICITEMENT ;
 *   - `p_workspace` doit être l'espace du contexte de la connexion
 *     (`app.current_workspace_id`), sinon AUCUNE ligne : on ne peut pas viser
 *     un autre espace que celui où l'on est déjà ;
 *   - `search_path` fixé, EXECUTE retiré à PUBLIC, accordé au seul rôle
 *     applicatif ;
 *   - les termes sont des PARAMÈTRES (`EXECUTE … USING`), jamais du texte
 *     concaténé : le SQL dynamique ne porte que des positions `$2[1]`, `$2[2]`.
 *
 * Sémantique INCHANGÉE (celle de `RechercheDenomination::appliquer`) : chaque
 * terme (déjà échappé des jokers `LIKE` par le PHP) est normalisé par
 * `normalize_name`, cherché en sous-chaîne, et plusieurs termes sont en OU.
 *
 * ── AUCUN INDEX NOUVEAU ─────────────────────────────────────────────────────
 * `idx_companies_denomination_trgm` sert déjà la condition hors RLS (mesuré
 * pour la #292 : 70 à 225 ms à froid). Un second index trigrammes coûterait
 * des centaines de Mo pour dupliquer celui-là.
 *
 * PUREMENT ADDITIVE : une fonction créée, aucune donnée lue ni écrite, aucune
 * table modifiée. `lock_timeout` borné par prudence (le `GRANT` touche le
 * catalogue). Interdits respectés : ni BYPASSRLS, ni LEAKPROOF.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET lock_timeout = '30s'");
        try {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION public.entreprises_liste_denomination_ids(
                    p_workspace UUID,
                    p_termes TEXT[]
                )
                RETURNS SETOF BIGINT
                LANGUAGE plpgsql
                STABLE
                SECURITY DEFINER
                SET search_path = pg_catalog, public
                ROWS 1000
                AS $fn$
                DECLARE
                    n_t      INT := coalesce(array_length(p_termes, 1), 0);
                    v_motifs TEXT[];
                    v_ou     TEXT;
                BEGIN
                    -- L'espace demandé doit être CELUI du contexte de la connexion.
                    IF p_workspace IS NULL
                       OR p_workspace::TEXT IS DISTINCT FROM NULLIF(current_setting('app.current_workspace_id', true), '') THEN
                        RETURN;
                    END IF;
                    IF n_t = 0 OR n_t > 20 THEN
                        RETURN;
                    END IF;

                    -- Même normalisation que `RechercheDenomination` : appliquée
                    -- au TERME, jamais à la colonne (déjà normalisée et indexée).
                    SELECT array_agg('%' || public.normalize_name(u.t) || '%' ORDER BY u.i)
                      INTO v_motifs
                      FROM unnest(p_termes) WITH ORDINALITY AS u(t, i);

                    SELECT string_agg(format('c.denomination_normalized ILIKE $2[%s]', i), ' OR ')
                      INTO v_ou FROM generate_series(1, n_t) AS i;

                    RETURN QUERY EXECUTE
                        'SELECT c.id FROM public.companies c WHERE c.workspace_id = $1 AND c.deleted_at IS NULL AND ('
                        || v_ou || ')'
                        USING p_workspace, v_motifs;
                END
                $fn$;
                REVOKE EXECUTE ON FUNCTION public.entreprises_liste_denomination_ids(UUID, TEXT[]) FROM PUBLIC;
            SQL);

            $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
            if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
                $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
                DB::statement('GRANT EXECUTE ON FUNCTION public.entreprises_liste_denomination_ids(UUID, TEXT[]) TO ' . $role);
            }
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS public.entreprises_liste_denomination_ids(UUID, TEXT[])');
    }
};
