<?php

/**
 * IMPORT DES FAMILLES INSEE (décision du 04/10/2026) —
 * `crm:insee:importer-familles`, et l'extension des créations de
 * `crm:insee:mise-a-jour-mensuelle` aux mêmes familles.
 *
 * L'API Sirene est SIMULÉE (`Http::fake`) : aucune requête réelle. Fixtures
 * FICTIVES (dépôt public) : SIREN de la plage 96xxxxxxx dont la clé de Luhn
 * est volontairement INVALIDE — garantis sans correspondance avec une unité
 * réelle ; dénominations « ZZ », communes inventées (xx999).
 *
 * Ce qui est verrouillé :
 *  - `--dry-run` n'écrit RIEN (ni fiche, ni journal) et donne le bilan :
 *    à créer / déjà présents / ignorés ;
 *  - seuls les SIREN ABSENTS sont créés ; une fiche existante n'est jamais
 *    modifiée ; après = avant + créées ;
 *  - la catégorie 1 et les non diffusibles (unité ou siège) ne sont JAMAIS
 *    importés, quoi que rende Sirene ;
 *  - le filtre « avec salariés » des familles 6 et 9 (requête ET revérification) ;
 *  - la reprise par curseur ; l'attente sur 429 ; `--jusqua` ; la fenêtre ;
 *  - la mémoire constante sur de nombreuses pages ;
 *  - la mise à jour mensuelle crée désormais aussi 7, 8, 6 et 9 (filtrés) ;
 *  - l'éligibilité d'envoi ne traite pas 6-9 en entrepreneurs individuels.
 */

use App\Crm\Campagnes\EligibiliteAdresse;
use App\Crm\Insee\FamillesInsee;
use App\Crm\Insee\ImportFamilles;
use App\Crm\Insee\LigneFicheInsee;
use App\Crm\Insee\MiseAJourMensuelle;
use App\Data\Sources\InseeCompanyData;
use App\Services\Insee\HttpInseeClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $_ENV['INSEE_API_KEY'] = 'cle-de-banc';
    $_SERVER['INSEE_API_KEY'] = 'cle-de-banc';
    putenv('INSEE_API_KEY=cle-de-banc');
    Http::swap(new HttpFactory(app('events')));
    // Un mardi, 10:00 heure de Paris : dans la fenêtre de lancement.
    $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00', 'Europe/Paris'));
});

afterEach(function () {
    unset($_ENV['INSEE_API_KEY'], $_SERVER['INSEE_API_KEY']);
    putenv('INSEE_API_KEY');
    $this->travelBack();
});

/** Un SIREN FICTIF : préfixe 96, clé de Luhn volontairement FAUSSE. */
function iffSiren(): string
{
    static $n = 0;
    $n++;

    return iffSirenNumero($n);
}

function iffSirenNumero(int $n): string
{
    $base = '96' . str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
    for ($c = 0; $c <= 9; $c++) {
        if (iffLuhnValide($base . $c)) {
            return $base . (($c + 1) % 10);
        }
    }

    throw new LogicException('clé de Luhn introuvable');
}

function iffLuhnValide(string $nombre): bool
{
    $somme = 0;
    foreach (array_reverse(str_split($nombre)) as $i => $chiffre) {
        $v = (int) $chiffre * ($i % 2 === 1 ? 2 : 1);
        $somme += $v > 9 ? $v - 9 : $v;
    }

    return $somme % 10 === 0;
}

/**
 * Une unité légale telle que la voie `/siren` de Sirene 3.11 la rend.
 *
 * @return array<string, mixed>
 */
function iffUnite(string $siren, string $categorie, ?string $tranche = '11', string $diffusion = 'O', string $etat = 'A'): array
{
    return array_filter([
        'siren' => $siren,
        'statutDiffusionUniteLegale' => $diffusion,
        'trancheEffectifsUniteLegale' => $tranche,
        'categorieEntreprise' => 'PME',
        'periodesUniteLegale' => [[
            'dateDebut' => '2020-01-01',
            'etatAdministratifUniteLegale' => $etat,
            'denominationUniteLegale' => 'ZZ UNITE ' . $siren,
            'categorieJuridiqueUniteLegale' => $categorie,
            'activitePrincipaleUniteLegale' => '84.11Z',
            'nicSiegeUniteLegale' => '00017',
        ]],
    ], static fn ($v) => $v !== null);
}

