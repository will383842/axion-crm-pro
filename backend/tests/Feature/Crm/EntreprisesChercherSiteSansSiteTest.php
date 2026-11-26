<?php

/**
 * SITE POUR FICHE SANS SITE (PR 4) — `crm:entreprises:chercher-site-sans-site`.
 *
 * Fiche sans `website`, dans un lot (1 à 7) : domaines candidats tirés des
 * noms, retenus SEULEMENT si le SIREN est sur l'accueil ou les mentions
 * légales (arrivée sur le même domaine). Gardes tenues ici :
 *  1. preuve par l'accueil : `website` écrit, méthode SANS `guess`, origine
 *     du champ posée, `enriched_at` intact ;
 *  2. SIREN sur un AUTRE domaine (redirection) : rien d'écrit ;
 *  3. robots.txt interdit : la page n'est pas lue ;
 *  4. adresse interne (10.0.0.1, 169.254.169.254) : refusée par la garde SSRF ;
 *  5. `--dry-run` : zéro requête d'écriture ;
 *  6. fiche protégée ou `website` rempli entre la lecture et l'écriture : écartée ;
 *  7. disque à 86 % : arrêt, code 4.
 *
 * AUCUN réseau : `Http::fake`. Fixtures FICTIVES (noms « ZZ », domaines `.test`).
 */

