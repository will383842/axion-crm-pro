<?php

/**
 * MISE À JOUR MENSUELLE INSEE — MÉMOIRE CONSTANTE (incident du 03/10/2026).
 *
 * En production, `crm:insee:mise-a-jour-mensuelle --dry-run --duree-max=20`
 * (rattrapage depuis le 2026-07-06) a épuisé les 128 Mo de PHP après ≈ 6
 * minutes. Ce qui est verrouillé ici :
 *  - des centaines de pages Sirene (`Http::fake`) : l'occupation mémoire ne
 *    dérive pas d'une page à l'autre (une page à la fois) ;
 *  - chaque unité du flux ne garde que sa période courante ;
 *  - la garde : mémoire proche de la limite → arrêt PROPRE, curseur
 *    mémorisé, statut `en_cours`, puis reprise exacte au passage suivant ;
 *  - le PIC d'une page lourde (réserves 1 et 2 de #320) : corps lu par
 *    morceaux sous `REPONSE_MAX_OCTETS`, page redemandée plus petite, sans
 *    perte ni doublon d'unité, mémoire de pointe sous un seuil.
 *
 * Fixtures FICTIVES (dépôt public) : SIREN 95xxxxxxx à clé de Luhn
 * volontairement INVALIDE, dénominations « ZZ ».
 */

use App\Crm\Insee\MiseAJourMensuelle;
use App\Services\Insee\HttpInseeClient;
use App\Services\Insee\PageSirene;
use GuzzleHttp\Psr7\PumpStream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $_ENV['INSEE_API_KEY'] = 'cle-de-banc';
    $_SERVER['INSEE_API_KEY'] = 'cle-de-banc';
    putenv('INSEE_API_KEY=cle-de-banc');
    Http::swap(new HttpFactory(app('events')));
});

afterEach(function () {
    unset($_ENV['INSEE_API_KEY'], $_SERVER['INSEE_API_KEY']);
    putenv('INSEE_API_KEY');
});

/** Un SIREN FICTIF : préfixe 95, clé de Luhn volontairement FAUSSE. */
function memoSiren(int $n): string
{
    $base = '95' . str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
    for ($c = 0; $c <= 9; $c++) {
        $somme = 0;
        foreach (array_reverse(str_split($base . $c)) as $i => $chiffre) {
            $v = (int) $chiffre * ($i % 2 === 1 ? 2 : 1);
            $somme += $v > 9 ? $v - 9 : $v;
        }
        if ($somme % 10 === 0) {
            return $base . (($c + 1) % 10);
        }
    }

    throw new LogicException('clé de Luhn introuvable');
}

/**
 * Une unité du flux, avec un long historique de périodes (comme une unité
 * ancienne). Entrepreneur individuel (1000) : jamais candidate à la création,
 * le test ne mesure que la lecture du flux.
 *
 * @return array<string, mixed>
 */
function memoUnite(string $siren, int $periodes = 12): array
{
    $liste = [];
    for ($k = 0; $k < $periodes; $k++) {
        $liste[] = [
            'dateFin' => $k === 0 ? null : '2020-01-01',
            'dateDebut' => $k === 0 ? '2026-09-01' : '2010-01-01',
            'etatAdministratifUniteLegale' => 'A',
            'denominationUniteLegale' => 'ZZ MEMOIRE ' . $siren . ' PERIODE ' . $k,
            'categorieJuridiqueUniteLegale' => '1000',
            'activitePrincipaleUniteLegale' => '62.01Z',
            'nicSiegeUniteLegale' => '00017',
        ];
    }

    return [
        'siren' => $siren,
        'statutDiffusionUniteLegale' => 'O',
        'trancheEffectifsUniteLegale' => '11',
        'categorieEntreprise' => 'PME',
        'periodesUniteLegale' => $liste,
    ];
}

/**
 * Simule un flux Sirene de `$pages` pages pleines, curseurs longs et tous
 * différents ; mesure `memory_get_usage()` à chaque requête. Le client HTTP
 * simulé n'ENREGISTRE pas les échanges (sinon c'est le banc qui grossirait).
 *
 * @param  list<int>  $mesures
 */