/**
 * Le siège (voie `/siret`) d'une unité.
 *
 * @return array<string, mixed>
 */
function iffSiege(string $siren, string $commune, string $categorie, ?string $tranche = '11', string $diffusionSiege = 'O'): array
{
    return [
        'siren' => $siren,
        'siret' => $siren . '00017',
        'etablissementSiege' => true,
        'statutDiffusionEtablissement' => $diffusionSiege,
        'uniteLegale' => array_filter([
            'etatAdministratifUniteLegale' => 'A',
            'statutDiffusionUniteLegale' => 'O',
            'denominationUniteLegale' => 'ZZ SIEGE ' . $siren,
            'categorieJuridiqueUniteLegale' => $categorie,
            'activitePrincipaleUniteLegale' => '84.11Z',
            'trancheEffectifsUniteLegale' => $tranche,
            'categorieEntreprise' => 'PME',
            'dateCreationUniteLegale' => '2020-01-01',
        ], static fn ($v) => $v !== null),
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
 * Simule Sirene : la recherche d'une famille (`/siren`, `periode(…)`) page
 * par page selon le curseur, et `/siret` (sièges). Toute autre requête lève.
 *
 * @param  array<string, array{0: list<array<string, mixed>>, 1: string}>  $pages  curseur => [unités, curseur suivant]
 * @param  array<string, array<string, mixed>>  $sieges  par SIRET
 */
function iffSirene(array $pages, array $sieges = [], ?callable $avantPage = null): void
{
    Http::swap(new HttpFactory(app('events')));
    Http::fake(function (Request $requete) use ($pages, $sieges, $avantPage) {
        parse_str((string) parse_url($requete->url(), PHP_URL_QUERY), $p);
        $q = (string) ($p['q'] ?? '');
        $chemin = (string) parse_url($requete->url(), PHP_URL_PATH);

        if (str_ends_with($chemin, '/siret')) {
            preg_match_all('/siret:(\d{14})/', $q, $m);
            $etabs = array_values(array_filter(array_map(static fn (string $s) => $sieges[$s] ?? null, $m[1])));

            return Http::response(['header' => ['total' => count($etabs)], 'etablissements' => $etabs], 200);
        }
        if (str_ends_with($chemin, '/siren') && str_contains($q, 'periode(')) {
            $curseur = (string) ($p['curseur'] ?? '');
            if ($avantPage !== null) {
                $avantPage($curseur);
            }
            [$unites, $suivant] = $pages[$curseur] ?? [[], $curseur];

            return Http::response(['header' => ['total' => 0, 'curseur' => $curseur, 'curseurSuivant' => $suivant], 'unitesLegales' => $unites], 200);
        }

        throw new LogicException('requête Sirene inattendue : ' . $chemin . ' ' . $q);
    });
}

/** @return list<array<string, string>> */
function iffRequetes(): array
{
    return Http::recorded()->map(function (array $paire): array {
        /** @var Request $r */
        $r = $paire[0];
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $p);

        return ['chemin' => (string) parse_url($r->url(), PHP_URL_PATH)] + array_map('strval', $p);
    })->values()->all();
}

/** @param  array<string, mixed>  $options */
function iffImporter(string $ws, string $famille, array $options = []): int
{
    return Artisan::call('crm:insee:importer-familles', array_merge([
        '--workspace' => $ws, '--famille' => $famille, '--delai-ms' => 0, '--pause-ms' => 0,
    ], $options));
}

/** @return list<array<string, mixed>> */
function iffInstantane(string $ws): array
{
    return DB::table('companies')->where('workspace_id', $ws)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
}

/**
 * Un espace (périmètre : département 38) et le scénario de la famille 7 :
 * page 1 — A absente (créée), B déjà présente, C entrepreneur individuel,
 * D non diffusible, E siège opposé ; page 2 — F siège à Paris (hors
 * périmètre), G absente (créée), H société 5xxx (autre famille).
 *
 * @return array{ws: string, s: array<string, string>, existante: int}
 */
function iffScenario7(): array
{
    $ws = F::espace('zz-insee-familles');
    $s = [];
    foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'x'] as $cle) {
        $s[$cle] = iffSiren();
    }
    // Le périmètre : une fiche de l'import INSEE en Isère.
    F::fiche($ws, 'ZZ Insee isere', ['siren' => $s['x'], 'department_code' => '38', 'legal_form' => '5710']);
    // B : déjà présente, avec des valeurs que Sirene contredit.
    $existante = F::fiche($ws, 'ZZ Commune saisie', [
        'siren' => $s['b'], 'department_code' => '38', 'legal_form' => '7210', 'discovery_source' => 'federations-2026',
        'prospection_status' => 'ready_for_outreach',
    ]);

    iffSirene(
        [
            '*' => [[
                iffUnite($s['a'], '7210', 'NN'),
                iffUnite($s['b'], '7210'),
                iffUnite($s['c'], '1000'),
                iffUnite($s['d'], '7210', '11', 'N'),
                iffUnite($s['e'], '7210'),
            ], 'P2'],
            'P2' => [[
                iffUnite($s['f'], '7220'),
                iffUnite($s['g'], '7346', '21'),
                iffUnite($s['h'], '5710'),
            ], 'P2'],
        ],
        [
            $s['a'] . '00017' => iffSiege($s['a'], '38999', '7210', 'NN'),
            $s['b'] . '00017' => iffSiege($s['b'], '38999', '7210'),
            $s['c'] . '00017' => iffSiege($s['c'], '38999', '1000'),
            $s['d'] . '00017' => iffSiege($s['d'], '38999', '7210'),
            $s['e'] . '00017' => iffSiege($s['e'], '38999', '7210', '11', 'P'),
            $s['f'] . '00017' => iffSiege($s['f'], '75999', '7220'),
            $s['g'] . '00017' => iffSiege($s['g'], '38998', '7346', '21'),
            $s['h'] . '00017' => iffSiege($s['h'], '38999', '5710'),
        ],
    );

    return ['ws' => $ws, 's' => $s, 'existante' => $existante];
}

