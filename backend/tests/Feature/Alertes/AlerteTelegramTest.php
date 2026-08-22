<?php

/**
 * GARDE — LE CANAL D'ALERTE EXISTE-T-IL VRAIMENT, OU EST-CE ENCORE UN
 * COMMENTAIRE ?
 *
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Le défaut réparé ici n'était pas une panne : c'était une PROMESSE. Trois
 * endroits du dépôt annonçaient une alerte Telegram, et aucun ne l'envoyait —
 * `// Sprint 11 : send TelegramAlert::dispatch(...)`. Le 2026-08-21, un
 * déploiement a détecté un 502 en 21 secondes, a échoué, et personne ne l'a lu
 * pendant treize minutes.
 *
 * Ces gardes défendent donc, dans l'ordre :
 *
 *   1. que l'envoi PARTE vraiment, à la bonne adresse et au bon canal ;
 *   2. que l'absence de configuration soit AUDIBLE, jamais silencieuse ;
 *   3. qu'une panne du canal ne fasse JAMAIS tomber la commande qui l'utilise ;
 *   4. que le journal garde la trace même quand Telegram marche ;
 *   5. que le JETON ne fuie NULLE PART dans les journaux ;
 *   6. qu'un message trop long soit tronqué plutôt que refusé en entier ;
 *   7. que `AnomalyDetect` s'en serve réellement.
 */

use App\Services\Alertes\AlerteTelegram;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

const TG_JETON = '123456789:CE-JETON-NE-DOIT-JAMAIS-APPARAITRE-DANS-UN-JOURNAL';
const TG_CANAL = '-1001234567890';

beforeEach(function () {
    // ⚠️ Sans cette remise à zéro, l'avertissement « non configuré » ne serait
    // émis que pour le PREMIER test du processus, et les suivants passeraient
    // au vert sans rien mesurer. Le verdict dépendrait de l'ordre de tirage —
    // exactement le défaut mesuré ailleurs dans cette campagne.
    AlerteTelegram::oublierLAvertissement();

    config([
        'alertes.telegram.token' => TG_JETON,
        'alertes.telegram.chat_id' => TG_CANAL,
        'alertes.telegram.actif' => true,
        'alertes.telegram.delai_max' => 5,
    ]);
});

// ── 1. L'ENVOI PART-IL, ET OÙ ? ─────────────────────────────────────────────

test('ALERTE — le message part a l API Telegram, sur le bon canal', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

    $parti = app(AlerteTelegram::class)->envoyer('Titre', 'Le corps du message.');

    expect($parti)->toBeTrue();

    Http::assertSent(function ($requete): bool {
        return str_contains($requete->url(), 'api.telegram.org/bot' . TG_JETON . '/sendMessage')
            && $requete['chat_id'] === TG_CANAL
            && str_contains((string) $requete['text'], 'Titre')
            && str_contains((string) $requete['text'], 'Le corps du message.');
    });
});

test('ALERTE — AUCUN `parse_mode` : une mise en forme fautive ferait rejeter tout le message', function () {
    // 🔑 Ce n'est pas un detail. En Markdown ou HTML, un `_` dans un nom de
    // variable ou un `<` dans une trace suffit a faire refuser l'envoi ENTIER
    // par Telegram. Une alerte perdue a cause de sa mise en forme est perdue au
    // pire moment : celui ou elle avait quelque chose a dire.
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

    app(AlerteTelegram::class)->envoyer('Titre', 'Un _souligne_ et un <chevron> et un *asterisque*.');

    Http::assertSent(fn ($requete): bool => ! isset($requete['parse_mode']));
});

// ── 2. NON CONFIGURÉ : LE SILENCE EST INTERDIT ──────────────────────────────

test('ALERTE — sans jeton, le canal le DIT, et nomme le geste', function () {
    config(['alertes.telegram.token' => '']);
    Http::fake();
    Log::spy();

    $parti = app(AlerteTelegram::class)->envoyer('Titre', 'Corps');

    expect($parti)->toBeFalse();
    Http::assertNothingSent();

    // 🔑 LE CŒUR DE CETTE GARDE. Se taire ici, ce serait reconstruire le defaut
    // repare : un canal d'alerte silencieux est indiscernable d'un canal qui
    // marche. Et l'avertissement doit dire QUOI FAIRE, pas seulement constater.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $m): bool => str_contains($m, 'TELEGRAM_BOT_TOKEN')
            && str_contains($m, 'TELEGRAM_CHAT_ID')
            && str_contains($m, 'force-recreate'))
        ->once();
});

test('ALERTE — sans canal non plus : les deux variables sont exigees', function () {
    config(['alertes.telegram.chat_id' => '']);
    Http::fake();

    expect(app(AlerteTelegram::class)->envoyer('Titre', 'Corps'))->toBeFalse();
    Http::assertNothingSent();
});

test('ALERTE — l avertissement ne se REPETE pas, il noierait son propre signal', function () {
    config(['alertes.telegram.token' => '']);
    Http::fake();
    Log::spy();

    $canal = app(AlerteTelegram::class);
    $canal->envoyer('Un', 'a');
    $canal->envoyer('Deux', 'b');
    $canal->envoyer('Trois', 'c');

    // Une commande qui emet trente alertes ecrirait trente avertissements
    // identiques : le signal disparaitrait sous sa propre repetition.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $m): bool => str_contains($m, 'NON CONFIGURE'))
        ->once();
});

// ── 3. UNE PANNE DU CANAL NE DOIT RIEN CASSER ───────────────────────────────

test('ALERTE — un refus de l API ne fait pas tomber l appelant', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'chat not found'], 400)]);

    // Une commande nocturne ne doit PAS echouer parce que Telegram refuse. Elle
    // apprend que l alerte n est pas partie, et continue son travail.
    $parti = app(AlerteTelegram::class)->envoyer('Titre', 'Corps');

    expect($parti)->toBeFalse();
});

