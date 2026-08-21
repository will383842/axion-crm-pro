<?php

/**
 * GARDE — A08-001 (S1), SECOND VOLET : les PRECONDITIONS du correctif.
 *
 * `RafraichissementMatriceCouvertureTest` prouve que la commande ABOUTIT sous le
 * role applicatif. C'est necessaire, ce n'est pas suffisant : le correctif
 * repose sur une fonction `SECURITY DEFINER`, et une fonction `SECURITY
 * DEFINER` n'est sure qu'a trois conditions qu'AUCUN test du depot n'inspectait
 * (verifie le 2026-08-21 : `grep -rn "prosecdef\|proconfig" backend/tests/`
 * ne rendait rien).
 *
 * ── LES TROIS TROUS MESURES, ET POURQUOI LA GARDE EXISTANTE NE LES VOIT PAS ─
 *
 *  1. `SET search_path` — un `SECURITY DEFINER` a `search_path` libre est un
 *     vecteur d'escalade : le proprietaire est ici SUPERUSER, et n'importe quel
 *     role pouvant creer un objet dans un schema place devant `public`
 *     detournerait la resolution. Un `CREATE OR REPLACE FUNCTION` qui oublie la
 *     clause laisse la garde existante ENTIEREMENT VERTE — la commande aboutit
 *     toujours.
 *
 *  2. `REVOKE EXECUTE … FROM PUBLIC` — la migration le fait, mais le troisieme
 *     test de la garde existante REVOQUE LUI-MEME depuis PUBLIC avant de
 *     mesurer (« sans ce REVOKE-la, le retrait ci-dessus serait sans effet »).
 *     Il COMPENSE donc un REVOKE manquant au lieu de le constater : si la
 *     migration perdait sa ligne `REVOKE … FROM PUBLIC`, tout resterait vert et
 *     tout role de la base pourrait appeler la fonction.
 *
 *  3. Le balayage `search_path` du depot repose sur
 *     `2026_08_16_200000_fixer_search_path_des_fonctions`, qui enumere SEPT
 *     signatures ECRITES A LA MAIN. Les fonctions creees APRES cette migration
 *     — dont celle du present correctif, et les cinq de
 *     `2026_08_20_000001_quality_score_declencheur_complet` — sont hors de
 *     cette liste. Elles portent la clause aujourd'hui ; rien ne l'exige. Le
 *     balayage ci-dessous interroge `pg_proc`, pas une liste : la prochaine
 *     fonction ajoutee sans clause rougira sans qu'on ait a penser a elle.
 *
 * ── ET UN QUATRIEME, QU'ON NE FERME PAS : ON LE COMPTE ─────────────────────
 *
 * La migration du correctif ecrit elle-meme le piege latent : `SECURITY
 * DEFINER` fait tourner la fonction avec les droits de SON proprietaire, et
 * `coverage_matrix_cells` lit `companies`, qui porte FORCE ROW LEVEL SECURITY.
 * Le proprietaire est aujourd'hui SUPERUSER + BYPASSRLS, donc le rollup est
 * complet. Le jour ou les migrations tourneront sous un proprietaire non
 * superuser, la vue se rafraichirait A VIDE en repondant « OK » — un echec
 * MUET, exactement le defaut que A08-001 reproche. Le dernier test mesure le
 * FAIT d'aujourd'hui (une ligne inseree ressort bien du rollup) plutot que
 * l'attribut du role : il rougira le jour ou la propriete changera.
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Nombre de fonctions du depot (hors membres d'extension) releve dans
 * `pg_proc` le 2026-08-21 sur `axion_crm_test_lot6`. Ce n'est PAS une cible :
 * c'est un TEMOIN DE COUVERTURE. Si le balayage rend moins que cela, c'est que
 * la requete ne voit plus ce qu'elle croit voir — un filtre trop serre, un
 * schema renomme, des migrations non jouees — et un balayage qui ne voit rien
 * est vert pour la pire des raisons.
 */
const A08_FONCTIONS_ATTENDUES_AU_MINIMUM = 9;

const A08_FONCTION = 'rafraichir_matrice_couverture';

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
});

afterEach(function () {
    DB::connection('pgsql_app')->disconnect();
    DB::connection('pgsql_owner')->disconnect();
});

/**
 * @return list<object>
 */
function a08FonctionsDuDepot(): array
{
    // Les membres d'extension (PostGIS, pgcrypto, pgvector, pg_partman…) sont
    // exclus par `pg_depend.deptype = 'e'` et non par une liste de noms : une
    // exclusion par nom raterait la prochaine extension installee, et
    // inclurait a tort une fonction du depot qui lui ressemblerait.
    return DB::select(<<<'SQL'
        SELECT p.oid,
               p.proname,
               pg_get_function_identity_arguments(p.oid) AS args,
               p.prosecdef,
               p.proconfig,
               p.proacl IS NULL AS acl_par_defaut,
               pg_get_userbyid(p.proowner) AS proprietaire
        FROM   pg_proc p
        JOIN   pg_namespace n ON n.oid = p.pronamespace
        WHERE  n.nspname = 'public'
          AND  NOT EXISTS (
                   SELECT 1
                   FROM   pg_depend d
                   WHERE  d.objid = p.oid
                     AND  d.classid = 'pg_proc'::regclass
                     AND  d.deptype = 'e'
               )
        ORDER BY p.proname
    SQL);
}

