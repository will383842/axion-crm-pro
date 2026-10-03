<?php

/**
 * MISE À JOUR MENSUELLE INSEE (lot N8, 03/10/2026) —
 * `crm:insee:mise-a-jour-mensuelle`.
 *
 * L'API Sirene est SIMULÉE (`Http::fake`) : aucune requête réelle. Fixtures
 * FICTIVES (dépôt public) : SIREN de la plage 94xxxxxxx des tests
 * (`DoublonsFixtures::siren`), dénominations « ZZ », communes inventées.
 *
 * Ce qui est verrouillé :
 *  - la requête : `dateDernierTraitementUniteLegale` depuis la date donnée,
 *    curseur Sirene (`curseur=*` puis le suivant), AUCUN filtre de diffusion ;
 *  - créations du périmètre de l'import (siège actif, diffusible, société
 *    5xxx, département déjà présent dans l'espace) — et RIEN d'autre ;
 *  - fermetures et non diffusibles MARQUÉS, jamais supprimés ;
 *  - modifications : `field_origins` et fiches protégées respectés ;
 *  - fiches de provenance tiers rafraîchies EN PREMIER ;
 *  - `--dry-run` : bilan chiffré, rien d'écrit ; `--limite` et reprise ;
 *  - le motif `non_diffusible` d'`EligibiliteAdresse` ;
 *  - la planification (mardi→samedi, jamais les 1er/2/3, 08:00-19:00) ;
 *  - le rejeu sous `axion_app` (RLS forcée).
 */

use App\Console\Commands\CrmInseeMiseAJourMensuelle;
use App\Crm\Campagnes\EligibiliteAdresse;
use App\Crm\FichesProtegees;
use App\Crm\Insee\MiseAJourMensuelle;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $_ENV['INSEE_API_KEY'] = 'cle-de-banc';
    $_SERVER['INSEE_API_KEY'] = 'cle-de-banc';
    putenv('INSEE_API_KEY=cle-de-banc');
});

afterEach(function () {
    unset($_ENV['INSEE_API_KEY'], $_SERVER['INSEE_API_KEY']);
    putenv('INSEE_API_KEY');
});

/**
 * Une unité légale telle que la voie `/siren` de Sirene 3.11 la rend.
 *
 * @param  array<string, mixed>  $periode
 * @param  array<string, mixed>  $unite
 * @return array<string, mixed>
 */
function mamUnite(string $siren, array $periode = [], array $unite = []): array
{
    return array_merge([
        'siren' => $siren,
        'statutDiffusionUniteLegale' => 'O',
        'dateCreationUniteLegale' => '2026-08-01',
        'trancheEffectifsUniteLegale' => '11',
        'categorieEntreprise' => 'PME',
        'dateDernierTraitementUniteLegale' => '2026-09-20T10:00:00.000',
        'periodesUniteLegale' => [array_merge([
            'dateFin' => null,
            'dateDebut' => '2026-09-01',
            'etatAdministratifUniteLegale' => 'A',
            'denominationUniteLegale' => 'ZZ FICTIVE ' . $siren,
            'categorieJuridiqueUniteLegale' => '5710',
            'activitePrincipaleUniteLegale' => '62.01Z',
            'nicSiegeUniteLegale' => '00017',
        ], $periode)],
    ], $unite);
}

/**
 * Le siège (voie `/siret`) d'une unité créée.
 *
 * @return array<string, mixed>
 */
function mamSiege(string $siren, string $commune, string $categorie = '5710', string $statut = 'O'): array
{
    return [
        'siren' => $siren,
        'siret' => $siren . '00017',
        'etablissementSiege' => true,
        'statutDiffusionEtablissement' => 'O',
        'uniteLegale' => [
            'etatAdministratifUniteLegale' => 'A',
            'statutDiffusionUniteLegale' => $statut,
            'denominationUniteLegale' => 'ZZ CREEE ' . $siren,
            'categorieJuridiqueUniteLegale' => $categorie,
            'activitePrincipaleUniteLegale' => '62.01Z',
            'trancheEffectifsUniteLegale' => '03',
            'categorieEntreprise' => 'PME',
            'dateCreationUniteLegale' => '2026-09-10',
        ],
        'adresseEtablissement' => [
            'numeroVoieEtablissement' => '1',
            'typeVoieEtablissement' => 'RUE',
            'libelleVoieEtablissement' => 'DE L EXEMPLE',
            'codePostalEtablissement' => substr($commune, 0, 2) . '000',
            'libelleCommuneEtablissement' => 'ZZ VILLE',
            'codeCommuneEtablissement' => $commune,
        ],
        'periodesEtablissement' => [['enseigne1Etablissement' => null]],
    ];
}

