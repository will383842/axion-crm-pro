<?php

/**
 * GARDE — LES DEUX SITES JUMEAUX DE C19-001 QUI N'AVAIENT PAS ETE PORTES.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE L'ENUMERATION A MONTRE, ET CE QU'ELLE NE POUVAIT PAS MONTRER
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `SsrfCompletudeTest` relit `app/Services` et `app/Jobs` et prouve que TOUTE
 * emission HTTP a URL non constante passe desormais par `SsrfGuard`. Sur le
 * chemin « PHP emet la requete », le constat C19-001 est donc ferme : les trois
 * services nommes sont branches, et il n'y en avait pas de quatrieme.
 *
 * Mais une URL issue de la donnee ne fait pas que se FAIRE APPELER. Elle se
 * fait aussi ECRIRE et TRANSMETTRE, et le commit 9389121 a garde deux de ces
 * trois points d'entree sans porter le troisieme :
 *
 *   GARDES (DomainFinderService, commit 9389121)
 *     - resultat de l'API Brave  -> `SsrfGuard::check($url)`  (ligne 475)
 *     - href extrait de Pages Jaunes -> `SsrfGuard::check($href)` (ligne 526)
 *
 *   NON GARDES (mesure du 2026-08-20, avant ce fichier)
 *     - `WaterfallOrchestrator::step3d_google_places()` ecrit
 *       `places.websiteUri` — une valeur fournie par l'API Google Places, donc
 *       par le proprietaire de la fiche — directement dans `companies.website`.
 *       `grep -n SsrfGuard app/Services/Waterfall/WaterfallOrchestrator.php`
 *       -> 0 resultat.
 *     - `DispatchScrapeJob::handle()` depose `target_url` (= `companies.website`,
 *       cf. `WaterfallOrchestrator` ligne 418) sur la liste Redis
 *       `axion:scrape:<source>`, lue par un worker Playwright cote Node.
 *       `grep -n SsrfGuard app/Jobs/DispatchScrapeJob.php` -> 0 resultat.
 *
 * C'est le patron A-011 du depot dans sa forme habituelle : le correctif est
 * ecrit, il n'a pas ete porte au jumeau. L'audit nommait UN site ; il y en avait
 * deux de plus.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ⚠️ HONNETETE SUR LA GRAVITE — a lire avant de citer ce fichier
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Aucun de ces deux points n'est, AUJOURD'HUI, un trou ouvert de bout en bout :
 *
 *  - Une URL interne ecrite dans `companies.website` par Google Places est
 *    ensuite refusee au moment du fetch, par `MentionsLegalesScraperService` et
 *    par `DomainFinderService::revalidateBatch()` (commit 9389121). Ce qui reste
 *    est une adresse interne PERSISTEE, affichee dans la fiche et exportee en
 *    CSV — pas une lecture de l'infrastructure.
 *  - `workers/src/scrapers/website.playwright.ts` ligne 23 appelle bien
 *    `ensureSsrf(req.target_url)`. La cible interne est donc refusee cote Node.
 *
 * Ce qui est repare ici est donc de la DEFENSE EN PROFONDEUR, et il faut le
 * dire ainsi. Elle vaut quand meme, pour une raison mesurable dans ce depot :
 * la garde aval est A UN SEUL ENDROIT a chaque fois, dans un autre fichier —
 * pour le scrape, dans un AUTRE LANGAGE. `pages-jaunes.playwright.ts` et
 * `google-search.playwright.ts` n'appellent pas `ensureSsrf` (ils n'utilisent
 * pas `target_url` aujourd'hui : mesure faite, `grep -n target_url workers/src`
 * -> 3 resultats, aucun chez eux). Le jour ou l'un d'eux lira `target_url`, la
 * garde PHP posee ici sera la seule en travers du chemin.
 *
 * REGLE DE MESURE : aucun `expect()->toContain($aiguille, $message)` — le 2e
 * argument de `toContain()` est VARIADIQUE en Pest.
 */

use App\Jobs\DispatchScrapeJob;
use App\Models\Company;
use App\Models\Workspace;
use App\Services\Waterfall\WaterfallOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Les quatre familles d'adresses internes. Toutes LITTERALES : la garde ne fait
 * alors aucune resolution DNS et le verdict ne depend ni du reseau ni du
 * resolveur du banc. Une garde de securite qui rougit quand le CI n'a pas de
 * DNS mesure l'atelier, pas le produit.
 */
