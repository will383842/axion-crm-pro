<?php

use App\Envoi\Campagnes\DepotCampagnes;
use App\Envoi\Campagnes\SelectionDestinataires;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * REPLI-MOT (relecture de #751) — la détection des adresses partagées (D10) passe par `QualificationEmail::normaliser()`, comme
 * le reste du code : « Info@X » et « info@x » portés par deux fiches désignent la MÊME adresse. Sous le rôle applicatif (RLS) ET
 * sous le propriétaire. Données fictives (`*.example.test`), aucun envoi.
 */
uses(TestCase::class, RefreshDatabase::class);

beforeEach(fn () => WorkspaceContext::clear());
afterEach(fn () => WorkspaceContext::clear());

function sapnEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Sapn', 'slug' => 'sapn-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    WorkspaceContext::run($id, fn () => DB::table('envoi_reglages')->updateOrInsert(['workspace_id' => $id, 'cle' => 'envoi.repli_generique'], ['valeur' => 'true', 'auteur' => 'fondateur']));

    return $id;
}

function sapnFiche(string $ws, string $adresse): int
{
    static $n = 30000000;
    $e = (int) DB::table('companies')->insertGetId(['workspace_id' => $ws, 'siren' => str_pad((string) ++$n, 9, '0', STR_PAD_LEFT), 'denomination' => 'Société fictive']);
    DB::table('contacts')->insert(['workspace_id' => $ws, 'company_id' => $e, 'first_name' => 'Prenom', 'last_name' => 'Nom' . $n, 'email' => $adresse]);

    return $e;
}

function sapnLot(string $mode, string $ws, array $entreprises): array
{
    $liste = (int) DB::table('listes_manuelles')->insertGetId(['workspace_id' => $ws, 'nom' => 'Liste ' . Str::random(6)]);
    foreach ($entreprises as $e) {
        DB::table('listes_manuelles_membres')->insert(['workspace_id' => $ws, 'liste_id' => $liste, 'contact_id' => null, 'company_id' => $e, 'origine' => 'coche']);
    }
    $campagne = app(DepotCampagnes::class)->creer($ws, 'Campagne fictive', ['type' => 'liste', 'reference' => (string) $liste, 'libelle' => 'Cible fictive'], [], 1, [], 'le fondateur');
    if ($mode === 'app') {
        WorkspaceContext::set($ws);
        DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    }
    try {
        $l = (new SelectionDestinataires)->lot($ws, $campagne, 'entreprises');
    } finally {
        if ($mode === 'app') {
            DB::statement('RESET ROLE');
        }
    }

    return [array_map(fn ($d) => $d->entrepriseId, $l->destinataires), $l->compteurs['sans_contact']];
}

test('REPLI-MOT : la même adresse écrite avec espace et majuscule sur deux fiches est partagée, donc exclue', function (string $mode) {
    $ws = sapnEspace();
    $a = sapnFiche($ws, ' Info@X.example.test');
    $b = sapnFiche($ws, ' info@x.example.test');
    $seule = sapnFiche($ws, 'info@seule.example.test');
    [$retenus, $sansContact] = sapnLot($mode, $ws, [$a, $b, $seule]);
    expect($retenus)->toBe([$seule])->and($sansContact)->toBe(2);
})->with(['app', 'proprio']);

test('REPLI-MOT : une adresse portée par une seule fiche reste admise en repli', function (string $mode) {
    $ws = sapnEspace();
    $a = sapnFiche($ws, ' Info@X.example.test');
    [$retenus] = sapnLot($mode, $ws, [$a]);
    expect($retenus)->toBe([$a]);
})->with(['app', 'proprio']);

test('REPLI-MOT : « Info@X » avec espace et « info@x » sans espace sur deux fiches sont la même adresse, donc exclue (cas du plan)', function (string $mode) {
    $ws = sapnEspace();
    $a = sapnFiche($ws, ' Info@X.example.test');
    $b = sapnFiche($ws, 'info@x.example.test');
    $seule = sapnFiche($ws, 'info@seule.example.test');
    [$retenus, $sansContact] = sapnLot($mode, $ws, [$a, $b, $seule]);
    expect($retenus)->toBe([$seule])->and($sansContact)->toBe(2);
})->with(['app', 'proprio']);

test('REPLI-MOT : la détection des adresses partagées reste cloisonnée par espace', function (string $mode) {
    $ws = sapnEspace();
    $autre = sapnEspace();
    $a = sapnFiche($ws, 'info@x.example.test');
    WorkspaceContext::run($autre, fn () => sapnFiche($autre, ' Info@X.example.test'));
    [$retenus] = sapnLot($mode, $ws, [$a]);
    expect($retenus)->toBe([$a]);
})->with(['app', 'proprio']);