/**
 * Simule Sirene : le flux des modifications (`/siren` + `dateDernierTraitement`)
 * en DEUX pages, la recherche par SIREN (priorité), et `/siret` (sièges).
 *
 * @param  list<array<string, mixed>>  $page1
 * @param  list<array<string, mixed>>  $page2
 * @param  array<string, array<string, mixed>>  $parSiren  unités rendues à la recherche par SIREN
 * @param  array<string, array<string, mixed>>  $sieges  sièges par SIRET
 */
function mamSirene(array $page1, array $page2, array $parSiren = [], array $sieges = []): void
{
    Http::fake(function (Request $requete) use ($page1, $page2, $parSiren, $sieges) {
        parse_str((string) parse_url($requete->url(), PHP_URL_QUERY), $p);
        $q = (string) ($p['q'] ?? '');
        $chemin = (string) parse_url($requete->url(), PHP_URL_PATH);

        if (str_ends_with($chemin, '/siret')) {
            preg_match_all('/siret:(\d{14})/', $q, $m);
            $etabs = array_values(array_filter(array_map(static fn (string $s) => $sieges[$s] ?? null, $m[1])));

            return Http::response(['header' => ['total' => count($etabs)], 'etablissements' => $etabs], 200);
        }
        if (str_contains($q, 'dateDernierTraitementUniteLegale')) {
            $curseur = (string) ($p['curseur'] ?? '');

            return $curseur === '*'
                ? Http::response(['header' => ['curseur' => '*', 'curseurSuivant' => 'PAGE2'], 'unitesLegales' => $page1], 200)
                : Http::response(['header' => ['curseur' => 'PAGE2', 'curseurSuivant' => 'PAGE2'], 'unitesLegales' => $page2], 200);
        }
        preg_match_all('/siren:(\d{9})/', $q, $m);
        $unites = array_values(array_filter(array_map(static fn (string $s) => $parSiren[$s] ?? null, $m[1])));

        return Http::response(['header' => ['total' => count($unites)], 'unitesLegales' => $unites], 200);
    });
}

/** @return list<array<string, string>> les requêtes Sirene envoyées, dans l'ordre (chemin + paramètres) */
function mamRequetes(): array
{
    return Http::recorded()->map(function (array $paire): array {
        /** @var Request $r */
        $r = $paire[0];
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $p);

        return ['chemin' => (string) parse_url($r->url(), PHP_URL_PATH)] + array_map('strval', $p);
    })->values()->all();
}

/**
 * Un espace et son jeu de fiches (périmètre : département 38).
 *
 * @return array<string, mixed>
 */
function mamEspace(): array
{
    $ws = F::espace('zz-insee-maj');
    $s = [];
    foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 't'] as $cle) {
        $s[$cle] = F::siren();
    }
    $commun = ['department_code' => '38', 'legal_form' => '5710', 'naf' => '62.01Z', 'effectif_range' => '11', 'prospection_status' => 'ready_for_outreach'];
    $ids = [
        // A : modification ordinaire (nom et activité).
        'a' => F::fiche($ws, 'ZZ Alpha', $commun + ['siren' => $s['a']]),
        // B : la dénomination a été SAISIE à la main.
        'b' => F::fiche($ws, 'ZZ Beta saisie', $commun + ['siren' => $s['b'], 'field_origins' => json_encode(['denomination' => 'declared'])]),
        // C : fiche PROTÉGÉE (fédération).
        'c' => F::fiche($ws, 'ZZ Gamma federation', $commun + ['siren' => $s['c']]),
        // D : fermée à l'INSEE.
        'd' => F::fiche($ws, 'ZZ Delta', $commun + ['siren' => $s['d']]),
        // E : passée en NON DIFFUSIBLE.
        'e' => F::fiche($ws, 'ZZ Epsilon', $commun + ['siren' => $s['e'], 'legal_form' => '1000']),
        // T : provenance TIERS (import fédérations), jamais confrontée à Sirene.
        't' => F::fiche($ws, 'ZZ Tiers', ['siren' => $s['t'], 'discovery_source' => 'federations-2026', 'department_code' => '38']),
    ];
    F::proteger($ws, $ids['c'], FichesProtegees::TAG_FEDERATIONS);

    return ['ws' => $ws, 's' => $s, 'ids' => $ids];
}

