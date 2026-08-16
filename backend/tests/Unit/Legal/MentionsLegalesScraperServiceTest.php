<?php

use App\Models\Company;
use App\Models\Workspace;
use App\Services\Legal\MentionsLegalesScraperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Ces tests montaient à l'origine des `new Company([...])` détachés, avec un
 * `workspace_id` inventé (00000000-…) et sans SIREN. Deux choses les ont
 * rattrapés :
 *
 *  1. `companies_identity_anchor_check` (migration 2026_08_15_120001) impose
 *     désormais « siren IS NOT NULL OR foreign_id IS NOT NULL » : le
 *     `$company->save()` final de `scrape()` violait la contrainte et le test
 *     mourait en QueryException — l'extraction elle-même n'était jamais jugée ;
 *  2. le workspace fantôme faisait échouer l'INSERT des fiches contact
 *     (FK `contacts_workspace_id_fkey`). `persistContact()` avale l'erreur en
 *     `Log::warning`, donc l'écriture des contacts n'était en réalité JAMAIS
 *     couverte, alors que c'est le livrable métier du scraper.
 *
 * On persiste donc de vraies entreprises rattachées à un vrai workspace : les
 * assertions d'extraction sont conservées à l'identique, et on peut enfin
 * vérifier que les fiches contact atterrissent bien en base.
 */
function mlWorkspace(): Workspace
{
    return Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'ml-' . Str::random(6),
        'name' => 'WS Mentions Legales',
    ]);
}

function mlCompany(string $workspaceId, array $overrides = []): Company
{
    return Company::create(array_merge([
        'workspace_id' => $workspaceId,
        // Ancre d'identité obligatoire depuis la migration 2026_08_15_120001.
        'siren' => str_pad((string) random_int(100000000, 999999999), 9, '0', STR_PAD_LEFT),
        'denomination' => 'Acme',
    ], $overrides));
}

it('extracts email and phone from mentions-legales page', function () {
    $body = str_repeat('Lorem ipsum dolor sit amet ', 50)
        . ' contact@acme.fr et 01 23 45 67 89 et autre texte';

    Http::fake([
        'acme.fr/mentions-legales' => Http::response($body, 200),
        '*' => Http::response('', 404),
    ]);

    $ws = mlWorkspace();
    $c = mlCompany($ws->id, ['website' => 'https://acme.fr/', 'denomination' => 'Acme']);

    $service = new MentionsLegalesScraperService;
    $result = $service->scrape($c);

    expect($result)->toBeTrue();
    expect($c->email_generic)->toBe('contact@acme.fr');
    expect($c->phone)->toBe('0123456789');

    // Le livrable métier : une fiche contact réellement écrite en base.
    expect(DB::table('contacts')
        ->where('company_id', $c->id)
        ->where('email', 'contact@acme.fr')
        ->where('discovery_source', 'mentions-legales')
        ->exists())->toBeTrue();
});

it('skips technical email prefixes', function () {
    $body = str_repeat('Lorem ipsum ', 100)
        . ' no-reply@foo.fr et hello@foo.fr';
    Http::fake([
        'foo.fr/mentions-legales' => Http::response($body, 200),
        '*' => Http::response('', 404),
    ]);

    $ws = mlWorkspace();
    $c = mlCompany($ws->id, ['website' => 'https://foo.fr', 'denomination' => 'Foo']);

    $service = new MentionsLegalesScraperService;
    expect($service->scrape($c))->toBeTrue();
    expect($c->email_generic)->toBe('hello@foo.fr');
    // La boîte technique n'est ni retenue comme générique, ni transformée en fiche.
    expect(DB::table('contacts')->where('email', 'no-reply@foo.fr')->exists())->toBeFalse();
});

it('returns false when body too short on all paths', function () {
    Http::fake(['*' => Http::response('short', 200)]);
    $ws = mlWorkspace();
    $c = mlCompany($ws->id, ['website' => 'https://tiny.fr', 'denomination' => 'Tiny']);

    expect((new MentionsLegalesScraperService)->scrape($c))->toBeFalse();
});

it('returns false when website missing', function () {
    $c = new Company(['denomination' => 'NoSite']);
    expect((new MentionsLegalesScraperService)->scrape($c))->toBeFalse();
});

it('tries fallback path when first 404', function () {
    // Le pool tape les 8 paths en parallèle : /contact 404, mais /contact.html
    // répond → l'email de secours est bien capturé. (Http::fake par URL car le
    // pool concurrent n'a pas d'ordre déterministe pour fakeSequence.)
    Http::fake([
        'bar.fr/contact' => Http::response('', 404),
        'bar.fr/contact.html' => Http::response(str_repeat('Lorem ipsum ', 100) . ' info@bar.fr', 200),
        '*' => Http::response('', 404),
    ]);

    $ws = mlWorkspace();
    $c = mlCompany($ws->id, ['website' => 'https://bar.fr', 'denomination' => 'Bar']);

    expect((new MentionsLegalesScraperService)->scrape($c))->toBeTrue();
    expect($c->email_generic)->toBe('info@bar.fr');
});

it('captures ALL emails and ALL phones (not just the first)', function () {
    $body = str_repeat('Lorem ipsum dolor ', 60)
        . ' Nos services : commercial@acme.fr, compta@acme.fr, contact@acme.fr '
        . ' Tel 01 23 45 67 89 ou 04.11.22.33.44 ou +33 6 12 34 56 78 ';

    Http::fake([
        'acme.fr/contact' => Http::response($body, 200),
        '*' => Http::response('', 404),
    ]);

    $ws = mlWorkspace();
    $c = mlCompany($ws->id, ['website' => 'https://acme.fr/', 'denomination' => 'Acme']);

    expect((new MentionsLegalesScraperService)->scrape($c))->toBeTrue();

    $channels = $c->signals['contact_channels'] ?? [];
    // Les 3 emails sont conservés (aucun perdu).
    expect($channels['emails'])->toContain('commercial@acme.fr')
        ->toContain('compta@acme.fr')
        ->toContain('contact@acme.fr');
    // Les 3 téléphones (national x2 + international normalisé) sont conservés.
    expect($channels['phones'])->toContain('0123456789')
        ->toContain('0411223344')
        ->toContain('0612345678');
    // Les 3 emails deviennent 3 fiches contact — pas seulement le générique.
    expect(DB::table('contacts')->where('company_id', $c->id)->count())->toBe(3);
});

it('deduces service roles and picks a service email as generic', function () {
    $body = str_repeat('Lorem ipsum ', 80) . ' rh@corp.fr et commercial@corp.fr ';
    Http::fake([
        'corp.fr/contact' => Http::response($body, 200),
        '*' => Http::response('', 404),
    ]);

    $ws = mlWorkspace();
    $c = mlCompany($ws->id, ['website' => 'https://corp.fr', 'denomination' => 'Corp']);

    expect((new MentionsLegalesScraperService)->scrape($c))->toBeTrue();
    // email_generic = une boîte service (le 1er accepté), pas vide.
    expect($c->email_generic)->not->toBeNull();
    expect($c->signals['contact_channels']['emails'])->toContain('rh@corp.fr')
        ->toContain('commercial@corp.fr');
    // Le rôle déduit du préfixe est bien posé sur la fiche.
    expect(DB::table('contacts')->where('email', 'rh@corp.fr')->value('role'))
        ->toBe('Ressources humaines');
    expect(DB::table('contacts')->where('email', 'commercial@corp.fr')->value('role'))
        ->toBe('Commercial');
});