test('essai à blanc : bilan à créer / déjà présents / ignorés, RIEN n est écrit', function () {
    $e = iffScenario7();
    $avant = iffInstantane($e['ws']);

    expect(iffImporter($e['ws'], '7', ['--dry-run' => true]))->toBe(0);
    $sortie = Artisan::output();

    expect($sortie)->toContain('ESSAI À BLANC')
        ->and($sortie)->toMatch('/à créer : 2\b/u')
        ->and($sortie)->toMatch('/créées : 0\b/u')
        ->and($sortie)->toMatch('/déjà présents : 1\b/u')
        ->and($sortie)->toMatch('/ignorés : 5\b/u')
        ->and($sortie)->toMatch('/entrepreneurs individuels : 1\b/u')
        ->and($sortie)->toMatch('/non diffusibles : 1\b/u')
        ->and($sortie)->toMatch('/autre famille : 1\b/u')
        ->and($sortie)->toMatch('/hors périmètre \(département\) : 1\b/u')
        ->and($sortie)->toMatch('/mémoire \d+\.\d Mo/u');

    expect(iffInstantane($e['ws']))->toBe($avant)
        ->and(DB::table('insee_imports_familles')->count())->toBe(0);
});

test('seuls les SIREN absents sont créés, sans jamais toucher une fiche existante ; après = avant + créées', function () {
    $e = iffScenario7();
    $s = $e['s'];
    $existante = (array) DB::table('companies')->where('id', $e['existante'])->first();
    $avant = DB::table('companies')->where('workspace_id', $e['ws'])->count();

    expect(iffImporter($e['ws'], '7'))->toBe(0);
    $sortie = Artisan::output();

    expect(DB::table('companies')->where('workspace_id', $e['ws'])->count())->toBe($avant + 2)
        ->and($sortie)->toMatch('/créées : 2\b/u')
        ->and($sortie)->toContain('après = avant + créées')
        ->and((array) DB::table('companies')->where('id', $e['existante'])->first())->toBe($existante)
        ->and(DB::table('companies')->where('workspace_id', $e['ws'])->whereNotNull('deleted_at')->count())->toBe(0);

    $a = DB::table('companies')->where('workspace_id', $e['ws'])->where('siren', $s['a'])->first();
    expect($a)->not->toBeNull()
        ->and($a->discovery_source)->toBe('insee')
        ->and($a->legal_form)->toBe('7210')
        ->and($a->effectif_range)->toBe('NN')
        ->and($a->naf)->toBe('84.11Z')
        ->and($a->department_code)->toBe('38')
        ->and($a->postcode)->toBe('38000')
        ->and($a->address)->toBe('1 RUE DE L EXEMPLE')
        ->and($a->siret)->toBe($s['a'] . '00017')
        ->and($a->entity_nature)->toBe('institution')
        ->and($a->insee_verifiee_le)->not->toBeNull()
        ->and(json_decode((string) $a->metadata, true)['lot_import'] ?? null)->toBe(FamillesInsee::LOT)
        ->and(json_decode((string) $a->field_origins, true))->toMatchArray(['denomination' => 'insee', 'legal_form' => 'insee']);
    expect(DB::table('companies')->where('siren', $s['g'])->exists())->toBeTrue();
    foreach (['c', 'd', 'e', 'f', 'h'] as $hors) {
        expect(DB::table('companies')->where('siren', $s[$hors])->exists())->toBeFalse("{$hors} ne devait pas être créé");
    }

    $journal = DB::table('insee_imports_familles')->where('workspace_id', $e['ws'])->first();
    expect($journal->statut)->toBe('reussie')
        ->and($journal->famille)->toBe('7')
        ->and($journal->lot)->toBe(FamillesInsee::LOT)
        ->and((int) $journal->fiches_avant)->toBe($avant)
        ->and((int) $journal->fiches_apres)->toBe($avant + 2)
        ->and(json_decode((string) $journal->bilan, true)['creees'])->toBe(2);

    // Rejouer la famille ne crée rien de plus : tout est déjà présent.
    expect(iffImporter($e['ws'], '7'))->toBe(0);
    expect(DB::table('companies')->where('workspace_id', $e['ws'])->count())->toBe($avant + 2)
        ->and(Artisan::output())->toMatch('/déjà présents : 3\b/u');
});

