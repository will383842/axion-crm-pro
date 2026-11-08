<?php

use App\Envoi\Configuration\DepotVersionsDb;
use App\Envoi\Configuration\Paquet;
use App\Envoi\Configuration\SourceConfigurationDb;
use App\Envoi\Configuration\StatutVersion;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function espaceK18(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'T', 'slug' => 'k18-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

test('versions : ajout seul, isolées par espace', function () {
    $ws = espaceK18();
    $autre = espaceK18();
    $depot = new DepotVersionsDb;
    $depot->noter($ws, str_repeat('a', 64), StatutVersion::Appliquee);
    $depot->noter($ws, str_repeat('b', 64), StatutVersion::DeriveRefusee, 'plafond_dur');

    WorkspaceContext::run($ws, function () use ($ws) {
        expect(DB::table('envoi_config_versions')->where('workspace_id', $ws)->count())->toBe(2);
        expect(fn () => DB::transaction(fn () => DB::table('envoi_config_versions')->update(['motif' => 'x'])))->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('envoi_config_versions')->delete()))->toThrow(QueryException::class);
    });
    // La connexion propriétaire est SUPERUSER (RLS ignorée) : on filtre par espace, comme ControlesDnsDbTest.
    WorkspaceContext::run($autre, fn () => expect(DB::table('envoi_config_versions')->where('workspace_id', $autre)->count())->toBe(0));
});

test('la source lit les domaines de la base et produit une empreinte stable', function () {
    $ws = espaceK18();
    WorkspaceContext::run($ws, function () use ($ws) {
        foreach (['b.example.test', 'a.example.test'] as $nom) {
            DB::table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => $nom, 'achete_le' => '2026-01-01']);
        }
    });
    $parametres = ['a.example.test' => ['selecteur' => 's1', 'cle_publique' => 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAxx', 'debit_heure' => 5, 'plafond_jour' => 10]];
    $p1 = Paquet::depuis(new SourceConfigurationDb($ws, $parametres));
    $p2 = Paquet::depuis(new SourceConfigurationDb($ws, $parametres));
    expect($p1->empreinte())->toBe($p2->empreinte());
    // seul le domaine présent en base ET paramétré entre dans le paquet
    expect(array_column($p1->contenu['domaines'], 'domaine'))->toBe(['a.example.test']);
});