function a08SearchPathFixe(?string $proconfig): bool
{
    // `proconfig` remonte en litteral tableau PostgreSQL : {"search_path=public, pg_catalog"}
    return $proconfig !== null && str_contains($proconfig, 'search_path=');
}

test('BALAYAGE — chaque fonction du depot porte un search_path fixe, et le balayage voit quelque chose', function () {
    $fonctions = a08FonctionsDuDepot();

    // TEMOIN DE COUVERTURE — avant de se rejouir qu'il n'y ait aucun defaut,
    // prouver que l'instrument regarde bien une population non vide.
    $this->assertGreaterThanOrEqual(
        A08_FONCTIONS_ATTENDUES_AU_MINIMUM,
        count($fonctions),
        'Le balayage de pg_proc ne voit que ' . count($fonctions) . ' fonction(s) du depot, '
        . 'contre ' . A08_FONCTIONS_ATTENDUES_AU_MINIMUM . ' relevees le 2026-08-21. '
        . 'Ce test serait vert sans rien avoir inspecte.',
    );

    $sansClause = [];
    foreach ($fonctions as $f) {
        if (! a08SearchPathFixe($f->proconfig)) {
            $sansClause[] = $f->proname . '(' . $f->args . ')';
        }
    }

    $this->assertSame(
        [],
        $sansClause,
        "Fonction(s) du depot sans `SET search_path` fixe :\n  - " . implode("\n  - ", $sansClause)
        . "\nRegle du depot depuis 2026_08_16_200000. Sur une fonction SECURITY DEFINER, "
        . 'un search_path libre est un vecteur de detournement de resolution.',
    );
});

test('la fonction du correctif A08-001 est SECURITY DEFINER, a search_path fixe, et n appartient PAS au role applicatif', function () {
    $fonctions = array_values(array_filter(
        a08FonctionsDuDepot(),
        static fn (object $f): bool => $f->proname === A08_FONCTION,
    ));

    $this->assertCount(
        1,
        $fonctions,
        'La fonction ' . A08_FONCTION . '() est introuvable dans pg_proc : la migration '
        . '2026_08_20_140000 n a pas tourne, ou la fonction a ete supprimee.',
    );

    $f = $fonctions[0];

    $this->assertTrue(
        (bool) $f->prosecdef,
        'La fonction ' . A08_FONCTION . '() n est plus SECURITY DEFINER : le role applicatif '
        . 'perd le seul chemin par lequel il peut rafraichir la vue.',
    );

    $this->assertTrue(
        a08SearchPathFixe($f->proconfig),
        'La fonction ' . A08_FONCTION . '() est SECURITY DEFINER SANS search_path fixe. '
        . 'proconfig = ' . var_export($f->proconfig, true),
    );

    $roleApp = (string) config('database.connections.pgsql_app.username');

    $this->assertNotSame(
        $roleApp,
        (string) $f->proprietaire,
        'La fonction appartient au role applicatif : SECURITY DEFINER ne lui accorde alors '
        . 'plus rien, et le role a recupere le droit de la redefinir.',
    );
});

test('EXECUTE est accorde au role applicatif et REFUSE a PUBLIC', function () {
    $roleApp = (string) config('database.connections.pgsql_app.username');

    // `proacl IS NULL` signifie « privileges par defaut », c'est-a-dire EXECUTE
    // a PUBLIC. C'est le cas d'une fonction creee SANS le REVOKE : il faut le
    // traiter comme un echec, pas comme une absence de donnee.
    $aclParDefaut = (bool) DB::scalar(
        "SELECT proacl IS NULL FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
         WHERE n.nspname = 'public' AND p.proname = ?",
        [A08_FONCTION],
    );

    $this->assertFalse(
        $aclParDefaut,
        'proacl est NULL sur ' . A08_FONCTION . '() : les privileges sont ceux par defaut, '
        . 'donc EXECUTE est accorde a PUBLIC. Le REVOKE de la migration a disparu.',
    );

    // `grantee = 0` designe PUBLIC dans `aclexplode` — il n a pas d entree dans
    // `pg_roles`, donc `has_function_privilege('public', …)` ne repond pas a la
    // question posee ici.
    $publicPeutExecuter = (bool) DB::scalar(
        "SELECT EXISTS (
             SELECT 1
             FROM   pg_proc p
             JOIN   pg_namespace n ON n.oid = p.pronamespace,
                    aclexplode(p.proacl) a
             WHERE  n.nspname = 'public'
               AND  p.proname = ?
               AND  a.grantee = 0
               AND  a.privilege_type = 'EXECUTE'
         )",
        [A08_FONCTION],
    );

    $this->assertFalse(
        $publicPeutExecuter,
        'PUBLIC porte EXECUTE sur ' . A08_FONCTION . '() : tout role de la base peut declencher '
        . 'une fonction SECURITY DEFINER qui tourne avec les droits du proprietaire.',
    );

    $appPeutExecuter = (bool) DB::scalar(
        "SELECT has_function_privilege(?, p.oid, 'EXECUTE')
         FROM   pg_proc p
         JOIN   pg_namespace n ON n.oid = p.pronamespace
         WHERE  n.nspname = 'public' AND p.proname = ?",
        [$roleApp, A08_FONCTION],
    );

    $this->assertTrue(
        $appPeutExecuter,
        "Le role applicatif « {$roleApp} » n a PAS EXECUTE sur " . A08_FONCTION . '() : '
        . 'coverage:refresh-matrix echouera a chaque passage, comme les 71 du constat.',
    );
});

