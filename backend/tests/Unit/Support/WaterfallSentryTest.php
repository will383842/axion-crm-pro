<?php

use App\Support\WaterfallSentry;
use Illuminate\Support\Facades\Log;

/**
 * Remet le drapeau « je l'ai deja dit » a zero.
 *
 * `WaterfallSentry` ne signale l'absence de DSN qu'UNE FOIS par processus, ce
 * qui est voulu (sinon un avertissement par exception noierait le journal).
 * Mais les tests partagent le processus : sans cette remise a zero, le
 * deuxieme cas mesurerait le silence du premier et verdirait pour la mauvaise
 * raison.
 */
function reinitialiserSignalementDsn(): void
{
    $propriete = new ReflectionProperty(WaterfallSentry::class, 'absenceDeDsnDejaSignalee');
    $propriete->setAccessible(true);
    $propriete->setValue(null, false);
}

it('captures without throwing when Sentry class missing', function () {
    // ⚠️ Le titre de ce cas et son commentaire d'origine affirmaient que le SDK
    // Sentry etait ABSENT du banc. C'est faux : `sentry/sentry-laravel` est en
    // `require` (composer.json:37), et le temoin C18-014 plus bas le verifie.
    // Ce que ce cas mesure reellement, c'est qu'aucun chemin de sortie du
    // helper — classe absente, DSN vide, ou capture reelle — ne propage
    // d'exception dans la waterfall qui l'appelle.
    $e = new \RuntimeException('test');
    expect(fn () => WaterfallSentry::capture(null, 'service', $e))->not->toThrow(\Throwable::class);
});

it('handles null company without crashing', function () {
    $e = new \RuntimeException('test');
    expect(fn () => WaterfallSentry::capture(null, 'auto-classify', $e))
        ->not->toThrow(\Throwable::class);
});

/**
 * C18-014 — DIX CAPTURES QUI PARTAIENT DANS LE VIDE, SANS UN MOT.
 *
 * Mesure du 2026-08-22 : `grep -rn "WaterfallSentry::capture" backend/app` rend
 * 11 lignes mais DIX sites d'appel — neuf dans `WaterfallOrchestrator`, un dans
 * `RefreshAudienceChunkJob`, la 11e etant l'exemple d'usage du docblock du
 * helper. (Le registre d'audit annoncait « exactement ONZE sites d'appel » : il
 * comptait ce docblock.) Tous passaient par ce helper, et le helper appelait
 * `\Sentry\captureException` sur un SDK sans DSN — donc inerte. Aucune
 * exception ne partait, et RIEN ne le disait.
 *
 * Le defaut n'est pas l'absence de DSN (le laisser vide en local est
 * legitime) : c'est le SILENCE. Une supervision muette ne se distingue pas
 * d'une supervision qui marche, et c'est precisement ce qui la rend dangereuse.
 */
test('C18-014 — TEMOIN : la premiere porte est bien OUVERTE en test', function () {
    // Sans ce temoin, la garde suivante pourrait verdir alors que le flot
    // s'arrete a `class_exists(\Sentry\State\Hub::class)` — elle prouverait
    // alors l'absence du paquet, pas le comportement du helper.
    expect(class_exists(\Sentry\State\Hub::class))->toBeTrue(
        'Le SDK Sentry est absent du vendor de ce banc. `WaterfallSentry::capture` sort ' .
        'alors a sa toute premiere ligne et la garde C18-014 ci-dessous ne mesure RIEN. ' .
        'Geste : `composer install` dans le conteneur api (sentry/sentry-laravel est en ' .
        'require, pas en require-dev — cf. backend/composer.json:37).',
    );
});

test('C18-014 — un DSN vide est DIT a voix haute, pas avale en silence', function () {
    reinitialiserSignalementDsn();
    config()->set('sentry.dsn', null);

    $journal = Log::spy();
    WaterfallSentry::capture(null, 'auto-classify', new \RuntimeException('panne de waterfall'));

    $journal->shouldHaveReceived('warning')->withArgs(
        fn ($message) => str_contains((string) $message, 'SENTRY_LARAVEL_DSN'),
    );
});