test('ALERTE — un reseau injoignable ne fait pas tomber l appelant non plus', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timeout'));

    expect(app(AlerteTelegram::class)->envoyer('Titre', 'Corps'))->toBeFalse();
});

// ── 4. LE JOURNAL GARDE TOUJOURS LA TRACE ───────────────────────────────────

test('ALERTE — le journal ecrit MEME quand Telegram accepte', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    Log::spy();

    app(AlerteTelegram::class)->envoyer('Titre qui compte', 'Corps', ['espace' => 'ws-1']);

    // Le journal est le canal FIABLE et collecte ; Telegram est le canal LU. Si
    // Telegram tombe demain, la trace d aujourd hui doit rester. C est la lecon
    // de `AuditVerifyChain`, dont la sortie console partait dans le vide.
    Log::shouldHaveReceived('critical')
        ->withArgs(fn (string $m): bool => str_contains($m, 'Titre qui compte'))
        ->once();
});

// ── 5. LE JETON NE FUIT NULLE PART ──────────────────────────────────────────

test('ALERTE — le JETON n apparait dans AUCUN journal, meme en cas d echec', function () {
    // 🔴 L'URL de l'API Telegram CONTIENT le jeton. Une exception non filtree,
    // un `$reponse->effectiveUri()` journalise, un `report($e)` — et le secret
    // part dans les journaux, qui sont collectes et conserves.
    $vus = [];
    Log::shouldReceive('critical')->andReturnUsing(function ($m, $c = []) use (&$vus): void {
        $vus[] = $m . json_encode($c);
    });
    Log::shouldReceive('error')->andReturnUsing(function ($m, $c = []) use (&$vus): void {
        $vus[] = $m . json_encode($c);
    });
    Log::shouldReceive('warning')->andReturnUsing(function ($m, $c = []) use (&$vus): void {
        $vus[] = $m . json_encode($c);
    });

    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);
    app(AlerteTelegram::class)->envoyer('Titre', 'Corps');

    Http::fake(fn () => throw new ConnectionException(
        'cURL error 7: Failed to connect to api.telegram.org/bot' . TG_JETON . '/sendMessage',
    ));
    app(AlerteTelegram::class)->envoyer('Titre', 'Corps');

    $tout = implode(' | ', $vus);

    expect(str_contains($tout, TG_JETON))->toBeFalse(
        "Le JETON du bot a fuite dans les journaux. L URL de l API le contient, et le message\n"
        . "d une exception reseau la cite. Filtrer, ou ne journaliser que le code HTTP.\n"
        . 'Journaux vus : ' . mb_substr($tout, 0, 400),
    );

    // Témoin : on a bien journalisé quelque chose — sans quoi l'absence du
    // jeton ne prouverait rien.
    expect($vus)->not->toBeEmpty('Aucun journal ecrit : le controle ci-dessus ne prouve rien.');
});

// ── 6. LA LIMITE DE TELEGRAM ────────────────────────────────────────────────

test('ALERTE — un message trop long est TRONQUE, pas perdu', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

    // Au-dela de 4 096 caracteres, l API refuse le message ENTIER. Une alerte
    // tronquee vaut infiniment mieux qu une alerte perdue — et la troncature
    // doit se VOIR, sinon le lecteur croit avoir tout lu.
    app(AlerteTelegram::class)->envoyer('Titre', str_repeat('x', 9000));

    Http::assertSent(function ($requete): bool {
        $texte = (string) $requete['text'];

        return mb_strlen($texte) <= AlerteTelegram::LIMITE_CARACTERES
            && str_contains($texte, 'message tronque');
    });
});

// ── 7. LE COUPE-CIRCUIT ─────────────────────────────────────────────────────

test('ALERTE — le coupe-circuit arrete l envoi, mais PAS le journal', function () {
    config(['alertes.telegram.actif' => false]);
    Http::fake();
    Log::spy();

    expect(app(AlerteTelegram::class)->envoyer('Titre', 'Corps'))->toBeFalse();
    Http::assertNothingSent();

    // Faire taire le canal ne doit pas faire taire la trace : sinon le
    // coupe-circuit devient un moyen d effacer l historique des incidents.
    Log::shouldHaveReceived('critical')->once();
});

// ── 8. LE DÉTECTEUR D'ANOMALIES S'EN SERT-IL VRAIMENT ? ─────────────────────

test('ALERTE — `AnomalyDetect` APPELLE le canal, il ne le promet plus en commentaire', function () {
    // 🔑 C'est le constat d'origine : `// Sprint 11 : send TelegramAlert::dispatch`.
    // Un commentaire ne s'exécute pas. Cette garde lit le CODE, pas les
    // intentions — et elle rougirait si quelqu'un rendait l'appel a nouveau
    // decoratif.
    $source = (string) file_get_contents(app_path('Console/Commands/AnomalyDetect.php'));

    $codeSeul = '';
    foreach (token_get_all($source) as $jeton) {
        if (is_array($jeton) && in_array($jeton[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $codeSeul .= is_array($jeton) ? $jeton[1] : $jeton;
    }

    expect(str_contains($codeSeul, 'AlerteTelegram::class'))->toBeTrue(
        "`AnomalyDetect` n appelle plus le canal d alerte. Il tourne toutes les 15 minutes\n"
        . "et redevient donc un detecteur qui ne previent personne — le defaut exact que ce\n"
        . 'lot ferme.',
    );
    expect(str_contains($codeSeul, '->envoyer('))->toBeTrue(
        'La classe est importee mais jamais appelee : le commentaire aurait juste change de forme.',
    );
});