/**
 * Le scénario Sirene du jeu `mamEspace()`.
 *
 * @param  array<string, mixed>  $e
 */
function mamScenario(array $e): void
{
    $s = $e['s'];
    mamSirene(
        [
            mamUnite($s['a'], ['denominationUniteLegale' => 'ZZ ALPHA RENOMMEE', 'activitePrincipaleUniteLegale' => '70.22Z']),
            mamUnite($s['b'], ['denominationUniteLegale' => 'ZZ BETA INSEE', 'activitePrincipaleUniteLegale' => '70.22Z']),
            mamUnite($s['c'], ['denominationUniteLegale' => 'ZZ GAMMA INSEE', 'activitePrincipaleUniteLegale' => '70.22Z']),
            mamUnite($s['d'], ['etatAdministratifUniteLegale' => 'C', 'dateDebut' => '2026-09-15']),
            mamUnite($s['e'], ['denominationUniteLegale' => null, 'nomUniteLegale' => '[ND]', 'categorieJuridiqueUniteLegale' => '1000'], ['statutDiffusionUniteLegale' => 'P', 'prenom1UniteLegale' => '[ND]']),
        ],
        [
            // F : création du périmètre (Isère, 5710, diffusible).
            mamUnite($s['f']),
            // G : entrepreneur individuel — hors périmètre.
            mamUnite($s['g'], ['categorieJuridiqueUniteLegale' => '1000']),
            // H : non diffusible — jamais créé.
            mamUnite($s['h'], [], ['statutDiffusionUniteLegale' => 'N']),
            // I : siège à Paris — département absent de l'espace.
            mamUnite($s['i']),
        ],
        [$s['t'] => mamUnite($s['t'], ['denominationUniteLegale' => 'ZZ TIERS INSEE', 'categorieJuridiqueUniteLegale' => '5499'])],
        [
            $s['f'] . '00017' => mamSiege($s['f'], '38999'),
            $s['i'] . '00017' => mamSiege($s['i'], '75999'),
        ],
    );
}

function mamFiche(int $id): stdClass
{
    return DB::table('companies')->where('id', $id)->first();
}

test('essai à blanc : bilan chiffré complet, RIEN n est écrit', function () {
    $e = mamEspace();
    mamScenario($e);
    $avant = DB::table('companies')->where('workspace_id', $e['ws'])->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

    $code = Artisan::call('crm:insee:mise-a-jour-mensuelle', [
        '--workspace' => $e['ws'], '--depuis' => '2026-07-06', '--dry-run' => true, '--delai-ms' => 0,
    ]);
    $sortie = Artisan::output();

    expect($code)->toBe(0)
        ->and($sortie)->toContain('ESSAI À BLANC')
        ->and($sortie)->toMatch('/créations\s*:\s*1\b/u')
        ->and($sortie)->toMatch('/modifications\s*:\s*3\b/u')
        ->and($sortie)->toMatch('/fermetures\s*:\s*1\b/u')
        ->and($sortie)->toMatch('/non diffusibles\s*:\s*1\b/u');

    $apres = DB::table('companies')->where('workspace_id', $e['ws'])->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    expect($apres)->toBe($avant)
        ->and(DB::table('insee_mises_a_jour')->count())->toBe(0);
});

