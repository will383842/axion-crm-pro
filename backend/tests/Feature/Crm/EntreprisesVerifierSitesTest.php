<?php

/**
 * VÉRIFIER LES SITES DEVINÉS PAR LOTS (lot N6, 03/10/2026) —
 * `crm:entreprises:verifier-sites`.
 *
 * Ce que ces gardes tiennent :
 *  1. preuve FORTE seulement : le SIREN de l'entreprise sur la page d'accueil
 *     ou la page de mentions légales → `verifie` (le marqueur que
 *     `SiteFiable` lit déjà) ; deux mots du nom sans SIREN → `non-conforme`,
 *     le site reste NON VÉRIFIÉ ; injoignable et robots.txt marqués tels ;
 *  2. seule la clé `metadata.site_entreprise` est écrite : aucune autre
 *     colonne, ni `updated_at`, ni aucune ligne SUPPRIMÉE (comptage
 *     avant = après, affiché au rapport) ;
 *  3. arrêt propre à l'heure (`--jusqua`, heure de Paris), puis REPRISE
 *     EXACTE au curseur persistant ;
 *  4. refus de démarrer hors fenêtre (mardi → samedi, 08:00-19:00, jamais
 *     les 1er, 2 et 3 du mois) sauf `--forcer` ;
 *  5. essai à blanc : rien d'écrit, curseur compris ; `--audience` ;
 *  6. sélection servie par l'index partiel SOUS `axion_app` ;
 *  7. jamais inscrite au calendrier ;
 *  8. réserves de relecture de #314 : redirection vers un autre domaine
 *     jamais une preuve (R1), purge de la mémoire sans perte (R2), à blanc
 *     sans aucun UPDATE ni transaction (R3), marqueur identique non réécrit,
 *     comptage borné à la plage parcourue (R5), `--jusqua` après minuit
 *     (R8), plancher du délai par domaine, droits du rôle applicatif.
 *
 * AUCUN appel réseau : `Http::fake`. Fixtures FICTIVES (dépôt public) :
 * noms « ZZ », SIREN 94xxxxxxx, domaines en `.test`.
 */

use App\Crm\Presse\SiteMedia;
use App\Crm\Sites\CurseurTraitement;
use App\Crm\Sites\SiteFiable;
use App\Crm\Sites\VerificationSite;
use App\Services\Domain\DomainFinderService;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionBeginning;
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

/** Mardi 6 octobre 2026, 10:00 à Paris : dans la fenêtre. */
const EVS_MARDI = '2026-10-06 10:00:00';

