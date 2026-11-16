<?php

use App\Crm\Origines\RequetesOrigines;
use App\Envoi\Campagnes\FiltreOrigine;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\OriginesJeu;
use Tests\TestCase;

/*
 * Lot X1-L2b-B — FiltreOrigine (forme, garde « actif ») et RequetesOrigines::reseauxDesUnites (LECTURE SEULE).
 * Chaque test de lecture tourne sous le rôle applicatif (RLS) ET sous le propriétaire. Données fictives (`*.example.test`).
 */
uses(TestCase::class, RefreshDatabase::class);

const FIO_MODES = ['app', 'proprio'];

function fioEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Fio', 'slug' => 'fio-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function fioN(): int
{
    static $n = 20000000;

    return ++$n;
}

function fioEntreprise(string $ws): int
{
    return (int) DB::table('companies')->insertGetId(['workspace_id' => $ws, 'siren' => fioSiren(fioN()), 'denomination' => 'Société fictive']);
}

function fioSiren(int $base): string
{
    $b = str_pad((string) $base, 8, '0', STR_PAD_LEFT);
    for ($cle = 0; $cle <= 9; $cle++) {
        $somme = 0;
        foreach (str_split($b . $cle) as $i => $d) {
            $n = (int) $d * ($i % 2 === 1 ? 2 : 1);
            $somme += $n > 9 ? $n - 9 : $n;
        }
        if ($somme % 10 === 0) {
            return $b . $cle;
        }
    }
    throw new LogicException('inatteignable');
}

function fioContact(string $ws, int $co): int
{
    return (int) DB::table('contacts')->insertGetId(['workspace_id' => $ws, 'company_id' => $co, 'first_name' => 'Prenom', 'last_name' => 'Nom' . fioN(), 'email' => 'c' . fioN() . '@example.test']);
}

/** @param list<string> $reseaux  @param array<string, mixed> $sur */
function fioPersonne(string $ws, array $reseaux, array $sur = []): int
{
    $origines = array_map(static fn (string $r): array => OriginesJeu::entree(['reseau' => $r]), $reseaux);

    return OriginesJeu::personne($ws, 'p' . fioN() . '@example.test', $origines, $sur);
}

/** @return array{entreprises: array<int,true>, contacts: array<int,true>, contacts_opposes: array<int,true>} */
function fioLire(string $mode, string $ws, array $entreprises, array $contacts, array $reseaux): array
{
    if ($mode === 'app') {
        WorkspaceContext::set($ws);
        DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    }
    try {
        return RequetesOrigines::reseauxDesUnites($ws, $entreprises, $contacts, $reseaux);
    } finally {
        if ($mode === 'app') {
            DB::statement('RESET ROLE');
        }
    }
}

test('depuisSaisie : trim, déduplique, trie ; refuse les formes invalides', function () {
    expect(FiltreOrigine::depuisSaisie([' linkedin ', 'facebook', 'linkedin'])->reseaux)->toBe(['facebook', 'linkedin']);
    expect(FiltreOrigine::depuisSaisie(['linkedin'])->enColonne())->toBe(['reseaux' => ['linkedin']]);
    $mauvais = [[], array_map(static fn (int $i): string => 'r' . $i, range(1, 11)), ['LinkedIn'], ['x@example.test'], 'linkedin', null, [1], [['a']], ['a' => 'linkedin'], ['  ']];
    foreach ($mauvais as $m) {
        expect(fn () => FiltreOrigine::depuisSaisie($m))->toThrow(InvalidArgumentException::class);
    }
    expect(FiltreOrigine::depuisSaisie(array_map(static fn (int $i): string => 'r' . $i, range(1, 10)))->reseaux)->toHaveCount(10);
});

test('depuisColonne : null → null, JSON valide → objet, hors forme → exception', function () {
    expect(FiltreOrigine::depuisColonne(null))->toBeNull();
    expect(FiltreOrigine::depuisColonne('{"reseaux":["linkedin","facebook"]}')?->reseaux)->toBe(['facebook', 'linkedin']);
    expect(FiltreOrigine::depuisColonne(['reseaux' => ['linkedin']])?->enColonne())->toBe(['reseaux' => ['linkedin']]);
    expect(FiltreOrigine::depuisColonne(['reseaux' => ['b', 'a', 'a']])?->reseaux)->toBe(['a', 'b']);
    foreach (['{"reseaux":[]}', '{"reseaux":["LinkedIn"]}', '{"reseaux":["a"],"autre":1}', '[]', '"x"', '{', '{"reseaux":"a"}', 12] as $m) {
        expect(fn () => FiltreOrigine::depuisColonne($m))->toThrow(InvalidArgumentException::class);
    }
});