test('catégorie 1 et non diffusibles ne sont JAMAIS importés, même rendus par Sirene', function () {
    $ws = F::espace('zz-insee-familles');
    F::fiche($ws, 'ZZ Insee isere', ['siren' => iffSiren(), 'department_code' => '38']);
    $ei = iffSiren();
    $partielle = iffSiren();
    $opposee = iffSiren();
    $siegeOppose = iffSiren();
    iffSirene(
        ['*' => [[
            iffUnite($ei, '1000'),
            iffUnite($partielle, '9220', '11', 'P'),
            iffUnite($opposee, '9220', '11', 'N'),
            iffUnite($siegeOppose, '9220'),
        ], '*']],
        [
            $ei . '00017' => iffSiege($ei, '38999', '1000'),
            $partielle . '00017' => iffSiege($partielle, '38999', '9220'),
            $opposee . '00017' => iffSiege($opposee, '38999', '9220'),
            $siegeOppose . '00017' => iffSiege($siegeOppose, '38999', '9220', '11', 'N'),
        ],
    );

    expect(iffImporter($ws, '9'))->toBe(0);
    foreach ([$ei, $partielle, $opposee, $siegeOppose] as $siren) {
        expect(DB::table('companies')->where('siren', $siren)->exists())->toBeFalse();
    }

    // La famille 1 n'est pas une option.
    expect(iffImporter($ws, '1'))->toBe(1)
        ->and(Artisan::output())->toContain('jamais 1');
    expect(FamillesInsee::admise('1000', '11'))->toBeFalse()
        ->and(FamillesInsee::admise('1000', '11', '1'))->toBeFalse();
});