beforeEach(function () {
    config(['crm.ingest.business_workspace' => 'axion-ia']);
    $this->espace = (string) (DB::table('workspaces')->where('slug', 'axion-ia')->value('id') ?? Str::uuid());
    if (! DB::table('workspaces')->where('id', $this->espace)->exists()) {
        DB::table('workspaces')->insert([
            'id' => $this->espace, 'slug' => 'axion-ia', 'name' => 'Axion-IA', 'settings' => '{}',
            'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    Carbon::setTestNow(Carbon::parse(EVS_MARDI, 'Europe/Paris'));
    // Le délai par domaine a un plancher (1 000 ms) : on n'attend pas pour de vrai.
    Sleep::fake();
});

// UN seul `afterEach` par fichier : l'horloge est toujours rendue, et la
// connexion `axion_app` refermée si un test l'a ouverte.
afterEach(function () {
    Carbon::setTestNow();
    if (! array_key_exists('pgsql_app', DB::getConnections())) {
        return;
    }
    try {
        evsApp()->statement('RESET enable_seqscan');
        evsApp()->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    } catch (Throwable) {
        // Connexion déjà perdue.
    }
    evsApp()->disconnect();
});

/** Une fiche au site DEVINÉ (`guess`), comme les 824 000 de la production. */
function evsFiche(string $espace, string $hote, string $nom, array $attrs = []): int
{
    return F::fiche($espace, $nom, $attrs + [
        'website' => 'https://' . $hote . '/',
        'website_method' => 'guess',
        'city_name' => 'ZZVILLE',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function evsSiren(int $id): string
{
    return (string) DB::table('companies')->where('id', $id)->value('siren');
}

function evsPage(string $titre, string $corps = '', string $pied = ''): string
{
    return '<!doctype html><html><head><meta charset="utf-8"><title>' . $titre . '</title></head><body><h1>'
        . $titre . '</h1><p>' . $corps . '</p><footer>' . $pied . '</footer></body></html>';
}

/**
 * Faux réseau : robots.txt absent (404) sauf indiqué, une page par URL ;
 * tout le reste en 404.
 *
 * @param  array<string, string>  $pages  URL complète → HTML
 * @param  array<string, string>  $robots  hôte → contenu de robots.txt
 */
function evsReseau(array $pages, array $robots = [], ?Closure $espion = null): void
{
    Http::fake(function (Request $q) use ($pages, $robots, $espion) {
        if ($espion !== null) {
            $espion($q);
        }
        $url = $q->url();
        $hote = (string) parse_url($url, PHP_URL_HOST);
        if (str_ends_with($url, '/robots.txt')) {
            return isset($robots[$hote]) ? Http::response($robots[$hote], 200, ['Content-Type' => 'text/plain']) : Http::response('', 404);
        }
        if (isset($pages[$url])) {
            return Http::response($pages[$url], 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        return Http::response('', 404);
    });
}

/** @return array{code: int, sortie: string} */
function evsLancer(array $options = []): array
{
    $code = Artisan::call('crm:entreprises:verifier-sites', $options);

    return ['code' => $code, 'sortie' => Artisan::output()];
}

/** @return array<string, mixed>|null */
function evsMarqueur(int $id): ?array
{
    $meta = json_decode((string) DB::table('companies')->where('id', $id)->value('metadata'), true);

    return is_array($meta) && is_array($meta[SiteFiable::CLE] ?? null) ? $meta[SiteFiable::CLE] : null;
}

/** Empreinte de la ligne entière SAUF `metadata` (seule colonne permise). */
function evsEmpreinte(int $id): string
{
    return (string) DB::selectOne(
        "SELECT md5((to_jsonb(c) - 'metadata')::text) AS e FROM companies c WHERE id = ?",
        [$id],
    )->e;
}

function evsEstNonVerifie(int $id): bool
{
    $l = DB::table('companies')->where('id', $id)->first(['website_method', 'metadata']);

    return SiteFiable::estNonVerifie($l->website_method, $l->metadata);
}

test('page d accueil portant le SIREN → vérifié ; seule metadata.site_entreprise est écrite', function () {
    $id = evsFiche($this->espace, 'zz-siren.test', 'ZZ BOULANGERIE FICTIVE');
    $avant = evsEmpreinte($id);
    $siren = evsSiren($id);
    evsReseau(['https://zz-siren.test/' => evsPage('Accueil', 'Pain et viennoiseries.', 'SARL au capital de 1 000 € — RCS ZZVILLE ' . chunk_split($siren, 3, ' '))]);

    $r = evsLancer();

    expect($r['code'])->toBe(0)
        ->and(evsMarqueur($id))->toMatchArray([
            'statut' => SiteMedia::VERIFIE,
            'url' => 'https://zz-siren.test/',
            'preuve' => VerificationSite::PREUVE_ACCUEIL,
            'v' => VerificationSite::VERSION,
        ])
        ->and(array_keys((array) evsMarqueur($id)))->toEqualCanonicalizing(['statut', 'url', 'preuve', 'le', 'v'])
        ->and(evsEstNonVerifie($id))->toBeFalse()
        ->and(SiteFiable::fichesNonVerifiees($this->espace)->count())->toBe(0)
        // aucune autre colonne, ni `updated_at`
        ->and(evsEmpreinte($id))->toBe($avant);
    // Le SIREN n'est ni stocké dans le marqueur ni affiché.
    expect(json_encode(evsMarqueur($id)))->not->toContain($siren)
        ->and($r['sortie'])->not->toContain($siren)
        ->and($r['sortie'])->not->toContain('zz-siren');
});

test('SIREN absent de l accueil mais présent dans les mentions légales liées → vérifié (preuve mentions)', function () {
    $id = evsFiche($this->espace, 'zz-mentions.test', 'ZZ ATELIER FICTIF');
    evsReseau([
        'https://zz-mentions.test/' => evsPage('ZZ Atelier Fictif', 'Bienvenue.', '<a href="/infos/mentions-legales.html">Mentions légales</a>'),
        'https://zz-mentions.test/infos/mentions-legales.html' => evsPage('Mentions légales', 'Éditeur : ZZ Atelier Fictif, SIREN ' . evsSiren($id)),
    ]);

    evsLancer();

    expect(evsMarqueur($id))->toMatchArray(['statut' => SiteMedia::VERIFIE, 'preuve' => VerificationSite::PREUVE_MENTIONS])
        ->and(evsEstNonVerifie($id))->toBeFalse();
});

test('page avec deux mots du nom, sans SIREN ni code postal → NON vérifié (non conforme), rien d effacé', function () {
    $id = evsFiche($this->espace, 'zz-deuxmots.test', 'ZZ PLOMBERIE MARTINEZ');
    $avant = evsEmpreinte($id);
    evsReseau(['https://zz-deuxmots.test/' => evsPage('Plomberie Martinez', str_repeat('Plomberie Martinez, dépannage rapide et devis gratuit. ', 10))]);

    $r = evsLancer();

    expect($r['code'])->toBe(0)
        ->and(evsMarqueur($id))->toMatchArray(['statut' => SiteMedia::NON_CONFORME, 'url' => 'https://zz-deuxmots.test/'])
        ->and(evsEstNonVerifie($id))->toBeTrue()
        ->and(DB::table('companies')->where('id', $id)->value('website'))->toBe('https://zz-deuxmots.test/')
        ->and(evsEmpreinte($id))->toBe($avant);
    // Les mentions légales ont été tentées (adresse par défaut), une seule fois.
    Http::assertSentCount(3); // robots.txt (une fois par origine), accueil, /mentions-legales
});

test('un SIREN collé à d autres chiffres (téléphone, autre numéro) ne prouve rien', function () {
    $id = evsFiche($this->espace, 'zz-colle.test', 'ZZ GARAGE FICTIF');
    evsReseau(['https://zz-colle.test/' => evsPage('Garage', 'Réf. 12' . evsSiren($id))]);

    evsLancer();

    expect(evsMarqueur($id)['statut'] ?? null)->toBe(SiteMedia::NON_CONFORME);
});

test('robots.txt qui interdit → ignoré : aucune page demandée, marqué robots-interdit, non vérifié', function () {
    $id = evsFiche($this->espace, 'zz-robots.test', 'ZZ ROBOTS FICTIFS');
    $pages = [];
    evsReseau(
        ['https://zz-robots.test/' => evsPage('Accueil', 'SIREN ' . evsSiren($id))],
        ['zz-robots.test' => "User-agent: *\nDisallow: /\n"],
        function (Request $q) use (&$pages): void {
            $pages[] = $q->url();
        },
    );

    $r = evsLancer();

    expect($pages)->toBe(['https://zz-robots.test/robots.txt'])
        ->and(evsMarqueur($id)['statut'] ?? null)->toBe(SiteMedia::ROBOTS_INTERDIT)
        ->and(evsEstNonVerifie($id))->toBeTrue()
        ->and($r['sortie'])->toMatch('/ignorés \(robots\.txt\)\D+1\b/u');
});

test('site injoignable (accueil en 503, ou connexion impossible) → marqué injoignable, non vérifié', function () {
    $panne = evsFiche($this->espace, 'zz-panne.test', 'ZZ PANNE FICTIVE');
    $coupe = evsFiche($this->espace, 'zz-coupe.test', 'ZZ COUPE FICTIVE');
    Http::fake([
        'https://zz-panne.test/robots.txt' => Http::response('', 404),
        'https://zz-panne.test/' => Http::response('SIREN ' . evsSiren($panne), 503),
        'https://zz-coupe.test/*' => Http::failedConnection(),
    ]);

    $r = evsLancer();

    expect(evsMarqueur($panne)['statut'] ?? null)->toBe(SiteMedia::INJOIGNABLE)
        ->and(evsMarqueur($coupe)['statut'] ?? null)->toBe(SiteMedia::INJOIGNABLE)
        ->and(evsEstNonVerifie($panne))->toBeTrue()
        ->and(evsEstNonVerifie($coupe))->toBeTrue()
        ->and($r['sortie'])->toMatch('/injoignables\D+2\b/u');
});

test('arrêt propre à l heure fixée (--jusqua), puis reprise EXACTE au curseur persistant', function () {
    $ids = [];
    $pages = [];
    foreach (['a', 'b', 'c', 'd'] as $l) {
        $ids[$l] = evsFiche($this->espace, "zz-{$l}.test", "ZZ FICHE {$l}");
        $pages["https://zz-{$l}.test/"] = evsPage('Accueil', 'SIREN ' . evsSiren($ids[$l]));
    }
    Carbon::setTestNow(Carbon::parse('2026-10-06 18:58:00', 'Europe/Paris'));
    $demandes = [];
    // Pendant la lecture du 1er paquet, l'horloge passe 19:00.
    evsReseau($pages, [], function (Request $q) use (&$demandes): void {
        $demandes[] = (string) parse_url($q->url(), PHP_URL_HOST);
        if ($q->url() === 'https://zz-b.test/') {
            Carbon::setTestNow(Carbon::parse('2026-10-06 19:00:30', 'Europe/Paris'));
        }
    });

    $r1 = evsLancer(['--paquet' => '2', '--jusqua' => '19:00']);

    expect($r1['code'])->toBe(0)
        ->and($r1['sortie'])->toContain('Arrêt à 19:00')
        ->and(evsMarqueur($ids['a'])['statut'] ?? null)->toBe(SiteMedia::VERIFIE)
        ->and(evsMarqueur($ids['b'])['statut'] ?? null)->toBe(SiteMedia::VERIFIE)
        ->and(evsMarqueur($ids['c']))->toBeNull()
        ->and(evsMarqueur($ids['d']))->toBeNull()
        ->and(CurseurTraitement::lire($this->espace, VerificationSite::cleCurseur(null)))->toBe($ids['b']);

    // Le lendemain matin : reprise au curseur, A et B ne sont pas relus.
    Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00', 'Europe/Paris'));
    $demandes = [];
    $r2 = evsLancer(['--paquet' => '2']);

    expect($r2['code'])->toBe(0)
        ->and(array_values(array_unique($demandes)))->toEqualCanonicalizing(['zz-c.test', 'zz-d.test'])
        ->and(evsMarqueur($ids['c'])['statut'] ?? null)->toBe(SiteMedia::VERIFIE)
        ->and(evsMarqueur($ids['d'])['statut'] ?? null)->toBe(SiteMedia::VERIFIE)
        ->and(CurseurTraitement::lire($this->espace, VerificationSite::cleCurseur(null)))->toBe($ids['d']);
});

test('refus de démarrer hors fenêtre — dimanche, lundi, avant 8 h, après 19 h, les 1er, 2 et 3 du mois — sauf --forcer', function (string $quand) {
    $id = evsFiche($this->espace, 'zz-fenetre.test', 'ZZ FENETRE');
    evsReseau(['https://zz-fenetre.test/' => evsPage('Accueil', 'SIREN ' . evsSiren($id))]);
    Carbon::setTestNow(Carbon::parse($quand, 'Europe/Paris'));

    $refus = evsLancer();

    expect($refus['code'])->toBe(1)
        ->and($refus['sortie'])->toContain('hors fenêtre')
        ->and(evsMarqueur($id))->toBeNull();
    Http::assertNothingSent();

    $force = evsLancer(['--forcer' => true]);
    expect($force['code'])->toBe(0)
        ->and(evsMarqueur($id)['statut'] ?? null)->toBe(SiteMedia::VERIFIE);
})->with([
    'dimanche' => ['2026-10-11 10:00:00'],
    'lundi' => ['2026-10-12 10:00:00'],
    'mardi 7 h 59' => ['2026-10-13 07:59:00'],
    'mardi 19 h' => ['2026-10-13 19:00:00'],
    'samedi 3 du mois' => ['2026-10-03 10:00:00'],
    'jeudi 1er du mois' => ['2026-10-01 10:00:00'],
    'vendredi 2 du mois' => ['2026-10-02 10:00:00'],
]);

test('fenêtre : les règles pures', function () {
    $p = static fn (string $t) => Carbon::parse($t, 'Europe/Paris');

    expect(VerificationSite::horsFenetre($p('2026-10-06 08:00:00')))->toBeNull()   // mardi
        ->and(VerificationSite::horsFenetre($p('2026-10-10 18:59:00')))->toBeNull() // samedi
        ->and(VerificationSite::horsFenetre($p('2026-10-04 12:00:00')))->not->toBeNull() // dimanche
        ->and(VerificationSite::horsFenetre($p('2026-11-03 12:00:00')))->not->toBeNull() // mardi 3
        // 10:00 UTC un mardi = 12:00 à Paris : l'heure est celle de PARIS.
        ->and(VerificationSite::horsFenetre(Carbon::parse('2026-10-06 17:30:00', 'UTC')))->not->toBeNull();
});

test('--dry-run : les sites sont lus, RIEN n est écrit — ni marqueur, ni curseur — et le compte de lignes est égal', function () {
    $id = evsFiche($this->espace, 'zz-blanc.test', 'ZZ A BLANC');
    $avant = DB::selectOne("SELECT md5(string_agg(c::text, '|' ORDER BY id)) AS e, count(*) AS n FROM companies c")->e;
    evsReseau(['https://zz-blanc.test/' => evsPage('Accueil', 'SIREN ' . evsSiren($id))]);

    $r = evsLancer(['--dry-run' => true, '--limite' => '200']);

    expect($r['code'])->toBe(0)
        ->and($r['sortie'])->toContain('À BLANC')
        ->and($r['sortie'])->toMatch('/vérifiés\D+1\b/u')
        ->and(DB::selectOne("SELECT md5(string_agg(c::text, '|' ORDER BY id)) AS e FROM companies c")->e)->toBe($avant)
        ->and(DB::table('curseurs_traitements')->count())->toBe(0);
    Http::assertSent(fn (Request $q): bool => $q->url() === 'https://zz-blanc.test/');
});

test('aucune ligne supprimée : comptage avant = après affiché, fiches vérifiées, non conformes et corbeille intactes', function () {
    $ok = evsFiche($this->espace, 'zz-ok.test', 'ZZ OK');
    $ko = evsFiche($this->espace, 'zz-ko.test', 'ZZ KO');
    $corbeille = evsFiche($this->espace, 'zz-corbeille.test', 'ZZ CORBEILLE', ['deleted_at' => now()]);
    $brave = evsFiche($this->espace, 'zz-brave.test', 'ZZ BRAVE', ['website_method' => 'brave']);
    $contact = F::contact($this->espace, $ko, 'Zoé', 'ZZ-Un');
    $avant = [DB::table('companies')->count(), DB::table('contacts')->count()];
    evsReseau([
        'https://zz-ok.test/' => evsPage('Accueil', 'SIREN ' . evsSiren($ok)),
        'https://zz-ko.test/' => evsPage('ZZ KO', 'Rien.'),
    ]);

    $r = evsLancer();

    expect([DB::table('companies')->count(), DB::table('contacts')->count()])->toBe($avant)
        ->and(DB::table('contacts')->where('id', $contact)->exists())->toBeTrue()
        ->and(evsMarqueur($corbeille))->toBeNull()
        ->and(evsMarqueur($brave))->toBeNull()
        ->and($r['sortie'])->toMatch('/lignes avant\D+(\d+)\b/u')
        ->and($r['sortie'])->toContain('identique')
        ->and($r['sortie'])->toMatch('/fiches vivantes avant\D+(\d+)/u');
    preg_match('/lignes avant\D+(\d+)/u', $r['sortie'], $a);
    preg_match('/lignes après\D+(\d+)/u', $r['sortie'], $b);
    expect($a[1])->toBe($b[1]);
    Http::assertNotSent(fn (Request $q): bool => str_contains($q->url(), 'zz-corbeille') || str_contains($q->url(), 'zz-brave'));
});

test('--audience : seules les fiches de l audience sont lues, avec leur propre curseur', function () {
    $dedans = evsFiche($this->espace, 'zz-dedans.test', 'ZZ DEDANS');
    $dehors = evsFiche($this->espace, 'zz-dehors.test', 'ZZ DEHORS');
    $audience = (int) DB::table('email_audiences')->insertGetId([
        'workspace_id' => $this->espace, 'name' => 'ZZ audience', 'criteria' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('audience_members')->insert(['audience_id' => $audience, 'company_id' => $dedans, 'workspace_id' => $this->espace]);
    evsReseau([
        'https://zz-dedans.test/' => evsPage('Accueil', 'SIREN ' . evsSiren($dedans)),
        'https://zz-dehors.test/' => evsPage('Accueil', 'SIREN ' . evsSiren($dehors)),
    ]);

    evsLancer(['--audience' => (string) $audience]);

    expect(evsMarqueur($dedans)['statut'] ?? null)->toBe(SiteMedia::VERIFIE)
        ->and(evsMarqueur($dehors))->toBeNull()
        ->and(CurseurTraitement::lire($this->espace, VerificationSite::cleCurseur($audience)))->toBe($dedans)
        ->and(CurseurTraitement::lire($this->espace, VerificationSite::cleCurseur(null)))->toBeNull();
    Http::assertNotSent(fn (Request $q): bool => str_contains($q->url(), 'zz-dehors'));

    expect(evsLancer(['--audience' => '999999'])['code'])->toBe(1);
});

test('adresse interne (garde SSRF) : jamais contactée, marquée injoignable', function () {
    $id = evsFiche($this->espace, '169.254.169.254', 'ZZ METADONNEES', ['website' => 'http://169.254.169.254/']);
    Http::fake(['*' => Http::response('SIREN ' . evsSiren($id), 200)]);

    evsLancer();

    Http::assertNothingSent();
    expect(evsMarqueur($id)['statut'] ?? null)->toBe(SiteMedia::INJOIGNABLE);
});

test('une fiche déjà vérifiée n est pas relue ; une option invalide est refusée', function () {
    $id = evsFiche($this->espace, 'zz-deja.test', 'ZZ DEJA', ['metadata' => json_encode([SiteFiable::CLE => ['statut' => 'verifie']])]);
    Http::fake();

    evsLancer();
    Http::assertNothingSent();
    expect(evsMarqueur($id))->toBe(['statut' => 'verifie']);

    expect(evsLancer(['--jusqua' => '25:00'])['code'])->toBe(1)
        ->and(evsLancer(['--limite' => '0'])['code'])->toBe(1)
        ->and(evsLancer(['--concurrence' => '20'])['code'])->toBe(1)
        // Plancher du délai par domaine : jamais deux requêtes d'un même domaine sans pause.
        ->and(evsLancer(['--delai-domaine-ms' => '0'])['code'])->toBe(1)
        ->and(evsLancer(['--delai-domaine-ms' => '999'])['code'])->toBe(1);
});

test('lien de mentions légales : préférence aux « mentions légales », jamais un autre site', function () {
    $base = 'https://www.zz-site.test/';
    $html = '<a href="/cgv">CGV</a> <a href="https://zz-autre.test/mentions-legales">Mentions légales</a>'
        . ' <a href="mailto:zz@zz-site.test">Mentions</a> <a href="#haut">Mentions légales</a>';

    expect(VerificationSite::lienMentions($html, $base))->toBe('https://www.zz-site.test/cgv')
        ->and(VerificationSite::lienMentions($html . '<a href="https://zz-site.test/legal/mentions-l%C3%A9gales">Infos</a>', $base))
        ->toBe('https://zz-site.test/legal/mentions-l%C3%A9gales')
        ->and(VerificationSite::lienMentions('<p>rien</p>', $base))->toBeNull()
        ->and(VerificationSite::mentionsParDefaut('zz-site.test/accueil'))->toBe('https://zz-site.test/mentions-legales');
});

test('SIREN prouvés par une page : SIRET et TVA acceptés, numéro collé refusé (règle de #305)', function () {
    $f = app(DomainFinderService::class);

    expect($f->sirensDansPage('<p>SIRET 940 000 001 00017</p>'))->toContain('940000001')
        ->and($f->sirensDansPage('<p>TVA FR12940000002</p>'))->toContain('940000002')
        ->and($f->sirensDansPage('<p>Tél. 0612 940000003</p>'))->not->toContain('940000003')
        ->and($f->sirensDansPage('<script>var s = "940000004";</script><p>rien</p>'))->toBe([]);
});

test('la commande n est PAS inscrite au calendrier (lancement à la main seulement)', function () {
    expect((string) file_get_contents(base_path('routes/console.php')))->not->toContain('crm:entreprises:verifier-sites');
});

// ── Réserves de relecture (#314) ────────────────────────────────────────────

/** Faux réseau avec redirections : URL → [code, corps, en-têtes]. */
function evsReseauBrut(array $reponses): void
{
    Http::fake(function (Request $q) use ($reponses) {
        $url = $q->url();
        if (str_ends_with($url, '/robots.txt')) {
            return Http::response('', 404);
        }
        if (isset($reponses[$url])) {
            [$code, $corps, $entetes] = $reponses[$url];

            return Http::response($corps, $code, $entetes);
        }

        return Http::response('', 404);
    });
}

test('R1 — accueil redirigé (301) vers un AUTRE domaine qui porte le SIREN → non conforme (redirection), jamais vérifié', function () {
    $id = evsFiche($this->espace, 'zz-devine.test', 'ZZ DEVINE FICTIF');
    evsReseauBrut([
        'https://zz-devine.test/' => [301, '', ['Location' => 'https://zz-annuaire.test/societe/zz-devine']],
        'https://zz-annuaire.test/societe/zz-devine' => [200, evsPage('Annuaire', 'ZZ DEVINE FICTIF — SIREN ' . evsSiren($id), '<a href="/mentions-legales">Mentions légales</a>'), ['Content-Type' => 'text/html']],
        'https://zz-annuaire.test/mentions-legales' => [200, evsPage('Mentions', 'SIREN ' . evsSiren($id)), ['Content-Type' => 'text/html']],
    ]);

    evsLancer();

    expect(evsMarqueur($id))->toMatchArray(['statut' => SiteMedia::NON_CONFORME, 'motif' => VerificationSite::MOTIF_REDIRECTION])
        ->and(evsEstNonVerifie($id))->toBeTrue();
    // Les mentions du site d'ARRIVÉE ne sont jamais lues.
    Http::assertNotSent(fn (Request $q): bool => $q->url() === 'https://zz-annuaire.test/mentions-legales');
});

test('R1 — mentions légales redirigées vers un autre domaine portant le SIREN → non conforme', function () {
    $id = evsFiche($this->espace, 'zz-mredir.test', 'ZZ MREDIR FICTIF');
    evsReseauBrut([
        'https://zz-mredir.test/' => [200, evsPage('Accueil', 'Bienvenue.', '<a href="/mentions-legales">Mentions légales</a>'), ['Content-Type' => 'text/html']],
        'https://zz-mredir.test/mentions-legales' => [302, '', ['Location' => 'https://zz-groupe.test/legal']],
        'https://zz-groupe.test/legal' => [200, evsPage('Légal', 'SIREN ' . evsSiren($id)), ['Content-Type' => 'text/html']],
    ]);

    evsLancer();

    expect(evsMarqueur($id))->toMatchArray(['statut' => SiteMedia::NON_CONFORME, 'motif' => VerificationSite::MOTIF_REDIRECTION])
        ->and(evsEstNonVerifie($id))->toBeTrue();
});

test('R1 — redirection sur le MÊME domaine (http → https, sans www → www) reste acceptée', function () {
    $id = evsFiche($this->espace, 'zz-meme.test', 'ZZ MEME FICTIF', ['website' => 'http://zz-meme.test/']);
    evsReseauBrut([
        'http://zz-meme.test/' => [301, '', ['Location' => 'https://www.zz-meme.test/accueil']],
        'https://www.zz-meme.test/accueil' => [200, evsPage('Accueil', 'SIREN ' . evsSiren($id)), ['Content-Type' => 'text/html']],
    ]);

    evsLancer();

    expect(evsMarqueur($id))->toMatchArray(['statut' => SiteMedia::VERIFIE, 'preuve' => VerificationSite::PREUVE_ACCUEIL]);
});

test('R1 — règle pure : arrivée sur un autre domaine enregistrable ou chez un parkeur refusée', function () {
    expect(VerificationSite::motifArrivee('https://zz-a.test/', 'https://www.zz-a.test/x'))->toBeNull()
        ->and(VerificationSite::motifArrivee('http://zz-a.test/', 'https://boutique.zz-a.test/'))->toBeNull()
        ->and(VerificationSite::motifArrivee('https://zz-a.test/', 'https://zz-b.test/'))->toBe(VerificationSite::MOTIF_REDIRECTION)
        ->and(VerificationSite::motifArrivee('https://zz-a.test/', 'https://zz-a.test.zz-b.test/'))->toBe(VerificationSite::MOTIF_REDIRECTION)
        ->and(VerificationSite::motifArrivee('https://zz-a.test/', 'pas une url'))->not->toBeNull();
});

test('R2 — la purge du mémoire n efface pas une adresse que le paquet en cours va lire', function () {
    config(['crm.verifier_sites.cache_max' => 2]);
    $a = evsFiche($this->espace, 'zz-commun.test', 'ZZ COMMUN A');
    $b = evsFiche($this->espace, 'zz-un.test', 'ZZ UN');
    $c = evsFiche($this->espace, 'zz-commun.test', 'ZZ COMMUN C');
    $d = evsFiche($this->espace, 'zz-deux.test', 'ZZ DEUX');
    evsReseau([
        'https://zz-commun.test/' => evsPage('Accueil', 'SIREN ' . evsSiren($a) . ' et ' . evsSiren($c)),
        'https://zz-un.test/' => evsPage('Accueil', 'SIREN ' . evsSiren($b)),
        'https://zz-deux.test/' => evsPage('Accueil', 'SIREN ' . evsSiren($d)),
    ]);

    $r = evsLancer(['--paquet' => '2']);

    foreach ([$a, $b, $c, $d] as $id) {
        expect(evsMarqueur($id)['statut'] ?? null)->toBe(SiteMedia::VERIFIE);
    }
    expect($r['sortie'])->toMatch('/erreurs \(non marquées\)\D+0\b/u');
});

test('R3 — --dry-run n exécute AUCUN UPDATE (même annulé) ni transaction d écriture : SELECT count(*) au même WHERE', function () {
    $id = evsFiche($this->espace, 'zz-blanc2.test', 'ZZ A BLANC DEUX');
    evsReseau(['https://zz-blanc2.test/' => evsPage('Accueil', 'SIREN ' . evsSiren($id))]);
    $requetes = [];
    $transactions = 0;
    DB::listen(function ($q) use (&$requetes): void {
        $requetes[] = strtolower(ltrim($q->sql));
    });
    DB::getEventDispatcher()?->listen(TransactionBeginning::class, function () use (&$transactions): void {
        $transactions++;
    });

    $r = evsLancer(['--dry-run' => true]);

    $ecritures = array_filter($requetes, fn (string $s): bool => preg_match('/^(update|insert|delete|set local)\b/', $s) === 1);
    expect($r['code'])->toBe(0)
        ->and($ecritures)->toBe([])
        ->and($transactions)->toBe(0)
        ->and(array_filter($requetes, fn (string $s): bool => str_contains($s, 'select count(*)') && str_contains($s, 'website = ?')))->not->toBe([])
        ->and($r['sortie'])->toMatch('/vérifiés\D+1\b/u')
        ->and(evsMarqueur($id))->toBeNull();
});

test('option R6 — un marqueur au même statut et à la même version n est pas réécrit ; une autre version l est', function () {
    $meme = evsFiche($this->espace, 'zz-inchange.test', 'ZZ INCHANGE', ['metadata' => json_encode([SiteFiable::CLE => [
        'statut' => SiteMedia::NON_CONFORME, 'url' => 'https://zz-inchange.test/', 'le' => '2026-01-01', 'v' => VerificationSite::VERSION,
    ]])]);
    $ancienne = evsFiche($this->espace, 'zz-ancienne.test', 'ZZ ANCIENNE', ['metadata' => json_encode([SiteFiable::CLE => [
        'statut' => SiteMedia::NON_CONFORME, 'url' => 'https://zz-ancienne.test/', 'le' => '2026-01-01', 'v' => 0,
    ]])]);
    evsReseau([
        'https://zz-inchange.test/' => evsPage('Accueil', 'Rien.'),
        'https://zz-ancienne.test/' => evsPage('Accueil', 'Rien.'),
    ]);
    $miseAJour = [];
    DB::listen(function ($q) use (&$miseAJour): void {
        if (preg_match('/^\s*update companies/i', $q->sql) === 1) {
            $miseAJour[] = $q->bindings;
        }
    });

    $r = evsLancer();

    expect(evsMarqueur($meme)['le'] ?? null)->toBe('2026-01-01')
        ->and(evsMarqueur($ancienne))->toMatchArray(['statut' => SiteMedia::NON_CONFORME, 'v' => VerificationSite::VERSION])
        ->and(evsMarqueur($ancienne)['le'] ?? null)->toBe('2026-10-06')
        ->and($miseAJour)->toHaveCount(1)
        ->and($r['sortie'])->toMatch('/inchangés[^\n]*?\D+1\b/u');
});

test('R5 — une fiche créée pendant le lancement ne fait pas échouer le comptage avant = après', function () {
    $id = evsFiche($this->espace, 'zz-pendant.test', 'ZZ PENDANT');
    $espace = $this->espace;
    $cree = false;
    evsReseau(['https://zz-pendant.test/' => evsPage('Accueil', 'SIREN ' . evsSiren($id))], [], function () use (&$cree, $espace): void {
        if (! $cree) {
            $cree = true;
            F::fiche($espace, 'ZZ SAISIE PENDANT LE LANCEMENT', ['website' => null]);
        }
    });

    $r = evsLancer();

    expect($r['code'])->toBe(0)
        ->and($r['sortie'])->toContain('identique')
        ->and(evsMarqueur($id)['statut'] ?? null)->toBe(SiteMedia::VERIFIE);
});

test('R8 — avec --forcer, --jusqua passe minuit (22:00 → 01:00 le lendemain)', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-06 22:00:00', 'Europe/Paris'));
    $id = evsFiche($this->espace, 'zz-nuit.test', 'ZZ NUIT');
    evsReseau(['https://zz-nuit.test/' => evsPage('Accueil', 'SIREN ' . evsSiren($id))]);

    $r = evsLancer(['--forcer' => true, '--jusqua' => '01:00']);

    expect($r['code'])->toBe(0)
        ->and($r['sortie'])->not->toContain('Arrêt à')
        ->and(evsMarqueur($id)['statut'] ?? null)->toBe(SiteMedia::VERIFIE);
});

test('R9 — le SIREN d une AUTRE entreprise ne prouve rien ; un SIREN écrit avec des points prouve', function () {
    $autre = evsFiche($this->espace, 'zz-autre-siren.test', 'ZZ AUTRE SIREN');
    $points = evsFiche($this->espace, 'zz-points.test', 'ZZ POINTS');
    $tiers = F::fiche($this->espace, 'ZZ TIERS', ['website' => null]);
    evsReseau([
        'https://zz-autre-siren.test/' => evsPage('Accueil', 'SIREN ' . evsSiren($tiers)),
        'https://zz-points.test/' => evsPage('Accueil', 'SIREN ' . implode('.', str_split(evsSiren($points), 3))),
    ]);

    evsLancer();

    expect(evsMarqueur($autre)['statut'] ?? null)->toBe(SiteMedia::NON_CONFORME)
        ->and(evsMarqueur($points)['statut'] ?? null)->toBe(SiteMedia::VERIFIE);
});

test('journal d interruption : la classe de l exception seulement, jamais son message (SQL et valeurs)', function () {
    expect((string) file_get_contents(app_path('Console/Commands/CrmEntreprisesVerifierSites.php')))
        ->not->toContain("['exception' => \$interruption]");
});

// ── Sous le rôle de production ──────────────────────────────────────────────

function evsApp(): Connection
{
    return DB::connection('pgsql_app');
}

test('sous axion_app : la sélection d un paquet passe par l index partiel ordonné, sans balayage séquentiel', function (bool $audience) {
    $espace = (string) Str::uuid();
    [$sql, $liaisons] = VerificationSite::selectionSql($espace, 0, 40, $audience ? 1 : null);
    evsApp()->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $espace]);
    evsApp()->statement('SET enable_seqscan = off');

    $plan = implode("\n", array_map(
        static fn ($l): string => (string) array_values((array) $l)[0],
        evsApp()->select('EXPLAIN ' . $sql, $liaisons),
    ));

    expect($plan)->toContain('idx_companies_site_non_verifie_id')
        ->and($plan)->not->toContain('Seq Scan on companies')
        ->and($plan)->not->toContain('Sort');
})->with(['toutes' => [false], 'audience' => [true]]);

test('curseurs_traitements : sécurité par espace activée ET forcée, politique stricte', function () {
    $t = DB::selectOne("SELECT relrowsecurity AS rls, relforcerowsecurity AS forcee FROM pg_class WHERE relname = 'curseurs_traitements'");
    $politique = DB::selectOne("SELECT qual, with_check FROM pg_policies WHERE tablename = 'curseurs_traitements'");

    expect($t)->not->toBeNull()
        ->and((bool) $t->rls)->toBeTrue()
        ->and((bool) $t->forcee)->toBeTrue()
        ->and($politique)->not->toBeNull()
        ->and((string) $politique->qual)->toContain('app.current_workspace_id')
        // Pas de repli permissif « contexte vide = tout voir ».
        ->and((string) $politique->qual)->not->toContain('IS NULL')
        ->and((string) $politique->with_check)->toContain('app.current_workspace_id');

    // Le rôle de production lit sans erreur (droits accordés), et ne voit rien hors contexte.
    evsApp()->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', (string) Str::uuid()]);
    expect(evsApp()->table('curseurs_traitements')->count())->toBe(0);
});

test('curseurs_traitements : le rôle de production ne peut ni DELETE ni TRUNCATE ; le retour arrière ne supprime rien', function () {
    $droits = DB::selectOne(
        "SELECT has_table_privilege('axion_app', 'curseurs_traitements', 'DELETE') AS del,
                has_table_privilege('axion_app', 'curseurs_traitements', 'TRUNCATE') AS tru,
                has_table_privilege('axion_app', 'curseurs_traitements', 'UPDATE') AS upd",
    );
    expect((bool) $droits->del)->toBeFalse()
        ->and((bool) $droits->tru)->toBeFalse()
        ->and((bool) $droits->upd)->toBeTrue()
        ->and((string) file_get_contents(database_path('migrations/2026_10_03_000070_curseurs_traitements.php')))
        ->not->toContain('DROP TABLE');
});