test('C18-014 — le nom de la variable a renseigner figure dans l avertissement', function () {
    reinitialiserSignalementDsn();
    config()->set('sentry.dsn', '');

    $journal = Log::spy();
    WaterfallSentry::capture(null, 'enrichissement', new \RuntimeException('panne'));

    // Un avertissement qui dit « supervision inactive » sans nommer la variable
    // envoie l'exploitant chercher dans quatre fichiers de configuration.
    $journal->shouldHaveReceived('warning')->withArgs(
        fn ($message) => str_contains((string) $message, 'SENTRY_LARAVEL_DSN')
            && str_contains((string) $message, '.env'),
    );
});

/**
 * TEMOIN NEGATIF — sans lui, un helper qui crierait a CHAQUE appel satisferait
 * aussi les cas ci-dessus. Or un avertissement par exception noie le journal
 * sous le message qui decrit le journal : on ne le dit qu'une fois.
 */
test('C18-014 — TEMOIN : l avertissement ne se repete pas a chaque exception', function () {
    reinitialiserSignalementDsn();
    config()->set('sentry.dsn', null);

    $journal = Log::spy();
    WaterfallSentry::capture(null, 'auto-classify', new \RuntimeException('une'));
    WaterfallSentry::capture(null, 'auto-classify', new \RuntimeException('deux'));
    WaterfallSentry::capture(null, 'auto-classify', new \RuntimeException('trois'));

    $journal->shouldHaveReceived('warning')->once();
});

/**
 * C18-014, second volet — la variable doit EXISTER dans le modele d'environnement.
 *
 * `backend/config/sentry.php:12` lit `SENTRY_LARAVEL_DSN`, et mesure du
 * 2026-08-22 : rien dans le depot ne la declarait. Un exploitant qui recopie
 * `.env.example` pour monter un serveur ne pouvait pas deviner son existence —
 * `.env.example` ne portait que `VITE_SENTRY_DSN` (le frontend), et les
 * `docker-compose*.yml` chargent `env_file: .env` sans rien y ajouter.
 */
test('C18-014 — .env.example declare SENTRY_LARAVEL_DSN, la variable que lit config/sentry.php', function () {
    $chemin = (realpath(base_path('..')) ?: base_path('..')) . '/.env.example';

    expect(file_exists($chemin))->toBeTrue(
        "Le banc ne voit pas {$chemin}. Une garde qui n'a aucun fichier a lire passe au vert " .
        'sans rien prouver : monte la racine du depot avant de la croire.',
    );

    $contenu = (string) file_get_contents($chemin);

    expect(str_contains($contenu, 'SENTRY_LARAVEL_DSN'))->toBeTrue(
        'config/sentry.php lit SENTRY_LARAVEL_DSN mais .env.example ne la nomme pas : ' .
        "l'exploitant qui recopie ce modele monte un serveur dont la capture d'exceptions " .
        "est inerte, sans jamais l'apprendre (constat C18-014). " .
        'Geste : ajouter `SENTRY_LARAVEL_DSN=` dans .env.example, a cote de VITE_SENTRY_DSN.',
    );

    // TEMOIN : la garde doit distinguer la variable BACKEND de celle du
    // frontend. Sans ce controle, `VITE_SENTRY_DSN` seul la satisferait si
    // quelqu'un relachait la sous-chaine cherchee.
    expect(preg_match('/^SENTRY_LARAVEL_DSN=/m', $contenu) === 1)->toBeTrue(
        'SENTRY_LARAVEL_DSN apparait dans .env.example, mais pas comme une DECLARATION ' .
        'en debut de ligne — seulement dans un commentaire. Un commentaire ne se recopie ' .
        'pas dans un .env. Geste : poser la ligne `SENTRY_LARAVEL_DSN=` elle-meme.',
    );
});
