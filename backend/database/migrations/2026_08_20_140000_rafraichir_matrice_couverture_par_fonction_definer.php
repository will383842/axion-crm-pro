<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 🔴 A08-001 (S1) — « coverage:refresh-matrix echoue 71 fois sur 71 en
 * production depuis l'armement du role applicatif ».
 *
 * ── LA MECANIQUE, MESUREE (2026-08-20, base axion_crm_test_lot8) ────────────
 *
 *   $ psql -U axion_app -c "REFRESH MATERIALIZED VIEW coverage_matrix_cells;"
 *     ERROR:  must be owner of materialized view coverage_matrix_cells
 *
 * `REFRESH MATERIALIZED VIEW` n'est PAS un privilege : PostgreSQL le reserve au
 * PROPRIETAIRE de la vue. Aucun `GRANT` ne peut l'accorder. Or le lot L0
 * (`2026_08_14_000001_harden_workspace_isolation`) a cree `axion_app` avec
 * SELECT/INSERT/UPDATE/DELETE, et `coverage_matrix_cells` appartient au role
 * proprietaire. Des que `CRM_DB_APP_ROLE_ENABLED` est passe a true, la tache
 * horaire de `routes/console.php` s'est mise a mourir a chaque passage.
 *
 * ── LES DEUX MAUVAISES REPONSES, ET POURQUOI ON NE LES PREND PAS ───────────
 *
 *  a) `ALTER MATERIALIZED VIEW … OWNER TO axion_app` : rendrait au role
 *     applicatif le droit de SUPPRIMER la vue et d'en changer la definition.
 *     C'est exactement ce que le lot L0 lui a retire.
 *  b) faire tourner la commande sur la connexion `pgsql_owner` : le conteneur
 *     `scheduler` porterait alors en permanence les identifiants du role
 *     proprietaire (superuser en production) pour UNE operation horaire.
 *
 * ── LE CORRECTIF ───────────────────────────────────────────────────────────
 *
 * Une fonction `SECURITY DEFINER` appartenant au proprietaire, dont le SEUL
 * effet est de rafraichir cette vue-la. Le role applicatif recoit EXECUTE
 * dessus, et rien d'autre : il obtient le geste, pas la propriete. La fonction
 * ne rend aucune ligne — elle ne peut donc pas servir a lire des donnees en
 * contournant la RLS.
 *
 *  · `SET search_path = public, pg_catalog` : obligatoire sur toute fonction
 *    `SECURITY DEFINER` (un `search_path` libre est un vecteur connu de
 *    detournement de resolution), et deja la regle du depot depuis
 *    `2026_08_16_200000_fixer_search_path_des_fonctions`.
 *  · `REVOKE … FROM PUBLIC` avant le GRANT : sans cela, EXECUTE est accorde a
 *    PUBLIC par defaut, donc a tout role de la base.
 *  · Le premier rafraichissement ne peut pas etre `CONCURRENTLY` : la vue est
 *    creee `WITH NO DATA` (`2026_05_16_000006`), et PostgreSQL refuse
 *    « CONCURRENTLY cannot be used when the materialized view is not
 *    populated ». La fonction retombe donc d'elle-meme sur le mode simple tant
 *    que `relispopulated` est faux — sinon la premiere execution horaire
 *    echouerait pour une deuxieme raison, cachee derriere la premiere.
 *
 * ── UN PIEGE LATENT, ECRIT ICI PLUTOT QUE DECOUVERT PLUS TARD ─────────────
 *
 * `coverage_matrix_cells` lit `companies`, qui porte `FORCE ROW LEVEL
 * SECURITY` (lot L0). En `SECURITY DEFINER`, la fonction tourne avec les droits
 * de SON PROPRIETAIRE, c'est-a-dire du role qui a joue cette migration. Ce role
 * est aujourd'hui `axion`, SUPERUSER et BYPASSRLS : il voit toutes les lignes,
 * et le rollup est complet. Le jour ou les migrations tourneront avec un role
 * proprietaire NON-superuser, `FORCE ROW LEVEL SECURITY` s'appliquera aussi a
 * lui : sans `app.current_workspace_id`, `companies` rendrait ZERO ligne et la
 * vue se rafraichirait A VIDE — en repondant « OK ». Ce n'est pas un defaut
 * present ; c'est un defaut a venir si la propriete change, et il serait MUET.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.rafraichir_matrice_couverture(p_concurrent BOOLEAN DEFAULT FALSE)
            RETURNS VOID
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_catalog
            AS $fn$
            DECLARE
                v_peuplee BOOLEAN;
            BEGIN
                SELECT relispopulated
                INTO   v_peuplee
                FROM   pg_class
                WHERE  oid = 'public.coverage_matrix_cells'::regclass;

                IF p_concurrent AND v_peuplee THEN
                    REFRESH MATERIALIZED VIEW CONCURRENTLY public.coverage_matrix_cells;
                ELSE
                    REFRESH MATERIALIZED VIEW public.coverage_matrix_cells;
                END IF;
            END
            $fn$;

            COMMENT ON FUNCTION public.rafraichir_matrice_couverture(BOOLEAN) IS
                'A08-001 : seul chemin par lequel le role applicatif peut rafraichir coverage_matrix_cells. REFRESH MATERIALIZED VIEW est reserve au proprietaire et ne s''accorde par aucun GRANT.';
        SQL);

        DB::statement('REVOKE ALL ON FUNCTION public.rafraichir_matrice_couverture(BOOLEAN) FROM PUBLIC');

        // Lu depuis la CONFIG et non env() : `config:cache` est actif en
        // production, ou env() renverrait null (meme regle que
        // `harden_workspace_isolation`).
        $role = (string) (config('database.connections.pgsql_app.username') ?: 'axion_app');

        if (! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role)) {
            throw new RuntimeException("Nom de role applicatif invalide : « {$role} ».");
        }

        // Le role peut ne pas exister sur une base ou le lot L0 n'a jamais
        // tourne : on ne fait pas rougir le deploiement pour autant, mais on le
        // dit — un GRANT muet qui n'a jamais eu lieu est exactement le genre de
        // silence qui a produit ce constat.
        $existe = DB::selectOne('SELECT 1 AS x FROM pg_roles WHERE rolname = ?', [$role]);

        if ($existe === null) {
            DB::unprepared(sprintf(
                'DO $$ BEGIN RAISE WARNING %s; END $$;',
                DB::getPdo()->quote(
                    "Role applicatif « {$role} » absent : EXECUTE sur rafraichir_matrice_couverture() "
                    . "n'a PAS ete accorde. coverage:refresh-matrix echouera des que le role sera arme.",
                ),
            ));

            return;
        }

        DB::statement("GRANT EXECUTE ON FUNCTION public.rafraichir_matrice_couverture(BOOLEAN) TO {$role}");
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS public.rafraichir_matrice_couverture(BOOLEAN)');
    }
};