function jumeauxAdressesInternes(): array
{
    return [
        'metadonnees AWS/GCP' => 'http://169.254.169.254/latest/meta-data/',
        'boucle locale' => 'http://127.0.0.1:6379/',
        'prive 10/8' => 'http://10.1.2.3/interne',
        'prive 192.168/16' => 'http://192.168.1.10/admin',
    ];
}

/** IP publique (example.com), litterale : temoin « adresse acceptable ». */
const JUMEAUX_URL_PUBLIQUE = 'https://93.184.216.34/';

// ═══════════════════════════════════════════════════════════════════════════
// 1. DispatchScrapeJob — la target_url passee au worker Node
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Remplace la connexion Redis par un espion. On ne veut ni Redis reel sur le
 * banc, ni un test qui passerait au vert parce que Redis est tombe : l'espion
 * ENREGISTRE, et le temoin ci-dessous verifie qu'il enregistre bien quelque
 * chose dans le cas nominal.
 *
 * @return array<int, array{0: string, 1: string}> recueil, par reference
 */
function jumeauxEspionRedis(array &$recueil): void
{
    $lien = Mockery::mock();
    $lien->shouldReceive('lpush')->andReturnUsing(function ($file, $charge) use (&$recueil) {
        $recueil[] = [(string) $file, (string) $charge];

        return 1;
    });

    // C18-011 : le pont passe desormais par une connexion Redis SANS prefixe
    // (`scrape_bridge`). Cet espion-ci reste pose au niveau de la FACADE : il
    // voit l'argument PHP, jamais le prefixe que le client Redis colle ensuite.
    // C'est `tests/Unit/Scraping/PontRedisPrefixeTest.php` qui mesure la clef
    // reellement emise sur le fil — cet espion en est structurellement aveugle.
    Redis::shouldReceive('connection')->with(DispatchScrapeJob::CONNEXION_REDIS)->andReturn($lien);
}

test('C19-001 — DispatchScrapeJob ne met JAMAIS une adresse interne en file', function () {
    foreach (jumeauxAdressesInternes() as $libelle => $url) {
        $pousse = [];
        jumeauxEspionRedis($pousse);

        (new DispatchScrapeJob(companyId: 42, source: 'website', context: [], targetUrl: $url))->handle();

        expect($pousse)->toBe(
            [],
            "DispatchScrapeJob a depose {$url} ({$libelle}) sur `axion:scrape:website`. Cette "
            . 'valeur vient de `companies.website` (cf. WaterfallOrchestrator ligne 418) : une '
            . 'ligne empoisonnee en base suffit a faire ouvrir cette adresse par le navigateur '
            . 'Playwright du worker Node, hors de portee de toute garde PHP.',
        );

        Mockery::close();
    }
});

test('TEMOIN — DispatchScrapeJob met TOUJOURS en file une adresse publique', function () {
    $pousse = [];
    jumeauxEspionRedis($pousse);

    (new DispatchScrapeJob(
        companyId: 42,
        source: 'website',
        context: ['siren' => '123456789'],
        targetUrl: JUMEAUX_URL_PUBLIQUE,
    ))->handle();

    expect($pousse)->toHaveCount(
        1,
        'Aucun scrape n\'est plus mis en file pour une adresse PUBLIQUE : la garde a ete branchee '
        . 'trop large et la chaine de scrape est morte. Un refus generalise n\'est pas un correctif. '
        . '(Et sans ce temoin, le test ci-dessus serait vert meme si `handle()` ne poussait plus '
        . 'jamais rien.)',
    );
    expect($pousse[0][0])->toBe('axion:scrape:website');

    // ⚠️ On DECODE la charge au lieu d'y chercher une sous-chaine : `json_encode()`
    // echappe les barres obliques (`https:\/\/93.184.216.34\/`), et un
    // `assertStringContainsString('https://…')` rougirait eternellement pour une
    // raison qui n'a rien a voir avec le SSRF. Piege paye, consigne.
    $charge = json_decode($pousse[0][1], true, 512, JSON_THROW_ON_ERROR);
    expect($charge['target_url'])->toBe(JUMEAUX_URL_PUBLIQUE);
});

