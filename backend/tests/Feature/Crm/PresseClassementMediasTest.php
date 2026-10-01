<?php

/**
 * LE CLASSEMENT DES MÉDIAS PAR LA LECTURE DE LEUR PAGE D'ACCUEIL (chantier F,
 * 2026-10-01) — `crm:presse:classer-medias`.
 *
 * Chaque test rougit sans la règle qu'il nomme : thème détecté, `inconnu`
 * sans signal, plusieurs thèmes, fiction écartée, verdict « média possible »
 * dans les deux sens, relation / nature / protection jamais modifiées,
 * idempotence, essai à blanc qui n'écrit rien, robots.txt respecté, aucune
 * donnée nominative stockée, étiquette manuelle qui gagne.
 *
 * AUCUN appel réseau : `Http::fake`, domaines en `.test`. Fixtures FICTIVES
 * (dépôt PUBLIC) : noms « ZZ », SIREN en 9xxxxxxxx.
 */

use App\Crm\FichesProtegees;
use App\Crm\Presse\ClassementMedia;
use App\Crm\Presse\LecturePageAccueil;
use App\Crm\Presse\MediaIncertain;
use App\Models\Company;
use App\Services\Tags\AutoTaggerService;
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

/** Une fiche de presse (relation `presse_media`), avec une ligne `media` d'une vraie source. */
function pcmPresse(string $espace, ?string $site, string $nom = 'ZZ EDITIONS FICTIVES', string $type = 'presse_mensuel', array $media = []): int
{
    $id = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $espace,
        'siren' => '9' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'denomination' => $nom,
        'website' => $site,
        'entity_nature' => 'media',
        'relation_type' => 'presse_media',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    pcmMedia($espace, $id, $type, 'cppap', $media + ['name' => $nom]);

    return $id;
}

/** Une fiche « média possible » : NAF 63.12Z, seule ligne `naf-extract`, prospect. */
function pcmIncertaine(string $espace, ?string $site): int
{
    $id = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $espace,
        'siren' => '9' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'denomination' => 'ZZ SOCIETE FICTIVE',
        'website' => $site,
        'naf' => '63.12Z',
        'entity_nature' => 'entreprise',
        'relation_type' => 'prospect',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    pcmMedia($espace, $id, 'portail_web', 'naf-extract', ['name' => 'ZZ SOCIETE FICTIVE']);

    return $id;
}