use App\Crm\FichesProtegees;
use App\Crm\Sites\GardeDisque;
use App\Crm\Sites\PerimetreEnrichissement;
use App\Crm\Sites\SiteFiable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['crm.ingest.business_workspace' => 'axion-ia', 'crm.sites_candidats.extensions' => ['test']]);
    $this->espace = (string) (DB::table('workspaces')->where('slug', 'axion-ia')->value('id') ?? Str::uuid());
    if (! DB::table('workspaces')->where('id', $this->espace)->exists()) {
        DB::table('workspaces')->insert([
            'id' => $this->espace, 'slug' => 'axion-ia', 'name' => 'Axion-IA', 'settings' => '{}',
            'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    // Mardi 6 octobre 2026, 10:00 à Paris : dans la fenêtre.
    Carbon::setTestNow(Carbon::parse('2026-10-06 10:00:00', 'Europe/Paris'));
    Sleep::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Une fiche SANS site, tranche 11 (lot 1), jamais passée au waterfall. */
function sssFiche(string $espace, string $nom, array $attrs = []): int
{
    return F::fiche($espace, $nom, $attrs + [
        'website' => null, 'effectif_range' => '11', 'city_name' => 'ZZVILLE', 'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function sssPage(string $corps): string
{
    return '<!doctype html><html><head><meta charset="utf-8"><title>Accueil</title></head><body><p>' . $corps . '</p></body></html>';
}

/** Faux réseau : robots.txt absent, une page par URL, le reste en 404. */
function sssReseau(array $pages, array &$demandes = []): void
{
    Http::fake(function (Request $q) use ($pages, &$demandes) {
        $demandes[] = $q->url();
        $r = $pages[$q->url()] ?? null;
        if (is_string($r)) {
            return Http::response($r, 200, ['Content-Type' => str_ends_with($q->url(), '/robots.txt') ? 'text/plain' : 'text/html; charset=utf-8']);
        }
        if (is_array($r)) {
            return Http::response($r[1], $r[0], $r[2] ?? []);
        }

        return Http::response('', 404);
    });
}

/** @return array{code: int, sortie: string} */
function sssLancer(array $options = []): array
{
    $code = Artisan::call('crm:entreprises:chercher-site-sans-site', $options + ['--lot' => '1']);

    return ['code' => $code, 'sortie' => Artisan::output()];
}

function sssLigne(int $id): object
{
    return DB::table('companies')->where('id', $id)->first();
}

function sssRecherche(int $id): ?array
{
    $m = json_decode((string) sssLigne($id)->metadata, true);

    return is_array($m) && is_array($m[PerimetreEnrichissement::MARQUEUR] ?? null) ? $m[PerimetreEnrichissement::MARQUEUR] : null;
}

test('SIREN sur l accueil du candidat : website écrit, méthode sans guess, origine posée, enriched_at intact', function () {
    $id = sssFiche($this->espace, 'ZZ BOULANGERIE FICTIVE');
    $avant = sssLigne($id);
    sssReseau(['https://zzboulangeriefictive.test/' => sssPage('RCS ZZVILLE ' . $avant->siren)]);

    $r = sssLancer();
    $l = sssLigne($id);

    expect($r['code'])->toBe(0)
        ->and($l->website)->toBe('https://zzboulangeriefictive.test/')
        ->and($l->website_method)->toBe('candidat-prouve')
        ->and($l->website_method)->not->toStartWith('guess')
        ->and(json_decode((string) $l->field_origins, true)['website'] ?? null)->toBe('candidat')
        ->and(json_decode((string) $l->field_origins_at, true)['website'] ?? null)->not->toBeNull()
        ->and($l->enriched_at)->toBeNull()
        ->and((string) $l->updated_at)->toBe((string) $avant->updated_at)
        ->and(json_decode((string) $l->metadata, true)[SiteFiable::CLE])->toMatchArray(['statut' => 'trouve-verifie', 'origine' => 'candidat'])
        ->and(SiteFiable::estNonVerifie($l->website_method, $l->metadata))->toBeFalse();
    // Ni SIREN ni domaine au rapport.
    expect($r['sortie'])->not->toContain($avant->siren)->and($r['sortie'])->not->toContain('zzboulangerie');
});

test('SIREN seulement sur un AUTRE domaine (redirection) : rien d écrit, marqueur aucun', function () {
    $id = sssFiche($this->espace, 'ZZ PLOMBERIE FICTIVE');
    sssReseau([
        'https://zzplomberiefictive.test/' => [301, '', ['Location' => 'https://zz-annuaire.test/fiche']],
        'https://zz-annuaire.test/fiche' => sssPage('SIREN ' . sssLigne($id)->siren),
    ]);

    sssLancer();

    expect(sssLigne($id)->website)->toBeNull()
        ->and(sssLigne($id)->website_method)->toBeNull()
        ->and(sssRecherche($id))->toMatchArray(['etape' => 'candidats', 'resultat' => 'aucun', 'lot' => 1]);
});

test('robots.txt interdit : la page du candidat n est pas lue', function () {
    $id = sssFiche($this->espace, 'ZZ GARAGE FICTIF');
    $demandes = [];
    sssReseau([
        'https://zzgaragefictif.test/robots.txt' => "User-agent: *\nDisallow: /",
        'https://zzgaragefictif.test/' => sssPage('SIREN ' . sssLigne($id)->siren),
    ], $demandes);

    sssLancer();

    expect($demandes)->not->toContain('https://zzgaragefictif.test/')
        ->and(sssLigne($id)->website)->toBeNull();
});

test('adresse interne (10.0.0.1, 169.254.169.254) : refusée par la garde SSRF, jamais contactée', function () {
    $id = sssFiche($this->espace, 'ZZ INTERNE FICTIVE');
    $demandes = [];
    sssReseau([
        'https://zzinternefictive.test/' => [302, '', ['Location' => 'http://10.0.0.1/']],
        'https://zz-interne-fictive.test/' => [302, '', ['Location' => 'http://169.254.169.254/latest/']],
        'http://10.0.0.1/' => sssPage('SIREN ' . sssLigne($id)->siren),
        'http://169.254.169.254/latest/' => sssPage('SIREN ' . sssLigne($id)->siren),
    ], $demandes);

    sssLancer();

    expect($demandes)->not->toContain('http://10.0.0.1/')
        ->and($demandes)->not->toContain('http://169.254.169.254/latest/')
        ->and(sssLigne($id)->website)->toBeNull();
});

test('--dry-run : sites lus, zéro requête d écriture', function () {
    $id = sssFiche($this->espace, 'ZZ BLANC FICTIF');
    sssReseau(['https://zzblancfictif.test/' => sssPage('SIREN ' . sssLigne($id)->siren)]);
    $ecritures = 0;
    DB::listen(function ($q) use (&$ecritures) {
        if (preg_match('/^\s*(update|insert|delete)\b/i', $q->sql) === 1) {
            $ecritures++;
        }
    });

    $r = sssLancer(['--dry-run' => true]);

    expect($r['code'])->toBe(0)
        ->and($ecritures)->toBe(0)
        ->and(sssLigne($id)->website)->toBeNull()
        ->and(sssRecherche($id))->toBeNull();
});

test('fiche protégée ou website rempli entre la lecture et l écriture : écartée', function () {
    $rempli = sssFiche($this->espace, 'ZZ REMPLIE FICTIVE');
    $protegee = sssFiche($this->espace, 'ZZ PROTEGEE FICTIVE');
    $sirenRempli = sssLigne($rempli)->siren;
    $sirenProtegee = sssLigne($protegee)->siren;
    $tag = F::tag($this->espace, FichesProtegees::TAG_FEDERATIONS);
    Http::fake(function (Request $q) use ($rempli, $protegee, $sirenRempli, $sirenProtegee, $tag) {
        if ($q->url() === 'https://zzrempliefictive.test/') {
            DB::table('companies')->where('id', $rempli)->update(['website' => 'https://zz-saisi-main.test/']);

            return Http::response(sssPage('SIREN ' . $sirenRempli), 200, ['Content-Type' => 'text/html']);
        }
        if ($q->url() === 'https://zzprotegeefictive.test/') {
            F::lier($this->espace, $protegee, $tag);

            return Http::response(sssPage('SIREN ' . $sirenProtegee), 200, ['Content-Type' => 'text/html']);
        }

        return Http::response('', 404);
    });

    $r = sssLancer();

    expect(sssLigne($rempli)->website)->toBe('https://zz-saisi-main.test/')
        ->and(sssLigne($rempli)->website_method)->toBeNull()
        ->and(sssLigne($protegee)->website)->toBeNull()
        ->and(sssLigne($protegee)->website_method)->toBeNull()
        ->and($r['sortie'])->toMatch('/écartées\D+2\b/u');
});

test('disque à 86 % : arrêt avec le code 4, rien lu ni écrit ; à 84 % : on continue', function () {
    $id = sssFiche($this->espace, 'ZZ DISQUE FICTIF');
    $demandes = [];
    sssReseau(['https://zzdisquefictif.test/' => sssPage('SIREN ' . sssLigne($id)->siren)], $demandes);

    app()->instance(GardeDisque::class, new GardeDisque('/', static fn (): float => 14.0, static fn (): float => 100.0));
    $r = sssLancer();
    expect($r['code'])->toBe(GardeDisque::CODE_SORTIE)->and($demandes)->toBe([])->and(sssLigne($id)->website)->toBeNull();

    app()->instance(GardeDisque::class, new GardeDisque('/', static fn (): float => 16.0, static fn (): float => 100.0));
    expect(sssLancer()['code'])->toBe(0)->and(sssLigne($id)->website)->toBe('https://zzdisquefictif.test/');
});

test('options invalides refusées ; --limite borne les fiches lues', function () {
    sssFiche($this->espace, 'ZZ UN FICTIF');
    sssFiche($this->espace, 'ZZ DEUX FICTIF');
    sssReseau([]);

    expect(Artisan::call('crm:entreprises:chercher-site-sans-site'))->toBe(1)
        ->and(sssLancer(['--lot' => '8'])['code'])->toBe(1)
        ->and(sssLancer(['--jusqua' => '25:00'])['code'])->toBe(1);

    $r = sssLancer(['--limite' => '1']);
    expect($r['sortie'])->toMatch('/fiches lues\D+1\b/u');
});
