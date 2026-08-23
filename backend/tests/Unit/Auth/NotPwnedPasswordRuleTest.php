<?php

use App\Rules\NotPwnedPassword;
use App\Services\Auth\HibpChecker;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

beforeEach(fn () => Cache::flush());

function makeHibpChecker(string $bodyResponse): HibpChecker
{
    $mock = new MockHandler([new Response(200, [], $bodyResponse)]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);

    return new HibpChecker($client);
}

test('NotPwnedPassword laisse passer si password non breached', function () {
    $checker = makeHibpChecker("DIFFERENTSUFFIX:1\r\n");
    $rule = new NotPwnedPassword(5, $checker);

    $failed = false;
    $rule->validate('password', 'SomeGoodPassword!42', function () use (&$failed) {
        $failed = true;
    });
    expect($failed)->toBeFalse();
});

test('NotPwnedPassword bloque si count > threshold', function () {
    // sha1 'password' suffix
    $checker = makeHibpChecker("1E4C9B93F3F0682250B6CF8331B7EE68FD8:100\r\n");
    $rule = new NotPwnedPassword(5, $checker);

    $message = null;
    $rule->validate('password', 'password', function (string $m) use (&$message) {
        $message = $m;
    });
    expect($message)->not->toBeNull();
    expect($message)->toContain('100');
});

test('NotPwnedPassword laisse passer si count = threshold', function () {
    $checker = makeHibpChecker("1E4C9B93F3F0682250B6CF8331B7EE68FD8:5\r\n");
    $rule = new NotPwnedPassword(5, $checker);

    $failed = false;
    $rule->validate('password', 'password', function () use (&$failed) {
        $failed = true;
    });
    expect($failed)->toBeFalse();
});

test('NotPwnedPassword threshold custom respecté', function () {
    $checker = makeHibpChecker("1E4C9B93F3F0682250B6CF8331B7EE68FD8:50\r\n");
    $rule = new NotPwnedPassword(100, $checker);  // threshold haut

    $failed = false;
    $rule->validate('password', 'password', function () use (&$failed) {
        $failed = true;
    });
    expect($failed)->toBeFalse();
});

test('NotPwnedPassword ignore les types non-string', function () {
    $rule = new NotPwnedPassword;
    $failed = false;
    $rule->validate('password', 12345, function () use (&$failed) {
        $failed = true;
    });
    expect($failed)->toBeFalse();
});

