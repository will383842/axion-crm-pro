<?php

/**
 * L'ADRESSE E-MAIL AFFICHÉE SUR UN SITE VÉRIFIÉ (décision du 04/10/2026) —
 * `crm:entreprises:email-site-verifie`.
 *
 * Ce que ces gardes tiennent :
 *  1. portée : SEULES les fiches au site vérifié (prouvé par le SIREN) ou de
 *     source fiable, sans e-mail fiable — un site deviné non vérifié n'est
 *     jamais lu ;
 *  2. une adresse d'un AUTRE domaine que le site (hébergeur, agence,
 *     webmaster tiers) est rejetée ; `noreply`, exemples, images aussi ;
 *  3. la GÉNÉRIQUE est préférée à la nominative ; une nominative n'est jamais
 *     écrite dans `email_generic` ;
 *  4. une valeur manuelle (ou déclarée) n'est JAMAIS écrasée : conflit →
 *     PROPOSITION (`propositions_champs`, origine `site-verifie`) ;
 *  5. `--dry-run` n'écrit RIEN (ni adresse, ni proposition, ni curseur) ;
 *  6. une page redirigée vers un autre domaine ne compte pas ;
 *  7. robots.txt respecté : une page interdite n'est pas demandée ;
 *  8. origine tracée (`field_origins`, `signals.email_site_verifie` : URL de
 *     la page et date), confiance A, curseur, fenêtre, comptage avant = après.
 *
 * AUCUN appel réseau : `Http::fake`. Fixtures FICTIVES (dépôt public) :
 * noms « ZZ », SIREN fictifs (`DoublonsFixtures`), domaines en `.example`.
 */

use App\Crm\Presse\SiteMedia;
use App\Crm\Propositions\Propositions;
use App\Crm\Sites\CurseurTraitement;
use App\Crm\Sites\EmailSiteVerifie;
use App\Crm\Sites\SiteFiable;
use App\Crm\Sites\VerificationSite;
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
const EESV_MARDI = '2026-10-06 10:00:00';

