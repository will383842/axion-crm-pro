<?php

use App\Envoi\FeuVert\CatalogueManuels;
use App\Envoi\FeuVert\DepotPreuves;
use App\Envoi\FeuVert\ListeFeuVert;
use App\Envoi\FeuVert\PointFeuVert;
use App\Envoi\JourEnvoi;
use App\Envoi\Sante\DepotSante;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('envoi');

/**
 * K17-FV (D12) — feu vert final côté serveur. Aucun envoi : la liste « tout vert » est SIMULÉE (au palier 0 les points
 * `serveur_reel` sont rouges, « accorde » est impossible par la route). ENVOI_REEL_ACTIF reste faux.
 */
const FVD_MOTIF = 'Décision de test après relecture, réf. exemple-01.';

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
});

function fvdCompte(string $role, ?string $espace = null): User
{
    $espace ??= Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'fvd-' . Str::random(8), 'name' => 'Espace décision', 'settings' => ['produits' => ['envoi' => 'actif']]])->id;
    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => $role . '-' . Str::random(6) . '@exemple.test',
        'name' => 'Responsable Test',
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $espace,
        'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($espace);
    $user->assignRole($role);

    return $user;
}

/** Liste simulée : tout vert, sauf les codes passés en rouge. `auto.reel_actif_faux` suit la vraie configuration. */
function fvdSimuler(array $rouges = []): void
{
    app()->bind(ListeFeuVert::class, fn () => new class(app(DepotPreuves::class), app(DepotSante::class), $rouges) extends ListeFeuVert
    {
        /** @param list<string> $rouges */
        public function __construct(DepotPreuves $p, DepotSante $s, private readonly array $rouges)
        {
            parent::__construct($p, $s);
        }

        public function points(string $ws): array
        {
            $r = [];
            foreach (CatalogueManuels::POINTS as $code => $c) {
                $fait = $code === 'auto.reel_actif_faux' ? config('envoi.reel_actif') === false : ! in_array($code, $this->rouges, true);
                $r[] = new PointFeuVert($code, $c['libelle'], str_starts_with($code, 'auto.'), $fait, 'simulé', $c['source'], $c['groupe'], $fait ? 'fait' : 'a_faire');
            }

            return $r;
        }
    });
}

function fvdEmpreinte($test, User $u): string
{
    return (string) $test->actingAs($u)->getJson('/api/v1/envoi/feu-vert')->assertOk()->json('empreinte_liste');
}

function fvdDecider($test, User $u, string $decision, ?string $siMatch, string $motif = FVD_MOTIF)
{
    return $test->actingAs($u)->postJson('/api/v1/envoi/feu-vert/decision', ['decision' => $decision, 'motif' => $motif], $siMatch === null ? [] : ['If-Match' => $siMatch]);
}

function fvdLignes(string $ws): int
{
    return (int) WorkspaceContext::run($ws, fn () => DB::table('envoi_feu_vert_decisions')->where('workspace_id', $ws)->count());
}

test('401 sans session', function () {
    $this->postJson('/api/v1/envoi/feu-vert/decision', ['decision' => 'retire', 'motif' => FVD_MOTIF])->assertUnauthorized();
});

test('administrateur, opérateur et lecteur : 403, rien n’est écrit', function (string $role) {
    fvdSimuler();
    $u = fvdCompte($role);
    fvdDecider($this, $u, 'retire', null)->assertForbidden();
    fvdDecider($this, $u, 'accorde', 'x')->assertForbidden();
    expect(DB::table('envoi_feu_vert_decisions')->count())->toBe(0);
})->with(['viewer', 'operator', 'admin']);

test('au palier 0, « accorde » par la route est impossible : 409, même avec la bonne empreinte et toutes les preuves', function () {
    $u = fvdCompte('owner');
    foreach (CatalogueManuels::codesManuels() as $code) {
        app(DepotPreuves::class)->ajouter($u->current_workspace_id, $code, 'Preuve de test, réf. exemple-01', 'auteur');
    }
    $e = fvdEmpreinte($this, $u);
    fvdDecider($this, $u, 'accorde', $e)->assertStatus(409);
    expect(fvdLignes($u->current_workspace_id))->toBe(0)->and(config('envoi.reel_actif'))->toBeFalse();
});