test('ANTI-VERT-A-VIDE — le rollup voit les lignes malgre FORCE ROW LEVEL SECURITY sur companies', function () {
    // La premisse du piege : sans FORCE RLS sur `companies`, ce test ne
    // mesurerait rien de particulier.
    $force = (bool) DB::scalar(
        "SELECT relforcerowsecurity FROM pg_class WHERE relname = 'companies'",
    );
    $this->assertTrue($force, '`companies` ne porte plus FORCE ROW LEVEL SECURITY : la premisse de ce test a change.');

    $workspace = DB::scalar('SELECT id FROM workspaces LIMIT 1');
    $this->assertNotNull($workspace, 'Aucun workspace en base : le test serait vert a vide.');

    $codePostal = '75116';
    $naf = '9999Z';

    // Nettoyage prealable : une execution precedente interrompue ne doit pas
    // faire passer ce test pour une mauvaise raison.
    DB::connection('pgsql_owner')->delete('DELETE FROM companies WHERE naf = ?', [$naf]);

    try {
        // On rafraichit AVEC LE PROPRIETAIRE *avant* d inserer : le rollup est
        // donc peuple, et ne contient PAS encore la ligne temoin. Le compte qui
        // suit ne peut venir que de la commande.
        //
        // (On ne depeuple pas : un `WITH NO DATA` ferait echouer le SELECT final
        // par une exception PostgreSQL au lieu de rendre 0, et le rouge de ce
        // test cesserait d etre lisible.)
        DB::connection('pgsql_owner')->statement('REFRESH MATERIALIZED VIEW coverage_matrix_cells');

        $avant = (int) DB::connection('pgsql_owner')->scalar(
            'SELECT COALESCE(SUM(company_count), 0) FROM coverage_matrix_cells WHERE naf = ?',
            [$naf],
        );
        $this->assertSame(0, $avant, 'Le rollup contient deja la ligne temoin avant la commande : le vert qui suit ne prouverait rien.');

        DB::connection('pgsql_owner')->insert(
            // `companies_identity_anchor_check` (2026_08_15_120001) exige `siren`
            // OU `foreign_id` : une insertion minimale sans ancre est rejetee.
            'INSERT INTO companies (workspace_id, postcode, naf, size_category, siren)
             VALUES (?, ?, ?, ?, ?)',
            [$workspace, $codePostal, $naf, 'tpe', '999999999'],
        );

        config(['database.default' => 'pgsql_app']);
        DB::purge('pgsql_app');

        try {
            $code = Artisan::call('coverage:refresh-matrix');
            $sortie = Artisan::output();
        } finally {
            config(['database.default' => 'pgsql']);
            DB::purge('pgsql_app');
        }

        $this->assertSame(0, $code, "coverage:refresh-matrix a rendu {$code}. Sortie :\n" . $sortie);

        $cellules = (int) DB::connection('pgsql_owner')->scalar(
            'SELECT COALESCE(SUM(company_count), 0) FROM coverage_matrix_cells WHERE naf = ?',
            [$naf],
        );

        $this->assertGreaterThanOrEqual(
            1,
            $cellules,
            'La commande a repondu OK et le rollup ne contient AUCUNE des lignes inserees. '
            . 'C est le defaut MUET annonce par 2026_08_20_140000 : la fonction SECURITY DEFINER '
            . 'tourne avec les droits de son proprietaire, et ce proprietaire ne voit plus '
            . '`companies` a travers FORCE ROW LEVEL SECURITY. Le rafraichissement est vide et '
            . 'personne ne le sait.',
        );
    } finally {
        DB::connection('pgsql_owner')->delete('DELETE FROM companies WHERE naf = ?', [$naf]);
        DB::connection('pgsql_owner')->statement('REFRESH MATERIALIZED VIEW coverage_matrix_cells');
    }
});
