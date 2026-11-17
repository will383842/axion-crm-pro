<?php

use App\Crm\Origines\RequetesOrigines;
use App\Envoi\Campagnes\FiltreOrigineContenu;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\OriginesJeu;
use Tests\TestCase;

/*
 * Lot PONT-LINKEDIN PO-A — FiltreOrigineContenu (mots / guides) et lecture des unités par réseau / mot / guide.
 * LECTURE SEULE. Chaque test de base de données tourne sous le rôle applicatif (RLS) ET sous le propriétaire.
 * Cahier PONT-LINKEDIN (#691) § 4. Données fictives (`*.example.test`). Aucun envoi.
 */
uses(TestCase::class, RefreshDatabase::class);

const FOC_MODES = ['app', 'proprio'];

afterEach(function () {
    WorkspaceContext::clear();
});

function focEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Foc', 'slug' => 'foc-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function focN(): int
{
    static $n = 40000000;

    return ++$n;
}

/** Une entreprise et un contact rattaché ; l'adresse de la personne est fictive. @return array{int, int} */
function focUnite(string $ws): array
{
    $n = focN();
    $co = (int) DB::table('companies')->insertGetId(['workspace_id' => $ws, 'siren' => sprintf('%09d', $n), 'denomination' => 'Société fictive']);
    $ct = (int) DB::table('contacts')->insertGetId(['workspace_id' => $ws, 'company_id' => $co, 'first_name' => 'Prenom', 'last_name' => 'Nom' . $n, 'email' => "prenom.nom{$n}@societe.example.test"]);

    return [$co, $ct];
}

/** @param list<array<string, mixed>> $origines @param array<string, mixed> $sur */
function focPersonne(string $ws, int $contact, int $entreprise, array $origines, array $sur = []): int
{
    return OriginesJeu::personne($ws, 'p' . focN() . '@example.test', $origines, array_merge(['contact_id' => $contact, 'company_id' => $entreprise], $sur));
}

/** @param Closure(): mixed $f */
function focComme(string $mode, string $ws, Closure $f): mixed
{
    if ($mode === 'app') {
        WorkspaceContext::set($ws);
        DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    }
    try {
        return $f();
    } finally {
        if ($mode === 'app') {
            DB::statement('RESET ROLE');
        }
    }
}

/** @param list<int> $e @param list<int> $c @param list<string>|null $r @param list<string>|null $m @param list<string>|null $g */
function focUnites(string $mode, string $ws, array $e, array $c, ?array $r, ?array $m, ?array $g): array
{
    return focComme($mode, $ws, static fn (): array => RequetesOrigines::unitesParOrigine($ws, $e, $c, $r, $m, $g));
}

// 1. Formes ---------------------------------------------------------------------------------------------------------

test('PO-A 1 formes valides : trim, dédoublonnage, tri, forme exacte de la colonne', function () {
    $f = FiltreOrigineContenu::depuisSaisie(['mots' => [' ia ', 'agent', 'ia'], 'guides' => ['g-2', 'g-1']]);
    expect($f->enColonne())->toBe(['mots' => ['agent', 'ia'], 'guides' => ['g-1', 'g-2']]);
    expect(FiltreOrigineContenu::depuisSaisie(['mots' => ['a']])->enColonne())->toBe(['mots' => ['a']]);
    expect(FiltreOrigineContenu::depuisSaisie(['guides' => ['g-1']])->enColonne())->toBe(['guides' => ['g-1']]);
    expect(FiltreOrigineContenu::depuisColonne(null))->toBeNull();
    expect(FiltreOrigineContenu::depuisColonne('{"guides":["g-2","g-1","g-1"]}')->enColonne())->toBe(['guides' => ['g-1', 'g-2']]);
    expect(FiltreOrigineContenu::depuisColonne(['mots' => ['b', 'a']])->enColonne())->toBe(['mots' => ['a', 'b']]);
});

test('PO-A 1 formes invalides : toute forme douteuse lève InvalidArgumentException', function (mixed $saisie) {
    expect(fn () => FiltreOrigineContenu::depuisSaisie($saisie))->toThrow(InvalidArgumentException::class);
})->with([
    'vide' => [[]],
    'clé inconnue' => [['reseaux' => ['linkedin']]],
    'clé inconnue en plus' => [['mots' => ['a'], 'x' => ['b']]],
    'liste vide' => [['mots' => []]],
    'onze mots' => [['mots' => ['m1', 'm2', 'm3', 'm4', 'm5', 'm6', 'm7', 'm8', 'm9', 'm10', 'm11']]],
    'majuscule (on ne corrige pas)' => [['mots' => ['Guide']]],
    'jeton de 65 caractères' => [['guides' => [str_repeat('a', 65)]]],
    'saut de ligne' => [['mots' => ["a\nb"]]],
    'pas une chaîne' => [['mots' => [1]]],
    'pas une liste' => [['mots' => ['k' => 'a']]],
    'mots pas un tableau' => [['mots' => 'a']],
]);

