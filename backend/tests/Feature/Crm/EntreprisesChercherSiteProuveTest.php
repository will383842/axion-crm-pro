<?php

/**
 * CHERCHER LE VRAI SITE, PROUVÉ PAR LE SIREN (suite du lot N6, 04/10/2026) —
 * `crm:entreprises:chercher-site-prouve`.
 *
 * Essai à blanc du 04/10 (200 fiches) : 74 % de sites devinés NON CONFORMES
 * (le site d'une autre entreprise), 15 INJOIGNABLES. Ce que ces gardes
 * tiennent :
 *  1. NON CONFORME → domaines CANDIDATS tirés de la dénomination, de
 *     l'enseigne et du sigle (≤ 6) ; un candidat n'est retenu QUE si le
 *     SIREN est sur son accueil ou ses mentions légales (même preuve que N6) ;
 *     redirection vers un autre domaine refusée ; l'ancien site deviné est
 *     GARDÉ en trace dans le marqueur ; aucun candidat prouvé → la fiche reste
 *     sans site vérifié, rien n'est inventé ;
 *  2. un site saisi à la main / de source fiable (`field_origins`) ou une
 *     fiche protégée n'est JAMAIS remplacé ;
 *  3. INJOIGNABLE → au plus 3 réessais, à 3 jours d'intervalle au moins
 *     (variantes www / sans www, https / http), puis bascule vers les
 *     candidats ;
 *  4. `--dry-run` : AUCUNE écriture ; comptage avant = après ; `--limite` ;
 *  5. filtre d'envoi : un site prouvé par candidat ne libère QUE les adresses
 *     de son domaine — ce qui venait de l'ancien site reste en quarantaine.
 *
 * AUCUN appel réseau : `Http::fake`. Fixtures FICTIVES (dépôt public) :
 * noms « ZZ », SIREN de fixtures, domaines en `.test` (les extensions des
 * candidats sont réduites à `.test` ici).
 */

use App\Crm\FichesProtegees;
use App\Crm\Presse\SiteMedia;
use App\Crm\Sites\CandidatsSite;
use App\Crm\Sites\CurseurTraitement;
use App\Crm\Sites\QuarantaineSite;
use App\Crm\Sites\SiteFiable;
use App\Crm\Sites\VerificationSite;
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
const CSP_MARDI = '2026-10-06 10:00:00';

