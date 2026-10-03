<?php

/**
 * LOT N11 — SOCLE DU FUTUR CANAL AXION PARTNERS, FERMÉ PAR DÉFAUT.
 *
 * Ce que ces tests prouvent :
 *   - mode `off` → 404 au corps vide, aucune ligne écrite ;
 *   - TOUS les refus d'authentification (kid inconnu, absent ou hors format,
 *     signature fausse ou absente, horodatage absent ou hors fenêtre, rejeu,
 *     clé d'essai en mode actif) rendent un 401 au corps IDENTIQUE À L'OCTET ;
 *   - refus de démarrage : mode inconnu, secret entrant manquant, trop court,
 *     au préfixe de développement, etc. ;
 *   - idempotence : même clé + même corps → réponse rejouée ; corps différent
 *     → 409 ; la table ne porte ni corps ni donnée personnelle ;
 *   - la table d'idempotence n'est lisible par aucune route.
 *
 * Secrets : valeurs de FIXTURE, sans aucune valeur réelle (dépôt public).
 */

use App\Http\Middleware\VerificateurCanalPartners;
use App\Providers\CanalPartnersServiceProvider;
use App\Support\Partners\ConfigurationCanalPartners;
use App\Support\Partners\IdempotencePartners;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// RefreshDatabase : la route écrit sa ligne d'idempotence, et chaque POST
// laisse sa ligne au journal d'audit (empreinte seule).
uses(TestCase::class, RefreshDatabase::class);

const N11_ROUTE = '/api/internal/partners/v1/ping';
const N11_KID_PROD = 'prod-2026-10';
const N11_KID_ESSAI = 'essai-1';
const N11_CORPS_401 = '{"erreur":"non_autorise"}';

function n11SecretProd(): string
{
    return 'fixture-partners-prod-' . str_repeat('9f3c', 8);
}

function n11SecretEssai(): string
{
    return 'fixture-partners-essai-' . str_repeat('7a1d', 8);
}

function n11Configurer(string $mode, ?string $entrants = null, string $kidEssai = N11_KID_ESSAI): void
{
    config([
        'crm.partners.mode' => $mode,
        'crm.partners.entrant_secrets' => $entrants ?? (N11_KID_PROD . ':' . n11SecretProd() . ',' . N11_KID_ESSAI . ':' . n11SecretEssai()),
        'crm.partners.sortant_secrets' => '',
        'crm.partners.api3_tokens' => '',
        'crm.partners.kid_essai' => $kidEssai,
        'crm.ingest.max_clock_skew_seconds' => 300,
        'crm.ingest.replay_store' => 'array',
    ]);
}

/** @return array<string, string> */
function n11Entetes(
    string $corps,
    ?string $kid = N11_KID_ESSAI,
    ?string $secret = null,
    ?string $horodatage = null,
    ?string $cle = 'cle-idem-0001',
): array {
    $horodatage ??= (string) time();
    $secret ??= $kid === N11_KID_PROD ? n11SecretProd() : n11SecretEssai();

    $entetes = [
        'HTTP_X_PARTNERS_TIMESTAMP' => $horodatage,
        'HTTP_X_PARTNERS_SIGNATURE' => hash_hmac('sha256', $horodatage . '.' . $corps, $secret),
    ];
    if ($kid !== null) {
        $entetes['HTTP_X_PARTNERS_KID'] = $kid;
    }
    if ($cle !== null) {
        $entetes['HTTP_IDEMPOTENCY_KEY'] = $cle;
    }

    return $entetes;
}