test('« accorde » avec un bloquant rouge ⇒ 409, rien n’est écrit', function () {
    fvdSimuler(['manuel.port25']);
    $u = fvdCompte('owner');
    fvdDecider($this, $u, 'accorde', fvdEmpreinte($this, $u))->assertStatus(409)->assertJsonPath('error', 'conflit');
    expect(fvdLignes($u->current_workspace_id))->toBe(0);
});

test('« accorde » sans If-Match ⇒ 428 ; avec une empreinte périmée ⇒ 412 ; rien n’est écrit', function () {
    fvdSimuler();
    $u = fvdCompte('owner');
    fvdDecider($this, $u, 'accorde', null)->assertStatus(428);
    fvdDecider($this, $u, 'accorde', str_repeat('0', 64))->assertStatus(412);
    expect(fvdLignes($u->current_workspace_id))->toBe(0);
});

test('succès sur liste simulée : 200, décision consignée avec la photo des points, aucune configuration modifiée', function () {
    fvdSimuler();
    $u = fvdCompte('owner');
    $ws = $u->current_workspace_id;
    DB::table('envoi_drapeaux')->updateOrInsert(['workspace_id' => $ws], ['mode_temoins' => true, 'feu_vert_signature' => null]);
    $avant = DB::table('envoi_drapeaux')->where('workspace_id', $ws)->first();
    $r = fvdDecider($this, $u, 'accorde', fvdEmpreinte($this, $u))->assertOk();
    expect($r->json('decision.decision'))->toBe('accorde')->and($r->json('decision.motif'))->toBe(FVD_MOTIF)->and($r->json('total'))->toBe(49);
    $ligne = WorkspaceContext::run($ws, fn () => DB::table('envoi_feu_vert_decisions')->where('workspace_id', $ws)->first());
    expect($ligne->decision)->toBe('accorde')->and($ligne->auteur)->toBe('Responsable Test')->and($ligne->empreinte_liste)->not->toBe('');
    $photo = json_decode($ligne->points, true);
    $codes = array_keys($photo);
    sort($codes);
    $attendus = CatalogueManuels::codes();
    sort($attendus);
    expect($photo)->toHaveCount(49)->and($codes)->toBe($attendus); // jsonb ne garde pas l'ordre des clés
    expect(DB::table('envoi_drapeaux')->where('workspace_id', $ws)->first())->toEqual($avant)->and(config('envoi.reel_actif'))->toBeFalse();
});

test('If-Match accepté aussi entre guillemets', function () {
    fvdSimuler();
    $u = fvdCompte('owner');
    fvdDecider($this, $u, 'accorde', '"' . fvdEmpreinte($this, $u) . '"')->assertOk();
});

test('un rouge sur auto.reel_actif_faux seul ne bloque pas « accorde » ; l’empreinte ignore ce point', function () {
    fvdSimuler();
    $u = fvdCompte('owner');
    $e = fvdEmpreinte($this, $u);
    config(['envoi.reel_actif' => true]);
    expect(fvdEmpreinte($this, $u))->toBe($e);
    fvdDecider($this, $u, 'accorde', $e)->assertOk();
    expect(fvdLignes($u->current_workspace_id))->toBe(1);
});

test('l’empreinte est stable d’un appel à l’autre, et change quand une décision est ajoutée', function () {
    fvdSimuler();
    $u = fvdCompte('owner');
    $e = fvdEmpreinte($this, $u);
    expect(fvdEmpreinte($this, $u))->toBe($e)->and($e)->toMatch('/^[0-9a-f]{64}$/');
    fvdDecider($this, $u, 'retire', null)->assertOk();
    expect(fvdEmpreinte($this, $u))->not->toBe($e);
});

test('retire, puis accorde avec l’empreinte lue avant le retrait ⇒ 412 (le stop gagne)', function () {
    fvdSimuler();
    $u = fvdCompte('owner');
    $lue = fvdEmpreinte($this, $u);
    fvdDecider($this, $u, 'retire', null)->assertOk();
    fvdDecider($this, $u, 'accorde', $lue)->assertStatus(412);
    expect(fvdLignes($u->current_workspace_id))->toBe(1);
});

test('« retire » est toujours accepté : sans If-Match, avec une empreinte périmée, avec des bloquants rouges, consigné à chaque appel', function () {
    fvdSimuler(['manuel.port25']);
    $u = fvdCompte('owner');
    fvdDecider($this, $u, 'retire', null)->assertOk()->assertJsonPath('decision.decision', 'retire');
    fvdDecider($this, $u, 'retire', str_repeat('0', 64))->assertOk();
    fvdDecider($this, $u, 'retire', 'n’importe quoi')->assertOk();
    expect(fvdLignes($u->current_workspace_id))->toBe(3);
});

