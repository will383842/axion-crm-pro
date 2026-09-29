<?php

/**
 * LE QUOTA BRAVE SUR TOUS LES CHEMINS — sans base (2026-09-29).
 *
 * La clé Brave est posée en production depuis le 29/09. Sans ces gardes,
 * `companies:rescrape-archives` et `companies:retry-google-places` (le 1er du
 * mois) auraient envoyé des `EnrichCompanyJob` → `DomainFinderService::find()`
 * → Brave, jusqu'à trois requêtes par fiche (`->retry(2)`), hors compteur :
 * le crédit gratuit épuisé avant le passage des fédérations, le 3.
 *
 * Aucune base ici : le quota est le compteur FACTICE en mémoire
 * (`QuotaBraveEnMemoire`, même règle `PlafondsBrave` que la production), sauf
 * le premier test, qui prend l'implémentation de PRODUCTION et prouve qu'elle
 * ne touche même pas la base quand le sous-quota vaut 0.
 */

use App\Crm\Brave\QuotaBrave;
use App\Crm\Brave\RechercheBrave;
use App\Models\Company;
use App\Services\Domain\DomainFinderService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\QuotaBraveEnMemoire;

beforeEach(function () {
    Config::set('services.brave.api_key', 'fausse-cle-brave');
    Config::set('services.scrapers.mock', true);
});

/** @param  array<string, int>  $quotas  usage => sous-quota */
function dfbqQuota(int $global, array $quotas): QuotaBraveEnMemoire
{
    Config::set('crm.brave.quota_mensuel', $global);
    foreach ($quotas as $usage => $plafond) {
        Config::set('crm.brave.quotas.' . $usage, $plafond);
    }
    $quota = new QuotaBraveEnMemoire;
    app()->instance(QuotaBrave::class, $quota);

    return $quota;
}

/** Brave rend un site ACCEPTÉ : `find()` s'arrête là, sans DNS ni devinette. */
function dfbqBraveRepond(): void
{
    Http::preventStrayRequests();
    Http::fake([
        'api.search.brave.com/*' => Http::response(['web' => ['results' => [['url' => 'https://site-trouve.test/']]]], 200),
    ]);
}

function dfbqRequetesBrave(): int
{
    return Http::recorded()
        ->filter(static fn (array $paire): bool => str_contains($paire[0]->url(), 'api.search.brave.com'))
        ->count();
}

function dfbqEntreprise(): Company
{
    return new Company(['denomination' => 'Zzqx Inexistante Quota', 'city_name' => 'Nulle-Part']);
}

test('sous-quota enrichissement a 0 (defaut) : find() n envoie AUCUNE requete Brave, cle posee, et ne lit pas la base', function () {
    // L'implémentation de PRODUCTION, pas le compteur factice.
    app()->forgetInstance(QuotaBrave::class);
    Config::set('crm.brave.quota_mensuel', 900);
    Config::set('crm.brave.quotas.enrichissement', 0);
    dfbqBraveRepond();
    $requetesSql = 0;
    DB::listen(function (QueryExecuted $q) use (&$requetesSql): void {
        $requetesSql++;
    });

    $url = (new DomainFinderService)->find(dfbqEntreprise());

    expect(dfbqRequetesBrave())->toBe(0)
        ->and($url)->toBeNull()
        ->and($requetesSql)->toBe(0);
});

test('le defaut de configuration du sous-quota enrichissement est 0', function () {
    $quota = new QuotaBraveEnMemoire;
    Config::set('crm.brave.quotas', []);

    expect($quota->plafond(QuotaBrave::ENRICHISSEMENT))->toBe(0)
        ->and($quota->plafond(QuotaBrave::FEDERATIONS))->toBe(900)
        ->and($quota->plafond('inconnu'))->toBe(0)
        ->and($quota->reserver('inconnu'))->toBeFalse();
});

test('enrichissement : la N+1e requete n est jamais envoyee (sous-quota)', function () {
    $quota = dfbqQuota(100, ['enrichissement' => 2, 'federations' => 100]);
    dfbqBraveRepond();

    $urls = [];
    for ($i = 0; $i < 4; $i++) {
        $urls[] = (new DomainFinderService)->find(dfbqEntreprise());
    }

    expect(dfbqRequetesBrave())->toBe(2)
        ->and($quota->consommees(QuotaBrave::ENRICHISSEMENT))->toBe(2)
        ->and($quota->refus)->toBe(['enrichissement', 'enrichissement'])
        ->and(array_slice($urls, 0, 2))->toBe(['https://site-trouve.test/', 'https://site-trouve.test/']);
});

test('tous chemins confondus : le total ne depasse JAMAIS le plafond global', function () {
    $quota = dfbqQuota(3, ['enrichissement' => 5, 'federations' => 5]);
    dfbqBraveRepond();
    $brave = app(RechercheBrave::class);

    $etats = [];
    $etats[] = $brave->chercher('premiere', QuotaBrave::FEDERATIONS)['etat'];
    $etats[] = $brave->chercher('deuxieme', QuotaBrave::FEDERATIONS)['etat'];
    for ($i = 0; $i < 3; $i++) {
        (new DomainFinderService)->find(dfbqEntreprise());
    }
    $etats[] = $brave->chercher('apres', QuotaBrave::FEDERATIONS)['etat'];

    expect(dfbqRequetesBrave())->toBe(3)
        ->and($quota->consommees())->toBe(3)
        ->and($quota->consommees(QuotaBrave::FEDERATIONS))->toBe(2)
        ->and($quota->consommees(QuotaBrave::ENRICHISSEMENT))->toBe(1)
        ->and($etats)->toBe(['ok', 'ok', 'plafond']);
});

test('plus de retry : une requete Brave en echec reseau n est envoyee qu UNE fois, et comptee', function () {
    $quota = dfbqQuota(100, ['enrichissement' => 10]);
    Http::preventStrayRequests();
    Http::fake(['api.search.brave.com/*' => fn () => throw new ConnectionException('delai')]);

    // Avant : `->retry(2, 500, …ConnectionException)` — TROIS requêtes
    // facturées pour un seul `find()`.
    (new DomainFinderService)->find(dfbqEntreprise());
    expect(dfbqRequetesBrave())->toBe(1)
        ->and($quota->consommees())->toBe(1);

    $etat = app(RechercheBrave::class)->chercher('requete', QuotaBrave::ENRICHISSEMENT)['etat'];
    expect(dfbqRequetesBrave())->toBe(2)
        ->and($etat)->toBe('erreur')
        ->and($quota->consommees())->toBe(2);
});

test('sans cle : rien n est reserve, rien n est envoye', function () {
    $quota = dfbqQuota(100, ['enrichissement' => 10, 'federations' => 10]);
    Config::set('services.brave.api_key', '');
    Http::fake();

    $etat = app(RechercheBrave::class)->chercher('requete', QuotaBrave::FEDERATIONS)['etat'];

    Http::assertNothingSent();
    expect($etat)->toBe('sans_cle')
        ->and($quota->consommees())->toBe(0);
});

test('la cle part dans l en-tete de la requete Brave, jamais dans l URL', function () {
    dfbqQuota(100, ['enrichissement' => 10]);
    dfbqBraveRepond();

    (new DomainFinderService)->find(dfbqEntreprise());

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'api.search.brave.com')
        && $r->hasHeader('X-Subscription-Token', 'fausse-cle-brave')
        && ! str_contains($r->url(), 'fausse-cle-brave'));
});