test('PO-A 1 depuisColonne : JSON illisible ou forme douteuse refusés', function () {
    foreach (['{', '[]', '{}', '"x"', '{"reseaux":["a"]}', '{"mots":[]}'] as $v) {
        expect(fn () => FiltreOrigineContenu::depuisColonne($v))->toThrow(InvalidArgumentException::class);
    }
});

test('PO-A 1 la porte est celle de FiltreOrigine::actif : drapeau éteint puis allumé', function () {
    $ws = focEspace();
    config(['origines.lecture_enabled' => false, 'origines.v3_enabled' => false]);
    expect(FiltreOrigineContenu::actif($ws))->toBeFalse();
    OriginesJeu::allumer();
    expect(FiltreOrigineContenu::actif($ws))->toBeFalse(); // fonction d'espace fermée
    OriginesJeu::ouvrir($ws);
    expect(FiltreOrigineContenu::actif($ws))->toBeTrue();
});

// 2 à 6. Lecture des unités -------------------------------------------------------------------------------------------

test('PO-A 2 mot seul : seule l\'unité dont une personne porte ce mot entre', function (string $mode) {
    $ws = focEspace();
    [$e1, $c1] = focUnite($ws);
    [$e2, $c2] = focUnite($ws);
    focPersonne($ws, $c1, $e1, [OriginesJeu::entree(['mot' => 'agent'])]);
    focPersonne($ws, $c2, $e2, [OriginesJeu::entree(['mot' => 'autre'])]);

    $r = focUnites($mode, $ws, [$e1, $e2], [$c1, $c2], null, ['agent'], null);

    expect(array_keys($r['entreprises']))->toBe([$e1])->and(array_keys($r['contacts']))->toBe([$c1])->and($r['contacts_opposes'])->toBe([]);
})->with(FOC_MODES);

test('PO-A 2 guide seul, puis réseau + mot + guide sur la même entrée', function (string $mode) {
    $ws = focEspace();
    [$e1, $c1] = focUnite($ws);
    [$e2, $c2] = focUnite($ws);
    focPersonne($ws, $c1, $e1, [OriginesJeu::entree(['reseau' => 'linkedin', 'mot' => 'agent', 'guide' => 'g-1'])]);
    focPersonne($ws, $c2, $e2, [OriginesJeu::entree(['reseau' => 'linkedin', 'mot' => 'agent', 'guide' => 'g-2'])]);

    $g = focUnites($mode, $ws, [$e1, $e2], [], null, null, ['g-1']);
    expect(array_keys($g['entreprises']))->toBe([$e1]);
    $tous = focUnites($mode, $ws, [$e1, $e2], [], ['linkedin'], ['agent'], ['g-2']);
    expect(array_keys($tous['entreprises']))->toBe([$e2]);
})->with(FOC_MODES);

test('PO-A 3 réseau + mot posés sur DEUX entrées différentes d\'une même personne : n\'entre pas', function (string $mode) {
    $ws = focEspace();
    [$e, $c] = focUnite($ws);
    focPersonne($ws, $c, $e, [
        OriginesJeu::entree(['reseau' => 'linkedin', 'mot' => 'autre', 'post_ref' => 'post_ref_foc_a']),
        OriginesJeu::entree(['reseau' => 'tiktok', 'mot' => 'agent', 'post_ref' => 'post_ref_foc_b']),
    ]);

    $r = focUnites($mode, $ws, [$e], [$c], ['linkedin'], ['agent'], null);
    expect($r['entreprises'])->toBe([])->and($r['contacts'])->toBe([]);
    // Contrôle : chaque critère seul, lui, trouve l'unité (le jeu n'est pas vide).
    expect(array_keys(focUnites($mode, $ws, [$e], [], ['linkedin'], null, null)['entreprises']))->toBe([$e]);
    expect(array_keys(focUnites($mode, $ws, [$e], [], null, ['agent'], null)['entreprises']))->toBe([$e]);
})->with(FOC_MODES);

test('PO-A 4 entrée masquée : ne compte pas', function (string $mode) {
    $ws = focEspace();
    [$e, $c] = focUnite($ws);
    focPersonne($ws, $c, $e, [OriginesJeu::entree(['mot' => 'agent', 'masque_le' => '2026-11-04T00:00:00Z'])]);

    $r = focUnites($mode, $ws, [$e], [$c], null, ['agent'], null);
    expect($r['entreprises'])->toBe([])->and($r['contacts'])->toBe([]);
})->with(FOC_MODES);