function pcmMedia(string $espace, int $fiche, string $type, string $source, array $valeurs = []): int
{
    return (int) DB::table('media')->insertGetId($valeurs + [
        'workspace_id' => $espace, 'company_id' => $fiche, 'name' => 'ZZ media fictif',
        'media_type' => $type, 'media_family' => 'editorial', 'source' => $source,
        'enrich_status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Une page d'accueil HTML minimale. */
function pcmPage(string $titre, array $menu = [], array $paragraphes = [], string $plus = ''): string
{
    $liens = implode('', array_map(static fn (string $m): string => '<a href="#">' . $m . '</a>', $menu));
    $p = implode('', array_map(static fn (string $t): string => '<p>' . $t . '</p>', $paragraphes));

    return '<!doctype html><html><head><meta charset="utf-8"><title>' . $titre . '</title></head><body>'
        . '<nav>' . $liens . '</nav><main>' . $p . $plus . '</main></body></html>';
}

/**
 * Faux réseau : robots.txt et page d'accueil par hôte ; tout le reste en 404.
 *
 * @param  array<string, array{robots?: array{0: int, 1: string}, page?: string, pages?: array<string, mixed>}>  $sites
 */
function pcmReseau(array $sites): void
{
    $faux = [];
    foreach ($sites as $hote => $s) {
        [$code, $corps] = $s['robots'] ?? [404, ''];
        $faux['https://' . $hote . '/robots.txt'] = Http::response($corps, $code, ['Content-Type' => 'text/plain']);
        if (isset($s['page'])) {
            $faux['https://' . $hote . '/'] = Http::response($s['page'], 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        foreach ($s['pages'] ?? [] as $chemin => $reponse) {
            $faux['https://' . $hote . $chemin] = $reponse;
        }
    }
    $faux['*'] = Http::response('', 404);
    Http::fake($faux);
}

/** @return array{code: int, sortie: string} */
function pcmClasser(array $options = []): array
{
    $code = Artisan::call('crm:presse:classer-medias', $options + ['--delai-domaine-ms' => '0']);

    return ['code' => $code, 'sortie' => Artisan::output()];
}

function pcmCompteur(string $sortie, string $cle): int
{
    preg_match('/\|\s*' . preg_quote($cle, '/') . '\s*\|\s*(\d+)\s*\|/', $sortie, $m);

    return isset($m[1]) ? (int) $m[1] : 0;
}

/** @return list<string> */
function pcmSlugs(int $companyId): array
{
    return DB::table('company_tag')->join('tags', 'tags.id', '=', 'company_tag.tag_id')
        ->where('company_tag.company_id', $companyId)->orderBy('tags.slug')->pluck('tags.slug')->all();
}

/** @return array<string, mixed>|null */
function pcmClassement(int $companyId): ?array
{
    $meta = json_decode((string) DB::table('companies')->where('id', $companyId)->value('metadata'), true);

    return is_array($meta) && is_array($meta[ClassementMedia::CLE] ?? null) ? $meta[ClassementMedia::CLE] : null;
}

function pcmSync(int $companyId): void
{
    (new AutoTaggerService)->syncTags(Company::query()->findOrFail($companyId));
}

// ── Thèmes et public ───────────────────────────────────────────────────────

test('theme detecte, PLUSIEURS themes, public dirigeants : lus sur la page d accueil', function () {
    $id = pcmPresse($this->espace, 'https://eco-pme.test');
    pcmReseau(['eco-pme.test' => [
        'robots' => [200, "User-agent: *\nDisallow: /admin\n"],
        'page' => pcmPage('ZZ Mag — économie et entrepreneurs', ['Économie', 'PME', 'Management', 'Régions'], ['Le magazine des dirigeants de PME.']),
    ]]);

    $r = pcmClasser(['--appliquer' => true]);

    $c = pcmClassement($id);
    expect($r['code'])->toBe(0)
        ->and($c['lecture'])->toBe('site')
        ->and($c['themes'])->toContain('economie-entreprise', 'pme-entrepreneurs')
        // « Management » : UN seul mot, dans le menu seulement — signal trop faible.
        ->and($c['themes'])->not->toContain('inconnu', 'grand-public', 'regional', 'rh-management')
        ->and($c['publics'])->toContain('dirigeants')
        ->and($c['format'])->toBeNull()
        ->and(pcmSlugs($id))->toContain('media-sujet:economie-entreprise', 'media-sujet:pme-entrepreneurs', 'media-public:dirigeants')
        ->and(pcmCompteur($r['sortie'], 'sites_lus'))->toBe(1);
    Http::assertSent(fn (Request $q): bool => $q->url() === 'https://eco-pme.test/'
        && str_starts_with($q->header('User-Agent')[0] ?? '', 'AxionCRM-ClassementMedias/'));
});

test('sans signal suffisant : inconnu (theme et public) — on n invente rien', function () {
    $neutre = pcmPresse($this->espace, 'https://neutre.test');
    $sansSite = pcmPresse($this->espace, null, 'ZZ SARL FICTIVE');
    pcmReseau(['neutre.test' => ['page' => pcmPage('Bienvenue', ['Accueil', 'Contact'], ['Une économie de moyens au quotidien.'])]]);

    pcmClasser(['--appliquer' => true]);

    foreach ([$neutre, $sansSite] as $id) {
        $c = pcmClassement($id);
        expect($c['themes'])->toBe(['inconnu'])
            ->and($c['publics'])->toBe(['inconnu'])
            ->and(pcmSlugs($id))->toContain('media-sujet:inconnu', 'media-public:inconnu');
    }
    // Le corps de page seul (« économie » dans un paragraphe) ne suffit pas : plafond de la zone texte.
    expect(pcmClassement($neutre)['lecture'])->toBe('site')
        ->and(pcmClassement($sansSite)['lecture'])->toBe('nom');
});

test('sans site : le NOM suffit quand le signal est net', function () {
    Http::fake();
    $id = pcmPresse($this->espace, null, 'ZZ LE JOURNAL DES ENTREPRENEURS');

    pcmClasser(['--appliquer' => true]);

    expect(pcmClassement($id)['themes'])->toContain('pme-entrepreneurs')
        ->and(pcmClassement($id)['lecture'])->toBe('nom');
    Http::assertNothingSent();
});

// ── Format TV ──────────────────────────────────────────────────────────────

test('TV : une fiction est ECARTEE (format fiction-jeu, aucun theme utile), un magazine eco est retenu', function () {
    $fiction = pcmPresse($this->espace, null, 'ZZ Entreprise : le feuilleton télévisé, saison 3', 'tv_emission');
    $eco = pcmPresse($this->espace, null, 'ZZ Business Eco, le magazine économique', 'tv_emission');
    $presse = pcmPresse($this->espace, null, 'ZZ Entreprise : le feuilleton télévisé, saison 3', 'presse_mensuel');

    pcmClasser(['--appliquer' => true]);

    expect(pcmClassement($fiction)['format'])->toBe('fiction-jeu')
        ->and(pcmClassement($fiction)['themes'])->toBe(['inconnu'])
        ->and(pcmClassement($fiction)['publics'])->not->toContain('dirigeants')
        ->and(pcmSlugs($fiction))->toContain('media-format:fiction-jeu')
        ->and(pcmSlugs($fiction))->not->toContain('media-sujet:economie-entreprise');
    expect(pcmClassement($eco)['format'])->toBe('magazine-eco')
        ->and(pcmClassement($eco)['themes'])->toContain('economie-entreprise')
        ->and(pcmSlugs($eco))->toContain('media-format:magazine-eco');
    // Témoin : hors télévision, pas de format, et le même nom garde son thème.
    expect(pcmClassement($presse)['format'])->toBeNull()
        ->and(pcmClassement($presse)['themes'])->toContain('economie-entreprise');
});

// ── Verdict « média possible » ─────────────────────────────────────────────

test('verdict media possible dans les DEUX sens — relation, nature, protection jamais modifiees', function () {
    $media = pcmIncertaine($this->espace, 'https://redac.test');
    $agence = pcmIncertaine($this->espace, 'https://agence.test');
    $articles = str_repeat('<article><h3>Brève</h3><time datetime="2026-09-30">30 septembre 2026</time></article>', 3);
    pcmReseau([
        'redac.test' => ['page' => pcmPage('ZZ Info', ['À la une', 'Rubriques', 'Abonnez-vous'], ['La rédaction vous informe.'], $articles)],
        'agence.test' => ['page' => pcmPage('ZZ Agence web', ['Nos services', 'Création de sites', 'Demande de devis'], ['Nos prestations sur mesure.'])],
    ]);
    pcmSync($media);
    pcmSync($agence);
    expect(pcmSlugs($media))->toContain(MediaIncertain::ETIQUETTE);
    $avant = DB::table('companies')->whereIn('id', [$media, $agence])->orderBy('id')
        ->get(['id', 'relation_type', 'entity_nature', 'relation_saisie_manuelle_at'])->toArray();

    $r = pcmClasser(['--appliquer' => true, '--perimetre' => 'media-possible']);

    expect(pcmClassement($media)['verdict'])->toBe('semble-media')
        ->and(pcmClassement($agence)['verdict'])->toBe('semble-pas-media')
        ->and(pcmSlugs($media))->toContain('media-possible:semble-media')
        ->and(pcmSlugs($media))->not->toContain(MediaIncertain::ETIQUETTE)
        ->and(pcmSlugs($agence))->toContain('media-possible:semble-pas-media')
        // v3 : un non-média n'a pas de ligne éditoriale — aucun sujet, aucun public.
        ->and(array_values(array_filter(pcmSlugs($agence), static fn (string $t): bool => str_starts_with($t, 'media-sujet:') || str_starts_with($t, 'media-public:') || str_starts_with($t, 'media-format:'))))->toBe([])
        ->and(pcmCompteur($r['sortie'], 'verdict:semble-media'))->toBe(1);
    $apres = DB::table('companies')->whereIn('id', [$media, $agence])->orderBy('id')
        ->get(['id', 'relation_type', 'entity_nature', 'relation_saisie_manuelle_at'])->toArray();
    expect($apres)->toEqual($avant)
        ->and(FichesProtegees::estProtegee($media))->toBeFalse()
        ->and(FichesProtegees::estProtegee($agence))->toBeFalse();
});

test('verdict : sans signal net la fiche RESTE a-verifier', function () {
    $id = pcmIncertaine($this->espace, 'https://flou.test');
    pcmReseau(['flou.test' => ['page' => pcmPage('ZZ', ['Accueil'], ['Bonjour.'])]]);

    pcmClasser(['--appliquer' => true]);

    expect(pcmClassement($id)['verdict'])->toBe('a-verifier')
        ->and(pcmSlugs($id))->toContain(MediaIncertain::ETIQUETTE)
        ->and(pcmSlugs($id))->not->toContain('media-possible:semble-media', 'media-possible:semble-pas-media');
});

test('une fiche de presse PROTEGEE le reste, relation et nature comprises', function () {
    $id = pcmPresse($this->espace, 'https://eco-pme.test');
    $tag = DB::table('tags')->insertGetId([
        'workspace_id' => $this->espace, 'slug' => FichesProtegees::TAG_PRESSE, 'name' => 'Presse 2026', 'category' => 'intent',
        'kind' => 'auto', 'rules' => '{}', 'is_locked' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('company_tag')->insert(['company_id' => $id, 'tag_id' => $tag, 'workspace_id' => $this->espace, 'assigned_at' => now(), 'assigned_by' => 'auto-rule']);
    pcmReseau(['eco-pme.test' => ['page' => pcmPage('ZZ Économie', ['Économie'])]]);

    pcmClasser(['--appliquer' => true]);

    $f = DB::table('companies')->where('id', $id)->first();
    expect($f->relation_type)->toBe('presse_media')
        ->and($f->entity_nature)->toBe('media')
        ->and(FichesProtegees::estProtegee($id))->toBeTrue()
        ->and(DB::table('companies')->where('id', $id)->whereNull('deleted_at')->exists())->toBeTrue();
});

// ── robots.txt ─────────────────────────────────────────────────────────────

test('robots.txt respecte : interdit a tous, a notre agent, ou serveur en erreur → page JAMAIS demandee', function () {
    $tous = pcmPresse($this->espace, 'https://bloque.test', 'ZZ ÉCONOMIE FICTIVE');
    $agent = pcmPresse($this->espace, 'https://agent.test');
    $erreur = pcmPresse($this->espace, 'https://erreur.test');
    $page = pcmPage('ZZ Économie', ['Économie', 'PME']);
    pcmReseau([
        'bloque.test' => ['robots' => [200, "User-agent: *\nDisallow: /\n"], 'page' => $page],
        'agent.test' => ['robots' => [200, "User-agent: AxionCRM\nDisallow: /\n\nUser-agent: *\nAllow: /\n"], 'page' => $page],
        'erreur.test' => ['robots' => [503, ''], 'page' => $page],
    ]);

    $r = pcmClasser(['--appliquer' => true]);

    foreach (['bloque.test', 'agent.test', 'erreur.test'] as $hote) {
        Http::assertNotSent(fn (Request $q): bool => $q->url() === 'https://' . $hote . '/');
        Http::assertSent(fn (Request $q): bool => $q->url() === 'https://' . $hote . '/robots.txt');
    }
    expect(pcmCompteur($r['sortie'], 'robots_interdits'))->toBe(3)
        ->and(pcmClassement($agent)['lecture'])->toBe('robots-interdit')
        // Repli sur le NOM seulement : « ÉCONOMIE » dans le nom de la fiche.
        ->and(pcmClassement($tous)['themes'])->toContain('economie-entreprise')
        ->and(pcmClassement($erreur)['themes'])->toBe(['inconnu']);
});

// ── Essai à blanc, idempotence ─────────────────────────────────────────────

test('essai a blanc PAR DEFAUT : les sites sont lus et comptes, RIEN n est ecrit', function () {
    $id = pcmPresse($this->espace, 'https://eco-pme.test');
    pcmReseau(['eco-pme.test' => ['page' => pcmPage('ZZ Économie', ['Économie'])]]);
    $metaAvant = DB::table('companies')->where('id', $id)->value('metadata');
    $liensAvant = DB::table('company_tag')->count();
    $tagsAvant = DB::table('tags')->count();

    $r = pcmClasser();
    $r2 = pcmClasser(['--dry-run' => true]);

    expect($r['code'])->toBe(0)
        ->and($r['sortie'])->toContain('[À BLANC]')
        ->and(pcmCompteur($r['sortie'], 'classements_ecrits'))->toBe(1)
        ->and(pcmCompteur($r2['sortie'], 'theme:economie-entreprise'))->toBe(1)
        ->and(DB::table('companies')->where('id', $id)->value('metadata'))->toBe($metaAvant)
        ->and(DB::table('company_tag')->count())->toBe($liensAvant)
        ->and(DB::table('tags')->count())->toBe($tagsAvant);
    expect(pcmClasser(['--dry-run' => true, '--appliquer' => true])['code'])->toBe(1);
});

test('idempotente : relancer ne relit rien, --reclasser ne reecrit rien, une resynchro ne retire rien', function () {
    $id = pcmPresse($this->espace, 'https://eco-pme.test');
    pcmReseau(['eco-pme.test' => ['page' => pcmPage('ZZ Économie', ['Économie', 'PME'])]]);

    pcmClasser(['--appliquer' => true]);
    $slugs = pcmSlugs($id);
    $liens = DB::table('company_tag')->count();

    $deux = pcmClasser(['--appliquer' => true]);
    $trois = pcmClasser(['--appliquer' => true, '--reclasser' => true]);
    pcmSync($id);

    expect(pcmCompteur($deux['sortie'], 'fiches_lues'))->toBe(0)
        ->and(pcmCompteur($trois['sortie'], 'fiches_lues'))->toBe(1)
        ->and(pcmCompteur($trois['sortie'], 'classements_ecrits'))->toBe(0)
        ->and(pcmCompteur($trois['sortie'], 'classements_inchanges'))->toBe(1)
        ->and(pcmSlugs($id))->toBe($slugs)
        ->and(DB::table('company_tag')->count())->toBe($liens)
        ->and(DB::table('tags')->where('slug', 'media-sujet:economie-entreprise')->count())->toBe(1);
});

test('une etiquette posee A LA MAIN gagne : rien d automatique dans son namespace, et elle reste', function () {
    $id = pcmPresse($this->espace, 'https://eco-pme.test');
    $manuel = DB::table('tags')->insertGetId([
        'workspace_id' => $this->espace, 'slug' => 'media-public:grand-public', 'name' => 'Grand public (à la main)',
        'category' => 'custom', 'kind' => 'manual', 'rules' => '{}', 'is_locked' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('company_tag')->insert(['company_id' => $id, 'tag_id' => $manuel, 'workspace_id' => $this->espace, 'assigned_at' => now(), 'assigned_by' => 'user']);
    pcmReseau(['eco-pme.test' => ['page' => pcmPage('ZZ Mag des dirigeants — économie', ['Économie', 'PME'])]]);

    pcmClasser(['--appliquer' => true]);

    expect(pcmClassement($id)['publics'])->toContain('dirigeants')
        ->and(pcmSlugs($id))->toContain('media-public:grand-public', 'media-sujet:economie-entreprise')
        ->and(pcmSlugs($id))->not->toContain('media-public:dirigeants');
});

// ── Données personnelles ───────────────────────────────────────────────────

test('AUCUNE donnee nominative stockee ni affichee : ni texte, ni nom, ni adresse de la page', function () {
    $id = pcmPresse($this->espace, 'https://eco-pme.test');
    pcmReseau(['eco-pme.test' => ['page' => pcmPage(
        'ZZ Économie — par Zorglub Fictivus',
        ['Économie', 'PME'],
        ['Contact : zorglub.fictivus@eco-pme.test, 06 00 00 00 00. Directeur de la publication : Zorglub Fictivus.'],
    )]]);

    $r = pcmClasser(['--appliquer' => true]);

    $meta = (string) DB::table('companies')->where('id', $id)->value('metadata');
    $etiquettes = DB::table('tags')->pluck('name')->implode(' ') . DB::table('tags')->pluck('slug')->implode(' ');
    foreach (['Zorglub', 'zorglub', 'Fictivus', '@', '06 00', 'ZZ Économie'] as $interdit) {
        expect($meta)->not->toContain($interdit)
            ->and($etiquettes)->not->toContain($interdit)
            ->and($r['sortie'])->not->toContain($interdit);
    }
    expect(array_keys(pcmClassement($id)))->toEqualCanonicalizing(['v', 'lecture', 'themes', 'secteurs', 'publics', 'format', 'verdict', 'scores', 'le'])
        ->and($r['sortie'])->not->toContain('eco-pme');
});

test('lecture : une page n est lue qu UNE fois, robots.txt une fois par domaine, une page par domaine et par tour', function () {
    $a = pcmPresse($this->espace, 'https://chaine.test', 'ZZ Chaîne', 'tv');
    $b = pcmPresse($this->espace, null, 'ZZ Émission', 'tv_emission', ['website' => 'chaine.test/emission']);
    $c = pcmPresse($this->espace, 'https://chaine.test/', 'ZZ Chaîne bis', 'tv');
    pcmReseau(['chaine.test' => ['page' => pcmPage('ZZ', ['Accueil']), 'pages' => [
        '/emission' => Http::response(pcmPage('ZZ', ['Accueil']), 200, ['Content-Type' => 'text/html']),
    ]]]);
    $attentes = [];
    // Une fonction fléchée capturerait `$attentes` PAR VALEUR : fermeture classique.
    app()->bind(LecturePageAccueil::class, function () use (&$attentes): LecturePageAccueil {
        return new LecturePageAccueil(4, 6, 0, function (int $ms) use (&$attentes): void {
            $attentes[] = $ms;
        });
    });

    $r = pcmClasser(['--appliquer' => true]);

    Http::assertSentCount(3); // un robots.txt + deux pages
    expect(collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), '/robots.txt'))->count())->toBe(1)
        ->and(pcmCompteur($r['sortie'], 'pages_deja_lues'))->toBe(1)
        ->and(count($attentes))->toBe(2) // deux tours : jamais deux pages du même domaine à la fois
        ->and(pcmClassement($a)['lecture'])->toBe('site')
        ->and(pcmClassement($b)['lecture'])->toBe('site')
        ->and(pcmClassement($c)['lecture'])->toBe('site');
});

test('le CHEMIN du media est lu tel quel, et robots.txt est evalue pour CE chemin', function () {
    $lyon = pcmPresse($this->espace, 'https://groupe.test', 'ZZ GROUPE FICTIF', 'presse_quotidien', ['website' => 'https://groupe.test/lyon']);
    $prive = pcmPresse($this->espace, null, 'ZZ AUTRE FICTIF', 'presse_quotidien', ['website' => 'groupe.test/prive/edition']);
    pcmReseau(['groupe.test' => [
        'robots' => [200, "User-agent: *\nDisallow: /prive/\n"],
        'page' => pcmPage('ZZ Groupe — séries, films, jeux'),
        'pages' => ['/lyon' => Http::response(pcmPage('ZZ Lyon — actualité locale', ['Lyon', 'Villeurbanne']), 200, ['Content-Type' => 'text/html'])],
    ]]);

    pcmClasser(['--appliquer' => true]);

    Http::assertSent(fn (Request $q): bool => $q->url() === 'https://groupe.test/lyon');
    Http::assertNotSent(fn (Request $q): bool => $q->url() === 'https://groupe.test/');
    Http::assertNotSent(fn (Request $q): bool => str_contains($q->url(), '/prive/'));
    expect(pcmClassement($lyon)['themes'])->toContain('regional')
        ->and(pcmClassement($prive)['lecture'])->toBe('robots-interdit');
});

test('bombe gzip : lecture coupee au plafond DECOMPRESSE, fiche marquee illisible et sautee a la relance', function () {
    $bombe = pcmPresse($this->espace, 'https://bombe.test', 'ZZ ÉCONOMIE FICTIVE');
    $gros = pcmPresse($this->espace, 'https://gros.test');
    $pdf = pcmPresse($this->espace, 'https://pdf.test');
    $sain = pcmPresse($this->espace, 'https://sain.test');
    $corpsBombe = (string) gzencode(str_repeat(' ', 20_000_000), 9);
    expect(strlen($corpsBombe))->toBeLessThan(LecturePageAccueil::CORPS_MAX);
    pcmReseau([
        'bombe.test' => ['pages' => ['/' => Http::response($corpsBombe, 200, ['Content-Type' => 'text/html', 'Content-Encoding' => 'gzip'])]],
        'gros.test' => ['pages' => ['/' => Http::response('<p>x</p>', 200, ['Content-Type' => 'text/html', 'Content-Length' => (string) (LecturePageAccueil::CORPS_MAX + 1)])]],
        'pdf.test' => ['pages' => ['/' => Http::response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf'])]],
        'sain.test' => ['pages' => ['/' => Http::response((string) gzencode(pcmPage('ZZ Économie', ['Économie'])), 200, ['Content-Type' => 'text/html', 'Content-Encoding' => 'gzip'])]],
    ]);

    $r = pcmClasser(['--appliquer' => true]);
    $relance = pcmClasser(['--appliquer' => true]);

    expect(pcmCompteur($r['sortie'], 'sites_illisibles'))->toBe(3)
        ->and(pcmClassement($bombe)['lecture'])->toBe('illisible')
        ->and(pcmClassement($gros)['lecture'])->toBe('illisible')
        ->and(pcmClassement($pdf)['lecture'])->toBe('illisible')
        // Repli sur le nom, comme pour un site interdit.
        ->and(pcmClassement($bombe)['themes'])->toContain('economie-entreprise')
        // Un gzip honnête est décompressé et lu.
        ->and(pcmClassement($sain)['lecture'])->toBe('site')
        ->and(pcmClassement($sain)['themes'])->toContain('economie-entreprise')
        ->and(pcmCompteur($relance['sortie'], 'fiches_lues'))->toBe(0);
});

test('--limite et --depuis-id bornent ce qui est lu et ecrit', function () {
    Http::fake();
    $un = pcmPresse($this->espace, null, 'ZZ ÉCONOMIE UN');
    $deux = pcmPresse($this->espace, null, 'ZZ ÉCONOMIE DEUX');
    $trois = pcmPresse($this->espace, null, 'ZZ ÉCONOMIE TROIS');

    $limite = pcmClasser(['--appliquer' => true, '--limite' => '1']);
    expect(pcmCompteur($limite['sortie'], 'fiches_lues'))->toBe(1)
        ->and(pcmClassement($un))->not->toBeNull()
        ->and(pcmClassement($deux))->toBeNull()
        ->and(pcmClassement($trois))->toBeNull();

    $depuis = pcmClasser(['--appliquer' => true, '--depuis-id' => (string) $trois]);
    expect(pcmCompteur($depuis['sortie'], 'fiches_lues'))->toBe(1)
        ->and(pcmClassement($deux))->toBeNull()
        ->and(pcmClassement($trois))->not->toBeNull();
});

test('un classement LU SUR LE SITE n est pas remplace par un classement au nom seul (--reclasser, site devenu interdit)', function () {
    $id = pcmPresse($this->espace, 'https://eco-pme.test', 'ZZ EDITIONS FICTIVES');
    $phase = 1;
    Http::fake(function (Request $q) use (&$phase) {
        if (str_ends_with($q->url(), '/robots.txt')) {
            return $phase === 1 ? Http::response('', 404) : Http::response('', 503);
        }

        return Http::response(pcmPage('ZZ Économie', ['Économie', 'PME']), 200, ['Content-Type' => 'text/html']);
    });

    pcmClasser(['--appliquer' => true]);
    $avant = pcmClassement($id);
    $phase = 2;
    $r = pcmClasser(['--appliquer' => true, '--reclasser' => true]);

    expect($avant['lecture'])->toBe('site')
        ->and(pcmCompteur($r['sortie'], 'robots_interdits'))->toBe(1)
        ->and(pcmCompteur($r['sortie'], 'lus_sur_site_gardes'))->toBe(1)
        ->and(pcmCompteur($r['sortie'], 'classements_ecrits'))->toBe(0)
        ->and(pcmClassement($id))->toEqual($avant);
});