function memoFlux(int $pages, array &$mesures): void
{
    $unites = [];
    for ($i = 0; $i < HttpInseeClient::PAGE_SIRENE; $i++) {
        $unites[] = memoUnite(memoSiren(1000 + $i));
    }
    $curseur = static fn (int $k): string => 'AoE' . str_repeat('x', 40) . sprintf('%06d', $k);

    Http::fake(function (Request $r) use ($pages, $unites, $curseur, &$mesures) {
        $mesures[] = memory_get_usage();
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $p);
        $c = (string) ($p['curseur'] ?? '*');
        $k = $c === '*' ? 1 : (int) substr($c, -6);
        $suivant = $k >= $pages ? $c : $curseur($k + 1);

        return Http::response([
            'header' => ['total' => $pages * HttpInseeClient::PAGE_SIRENE, 'curseur' => $c, 'curseurSuivant' => $suivant],
            'unitesLegales' => $unites,
        ], 200);
    });
    (fn () => $this->recording = false)->call(Http::getFacadeRoot());
}

test('mémoire constante : 200 pages Sirene pleines, aucune dérive d une page à l autre', function () {
    $ws = F::espace('zz-insee-memo');
    F::fiche($ws, 'ZZ Memo', ['siren' => memoSiren(1), 'department_code' => '38']);
    $mesures = [];
    memoFlux(200, $mesures);

    $code = Artisan::call('crm:insee:mise-a-jour-mensuelle', [
        '--workspace' => $ws, '--depuis' => '2026-07-06', '--delai-ms' => 0, '--pause-ms' => 0, '--duree-max' => 0,
    ]);

    expect($code)->toBe(0)
        ->and($mesures)->toHaveCount(200)
        ->and(DB::table('insee_mises_a_jour')->where('workspace_id', $ws)->value('statut'))->toBe('reussie')
        ->and((int) DB::table('insee_mises_a_jour')->where('workspace_id', $ws)->value('pages'))->toBe(200);

    // Après mise en route (page 20), l'occupation ne grossit plus : au plus
    // 4 Mo de dérive sur 180 pages — accumuler les pages coûterait des
    // centaines de Mo.
    $repere = $mesures[19];
    $pic = max(array_slice($mesures, 20));
    expect($pic - $repere)->toBeLessThan(4 * 1024 * 1024);
});

test('le flux ne garde que la période courante, et la page rendue est vidée à la reprise', function () {
    $mesures = [];
    memoFlux(3, $mesures);
    $client = (new HttpInseeClient)->avecDelaiEntreRequetes(0);

    $lues = 0;
    $precedente = null;
    foreach ($client->iterateModificationsDepuis('2026-07-06') as $page) {
        expect($page)->toBeInstanceOf(PageSirene::class)
            ->and($page->unites)->toHaveCount(HttpInseeClient::PAGE_SIRENE)
            ->and($page->unites[0]['periodesUniteLegale'])->toHaveCount(1)
            ->and($page->unites[0]['periodesUniteLegale'][0]['dateDebut'])->toBe('2026-09-01');
        if ($precedente !== null) {
            // Le générateur a vidé la page précédente AVANT de lire celle-ci.
            expect($precedente->unites)->toBe([]);
        }
        $precedente = $page;
        $lues++;
    }
    expect($lues)->toBe(3);
});

test('garde mémoire : arrêt PROPRE avant la limite, curseur mémorisé, puis reprise exacte', function () {
    $ws = F::espace('zz-insee-memo');
    F::fiche($ws, 'ZZ Memo', ['siren' => memoSiren(2), 'department_code' => '38']);
    $mesures = [];
    memoFlux(4, $mesures);

    // Le plafond tombe pendant la page 1 : le passage s'arrête après elle.
    $maj = (new MiseAJourMensuelle((new HttpInseeClient)->avecDelaiEntreRequetes(0)))->avecPlafondMemoire(0);
    $resultat = $maj->executer(
        $ws,
        '2026-07-06',
        journal: function () use ($maj): void {
            $maj->avecPlafondMemoire(1);
        },
        pauseMs: 0,
    );

    $passage = DB::table('insee_mises_a_jour')->where('workspace_id', $ws)->first();
    expect($resultat['statut'])->toBe('en_cours')
        ->and($resultat['arret_memoire'])->toBeTrue()
        ->and($maj->arreteParLaMemoire())->toBeTrue()
        ->and($resultat['bilan']['pages'])->toBe(1)
        ->and($passage->statut)->toBe('en_cours')
        // Le curseur de la page SUIVANTE est mémorisé : rien n'est perdu.
        ->and($passage->curseur)->toEndWith('000002')
        ->and(MiseAJourMensuelle::repriseEnAttente($ws))->toBeTrue()
        ->and($mesures)->toHaveCount(1);

    // Le passage suivant (sans --depuis) reprend là, et va au bout.
    $code = Artisan::call('crm:insee:mise-a-jour-mensuelle', [
        '--workspace' => $ws, '--delai-ms' => 0, '--pause-ms' => 0, '--memoire-max' => 4096,
    ]);
    expect($code)->toBe(0)
        ->and(DB::table('insee_mises_a_jour')->where('workspace_id', $ws)->value('statut'))->toBe('reussie')
        ->and((int) DB::table('insee_mises_a_jour')->where('workspace_id', $ws)->value('pages'))->toBe(4)
        ->and($mesures)->toHaveCount(4)
        ->and(MiseAJourMensuelle::repriseEnAttente($ws))->toBeFalse();
});