test('PO-A 5 entrée seulement dans l\'archive : compte ; archive masquée : ne compte pas', function (string $mode) {
    $ws = focEspace();
    [$e1, $c1] = focUnite($ws);
    [$e2, $c2] = focUnite($ws);
    $p1 = focPersonne($ws, $c1, $e1, [OriginesJeu::entree(['mot' => 'present'])]);
    OriginesJeu::archive($ws, $p1, OriginesJeu::entree(['mot' => 'ancien', 'post_ref' => 'post_ref_foc_arch1']));
    $p2 = focPersonne($ws, $c2, $e2, [OriginesJeu::entree(['mot' => 'present'])]);
    OriginesJeu::archive($ws, $p2, OriginesJeu::entree(['mot' => 'ancien', 'post_ref' => 'post_ref_foc_arch2']), '2026-11-05T00:00:00Z');

    $r = focUnites($mode, $ws, [$e1, $e2], [$c1, $c2], null, ['ancien'], null);
    expect(array_keys($r['entreprises']))->toBe([$e1])->and(array_keys($r['contacts']))->toBe([$c1]);
})->with(FOC_MODES);

test('PO-A 6 personne opposée portant le mot : l\'unité n\'entre pas, le contact est rendu opposé', function (string $mode) {
    $ws = focEspace();
    [$e, $c] = focUnite($ws);
    [$e2, $c2] = focUnite($ws);
    focPersonne($ws, $c, $e, [OriginesJeu::entree(['mot' => 'agent'])], ['opposition_at' => now()]);
    focPersonne($ws, $c2, $e2, [OriginesJeu::entree(['mot' => 'agent'])]);

    $r = focUnites($mode, $ws, [$e, $e2], [$c, $c2], null, ['agent'], null);
    expect(array_keys($r['entreprises']))->toBe([$e2])
        ->and(array_keys($r['contacts']))->toBe([$c2])
        ->and(array_keys($r['contacts_opposes']))->toBe([$c]);
})->with(FOC_MODES);

test('PO-A 8 cloisonnement : aucune personne de l\'espace A n\'est rendue pour l\'espace B', function (string $mode) {
    $a = focEspace();
    $b = focEspace();
    [$ea, $ca] = focUnite($a);
    focPersonne($a, $ca, $ea, [OriginesJeu::entree(['mot' => 'agent', 'reseau' => 'linkedin', 'guide' => 'g-1'])]);

    // Espace B demande les identifiants de A : rien ne sort, quelle que soit la porte.
    foreach ([[null, ['agent'], null], [['linkedin'], null, null], [null, null, ['g-1']]] as [$r, $m, $g]) {
        $sortie = focUnites($mode, $b, [$ea], [$ca], $r, $m, $g);
        expect($sortie)->toBe(['entreprises' => [], 'contacts' => [], 'contacts_opposes' => []]);
    }
    expect(array_keys(focUnites($mode, $a, [$ea], [$ca], null, ['agent'], null)['entreprises']))->toBe([$ea]);
})->with(FOC_MODES);

test('PO-A 9 tous les critères null : LogicException ; critère vide ou invalide : rien ne correspond', function () {
    $ws = focEspace();
    [$e, $c] = focUnite($ws);
    focPersonne($ws, $c, $e, [OriginesJeu::entree(['mot' => 'agent'])]);

    expect(fn () => RequetesOrigines::unitesParOrigine($ws, [$e], [$c], null, null, null))->toThrow(LogicException::class);
    $vide = ['entreprises' => [], 'contacts' => [], 'contacts_opposes' => []];
    expect(RequetesOrigines::unitesParOrigine($ws, [$e], [$c], null, [], null))->toBe($vide);
    expect(RequetesOrigines::unitesParOrigine($ws, [$e], [$c], null, ['Agent'], null))->toBe($vide);
    expect(RequetesOrigines::unitesParOrigine($ws, [$e], [$c], null, ["agent\n"], null))->toBe($vide);
});