test('la requête : dateDernierTraitementUniteLegale, curseur Sirene, aucun filtre de diffusion, tiers en premier', function () {
    $e = mamEspace();
    mamScenario($e);

    Artisan::call('crm:insee:mise-a-jour-mensuelle', ['--workspace' => $e['ws'], '--depuis' => '2026-07-06', '--delai-ms' => 0]);

    $requetes = mamRequetes();
    $flux = array_values(array_filter($requetes, fn (array $r) => str_contains($r['q'] ?? '', 'dateDernierTraitementUniteLegale')));

    // La priorité (fiche tiers) part AVANT le flux des modifications.
    expect($requetes[0]['q'])->toContain('siren:' . $e['s']['t'])
        ->and(str_ends_with($requetes[0]['chemin'], '/siren'))->toBeTrue();

    expect($flux)->toHaveCount(2)
        ->and($flux[0]['q'])->toContain('dateDernierTraitementUniteLegale:[2026-07-06 TO *]')
        ->and($flux[0]['curseur'])->toBe('*')
        ->and($flux[1]['curseur'])->toBe('PAGE2')
        ->and(str_ends_with($flux[0]['chemin'], '/siren'))->toBeTrue();
    foreach ($requetes as $r) {
        expect($r['q'] ?? '')->not->toContain('statutDiffusion')
            ->and($r['q'] ?? '')->not->toContain('etatAdministratif');
    }
});

test('créations du périmètre seulement ; fermetures et non diffusibles MARQUÉS, jamais supprimés', function () {
    $e = mamEspace();
    mamScenario($e);
    $s = $e['s'];
    $ids = $e['ids'];
    $avant = DB::table('companies')->where('workspace_id', $e['ws'])->count();

    $code = Artisan::call('crm:insee:mise-a-jour-mensuelle', ['--workspace' => $e['ws'], '--depuis' => '2026-07-06', '--delai-ms' => 0]);
    expect($code)->toBe(0);

    // Rien n'est supprimé : une seule fiche de plus, aucune en corbeille.
    expect(DB::table('companies')->where('workspace_id', $e['ws'])->count())->toBe($avant + 1)
        ->and(DB::table('companies')->where('workspace_id', $e['ws'])->whereNotNull('deleted_at')->count())->toBe(0);

    $cree = DB::table('companies')->where('workspace_id', $e['ws'])->where('siren', $s['f'])->first();
    expect($cree)->not->toBeNull()
        ->and($cree->denomination)->toBe('ZZ CREEE ' . $s['f'])
        ->and($cree->department_code)->toBe('38')
        ->and($cree->discovery_source)->toBe('insee')
        ->and($cree->siret)->toBe($s['f'] . '00017')
        ->and($cree->insee_verifiee_le)->not->toBeNull();
    foreach (['g', 'h', 'i'] as $hors) {
        expect(DB::table('companies')->where('siren', $s[$hors])->exists())->toBeFalse("{$hors} ne devait pas être créé");
    }

    $d = mamFiche($ids['d']);
    expect($d->deleted_at)->toBeNull()
        ->and((string) $d->insee_ferme_le)->toBe('2026-09-15')
        ->and($d->archive_reason)->toBe('entreprise_radiee')
        ->and($d->prospection_status)->toBe('archived_no_email');

    $nd = mamFiche($ids['e']);
    expect($nd->deleted_at)->toBeNull()
        ->and($nd->insee_non_diffusible_le)->not->toBeNull()
        ->and($nd->archive_reason)->toBe(MiseAJourMensuelle::MOTIF_NON_DIFFUSIBLE)
        ->and($nd->prospection_status)->toBe('archived_no_email')
        // Le masque « [ND] » de l'INSEE n'est JAMAIS recopié.
        ->and($nd->denomination)->toBe('ZZ Epsilon');

    $journal = DB::table('insee_mises_a_jour')->where('workspace_id', $e['ws'])->first();
    expect($journal->statut)->toBe('reussie')
        ->and((string) $journal->depuis)->toBe('2026-07-06');
});