test('familles 6 et 9 : avec salariés seulement (requête ET revérification) ; 7 et 8 : toutes', function (string $famille, string $categorie, bool $filtre) {
    $ws = F::espace('zz-insee-familles');
    F::fiche($ws, 'ZZ Insee isere', ['siren' => iffSiren(), 'department_code' => '38']);
    $avec = iffSiren();
    $nn = iffSiren();
    $zero = iffSiren();
    $sans = iffSiren();
    iffSirene(
        ['*' => [[
            iffUnite($avec, $categorie, '11'),
            iffUnite($nn, $categorie, 'NN'),
            iffUnite($zero, $categorie, '00'),
            iffUnite($sans, $categorie, null),
        ], '*']],
        [
            $avec . '00017' => iffSiege($avec, '38999', $categorie, '11'),
            $nn . '00017' => iffSiege($nn, '38999', $categorie, 'NN'),
            $zero . '00017' => iffSiege($zero, '38999', $categorie, '00'),
            $sans . '00017' => iffSiege($sans, '38999', $categorie, null),
        ],
    );

    expect(iffImporter($ws, $famille))->toBe(0);

    $q = collect(iffRequetes())->first(fn (array $r) => str_contains($r['q'] ?? '', 'periode('))['q'];
    expect($q)->toContain('periode(etatAdministratifUniteLegale:A AND categorieJuridiqueUniteLegale:' . $famille . '* AND -dateFin:*)')
        ->and($q)->toContain('statutDiffusionUniteLegale:O');
    if ($filtre) {
        expect($q)->toContain('trancheEffectifsUniteLegale:[01 TO 53]');
    } else {
        expect($q)->not->toContain('trancheEffectifs');
    }

    expect(DB::table('companies')->where('siren', $avec)->exists())->toBeTrue();
    foreach ([$nn, $zero, $sans] as $siren) {
        expect(DB::table('companies')->where('siren', $siren)->exists())->toBe(! $filtre);
    }
})->with([
    'famille 6 (SCI)' => ['6', '6540', true],
    'famille 9 (association)' => ['9', '9220', true],
    'famille 7 (commune)' => ['7', '7210', false],
    'famille 8 (mutuelle)' => ['8', '8210', false],
]);

test('reprise : --limite arrête proprement, le lancement suivant repart du curseur mémorisé', function () {
    $e = iffScenario7();
    $s = $e['s'];

    // 5 unités en page 1 : la limite tombe en fin de page 1.
    expect(iffImporter($e['ws'], '7', ['--limite' => 5]))->toBe(0);
    expect(Artisan::output())->toContain('Passage INACHEVÉ');
    $journal = DB::table('insee_imports_familles')->where('workspace_id', $e['ws'])->first();
    expect($journal->statut)->toBe('en_cours')
        ->and($journal->curseur)->toBe('P2')
        ->and(DB::table('companies')->where('siren', $s['a'])->exists())->toBeTrue()
        ->and(DB::table('companies')->where('siren', $s['g'])->exists())->toBeFalse();

    $deja = count(iffRequetes());
    expect(iffImporter($e['ws'], '7'))->toBe(0);
    expect(Artisan::output())->toContain('reprise du passage inachevé');

    $flux = array_values(array_filter(array_slice(iffRequetes(), $deja), fn (array $r) => str_contains($r['q'] ?? '', 'periode(')));
    expect($flux)->toHaveCount(1)
        ->and($flux[0]['curseur'])->toBe('P2')
        ->and(DB::table('companies')->where('siren', $s['g'])->exists())->toBeTrue()
        ->and(DB::table('insee_imports_familles')->where('workspace_id', $e['ws'])->count())->toBe(1);
    $journal = DB::table('insee_imports_familles')->where('workspace_id', $e['ws'])->first();
    expect($journal->statut)->toBe('reussie')
        ->and(json_decode((string) $journal->bilan, true)['creees'])->toBe(2);
});

