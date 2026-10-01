<?php

/**
 * VÉRIFIER — ET TROUVER — LE SITE DES MÉDIAS (2026-10-01) —
 * `crm:presse:verifier-sites`.
 *
 * Chaque test rougit sans la règle qu'il nomme : site conforme accepté ;
 * paris.fr pour « PARIS LIVE » refusé ; site au nom d'un autre refusé ;
 * domaine partagé refusé d'office ; candidat trouvé et vérifié écrit
 * SEULEMENT là où il n'y a pas de site, jamais d'écrasement ; essai à blanc
 * qui n'écrit rien ; aucune donnée nominative stockée ; idempotence.
 *
 * AUCUN appel réseau : `Http::fake`. Fixtures FICTIVES (dépôt PUBLIC) : noms
 * « ZZ » / « Zorglub », SIREN en 9xxxxxxxx.
 */

use App\Crm\Presse\SiteMedia;
use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['crm.scrape_funnel.validate_mx' => false, 'crm.ingest.business_workspace' => 'axion-ia']);
    $this->espace = (string) (DB::table('workspaces')->where('slug', 'axion-ia')->value('id') ?? Str::uuid());
    if (! DB::table('workspaces')->where('id', $this->espace)->exists()) {
        DB::table('workspaces')->insert([
            'id' => $this->espace, 'slug' => 'axion-ia', 'name' => 'Axion-IA', 'settings' => '{}',
            'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $this->seed(ScrapingSourcesSeeder::class);
});

/** Une fiche de presse avec une ligne `media` (site de la fiche deviné, comme en production). */
function pvsFiche(string $espace, ?string $site, string $nom, array $media = []): int
{
    $id = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $espace,
        'siren' => '9' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'denomination' => $nom,
        'website' => $site,
        'website_method' => $site === null ? null : 'guess',
        'entity_nature' => 'media',
        'relation_type' => 'presse_media',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    pvsMedia($espace, $id, $media + ['name' => $nom]);

    return $id;
}

function pvsMedia(string $espace, int $fiche, array $valeurs = []): int
{
    return (int) DB::table('media')->insertGetId($valeurs + [
        'workspace_id' => $espace, 'company_id' => $fiche, 'name' => 'ZZ media fictif',
        'media_type' => 'presse_mensuel', 'media_family' => 'editorial', 'source' => 'cppap',
        'enrich_status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function pvsPage(string $titre, string $corps = ''): string
{
    return '<!doctype html><html><head><meta charset="utf-8"><title>' . $titre . '</title></head><body><p>' . $corps . '</p></body></html>';
}

/** Faux réseau : une page d'accueil par hôte (robots.txt absent) ; tout le reste en 404. */
function pvsReseau(array $pages): void
{
    $faux = [];
    foreach ($pages as $hote => $page) {
        $faux['https://' . $hote . '/robots.txt'] = Http::response('', 404);
        $faux['https://' . $hote . '/'] = Http::response($page, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
    $faux['*'] = Http::response('', 404);
    Http::fake($faux);
}

/** @return array{code: int, sortie: string} */
function pvsLancer(array $options = []): array
{
    $code = Artisan::call('crm:presse:verifier-sites', $options + ['--delai-domaine-ms' => '0']);

    return ['code' => $code, 'sortie' => Artisan::output()];
}

/** @return array<string, mixed>|null */
function pvsMarqueur(int $id): ?array
{
    $meta = json_decode((string) DB::table('companies')->where('id', $id)->value('metadata'), true);

    return is_array($meta) && is_array($meta[SiteMedia::CLE] ?? null) ? $meta[SiteMedia::CLE] : null;
}

test('site CONFORME accepte : verifie, URL gardee, rien d autre ecrit — aucune donnee nominative', function () {
    $id = pvsFiche($this->espace, 'https://echo-zorglubs.test', "ZZ L'ÉCHO DES ZORGLUBS");
    pvsReseau(['echo-zorglubs.test' => pvsPage("L'Écho des Zorglubs — par Zorglub Fictivus", 'Contact : zorglub.fictivus@echo-zorglubs.test, 06 00 00 00 00')]);

    $r = pvsLancer(['--appliquer' => true]);

    expect($r['code'])->toBe(0)
        ->and(pvsMarqueur($id))->toMatchArray(['statut' => SiteMedia::VERIFIE, 'url' => 'https://echo-zorglubs.test/', 'v' => SiteMedia::VERSION])
        ->and(array_keys(pvsMarqueur($id)))->toEqualCanonicalizing(['statut', 'url', 'le', 'v'])
        ->and(SiteMedia::estVerifie($id))->toBeTrue()
        ->and(DB::table('companies')->where('id', $id)->value('website'))->toBe('https://echo-zorglubs.test')
        ->and(DB::table('media')->where('company_id', $id)->value('website'))->toBeNull();
    $meta = (string) DB::table('companies')->where('id', $id)->value('metadata');
    foreach (['Fictivus', 'fictivus', '@', '06 00', 'Écho des'] as $interdit) {
        expect($meta)->not->toContain($interdit)
            ->and($r['sortie'])->not->toContain($interdit);
    }
    expect($r['sortie'])->not->toContain('echo-zorglubs');
});

test('paris.fr pour PARIS LIVE REFUSE (liste noire, sans lecture) ; un site au nom d un autre refuse — rien n est efface', function () {
    $paris = pvsFiche($this->espace, 'https://www.paris.fr', 'PARIS LIVE');
    $cholet = pvsFiche($this->espace, 'https://cholet-zz.test', 'ZZ CHOLET REPRO SERVICES');
    pvsReseau(['cholet-zz.test' => pvsPage('Ville de Cholet — site officiel')]);

    pvsLancer(['--appliquer' => true, '--sans-recherche' => true]);

    Http::assertNotSent(fn (Request $q): bool => str_contains($q->url(), 'paris.fr'));
    Http::assertSent(fn (Request $q): bool => $q->url() === 'https://cholet-zz.test/');
    expect(pvsMarqueur($paris))->toMatchArray(['statut' => SiteMedia::NON_CONFORME, 'motif' => SiteMedia::MOTIF_LISTE_NOIRE])
        ->and(pvsMarqueur($cholet))->toMatchArray(['statut' => SiteMedia::NON_CONFORME, 'motif' => SiteMedia::MOTIF_NOM])
        ->and(SiteMedia::estVerifie($paris))->toBeFalse()
        ->and(SiteMedia::estVerifie($cholet))->toBeFalse()
        // marquer, pas supprimer : les valeurs restent
        ->and(DB::table('companies')->where('id', $paris)->value('website'))->toBe('https://www.paris.fr')
        ->and(DB::table('companies')->where('id', $cholet)->value('website'))->toBe('https://cholet-zz.test');
});

test('domaine PARTAGE par plus de N fiches sans correspondance : non conforme d office, jamais lu', function () {
    $ids = [];
    foreach (['ZZ ALPHA ROMEO', 'ZZ BRAVO TANGO', 'ZZ CHARLIE MIKE', 'ZZ DELTA ECHO'] as $nom) {
        $ids[] = pvsFiche($this->espace, 'https://portail-zz.test', $nom);
    }
    // La page porterait pourtant chacun des noms : seul le partage décide.
    pvsReseau(['portail-zz.test' => pvsPage('Alpha Romeo Bravo Tango Charlie Mike Delta Echo')]);

    pvsLancer(['--appliquer' => true, '--sans-recherche' => true, '--partage-max' => '3']);

    Http::assertNotSent(fn (Request $q): bool => str_contains($q->url(), 'portail-zz.test'));
    foreach ($ids as $id) {
        expect(pvsMarqueur($id))->toMatchArray(['statut' => SiteMedia::NON_CONFORME, 'motif' => SiteMedia::MOTIF_PARTAGE]);
    }
});

test('candidat TROUVE et verifie : ecrit seulement dans les lignes sans site — jamais d ecrasement', function () {
    $id = pvsFiche($this->espace, null, 'LE ZORGLUB ZZ', ['website' => 'https://ancien-zz.test/']);
    $vide = pvsMedia($this->espace, $id, ['name' => 'LE ZORGLUB ZZ', 'website' => null]);
    $pleine = (int) DB::table('media')->where('company_id', $id)->where('id', '<>', $vide)->value('id');
    pvsReseau([
        'ancien-zz.test' => pvsPage('Tout autre chose'),
        'zorglubzz.fr' => pvsPage('Le Zorglub ZZ — hebdomadaire'),
    ]);

    $r = pvsLancer(['--appliquer' => true]);

    Http::assertSent(fn (Request $q): bool => $q->url() === 'https://zorglubzz.fr/');
    expect(pvsMarqueur($id))->toMatchArray(['statut' => SiteMedia::TROUVE_VERIFIE, 'url' => 'https://zorglubzz.fr/'])
        ->and(SiteMedia::estVerifie($id))->toBeTrue()
        ->and(DB::table('media')->where('id', $vide)->value('website'))->toBe('https://zorglubzz.fr/')
        ->and(DB::table('media')->where('id', $vide)->value('website_method'))->toBe(SiteMedia::METHODE)
        // la ligne qui avait un site (faux) le garde : jamais écrasé
        ->and(DB::table('media')->where('id', $pleine)->value('website'))->toBe('https://ancien-zz.test/')
        ->and(DB::table('companies')->where('id', $id)->value('website'))->toBeNull()
        ->and((int) preg_match('/\|\s*sites_trouves\s*\|\s*1\s*\|/', $r['sortie']))->toBe(1);
});

test('un candidat deja site d une AUTRE fiche n est jamais pris', function () {
    $autre = pvsFiche($this->espace, 'https://zorglubzz.fr', 'ZZ AUTRE FICHE');
    $id = pvsFiche($this->espace, null, 'LE ZORGLUB ZZ');
    pvsReseau(['zorglubzz.fr' => pvsPage('Le Zorglub ZZ'), 'lezorglubzz.fr' => pvsPage('Ce domaine est à vendre')]);

    pvsLancer(['--appliquer' => true]);

    // zorglubzz.fr porte le nom, mais c'est le site (lu, non conforme) d'une autre fiche.
    expect(pvsMarqueur($id)['statut'])->toBe(SiteMedia::SANS_SITE)
        ->and(DB::table('media')->where('company_id', $id)->value('website'))->toBeNull()
        ->and(pvsMarqueur($autre)['statut'])->toBe(SiteMedia::NON_CONFORME);
});

test('essai a blanc PAR DEFAUT : les sites sont lus, RIEN n est ecrit', function () {
    $verifie = pvsFiche($this->espace, 'https://echo-zorglubs.test', "ZZ L'ÉCHO DES ZORGLUBS");
    $trouve = pvsFiche($this->espace, null, 'LE ZORGLUB ZZ');
    pvsReseau(['echo-zorglubs.test' => pvsPage("L'Écho des Zorglubs"), 'zorglubzz.fr' => pvsPage('Le Zorglub ZZ — hebdomadaire')]);

    $r = pvsLancer();

    Http::assertSent(fn (Request $q): bool => $q->url() === 'https://echo-zorglubs.test/');
    expect($r['sortie'])->toContain('[À BLANC]')
        ->and(pvsMarqueur($verifie))->toBeNull()
        ->and(pvsMarqueur($trouve))->toBeNull()
        ->and(DB::table('media')->where('company_id', $trouve)->value('website'))->toBeNull()
        ->and((int) preg_match('/\|\s*sites_trouves\s*\|\s*1\s*\|/', $r['sortie']))->toBe(1);
});

test('idempotente : une fiche deja verifiee n est pas relue ; --reverifier relit sans reecrire', function () {
    $id = pvsFiche($this->espace, 'https://echo-zorglubs.test', "ZZ L'ÉCHO DES ZORGLUBS");
    pvsReseau(['echo-zorglubs.test' => pvsPage("L'Écho des Zorglubs")]);
    pvsLancer(['--appliquer' => true]);
    $avant = pvsMarqueur($id);

    $n = count(Http::recorded());
    pvsLancer(['--appliquer' => true]);
    expect(count(Http::recorded()))->toBe($n);

    $r = pvsLancer(['--appliquer' => true, '--reverifier' => true]);
    expect(pvsMarqueur($id))->toBe($avant)
        ->and((int) preg_match('/\|\s*marqueurs_inchanges\s*\|\s*1\s*\|/', $r['sortie']))->toBe(1);
});

// ── Relecture A09 de #273 ──────────────────────────────────────────────────

test('une 404 personnalisee qui reprend le nom n est PAS un site verifie', function () {
    $id = pvsFiche($this->espace, 'https://zorglubzz.fr', 'LE ZORGLUB ZZ');
    Http::fake([
        'https://zorglubzz.fr/robots.txt' => Http::response('', 404),
        'https://zorglubzz.fr/' => Http::response(pvsPage('zorglubzz.fr — Le Zorglub ZZ, hebdomadaire : page introuvable'), 404, ['Content-Type' => 'text/html']),
        '*' => Http::response('', 404),
    ]);

    pvsLancer(['--appliquer' => true, '--sans-recherche' => true]);

    expect(pvsMarqueur($id)['statut'])->toBe(SiteMedia::INJOIGNABLE)
        ->and(SiteMedia::estVerifie($id))->toBeFalse();
});

test('un candidat qui REDIRIGE vers un autre domaine ou un parkeur n est jamais pris ni ecrit', function () {
    $id = pvsFiche($this->espace, null, 'LE ZORGLUB ZZ');
    $page = Http::response(pvsPage('Le Zorglub ZZ — hebdomadaire'), 200, ['Content-Type' => 'text/html']);
    Http::fake([
        'https://lezorglubzz.fr/robots.txt' => Http::response('', 404),
        'https://lezorglubzz.fr/' => Http::response('', 301, ['Location' => 'https://sedoparking.com/lezorglubzz.fr']),
        'https://sedoparking.com/*' => $page,
        'https://zorglubzz.fr/robots.txt' => Http::response('', 404),
        'https://zorglubzz.fr/' => Http::response('', 302, ['Location' => 'https://autre-media-zz.test/']),
        'https://autre-media-zz.test/*' => $page,
        '*' => Http::response('', 404),
    ]);

    $r = pvsLancer(['--appliquer' => true]);

    Http::assertSent(fn (Request $q): bool => str_starts_with($q->url(), 'https://autre-media-zz.test/'));
    expect(pvsMarqueur($id)['statut'])->toBe(SiteMedia::SANS_SITE)
        ->and(DB::table('media')->where('company_id', $id)->value('website'))->toBeNull()
        ->and((int) preg_match('/\|\s*sites_trouves\s*\|\s*0\s*\|/', $r['sortie']))->toBe(1);
});

test('une page de PARKING qui recopie l adresse n est jamais prise (« zorglubzz.fr — domaine a vendre »)', function () {
    $id = pvsFiche($this->espace, null, 'LE ZORGLUB ZZ');
    pvsReseau([
        'lezorglubzz.fr' => pvsPage('lezorglubzz.fr'),
        'zorglubzz.fr' => pvsPage('zorglubzz.fr — domaine à vendre', 'Le Zorglub ZZ hebdomadaire'),
    ]);

    pvsLancer(['--appliquer' => true]);

    expect(pvsMarqueur($id)['statut'])->toBe(SiteMedia::SANS_SITE)
        ->and(DB::table('media')->where('company_id', $id)->value('website'))->toBeNull();
});

test('un seul mot distinctif sans indice de media : a-confirmer, jamais fiable ni ecrit', function () {
    $existant = pvsFiche($this->espace, 'https://zorglub-station.test', 'LA ZORGLUB');
    $cherche = pvsFiche($this->espace, null, 'LE ZORGLUB ZZ');
    pvsReseau([
        'zorglub-station.test' => pvsPage('La Zorglub — station de ski', 'Forfaits, pistes'),
        'zorglubzz.fr' => pvsPage('Le Zorglub ZZ', 'Nos chambres, nos tarifs'),
    ]);

    pvsLancer(['--appliquer' => true]);

    expect(pvsMarqueur($existant)['statut'])->toBe(SiteMedia::A_CONFIRMER)
        ->and(SiteMedia::estVerifie($existant))->toBeFalse()
        ->and(pvsMarqueur($cherche)['statut'])->toBe(SiteMedia::SANS_SITE)
        ->and(DB::table('media')->where('company_id', $cherche)->value('website'))->toBeNull();
});

test('homonymes : jamais le site d une ligne media d un AUTRE espace', function () {
    $autre = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $autre, 'slug' => 'zz-autre-espace', 'name' => 'ZZ autre', 'settings' => '{}',
        'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('media')->insert([
        'workspace_id' => $autre, 'company_id' => null, 'name' => 'LE ZORGLUB ZZ', 'website' => 'https://homonyme-zz.test/',
        'media_type' => 'presse_mensuel', 'media_family' => 'editorial', 'source' => 'wikidata',
        'enrich_status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);
    pvsFiche($this->espace, null, 'LE ZORGLUB ZZ');
    pvsReseau(['homonyme-zz.test' => pvsPage('Le Zorglub ZZ — hebdomadaire')]);

    pvsLancer(['--appliquer' => true]);

    Http::assertNotSent(fn (Request $q): bool => str_contains($q->url(), 'homonyme-zz.test'));
});

test('--depuis-id et --limite bornent les fiches verifiees', function () {
    $un = pvsFiche($this->espace, null, 'ZZ ALPHA ROMEO');
    $deux = pvsFiche($this->espace, null, 'ZZ BRAVO TANGO');
    $trois = pvsFiche($this->espace, null, 'ZZ CHARLIE MIKE');
    pvsReseau([]);

    pvsLancer(['--appliquer' => true, '--sans-recherche' => true, '--depuis-id' => (string) $deux, '--limite' => '1']);

    expect(pvsMarqueur($un))->toBeNull()
        ->and(pvsMarqueur($deux)['statut'])->toBe(SiteMedia::SANS_SITE)
        ->and(pvsMarqueur($trois))->toBeNull();
});