test('modifications : field_origins et fiches protégées respectés, provenance tiers rafraîchie', function () {
    $e = mamEspace();
    mamScenario($e);
    $ids = $e['ids'];

    Artisan::call('crm:insee:mise-a-jour-mensuelle', ['--workspace' => $e['ws'], '--depuis' => '2026-07-06', '--delai-ms' => 0]);

    $a = mamFiche($ids['a']);
    expect($a->denomination)->toBe('ZZ ALPHA RENOMMEE')
        ->and($a->naf)->toBe('70.22Z')
        ->and($a->naf_rev2)->toBe('70.22Z')
        ->and(json_decode($a->field_origins, true))->toMatchArray(['denomination' => 'insee', 'naf' => 'insee'])
        ->and($a->insee_verifiee_le)->not->toBeNull();

    // B : la saisie manuelle n'est pas écrasée ; le reste suit l'INSEE.
    $b = mamFiche($ids['b']);
    expect($b->denomination)->toBe('ZZ Beta saisie')
        ->and($b->naf)->toBe('70.22Z')
        ->and(json_decode($b->field_origins, true)['denomination'])->toBe('declared');

    // C : fiche protégée — aucun champ touché.
    $c = mamFiche($ids['c']);
    expect($c->denomination)->toBe('ZZ Gamma federation')
        ->and($c->naf)->toBe('62.01Z');

    // T : provenance tiers, rafraîchie par la passe prioritaire.
    $t = mamFiche($ids['t']);
    expect($t->legal_form)->toBe('5499')
        ->and($t->denomination)->toBe('ZZ TIERS INSEE')
        ->and($t->insee_verifiee_le)->not->toBeNull();
});

test('reprise : --limite arrête proprement, le passage suivant repart du curseur mémorisé', function () {
    $e = mamEspace();
    mamScenario($e);

    // 1 fiche tiers + 5 unités de la page 1 : la limite tombe en fin de page 1.
    Artisan::call('crm:insee:mise-a-jour-mensuelle', ['--workspace' => $e['ws'], '--depuis' => '2026-07-06', '--delai-ms' => 0, '--limite' => 6]);
    $journal = DB::table('insee_mises_a_jour')->where('workspace_id', $e['ws'])->first();
    expect($journal->statut)->toBe('en_cours')
        ->and($journal->curseur)->toBe('PAGE2')
        ->and(DB::table('companies')->where('siren', $e['s']['f'])->exists())->toBeFalse();

    $deja = count(mamRequetes());
    Artisan::call('crm:insee:mise-a-jour-mensuelle', ['--workspace' => $e['ws'], '--delai-ms' => 0]);

    $flux = array_values(array_filter(array_slice(mamRequetes(), $deja), fn (array $r) => str_contains($r['q'] ?? '', 'dateDernierTraitementUniteLegale')));
    expect($flux[0]['curseur'])->toBe('PAGE2')
        ->and($flux[0]['q'])->toContain('[2026-07-06 TO *]')
        ->and(DB::table('companies')->where('siren', $e['s']['f'])->exists())->toBeTrue()
        ->and(DB::table('insee_mises_a_jour')->where('workspace_id', $e['ws'])->count())->toBe(1)
        ->and(DB::table('insee_mises_a_jour')->where('workspace_id', $e['ws'])->value('statut'))->toBe('reussie');
});

test('sans --depuis : rattrapage initial depuis le 2026-07-06, puis depuis la dernière exécution réussie', function () {
    $e = mamEspace();
    mamScenario($e);
    Artisan::call('crm:insee:mise-a-jour-mensuelle', ['--workspace' => $e['ws'], '--delai-ms' => 0]);
    $flux = array_values(array_filter(mamRequetes(), fn (array $r) => str_contains($r['q'] ?? '', 'dateDernierTraitementUniteLegale')));
    expect($flux[0]['q'])->toContain('[' . MiseAJourMensuelle::DEPUIS_INITIAL . ' TO *]');

    DB::table('insee_mises_a_jour')->where('workspace_id', $e['ws'])->update(['demarree_le' => '2026-09-08 10:00:00+02']);
    $deja = count(mamRequetes());
    Artisan::call('crm:insee:mise-a-jour-mensuelle', ['--workspace' => $e['ws'], '--delai-ms' => 0]);
    $flux = array_values(array_filter(array_slice(mamRequetes(), $deja), fn (array $r) => str_contains($r['q'] ?? '', 'dateDernierTraitementUniteLegale')));
    expect($flux[0]['q'])->toContain('[2026-09-08 TO *]');
});

test('réouverture : une fiche fermée redevenue active est démarquée et revient en prospection', function () {
    $ws = F::espace('zz-insee-maj');
    $siren = F::siren();
    $id = F::fiche($ws, 'ZZ Rouverte', [
        'siren' => $siren, 'department_code' => '38', 'insee_ferme_le' => '2026-08-01',
        'prospection_status' => 'archived_no_email', 'archive_reason' => 'entreprise_radiee',
    ]);
    mamSirene([mamUnite($siren, ['denominationUniteLegale' => 'ZZ Rouverte'])], []);

    Artisan::call('crm:insee:mise-a-jour-mensuelle', ['--workspace' => $ws, '--depuis' => '2026-07-06', '--delai-ms' => 0]);

    $f = mamFiche($id);
    expect($f->insee_ferme_le)->toBeNull()
        ->and($f->archive_reason)->toBeNull()
        ->and($f->prospection_status)->toBe('pending')
        ->and(Artisan::output())->toMatch('/réouvertures\s*:\s*1\b/u');
});