test('actif : chaque condition fausse tour à tour → faux ; tout vrai → vrai ; drapeau éteint : zéro requête', function () {
    $ws = fioEspace();
    OriginesJeu::allumer();
    OriginesJeu::ouvrir($ws);
    expect(FiltreOrigine::actif($ws))->toBeTrue();

    OriginesJeu::ouvrir($ws, false);
    expect(FiltreOrigine::actif($ws))->toBeFalse();
    DB::table('fonctions_espace')->where('espace_id', $ws)->delete();
    expect(FiltreOrigine::actif($ws))->toBeFalse();
    OriginesJeu::ouvrir($ws);

    config(['origines.v3_enabled' => false]);
    expect(FiltreOrigine::actif($ws))->toBeFalse();
    config(['origines.v3_enabled' => true]);

    $avant = config('origines.lecture_enabled');
    foreach ([false, null] as $eteint) {
        config(['origines.lecture_enabled' => $eteint]);
        $requetes = [];
        DB::listen(static function ($q) use (&$requetes): void {
            $requetes[] = $q->sql;
        });
        expect(FiltreOrigine::actif($ws))->toBeFalse();
        expect($requetes)->toBe([]);
    }
    config(['origines.lecture_enabled' => $avant]);
    expect(config('origines.lecture_enabled'))->toBe($avant);
});

test('actif : colonne personnes.origines absente → faux', function () {
    $ws = fioEspace();
    OriginesJeu::allumer();
    OriginesJeu::ouvrir($ws);
    DB::statement('SAVEPOINT fio_col');
    DB::statement('ALTER TABLE personnes RENAME COLUMN origines TO origines_off');
    try {
        expect(FiltreOrigine::actif($ws))->toBeFalse();
    } finally {
        DB::statement('ROLLBACK TO SAVEPOINT fio_col');
    }
    expect(FiltreOrigine::actif($ws))->toBeTrue();
});

test('R1 : l\'entreprise est du réseau par une personne rattachée à elle ou à l\'un de ses contacts', function () {
    foreach (FIO_MODES as $mode) {
        $ws = fioEspace();
        $e1 = fioEntreprise($ws);
        $e2 = fioEntreprise($ws);
        $e3 = fioEntreprise($ws);
        $c2 = fioContact($ws, $e2);
        $c3 = fioContact($ws, $e3);
        fioPersonne($ws, ['linkedin'], ['company_id' => $e1]);
        fioPersonne($ws, ['linkedin'], ['contact_id' => $c2]);
        fioPersonne($ws, ['facebook'], ['contact_id' => $c3, 'company_id' => $e3]);
        fioPersonne($ws, ['facebook', 'instagram'], ['company_id' => $e3]);

        $r = fioLire($mode, $ws, [$e1, $e2, $e3], [], ['linkedin']);
        expect(array_keys($r['entreprises']))->toEqualCanonicalizing([$e1, $e2]);
        $r = fioLire($mode, $ws, [$e3], [], ['linkedin', 'instagram']);
        expect(array_keys($r['entreprises']))->toBe([$e3]);
    }
});

test('R2 : le contact est du réseau par sa personne ou par l\'entreprise du réseau', function () {
    foreach (FIO_MODES as $mode) {
        $ws = fioEspace();
        $e1 = fioEntreprise($ws);
        $e2 = fioEntreprise($ws);
        $e3 = fioEntreprise($ws);
        $c1 = fioContact($ws, $e1);
        $c2 = fioContact($ws, $e2);
        $c3 = fioContact($ws, $e3);
        fioPersonne($ws, ['linkedin'], ['contact_id' => $c1]);
        fioPersonne($ws, ['linkedin'], ['company_id' => $e2]);
        fioPersonne($ws, ['facebook'], ['company_id' => $e3]);

        $r = fioLire($mode, $ws, [$e1, $e2, $e3], [$c1, $c2, $c3], ['linkedin']);
        expect(array_keys($r['contacts']))->toEqualCanonicalizing([$c1, $c2]);
        expect($r['contacts_opposes'])->toBe([]);
    }
});