test('garde mémoire par la commande : --memoire-max dépassé → arrêt propre annoncé, statut en_cours', function () {
    $ws = F::espace('zz-insee-memo');
    F::fiche($ws, 'ZZ Memo', ['siren' => memoSiren(3), 'department_code' => '38']);
    $mesures = [];
    memoFlux(2, $mesures);

    $code = Artisan::call('crm:insee:mise-a-jour-mensuelle', [
        '--workspace' => $ws, '--depuis' => '2026-07-06', '--delai-ms' => 0, '--pause-ms' => 0, '--memoire-max' => 1,
    ]);

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('Arrêt PROPRE')
        ->and(DB::table('insee_mises_a_jour')->where('workspace_id', $ws)->value('statut'))->toBe('en_cours')
        ->and(DB::table('companies')->where('workspace_id', $ws)->whereNotNull('deleted_at')->count())->toBe(0);
});

test('memory_limit lu en octets ; -1 = aucune garde', function () {
    expect(MiseAJourMensuelle::octets('128M'))->toBe(134217728)
        ->and(MiseAJourMensuelle::octets('1G'))->toBe(1073741824)
        ->and(MiseAJourMensuelle::octets('512k'))->toBe(524288)
        ->and(MiseAJourMensuelle::octets('-1'))->toBeLessThanOrEqual(0)
        ->and(MiseAJourMensuelle::octets(''))->toBe(0);

    $maj = new MiseAJourMensuelle(new HttpInseeClient);
    expect($maj->avecPlafondMemoire(0)->plafondMemoire())->toBe(0)
        ->and($maj->avecPlafondMemoire(1024)->plafondMemoire())->toBe(1024);
});

/**
 * Un flux Sirene LOURD (incident du 03/10/2026) : `$total` unités à long
 * historique (≈ 40 Ko de JSON chacune), servies à partir du curseur
 * `OFF<n>` selon le `nombre` demandé. Le corps est PRODUIT À LA DEMANDE
 * (`PumpStream`, sans `Content-Length`) : le banc ne garde rien en mémoire,
 * seul le client mesuré lit et décode. Les `nombre` demandés sont notés.
 *
 * @param  list<int>  $nombres
 */
function memoFluxLourd(int $total, array &$nombres): void
{
    Http::fake(function (Request $r) use ($total, &$nombres) {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $p);
        $nombres[] = (int) ($p['nombre'] ?? 0);
        $c = (string) ($p['curseur'] ?? '*');
        $debut = $c === '*' ? 0 : (int) substr($c, 3);
        $fin = min($total, $debut + (int) ($p['nombre'] ?? 0));
        $suivant = $fin >= $total ? $c : 'OFF' . $fin;

        $morceaux = (function () use ($total, $c, $suivant, $debut, $fin) {
            yield '{"header":' . json_encode(['total' => $total, 'curseur' => $c, 'curseurSuivant' => $suivant]) . ',"unitesLegales":[';
            for ($i = $debut; $i < $fin; $i++) {
                yield ($i > $debut ? ',' : '') . json_encode(memoUnite(memoSiren(5000 + $i), 160));
            }
            yield ']}';
        })();
        $corps = new PumpStream(function () use ($morceaux) {
            if (! $morceaux->valid()) {
                return false;
            }
            $morceau = $morceaux->current();
            $morceaux->next();

            return $morceau;
        });

        return Http::response($corps, 200);
    });
    (fn () => $this->recording = false)->call(Http::getFacadeRoot());
}

