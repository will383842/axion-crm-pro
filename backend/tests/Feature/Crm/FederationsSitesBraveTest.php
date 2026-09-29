<?php

/**
 * LE SITE DES FÉDÉRATIONS SANS CONTACT, PAR BRAVE — pour zéro euro (29/09).
 *
 * Gardes de `crm:federations:trouver-sites` :
 *  - le plafond mensuel est STRICT : la requête N+1 n'est jamais envoyée, et
 *    une requête en échec est comptée ;
 *  - un site n'est retenu que s'il appartient à l'organisme — le site
 *    NATIONAL est rejeté pour une section départementale ;
 *  - rien n'est écrit hors des quatre colonnes du site ;
 *  - l'ordre de priorité (national → régional → départemental, avec
 *    salariés d'abord) et le périmètre (sans site, sans contact, protégées) ;
 *  - l'essai à blanc n'envoie rien, la reprise ne recherche pas deux fois ;
 *  - la clé n'apparaît ni à l'écran ni au journal.
 *
 * Brave et les sites sont SIMULÉS (`Http::fake`, `preventStrayRequests`) :
 * aucun appel réseau réel. Domaines en `.test` : `SsrfGuard` les tolère sur
 * le banc (cf. `SsrfGuard::requireDnsResolution()`).
 */

use App\Console\Commands\CrmFederationsTrouverSites;
use App\Crm\Brave\QuotaBrave;
use App\Crm\Federations\AppartenanceSite;
use App\Crm\FichesProtegees;
use App\Models\Company;
use App\Models\Workspace;
use App\Services\Domain\DomainFinderService;
use Illuminate\Console\Scheduling\Event as EvenementPlanifie;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const FSB_CLE = 'CLE-BRAVE-SECRETE-0123456789';

beforeEach(function () {
    Config::set('services.brave.api_key', FSB_CLE);
    Config::set('crm.brave.quota_mensuel', 100);
    Http::preventStrayRequests();
});

function fsbEspace(): string
{
    $slug = 'fsb-' . Str::lower(Str::random(8));
    Config::set('crm.ingest.business_workspace', $slug);
    $id = (string) Str::uuid();
    Workspace::create(['id' => $id, 'slug' => $slug, 'name' => 'Espace sites brave']);

    return $id;
}

/**
 * Une fiche fédération protégée, sans site par défaut.
 *
 * @param  array<string, mixed>  $fiche
 * @param  array<string, mixed>  $federation
 */