test('quota : un 429 de Sirene est ATTENDU (20 s) puis retenté sur le même curseur', function () {
    $ws = F::espace('zz-insee-familles');
    F::fiche($ws, 'ZZ Insee isere', ['siren' => iffSiren(), 'department_code' => '38']);
    $siren = iffSiren();
    Sleep::fake();
    Http::fakeSequence('api.insee.fr/api-sirene/3.11/siren*')
        ->push(['fault' => 'Too Many Requests'], 429)
        ->push(['header' => ['curseur' => '*', 'curseurSuivant' => '*'], 'unitesLegales' => [iffUnite($siren, '8210')]], 200);
    Http::fake(['api.insee.fr/api-sirene/3.11/siret*' => Http::response([
        'header' => ['total' => 1], 'etablissements' => [iffSiege($siren, '38999', '8210')],
    ], 200)]);

    expect(iffImporter($ws, '8'))->toBe(0);
    expect(DB::table('companies')->where('siren', $siren)->exists())->toBeTrue();
    $curseurs = collect(iffRequetes())->filter(fn (array $r) => str_ends_with($r['chemin'], '/siren'))->pluck('curseur')->all();
    expect($curseurs)->toBe(['*', '*']);
    Sleep::assertSlept(fn (CarbonInterval $d): bool => (int) $d->totalSeconds === 20, 1);
});

test('--jusqua : le passage s arrête proprement à l heure dite et garde son curseur', function () {
    $e = iffScenario7();
    // Lire la page 1 « prend » deux minutes.
    iffSirene(
        ['*' => [[iffUnite($e['s']['a'], '7210')], 'P2'], 'P2' => [[iffUnite($e['s']['g'], '7346')], 'P2']],
        [
            $e['s']['a'] . '00017' => iffSiege($e['s']['a'], '38999', '7210'),
            $e['s']['g'] . '00017' => iffSiege($e['s']['g'], '38999', '7346'),
        ],
        function (string $curseur): void {
            $this->travel(2)->minutes();
        },
    );

    expect(iffImporter($e['ws'], '7', ['--jusqua' => '10:01']))->toBe(0);
    expect(Artisan::output())->toContain('heure d arrêt atteinte');
    $journal = DB::table('insee_imports_familles')->where('workspace_id', $e['ws'])->first();
    expect($journal->statut)->toBe('en_cours')
        ->and($journal->curseur)->toBe('P2')
        ->and(DB::table('companies')->where('siren', $e['s']['g'])->exists())->toBeFalse();

    // Une heure déjà passée, ou mal écrite, est refusée.
    expect(iffImporter($e['ws'], '7', ['--jusqua' => '09:00']))->toBe(1)
        ->and(iffImporter($e['ws'], '7', ['--jusqua' => '25h']))->toBe(1);
});

test('fenêtre de la mise à jour mensuelle : un passage réel refusé hors fenêtre, l essai à blanc permis', function (string $instant) {
    $e = iffScenario7();
    $this->travelTo(CarbonImmutable::parse($instant, 'Europe/Paris'));
    $avant = iffInstantane($e['ws']);

    expect(iffImporter($e['ws'], '7'))->toBe(1)
        ->and(Artisan::output())->toContain('Refusé')
        ->and(iffInstantane($e['ws']))->toBe($avant)
        ->and(iffImporter($e['ws'], '7', ['--dry-run' => true]))->toBe(0);
})->with([
    'dimanche' => ['2026-10-04 10:00'],
    'lundi' => ['2026-10-05 10:00'],
    'le 2 du mois' => ['2026-12-02 10:00'],
    'avant 08:00' => ['2026-10-06 07:59'],
    'après 19:00' => ['2026-10-06 19:00'],
]);

test('le verrou de la mise à jour mensuelle est respecté : jamais les deux ensemble', function () {
    $e = iffScenario7();
    $verrou = Cache::lock(MiseAJourMensuelle::VERROU, 60);
    expect($verrou->get())->toBeTrue();
    try {
        expect(iffImporter($e['ws'], '7'))->toBe(1)
            ->and(Artisan::output())->toContain('tourne déjà');
    } finally {
        $verrou->release();
    }
    expect(iffImporter($e['ws'], '7'))->toBe(0);
});