test('TEMOIN — DispatchScrapeJob sans target_url fonctionne toujours', function () {
    // `LaunchZoneScrapingJob` ligne 404 dispatche avec `targetUrl: null` : la
    // garde ne doit surtout pas transformer « pas d'URL » en « URL refusee ».
    $pousse = [];
    jumeauxEspionRedis($pousse);

    (new DispatchScrapeJob(companyId: 7, source: 'google-maps', context: [], targetUrl: null))->handle();

    expect($pousse)->toHaveCount(
        1,
        'Un dispatch SANS target_url (le cas de LaunchZoneScrapingJob) est desormais refuse : la '
        . 'garde traite l\'absence d\'URL comme une URL invalide et casse le scrape de zone.',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. WaterfallOrchestrator — le websiteUri rendu par l'API Google Places
// ═══════════════════════════════════════════════════════════════════════════

function jumeauxWorkspace(): Workspace
{
    return Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'ws-' . Str::random(6),
        'name' => 'WS SSRF jumeaux',
    ]);
}

function jumeauxEntreprise(string $workspaceId, array $ajouts = []): Company
{
    return Company::create(array_merge([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspaceId,
        'siren' => str_pad((string) random_int(100000000, 999999999), 9, '0', STR_PAD_LEFT),
        'denomination' => 'Acme SA',
        'city_name' => 'Paris',
    ], $ajouts));
}

/** Invoque l'etape privee `step3d_google_places` (meme technique que GooglePlacesSmartSkipTest). */
function jumeauxInvoquerGooglePlaces(Company $entreprise): void
{
    $orchestrateur = app(WaterfallOrchestrator::class);
    $methode = (new ReflectionClass($orchestrateur))->getMethod('step3d_google_places');
    $methode->setAccessible(true);
    $methode->invoke($orchestrateur, $entreprise);
}

beforeEach(function () {
    // `MOCK_SCRAPERS` vaut `true` par defaut — c'est ce qui protege la
    // production d'appels FACTURES declenches par megarde. Un test qui exerce
    // le chemin reel doit en sortir explicitement, jamais en heriter en silence.
    Config::set('services.scrapers.mock', false);
    Config::set('services.google.places.api_key', 'fausse-cle');
    // On desactive le smart-skip H16 : sinon l'etape peut se terminer avant
    // meme d'appeler Google, et le test serait vert sans avoir rien mesure.
    Config::set('services.google.places.smart_skip', false);
    Cache::flush();
});

test('C19-001 — un websiteUri interne rendu par Google Places n est PAS ecrit en base', function () {
    $ws = jumeauxWorkspace();

    foreach (jumeauxAdressesInternes() as $libelle => $url) {
        Cache::flush(); // le client met le `place` en cache par requete
        Http::fake([
            'places.googleapis.com/*' => Http::response(['places' => [[
                'id' => 'place-' . md5($url),
                'websiteUri' => $url,
                'displayName' => ['text' => 'Acme SA'],
            ]]], 200),
        ]);

        $entreprise = jumeauxEntreprise($ws->id);
        jumeauxInvoquerGooglePlaces($entreprise);
        $entreprise->refresh();

        expect($entreprise->website)->toBeNull(
            "Google Places a rendu {$url} ({$libelle}) et la valeur a ete ecrite dans "
            . '`companies.website`. C\'est le site JUMEAU des deux entrees deja gardees dans '
            . 'DomainFinderService (resultat Brave ligne 475, href Pages Jaunes ligne 526) : une '
            . 'URL fournie par un tiers, persistee sans controle, puis affichee, exportee, et '
            . 'transmise comme `target_url` au worker Playwright.',
        );
    }
});

test('TEMOIN — un websiteUri PUBLIC rendu par Google Places est toujours ecrit en base', function () {
    $ws = jumeauxWorkspace();
    Http::fake([
        'places.googleapis.com/*' => Http::response(['places' => [[
            'id' => 'place-public',
            'websiteUri' => JUMEAUX_URL_PUBLIQUE,
            'internationalPhoneNumber' => '+33 1 23 45 67 89',
            'displayName' => ['text' => 'Acme SA'],
        ]]], 200),
    ]);

    $entreprise = jumeauxEntreprise($ws->id);
    jumeauxInvoquerGooglePlaces($entreprise);
    $entreprise->refresh();

    expect($entreprise->website)->toBe(
        JUMEAUX_URL_PUBLIQUE,
        'L\'enrichissement Google Places n\'ecrit plus le site meme pour une adresse PUBLIQUE : '
        . 'la garde a ete branchee trop large et l\'etape 3d est morte. Sans ce temoin, le test '
        . 'ci-dessus serait vert pour la simple raison que plus rien ne s\'ecrit.',
    );
    expect($entreprise->phone)->toBe(
        '+33 1 23 45 67 89',
        'Le reste de l\'enrichissement (telephone) ne passe plus : le refus SSRF a fait sauter '
        . 'toute l\'etape au lieu du seul champ concerne.',
    );
});
