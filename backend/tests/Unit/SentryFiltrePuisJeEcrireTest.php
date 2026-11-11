<?php

use App\Support\SentryFiltre;
use Sentry\Event;

/** R05b : l'adresse testée par « puis-je écrire » ne part jamais chez Sentry (elle voyage dans l'URL de la requête). */
test('avantEnvoi retire la query string et l’adresse de l’URL de puis-je-ecrire', function () {
    $e = Event::createEvent();
    $e->setRequest([
        'url' => 'https://api.exemple.test/api/v1/envoi/puis-je-ecrire?adresse=secret%40zz-client.example.invalid',
        'method' => 'GET',
        'query_string' => 'adresse=secret%40zz-client.example.invalid',
    ]);
    $e->setExtra(['adresse' => 'secret@zz-client.example.invalid']);

    $sorti = SentryFiltre::avantEnvoi($e);

    expect($sorti)->not->toBeNull();
    $brut = json_encode([$sorti->getRequest(), $sorti->getExtra()]);
    expect($brut)->not->toContain('secret')->not->toContain('zz-client')
        ->and($sorti->getRequest()['url'])->toContain('/envoi/puis-je-ecrire');
});

test('avantEnvoi laisse les autres requêtes intactes', function () {
    $e = Event::createEvent();
    $req = ['url' => 'https://api.exemple.test/api/v1/envoi/desinscriptions?q=a', 'method' => 'GET', 'query_string' => 'q=a'];
    $e->setRequest($req);
    expect(SentryFiltre::avantEnvoi($e)->getRequest())->toBe($req);
});

test('la config Sentry branche before_send sur le filtre', function () {
    expect(config('sentry.before_send'))->toBe([SentryFiltre::class, 'avantEnvoi']);
});