test('mémoire CONSTANTE : des dizaines de pages de 1000 unités, une seule page vit à la fois', function () {
    $ws = F::espace('zz-insee-familles');
    F::fiche($ws, 'ZZ Insee isere', ['siren' => iffSiren(), 'department_code' => '38']);
    $pages = 60;
    Http::fake(function (Request $requete) use ($pages) {
        parse_str((string) parse_url($requete->url(), PHP_URL_QUERY), $p);
        $n = ($p['curseur'] ?? '*') === '*' ? 0 : (int) substr((string) $p['curseur'], 1);
        $unites = [];
        for ($i = 0; $i < 1000; $i++) {
            // Siège sans NIC : écartée sans requête `/siret` ni écriture ; seule
            // la lecture des pages est mesurée.
            $u = iffUnite(iffSirenNumero(100000 + $n * 1000 + $i), '7210');
            $u['periodesUniteLegale'][0]['nicSiegeUniteLegale'] = null;
            $unites[] = $u;
        }
        $suivant = $n + 1 >= $pages ? 'C' . $n : 'C' . ($n + 1);

        return Http::response(['header' => ['total' => $pages * 1000, 'curseur' => 'C' . $n, 'curseurSuivant' => $suivant], 'unitesLegales' => $unites], 200);
    });
    // Le faux client garde chaque réponse pour `Http::recorded()` : c'est le
    // BANC qui accumulerait, pas l'import (même geste que
    // `InseeMiseAJourMemoireTest`).
    (fn () => $this->recording = false)->call(Http::getFacadeRoot());

    $mesures = [];
    $r = (new ImportFamilles((new HttpInseeClient)->avecDelaiEntreRequetes(0)))->executer(
        $ws,
        '7',
        true,
        0,
        null,
        function (string $ligne) use (&$mesures): void {
            $mesures[] = memory_get_usage(false);
        },
    );

    expect($r['statut'])->toBe('reussie')
        ->and($r['bilan']['pages'])->toBe($pages)
        ->and($r['bilan']['unites_lues'])->toBe($pages * 1000)
        ->and($r['bilan']['ignorees_siege'])->toBe($pages * 1000);
    // De la 10e à la dernière page : pas de dérive (une page à la fois).
    expect($mesures[$pages - 1] - $mesures[9])->toBeLessThan(2 * 1024 * 1024);
});

test('mise à jour mensuelle : elle crée AUSSI les familles 7 et 8 (toutes), 6 et 9 (avec salariés) — jamais 1', function () {
    $ws = F::espace('zz-insee-familles');
    F::fiche($ws, 'ZZ Insee isere', ['siren' => iffSiren(), 'department_code' => '38']);
    $cas = [
        'commune' => ['7210', 'NN', true],
        'mutuelle' => ['8210', null, true],
        'sci_employeuse' => ['6540', '03', true],
        'sci_sans_salarie' => ['6540', 'NN', false],
        'association_employeuse' => ['9220', '12', true],
        'association_sans_salarie' => ['9220', '00', false],
        'societe' => ['5710', '11', true],
        'entrepreneur' => ['1000', '11', false],
        'groupement_de_fait' => ['2110', '11', false],
    ];
    $sirens = [];
    $unites = [];
    $sieges = [];
    foreach ($cas as $nom => [$cj, $tranche]) {
        $sirens[$nom] = $siren = iffSiren();
        $unites[] = iffUnite($siren, $cj, $tranche);
        $sieges[$siren . '00017'] = iffSiege($siren, '38999', $cj, $tranche);
    }
    Http::fake(function (Request $requete) use ($unites, $sieges) {
        parse_str((string) parse_url($requete->url(), PHP_URL_QUERY), $p);
        $q = (string) ($p['q'] ?? '');
        if (str_ends_with((string) parse_url($requete->url(), PHP_URL_PATH), '/siret')) {
            preg_match_all('/siret:(\d{14})/', $q, $m);

            return Http::response(['header' => [], 'etablissements' => array_values(array_filter(array_map(static fn (string $s) => $sieges[$s] ?? null, $m[1])))], 200);
        }
        if (str_contains($q, 'dateDernierTraitementUniteLegale')) {
            return Http::response(['header' => ['curseur' => '*', 'curseurSuivant' => '*'], 'unitesLegales' => $unites], 200);
        }

        return Http::response(['header' => [], 'unitesLegales' => []], 200);
    });

    $code = Artisan::call('crm:insee:mise-a-jour-mensuelle', ['--workspace' => $ws, '--depuis' => '2026-09-01', '--delai-ms' => 0, '--pause-ms' => 0]);
    expect($code)->toBe(0, Artisan::output());

    foreach ($cas as $nom => [$cj, $tranche, $cree]) {
        expect(DB::table('companies')->where('workspace_id', $ws)->where('siren', $sirens[$nom])->exists())->toBe($cree, $nom);
    }
    // La nature suit la famille ; une société commerciale reste « entreprise ».
    $nature = fn (string $nom) => DB::table('companies')->where('siren', $sirens[$nom])->value('entity_nature');
    expect($nature('commune'))->toBe('institution')
        ->and($nature('association_employeuse'))->toBe('association')
        ->and($nature('societe'))->toBe('entreprise');
});

