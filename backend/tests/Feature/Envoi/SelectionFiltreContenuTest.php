<?php

use App\Envoi\Campagnes\DepotCampagnes;
use App\Envoi\Campagnes\Destinataire;
use App\Envoi\Campagnes\LotDestinataires;
use App\Envoi\Campagnes\SelectionDestinataires;
use App\Envoi\Campagnes\SelectionRefusee;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\Support\OriginesJeu;
use Tests\TestCase;

/*
 * Lot PONT-LINKEDIN PO-B — filtre « mot / guide » dans SelectionDestinataires (LECTURE SEULE). Chaque test tourne sous le rôle
 * applicatif (RLS) ET sous le propriétaire. Cahier PONT-LINKEDIN (#691) § 4. Données fictives (`*.example.test`). Aucun envoi.
 */
uses(TestCase::class, RefreshDatabase::class);

const POB_MODES = ['app', 'proprio'];

beforeEach(function () {
    config(['origines.lecture_enabled' => false, 'origines.v3_enabled' => false]);
});

afterEach(function () {
    WorkspaceContext::clear();
});

function pobEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Sfc', 'slug' => 'sfc-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function pobN(): int
{
    static $n = 50000000;

    return ++$n;
}

/** Entreprise + un contact nominatif. @return array{int, int} [entreprise, contact] */
function pobUnite(string $ws): array
{
    $n = pobN();
    $co = (int) DB::table('companies')->insertGetId(['workspace_id' => $ws, 'siren' => F::siren(), 'denomination' => 'Société fictive']);
    $ct = (int) DB::table('contacts')->insertGetId(['workspace_id' => $ws, 'company_id' => $co, 'first_name' => 'Prenom', 'last_name' => 'Nom' . $n, 'email' => "prenom.nom{$n}@societe.example.test"]);

    return [$co, $ct];
}

/** @param list<int> $entreprises */
function pobCampagne(string $ws, array $entreprises): string
{
    $liste = (int) DB::table('listes_manuelles')->insertGetId(['workspace_id' => $ws, 'nom' => 'Liste ' . pobN()]);
    foreach ($entreprises as $e) {
        DB::table('listes_manuelles_membres')->insert(['workspace_id' => $ws, 'liste_id' => $liste, 'contact_id' => null, 'company_id' => $e, 'origine' => 'coche']);
    }

    return app(DepotCampagnes::class)->creer($ws, 'Campagne fictive', ['type' => 'liste', 'reference' => (string) $liste, 'libelle' => 'Cible fictive'], [], 1, [], 'le fondateur');
}

/** Pose les filtres en propriétaire, version + 1. @param list<string>|null $reseaux @param array<string, list<string>>|null $contenu */
function pobFiltrer(string $ws, string $campagne, ?array $reseaux, ?array $contenu): void
{
    DB::table('envoi_campagnes')->where('workspace_id', $ws)->where('id', $campagne)->update([
        'filtre_origine' => $reseaux === null ? null : json_encode(['reseaux' => $reseaux]),
        'filtre_origine_contenu' => $contenu === null ? null : json_encode($contenu),
        'version' => DB::raw('version + 1'),
    ]);
}

function pobAllumer(string $ws): void
{
    OriginesJeu::allumer();
    OriginesJeu::ouvrir($ws);
}

/** @param list<array<string, mixed>> $entrees @param array<string, mixed> $sur */
function pobPersonne(string $ws, array $entrees, array $sur = []): int
{
    return OriginesJeu::personne($ws, 'p' . pobN() . '@example.test', array_map(static fn (array $e): array => OriginesJeu::entree($e), $entrees), $sur);
}

function pobSel(string $mode, string $ws, string $campagne): LotDestinataires
{
    if ($mode === 'app') {
        WorkspaceContext::set($ws);
        DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    }
    try {
        return (new SelectionDestinataires(static fn (string $w, array $l): array => array_map(static fn ($x): array => ['opposee' => false, 'motifs' => []], $l)))
            ->lot($ws, $campagne, 'entreprises', 0, 200);
    } finally {
        if ($mode === 'app') {
            DB::statement('RESET ROLE');
        }
    }
}

/** @return array{string, bool}|null */
function pobRefus(string $mode, string $ws, string $campagne): ?array
{
    DB::statement('SAVEPOINT pob_refus');
    try {
        pobSel($mode, $ws, $campagne);
        DB::statement('RELEASE SAVEPOINT pob_refus');

        return null;
    } catch (SelectionRefusee $e) {
        DB::statement('ROLLBACK TO SAVEPOINT pob_refus');
        DB::statement('RESET ROLE');

        return [$e->motif, $e->reessayable];
    }
}

