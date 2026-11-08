<?php

use App\Envoi\Configuration\DepotVersions;
use App\Envoi\Configuration\DepotVersionsDb;
use App\Envoi\Configuration\Paquet;
use App\Envoi\Configuration\PaquetInvalide;
use App\Envoi\Configuration\ServeurEnvoiConfiguration;
use App\Envoi\Configuration\SourceConfigurationDb;
use App\Envoi\Configuration\StatutVersion;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Unit\Envoi\Configuration\FauxServeurEnvoiConfiguration;

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
    $depot->noter($ws, str_repeat('c', 64), StatutVersion::Incertaine, 'transport');

    WorkspaceContext::run($ws, function () use ($ws) {
        expect(DB::table('envoi_config_versions')->where('workspace_id', $ws)->count())->toBe(3);
        expect(fn () => DB::transaction(fn () => DB::table('envoi_config_versions')->update(['motif' => 'x'])))->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('envoi_config_versions')->delete()))->toThrow(QueryException::class);
    });
    // La connexion propriétaire est SUPERUSER (RLS ignorée) : on filtre par espace, comme ControlesDnsDbTest.
    WorkspaceContext::run($autre, fn () => expect(DB::table('envoi_config_versions')->where('workspace_id', $autre)->count())->toBe(0));
});

function k18Domaines(string $ws, array $noms): void
{
    WorkspaceContext::run($ws, function () use ($ws, $noms) {
        foreach ($noms as $nom) {
            DB::table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => $nom, 'achete_le' => '2026-01-01']);
        }
    });
}

function k18Params(array $parametres): string
{
    $dir = sys_get_temp_dir() . '/k18-' . bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir . '/p.json', json_encode($parametres));

    return $dir . '/p.json';
}

function k18Lignes(string $ws): array
{
    return DB::table('envoi_config_versions')->where('workspace_id', $ws)->orderBy('id')->get()->all();
}

const K18_OK = ['a.example.test' => ['debit_heure' => 5, 'plafond_jour' => 10]];

test('la source lit les domaines de la base, liste ceux sans paramètres et produit une empreinte stable', function () {
    $ws = espaceK18();
    k18Domaines($ws, ['b.example.test', 'a.example.test']);
    $source = new SourceConfigurationDb($ws, K18_OK);
    $p1 = Paquet::pourEspace($ws, $source);
    $p2 = Paquet::pourEspace($ws, new SourceConfigurationDb($ws, K18_OK));
    expect($p1->empreinte())->toBe($p2->empreinte());
    expect(array_column($p1->contenu['domaines'], 'domaine'))->toBe(['a.example.test']);
    expect($p1->contenu['espace'])->toBe($ws);
    expect($source->ignores())->toBe(['b.example.test']);
});

test('la source refuse un paramètre pour un domaine absent de la base, et toute clé autre que débit et plafond', function () {
    $ws = espaceK18();
    k18Domaines($ws, ['a.example.test']);
    expect(fn () => (new SourceConfigurationDb($ws, K18_OK + ['zz.example.test' => ['debit_heure' => 1, 'plafond_jour' => 1]]))->domaines())->toThrow(PaquetInvalide::class);
    foreach (['selecteur' => 's1', 'cle_publique' => 'MIIB', 'autre' => 1] as $cle => $val) {
        $p = ['a.example.test' => ['debit_heure' => 5, 'plafond_jour' => 10, $cle => $val]];
        expect(fn () => (new SourceConfigurationDb($ws, $p))->domaines())->toThrow(PaquetInvalide::class);
    }
});

test('commande : plus d’argument répertoire', function () {
    $args = array_keys(Artisan::all()['envoi:config-appliquer']->getDefinition()->getArguments());
    expect($args)->toBe(['workspace', 'parametres']);
});

test('commande : sans adaptateur lié, FAILURE, aucune ligne, aucun fichier créé', function () {
    $ws = espaceK18();
    k18Domaines($ws, ['a.example.test']);
    $fichier = k18Params(K18_OK);
    $avant = scandir(dirname($fichier));
    expect(app()->bound(ServeurEnvoiConfiguration::class))->toBeFalse();
    $code = Artisan::call('envoi:config-appliquer', ['workspace' => $ws, 'parametres' => $fichier]);
    expect($code)->toBe(1)->and(Artisan::output())->toContain('adaptateur de configuration absent (K9-suite)');
    expect(k18Lignes($ws))->toBe([])->and(scandir(dirname($fichier)))->toBe($avant);
});