test('éligibilité d envoi : 6, 7, 8 et 9 ne sont pas des entrepreneurs individuels ; 1 l est toujours', function () {
    foreach (['6540', '6589', '7210', '7346', '8210', '8410', '9220', '9300'] as $cj) {
        expect(EligibiliteAdresse::estEntrepriseIndividuelle($cj))->toBeFalse($cj);
    }
    expect(EligibiliteAdresse::estEntrepriseIndividuelle('1000'))->toBeTrue();

    // Les autres motifs s'appliquent à ces fiches comme à toutes.
    $valide = ['verification' => 'valide'];
    expect(EligibiliteAdresse::motif('contact@zz-exemple.invalid', [['non_diffusible' => true] + $valide]))->toBe(EligibiliteAdresse::NON_DIFFUSIBLE)
        ->and(EligibiliteAdresse::motif('contact@zz-exemple.invalid', [['site_non_verifie' => true] + $valide]))->toBe(EligibiliteAdresse::SITE_NON_VERIFIE)
        ->and(EligibiliteAdresse::motif('pas-une-adresse', [$valide]))->toBe(EligibiliteAdresse::INVALIDE);
});

test('nature d une fiche créée : 5 reste entreprise ; 7 institution ; 9 association ; NAF d organisation pris en compte', function () {
    $d = static fn (string $cj, ?string $naf = null): InseeCompanyData => new InseeCompanyData(siren: iffSiren(), denomination: 'ZZ', naf: $naf, legalForm: $cj);
    expect(LigneFicheInsee::nature($d('5710', '84.11Z')))->toBe('entreprise')
        ->and(LigneFicheInsee::nature($d('7210', '86.10Z')))->toBe('institution')
        ->and(LigneFicheInsee::nature($d('9300', '88.10A')))->toBe('association')
        ->and(LigneFicheInsee::nature($d('9220', '94.99Z')))->toBe('association')
        ->and(LigneFicheInsee::nature($d('8410', '94.20Z')))->toBe('federation')
        ->and(LigneFicheInsee::nature($d('6540', '68.20B')))->toBe('entreprise');
});

test('Luhn : les SIREN des fixtures sont INVALIDES (garantis fictifs)', function () {
    foreach (range(1, 300) as $i) {
        expect(iffLuhnValide(iffSirenNumero($i)))->toBeFalse();
    }
    expect(iffLuhnValide('96000000' . (function (): int {
        for ($c = 0; $c <= 9; $c++) {
            if (iffLuhnValide('96000000' . $c)) {
                return $c;
            }
        }

        return -1;
    })()))->toBeTrue();
});

test('le journal des passages : RLS forcée, et le rôle applicatif ne peut rien en supprimer', function () {
    $t = DB::selectOne("SELECT relrowsecurity AS rls, relforcerowsecurity AS forcee FROM pg_class WHERE relname = 'insee_imports_familles'");
    expect($t->rls)->toBeTrue()->and($t->forcee)->toBeTrue();

    $droits = DB::selectOne(
        "SELECT has_table_privilege('axion_app', 'insee_imports_familles', 'DELETE') AS del,
                has_table_privilege('axion_app', 'insee_imports_familles', 'TRUNCATE') AS tru,
                has_table_privilege('axion_app', 'insee_imports_familles', 'UPDATE') AS upd",
    );
    expect($droits->del)->toBeFalse()
        ->and($droits->tru)->toBeFalse()
        ->and($droits->upd)->toBeTrue();
});
