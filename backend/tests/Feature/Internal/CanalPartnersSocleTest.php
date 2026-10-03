<?php

/**
 * LOT N11 — SOCLE DU FUTUR CANAL AXION PARTNERS, FERMÉ PAR DÉFAUT.
 *
 * Ce que ces tests prouvent :
 *   - mode `off` → 404 indiscernable d'une route absente (même corps, aucun
 *     en-tête `X-RateLimit-*`, jamais 429), aucune ligne d'idempotence ;
 *   - TOUS les refus d'authentification (kid inconnu, absent ou hors format,
 *     signature fausse ou absente, horodatage absent ou hors fenêtre, clé
 *     d'idempotence absente, hors format ou réécrite, rejeu, clé d'essai en
 *     mode actif) rendent un 401 au corps IDENTIQUE À L'OCTET ;
 *   - la clé d'idempotence est couverte par la signature ;
 *   - refus de démarrage : mode inconnu, secret entrant manquant, trop court,
 *     trop pauvre, au préfixe de développement, etc. ;
 *   - idempotence par (route, clé) : reprise rejouée À L'IDENTIQUE (mode
 *     d'origine), corps différent → 409 ; la table ne porte ni corps ni donnée
 *     personnelle ; code borné 100-599 ;
 *   - la table n'est lisible par aucune route, et le rôle applicatif ne peut
 *     ni la modifier ni la vider (test jamais sauté en CI).
 *
 * Secrets : valeurs de FIXTURE, sans aucune valeur réelle (dépôt public).
 */

use App\Http\Middleware\VerificateurCanalPartners;
use App\Providers\CanalPartnersServiceProvider;
use App\Support\Partners\ConfigurationCanalPartners;
use App\Support\Partners\IdempotencePartners;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// RefreshDatabase : la route écrit sa ligne d'idempotence, et chaque POST
// laisse sa ligne générique au journal d'audit chaîné (empreinte seule).
uses(TestCase::class, RefreshDatabase::class);

const N11_ROUTE = '/api/internal/partners/v1/ping';
const N11_KID_PROD = 'prod-2026-10';
const N11_KID_ESSAI = 'essai-1';
const N11_CORPS_401 = '{"erreur":"non_autorise"}';
const N11_CLE = '0b8e3c1a-5f2d-4c7e-9a10-3d4e5f6a7b8c:declaration:1';

function n11SecretProd(): string
{
    return 'fixture-partners-prod-' . str_repeat('9f3c', 8);
}

function n11SecretEssai(): string
{
    return 'fixture-partners-essai-' . str_repeat('7a1d', 8);
}

