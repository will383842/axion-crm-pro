<?php

use App\Models\Company;
use App\Services\Domain\DomainFinderService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Passe 3 — re-validation des sites déjà trouvés (DomainFinderService::revalidateBatch).
 *
 * Règle CONSERVATRICE : toute réponse HTTP (même 4xx/5xx) = VIVANT ; seule une
 * exception de connexion (DNS / refus / timeout) = MORT.
 */
function makeCompany(int $id, ?string $website): Company
{
    $c = new Company(['denomination' => "Co {$id}", 'website' => $website]);
    $c->id = $id;

    return $c;
}

it('marque VIVANT un site qui répond 200', function () {
    Http::fake(['alive.test/*' => Http::response('<html>ok</html>', 200)]);

    $result = (new DomainFinderService())->revalidateBatch([
        makeCompany(1, 'https://alive.test/'),
    ]);

    expect($result)->toBe([1 => true]);
});

it('marque VIVANT même un 404/500 (le serveur répond = hébergement vivant)', function () {
    Http::fake([
        'gone.test/*' => Http::response('not found', 404),
        'broken.test/*' => Http::response('server error', 500),
    ]);

    $result = (new DomainFinderService())->revalidateBatch([
        makeCompany(1, 'https://gone.test/'),
        makeCompany(2, 'https://broken.test/'),
    ]);

    expect($result)->toBe([1 => true, 2 => true]);
});

it('marque MORT un site dont la requête lève une ConnectionException', function () {
    Http::fake(['dead.test/*' => fn () => throw new ConnectionException('Connection refused')]);

    $result = (new DomainFinderService())->revalidateBatch([
        makeCompany(1, 'https://dead.test/'),
    ]);

    expect($result)->toBe([1 => false]);
});

it('mélange vivant + mort dans un même lot', function () {
    Http::fake([
        'alive.test/*' => Http::response('ok', 200),
        'dead.test/*' => fn () => throw new ConnectionException('DNS fail'),
    ]);

    $result = (new DomainFinderService())->revalidateBatch([
        makeCompany(1, 'https://alive.test/'),
        makeCompany(2, 'https://dead.test/'),
    ]);

    expect($result)->toBe([1 => true, 2 => false]);
});

it('ignore (skip) les entreprises sans website', function () {
    Http::preventStrayRequests();

    $result = (new DomainFinderService())->revalidateBatch([
        makeCompany(1, null),
        makeCompany(2, '   '),
    ]);

    expect($result)->toBe([]);
});