test('sobriété : une fiche INSEE inchangée n est pas réécrite ; un SIREN tiers inconnu de Sirene ne revient pas', function () {
    $ws = F::espace('zz-insee-maj');
    $x = F::siren();
    $y = F::siren();
    $inchangee = F::fiche($ws, 'ZZ FICTIVE ' . $x, [
        'siren' => $x, 'department_code' => '38', 'legal_form' => '5710', 'naf' => '62.01Z', 'effectif_range' => '11',
        'updated_at' => '2026-01-01 00:00:00+01',
    ]);
    $inconnue = F::fiche($ws, 'ZZ Tiers inconnue', ['siren' => $y, 'discovery_source' => 'federations-2026', 'department_code' => '38']);
    mamSirene([mamUnite($x)], []);

    Artisan::call('crm:insee:mise-a-jour-mensuelle', ['--workspace' => $ws, '--depuis' => '2026-07-06', '--delai-ms' => 0]);
    expect(Artisan::output())->toContain('dont 1 inconnues de Sirene');

    $f = mamFiche($inchangee);
    expect(substr((string) $f->updated_at, 0, 10))->toBe('2026-01-01')
        ->and($f->insee_verifiee_le)->toBeNull()
        ->and(mamFiche($inconnue)->insee_verifiee_le)->not->toBeNull();

    $deja = count(mamRequetes());
    Artisan::call('crm:insee:mise-a-jour-mensuelle', ['--workspace' => $ws, '--depuis' => '2026-07-06', '--delai-ms' => 0]);
    foreach (array_slice(mamRequetes(), $deja) as $r) {
        expect($r['q'] ?? '')->not->toContain('siren:' . $y);
    }
});

test('quota : un 429 de Sirene est attendu puis retenté sur le MÊME curseur, sans rien perdre', function () {
    $ws = F::espace('zz-insee-maj');
    $siren = F::siren();
    $id = F::fiche($ws, 'ZZ Quota', ['siren' => $siren, 'department_code' => '38']);
    Sleep::fake();
    Http::fakeSequence('api.insee.fr/api-sirene/3.11/siren*')
        ->push(['fault' => 'Too Many Requests'], 429)
        ->push(['header' => ['curseur' => '*', 'curseurSuivant' => '*'], 'unitesLegales' => [mamUnite($siren, ['denominationUniteLegale' => 'ZZ QUOTA APRES'])]], 200);

    $code = Artisan::call('crm:insee:mise-a-jour-mensuelle', ['--workspace' => $ws, '--depuis' => '2026-07-06', '--delai-ms' => 0]);

    expect($code)->toBe(0)
        ->and(mamFiche($id)->denomination)->toBe('ZZ QUOTA APRES')
        ->and(collect(mamRequetes())->pluck('curseur')->all())->toBe(['*', '*']);
    Sleep::assertSleptTimes(1);
});

test('une date --depuis invalide est refusée, sans aucun appel', function () {
    $e = mamEspace();
    Http::fake();
    $code = Artisan::call('crm:insee:mise-a-jour-mensuelle', ['--workspace' => $e['ws'], '--depuis' => '06/07/2026', '--delai-ms' => 0]);
    expect($code)->toBe(1);
    Http::assertNothingSent();
});

test('éligibilité : une fiche non diffusible sort de toute campagne (motif non_diffusible)', function () {
    $occ = ['status' => null, 'verification' => 'valide', 'perso' => false, 'deja_informe' => false];
    expect(EligibiliteAdresse::motif('contact@zz-nd.example.invalid', [$occ + ['non_diffusible' => true]]))
        ->toBe(EligibiliteAdresse::NON_DIFFUSIBLE)
        ->and(EligibiliteAdresse::MOTIFS)->toContain(EligibiliteAdresse::NON_DIFFUSIBLE)
        ->and(EligibiliteAdresse::motif('contact@zz-nd.example.invalid', [$occ + ['non_diffusible' => false]]))
        ->not->toBe(EligibiliteAdresse::NON_DIFFUSIBLE);
});