/** @param array<string, string> $entetes */
function n11Appel(string $corps, array $entetes, string $methode = 'POST'): TestResponse
{
    return test()->call($methode, N11_ROUTE, [], [], [], array_merge([
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $entetes), $corps);
}

function n11Lignes(): int
{
    return DB::table(IdempotencePartners::TABLE)->count();
}

function n11Demarrer(): void
{
    (new CanalPartnersServiceProvider(app()))->boot();
}

// ─── Mode off ────────────────────────────────────────────────────────────

test('mode off (défaut livré) : 404 au corps vide, rien n’est écrit', function () {
    n11Configurer('off');
    $corps = '{"essai":1}';

    $reponse = n11Appel($corps, n11Entetes($corps));

    expect($reponse->getStatusCode())->toBe(404)
        ->and($reponse->getContent())->toBe('')
        ->and(n11Lignes())->toBe(0);
});

test('la configuration livrée ferme le canal (mode off par défaut)', function () {
    $cle = 'CRM_PARTNERS_MODE';
    $avant = getenv($cle);
    putenv($cle);
    unset($_SERVER[$cle], $_ENV[$cle]);

    try {
        $livree = require config_path('crm.php');
        expect($livree['partners']['mode'])->toBe('off');
    } finally {
        if ($avant !== false) {
            putenv("{$cle}={$avant}");
        }
    }
});

// ─── Témoins ─────────────────────────────────────────────────────────────

test('TÉMOIN essai : une requête signée par la clé d’essai passe et n’écrit que sa ligne', function () {
    n11Configurer('essai');
    $corps = '{"essai":1}';

    $reponse = n11Appel($corps, n11Entetes($corps));

    $reponse->assertOk();
    expect($reponse->getContent())->toBe('{"ok":true,"mode":"essai"}')
        ->and(n11Lignes())->toBe(1);

    $ligne = (array) DB::table(IdempotencePartners::TABLE)->first();
    expect($ligne['cle_idempotence'])->toBe('cle-idem-0001')
        ->and($ligne['empreinte_corps'])->toBe(hash('sha256', $corps))
        ->and((int) $ligne['code_reponse'])->toBe(200);
});

test('TÉMOIN actif : la clé de production passe, la réponse annonce le mode', function () {
    n11Configurer('actif');
    $corps = '{"essai":2}';

    n11Appel($corps, n11Entetes($corps, N11_KID_PROD))
        ->assertOk()
        ->assertExactJson(['ok' => true, 'mode' => 'actif']);
});

// ─── 401 identiques à l'octet ────────────────────────────────────────────

test('chaque refus d’authentification rend le MÊME 401, à l’octet près', function () {
    n11Configurer('actif');
    $corps = '{"essai":3}';
    $maintenant = time();

    $cas = [
        'kid inconnu' => n11Entetes($corps, 'inconnu-1', n11SecretProd()),
        'kid hors format' => n11Entetes($corps, 'PROD_2026', n11SecretProd()),
        'kid absent' => n11Entetes($corps, null, n11SecretProd()),
        'signature fausse' => n11Entetes($corps, N11_KID_PROD, 'fixture-autre-secret-' . str_repeat('0b0b', 8)),
        'signature absente' => array_diff_key(n11Entetes($corps, N11_KID_PROD), ['HTTP_X_PARTNERS_SIGNATURE' => 1]),
        'horodatage absent' => array_diff_key(n11Entetes($corps, N11_KID_PROD), ['HTTP_X_PARTNERS_TIMESTAMP' => 1]),
        'horodatage périmé' => n11Entetes($corps, N11_KID_PROD, null, (string) ($maintenant - 301)),
        'horodatage en avance' => n11Entetes($corps, N11_KID_PROD, null, (string) ($maintenant + 301)),
        'horodatage non entier' => n11Entetes($corps, N11_KID_PROD, null, $maintenant . '.5'),
        'clé d’essai en mode actif' => n11Entetes($corps, N11_KID_ESSAI),
    ];

    foreach ($cas as $cause => $entetes) {
        $reponse = n11Appel($corps, $entetes);
        expect($reponse->getStatusCode())->toBe(401, "statut pour : {$cause}")
            ->and($reponse->getContent())->toBe(N11_CORPS_401, "corps pour : {$cause}");
    }

    expect(VerificateurCanalPartners::CORPS_REFUS)->toBe(N11_CORPS_401)
        ->and(n11Lignes())->toBe(0);
});

test('une requête identique REJOUÉE est refusée par le même 401', function () {
    n11Configurer('essai');
    $corps = '{"essai":4}';
    $entetes = n11Entetes($corps);

    n11Appel($corps, $entetes)->assertOk();
    $rejeu = n11Appel($corps, $entetes);

    expect($rejeu->getStatusCode())->toBe(401)
        ->and($rejeu->getContent())->toBe(N11_CORPS_401)
        ->and(n11Lignes())->toBe(1);
});

test('le rejeu est refusé même sous une autre clé d’idempotence', function () {
    n11Configurer('essai');
    $corps = '{"essai":5}';
    $entetes = n11Entetes($corps);

    n11Appel($corps, $entetes)->assertOk();
    $entetes['HTTP_IDEMPOTENCY_KEY'] = 'cle-idem-0002';

    expect(n11Appel($corps, $entetes)->getContent())->toBe(N11_CORPS_401)
        ->and(n11Lignes())->toBe(1);
});

// ─── Idempotence ─────────────────────────────────────────────────────────

test('même clé + même corps (re-signé) → réponse rejouée, sans nouvelle ligne', function () {
    n11Configurer('essai');
    $corps = '{"essai":6}';

    n11Appel($corps, n11Entetes($corps, horodatage: (string) (time() - 5)))->assertOk();
    $reprise = n11Appel($corps, n11Entetes($corps));

    $reprise->assertOk()->assertHeader('Idempotent-Replayed', 'true');
    expect($reprise->getContent())->toBe('{"ok":true,"mode":"essai"}')
        ->and(n11Lignes())->toBe(1);
});

test('même clé + corps différent → 409 cle_reutilisee', function () {
    n11Configurer('essai');

    n11Appel('{"essai":7}', n11Entetes('{"essai":7}'))->assertOk();
    $autre = n11Appel('{"essai":8}', n11Entetes('{"essai":8}'));

    expect($autre->getStatusCode())->toBe(409)
        ->and($autre->getContent())->toBe('{"erreur":"cle_reutilisee"}')
        ->and(n11Lignes())->toBe(1);
});

test('clé d’idempotence absente ou hors format → 400, rien n’est écrit', function (?string $cle) {
    n11Configurer('essai');
    $corps = '{"essai":9}';

    n11Appel($corps, n11Entetes($corps, cle: $cle))
        ->assertStatus(400)
        ->assertExactJson(['erreur' => 'cle_idempotence_invalide']);

    expect(n11Lignes())->toBe(0);
})->with([
    'absente' => [null],
    'trop courte' => ['court'],
    'caractères interdits' => ['cle idem/0001'],
]);

test('la table d’idempotence ne porte que clé, empreinte, date et code', function () {
    expect(Schema::getColumnListing(IdempotencePartners::TABLE))
        ->toEqualCanonicalizing(['id', 'cle_idempotence', 'empreinte_corps', 'code_reponse', 'recu_le']);
});

// ─── Refus de démarrage ──────────────────────────────────────────────────

test('démarrage REFUSÉ pour une configuration fausse', function (string $mode, ?string $entrants, string $kidEssai) {
    n11Configurer($mode, $entrants, $kidEssai);

    expect(fn () => n11Demarrer())->toThrow(RuntimeException::class, 'canal Partners');
})->with([
    'mode inconnu' => ['on', null, N11_KID_ESSAI],
    'mode vide' => ['', null, N11_KID_ESSAI],
    'mode en capitales' => ['ACTIF', null, N11_KID_ESSAI],
    'secret entrant manquant (essai)' => ['essai', '', ''],
    'secret entrant manquant (actif)' => ['actif', '', ''],
    'secret trop court' => ['actif', 'prod-1:' . str_repeat('a', 31), ''],
    'préfixe dev' => ['actif', 'prod-1:dev-' . str_repeat('a', 40), ''],
    'préfixe test' => ['actif', 'prod-1:test' . str_repeat('a', 40), ''],
    'préfixe changeme' => ['actif', 'prod-1:CHANGEME' . str_repeat('a', 40), ''],
    'kid hors format' => ['actif', 'Prod_1:' . str_repeat('b7', 20), ''],
    'kid trop long' => ['actif', str_repeat('k', 33) . ':' . str_repeat('b7', 20), ''],
    'sans séparateur' => ['actif', str_repeat('b7', 20), ''],
    'kid en double' => ['actif', 'prod-1:' . str_repeat('b7', 20) . ',prod-1:' . str_repeat('c8', 20), ''],
    'trois clés' => ['actif', 'a:' . str_repeat('b7', 20) . ',b:' . str_repeat('c8', 20) . ',c:' . str_repeat('d9', 20), ''],
    'kid d’essai inconnu' => ['essai', 'prod-1:' . str_repeat('b7', 20), 'essai-9'],
    'actif avec la seule clé d’essai' => ['actif', 'essai-1:' . str_repeat('b7', 20), 'essai-1'],
]);

test('un secret sortant mal posé fait aussi refuser le démarrage', function () {
    n11Configurer('essai');
    config(['crm.partners.sortant_secrets' => 'crm-1:changeme']);

    expect(fn () => n11Demarrer())->toThrow(RuntimeException::class, 'CRM_PARTNERS_SORTANT_SECRETS');
});

test('le message de refus ne recopie jamais le secret', function () {
    $secret = 'dev-' . str_repeat('5e', 30);
    n11Configurer('actif', 'prod-1:' . $secret, '');

    try {
        n11Demarrer();
        $this->fail('Le démarrage aurait dû être refusé.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain($secret)
            ->and($e->getMessage())->toContain('CRM_PARTNERS_ENTRANT_SECRETS');
    }
});

test('TÉMOIN : off sans aucun secret démarre ; essai et actif bien configurés démarrent', function () {
    n11Configurer('off', '', '');
    n11Demarrer();

    n11Configurer('essai');
    n11Demarrer();

    n11Configurer('actif');
    n11Demarrer();

    expect(ConfigurationCanalPartners::depuisConfig()->mode)->toBe('actif');
});

test('le contrôle de démarrage est enregistré parmi les fournisseurs', function () {
    expect(require base_path('bootstrap/providers.php'))->toContain(CanalPartnersServiceProvider::class);
});

// ─── Aucune lecture par une route ────────────────────────────────────────

test('une seule route sous partners : le ping, en POST, protégé par le vérificateur', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), 'partners'))
        ->values();

    expect($routes)->toHaveCount(1);
    $route = $routes->first();
    expect($route->uri())->toBe('api/internal/partners/v1/ping')
        ->and($route->methods())->toBe(['POST'])
        ->and($route->gatherMiddleware())->toContain(VerificateurCanalPartners::class);
});