beforeEach(function () {
    config(['crm.ingest.business_workspace' => 'axion-ia', 'crm.scrape_funnel.validate_mx' => false]);
    $this->espace = (string) (DB::table('workspaces')->where('slug', 'axion-ia')->value('id') ?? Str::uuid());
    if (! DB::table('workspaces')->where('id', $this->espace)->exists()) {
        DB::table('workspaces')->insert([
            'id' => $this->espace, 'slug' => 'axion-ia', 'name' => 'Axion-IA', 'settings' => '{}',
            'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    Carbon::setTestNow(Carbon::parse(EESV_MARDI, 'Europe/Paris'));
    Sleep::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Une fiche au site deviné, VÉRIFIÉ par le SIREN dans ses mentions légales. */
function eesvFiche(string $espace, string $hote, string $nom, array $attrs = [], string $preuve = VerificationSite::PREUVE_MENTIONS): int
{
    return F::fiche($espace, $nom, $attrs + [
        'website' => 'https://' . $hote . '/',
        'website_method' => 'guess',
        'metadata' => json_encode([SiteFiable::CLE => [
            'statut' => SiteMedia::VERIFIE, 'url' => 'https://' . $hote . '/', 'preuve' => $preuve, 'le' => '2026-10-05', 'v' => VerificationSite::VERSION,
        ]]),
        'city_name' => 'ZZVILLE',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function eesvPage(string $titre, string $corps = '', string $pied = ''): string
{
    return '<!doctype html><html><head><meta charset="utf-8"><title>' . $titre . '</title></head><body><h1>'
        . $titre . '</h1><p>' . $corps . '</p><footer>' . $pied . '</footer></body></html>';
}

/** L'accueil type : un lien vers les mentions légales et un vers la page contact. */
function eesvAccueil(string $titre = 'Accueil'): string
{
    return eesvPage($titre, 'Bienvenue.', '<a href="/mentions-legales">Mentions légales</a> · <a href="/contact">Nous contacter</a>');
}

/**
 * Faux réseau : robots.txt absent (404) sauf indiqué ; une page par URL ;
 * `$redirections` : URL → adresse de destination (301) ; le reste en 404.
 *
 * @param  array<string, string>  $pages
 * @param  array<string, string>  $robots  hôte → robots.txt
 * @param  array<string, string>  $redirections
 */
function eesvReseau(array $pages, array $robots = [], array $redirections = [], ?Closure $espion = null): void
{
    Http::fake(function (Request $q) use ($pages, $robots, $redirections, $espion) {
        if ($espion !== null) {
            $espion($q);
        }
        $url = $q->url();
        $hote = (string) parse_url($url, PHP_URL_HOST);
        if (str_ends_with($url, '/robots.txt')) {
            return isset($robots[$hote]) ? Http::response($robots[$hote], 200, ['Content-Type' => 'text/plain']) : Http::response('', 404);
        }
        if (isset($redirections[$url])) {
            return Http::response('', 301, ['Location' => $redirections[$url]]);
        }
        if (isset($pages[$url])) {
            return Http::response($pages[$url], 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        return Http::response('', 404);
    });
}

/** @return array{code: int, sortie: string} */
function eesvLancer(array $options = []): array
{
    $code = Artisan::call('crm:entreprises:email-site-verifie', $options);

    return ['code' => $code, 'sortie' => Artisan::output()];
}

function eesvEmail(int $id): ?string
{
    $v = DB::table('companies')->where('id', $id)->value('email_generic');

    return $v === null ? null : (string) $v;
}

/** @return array<string, mixed> */
function eesvJson(int $id, string $colonne): array
{
    $v = json_decode((string) DB::table('companies')->where('id', $id)->value($colonne), true);

    return is_array($v) ? $v : [];
}

function eesvPropositions(?int $id = null): int
{
    return DB::table('propositions_champs')->when($id !== null, fn ($q) => $q->where('entite_id', $id))->count();
}

// ─────────────────────────────────────────────────────────────────────────────
// Règles pures
// ─────────────────────────────────────────────────────────────────────────────

test('extraction : mailto, adresses en clair et désobfuscation simple', function () {
    $html = '<a href="mailto:Contact@ZZ-Acme.example?subject=Devis">Écrire</a>'
        . '<p>Accueil : accueil [at] zz-acme.example — RH : rh (arobase) zz-acme.example</p>'
        . '<p>Direction : direction@zz-acme.example.</p>'
        . '<script>var x = "script@zz-acme.example";</script>';

    expect(EmailSiteVerifie::adresses($html))->toBe([
        'contact@zz-acme.example', 'accueil@zz-acme.example', 'rh@zz-acme.example', 'direction@zz-acme.example',
    ]);
});

test('jugement : autre domaine, noreply, exemple, image rejetés ; générique / nominative classées', function () {
    $site = 'https://www.zz-acme.example/';

    expect(EmailSiteVerifie::juger('contact@zz-acme.example', $site))->toBe([EmailSiteVerifie::GENERIQUE, null])
        ->and(EmailSiteVerifie::juger('formation@paris.zz-acme.example', $site))->toBe([EmailSiteVerifie::GENERIQUE, null])
        ->and(EmailSiteVerifie::juger('rh@zz-acme.example', $site))->toBe([EmailSiteVerifie::GENERIQUE, null])
        ->and(EmailSiteVerifie::juger('jeanne.zzmartin@zz-acme.example', $site))->toBe([EmailSiteVerifie::NOMINATIF, null])
        ->and(EmailSiteVerifie::juger('contact@zz-agence-web.example', $site))->toBe([null, EmailSiteVerifie::MOTIF_AUTRE_DOMAINE])
        ->and(EmailSiteVerifie::juger('contact@acme.example', $site))->toBe([null, EmailSiteVerifie::MOTIF_AUTRE_DOMAINE])
        ->and(EmailSiteVerifie::juger('no-reply@zz-acme.example', $site))->toBe([null, EmailSiteVerifie::MOTIF_NOREPLY])
        ->and(EmailSiteVerifie::juger('webmaster@zz-acme.example', $site))->toBe([null, EmailSiteVerifie::MOTIF_TECHNIQUE])
        ->and(EmailSiteVerifie::juger('jean@example.com', $site))->toBe([null, EmailSiteVerifie::MOTIF_EXEMPLE])
        ->and(EmailSiteVerifie::juger('votre-email@zz-acme.example', $site))->toBe([null, EmailSiteVerifie::MOTIF_EXEMPLE])
        ->and(EmailSiteVerifie::juger('logo@2x.png', $site))->toBe([null, EmailSiteVerifie::MOTIF_IMAGE]);
});

test('préférence : contact@ avant les autres génériques, ordre d apparition sinon', function () {
    expect(EmailSiteVerifie::preferee(['direction@zz.example', 'contact@zz.example', 'info@zz.example']))->toBe('contact@zz.example')
        ->and(EmailSiteVerifie::preferee(['formation@zz.example', 'rh@zz.example']))->toBe('formation@zz.example')
        ->and(EmailSiteVerifie::preferee([]))->toBeNull();
});

test('liens contact : au plus 2, même site seulement', function () {
    $html = '<a href="/contact">Contact</a><a href="https://zz-autre.example/contact">Contact</a>'
        . '<a href="/nous-joindre">Nous joindre</a><a href="/coordonnees">Coordonnées</a><a href="mailto:a@b.example">contact</a>';

    expect(EmailSiteVerifie::liensContact($html, 'https://zz-acme.example/'))
        ->toBe(['https://zz-acme.example/contact', 'https://zz-acme.example/nous-joindre']);
});

// ─────────────────────────────────────────────────────────────────────────────
// La commande
// ─────────────────────────────────────────────────────────────────────────────

test('site vérifié : la générique des mentions légales est écrite, origine et page tracées, confiance A', function () {
    $id = eesvFiche($this->espace, 'zz-acme.example', 'ZZ ACME FICTIVE');
    eesvReseau([
        'https://zz-acme.example/' => eesvAccueil(),
        'https://zz-acme.example/mentions-legales' => eesvPage('Mentions légales', 'Éditeur : ZZ Acme. Directrice : jeanne.zzmartin@zz-acme.example'),
        'https://zz-acme.example/contact' => eesvPage('Contact', 'Écrivez-nous : <a href="mailto:contact@zz-acme.example">contact@zz-acme.example</a>'),
    ]);

    $r = eesvLancer();

    expect($r['code'])->toBe(0)
        ->and(eesvEmail($id))->toBe('contact@zz-acme.example')
        ->and(eesvJson($id, 'field_origins'))->toMatchArray(['email_generic' => EmailSiteVerifie::ORIGINE])
        ->and(eesvJson($id, 'signals')[EmailSiteVerifie::CLE_SIGNAL] ?? null)->toMatchArray([
            'url' => 'https://zz-acme.example/contact',
            'le' => '2026-10-06',
            'type' => EmailSiteVerifie::GENERIQUE,
            'v' => EmailSiteVerifie::VERSION,
        ])
        ->and(DB::table('companies')->where('id', $id)->value('best_email_confidence'))->toBe('A')
        ->and(eesvPropositions())->toBe(0)
        // Le rapport ne montre ni adresse ni domaine.
        ->and($r['sortie'])->not->toContain('zz-acme')
        ->and($r['sortie'])->toContain('Comptage des fiches : identique');
});

test('site deviné NON vérifié : ignoré, aucune requête', function () {
    $id = F::fiche($this->espace, 'ZZ DEVINEE FICTIVE', [
        'website' => 'https://zz-devine.example/', 'website_method' => 'guess',
    ]);
    $requetes = 0;
    eesvReseau([
        'https://zz-devine.example/' => eesvPage('Accueil', 'contact@zz-devine.example'),
    ], espion: function () use (&$requetes) {
        $requetes++;
    });

    eesvLancer();

    expect(eesvEmail($id))->toBeNull()
        ->and($requetes)->toBe(0);
});

test('site de source fiable (jamais deviné) : dans la portée', function () {
    $id = F::fiche($this->espace, 'ZZ SAISIE FICTIVE', [
        'website' => 'https://zz-saisie.example/', 'website_method' => null,
    ]);
    eesvReseau([
        'https://zz-saisie.example/' => eesvAccueil(),
        'https://zz-saisie.example/mentions-legales' => eesvPage('Mentions légales', 'Contact : info@zz-saisie.example'),
    ]);

    eesvLancer();

    expect(eesvEmail($id))->toBe('info@zz-saisie.example');
});

test('adresse d un autre domaine (agence, hébergeur) rejetée', function () {
    $id = eesvFiche($this->espace, 'zz-client.example', 'ZZ CLIENT FICTIF');
    eesvReseau([
        'https://zz-client.example/' => eesvAccueil(),
        'https://zz-client.example/mentions-legales' => eesvPage('Mentions légales', 'Site réalisé par ZZ Agence : contact@zz-agence-web.example. Hébergeur : support@zz-hebergeur.example'),
    ]);

    $r = eesvLancer();

    expect(eesvEmail($id))->toBeNull()
        ->and($r['sortie'])->toMatch('/rejetées : autre domaine\s*\|\s*2/');
});

test('générique préférée à la nominative ; une nominative seule n est jamais écrite', function () {
    $mixte = eesvFiche($this->espace, 'zz-mixte.example', 'ZZ MIXTE FICTIVE');
    $nominative = eesvFiche($this->espace, 'zz-nomin.example', 'ZZ NOMIN FICTIVE');
    eesvReseau([
        'https://zz-mixte.example/' => eesvAccueil(),
        'https://zz-mixte.example/mentions-legales' => eesvPage('Mentions légales', 'Directeur de la publication : paul.zzdurand@zz-mixte.example — accueil@zz-mixte.example'),
        'https://zz-nomin.example/' => eesvAccueil(),
        'https://zz-nomin.example/mentions-legales' => eesvPage('Mentions légales', 'Responsable : lucie.zzpetit@zz-nomin.example'),
    ]);

    eesvLancer();

    expect(eesvEmail($mixte))->toBe('accueil@zz-mixte.example')
        ->and(eesvEmail($nominative))->toBeNull()
        ->and(DB::table('contacts')->count())->toBe(0);
});

test('valeur manuelle jamais écrasée : conflit → proposition ; champ déclaré vide → proposition', function () {
    $manuelle = eesvFiche($this->espace, 'zz-manuel.example', 'ZZ MANUEL FICTIF', [
        'email_generic' => 'direction@zz-manuel.example',
        'signals' => json_encode(['email_generic_verification' => ['statut' => 'invalide', 'motif' => 'inexistant']]),
    ]);
    $declaree = eesvFiche($this->espace, 'zz-declare.example', 'ZZ DECLARE FICTIF', [
        'field_origins' => json_encode(['email_generic' => 'declared']),
    ]);
    eesvReseau([
        'https://zz-manuel.example/' => eesvAccueil(),
        'https://zz-manuel.example/mentions-legales' => eesvPage('Mentions légales', 'contact@zz-manuel.example'),
        'https://zz-declare.example/' => eesvAccueil(),
        'https://zz-declare.example/mentions-legales' => eesvPage('Mentions légales', 'contact@zz-declare.example'),
    ]);

    $r = eesvLancer();

    expect($r['code'])->toBe(0)
        ->and(eesvEmail($manuelle))->toBe('direction@zz-manuel.example')
        ->and(eesvEmail($declaree))->toBeNull()
        ->and(eesvJson($declaree, 'field_origins'))->toBe(['email_generic' => 'declared']);
    $p = DB::table('propositions_champs')->where('entite_id', $manuelle)->first();
    expect($p)->not->toBeNull()
        ->and($p->entite)->toBe(Propositions::ENTREPRISE)
        ->and($p->champ)->toBe('email_generic')
        ->and($p->valeur_actuelle)->toBe('direction@zz-manuel.example')
        ->and($p->valeur_proposee)->toBe('contact@zz-manuel.example')
        ->and($p->origine)->toBe(EmailSiteVerifie::ORIGINE)
        ->and($p->reference_externe)->toBe('https://zz-manuel.example/mentions-legales')
        ->and(eesvPropositions($declaree))->toBe(1);

    // Relancé : la même proposition n'est pas ouverte deux fois.
    eesvLancer(['--depuis-debut' => true]);
    expect(eesvPropositions())->toBe(2);
});

test('fiche avec un e-mail fiable : hors portée, rien n est lu', function () {
    $id = eesvFiche($this->espace, 'zz-fiable.example', 'ZZ FIABLE FICTIF', ['email_generic' => 'bonjour@zz-fiable.example']);
    $requetes = 0;
    eesvReseau([], espion: function () use (&$requetes) {
        $requetes++;
    });

    eesvLancer();

    expect(eesvEmail($id))->toBe('bonjour@zz-fiable.example')->and($requetes)->toBe(0);
});

test('--dry-run : rien n est écrit (ni adresse, ni proposition, ni curseur), le bilan est donné', function () {
    $id = eesvFiche($this->espace, 'zz-blanc.example', 'ZZ BLANC FICTIF');
    $conflit = eesvFiche($this->espace, 'zz-blanc2.example', 'ZZ BLANC DEUX', [
        'email_generic' => 'ancien@zz-blanc2.example',
        'signals' => json_encode(['email_generic_verification' => ['statut' => 'invalide']]),
    ]);
    $avant = DB::table('companies')->orderBy('id')->get()->toJson();
    eesvReseau([
        'https://zz-blanc.example/' => eesvAccueil(),
        'https://zz-blanc.example/mentions-legales' => eesvPage('Mentions légales', 'contact@zz-blanc.example — marc.zzroux@zz-blanc.example — contact@zz-agence.example'),
        'https://zz-blanc2.example/' => eesvAccueil(),
        'https://zz-blanc2.example/mentions-legales' => eesvPage('Mentions légales', 'contact@zz-blanc2.example'),
    ]);

    $r = eesvLancer(['--dry-run' => true]);

    expect($r['code'])->toBe(0)
        ->and(DB::table('companies')->orderBy('id')->get()->toJson())->toBe($avant)
        ->and(eesvPropositions())->toBe(0)
        ->and(CurseurTraitement::lire($this->espace, EmailSiteVerifie::TRAITEMENT))->toBeNull()
        ->and($r['sortie'])->toContain('[À BLANC]')
        ->and($r['sortie'])->toMatch('/sites lus\s*\|\s*2/')
        ->and($r['sortie'])->toMatch('/génériques trouvées\s*\|\s*2/')
        ->and($r['sortie'])->toMatch('/nominatives trouvées \(jamais écrites\)\s*\|\s*1/')
        ->and($r['sortie'])->toMatch('/rejetées : autre domaine\s*\|\s*1/')
        ->and($r['sortie'])->toMatch('/adresses écrites\s*\|\s*1/')
        ->and($r['sortie'])->toMatch('/conflits → propositions\s*\|\s*1/');
    expect(eesvEmail($id))->toBeNull()->and(eesvEmail($conflit))->toBe('ancien@zz-blanc2.example');
});

test('page redirigée vers un autre domaine : refusée, rien n est pris', function () {
    $id = eesvFiche($this->espace, 'zz-redir.example', 'ZZ REDIR FICTIF');
    eesvReseau(
        [
            'https://zz-redir.example/' => eesvAccueil(),
            'https://zz-annuaire.example/fiche' => eesvPage('Annuaire', 'contact@zz-redir.example'),
        ],
        redirections: [
            'https://zz-redir.example/mentions-legales' => 'https://zz-annuaire.example/fiche',
            'https://zz-redir.example/contact' => 'https://zz-annuaire.example/fiche',
        ],
    );

    $r = eesvLancer();

    expect(eesvEmail($id))->toBeNull()
        ->and($r['sortie'])->toMatch('/pages refusées \(redirection hors domaine\)\s*\|\s*2/');
});

test('robots.txt respecté : une page interdite n est pas demandée', function () {
    $id = eesvFiche($this->espace, 'zz-robots.example', 'ZZ ROBOTS FICTIF');
    $demandees = [];
    eesvReseau(
        [
            'https://zz-robots.example/' => eesvAccueil(),
            'https://zz-robots.example/mentions-legales' => eesvPage('Mentions légales', 'Rien ici.'),
            'https://zz-robots.example/contact' => eesvPage('Contact', 'contact@zz-robots.example'),
        ],
        robots: ['zz-robots.example' => "User-agent: *\nDisallow: /contact\n"],
        espion: function (Request $q) use (&$demandees) {
            $demandees[] = $q->url();
        },
    );

    eesvLancer();

    expect(eesvEmail($id))->toBeNull()
        ->and($demandees)->not->toContain('https://zz-robots.example/contact')
        ->and($demandees)->toContain('https://zz-robots.example/mentions-legales');
});

test('preuve sur l accueil : l accueil est lu comme page de preuve', function () {
    $id = eesvFiche($this->espace, 'zz-accueil.example', 'ZZ ACCUEIL FICTIF', [], VerificationSite::PREUVE_ACCUEIL);
    eesvReseau([
        'https://zz-accueil.example/' => eesvPage('Accueil', 'Nous écrire : bonjour [at] zz-accueil.example'),
    ]);

    eesvLancer();

    expect(eesvEmail($id))->toBe('bonjour@zz-accueil.example')
        ->and(eesvJson($id, 'signals')[EmailSiteVerifie::CLE_SIGNAL]['url'] ?? null)->toBe('https://zz-accueil.example/');
});

test('hors fenêtre : refus sans --forcer ; curseur et reprise', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-06 21:00:00', 'Europe/Paris'));
    expect(eesvLancer()['code'])->toBe(1);

    Carbon::setTestNow(Carbon::parse(EESV_MARDI, 'Europe/Paris'));
    $a = eesvFiche($this->espace, 'zz-a.example', 'ZZ A FICTIF');
    $b = eesvFiche($this->espace, 'zz-b.example', 'ZZ B FICTIF');
    eesvReseau([
        'https://zz-a.example/' => eesvAccueil(),
        'https://zz-a.example/mentions-legales' => eesvPage('ML', 'contact@zz-a.example'),
        'https://zz-b.example/' => eesvAccueil(),
        'https://zz-b.example/mentions-legales' => eesvPage('ML', 'contact@zz-b.example'),
    ]);

    eesvLancer(['--limite' => '1', '--paquet' => '1']);
    expect(eesvEmail($a))->toBe('contact@zz-a.example')
        ->and(eesvEmail($b))->toBeNull()
        ->and(CurseurTraitement::lire($this->espace, EmailSiteVerifie::TRAITEMENT))->toBe($a);

    eesvLancer();
    expect(eesvEmail($b))->toBe('contact@zz-b.example');
});

test('jamais inscrite au calendrier', function () {
    expect((string) file_get_contents(base_path('routes/console.php')))->not->toContain('email-site-verifie');
});