test('planification : mensuelle, mardi→samedi, jamais les 1er/2/3, 08:00-19:00 Paris, sans chevauchement', function () {
    $evenements = collect(app(Schedule::class)->events())
        ->filter(fn ($ev) => str_contains((string) $ev->command, CrmInseeMiseAJourMensuelle::SIGNATURE_PLANIFIEE));
    expect($evenements)->toHaveCount(1);
    $ev = $evenements->first();
    expect($ev->withoutOverlapping)->toBeTrue()
        ->and($ev->expiresAt)->toBeLessThanOrEqual(360)
        ->and((string) $ev->timezone)->toBe('Europe/Paris');

    // Exactement UN jour par mois, toujours un mardi→samedi, jamais avant le 4.
    foreach (['2026-10', '2026-11', '2026-12', '2027-01', '2027-02', '2027-03', '2027-04', '2027-05', '2027-06'] as $mois) {
        $jours = [];
        $d = CarbonImmutable::parse($mois . '-01 10:00', 'Europe/Paris');
        while ($d->format('Y-m') === $mois) {
            if (MiseAJourMensuelle::estJourPlanifie($d)) {
                $jours[] = $d;
            }
            $d = $d->addDay();
        }
        expect($jours)->toHaveCount(1);
        expect($jours[0]->day)->toBeGreaterThanOrEqual(4)
            ->and($jours[0]->dayOfWeekIso)->toBeGreaterThanOrEqual(2)
            ->and($jours[0]->dayOfWeekIso)->toBeLessThanOrEqual(6);
    }
    // Fenêtre horaire : 08:00-19:00 (heure de Paris).
    $jour = CarbonImmutable::parse('2026-11-04 10:00', 'Europe/Paris');
    expect(MiseAJourMensuelle::estJourPlanifie($jour))->toBeTrue()
        ->and(MiseAJourMensuelle::estJourPlanifie($jour->setTime(7, 59)))->toBeFalse()
        ->and(MiseAJourMensuelle::estJourPlanifie($jour->setTime(19, 0)))->toBeFalse()
        // Le 3 novembre 2026 est un mardi : jamais le 3.
        ->and(MiseAJourMensuelle::estJourPlanifie(CarbonImmutable::parse('2026-11-03 10:00', 'Europe/Paris')))->toBeFalse();
});

// ── Sous le rôle de production (axion_app, RLS forcée) ──────────────────────

/** Le plan d'une requête, sous `axion_app`, sans balayage séquentiel permis. */
function mamPlan(string $espace, string $sql, array $liaisons): string
{
    $app = DB::connection('pgsql_app');
    $app->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $espace]);
    $app->statement('SET enable_seqscan = off');
    try {
        $lignes = $app->select('EXPLAIN ' . $sql, $liaisons);
    } finally {
        $app->statement('RESET enable_seqscan');
        $app->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
        $app->disconnect();
    }

    return implode("\n", array_map(static fn ($l): string => (string) array_values((array) $l)[0], $lignes));
}

