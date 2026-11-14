<?php

use App\Envoi\Infrastructure\NomDomaineEnvoi;

test('un nom ordinaire est normalisé en minuscules', function () {
    expect(NomDomaineEnvoi::normaliser('  Envoi-Fictif-R15.FR. '))->toBe('envoi-fictif-r15.fr');
});

test('un IDN est accepté et normalisé en punycode', function () {
    expect(NomDomaineEnvoi::normaliser('Bücher-fictif-r15.fr'))->toBe('xn--bcher-fictif-r15-jzb.fr');
})->skip(! function_exists('idn_to_ascii'), 'extension intl absente');

test('la longueur maximale est respectée (253 accepté, 254 refusé, étiquette de 64 refusée)', function () {
    $label = str_repeat('a', 63);
    $ok = $label . '.' . $label . '.' . $label . '.' . str_repeat('b', 58) . '.fr'; // 3*63 + 58 + 3 points + 3 = 253
    expect(strlen($ok))->toBe(253);
    expect(NomDomaineEnvoi::normaliser($ok))->toBe($ok);
    expect(fn () => NomDomaineEnvoi::normaliser('a' . $ok))->toThrow(InvalidArgumentException::class);
    expect(fn () => NomDomaineEnvoi::normaliser(str_repeat('a', 64) . '.fr'))->toThrow(InvalidArgumentException::class);
});

test('une adresse IP n\'est jamais un domaine', function (string $ip) {
    expect(fn () => NomDomaineEnvoi::normaliser($ip))->toThrow(InvalidArgumentException::class);
})->with(['192.0.2.1', '2001:db8::1', '[2001:db8::1]', '3232235777']);

test('domaines internes ou réservés refusés', function (string $nom) {
    expect(fn () => NomDomaineEnvoi::normaliser($nom))->toThrow(InvalidArgumentException::class);
})->with(['localhost', 'a.localhost', 'serveur.local', 'a.b.internal', 'x.invalid', 'envoi.test', 'envoi.example', 'example.com', 'envoi.example.net', 'www.example.org', 'service.onion', 'x.alt', 'poste.localdomain', 'un-seul-mot', 'a..fr', '-a.fr', 'a b.fr', '']);

test('le message d\'erreur ne cite pas la saisie', function () {
    expect(fn () => NomDomaineEnvoi::normaliser('secret-saisi.local'))
        ->toThrow(fn (InvalidArgumentException $e) => expect($e->getMessage())->not->toContain('secret-saisi'));
});