/** Secret de fixture assez long et assez riche (64 hex, ≥ 15 caractères distincts). */
function n11Fort(string $graine): string
{
    return hash('sha256', 'n11-' . $graine);
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

/**
 * En-têtes signés comme Partners les produira :
 * HMAC-SHA256(secret, "<horodatage>.<Idempotency-Key>.<corps>").
 *
 * @return array<string, string>
 */
function n11Entetes(
    string $corps,
    ?string $kid = N11_KID_ESSAI,
    ?string $secret = null,
    ?string $horodatage = null,
    ?string $cle = N11_CLE,
    ?string $cleSignee = null,
): array {
    $horodatage ??= (string) time();
    $secret ??= $kid === N11_KID_PROD ? n11SecretProd() : n11SecretEssai();
    $cleSignee ??= (string) $cle;

    $entetes = [
        'HTTP_X_PARTNERS_TIMESTAMP' => $horodatage,
        'HTTP_X_PARTNERS_SIGNATURE' => hash_hmac('sha256', $horodatage . '.' . $cleSignee . '.' . $corps, $secret),
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
function n11Appel(string $corps, array $entetes, string $methode = 'POST', string $route = N11_ROUTE): TestResponse
{
    return test()->call($methode, $route, [], [], [], array_merge([
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

/** Chemin tel qu'il apparaît dans un corps JSON (barres obliques échappées ou non). */
function n11CheminJson(string $route): string
{
    return substr((string) json_encode(ltrim($route, '/')), 1, -1);
}

/** @return list<string> noms des en-têtes de limitation présents */
function n11EntetesLimiteur(TestResponse $reponse): array
{
    return array_values(array_filter(
        array_keys($reponse->headers->all()),
        fn (string $nom): bool => str_starts_with(strtolower($nom), 'x-ratelimit') || strtolower($nom) === 'retry-after',
    ));
}

// ─── Mode off ────────────────────────────────────────────────────────────

test('mode off : 404 INDISCERNABLE d’une route absente, sans limiteur, sans ligne d’idempotence', function () {
    n11Configurer('off');
    config(['app.debug' => false]);
    $corps = '{"essai":1}';
    $absente = '/api/internal/partners/v1/route-absente';

    $temoin = n11Appel($corps, n11Entetes($corps), 'POST', $absente);
    $reponse = n11Appel($corps, n11Entetes($corps));

    expect($temoin->getStatusCode())->toBe(404)
        ->and($reponse->getStatusCode())->toBe(404)
        // Même corps au chemin près (le message du routeur cite le chemin).
        ->and($reponse->getContent())->toBe(str_replace(
            n11CheminJson($absente),
            n11CheminJson(N11_ROUTE),
            (string) $temoin->getContent(),
        ))
        ->and((string) $temoin->getContent())->toContain(n11CheminJson($absente))
        ->and($reponse->headers->get('Content-Type'))->toBe($temoin->headers->get('Content-Type'))
        ->and(n11EntetesLimiteur($reponse))->toBe([])
        ->and(n11EntetesLimiteur($temoin))->toBe([])
        // Le journal d'audit chaîné écrit sa ligne générique (POST d'une route
        // déclarée) ; la table d'idempotence, elle, reste vide.
        ->and(n11Lignes())->toBe(0);
});

test('mode off : jamais de 429, même au-delà du plafond des canaux ouverts', function () {
    n11Configurer('off');
    for ($i = 0; $i < 605; $i++) {
        $reponse = test()->call('POST', N11_ROUTE, [], [], [], ['HTTP_ACCEPT' => 'application/json'], '');
        if ($reponse->getStatusCode() !== 404) {
            $this->fail("Appel n°{$i} en mode off : statut {$reponse->getStatusCode()} au lieu de 404.");
        }
    }

    expect(true)->toBeTrue();
});

test('TÉMOIN : canal ouvert, le limiteur s’applique bien (en-têtes X-RateLimit-*)', function () {
    n11Configurer('essai');
    $corps = '{"essai":"limiteur"}';

    $reponse = n11Appel($corps, n11Entetes($corps));

    $reponse->assertOk();
    expect(n11EntetesLimiteur($reponse))->not->toBe([]);
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
    expect($ligne['route'])->toBe('ping')
        ->and($ligne['cle_idempotence'])->toBe(N11_CLE)
        ->and($ligne['empreinte_corps'])->toBe(hash('sha256', $corps))
        ->and((int) $ligne['code_reponse'])->toBe(200)
        ->and($ligne['resume_reponse'])->toBe('essai');
});

test('TÉMOIN actif : la clé de production passe, la réponse annonce le mode', function () {
    n11Configurer('actif');
    $corps = '{"essai":2}';

    n11Appel($corps, n11Entetes($corps, N11_KID_PROD))
        ->assertOk()
        ->assertExactJson(['ok' => true, 'mode' => 'actif']);
});

test('espaces autour du kid et du secret : rognés, la clé fonctionne', function () {
    n11Configurer('actif', ' ' . N11_KID_PROD . ' : ' . n11SecretProd() . ' ', '');
    n11Demarrer();
    $corps = '{"essai":"espaces"}';

    n11Appel($corps, n11Entetes($corps, N11_KID_PROD))->assertOk();
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
        'signature fausse' => n11Entetes($corps, N11_KID_PROD, n11Fort('autre')),
        'signature absente' => array_diff_key(n11Entetes($corps, N11_KID_PROD), ['HTTP_X_PARTNERS_SIGNATURE' => 1]),
        'signature sans la clé d’idempotence' => array_merge(n11Entetes($corps, N11_KID_PROD, null, (string) $maintenant), [
            'HTTP_X_PARTNERS_SIGNATURE' => hash_hmac('sha256', $maintenant . '.' . $corps, n11SecretProd()),
        ]),
        'horodatage absent' => array_diff_key(n11Entetes($corps, N11_KID_PROD), ['HTTP_X_PARTNERS_TIMESTAMP' => 1]),
        'horodatage périmé' => n11Entetes($corps, N11_KID_PROD, null, (string) ($maintenant - 301)),
        'horodatage en avance' => n11Entetes($corps, N11_KID_PROD, null, (string) ($maintenant + 301)),
        'horodatage non entier' => n11Entetes($corps, N11_KID_PROD, null, $maintenant . '.5'),
        'clé d’idempotence absente' => n11Entetes($corps, N11_KID_PROD, cle: null, cleSignee: ''),
        'clé d’idempotence hors format (point)' => n11Entetes($corps, N11_KID_PROD, cle: 'cle.avec.point'),
        'clé d’idempotence trop courte' => n11Entetes($corps, N11_KID_PROD, cle: 'court'),
        'clé d’idempotence réécrite' => n11Entetes($corps, N11_KID_PROD, cle: 'cle-reecrite-0002', cleSignee: N11_CLE),
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

test('une requête interceptée ne peut pas être présentée sous une autre clé', function () {
    n11Configurer('essai');
    $corps = '{"essai":5}';
    $entetes = n11Entetes($corps);
    $detournee = array_merge($entetes, ['HTTP_IDEMPOTENCY_KEY' => 'cle-detournee-0003']);

    // Présentée en premier sous une autre clé : signature fausse → 401, et la
    // vraie requête passe ensuite normalement.
    expect(n11Appel($corps, $detournee)->getContent())->toBe(N11_CORPS_401);
    n11Appel($corps, $entetes)->assertOk();
    expect(n11Lignes())->toBe(1);
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

test('la réponse rejouée annonce le mode D’ORIGINE, pas le mode courant', function () {
    n11Configurer('essai');
    $corps = '{"essai":"bascule"}';
    n11Appel($corps, n11Entetes($corps, N11_KID_PROD, horodatage: (string) (time() - 5)))
        ->assertExactJson(['ok' => true, 'mode' => 'essai']);

    n11Configurer('actif');
    $reprise = n11Appel($corps, n11Entetes($corps, N11_KID_PROD));

    $reprise->assertOk()->assertHeader('Idempotent-Replayed', 'true');
    expect($reprise->getContent())->toBe('{"ok":true,"mode":"essai"}');
});

test('même clé + corps différent → 409 cle_reutilisee', function () {
    n11Configurer('essai');

    n11Appel('{"essai":7}', n11Entetes('{"essai":7}'))->assertOk();
    $autre = n11Appel('{"essai":8}', n11Entetes('{"essai":8}'));

    expect($autre->getStatusCode())->toBe(409)
        ->and($autre->getContent())->toBe('{"erreur":"cle_reutilisee"}')
        ->and(n11Lignes())->toBe(1);
});

test('unicité sur (route, clé) : même clé sur une autre route admise, sur la même route refusée', function () {
    $ligne = fn (string $route): array => [
        'route' => $route,
        'cle_idempotence' => N11_CLE,
        'empreinte_corps' => hash('sha256', '{}'),
        'code_reponse' => 200,
        'resume_reponse' => null,
    ];

    DB::table(IdempotencePartners::TABLE)->insert($ligne('ping'));
    DB::table(IdempotencePartners::TABLE)->insert($ligne('autre-route'));
    expect(n11Lignes())->toBe(2);

    expect(fn () => DB::transaction(fn () => DB::table(IdempotencePartners::TABLE)->insert($ligne('ping'))))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('code de réponse borné à 100-599', function (int $code) {
    expect(fn () => DB::transaction(fn () => DB::table(IdempotencePartners::TABLE)->insert([
        'route' => 'ping',
        'cle_idempotence' => 'cle-code-' . $code . '-0001',
        'empreinte_corps' => hash('sha256', '{}'),
        'code_reponse' => $code,
    ])))->toThrow(QueryException::class, 'partners_idempotence_code_reponse_http');
})->with([99, 600, 0, -1]);

test('la table d’idempotence ne porte que route, clé, empreinte, code, résumé et date', function () {
    expect(Schema::getColumnListing(IdempotencePartners::TABLE))
        ->toEqualCanonicalizing(['id', 'route', 'cle_idempotence', 'empreinte_corps', 'code_reponse', 'resume_reponse', 'recu_le']);
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
    'secret trop court' => ['actif', 'prod-1:' . substr(n11Fort('a'), 0, 31), ''],
    'secret trop pauvre (aaaa…)' => ['actif', 'prod-1:' . str_repeat('a', 64), ''],
    'secret trop pauvre (0123… x)' => ['actif', 'prod-1:' . str_repeat('0123456789', 6), ''],
    'préfixe dev' => ['actif', 'prod-1:dev-' . n11Fort('a'), ''],
    'préfixe test' => ['actif', 'prod-1:test' . n11Fort('a'), ''],
    'préfixe changeme' => ['actif', 'prod-1:CHANGEME' . n11Fort('a'), ''],
    'préfixe dev après espaces' => ['actif', 'prod-1:   dev-' . n11Fort('a'), ''],
    'kid hors format' => ['actif', 'Prod_1:' . n11Fort('a'), ''],
    'kid trop long' => ['actif', str_repeat('k', 33) . ':' . n11Fort('a'), ''],
    'sans séparateur' => ['actif', n11Fort('a'), ''],
    'kid en double' => ['actif', 'prod-1:' . n11Fort('a') . ',prod-1:' . n11Fort('b'), ''],
    'kid en double (espaces)' => ['actif', 'prod-1:' . n11Fort('a') . ', prod-1 :' . n11Fort('b'), ''],
    'trois clés' => ['actif', 'a:' . n11Fort('a') . ',b:' . n11Fort('b') . ',c:' . n11Fort('c'), ''],
    'kid d’essai inconnu' => ['essai', 'prod-1:' . n11Fort('a'), 'essai-9'],
    'actif avec la seule clé d’essai' => ['actif', 'essai-1:' . n11Fort('a'), 'essai-1'],
]);

test('un secret sortant mal posé fait aussi refuser le démarrage', function () {
    n11Configurer('essai');
    config(['crm.partners.sortant_secrets' => 'crm-1:changeme']);

    expect(fn () => n11Demarrer())->toThrow(RuntimeException::class, 'CRM_PARTNERS_SORTANT_SECRETS');
});

test('le message de refus ne recopie jamais le secret', function () {
    $secret = 'dev-' . n11Fort('message');
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

test('une seule route sous partners : le ping, en POST, limiteur dédié puis vérificateur', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), 'partners'))
        ->values();

    expect($routes)->toHaveCount(1);
    $route = $routes->first();
    expect($route->uri())->toBe('api/internal/partners/v1/ping')
        ->and($route->methods())->toBe(['POST'])
        ->and($route->gatherMiddleware())->toContain(VerificateurCanalPartners::class)
        ->and($route->gatherMiddleware())->toContain('throttle:partners')
        ->and($route->gatherMiddleware())->not->toContain('throttle:internal');
});

test('la table d’idempotence n’est lue par aucun contrôleur ni aucune route', function () {
    $fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
    $lecteurs = [];
    foreach ($fichiers as $fichier) {
        if ($fichier->isFile() && $fichier->getExtension() === 'php'
            && str_contains((string) file_get_contents($fichier->getPathname()), 'partners_idempotence')) {
            $lecteurs[] = str_replace('\\', '/', substr($fichier->getPathname(), strlen(app_path()) + 1));
        }
    }

    expect($lecteurs)->toBe(['Support/Partners/IdempotencePartners.php'])
        ->and((string) file_get_contents(base_path('routes/api.php')))->not->toContain('partners_idempotence');
});

test('en lecture (GET) le ping ne rend rien de la table', function () {
    n11Configurer('essai');
    $corps = '{"essai":10}';
    n11Appel($corps, n11Entetes($corps))->assertOk();

    $lecture = n11Appel('', [], 'GET');

    expect($lecture->getStatusCode())->toBe(405)
        ->and((string) $lecture->getContent())->not->toContain(N11_CLE)
        ->and((string) $lecture->getContent())->not->toContain(hash('sha256', $corps));
});

test('le rôle applicatif lit et insère, mais ne modifie, ne supprime ni ne vide aucune ligne', function () {
    $role = (string) config('database.connections.pgsql_app.username');
    if ($role === '' || DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$role]) === null) {
        // En CI le rôle existe TOUJOURS : son absence y est une panne de la
        // garde, pas un cas à sauter (une garde sautée ne garde rien).
        if (getenv('CI') !== false && getenv('CI') !== '') {
            $this->fail("Rôle applicatif « {$role} » absent en CI : la garde des droits ne mesure plus rien.");
        }
        $this->markTestSkipped("Rôle applicatif « {$role} » absent de cette base locale.");
    }

    $droit = fn (string $privilege): bool => (bool) DB::selectOne(
        'SELECT has_table_privilege(?, ?, ?) AS a',
        [$role, 'public.' . IdempotencePartners::TABLE, $privilege],
    )->a;

    expect($droit('SELECT'))->toBeTrue('SELECT retiré : le rejeu échouerait')
        ->and($droit('INSERT'))->toBeTrue('INSERT retiré : aucune clé ne serait enregistrée')
        ->and($droit('UPDATE'))->toBeFalse('UPDATE rétabli — un GRANT ON ALL TABLES a-t-il été relancé ?')
        ->and($droit('DELETE'))->toBeFalse('DELETE rétabli — un GRANT ON ALL TABLES a-t-il été relancé ?')
        ->and($droit('TRUNCATE'))->toBeFalse('TRUNCATE rétabli');
});