/** @return list<int> */
function pobIds(LotDestinataires $l): array
{
    $ids = array_map(static fn (Destinataire $d): int => $d->contactId, $l->destinataires);
    sort($ids);

    return $ids;
}

/** @return list<string> */
function pobRequetes(Closure $f): array
{
    $vues = [];
    DB::listen(function ($q) use (&$vues) {
        $vues[] = $q->sql;
    });
    $f();

    return $vues;
}

test('PO-B 1 sans filtre : mêmes destinataires, aucune lecture d\'origine', function () {
    foreach (POB_MODES as $mode) {
        $ws = pobEspace();
        [$e1, $k1] = pobUnite($ws);
        [$e2, $k2] = pobUnite($ws);
        $campagne = pobCampagne($ws, [$e1, $e2]);
        pobAllumer($ws);
        $l = null;
        $vues = pobRequetes(function () use (&$l, $mode, $ws, $campagne) {
            $l = pobSel($mode, $ws, $campagne);
        });
        expect(pobIds($l))->toBe([$k1, $k2])->and($l->compteurs['hors_reseau'])->toBe(0, $mode)
            ->and(preg_grep('/personnes|personne_origines_archive|fonctions_espace/', $vues))->toBe([]);
    }
});

test('PO-B 2 mot posé, porte fermée : refus filtre_origine_inactif avant toute lecture des membres', function () {
    foreach (POB_MODES as $mode) {
        $ws = pobEspace();
        [$e] = pobUnite($ws);
        $campagne = pobCampagne($ws, [$e]);
        pobFiltrer($ws, $campagne, null, ['mots' => ['guide']]);
        $refus = null;
        $vues = pobRequetes(function () use (&$refus, $mode, $ws, $campagne) {
            $refus = pobRefus($mode, $ws, $campagne);
        });
        expect($refus)->toBe(['filtre_origine_inactif', false], $mode)
            ->and(preg_grep('/listes_manuelles|contacts|companies|personnes|personne_origines_archive/', $vues))->toBe([]);
    }
});

test('PO-B 3 mot posé, porte ouverte : seules les unités du mot', function () {
    foreach (POB_MODES as $mode) {
        $ws = pobEspace();
        [$e1, $k1] = pobUnite($ws);
        [$e2] = pobUnite($ws);
        pobPersonne($ws, [['mot' => 'alpha']], ['company_id' => $e1]);
        pobPersonne($ws, [['mot' => 'beta']], ['company_id' => $e2]);
        $campagne = pobCampagne($ws, [$e1, $e2]);
        pobAllumer($ws);
        pobFiltrer($ws, $campagne, null, ['mots' => ['alpha']]);
        $l = pobSel($mode, $ws, $campagne);
        expect(pobIds($l))->toBe([$k1])->and($l->compteurs['hors_reseau'])->toBe(1, $mode)
            ->and($l->compteurs['membres'])->toBe(2);
    }
});

test('PO-B 4 réseau + guide : intersection « même entrée »', function () {
    foreach (POB_MODES as $mode) {
        $ws = pobEspace();
        [$e1, $k1] = pobUnite($ws);
        [$e2] = pobUnite($ws);
        pobPersonne($ws, [['reseau' => 'linkedin', 'guide' => 'g1']], ['company_id' => $e1]);
        // réseau et guide sur DEUX entrées différentes : n'entre pas
        pobPersonne($ws, [['reseau' => 'linkedin', 'guide' => 'g2'], ['reseau' => 'facebook', 'guide' => 'g1']], ['company_id' => $e2]);
        $campagne = pobCampagne($ws, [$e1, $e2]);
        pobAllumer($ws);
        pobFiltrer($ws, $campagne, ['linkedin'], ['guides' => ['g1']]);
        $l = pobSel($mode, $ws, $campagne);
        expect(pobIds($l))->toBe([$k1])->and($l->compteurs['hors_reseau'])->toBe(1, $mode);
    }
});

test('PO-B 5 contact rattaché à une personne opposée : opposition, jamais destinataire', function () {
    foreach (POB_MODES as $mode) {
        $ws = pobEspace();
        [$e, $k] = pobUnite($ws);
        $k2 = (int) DB::table('contacts')->insertGetId(['workspace_id' => $ws, 'company_id' => $e, 'first_name' => 'Autre', 'last_name' => 'Nom' . pobN(), 'email' => 'autre.nom' . pobN() . '@societe.example.test']);
        pobPersonne($ws, [['mot' => 'alpha']], ['contact_id' => $k, 'opposition_at' => now()]);
        pobPersonne($ws, [['mot' => 'alpha']], ['company_id' => $e]);
        $campagne = pobCampagne($ws, [$e]);
        pobAllumer($ws);
        pobFiltrer($ws, $campagne, null, ['mots' => ['alpha']]);
        $l = pobSel($mode, $ws, $campagne);
        expect(pobIds($l))->toBe([$k2])->and(pobIds($l))->not->toContain($k, $mode)
            ->and($l->compteurs['opposition'])->toBe(0);
        // l'entreprise dont le seul contact est opposé : motif `opposition`
        [$e3, $k3] = pobUnite($ws);
        pobPersonne($ws, [['mot' => 'alpha']], ['contact_id' => $k3, 'opposition_at' => now()]);
        pobPersonne($ws, [['mot' => 'alpha']], ['company_id' => $e3]);
        $c3 = pobCampagne($ws, [$e3]);
        pobFiltrer($ws, $c3, null, ['mots' => ['alpha']]);
        $l = pobSel($mode, $ws, $c3);
        expect(pobIds($l))->toBe([])->and($l->compteurs['opposition'])->toBe(1, $mode);
    }
});

