<?php

/**
 * GARDE : LE FILET `statement_timeout` DES REQUÊTES WEB — constat prod du
 * 2026-10-02.
 *
 * `show statement_timeout` rendait `0` en production : la liste « Contacts »
 * tournait plus de 100 s, chaque visite en relançait une, les requêtes
 * s'empilaient sur 2 CPU. Le filet borne les requêtes SQL d'ÉCRAN, et RIEN
 * d'autre :
 *
 *   1. une requête HTTP de l'API tourne sous 15 s ;
 *   2. la limite ne survit pas à la requête, et une commande artisan
 *      (joignabilité, classement, imports) n'est JAMAIS bornée ;
 *   3. un dépassement rend un 503 en français, pas un 500 ;
 *   4. les exports en flux ont droit à 300 s.
 */

use App\Support\DelaiRequeteSql;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Une route de sonde DANS le groupe `api` : elle rend le délai en vigueur. */
function delaiSqlSonde(): void
{
    Route::middleware('api')->get('api/v1/__sonde-delai-sql', fn () => response()->json([
        'ms' => DelaiRequeteSql::courantMs(),
    ]));
}

/** Une commande de sonde : elle écrit le délai vu par une commande artisan. */
function delaiSqlCommandeSonde(): void
{
    $commande = new class extends Command
    {
        protected $signature = 'test:sonde-delai-sql';

        protected $description = 'Sonde de test : délai SQL vu par une commande.';

        public function handle(): int
        {
            $this->line('ms=' . DelaiRequeteSql::courantMs());

            return self::SUCCESS;
        }
    };

    app(ConsoleKernel::class)->registerCommand($commande);
}

test('TEMOIN — hors requete web, la connexion n est pas bornee (valeur du serveur)', function () {
    // Sans ce témoin, une configuration qui poserait la limite PARTOUT (option
    // de connexion, fournisseur de service) ferait passer la garde 1 au vert
    // pour une mauvaise raison — et briserait les commandes longues.
    expect(DelaiRequeteSql::courantMs())->toBe(0);
});

test('une requete HTTP de l API tourne sous un delai de 15 s', function () {
    delaiSqlSonde();

    $this->getJson('/api/v1/__sonde-delai-sql')
        ->assertOk()
        ->assertJsonPath('ms', 15000);
});

test('le delai ne survit pas a la requete, et une commande artisan n est JAMAIS bornee', function () {
    delaiSqlSonde();
    delaiSqlCommandeSonde();

    // L'ordre réel d'un processus qui sert puis exécute : requête web, PUIS
    // commande. Si la limite fuyait de l'une à l'autre, la joignabilité ou le
    // classement (des heures) seraient coupés au bout de 15 s.
    $this->getJson('/api/v1/__sonde-delai-sql')->assertJsonPath('ms', 15000);

    Artisan::call('test:sonde-delai-sql');

    expect(trim(Artisan::output()))->toBe('ms=0');
});

test('un depassement rend 503 avec un message francais, pas un 500', function () {
    config(['database.statement_timeout_web_ms' => 50]);

    Route::middleware('api')->get('api/v1/__sonde-delai-sql-lente', function () {
        // Dans un point de sauvegarde : l'annulation avorte la transaction
        // courante, et celle du test (RefreshDatabase) doit lui survivre.
        return DB::transaction(fn () => response()->json(DB::select('SELECT pg_sleep(1)')));
    });

    $this->getJson('/api/v1/__sonde-delai-sql-lente')
        ->assertStatus(503)
        ->assertJsonPath('error', 'requete_trop_longue')
        ->assertJsonPath('message', 'La recherche prend trop de temps, affinez les filtres.');
});

test('les exports en flux ont droit a 300 s', function (string $chemin) {
    $route = app('router')->getRoutes()->match(Request::create($chemin, 'GET'));

    expect($route->gatherMiddleware())->toContain('delai-sql:300');
})->with([
    '/api/v1/companies/export',
    '/api/v1/media/export',
    '/api/v1/journalists/export',
    '/api/v1/crm/personnes/export',
]);

test('etendu() elargit le delai le temps du calcul, puis le restaure', function () {
    DelaiRequeteSql::poser(15000);

    $pendant = DelaiRequeteSql::etendu(120, fn () => DelaiRequeteSql::courantMs());

    expect($pendant)->toBe(120000);
    expect(DelaiRequeteSql::courantMs())->toBe(15000);

    DB::statement('RESET statement_timeout');
});

test('etendu() ne pose AUCUNE limite sur une connexion qui n en a pas', function () {
    // Une commande artisan qui appelle un calcul « étendu » ne doit pas se
    // retrouver bornée à 120 s alors qu'elle ne l'était pas du tout.
    $pendant = DelaiRequeteSql::etendu(120, fn () => DelaiRequeteSql::courantMs());

    expect($pendant)->toBe(0);
});