test('NotPwnedPassword ignore les strings vides', function () {
    $rule = new NotPwnedPassword;
    $failed = false;
    $rule->validate('password', '', function () use (&$failed) {
        $failed = true;
    });
    expect($failed)->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// P5-35-010 — la porte de sortie du fail-closed
// ─────────────────────────────────────────────────────────────────────────────
//
// Le constat, mesure le 2026-08-22 : F35-004 a ferme un fail-open SILENCIEUX,
// et c'etait juste. Mais il a laisse le produit dans un etat ou la seule voie
// de reprise en main d'un compte — `PasswordResetController`, unique point de
// choix d'un mot de passe (verifie : `Hash::make` n'apparait ailleurs que sur
// les codes de secours 2FA et le hache factice anti-enumeration) — depend d'un
// service TIERS, avec des delais courts (`HibpChecker` : 5 s / 3 s). Une panne
// DNS ou un pare-feu sortant suspendait donc toute reinitialisation, sans
// aucune porte de sortie : `grep -r 'HIBP_FAIL_MODE|fail_mode' backend/` ne
// rendait rien.
//
// Ce que ces gardes tiennent : le mode degrade existe, il est OPT-IN, il est
// BRUYANT, il ne s'ouvre que sur l'indisponibilite, et toute valeur inconnue
// du drapeau retombe sur le refus.
//
// ⚠️ CE QU'ELLES NE COUVRENT PAS : elles n'eprouvent pas le controleur de
// reinitialisation lui-meme (cf. `tests/Feature/Auth/`), ni le fait que
// quelqu'un repasse effectivement le drapeau a `closed` apres l'incident —
// c'est un geste d'exploitation, aucun test ne peut le garantir.

/** Un HibpChecker dont l'API est injoignable (le cas qui declenche le repli). */
function makeHibpCheckerInjoignable(): HibpChecker
{
    $requete = new Request('GET', HibpChecker::API_BASE_URL);
    $mock = new MockHandler([
        new ConnectException('cURL error 6', $requete),
        new ConnectException('cURL error 6', $requete),
    ]);

    return new HibpChecker(new Client(['handler' => HandlerStack::create($mock)]));
}

/**
 * Recolte les lignes de journal emises pendant $geste.
 *
 * On ecoute `MessageLogged` plutot que d'espionner la facade : on veut lire le
 * NIVEAU, le message ET le contexte pour pouvoir dire precisement ce qui
 * manque, ce qu'un `Log::shouldHaveReceived` ne permet pas.
 *
 * @return list<array{niveau: string, message: string, contexte: array<string, mixed>}>
 */
function journalPendant(Closure $geste): array
{
    $lignes = [];
    Event::listen(
        MessageLogged::class,
        function (MessageLogged $e) use (&$lignes) {
            $lignes[] = ['niveau' => $e->level, 'message' => $e->message, 'contexte' => $e->context];
        },
    );

    $geste();

    return $lignes;
}

test('P5-35-010 — sans drapeau, HIBP injoignable REFUSE toujours (defaut inchange)', function () {
    // Le defaut de `config/auth.php` est `closed`. Si ce test rougit, c'est que
    // la valeur par defaut a bascule : le produit accepterait des mots de passe
    // non verifies sans que personne ne l'ait decide.
    config()->offsetUnset('auth.hibp');

    $message = null;
    (new NotPwnedPassword(5, makeHibpCheckerInjoignable()))
        ->validate('password', 'password', function (string $m) use (&$message) {
            $message = $m;
        });

    expect($message !== null)->toBeTrue(
        'P5-35-010 : HIBP injoignable et le mot de passe passe alors que le mode '
        . "n'est pas configure. Geste : verifier que `NotPwnedPassword::failMode()` "
        . 'retombe sur `closed` quand `auth.hibp.fail_mode` est absent.',
    );
});

test('P5-35-010 — mode `open-audited` : le mot de passe passe ET la ligne d alerte part', function () {
    config(['auth.hibp.fail_mode' => NotPwnedPassword::FAIL_MODE_OPEN_AUDITED]);

    $echoue = false;
    $lignes = journalPendant(function () use (&$echoue) {
        (new NotPwnedPassword(5, makeHibpCheckerInjoignable()))
            ->validate('password', 'un-mot-de-passe-de-reprise-2026', function () use (&$echoue) {
                $echoue = true;
            });
    });

    expect($echoue)->toBeFalse(
        'P5-35-010 : le mode `open-audited` refuse quand meme — la porte de sortie '
        . "ne s'ouvre pas. Geste : dans `NotPwnedPassword::validate`, court-circuiter "
        . 'le `$fail` quand `failMode() === FAIL_MODE_OPEN_AUDITED`.',
    );

    $alertes = array_values(array_filter(
        $lignes,
        fn (array $l) => $l['message'] === 'auth.hibp.fail_open',
    ));

    expect(count($alertes))->toBe(
        1,
        'P5-35-010 : le contournement du controle HIBP est passe SANS ligne de '
        . "journal — c'est exactement le fail-open silencieux de F35-004, remis en "
        . "place par la porte de sortie. Geste : `Log::alert('auth.hibp.fail_open', "
        . '[...])` avant le `return` du mode degrade.',
    );
    expect($alertes[0]['niveau'])->toBe(
        'alert',
        'P5-35-010 : la ligne existe mais pas au niveau `alert`. Un contournement '
        . "de controle de securite doit sortir au-dessus du bruit d'exploitation. "
        . 'Geste : `Log::alert`, pas `Log::warning` ni `Log::info`.',
    );
});

test('P5-35-010 — la ligne d alerte ne fuite ni le mot de passe ni son empreinte', function () {
    config(['auth.hibp.fail_mode' => NotPwnedPassword::FAIL_MODE_OPEN_AUDITED]);
    $secret = 'un-mot-de-passe-de-reprise-2026';

    $lignes = journalPendant(function () use ($secret) {
        (new NotPwnedPassword(5, makeHibpCheckerInjoignable()))
            ->validate('password', $secret, fn () => null);
    });

    $tout = json_encode($lignes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

    expect(str_contains($tout, $secret))->toBeFalse(
        'P5-35-010 : le mot de passe EN CLAIR est parti dans les journaux. Geste : '
        . "ne journaliser que l'attribut et la raison, jamais la valeur.",
    );
    expect(str_contains($tout, strtoupper(sha1($secret))))->toBeFalse(
        'P5-35-010 : le SHA-1 du mot de passe est parti dans les journaux — un '
        . 'journal devient alors une liste de condensats a casser hors ligne. '
        . "Geste : retirer l'empreinte du contexte.",
    );
});

test('P5-35-010 — une valeur de drapeau inconnue retombe sur le REFUS', function () {
    // Le piege que ce test tient : « drapeau oublie a `open` ». Une liste noire
    // laisserait passer `open`, `OPEN-AUDITED`, `true`, `1`. Seule la chaine
    // exacte doit ouvrir.
    foreach (['open', 'OPEN-AUDITED', 'Open-Audited', 'true', '1', '', 'oui'] as $valeur) {
        Cache::flush();
        config(['auth.hibp.fail_mode' => $valeur]);

        $message = null;
        (new NotPwnedPassword(5, makeHibpCheckerInjoignable()))
            ->validate('password', 'password', function (string $m) use (&$message) {
                $message = $m;
            });

        expect($message !== null)->toBeTrue(
            "P5-35-010 : la valeur de drapeau « {$valeur} » a OUVERT le controle. "
            . "Un drapeau mal orthographie qui echoue vers l'ouverture reproduit "
            . 'F35-004. Geste : garder la liste blanche de '
            . '`NotPwnedPassword::failMode()` (comparaison stricte a `open-audited`).',
        );
    }
});

test('P5-35-010 — meme en `open-audited`, un mot de passe COMPROMIS reste refuse', function () {
    // La porte de sortie ne vaut que pour l'INDISPONIBILITE. Si elle laissait
    // aussi passer un mot de passe dont HIBP a repondu « vu 9 999 999 fois »,
    // ce ne serait plus un mode degrade, ce serait la desactivation du controle.
    config(['auth.hibp.fail_mode' => NotPwnedPassword::FAIL_MODE_OPEN_AUDITED]);

    $corps = substr(strtoupper(sha1('password')), 5) . ':9999999' . "\r\n";
    $message = null;
    (new NotPwnedPassword(5, makeHibpChecker($corps)))
        ->validate('password', 'password', function (string $m) use (&$message) {
            $message = $m;
        });

    expect($message !== null)->toBeTrue(
        'P5-35-010 : `open-audited` laisse passer un mot de passe dont HIBP a '
        . 'REPONDU qu il est compromis. Geste : le court-circuit ne doit vivre que '
        . 'dans la branche `$count === null`, jamais apres.',
    );
});