beforeEach(function () {
    config(['crm.ingest.business_workspace' => 'axion-ia', 'crm.sites_candidats.extensions' => ['test']]);
    $this->espace = (string) (DB::table('workspaces')->where('slug', 'axion-ia')->value('id') ?? Str::uuid());
    if (! DB::table('workspaces')->where('id', $this->espace)->exists()) {
        DB::table('workspaces')->insert([
            'id' => $this->espace, 'slug' => 'axion-ia', 'name' => 'Axion-IA', 'settings' => '{}',
            'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    Carbon::setTestNow(Carbon::parse(CSP_MARDI, 'Europe/Paris'));
    Sleep::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Une fiche au site DEVINÉ, déjà jugée par N6 (`$statut` : non-conforme ou
 * injoignable), le 03/10.
 *
 * @param  array<string, mixed>  $marqueur  clés ajoutées au marqueur N6
 * @param  array<string, mixed>  $meta  autres clés de `metadata` (sigle…)
 */
function cspFiche(string $espace, string $hote, string $nom, string $statut, array $attrs = [], array $marqueur = [], array $meta = []): int
{
    $m = $marqueur + ['statut' => $statut, 'url' => 'https://' . $hote . '/', 'le' => '2026-10-03', 'v' => VerificationSite::VERSION];

    return F::fiche($espace, $nom, $attrs + [
        'website' => 'https://' . $hote . '/',
        'website_method' => 'guess',
        'city_name' => 'ZZVILLE',
        'metadata' => json_encode($meta + [SiteFiable::CLE => $m], JSON_THROW_ON_ERROR),
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function cspSiren(int $id): string
{
    return (string) DB::table('companies')->where('id', $id)->value('siren');
}

function cspPage(string $titre, string $corps = '', string $pied = ''): string
{
    return '<!doctype html><html><head><meta charset="utf-8"><title>' . $titre . '</title></head><body><h1>'
        . $titre . '</h1><p>' . $corps . '</p><footer>' . $pied . '</footer></body></html>';
}

/**
 * Faux réseau : robots.txt absent (404) ; URL → [code, corps, en-têtes] ou
 * HTML (200) ; tout le reste en 404. Chaque URL demandée est notée.
 *
 * @param  array<string, string|array{0: int, 1: string, 2: array<string, string>}>  $reponses
 * @param  list<string>  $demandes
 */
function cspReseau(array $reponses, array &$demandes = []): void
{
    Http::fake(function (Request $q) use ($reponses, &$demandes) {
        $url = $q->url();
        $demandes[] = $url;
        if (str_ends_with($url, '/robots.txt')) {
            return Http::response('', 404);
        }
        $r = $reponses[$url] ?? null;
        if (is_string($r)) {
            return Http::response($r, 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        if (is_array($r)) {
            return Http::response($r[1], $r[0], $r[2]);
        }

        return Http::response('', 404);
    });
}

/** @return array{code: int, sortie: string} */
function cspLancer(array $options = []): array
{
    $code = Artisan::call('crm:entreprises:chercher-site-prouve', $options);

    return ['code' => $code, 'sortie' => Artisan::output()];
}

/** @return array<string, mixed>|null */
function cspMarqueur(int $id): ?array
{
    $meta = json_decode((string) DB::table('companies')->where('id', $id)->value('metadata'), true);

    return is_array($meta) && is_array($meta[SiteFiable::CLE] ?? null) ? $meta[SiteFiable::CLE] : null;
}

/** Empreinte de la ligne SAUF `metadata` et `website` (seules colonnes permises). */
function cspEmpreinte(int $id): string
{
    return (string) DB::selectOne(
        "SELECT md5((to_jsonb(c) - 'metadata' - 'website')::text) AS e FROM companies c WHERE id = ?",
        [$id],
    )->e;
}

function cspEstNonVerifie(int $id): bool
{
    $l = DB::table('companies')->where('id', $id)->first(['website_method', 'metadata']);

    return SiteFiable::estNonVerifie($l->website_method, $l->metadata);
}

function cspSite(int $id): string
{
    return (string) DB::table('companies')->where('id', $id)->value('website');
}

// ── 1. Non conformes → candidats ───────────────────────────────────────────

test('candidat prouvé (SIREN sur son accueil) → nouveau site VÉRIFIÉ, ancien site deviné gardé en trace', function () {
    $id = cspFiche($this->espace, 'zz-devine.test', 'ZZ BOULANGERIE FICTIVE', SiteMedia::NON_CONFORME);
    $avant = cspEmpreinte($id);
    $siren = cspSiren($id);
    cspReseau([
        'https://zzboulangeriefictive.test/' => cspPage('ZZ Boulangerie', 'Pain.', 'SARL — RCS ZZVILLE ' . chunk_split($siren, 3, ' ')),
    ]);

    $r = cspLancer();

    expect($r['code'])->toBe(0)
        ->and(cspSite($id))->toBe('https://zzboulangeriefictive.test/')
        ->and(cspMarqueur($id))->toMatchArray([
            'statut' => SiteMedia::TROUVE_VERIFIE,
            'url' => 'https://zzboulangeriefictive.test/',
            'preuve' => VerificationSite::PREUVE_ACCUEIL,
            'origine' => CandidatsSite::ORIGINE,
        ])
        ->and(cspMarqueur($id)['ancien'] ?? null)->toMatchArray([
            'url' => 'https://zz-devine.test/',
            'statut' => SiteMedia::NON_CONFORME,
        ])
        ->and(cspEstNonVerifie($id))->toBeFalse()
        // `website_method` inchangé (guess), aucune autre colonne, ni `updated_at`.
        ->and((string) DB::table('companies')->where('id', $id)->value('website_method'))->toBe('guess')
        ->and(cspEmpreinte($id))->toBe($avant)
        ->and($r['sortie'])->toMatch('/prouvés par candidat\D+1\b/u');
    // Ni SIREN ni domaine au rapport.
    expect($r['sortie'])->not->toContain($siren)
        ->and($r['sortie'])->not->toContain('zzboulangerie')
        ->and(json_encode(cspMarqueur($id)))->not->toContain($siren);
});

test('candidat prouvé par ses mentions légales ; l enseigne et le sigle donnent aussi des candidats', function () {
    $id = cspFiche($this->espace, 'zz-autre.test', 'ZZ HOLDING FICTIVE SAS', SiteMedia::NON_CONFORME, ['enseigne' => 'ZZ Épicerie Fine'], [], ['sigle' => 'Z.Z.F']);
    cspReseau([
        'https://zzepiceriefine.test/' => cspPage('Épicerie', 'Bienvenue.', '<a href="/legal">Mentions légales</a>'),
        'https://zzepiceriefine.test/legal' => cspPage('Mentions légales', 'Éditeur : SIREN ' . cspSiren($id)),
    ]);

    cspLancer();

    expect(cspSite($id))->toBe('https://zzepiceriefine.test/')
        ->and(cspMarqueur($id))->toMatchArray(['statut' => SiteMedia::TROUVE_VERIFIE, 'preuve' => VerificationSite::PREUVE_MENTIONS]);
});

test('candidat sans le SIREN → rejeté : la fiche reste sans site vérifié, rien n est inventé ni effacé', function () {
    $id = cspFiche($this->espace, 'zz-devine.test', 'ZZ GARAGE FICTIF', SiteMedia::NON_CONFORME, [], ['motif' => null]);
    $demandes = [];
    cspReseau([
        // Deux mots du nom, la ville, mais PAS le SIREN.
        'https://zzgaragefictif.test/' => cspPage('ZZ Garage Fictif', 'Votre garage à ZZVILLE.'),
        'https://zz-garage-fictif.test/' => cspPage('ZZ Garage Fictif', 'Un autre garage, SIREN 999999999.'),
    ], $demandes);

    $r = cspLancer();

    expect(cspSite($id))->toBe('https://zz-devine.test/')
        ->and(cspMarqueur($id))->toMatchArray(['statut' => SiteMedia::NON_CONFORME, 'url' => 'https://zz-devine.test/'])
        ->and(cspMarqueur($id)['candidats']['essayes'] ?? null)->toBeGreaterThanOrEqual(2)
        ->and(cspMarqueur($id)['candidats']['essayes'] ?? 99)->toBeLessThanOrEqual(CandidatsSite::MAX)
        ->and(cspEstNonVerifie($id))->toBeTrue()
        ->and($r['sortie'])->toMatch('/toujours sans site vérifié\D+1\b/u');

    // Fiche déjà cherchée : la relance suivante ne relit rien.
    $demandes = [];
    cspLancer(['--depuis-debut' => true]);
    expect($demandes)->toBe([]);
});

test('candidat redirigé vers un AUTRE domaine qui porte le SIREN → rejeté', function () {
    $id = cspFiche($this->espace, 'zz-devine.test', 'ZZ PLOMBERIE FICTIVE', SiteMedia::NON_CONFORME);
    cspReseau([
        'https://zzplomberiefictive.test/' => [301, '', ['Location' => 'https://zz-annuaire.test/zz-plomberie']],
        'https://zz-annuaire.test/zz-plomberie' => cspPage('Annuaire', 'ZZ PLOMBERIE FICTIVE — SIREN ' . cspSiren($id)),
    ]);

    cspLancer();

    expect(cspSite($id))->toBe('https://zz-devine.test/')
        ->and(cspMarqueur($id)['statut'] ?? null)->toBe(SiteMedia::NON_CONFORME)
        ->and(cspEstNonVerifie($id))->toBeTrue();
});

test('site saisi à la main (field_origins) ou fiche protégée : JAMAIS remplacé, aucun candidat demandé', function () {
    $saisi = cspFiche($this->espace, 'zz-saisi.test', 'ZZ SAISIE FICTIVE', SiteMedia::NON_CONFORME, ['field_origins' => json_encode(['website' => 'declared'])]);
    $protegee = cspFiche($this->espace, 'zz-protege.test', 'ZZ PROTEGEE FICTIVE', SiteMedia::NON_CONFORME);
    F::lier($this->espace, $protegee, F::tag($this->espace, FichesProtegees::TAG_FEDERATIONS));
    $demandes = [];
    cspReseau([
        'https://zzsaisiefictive.test/' => cspPage('Saisie', 'SIREN ' . cspSiren($saisi)),
        'https://zzprotegeefictive.test/' => cspPage('Protégée', 'SIREN ' . cspSiren($protegee)),
    ], $demandes);

    $r = cspLancer();

    expect(cspSite($saisi))->toBe('https://zz-saisi.test/')
        ->and(cspSite($protegee))->toBe('https://zz-protege.test/')
        ->and(cspMarqueur($saisi)['statut'] ?? null)->toBe(SiteMedia::NON_CONFORME)
        ->and(cspMarqueur($protegee)['statut'] ?? null)->toBe(SiteMedia::NON_CONFORME)
        ->and(array_filter($demandes, static fn (string $u): bool => str_contains($u, 'zzsaisie') || str_contains($u, 'zzprotegee')))->toBe([])
        ->and($r['sortie'])->toMatch('/jamais remplacés\D+2\b/u');
});

test('garde d écriture : un site changé à la main PENDANT la lecture n est pas écrasé', function () {
    $id = cspFiche($this->espace, 'zz-devine.test', 'ZZ PENDANT FICTIF', SiteMedia::NON_CONFORME);
    $siren = cspSiren($id);
    Http::fake(function (Request $q) use ($id, $siren) {
        if (str_ends_with($q->url(), '/robots.txt')) {
            return Http::response('', 404);
        }
        if ($q->url() === 'https://zzpendantfictif.test/') {
            DB::table('companies')->where('id', $id)->update(['website' => 'https://zz-saisi-main.test/']);

            return Http::response(cspPage('Accueil', 'SIREN ' . $siren), 200, ['Content-Type' => 'text/html']);
        }

        return Http::response('', 404);
    });

    $r = cspLancer();

    expect(cspSite($id))->toBe('https://zz-saisi-main.test/')
        ->and(cspMarqueur($id)['statut'] ?? null)->toBe(SiteMedia::NON_CONFORME)
        ->and($r['sortie'])->toMatch('/écartés\D+1\b/u');
});

// ── 2. Injoignables → réessais espacés ─────────────────────────────────────

test('injoignable : réessayé 3 fois à 3 jours d intervalle (www, sans www, http), puis basculé vers les candidats', function () {
    $id = cspFiche($this->espace, 'zz-panne.test', 'ZZ PANNE FICTIVE', SiteMedia::INJOIGNABLE);
    $demandes = [];
    cspReseau([], $demandes);

    // Réessai 1 (03/10 → 06/10 : 3 jours) : les trois variantes.
    cspLancer(['--forcer' => true]);
    expect(cspMarqueur($id))->toMatchArray(['statut' => SiteMedia::INJOIGNABLE, 'reessais' => 1, 'reessai_le' => '2026-10-06'])
        ->and($demandes)->toContain('https://zz-panne.test/')
        ->and($demandes)->toContain('https://www.zz-panne.test/')
        ->and($demandes)->toContain('http://zz-panne.test/');

    // Le lendemain : pas encore dû, rien n'est demandé.
    Carbon::setTestNow(Carbon::parse('2026-10-07 10:00:00', 'Europe/Paris'));
    $demandes = [];
    $r = cspLancer(['--forcer' => true]);
    expect($demandes)->toBe([])
        ->and(cspMarqueur($id)['reessais'] ?? null)->toBe(1)
        ->and($r['sortie'])->toMatch('/pas encore dus\D+1\b/u');

    // 09/10 : réessai 2.
    Carbon::setTestNow(Carbon::parse('2026-10-09 10:00:00', 'Europe/Paris'));
    cspLancer(['--forcer' => true]);
    expect(cspMarqueur($id)['reessais'] ?? null)->toBe(2)
        ->and(cspMarqueur($id)['candidats'] ?? null)->toBeNull();

    // 12/10 : réessai 3, échec → bascule : les candidats sont cherchés.
    Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00', 'Europe/Paris'));
    $demandes = [];
    $r = cspLancer(['--forcer' => true]);
    expect(cspMarqueur($id))->toMatchArray(['statut' => SiteMedia::INJOIGNABLE, 'reessais' => 3])
        ->and(cspMarqueur($id)['candidats']['essayes'] ?? null)->toBeGreaterThanOrEqual(1)
        ->and($demandes)->toContain('https://zzpannefictive.test/')
        ->and(cspSite($id))->toBe('https://zz-panne.test/')
        ->and($r['sortie'])->toMatch('/basculés vers les candidats\D+1\b/u');

    // Plus jamais relue : 3 réessais faits, candidats cherchés.
    Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00', 'Europe/Paris'));
    $demandes = [];
    cspLancer(['--forcer' => true]);
    expect($demandes)->toBe([]);
});

test('injoignable puis 3e réessai : un candidat prouvé garde l ancien site INJOIGNABLE en trace', function () {
    $id = cspFiche($this->espace, 'zz-mort.test', 'ZZ MORT FICTIF', SiteMedia::INJOIGNABLE, [], ['reessais' => 2, 'reessai_le' => '2026-10-01']);
    cspReseau(['https://zzmortfictif.test/' => cspPage('ZZ Mort Fictif', 'SIREN ' . cspSiren($id))]);

    cspLancer();

    expect(cspSite($id))->toBe('https://zzmortfictif.test/')
        ->and(cspMarqueur($id)['statut'] ?? null)->toBe(SiteMedia::TROUVE_VERIFIE)
        ->and(cspMarqueur($id)['ancien'] ?? null)->toMatchArray(['url' => 'https://zz-mort.test/', 'statut' => SiteMedia::INJOIGNABLE, 'reessais' => 3]);
});

test('injoignable joignable au réessai par la variante www, SIREN présent → vérifié, site inchangé', function () {
    $id = cspFiche($this->espace, 'zz-www.test', 'ZZ WWW FICTIF', SiteMedia::INJOIGNABLE);
    cspReseau([
        'https://zz-www.test/' => [503, '', []],
        'https://www.zz-www.test/' => cspPage('Accueil', 'SIREN ' . cspSiren($id)),
    ]);

    $r = cspLancer();

    expect(cspSite($id))->toBe('https://zz-www.test/')
        ->and(cspMarqueur($id))->toMatchArray(['statut' => SiteMedia::VERIFIE, 'preuve' => VerificationSite::PREUVE_ACCUEIL, 'reessais' => 1])
        ->and(cspEstNonVerifie($id))->toBeFalse()
        ->and($r['sortie'])->toMatch('/vérifiés au réessai\D+1\b/u');
});

// ── 3. Prudence, essai à blanc, comptage ───────────────────────────────────

test('--dry-run : les sites sont lus, AUCUNE écriture (ni UPDATE, ni transaction, ni curseur), comptage égal', function () {
    $id = cspFiche($this->espace, 'zz-devine.test', 'ZZ BLANC FICTIF', SiteMedia::NON_CONFORME);
    $panne = cspFiche($this->espace, 'zz-panne.test', 'ZZ PANNE BLANC', SiteMedia::INJOIGNABLE);
    $metaAvant = DB::table('companies')->whereIn('id', [$id, $panne])->orderBy('id')->pluck('metadata')->all();
    cspReseau(['https://zzblancfictif.test/' => cspPage('Accueil', 'SIREN ' . cspSiren($id))]);
    $ecritures = [];
    DB::listen(function ($q) use (&$ecritures): void {
        if (preg_match('/^\s*(UPDATE|INSERT|DELETE)\b/i', $q->sql) === 1) {
            $ecritures[] = $q->sql;
        }
    });
    $transactions = 0;
    DB::getEventDispatcher()?->listen(TransactionBeginning::class, function () use (&$transactions): void {
        $transactions++;
    });

    $r = cspLancer(['--dry-run' => true]);

    expect($r['code'])->toBe(0)
        ->and($ecritures)->toBe([])
        ->and($transactions)->toBe(0)
        ->and(cspSite($id))->toBe('https://zz-devine.test/')
        ->and(DB::table('companies')->whereIn('id', [$id, $panne])->orderBy('id')->pluck('metadata')->all())->toBe($metaAvant)
        ->and(CurseurTraitement::lire($this->espace, CandidatsSite::cleCurseur(null)))->toBeNull()
        ->and($r['sortie'])->toContain('[À BLANC]')
        ->and($r['sortie'])->toMatch('/prouvés par candidat\D+1\b/u')
        ->and($r['sortie'])->toContain('Comptage des fiches : identique');
});

test('--limite, curseur et reprise : N fiches au plus, puis reprise après la dernière', function () {
    $ids = [];
    foreach (['UN', 'DEUX', 'TROIS'] as $n) {
        $ids[] = cspFiche($this->espace, 'zz-' . strtolower($n) . '.test', 'ZZ LOT ' . $n, SiteMedia::NON_CONFORME);
    }
    cspReseau([]);

    $r = cspLancer(['--limite' => '2']);
    expect($r['sortie'])->toMatch('/fiches lues\D+2\b/u')
        ->and(CurseurTraitement::lire($this->espace, CandidatsSite::cleCurseur(null)))->toBe($ids[1])
        ->and(cspMarqueur($ids[2])['candidats'] ?? null)->toBeNull();

    cspLancer();
    expect(cspMarqueur($ids[2])['candidats'] ?? null)->not->toBeNull();
});

test('refus hors fenêtre (celle de N6, 08:00-19:00) sauf --forcer ; options invalides refusées', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-06 21:00:00', 'Europe/Paris')); // après 19:00
    expect(cspLancer()['code'])->toBe(1)
        ->and(cspLancer(['--forcer' => true, '--concurrence' => '9'])['code'])->toBe(1)
        ->and(cspLancer(['--forcer' => true, '--delai-domaine-ms' => '10'])['code'])->toBe(1)
        ->and(cspLancer(['--forcer' => true])['code'])->toBe(0);
});

test('une fiche jamais jugée par N6, vérifiée, ou ignorée par robots.txt n est pas lue', function () {
    $jamais = F::fiche($this->espace, 'ZZ JAMAIS FICTIF', ['website' => 'https://zz-jamais.test/', 'website_method' => 'guess']);
    $robots = cspFiche($this->espace, 'zz-robots.test', 'ZZ ROBOTS FICTIF', SiteMedia::ROBOTS_INTERDIT);
    $ok = cspFiche($this->espace, 'zz-ok.test', 'ZZ OK FICTIF', SiteMedia::VERIFIE);
    $demandes = [];
    cspReseau([], $demandes);

    $r = cspLancer();

    expect($demandes)->toBe([])
        ->and($r['sortie'])->toMatch('/fiches lues\D+0\b/u');
    unset($jamais, $robots, $ok);
});

test('la commande n est PAS inscrite au calendrier, et partage le verrou de N6', function () {
    expect((string) file_get_contents(base_path('routes/console.php')))->not->toContain('chercher-site-prouve')
        ->and((string) file_get_contents(app_path('Console/Commands/CrmEntreprisesChercherSiteProuve.php')))
        ->toContain("'crm:entreprises:verifier-sites:'");
});

// ── 4. Règles pures des candidats ──────────────────────────────────────────

test('candidats : accents, mots vides juridiques, tirets ; .fr puis .com ; avec et sans tiret ; bornés à 6', function () {
    $d = CandidatsSite::domaines('SARL Les Ateliers Électriques du Nord', 'Élec Nord', 'A.E.N', 'https://zz-ancien.test/', ['fr', 'com']);

    expect($d)->toHaveCount(CandidatsSite::MAX)
        ->and($d)->toBe([
            'atelierselectriquesnord.fr', 'ateliers-electriques-nord.fr', 'elecnord.fr', 'elec-nord.fr', 'aen.fr',
            'atelierselectriquesnord.com',
        ]);
    // .fr d'abord, puis .com
    $premierCom = array_search(true, array_map(static fn (string $x): bool => str_ends_with($x, '.com'), $d), true);
    expect(array_filter(array_slice($d, 0, (int) $premierCom), static fn (string $x): bool => str_ends_with($x, '.com')))->toBe([]);
    foreach ($d as $x) {
        expect($x)->toMatch('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?\.(fr|com)$/')
            ->and($x)->not->toContain('sarl');
    }
});

test('candidats : l ancien domaine deviné est exclu ; un nom vide ou trop court n en donne aucun ; sigle court ignoré', function () {
    expect(CandidatsSite::domaines('ZZ Exemple', null, null, 'https://www.zzexemple.fr/', ['fr', 'com']))->not->toContain('zzexemple.fr')
        ->and(CandidatsSite::domaines('SAS', null, null, null, ['fr']))->toBe([])
        ->and(CandidatsSite::domaines('', '', 'AB', null, ['fr']))->toBe([])
        ->and(CandidatsSite::domaines(null, null, null, null, ['fr']))->toBe([]);
});

test('réessais : variantes www / sans www / http, au plus 3 ; échéance de 3 jours, 3 réessais au plus', function () {
    expect(CandidatsSite::variantesReessai('https://zz-a.test/'))->toBe(['https://zz-a.test/', 'https://www.zz-a.test/', 'http://zz-a.test/'])
        ->and(CandidatsSite::variantesReessai('http://www.zz-a.test/x'))->toBe(['http://www.zz-a.test/x', 'https://www.zz-a.test/', 'https://zz-a.test/']);
    $jour = Carbon::parse('2026-10-06', 'Europe/Paris');
    expect(CandidatsSite::reessaiDu(0, '2026-10-03', $jour))->toBeTrue()
        ->and(CandidatsSite::reessaiDu(1, '2026-10-04', $jour))->toBeFalse()
        ->and(CandidatsSite::reessaiDu(2, null, $jour))->toBeTrue()
        ->and(CandidatsSite::reessaiDu(3, '2026-09-01', $jour))->toBeFalse();
});

// ── 5. Filtre d'envoi : seul le domaine prouvé est libéré ──────────────────

test('quarantaine : site prouvé par candidat → adresses de SON domaine éligibles, rien de l ancien site', function () {
    $id = cspFiche($this->espace, 'zz-devine.test', 'ZZ QUARANTAINE FICTIVE', SiteMedia::NON_CONFORME, ['email_generic' => 'contact@zz-devine.test']);
    cspReseau(['https://zzquarantainefictive.test/' => cspPage('Accueil', 'SIREN ' . cspSiren($id))]);
    cspLancer();
    expect(cspMarqueur($id)['statut'] ?? null)->toBe(SiteMedia::TROUVE_VERIFIE);

    $contact = static fn (string $email, string $source): int => (int) DB::table('contacts')->insertGetId([
        'workspace_id' => test()->espace, 'company_id' => $id, 'email' => $email, 'discovery_source' => $source,
        'first_name' => 'Zz', 'last_name' => 'Fictif ' . md5($email), 'sources' => json_encode([$source]), 'metadata' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $nouveau = $contact('direction@zzquarantainefictive.test', 'site');
    $ancienSite = $contact('jean@zz-devine.test', 'insee');
    $releveAncien = $contact('zz.fictif@zz-perso.test', 'mentions-legales');
    $ailleurs = $contact('zz.autre@zz-perso.test', 'insee');

    $f = DB::table('companies')->where('id', $id)->first(['website', 'website_method', 'metadata']);
    $nonVerifiee = QuarantaineSite::ficheNonVerifiee($f->website_method, $f->metadata);
    $ancien = QuarantaineSite::siteAncien($f->metadata);
    expect($nonVerifiee)->toBeFalse()
        ->and($ancien)->toBe('https://zz-devine.test/')
        // Mémoire.
        ->and(QuarantaineSite::adresseFiche($nonVerifiee, 'contact@zz-devine.test', $f->website, $ancien))->toBeTrue()
        ->and(QuarantaineSite::adresseFiche($nonVerifiee, 'contact@zzquarantainefictive.test', $f->website, $ancien))->toBeFalse()
        ->and(QuarantaineSite::personne($nonVerifiee, 'site', 'direction@zzquarantainefictive.test', $f->website, $ancien))->toBeFalse()
        ->and(QuarantaineSite::personne($nonVerifiee, 'insee', 'jean@zz-devine.test', $f->website, $ancien))->toBeTrue()
        ->and(QuarantaineSite::personne($nonVerifiee, 'mentions-legales', 'zz.fictif@zz-perso.test', $f->website, $ancien))->toBeTrue()
        ->and(QuarantaineSite::personne($nonVerifiee, 'insee', 'zz.autre@zz-perso.test', $f->website, $ancien))->toBeFalse();

    // SQL : même verdict.
    $sql = static fn (int $cid): bool => (bool) DB::selectOne(
        'SELECT ' . QuarantaineSite::personneSql('ct', 'c') . ' AS q FROM contacts ct JOIN companies c ON c.id = ct.company_id WHERE ct.id = ?',
        [$cid],
    )->q;
    expect($sql($nouveau))->toBeFalse()
        ->and($sql($ancienSite))->toBeTrue()
        ->and($sql($releveAncien))->toBeTrue()
        ->and($sql($ailleurs))->toBeFalse()
        ->and((bool) DB::selectOne('SELECT ' . QuarantaineSite::generiqueSql('c') . ' AS q FROM companies c WHERE id = ?', [$id])->q)->toBeTrue()
        ->and(DB::selectOne('SELECT ' . QuarantaineSite::siteAncienSql('c') . ' AS a FROM companies c WHERE id = ?', [$id])->a)->toBe('https://zz-devine.test/');

    // Générique sur le NOUVEAU domaine : libérée.
    DB::table('companies')->where('id', $id)->update(['email_generic' => 'contact@zzquarantainefictive.test']);
    expect((bool) DB::selectOne('SELECT ' . QuarantaineSite::generiqueSql('c') . ' AS q FROM companies c WHERE id = ?', [$id])->q)->toBeFalse();
});

test('quarantaine : une fiche vérifiée par N6 (même site) reste entièrement libérée ; non vérifiée, rien ne change', function () {
    $verifiee = cspFiche($this->espace, 'zz-n6.test', 'ZZ N6 FICTIF', SiteMedia::VERIFIE, ['email_generic' => 'contact@zz-n6.test']);
    $nonVerifiee = cspFiche($this->espace, 'zz-nv.test', 'ZZ NV FICTIF', SiteMedia::NON_CONFORME, ['email_generic' => 'contact@zz-autre.test']);

    $q = static fn (int $id): bool => (bool) DB::selectOne('SELECT ' . QuarantaineSite::generiqueSql('c') . ' AS q FROM companies c WHERE id = ?', [$id])->q;
    expect($q($verifiee))->toBeFalse()
        ->and($q($nonVerifiee))->toBeTrue()
        ->and(QuarantaineSite::siteAncien((string) DB::table('companies')->where('id', $verifiee)->value('metadata')))->toBeNull()
        ->and(QuarantaineSite::adresseFiche(false, 'x@zz-autre.test', 'https://zz-n6.test/', null))->toBeFalse()
        ->and(QuarantaineSite::adresseFiche(true, 'x@zz-n6.test', 'https://zz-n6.test/', null))->toBeTrue();
});