test('422 : décision inconnue, motif court, motif avec adresse ; la validation passe avant 428', function () {
    fvdSimuler();
    $u = fvdCompte('owner');
    fvdDecider($this, $u, 'peut-etre', null)->assertStatus(422);
    fvdDecider($this, $u, 'accorde', null, 'court')->assertStatus(422);
    fvdDecider($this, $u, 'retire', null, 'Écrire à quelqu’un@exemple.test pour confirmer')->assertStatus(422);
    fvdDecider($this, $u, 'retire', null, str_repeat('a', 501))->assertStatus(422);
    expect(fvdLignes($u->current_workspace_id))->toBe(0);
});

test('cloisonnement : la décision d’un espace ne change pas l’empreinte d’un autre', function () {
    fvdSimuler();
    $a = fvdCompte('owner');
    $b = fvdCompte('owner');
    $eb = fvdEmpreinte($this, $b);
    fvdDecider($this, $a, 'retire', null)->assertOk();
    expect(fvdEmpreinte($this, $b))->toBe($eb)->and(fvdLignes($b->current_workspace_id))->toBe(0);
    expect($this->actingAs($b)->getJson('/api/v1/envoi/feu-vert')->json('decision'))->toBeNull();
});

test('GET : `decision` est nulle sans décision, puis la dernière', function () {
    fvdSimuler();
    $u = fvdCompte('owner');
    expect($this->actingAs($u)->getJson('/api/v1/envoi/feu-vert')->assertOk()->json())->toHaveKey('decision')->and($this->actingAs($u)->getJson('/api/v1/envoi/feu-vert')->json('decision'))->toBeNull();
    fvdDecider($this, $u, 'retire', null)->assertOk();
    expect($this->actingAs($u)->getJson('/api/v1/envoi/feu-vert')->json('decision.decision'))->toBe('retire');
});

test('la décision ne touche ni verrou, ni signature, ni drapeau : le service ne contient aucune écriture de configuration', function () {
    $src = (string) file_get_contents(app_path('Envoi/FeuVert/DecisionFeuVert.php'));
    expect($src)->not->toContain('envoi_drapeaux')->not->toContain('feu_vert_signature')->not->toContain('config([')->not->toContain('reel_actif');
});

