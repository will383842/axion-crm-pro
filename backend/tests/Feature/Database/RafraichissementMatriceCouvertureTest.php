<?php

/**
 * GARDE — A08-001 (S1) : « coverage:refresh-matrix echoue 71 fois sur 71 en
 * production depuis l'armement du role applicatif ».
 *
 * ── LE DEFAUT, REPRODUIT AVANT D'ECRIRE LA MOINDRE LIGNE DE CORRECTIF ───────
 *
 *   $ docker exec -e PGPASSWORD=… axion-crm-postgres \
 *       psql -U axion_app -h 127.0.0.1 -d axion_crm_test_lot8 \
 *       -c "REFRESH MATERIALIZED VIEW coverage_matrix_cells;"
 *     ERROR:  must be owner of materialized view coverage_matrix_cells
 *
 * PostgreSQL reserve `REFRESH MATERIALIZED VIEW` au PROPRIETAIRE de la vue.
 * Aucun `GRANT` ne l'accorde : ce n'est pas un privilege, c'est un attribut de
 * propriete. Or `2026_08_14_000001_harden_workspace_isolation` cree le role
 * applicatif `axion_app` avec SELECT/INSERT/UPDATE/DELETE seulement, et
 * `coverage_matrix_cells` appartient a `axion`. Depuis que
 * `CRM_DB_APP_ROLE_ENABLED` est arme, la tache horaire de `routes/console.php`
 * meurt a chaque passage — et le planificateur de Laravel ne lit pas le code de
 * sortie d'une commande planifiee, donc l'echec est MUET : 71 passages, 71
 * echecs, zero alerte, une matrice de couverture figee.
 *
 * ── CE QUE CETTE GARDE VERIFIE ─────────────────────────────────────────────
 *
 *  1. TEMOIN : le rafraichissement DIRECT par le role applicatif echoue
 *     toujours (le correctif ne consiste PAS a donner la propriete de la vue au
 *     role applicatif — ce serait lui rendre le droit de la supprimer). Si un
 *     jour ce temoin passe au vert, c'est que quelqu'un a promu `axion_app`, et
 *     la garde suivante ne prouverait plus rien.
 *  2. La commande `coverage:refresh-matrix`, executee SUR LE ROLE APPLICATIF,
 *     rend 0 et laisse la vue PEUPLEE.
 *  3. Anti-vert-a-vide : la vue est depeuplee juste avant, et on verifie que
 *     c'est bien la commande qui l'a repeuplee.
 */

use App\Console\Commands\CoverageRefreshMatrix;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // La suite ne passe pas par RefreshDatabase ici : c'est justement l'etat du
    // catalogue (proprietaire, privileges, fonctions) qu'on mesure. On garantit
    // seulement que les migrations sont a jour, sans quoi le test serait vert a
    // vide sur une base ou la fonction correctrice n'existe pas encore.
    Artisan::call('migrate', ['--force' => true]);
});

afterEach(function () {
    DB::connection('pgsql_app')->disconnect();
    DB::connection('pgsql_owner')->disconnect();
});

test('TEMOIN — le role applicatif ne peut PAS rafraichir la vue en direct', function () {
    $role = (string) DB::connection('pgsql_app')->scalar('SELECT current_user');
    expect($role)->toBe((string) config('database.connections.pgsql_app.username'));

    // Le role de la vue doit rester distinct du role applicatif : c'est la
    // premisse du constat. Si les deux se confondaient, tout ce fichier serait
    // un vert de complaisance.
    $proprietaire = (string) DB::scalar(
        "SELECT pg_get_userbyid(relowner) FROM pg_class WHERE relname = 'coverage_matrix_cells'",
    );
    expect($proprietaire)->not->toBe($role);

    $erreur = null;
    try {
        DB::connection('pgsql_app')->statement('REFRESH MATERIALIZED VIEW coverage_matrix_cells');
    } catch (QueryException $e) {
        $erreur = $e->getMessage();
    }

    expect($erreur)->not->toBeNull();
    // Message PostgreSQL, en anglais : pas de piege d'accent ici.
    $this->assertStringContainsString('must be owner of materialized view', (string) $erreur);
});

test('coverage:refresh-matrix aboutit lorsqu il tourne sur le role applicatif', function () {
    // On depeuple la vue AVEC LE ROLE PROPRIETAIRE, pour que le vert qui suit ne
    // puisse pas venir d'un etat anterieur.
    DB::connection('pgsql_owner')->statement('REFRESH MATERIALIZED VIEW coverage_matrix_cells WITH NO DATA');

    $peupleeAvant = (bool) DB::scalar(
        "SELECT relispopulated FROM pg_class WHERE relname = 'coverage_matrix_cells'",
    );
    expect($peupleeAvant)->toBeFalse();

    // La commande tourne sur la connexion PAR DEFAUT : on bascule cette
    // connexion sur le role applicatif, exactement comme le fait la production
    // quand `CRM_DB_APP_ROLE_ENABLED` est a true.
    config(['database.default' => 'pgsql_app']);
    DB::purge('pgsql_app');

    try {
        $roleEffectif = (string) DB::scalar('SELECT current_user');
        expect($roleEffectif)->toBe((string) config('database.connections.pgsql_app.username'));

        $code = Artisan::call('coverage:refresh-matrix');
        $sortie = Artisan::output();
    } finally {
        config(['database.default' => 'pgsql']);
        DB::purge('pgsql_app');
    }

    expect($code)->toBe(0, "coverage:refresh-matrix a rendu {$code}. Sortie :\n" . ($sortie ?? ''));

    $peupleeApres = (bool) DB::scalar(
        "SELECT relispopulated FROM pg_class WHERE relname = 'coverage_matrix_cells'",
    );
    expect($peupleeApres)->toBeTrue();
});

test('un echec de rafraichissement N EST PLUS MUET : code de sortie non nul et journal critique', function () {
    // Ce que le constat A08-001 reproche vraiment, ce n'est pas seulement
    // l'echec : c'est qu'il ait pu durer 71 passages sans que personne le voie.
    // On rejoue donc l'echec, de la seule facon qui n'abime rien : on retire
    // EXECUTE au role applicatif, on lance la commande, on le lui rend.
    $role = (string) config('database.connections.pgsql_app.username');

    DB::connection('pgsql_owner')->statement(
        "REVOKE EXECUTE ON FUNCTION public.rafraichir_matrice_couverture(BOOLEAN) FROM {$role}",
    );

    // PUBLIC porte EXECUTE par defaut sur toute fonction : sans ce REVOKE-la,
    // le retrait ci-dessus serait sans effet et le test serait vert a vide.
    DB::connection('pgsql_owner')->statement(
        'REVOKE EXECUTE ON FUNCTION public.rafraichir_matrice_couverture(BOOLEAN) FROM PUBLIC',
    );

    Log::spy();

    config(['database.default' => 'pgsql_app']);
    DB::purge('pgsql_app');

    try {
        $code = Artisan::call('coverage:refresh-matrix');
    } finally {
        config(['database.default' => 'pgsql']);
        DB::purge('pgsql_app');
        DB::connection('pgsql_owner')->statement(
            "GRANT EXECUTE ON FUNCTION public.rafraichir_matrice_couverture(BOOLEAN) TO {$role}",
        );
    }

    expect($code)->not->toBe(0);

    Log::shouldHaveReceived('critical')->once()->withArgs(function (string $message): bool {
        // Sous-chaine SANS ACCENT : le message est francais, un accent mal
        // encode ferait echouer la comparaison pour une mauvaise raison.
        return str_contains($message, CoverageRefreshMatrix::PREFIXE_ALERTE);
    });
});