test('PO-B 6 forme corrompue écrite en propriétaire : refus, jamais ignorée', function () {
    $ws = pobEspace();
    [$e] = pobUnite($ws);
    $campagne = pobCampagne($ws, [$e]);
    pobAllumer($ws);
    DB::statement('ALTER TABLE envoi_campagnes DROP CONSTRAINT envoi_campagnes_filtre_origine_contenu_forme');
    foreach (POB_MODES as $mode) {
        foreach (['{"mots": []}', '{"mots": ["Alpha"]}', '{"autre": 1}', '"x"'] as $brut) {
            DB::statement('UPDATE envoi_campagnes SET filtre_origine_contenu = ?::jsonb, version = version + 1 WHERE id = ?', [$brut, $campagne]);
            expect(pobRefus($mode, $ws, $campagne))->toBe(['filtre_origine_inactif', false], "{$mode} : forme {$brut}");
        }
    }
});

test('PO-B 7 exception de lecture : lecture_origine_impossible réessayable, aucun destinataire', function () {
    $ws = pobEspace();
    [$e] = pobUnite($ws);
    pobPersonne($ws, [['mot' => 'alpha']], ['company_id' => $e]);
    $campagne = pobCampagne($ws, [$e]);
    pobAllumer($ws);
    pobFiltrer($ws, $campagne, null, ['mots' => ['alpha']]);
    DB::statement('REVOKE SELECT ON personne_origines_archive FROM "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    expect(pobRefus('app', $ws, $campagne))->toBe(['lecture_origine_impossible', true]);
});

test('PO-B 8 réseau seul : résultat identique au chemin L2b-C, et sans filtre de contenu', function () {
    foreach (POB_MODES as $mode) {
        $ws = pobEspace();
        [$e1, $k1] = pobUnite($ws);
        [$e2] = pobUnite($ws);
        pobPersonne($ws, [['reseau' => 'linkedin', 'mot' => 'alpha']], ['company_id' => $e1]);
        pobPersonne($ws, [['reseau' => 'facebook', 'mot' => 'alpha']], ['company_id' => $e2]);
        $campagne = pobCampagne($ws, [$e1, $e2]);
        pobAllumer($ws);
        pobFiltrer($ws, $campagne, ['linkedin'], null);
        $l = pobSel($mode, $ws, $campagne);
        expect(pobIds($l))->toBe([$k1])->and($l->compteurs['hors_reseau'])->toBe(1, $mode);
        // le même résultat quand le mot couvre tout : le contenu ne fait que restreindre
        pobFiltrer($ws, $campagne, ['linkedin'], ['mots' => ['alpha']]);
        expect(pobIds(pobSel($mode, $ws, $campagne)))->toBe([$k1]);
    }
});

test('PO-B 9 cloisonnement : la personne d\'un autre espace ne fait entrer personne', function () {
    foreach (POB_MODES as $mode) {
        $a = pobEspace();
        $b = pobEspace();
        [$eA] = pobUnite($a);
        pobPersonne($b, [['mot' => 'alpha']], ['company_id' => $eA]); // identifiant de A, personne de B
        $campagneA = pobCampagne($a, [$eA]);
        pobAllumer($a);
        pobFiltrer($a, $campagneA, null, ['mots' => ['alpha']]);
        $l = pobSel($mode, $a, $campagneA);
        expect(pobIds($l))->toBe([])->and($l->compteurs['hors_reseau'])->toBe(1, $mode);
        [$eB] = pobUnite($b);
        expect(pobRefus($mode, $a, pobCampagne($b, [$eB])))->toBe(['campagne_introuvable', false]);
    }
});

test('PO-B 10 la requête lit la colonne par to_jsonb(k) -> filtre_origine_contenu (déploiement : code avant migration)', function () {
    expect(file_get_contents(app_path('Envoi/Campagnes/SelectionDestinataires.php')))->toContain("to_jsonb(k) -> 'filtre_origine_contenu'");
});