test('commande : workspace non UUID ou inexistant, FAILURE, aucune ligne', function () {
    app()->instance(ServeurEnvoiConfiguration::class, $faux = new FauxServeurEnvoiConfiguration);
    $fichier = k18Params(K18_OK);
    foreach (['pas-un-uuid', (string) Str::uuid(), strtoupper((string) Str::uuid())] as $ws) {
        expect(Artisan::call('envoi:config-appliquer', ['workspace' => $ws, 'parametres' => $fichier]))->toBe(1);
    }
    expect(DB::table('envoi_config_versions')->count())->toBe(0)->and($faux->appels)->toBe([]);
});

test('commande : succès, un paquet par espace, domaine sans paramètres listé et compté', function () {
    app()->instance(ServeurEnvoiConfiguration::class, $faux = new FauxServeurEnvoiConfiguration);
    config(['envoi.plafond_jour' => 20, 'envoi.plafond_global_jour' => 20]);
    $ws = espaceK18();
    k18Domaines($ws, ['a.example.test', 'b.example.test']);
    $code = Artisan::call('envoi:config-appliquer', ['workspace' => $ws, 'parametres' => k18Params(K18_OK)]);
    expect($code)->toBe(0);
    expect(Artisan::output())->toContain('appliquee')->toContain('ignoré : b.example.test');
    $lignes = k18Lignes($ws);
    expect($lignes)->toHaveCount(1)->and($lignes[0]->statut)->toBe('appliquee')->and($lignes[0]->motif)->toContain('1');
    expect($faux->etat[$ws]['espace'])->toBe($ws);
});

test('commande : paramètre pour un domaine absent, FAILURE et ligne invalide', function () {
    app()->instance(ServeurEnvoiConfiguration::class, $faux = new FauxServeurEnvoiConfiguration);
    $ws = espaceK18();
    k18Domaines($ws, ['a.example.test']);
    $code = Artisan::call('envoi:config-appliquer', ['workspace' => $ws, 'parametres' => k18Params(K18_OK + ['zz.example.test' => ['debit_heure' => 1, 'plafond_jour' => 1]])]);
    expect($code)->toBe(1)->and($faux->appels)->toBe([]);
    expect(array_column(k18Lignes($ws), 'statut'))->toBe(['invalide']);
});

test('commande : verrou tenu par une seconde connexion, FAILURE, aucune ligne', function () {
    app()->instance(ServeurEnvoiConfiguration::class, $faux = new FauxServeurEnvoiConfiguration);
    $ws = espaceK18();
    k18Domaines($ws, ['a.example.test']);
    $c = DB::connection()->getConfig();
    $autre = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']}", $c['username'], $c['password']);
    $prise = $autre->prepare("SELECT pg_try_advisory_lock(hashtextextended('envoi_config:' || ?::text, 0))");
    $prise->execute([$ws]);
    expect($prise->fetchColumn())->toBeTrue();
    $fichier = k18Params(K18_OK);
    expect(Artisan::call('envoi:config-appliquer', ['workspace' => $ws, 'parametres' => $fichier]))->toBe(1);
    expect(k18Lignes($ws))->toBe([])->and($faux->appels)->toBe([]);
    $autre->exec('SELECT pg_advisory_unlock_all()');
    // Verrou libéré : la commande passe, puis libère le sien (un second passage réussit aussi).
    expect(Artisan::call('envoi:config-appliquer', ['workspace' => $ws, 'parametres' => $fichier]))->toBe(0);
    expect(Artisan::call('envoi:config-appliquer', ['workspace' => $ws, 'parametres' => $fichier]))->toBe(0);
});

test('commande : dépôt de versions qui lève après application, Log::critical et FAILURE', function () {
    Log::spy();
    app()->instance(ServeurEnvoiConfiguration::class, $faux = new FauxServeurEnvoiConfiguration);
    app()->instance(DepotVersions::class, new class implements DepotVersions
    {
        public function noter(string $workspaceId, string $empreinte, StatutVersion $statut, ?string $motif = null): void
        {
            throw new RuntimeException('base coupée');
        }
    });
    config(['envoi.plafond_jour' => 20, 'envoi.plafond_global_jour' => 20]);
    $ws = espaceK18();
    k18Domaines($ws, ['a.example.test']);
    expect(Artisan::call('envoi:config-appliquer', ['workspace' => $ws, 'parametres' => k18Params(K18_OK)]))->toBe(1);
    expect($faux->appels)->toContain('appliquer');
    Log::shouldHaveReceived('critical')->atLeast()->once();
});
