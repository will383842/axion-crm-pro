<?php

/**
 * `prospection:find-websites --naf=47.73Z` : le filtre ne touche que ce NAF, un
 * site déjà connu n'est jamais remplacé, le dry-run n'écrit rien.
 *
 * Fixtures FICTIVES (dépôt public) : SIREN 96xxxxxxx, noms « ZZ ». Le service de
 * devinette est remplacé : aucun accès réseau.
 */

use App\Models\Workspace;
use App\Services\Domain\DomainFinderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->espace = (string) Str::uuid();
    $this->slug = 'zz-sites-' . Str::random(6);
    Workspace::create(['id' => $this->espace, 'slug' => $this->slug, 'name' => 'ZZ sites']);
    $this->appels = 0;
    $test = $this;
    $this->mock(DomainFinderService::class, function (MockInterface $m) use ($test): void {
        $m->shouldReceive('guessDomainsBatch')->andReturnUsing(function (iterable $companies) use ($test): array {
            $test->appels++;
            $urls = [];
            foreach ($companies as $c) {
                $urls[$c->id] = 'https://zz-' . $c->id . '.example.test';
            }

            return $urls;
        });
    });
});

/** @param  array<string, mixed>  $attrs */
function prsFiche(string $espace, array $attrs = []): int
{
    static $seq = 0;
    $seq++;

    return (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $espace,
        'siren' => (string) (960000000 + $seq),
        'denomination' => 'ZZ fiche ' . $seq,
        'naf' => '47.73Z',
        'legal_form' => '5499',
        'discovery_source' => 'insee',
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));
}

function prsLancer(object $test, array $options = []): string
{
    Artisan::call('prospection:find-websites', array_merge(['--naf' => '47.73Z', '--workspace' => $test->slug], $options));

    return Artisan::output();
}

it('ne touche que les fiches du NAF demandé', function () {
    $pharmacie = prsFiche($this->espace);
    $autre = prsFiche($this->espace, ['naf' => '56.10A']);

    prsLancer($this);

    expect(DB::table('companies')->where('id', $pharmacie)->value('website_status'))->toBe('found')
        ->and(DB::table('companies')->where('id', $pharmacie)->value('website'))->not->toBeNull()
        ->and(DB::table('companies')->where('id', $autre)->value('website'))->toBeNull()
        ->and(DB::table('companies')->where('id', $autre)->value('website_status'))->toBe('pending');
});

it('écarte corbeille, fermées, non-officines et autre espace', function () {
    $ouverte = prsFiche($this->espace);
    $supprimee = prsFiche($this->espace, ['deleted_at' => now()]);
    $fermee = prsFiche($this->espace, ['insee_ferme_le' => '2020-01-01']);
    $nonOfficine = prsFiche($this->espace, ['metadata' => json_encode(['type_etablissement' => 'non_officine'])]);
    $autreEspace = (string) Str::uuid();
    Workspace::create(['id' => $autreEspace, 'slug' => 'zz-autre-' . Str::random(6), 'name' => 'ZZ autre']);
    $ailleurs = prsFiche($autreEspace);

    prsLancer($this);

    expect(DB::table('companies')->where('id', $ouverte)->value('website'))->not->toBeNull();
    foreach ([$supprimee, $fermee, $nonOfficine, $ailleurs] as $id) {
        expect(DB::table('companies')->where('id', $id)->value('website'))->toBeNull();
    }
});

it('ne remplace jamais un site déjà connu', function () {
    $connue = prsFiche($this->espace, ['website' => 'https://connu.example.test', 'website_status' => 'found']);
    $vide = prsFiche($this->espace);

    prsLancer($this);

    expect(DB::table('companies')->where('id', $connue)->value('website'))->toBe('https://connu.example.test')
        ->and(DB::table('companies')->where('id', $vide)->value('website_status'))->toBe('found');
});

it('le dry-run n écrit rien et ne devine rien', function () {
    $id = prsFiche($this->espace);

    $sortie = prsLancer($this, ['--dry-run' => true]);

    expect($sortie)->toContain('1 fiches à traiter')
        ->and($this->appels)->toBe(0)
        ->and(DB::table('companies')->where('id', $id)->value('website'))->toBeNull()
        ->and(DB::table('companies')->where('id', $id)->value('website_status'))->toBe('pending');
});

it('--limit borne le nombre de fiches traitées', function () {
    foreach (range(1, 3) as $_) {
        prsFiche($this->espace);
    }

    prsLancer($this, ['--limit' => 2]);

    expect(DB::table('companies')->where('website_status', 'found')->count())->toBe(2);
});

it('--dry-run est refusé sans --naf', function () {
    $code = Artisan::call('prospection:find-websites', ['--dry-run' => true]);

    expect($code)->toBe(1);
});