test('PO-A 9 trois requêtes de données au plus', function () {
    $ws = focEspace();
    [$e, $c] = focUnite($ws);
    focPersonne($ws, $c, $e, [OriginesJeu::entree(['mot' => 'agent', 'reseau' => 'linkedin', 'guide' => 'g-1'])]);

    DB::enableQueryLog();
    DB::flushQueryLog();
    RequetesOrigines::unitesParOrigine($ws, [$e], [$c], ['linkedin'], ['agent'], ['g-1']);
    // Requêtes de DONNÉES : on écarte les lectures du catalogue (`Schema::hasColumn`).
    $n = count(array_filter(DB::getQueryLog(), static fn (array $q): bool => ! str_contains($q['query'], 'information_schema') && ! str_contains($q['query'], 'pg_')));
    DB::disableQueryLog();
    expect($n)->toBeLessThanOrEqual(3);
});

// 7. parGuide ---------------------------------------------------------------------------------------------------------

test('PO-A 7 parGuide : comptes exacts, entrées masquées exclues, filtres combinables', function (string $mode) {
    $ws = focEspace();
    [$e1, $c1] = focUnite($ws);
    [$e2, $c2] = focUnite($ws);
    focPersonne($ws, $c1, $e1, [
        OriginesJeu::entree(['guide' => 'g-1', 'reseau' => 'linkedin', 'post_ref' => 'post_ref_foc_g1']),
        OriginesJeu::entree(['guide' => 'g-1', 'reseau' => 'tiktok', 'post_ref' => 'post_ref_foc_g2']),
        OriginesJeu::entree(['guide' => 'g-2', 'reseau' => 'linkedin', 'post_ref' => 'post_ref_foc_g3', 'masque_le' => '2026-11-04T00:00:00Z']),
    ]);
    focPersonne($ws, $c2, $e2, [OriginesJeu::entree(['guide' => 'g-1', 'reseau' => 'linkedin', 'post_ref' => 'post_ref_foc_g4'])]);

    $tous = focComme($mode, $ws, static fn (): array => RequetesOrigines::parGuide($ws, RequetesOrigines::filtresVides(), 1, 50));
    expect($tous['total'])->toBe(1)
        ->and($tous['data'])->toHaveCount(1)
        ->and($tous['data'][0]['guide'])->toBe('g-1')
        ->and($tous['data'][0]['demandes'])->toBe(3)
        ->and($tous['data'][0]['personnes'])->toBe(2);

    $filtre = array_merge(RequetesOrigines::filtresVides(), ['reseau' => 'linkedin']);
    $lk = focComme($mode, $ws, static fn (): array => RequetesOrigines::parGuide($ws, $filtre, 1, 50));
    expect($lk['data'][0]['demandes'])->toBe(2)->and($lk['data'][0]['personnes'])->toBe(2);

    $parGuide = array_merge(RequetesOrigines::filtresVides(), ['guide' => 'g-2']);
    $g2 = focComme($mode, $ws, static fn (): array => RequetesOrigines::parGuide($ws, $parGuide, 1, 50));
    expect($g2['total'])->toBe(0)->and($g2['data'])->toBe([]); // la seule entrée g-2 est masquée
})->with(FOC_MODES);

test('PO-A 7 parGuide : cloisonnement et pagination', function (string $mode) {
    $a = focEspace();
    $b = focEspace();
    foreach (['g-1', 'g-2', 'g-3'] as $i => $g) {
        [$e, $c] = focUnite($a);
        focPersonne($a, $c, $e, [OriginesJeu::entree(['guide' => $g, 'post_ref' => 'post_ref_foc_p' . $i])]);
    }
    $deB = focComme($mode, $b, static fn (): array => RequetesOrigines::parGuide($b, RequetesOrigines::filtresVides(), 1, 50));
    expect($deB)->toBe(['data' => [], 'total' => 0]);

    $page2 = focComme($mode, $a, static fn (): array => RequetesOrigines::parGuide($a, RequetesOrigines::filtresVides(), 2, 2));
    expect($page2['total'])->toBe(3)->and($page2['data'])->toHaveCount(1);
})->with(FOC_MODES);

// Lecture seule ------------------------------------------------------------------------------------------------------

test('PO-A lecture seule : aucune écriture sur personnes, archive ni entreprises', function () {
    $ws = focEspace();
    [$e, $c] = focUnite($ws);
    focPersonne($ws, $c, $e, [OriginesJeu::entree(['mot' => 'agent'])]);
    $avant = [DB::table('personnes')->count(), DB::table('personne_origines_archive')->count(), md5(json_encode(DB::table('personnes')->get()))];

    RequetesOrigines::unitesParOrigine($ws, [$e], [$c], null, ['agent'], null);
    RequetesOrigines::parGuide($ws, RequetesOrigines::filtresVides(), 1, 10);

    expect([DB::table('personnes')->count(), DB::table('personne_origines_archive')->count(), md5(json_encode(DB::table('personnes')->get()))])->toBe($avant);
});
