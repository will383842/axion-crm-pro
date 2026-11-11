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

test('avantEnvoi retire la query string de toute route /envoi/*', function (string $chemin) {
    $e = Event::createEvent();
    $e->setRequest(['url' => 'https://api.exemple.test/api/v1' . $chemin . '?q=zz-valeur', 'method' => 'GET', 'query_string' => 'q=zz-valeur', 'data' => ['x' => 'zz-valeur']]);
    $req = SentryFiltre::avantEnvoi($e)->getRequest();
    expect(json_encode($req))->not->toContain('zz-valeur')->and($req['url'])->toEndWith($chemin);
})->with(['/envoi/desinscriptions', '/envoi/opposition/export', '/envoi/puis-je-ecrire', '/envoi/campagnes/1/destinataires']);

test('avantEnvoi laisse les autres requêtes intactes', function () {
    $e = Event::createEvent();
    $req = ['url' => 'https://api.exemple.test/api/v1/crm/entreprises?q=a', 'method' => 'GET', 'query_string' => 'q=a'];
    $e->setRequest($req);
    expect(SentryFiltre::avantEnvoi($e)->getRequest())->toBe($req);
});

test('la config Sentry branche before_send sur le filtre', function () {
    expect(config('sentry.before_send'))->toBe([SentryFiltre::class, 'avantEnvoi']);
});