test('archive : compte ; entrée masquée (archive ou fiche) ne compte pas', function () {
    foreach (FIO_MODES as $mode) {
        $ws = fioEspace();
        [$e1, $e2, $e3, $e4] = [fioEntreprise($ws), fioEntreprise($ws), fioEntreprise($ws), fioEntreprise($ws)];
        $p1 = fioPersonne($ws, ['facebook'], ['company_id' => $e1]);
        OriginesJeu::archive($ws, $p1, OriginesJeu::entree(['reseau' => 'linkedin']));
        $p2 = fioPersonne($ws, ['facebook'], ['company_id' => $e2]);
        OriginesJeu::archive($ws, $p2, OriginesJeu::entree(['reseau' => 'linkedin']), '2026-11-02 10:00:00+00');
        fioPersonne($ws, [], ['company_id' => $e3, 'origines' => json_encode([OriginesJeu::entree(['reseau' => 'linkedin']) + ['masque_le' => '2026-11-02T10:00:00Z']])]);
        $p4 = fioPersonne($ws, ['facebook'], ['company_id' => $e4]);
        OriginesJeu::archive($ws, $p4, OriginesJeu::entree(['reseau' => 'linkedin']) + ['masque_le' => '2026-11-02T10:00:00Z']);

        $r = fioLire($mode, $ws, [$e1, $e2, $e3, $e4], [], ['linkedin']);
        expect(array_keys($r['entreprises']))->toBe([$e1]);
    }
});

test('O1/O2 : une personne opposée ne fait jamais entrer une unité ; son contact est signalé', function () {
    foreach (FIO_MODES as $mode) {
        $ws = fioEspace();
        $e = fioEntreprise($ws);
        $c = fioContact($ws, $e);
        $autre = fioEntreprise($ws);
        fioPersonne($ws, ['linkedin'], ['company_id' => $e, 'contact_id' => $c, 'opposition_at' => now()]);

        $r = fioLire($mode, $ws, [$e, $autre], [$c], ['linkedin']);
        expect($r['entreprises'])->toBe([]);
        expect($r['contacts'])->toBe([]);
        expect(array_keys($r['contacts_opposes']))->toBe([$c]);
    }
});

test('cloisonnement : une personne de l\'espace B ne compte pas dans A', function () {
    foreach (FIO_MODES as $mode) {
        $a = fioEspace();
        $b = fioEspace();
        $eb = fioEntreprise($b);
        $cb = fioContact($b, $eb);
        fioPersonne($b, ['linkedin'], ['company_id' => $eb, 'contact_id' => $cb, 'opposition_at' => now()]);
        fioPersonne($b, ['linkedin'], ['company_id' => $eb]);

        $r = fioLire($mode, $a, [$eb], [$cb], ['linkedin']);
        expect($r)->toBe(['entreprises' => [], 'contacts' => [], 'contacts_opposes' => []]);
    }
});

test('lecture seule : aucune écriture, au plus 3 requêtes pour 50 unités ; listes vides : rien', function () {
    $ws = fioEspace();
    $ents = [];
    $cts = [];
    for ($i = 0; $i < 50; $i++) {
        $ents[] = $e = fioEntreprise($ws);
        $cts[] = $c = fioContact($ws, $e);
        fioPersonne($ws, ['linkedin'], ['contact_id' => $c]);
    }
    $requetes = [];
    DB::listen(static function ($q) use (&$requetes): void {
        $requetes[] = $q->sql;
    });
    $r = RequetesOrigines::reseauxDesUnites($ws, $ents, $cts, ['linkedin']);
    expect(count($r['entreprises']))->toBe(50)->and(count($r['contacts']))->toBe(50);
    // Les lectures du catalogue (`Schema::hasColumn`, déjà dans `entrees()`) ne sont pas des requêtes de données.
    $donnees = array_values(array_filter($requetes, static fn (string $sql): bool => ! preg_match('/pg_attribute|information_schema|pg_class/i', $sql)));
    expect(count($donnees))->toBeLessThanOrEqual(3);
    foreach ($requetes as $sql) {
        expect($sql)->not->toMatch('/\b(insert|update|delete)\b/i');
    }
    $requetes = [];
    expect(RequetesOrigines::reseauxDesUnites($ws, [], [], ['linkedin']))->toBe(['entreprises' => [], 'contacts' => [], 'contacts_opposes' => []]);
    expect(RequetesOrigines::reseauxDesUnites($ws, $ents, $cts, []))->toBe(['entreprises' => [], 'contacts' => [], 'contacts_opposes' => []]);
    expect($requetes)->toBe([]);
});