test('INCIDENT — page lourde : refusée au-delà du plafond, redemandée plus petite, sans perte, pic mémoire borné', function () {
    $nombres = [];
    memoFluxLourd(600, $nombres);
    $client = (new HttpInseeClient)->avecDelaiEntreRequetes(0);

    gc_collect_cycles();
    $base = memory_get_usage();
    memory_reset_peak_usage();

    $sirens = [];
    foreach ($client->iterateModificationsDepuis('2026-07-06') as $page) {
        foreach ($page->unites as $u) {
            $sirens[] = $u['siren'];
        }
    }
    $pic = memory_get_peak_usage() - $base;

    // 500 unités ≈ 20 Mo, 250 ≈ 10 Mo : refusées ; 125 ≈ 5 Mo : lue.
    expect(array_slice($nombres, 0, 3))->toBe([500, 250, 125])
        ->and($nombres)->toHaveCount(2 + 5)
        // Aucune unité perdue ni lue deux fois, dans l'ordre du flux.
        ->and($sirens)->toBe(array_map(static fn (int $i): string => memoSiren(5000 + $i), range(0, 599)));
    // Avant (#320) : la page entière de 500 unités (≈ 20 Mo, sous l'ancien
    // plafond de 32 Mo) était lue puis décodée d'un bloc — plus de 64 Mo de
    // pointe. Borné : corps ≤ 8 Mo + décodage d'une page réduite.
    expect($pic)->toBeLessThan(48 * 1024 * 1024);
});

test('une page trop lourde même au plancher lève proprement, sans boucler', function () {
    Http::fake(['*' => Http::response('{}', 200, ['Content-Length' => (string) (HttpInseeClient::REPONSE_MAX_OCTETS + 1)])]);
    $client = (new HttpInseeClient)->avecDelaiEntreRequetes(0);

    expect(fn () => iterator_to_array($client->iterateModificationsDepuis('2026-07-06'), false))
        ->toThrow(RuntimeException::class, 'trop volumineuse');
    $nombres = Http::recorded()->map(function (array $paire): int {
        parse_str((string) parse_url($paire[0]->url(), PHP_URL_QUERY), $p);

        return (int) $p['nombre'];
    })->all();
    expect($nombres)->toBe([500, 250, 125, 62, 31, HttpInseeClient::PAGE_SIRENE_PLANCHER]);
});

test('le flux ne demande que les champs lus ; un refus de « champs » (400) retombe sur la requête complète', function () {
    $avecChamps = [];
    Http::fake(function (Request $r) use (&$avecChamps) {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $p);
        $avecChamps[] = $p['champs'] ?? null;
        if (isset($p['champs'])) {
            return Http::response(['header' => ['statut' => 400, 'message' => 'champ inconnu']], 400);
        }

        return Http::response(['header' => ['curseur' => '*', 'curseurSuivant' => '*'], 'unitesLegales' => [memoUnite(memoSiren(7))]], 200);
    });
    $client = (new HttpInseeClient)->avecDelaiEntreRequetes(0);

    $pages = iterator_to_array($client->iterateModificationsDepuis('2026-07-06'), false);
    expect($pages)->toHaveCount(1)
        ->and($pages[0]->unites)->toHaveCount(1)
        ->and($avecChamps)->toHaveCount(2)
        ->and($avecChamps[1])->toBeNull()
        ->and(explode(',', (string) $avecChamps[0]))->toBe(HttpInseeClient::CHAMPS_UNITES);
    foreach (['siren', 'statutDiffusionUniteLegale', 'dateDebut', 'nicSiegeUniteLegale', 'categorieEntreprise'] as $champ) {
        expect(HttpInseeClient::CHAMPS_UNITES)->toContain($champ);
    }
});

test('essai à blanc interrompu : bilan PARTIEL annoncé, jamais « curseur mémorisé »', function () {
    $ws = F::espace('zz-insee-memo');
    F::fiche($ws, 'ZZ Memo', ['siren' => memoSiren(4), 'department_code' => '38']);
    $mesures = [];
    memoFlux(2, $mesures);

    $code = Artisan::call('crm:insee:mise-a-jour-mensuelle', [
        '--workspace' => $ws, '--depuis' => '2026-07-06', '--delai-ms' => 0, '--pause-ms' => 0, '--memoire-max' => 1, '--dry-run' => true,
    ]);

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('Essai INCOMPLET')
        ->and(Artisan::output())->toContain('--limite')
        ->and(Artisan::output())->not->toContain('curseur est mémorisé')
        ->and(DB::table('insee_mises_a_jour')->where('workspace_id', $ws)->count())->toBe(0);
});

test('--memoire-max démesuré : borné, sans débordement', function () {
    $ws = F::espace('zz-insee-memo');
    F::fiche($ws, 'ZZ Memo', ['siren' => memoSiren(5), 'department_code' => '38']);
    $mesures = [];
    memoFlux(2, $mesures);

    $code = Artisan::call('crm:insee:mise-a-jour-mensuelle', [
        '--workspace' => $ws, '--depuis' => '2026-07-06', '--delai-ms' => 0, '--pause-ms' => 0, '--memoire-max' => '99999999999999999999',
    ]);

    expect($code)->toBe(0)
        ->and(DB::table('insee_mises_a_jour')->where('workspace_id', $ws)->value('statut'))->toBe('reussie');
});