test('la table d’idempotence n’est lue par aucun contrôleur ni aucune route', function () {
    $fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
    $lecteurs = [];
    foreach ($fichiers as $fichier) {
        if ($fichier->isFile() && $fichier->getExtension() === 'php'
            && str_contains((string) file_get_contents($fichier->getPathname()), 'partners_evenements_recus')) {
            $lecteurs[] = str_replace('\\', '/', substr($fichier->getPathname(), strlen(app_path()) + 1));
        }
    }

    expect($lecteurs)->toBe(['Support/Partners/IdempotencePartners.php'])
        ->and((string) file_get_contents(base_path('routes/api.php')))->not->toContain('partners_evenements_recus');
});

test('en lecture (GET) le ping ne rend rien de la table', function () {
    n11Configurer('essai');
    $corps = '{"essai":10}';
    n11Appel($corps, n11Entetes($corps))->assertOk();

    $lecture = n11Appel('', [], 'GET');

    expect($lecture->getStatusCode())->toBe(405)
        ->and((string) $lecture->getContent())->not->toContain('cle-idem-0001')
        ->and((string) $lecture->getContent())->not->toContain(hash('sha256', $corps));
});

test('le rôle applicatif lit et insère, mais ne modifie ni ne supprime une ligne', function () {
    $role = (string) config('database.connections.pgsql_app.username');
    if ($role === '' || DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$role]) === null) {
        $this->markTestSkipped("Rôle applicatif {$role} absent de cette base.");
    }

    $droit = fn (string $privilege): bool => (bool) DB::selectOne(
        'SELECT has_table_privilege(?, ?, ?) AS a',
        [$role, 'public.' . IdempotencePartners::TABLE, $privilege],
    )->a;

    expect($droit('SELECT'))->toBeTrue()
        ->and($droit('INSERT'))->toBeTrue()
        ->and($droit('UPDATE'))->toBeFalse()
        ->and($droit('DELETE'))->toBeFalse();
});