test('deux « accorde » simultanés sur la même empreinte : un seul accepté, l’autre 412 (deux processus, deux connexions)', function () {
    $owner = DB::connection('pgsql_owner');
    $ws = (string) Str::uuid();
    $owner->table('workspaces')->insert(['id' => $ws, 'name' => 'Concurrence FV', 'slug' => 'fvc-' . substr($ws, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    try {
        // Empreinte lue AVANT : celle d'une liste simulée, sans décision.
        fvdSimuler();
        $empreinte = WorkspaceContext::run($ws, fn () => app(ListeFeuVert::class)->empreinte($ws, app(ListeFeuVert::class)->points($ws)));
        $depart = microtime(true) + 3.0;
        $env = array_merge(getenv(), ['APP_ENV' => 'testing']);
        $procs = [];
        for ($i = 0; $i < 2; $i++) {
            $p = proc_open([PHP_BINARY, base_path('tests/Support/ouvrier_feu_vert.php'), $ws, $empreinte, (string) $depart], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            expect(is_resource($p))->toBeTrue();
            $procs[] = [$p, $pipes];
        }
        $statuts = [];
        foreach ($procs as [$p, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            expect(proc_close($p))->toBe(0, "ouvrier en échec : {$err} {$out}");
            $statuts[] = json_decode(trim($out), true)['statut'] ?? null;
        }
        sort($statuts);
        expect($statuts)->toBe([200, 412]);
        $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $ws]);
        expect($owner->table('envoi_feu_vert_decisions')->where('workspace_id', $ws)->count())->toBe(1);
    } finally {
        $owner->statement("SET session_replication_role = 'replica'");
        $owner->table('envoi_feu_vert_decisions')->where('workspace_id', $ws)->delete();
        $owner->table('workspaces')->where('id', $ws)->delete();
        $owner->statement("SET session_replication_role = 'origin'");
        $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    }
});

// ---- Points automatiques v5 -------------------------------------------------------------------------------------

function fvdPoint(string $ws, string $code): PointFeuVert
{
    foreach (app(ListeFeuVert::class)->points($ws) as $p) {
        if ($p->code === $code) {
            return $p;
        }
    }
    throw new RuntimeException($code);
}

function fvdEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Test', 'slug' => 'fvp-' . substr($id, 0, 8), 'settings' => json_encode(['produits' => ['envoi' => 'actif']]), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function fvdEssai(string $ws, bool $reussi, string $fin): void
{
    WorkspaceContext::run($ws, fn () => DB::table('envoi_essais_blanc')->insert([
        'workspace_id' => $ws, 'auteur' => 'test', 'debut' => date('c', strtotime($fin) - 600), 'fin' => date('c', strtotime($fin)), 'reussi' => $reussi, 'etapes' => '[]',
    ]));
}

test('v5 : auto.essai_a_blanc_recent : rouge sans essai, avec un essai raté ou de 8 jours ; vert avec un essai réussi de moins de 7 jours', function () {
    $ws = fvdEspace();
    expect(fvdPoint($ws, 'auto.essai_a_blanc_recent'))->fait->toBeFalse()->automatique->toBeTrue();
    fvdEssai($ws, false, '-1 day');
    expect(fvdPoint($ws, 'auto.essai_a_blanc_recent')->fait)->toBeFalse();
    fvdEssai($ws, true, '-8 days');
    expect(fvdPoint($ws, 'auto.essai_a_blanc_recent')->fait)->toBeFalse();
    fvdEssai($ws, true, '-6 days');
    expect(fvdPoint($ws, 'auto.essai_a_blanc_recent'))->fait->toBeTrue()->etat->toBe('fait');
});

test('v5 : auto.essai_a_blanc_recent : l’essai d’un autre espace ne compte pas', function () {
    $a = fvdEspace();
    $b = fvdEspace();
    fvdEssai($b, true, '-1 day');
    expect(fvdPoint($a, 'auto.essai_a_blanc_recent')->fait)->toBeFalse();
});

test('v5 : auto.plafond_butee_base : vert tant que la fonction de butée existe en base', function () {
    $ws = fvdEspace();
    expect(fvdPoint($ws, 'auto.plafond_butee_base'))->fait->toBeTrue();
});

test('v5 : auto.adaptateurs_domaines : vert sans domaine réel ; un domaine non faux exige un DNS vert du jour', function () {
    $ws = fvdEspace();
    expect(fvdPoint($ws, 'auto.adaptateurs_domaines')->fait)->toBeTrue();
    WorkspaceContext::run($ws, function () use ($ws) {
        DB::table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => 'a.exemple.test', 'achete_le' => '2026-01-01']);
        DB::select('SELECT envoi_domaine_adaptateur_changer(?, ?, ?, ?)', [$ws, 'a.exemple.test', 'kumo', 'cli']);
    });
    expect(fvdPoint($ws, 'auto.adaptateurs_domaines')->fait)->toBeFalse();
    $dns = fn (bool $pret, bool $pause, string $quand) => WorkspaceContext::run($ws, fn () => DB::table('envoi_controles_dns')->insert(['workspace_id' => $ws, 'domaine' => 'a.exemple.test', 'controle_le' => $quand, 'pret' => $pret, 'pause' => $pause]));
    $dns(true, false, JourEnvoi::de()->modify('-1 day')->format('Y-m-d') . ' 12:00:00+01');
    expect(fvdPoint($ws, 'auto.adaptateurs_domaines')->fait)->toBeFalse('un DNS d’hier ne suffit pas');
    $dns(false, false, now()->toIso8601String());
    expect(fvdPoint($ws, 'auto.adaptateurs_domaines')->fait)->toBeFalse('le dernier contrôle du jour est rouge');
    $dns(true, true, now()->toIso8601String());
    expect(fvdPoint($ws, 'auto.adaptateurs_domaines')->fait)->toBeFalse('domaine en pause');
    $dns(true, false, now()->toIso8601String());
    expect(fvdPoint($ws, 'auto.adaptateurs_domaines')->fait)->toBeTrue();
});

test('v5 : le catalogue passe en version 6, 49 points dont 29 manuels et trois nouveaux auto.*', function () {
    expect(CatalogueManuels::VERSION)->toBe(6)->and(CatalogueManuels::codes())->toHaveCount(49)->and(CatalogueManuels::codesManuels())->toHaveCount(29);
    expect(CatalogueManuels::codes())->toContain('auto.essai_a_blanc_recent', 'auto.plafond_butee_base', 'auto.adaptateurs_domaines');
});