test('index : la passe prioritaire et la lecture par SIREN sont indexées sous axion_app (4,35 M de fiches)', function () {
    $index = DB::selectOne(
        'SELECT i.indisvalid AS valide, pg_get_indexdef(i.indexrelid) AS def
           FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?',
        ['idx_companies_insee_priorite'],
    );
    expect($index)->not->toBeNull()
        ->and((bool) $index->valide)->toBeTrue()
        ->and($index->def)->toContain('siren IS NOT NULL')
        ->and($index->def)->toContain("'insee'")
        ->and($index->def)->toContain('deleted_at IS NULL');

    $espace = (string) Str::uuid();
    DB::enableQueryLog();
    MiseAJourMensuelle::fichesPrioritaires($espace, '2026-07-06', 100);
    $requete = collect(DB::getQueryLog())->last();
    DB::disableQueryLog();
    expect(mamPlan($espace, $requete['query'], $requete['bindings']))->toContain('idx_companies_insee_priorite');

    // La lecture par SIREN : sur une table vide, le planificateur choisit au
    // hasard. On sème donc un volume (fictif) et des statistiques, et on lit
    // le plan sous le rôle de production DANS la transaction du test.
    $ws = F::espace('zz-insee-plan');
    DB::statement(
        "INSERT INTO companies (workspace_id, siren, denomination, discovery_source, created_at, updated_at)
         SELECT ?::uuid, lpad((940000000 + g)::text, 9, '0'), 'ZZ Plan ' || g, 'insee', now(), now()
           FROM generate_series(1, 20000) g",
        [$ws],
    );
    DB::statement('ANALYZE companies');
    DB::statement('SET LOCAL ROLE axion_app');
    DB::select('SELECT set_config(?, ?, true)', ['app.current_workspace_id', $ws]);
    $lignes = DB::select('EXPLAIN SELECT id FROM companies WHERE workspace_id = ? AND siren IN (?, ?)', [$ws, '940000001', '940000002']);
    DB::statement('RESET ROLE');
    $parSiren = implode("\n", array_map(static fn ($l): string => (string) array_values((array) $l)[0], $lignes));
    expect($parSiren)->toContain('companies_workspace_id_siren_key')
        ->and($parSiren)->not->toContain('Seq Scan');
});

function mamProprio(): Connection
{
    return DB::connection('pgsql_owner');
}

/** @return array{id: string, fiche: int, siren: string} */
function mamEspaceCommite(): array
{
    $owner = mamProprio();
    $id = (string) Str::uuid();
    $owner->table('workspaces')->insert([
        'id' => $id, 'slug' => 'zz-insee-rls-' . substr(str_replace('-', '', $id), 0, 8), 'name' => 'ZZ INSEE RLS', 'settings' => '{}',
        'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $siren = '94' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
    $fiche = (int) $owner->table('companies')->insertGetId([
        'workspace_id' => $id, 'siren' => $siren, 'denomination' => 'ZZ Rls avant', 'legal_form' => '5710',
        'department_code' => '38', 'discovery_source' => 'insee', 'metadata' => '{}', 'quality_score' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return ['id' => $id, 'fiche' => $fiche, 'siren' => $siren];
}

/** @param  array{id: string}  $e */
function mamNettoyer(array $e): void
{
    $owner = mamProprio();
    $owner->transaction(function () use ($owner, $e): void {
        // Nettoyage du TEST (données semées ici) — le produit ne supprime rien.
        foreach (['insee_mises_a_jour', 'companies'] as $table) {
            $owner->table($table)->where('workspace_id', $e['id'])->delete();
        }
        $owner->table('workspaces')->where('id', $e['id'])->delete();
    });
}

test('sous axion_app (RLS) : la mise à jour s applique à son espace, et à lui seul', function () {
    $a = mamEspaceCommite();
    $b = mamEspaceCommite();
    // Même SIREN dans l'autre espace : il ne doit pas bouger.
    mamProprio()->table('companies')->where('id', $b['fiche'])->update(['siren' => $a['siren']]);
    mamSirene([mamUnite($a['siren'], ['denominationUniteLegale' => 'ZZ RLS APRES'])], []);
    $precedente = DB::getDefaultConnection();

    try {
        DB::setDefaultConnection('pgsql_app');
        $role = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        expect($role->rolsuper)->toBeFalse()->and($role->rolbypassrls)->toBeFalse();

        $code = Artisan::call('crm:insee:mise-a-jour-mensuelle', ['--workspace' => $a['id'], '--depuis' => '2026-07-06', '--delai-ms' => 0]);
        expect($code)->toBe(0, Artisan::output());
    } finally {
        DB::setDefaultConnection($precedente);
        DB::connection('pgsql_app')->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
        DB::connection('pgsql_app')->disconnect();
    }

    try {
        expect(mamProprio()->table('companies')->where('id', $a['fiche'])->value('denomination'))->toBe('ZZ RLS APRES')
            ->and(mamProprio()->table('companies')->where('id', $b['fiche'])->value('denomination'))->toBe('ZZ Rls avant')
            ->and(mamProprio()->table('insee_mises_a_jour')->where('workspace_id', $a['id'])->value('statut'))->toBe('reussie');
    } finally {
        mamNettoyer($a);
        mamNettoyer($b);
        mamProprio()->disconnect();
    }
});