function fsbFederation(string $espace, array $fiche = [], array $federation = [], bool $protegee = true): int
{
    static $seq = 0;
    $seq++;

    $id = (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $espace,
        'siren' => str_pad((string) (930000000 + $seq), 9, '0', STR_PAD_LEFT),
        'entity_nature' => 'federation',
        'denomination' => 'ZZ Organisme ' . $seq,
        'created_at' => now()->subDays(10),
        'updated_at' => now()->subDays(10),
    ], $fiche));

    if ($protegee) {
        $tagId = DB::table('tags')->where('workspace_id', $espace)->where('slug', FichesProtegees::TAG_FEDERATIONS)->value('id')
            ?? DB::table('tags')->insertGetId([
                'workspace_id' => $espace, 'slug' => FichesProtegees::TAG_FEDERATIONS, 'name' => 'Collecte — fédérations',
                'category' => 'intent', 'kind' => 'auto', 'rules' => '{}', 'is_locked' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        DB::table('company_tag')->insert([
            'company_id' => $id, 'tag_id' => (int) $tagId, 'workspace_id' => $espace,
            'assigned_at' => now(), 'assigned_by' => 'auto-rule',
        ]);
    }

    DB::table('federations')->insert(array_merge([
        'company_id' => $id, 'workspace_id' => $espace, 'famille' => 'federation_syndicat_pro',
        'niveau' => 'national', 'pertinence' => 'haute', 'contactabilite' => 'aucun_contact',
    ], $federation));

    return $id;
}

/** Une page d'accueil de plus de 200 caractères. */
function fsbPage(string $contenu): string
{
    return '<html><body><h1>' . $contenu . '</h1><p>' . str_repeat('Actualites, adherents, agenda, publications. ', 8) . '</p></body></html>';
}

/**
 * Simule Brave (réponse choisie d'après la requête `q`) et les sites.
 *
 * @param  Closure(string): mixed  $brave  q => liste d'URL, ou une réponse / une exception
 * @param  array<string, string>  $sites  hôte => HTML de la page d'accueil
 */
function fsbSimuler(Closure $brave, array $sites = []): void
{
    $faux = [
        'api.search.brave.com/*' => function (Request $requete) use ($brave) {
            parse_str((string) parse_url($requete->url(), PHP_URL_QUERY), $params);
            $rendu = $brave((string) ($params['q'] ?? ''));
            if (is_array($rendu)) {
                return Http::response(['web' => ['results' => array_map(static fn (string $u): array => ['url' => $u], $rendu)]], 200);
            }

            return $rendu;
        },
    ];
    foreach ($sites as $hote => $html) {
        $faux[$hote . '/*'] = Http::response($html, 200);
    }
    Http::fake($faux);
}

/** @return list<string> les requêtes `q` envoyées à Brave, dans l'ordre */
function fsbRequetesBrave(): array
{
    $q = [];
    foreach (Http::recorded() as [$requete]) {
        if (str_contains($requete->url(), 'api.search.brave.com')) {
            parse_str((string) parse_url($requete->url(), PHP_URL_QUERY), $params);
            $q[] = (string) ($params['q'] ?? '');
        }
    }

    return $q;
}

/** @param  array<string, mixed>  $options */
function fsbLancer(array $options = []): int
{
    return Artisan::call(CrmFederationsTrouverSites::SIGNATURE_PLANIFIEE, array_merge(['--pause-ms' => 0], $options));
}

// ═════════════════════════════════════════════════════════════════════════════
// 1. LE PLAFOND MENSUEL EST STRICT
// ═════════════════════════════════════════════════════════════════════════════

test('plafond strict : la requete N+1 n est JAMAIS envoyee, et le passage le dit', function () {
    $espace = fsbEspace();
    Config::set('crm.brave.quota_mensuel', 3);
    $ids = [];
    for ($i = 0; $i < 5; $i++) {
        $ids[] = fsbFederation($espace);
    }
    fsbSimuler(fn (string $q): array => []);

    expect(fsbLancer())->toBe(0);

    expect(fsbRequetesBrave())->toHaveCount(3)
        ->and(app(QuotaBrave::class)->consommees())->toBe(3)
        ->and(Artisan::output())->toContain('Plafond mensuel atteint (fédérations 3 / 900, total 3 / 3)')
        ->and(DB::table('companies')->whereIn('id', $ids)->where('website_method', 'brave-federations')->count())->toBe(3)
        ->and(DB::table('companies')->whereIn('id', $ids)->whereNull('website_method')->count())->toBe(2);

    // Un second passage le même mois : plus RIEN ne part.
    expect(fsbLancer())->toBe(0);
    expect(fsbRequetesBrave())->toHaveCount(3)
        ->and(app(QuotaBrave::class)->consommees())->toBe(3);
});

test('plafond strict : deja atteint par un autre passage du mois, aucune requete ne part', function () {
    $espace = fsbEspace();
    Config::set('crm.brave.quota_mensuel', 2);
    fsbFederation($espace);
    expect(app(QuotaBrave::class)->reserver(QuotaBrave::FEDERATIONS))->toBeTrue()
        ->and(app(QuotaBrave::class)->reserver(QuotaBrave::FEDERATIONS))->toBeTrue()
        ->and(app(QuotaBrave::class)->reserver(QuotaBrave::FEDERATIONS))->toBeFalse();
    fsbSimuler(fn (string $q): array => []);

    fsbLancer();

    expect(fsbRequetesBrave())->toBe([])
        ->and(app(QuotaBrave::class)->consommees())->toBe(2);
});

test('sous-quota federations : il arrete le passage meme sous le plafond global', function () {
    $espace = fsbEspace();
    Config::set('crm.brave.quota_mensuel', 100);
    Config::set('crm.brave.quotas.federations', 2);
    for ($i = 0; $i < 4; $i++) {
        fsbFederation($espace);
    }
    fsbSimuler(fn (string $q): array => []);

    fsbLancer();

    expect(fsbRequetesBrave())->toHaveCount(2)
        ->and(app(QuotaBrave::class)->consommees(QuotaBrave::FEDERATIONS))->toBe(2)
        ->and(Artisan::output())->toContain('Plafond mensuel atteint (fédérations 2 / 2, total 2 / 100)');
});

test('EN BASE, tous chemins confondus : enrichissement puis federations ne depassent jamais le plafond global', function () {
    $espace = fsbEspace();
    Config::set('crm.brave.quota_mensuel', 3);
    Config::set('crm.brave.quotas.federations', 5);
    Config::set('crm.brave.quotas.enrichissement', 2);
    for ($i = 0; $i < 3; $i++) {
        fsbFederation($espace);
    }
    Http::fake([
        'api.search.brave.com/*' => Http::response(['web' => ['results' => [['url' => 'https://site-trouve.test/']]]], 200),
        'site-trouve.test/*' => Http::response('rien', 200),
    ]);
    $entreprise = new Company(['denomination' => 'Zzqx Entreprise', 'city_name' => 'Nulle-Part']);

    // L'enrichissement prend son sous-quota (2), la 3e fois il est refusé.
    $finder = app(DomainFinderService::class);
    $trouves = [$finder->find($entreprise), $finder->find($entreprise)];
    expect(fsbRequetesBrave())->toHaveCount(2)
        ->and($trouves)->toBe(['https://site-trouve.test/', 'https://site-trouve.test/']);

    // Les fédérations n'ont plus qu'UNE requête sous le plafond global (3).
    fsbLancer();
    $finder->find($entreprise);

    expect(fsbRequetesBrave())->toHaveCount(3)
        ->and(app(QuotaBrave::class)->consommees())->toBe(3)
        ->and(app(QuotaBrave::class)->consommees(QuotaBrave::ENRICHISSEMENT))->toBe(2)
        ->and(app(QuotaBrave::class)->consommees(QuotaBrave::FEDERATIONS))->toBe(1)
        ->and((int) DB::table('brave_quota_mensuel')->sum('requetes'))->toBe(3);
});

test('EN BASE, enrichissement a 0 (defaut) : find() n envoie rien et n ecrit aucune ligne de quota', function () {
    Config::set('crm.brave.quotas.enrichissement', 0);
    Http::fake();

    app(DomainFinderService::class)->find(new Company(['denomination' => 'Zzqx Entreprise Zero', 'city_name' => 'Nulle-Part']));

    expect(fsbRequetesBrave())->toBe([])
        ->and(DB::table('brave_quota_mensuel')->count())->toBe(0);
});

test('une requete en echec est COMPTEE, et sa fiche reste a reprendre', function () {
    $espace = fsbEspace();
    $id = fsbFederation($espace);
    fsbSimuler(fn (string $q) => Http::response('indisponible', 503));

    fsbLancer();

    expect(fsbRequetesBrave())->toHaveCount(1)
        ->and(app(QuotaBrave::class)->consommees())->toBe(1)
        ->and(DB::table('companies')->where('id', $id)->value('website_method'))->toBeNull();
});

test('Brave refuse (429) : le passage S ARRETE au lieu de bruler le credit', function () {
    $espace = fsbEspace();
    fsbFederation($espace);
    fsbFederation($espace);
    fsbSimuler(fn (string $q) => Http::response('trop', 429));

    expect(fsbLancer())->toBe(1);

    expect(fsbRequetesBrave())->toHaveCount(1)
        ->and(Artisan::output())->toContain('HTTP 429');
});

// ═════════════════════════════════════════════════════════════════════════════
// 2. L'APPARTENANCE — le site national n'est pas celui d'une section
// ═════════════════════════════════════════════════════════════════════════════

test('section departementale : le site NATIONAL est rejete, celui qui cite le departement est retenu', function () {
    $espace = fsbEspace();
    $section = fsbFederation($espace, ['denomination' => 'FFB DU RHONE', 'department_code' => '69', 'city' => 'Lyon'], [
        'niveau' => 'departemental', 'sigle' => 'FFB', 'nom_developpe' => 'Federation francaise du batiment',
    ]);
    fsbSimuler(
        fn (string $q): array => ['https://ffb-national.test/federation', 'https://ffb-section.test/'],
        [
            'ffb-national.test' => fsbPage('Fédération Française du Bâtiment (FFB) — 33 avenue Kléber, 75016 Paris'),
            'ffb-section.test' => fsbPage('FFB — Fédération Française du Bâtiment, section de Lyon — 23 rue de la République, 69002 Lyon'),
        ],
    );

    fsbLancer();

    expect(DB::table('companies')->where('id', $section)->value('website'))->toBe('https://ffb-section.test/');
});

test('section departementale : seul le site national en resultat -> AUCUN site pose', function () {
    $espace = fsbEspace();
    $section = fsbFederation($espace, ['denomination' => 'FFB DU RHONE', 'department_code' => '69'], [
        'niveau' => 'departemental', 'sigle' => 'FFB', 'nom_developpe' => 'Federation francaise du batiment',
    ]);
    fsbSimuler(
        fn (string $q): array => ['https://ffb-national.test/'],
        ['ffb-national.test' => fsbPage('Fédération Française du Bâtiment (FFB) — 33 avenue Kléber, 75016 Paris')],
    );

    fsbLancer();

    $fiche = DB::table('companies')->where('id', $section)->first();
    expect($fiche->website)->toBeNull()
        ->and($fiche->website_status)->toBe('not_found')
        ->and($fiche->website_method)->toBe('brave-federations');
});

test('un site deja pose sur une AUTRE federation (la tete de reseau) n est pas repris', function () {
    $espace = fsbEspace();
    fsbFederation($espace, ['website' => 'https://www.capeb-reseau.test/'], ['contactabilite' => 'email_verifie']);
    $antenne = fsbFederation($espace, ['city' => 'Paris'], ['sigle' => 'CAPEB', 'nom_developpe' => 'Confederation artisanat petites entreprises batiment']);
    fsbSimuler(
        fn (string $q): array => ['https://capeb-reseau.test/'],
        ['capeb-reseau.test' => fsbPage('CAPEB — Confédération de l\'artisanat et des petites entreprises du bâtiment')],
    );

    fsbLancer();

    expect(DB::table('companies')->where('id', $antenne)->value('website'))->toBeNull();
});

test('les annuaires et reseaux sociaux ne sont jamais retenus, meme s ils citent tout', function () {
    $espace = fsbEspace();
    $id = fsbFederation($espace, [], ['sigle' => 'FNTP', 'nom_developpe' => 'Federation nationale des travaux publics']);
    $page = fsbPage('FNTP Fédération nationale des travaux publics');
    fsbSimuler(
        fn (string $q): array => ['https://www.societe.com/societe/fntp.html', 'https://fr.linkedin.com/company/fntp', 'https://annuaire-pro.test/fntp'],
        ['www.societe.com' => $page, 'fr.linkedin.com' => $page, 'annuaire-pro.test' => $page],
    );

    fsbLancer();

    expect(DB::table('companies')->where('id', $id)->value('website'))->toBeNull();
    foreach (Http::recorded() as [$requete]) {
        expect($requete->url())->toContain('api.search.brave.com');
    }
});

test('AppartenanceSite : nom SANS sigle rejete, SIREN accepte, departement par son NOM', function () {
    $regle = app(AppartenanceSite::class);
    $fiche = (object) [
        'siren' => '775671356', 'denomination' => 'FNSEA', 'nom_developpe' => 'Federation nationale des syndicats d exploitants agricoles',
        'sigle' => 'FNSEA', 'niveau' => 'national', 'department_code' => '75', 'departement_nom' => 'Paris', 'city' => 'Paris', 'city_name' => null,
    ];

    // Le nom distinctif sans le sigle : non.
    expect($regle->verifier(fsbPage('Syndicats des exploitants agricoles de France'), $fiche))->toBeFalse()
        // Nom distinctif ET sigle : oui.
        ->and($regle->verifier(fsbPage('FNSEA — Fédération nationale des syndicats d\'exploitants agricoles'), $fiche))->toBeTrue()
        // Le SIREN seul (et deux mots du nom pour la règle commune) : oui.
        ->and($regle->verifier(fsbPage('Federation des exploitants — SIREN 775 671 356'), $fiche))->toBeTrue()
        // Les mots génériques ne distinguent personne.
        ->and($regle->motsDistinctifs('Fédération nationale des syndicats d\'exploitants agricoles'))->toBe(['exploitants', 'agricoles']);

    $section = (object) [
        'siren' => null, 'denomination' => 'FDSEA', 'nom_developpe' => 'Federation departementale des syndicats d exploitants agricoles',
        'sigle' => 'FDSEA', 'niveau' => 'departemental', 'department_code' => '69', 'departement_nom' => 'Rhône', 'city' => null, 'city_name' => null,
    ];
    $sansDepartement = 'FDSEA — Fédération départementale des syndicats d\'exploitants agricoles';
    expect($regle->verifier(fsbPage($sansDepartement), $section))->toBeFalse()
        ->and($regle->verifier(fsbPage($sansDepartement . ' du Rhône'), $section))->toBeTrue()
        ->and($regle->verifier(fsbPage($sansDepartement . ' — 69100 Villeurbanne'), $section))->toBeTrue();
});

// ═════════════════════════════════════════════════════════════════════════════
// 3. RIEN N'EST ÉCRIT HORS DU SITE
// ═════════════════════════════════════════════════════════════════════════════

test('seules website, website_status, website_method et website_checked_at changent — ni contact, ni etiquette, ni federation', function () {
    $espace = fsbEspace();
    $id = fsbFederation($espace, [
        'city' => 'Paris', 'phone' => '+33100000000', 'address' => '1 rue de Test', 'effectif_range' => '12',
        'metadata' => json_encode(['note' => 'intacte']),
    ], ['sigle' => 'UNSA', 'nom_developpe' => 'Union nationale ameublement', 'contactabilite' => 'site_ou_linkedin_seulement']);
    DB::table('contacts')->insert([
        'workspace_id' => $espace, 'company_id' => $id, 'last_name' => 'Fictif',
        'legal_basis' => 'legitimate_interest_b2b', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $avant = (array) DB::table('companies')->where('id', $id)->first();
    $federationAvant = (array) DB::table('federations')->where('company_id', $id)->first();
    $contactsAvant = DB::table('contacts')->where('company_id', $id)->get()->map(fn ($c) => (array) $c)->all();
    $etiquettesAvant = DB::table('company_tag')->where('company_id', $id)->get()->map(fn ($t) => (array) $t)->all();

    fsbSimuler(
        fn (string $q): array => ['https://unsa-ameublement.test/accueil'],
        ['unsa-ameublement.test' => fsbPage('UNSA — Union nationale de l\'ameublement')],
    );
    fsbLancer();

    $apres = (array) DB::table('companies')->where('id', $id)->first();
    $changees = array_keys(array_filter($apres, fn ($v, $k) => $v !== ($avant[$k] ?? null), ARRAY_FILTER_USE_BOTH));
    sort($changees);

    expect($apres['website'])->toBe('https://unsa-ameublement.test/')
        ->and($apres['website_status'])->toBe('found')
        ->and($apres['website_method'])->toBe('brave-federations')
        // `quality_score` (déclencheur) et `quality_badge` (colonne générée) : DÉRIVÉES.
        ->and(array_values(array_diff($changees, ['quality_score', 'quality_badge'])))->toBe(['website', 'website_checked_at', 'website_method', 'website_status'])
        ->and((array) DB::table('federations')->where('company_id', $id)->first())->toBe($federationAvant)
        ->and(DB::table('contacts')->where('company_id', $id)->get()->map(fn ($c) => (array) $c)->all())->toBe($contactsAvant)
        ->and(DB::table('company_tag')->where('company_id', $id)->get()->map(fn ($t) => (array) $t)->all())->toBe($etiquettesAvant);
});

test('updated_at n est pas touche — temoin : toute autre ecriture le remet a jour', function () {
    // Test SÉPARÉ, sans contact : insérer un contact recalcule le score de la
    // fiche, donc réécrit `updated_at` à `now()` — l'heure de DÉBUT de la
    // transaction du test, la même que celle qu'un UPDATE fautif poserait.
    // La comparaison ne mesurerait alors plus rien (mutation survivante).
    $ancienne = '2024-01-15 10:00:00';
    $espace = fsbEspace();
    $id = fsbFederation($espace, ['city' => 'Paris', 'updated_at' => $ancienne], ['sigle' => 'UNSA', 'nom_developpe' => 'Union nationale ameublement']);
    $temoin = fsbFederation($espace, ['updated_at' => $ancienne], ['contactabilite' => 'email_verifie']);
    fsbSimuler(
        fn (string $q): array => ['https://unsa-ameublement.test/'],
        ['unsa-ameublement.test' => fsbPage('UNSA — Union nationale de l\'ameublement')],
    );

    // Témoin AVANT la commande : son `SET LOCAL` vit jusqu'à la fin de la
    // transaction ENGLOBANTE — celle du test ici (la commande n'ouvre qu'un
    // point de sauvegarde), la sienne propre en production.
    DB::table('companies')->where('id', $temoin)->update(['website_status' => 'not_found']);
    fsbLancer();

    expect(DB::table('companies')->where('id', $id)->value('website'))->toBe('https://unsa-ameublement.test/')
        ->and(substr((string) DB::table('companies')->where('id', $id)->value('updated_at'), 0, 19))->toBe($ancienne)
        ->and(substr((string) DB::table('companies')->where('id', $temoin)->value('updated_at'), 0, 19))->not->toBe($ancienne);
});

// ═════════════════════════════════════════════════════════════════════════════
// 4. LA PRIORITÉ ET LE PÉRIMÈTRE
// ═════════════════════════════════════════════════════════════════════════════

test('priorite : national, regional, departemental — avec salaries d abord ; hors perimetre jamais cherche', function () {
    $espace = fsbEspace();
    fsbFederation($espace, ['denomination' => 'ZZ DEPT SALARIES', 'effectif_range' => '11'], ['niveau' => 'departemental']);
    fsbFederation($espace, ['denomination' => 'ZZ NATIONAL SANS SALARIE', 'effectif_range' => 'NN'], ['niveau' => 'national']);
    fsbFederation($espace, ['denomination' => 'ZZ REGIONAL SALARIES', 'effectif_range' => '03'], ['niveau' => 'regional', 'contactabilite' => 'site_ou_linkedin_seulement']);
    fsbFederation($espace, ['denomination' => 'ZZ NATIONAL SALARIES', 'effectif_range' => '21'], ['niveau' => 'national']);
    fsbFederation($espace, ['denomination' => 'ZZ REGIONAL ZERO', 'effectif_range' => '00'], ['niveau' => 'regional']);
    // Hors périmètre :
    fsbFederation($espace, ['denomination' => 'ZZ HORS LOCAL', 'effectif_range' => '21'], ['niveau' => 'local']);
    fsbFederation($espace, ['denomination' => 'ZZ HORS JOIGNABLE'], ['contactabilite' => 'email_verifie']);
    fsbFederation($espace, ['denomination' => 'ZZ HORS TELEPHONE'], ['contactabilite' => 'telephone_seulement']);
    fsbFederation($espace, ['denomination' => 'ZZ HORS AVEC SITE', 'website' => 'https://deja.test/']);
    fsbFederation($espace, ['denomination' => 'ZZ HORS NON PROTEGEE'], [], protegee: false);
    fsbFederation($espace, ['denomination' => 'ZZ HORS DEJA CHERCHEE', 'website_status' => 'not_found', 'website_method' => 'brave-federations']);
    fsbFederation($espace, ['denomination' => 'ZZ HORS CORBEILLE', 'deleted_at' => now()]);
    fsbSimuler(fn (string $q): array => []);

    fsbLancer();

    $ordre = array_map(static fn (string $q): string => (string) Str::before(Str::after($q, 'ZZ '), ' -site'), fsbRequetesBrave());
    expect($ordre)->toBe(['NATIONAL SALARIES', 'NATIONAL SANS SALARIE', 'REGIONAL SALARIES', 'REGIONAL ZERO', 'DEPT SALARIES']);
});

test('reprise : une fiche cherchee n est pas recherchee ; --reessayer reprend les introuvables', function () {
    $espace = fsbEspace();
    fsbFederation($espace);
    fsbSimuler(fn (string $q): array => []);

    fsbLancer();
    fsbLancer();
    expect(fsbRequetesBrave())->toHaveCount(1);

    fsbLancer(['--reessayer' => true]);
    expect(fsbRequetesBrave())->toHaveCount(2);
});

test('--limite borne le nombre de fiches, donc de requetes', function () {
    $espace = fsbEspace();
    for ($i = 0; $i < 4; $i++) {
        fsbFederation($espace);
    }
    fsbSimuler(fn (string $q): array => []);

    fsbLancer(['--limite' => 2]);

    expect(fsbRequetesBrave())->toHaveCount(2)
        ->and(app(QuotaBrave::class)->consommees())->toBe(2);
});

// ═════════════════════════════════════════════════════════════════════════════
// 5. L'ESSAI À BLANC, LA CLÉ, LE JOURNAL
// ═════════════════════════════════════════════════════════════════════════════

test('--dry-run : AUCUNE requete, aucune ecriture, le quota intact — et le nombre de fiches ciblees', function () {
    $espace = fsbEspace();
    Config::set('services.brave.api_key', null); // l'essai à blanc n'a pas besoin de la clé
    $a = fsbFederation($espace, ['effectif_range' => '11']);
    fsbFederation($espace, [], ['niveau' => 'departemental']);
    fsbFederation($espace, [], ['niveau' => 'local']);
    Http::fake();

    expect(fsbLancer(['--dry-run' => true]))->toBe(0);

    Http::assertNothingSent();
    expect(Artisan::output())->toContain('Fiches ciblées : 2')
        ->and(app(QuotaBrave::class)->consommees())->toBe(0)
        ->and(DB::table('companies')->where('id', $a)->value('website_method'))->toBeNull();
});

test('sans cle : refus, aucune requete', function () {
    $espace = fsbEspace();
    Config::set('services.brave.api_key', '');
    fsbFederation($espace);
    Http::fake();

    expect(fsbLancer())->toBe(1);
    Http::assertNothingSent();
    expect(app(QuotaBrave::class)->consommees())->toBe(0);
});

test('la cle part dans l en-tete, JAMAIS a l ecran, au journal ni dans l URL ; aucun nom d organisme non plus', function () {
    $espace = fsbEspace();
    fsbFederation($espace, ['denomination' => 'ZZ NOM CONFIDENTIEL UN']);
    fsbFederation($espace, ['denomination' => 'ZZ NOM CONFIDENTIEL DEUX']);
    fsbFederation($espace, ['denomination' => 'ZZ NOM CONFIDENTIEL TROIS']);
    $journal = [];
    Log::listen(function (MessageLogged $e) use (&$journal): void {
        $journal[] = $e->message . ' ' . json_encode($e->context);
    });
    $n = 0;
    fsbSimuler(function (string $q) use (&$n) {
        $n++;

        return match ($n) {
            1 => throw new ConnectionException('cURL error 28 https://api.search.brave.com/res/v1/web/search?q=' . urlencode($q)),
            2 => Http::response(['message' => 'erreur'], 500),
            default => Http::response(['error' => 'cle refusee'], 401),
        };
    });

    fsbLancer();

    $sortie = Artisan::output() . ' ' . implode(' ', $journal);
    Http::assertSent(fn (Request $r): bool => $r->hasHeader('X-Subscription-Token', FSB_CLE));
    expect($journal)->not->toBe([])
        ->and($sortie)->not->toContain(FSB_CLE)
        ->and($sortie)->not->toContain('CONFIDENTIEL');
    foreach (Http::recorded() as [$requete]) {
        expect($requete->url())->not->toContain(FSB_CLE);
    }
});

// ═════════════════════════════════════════════════════════════════════════════
// 6. LA PLANIFICATION MENSUELLE, FERMÉE PAR DÉFAUT
// ═════════════════════════════════════════════════════════════════════════════

test('planification mensuelle : fermee par defaut, ouverte par CRM_BRAVE_FEDERATIONS_PLANIFIEE', function () {
    $evenements = array_values(array_filter(
        app(Schedule::class)->events(),
        fn (EvenementPlanifie $e): bool => str_contains((string) $e->command, CrmFederationsTrouverSites::SIGNATURE_PLANIFIEE),
    ));

    expect($evenements)->toHaveCount(1);
    $evenement = $evenements[0];
    expect($evenement->expression)->toBe('30 5 3 * *');

    Config::set('crm.brave.federations_planifiee', false);
    expect($evenement->filtersPass(app()))->toBeFalse();

    Config::set('crm.brave.federations_planifiee', true);
    expect($evenement->filtersPass(app()))->toBeTrue();
});
