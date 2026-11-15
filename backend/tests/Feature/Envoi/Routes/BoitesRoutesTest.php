<?php

namespace Tests\Feature\Envoi\Routes;

use App\Envoi\Smtp\DepotBoites;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ContratOpenApiEnvoi;
use Tests\Support\NonServiAuPalier0;

uses(BaseRoutesEnvoi::class)->group('envoi');

const MARQUEURS_SECRETS_BOITES = ['IDENTIFIANT-CHIFFRE-BOITE', 'HOTE-SMTP-BOITE', 'utilisateur-smtp-secret'];

/** @return array{espace: Workspace, autre: Workspace, boite: string, boiteAutre: string} */
function semerBoites(object $t): array
{
    $nouvel = \Closure::bind(fn () => $this->espace(true), $t, BaseRoutesEnvoi::class);
    $espace = $nouvel();
    $autre = $nouvel();
    $ids = [];
    foreach (['espace' => $espace, 'autre' => $autre] as $cle => $ws) {
        $ids[$cle] = (string) Str::uuid();
        WorkspaceContext::run($ws->id, function () use ($ws, $cle, $ids) {
            DB::table('envoi_domaines')->insert(['workspace_id' => $ws->id, 'nom' => "boites-$cle.exemple.test", 'achete_le' => '2026-10-01']);
            DB::table('envoi_boites')->insert([
                'id' => $ids[$cle], 'workspace_id' => $ws->id, 'adresse' => "a@boites-$cle.exemple.test", 'hote' => 'HOTE-SMTP-BOITE.example.invalid',
                'port' => 587, 'identifiants_chiffres' => 'IDENTIFIANT-CHIFFRE-BOITE',
            ]);
        });
    }

    return ['espace' => $espace, 'autre' => $autre, 'boite' => $ids['espace'], 'boiteAutre' => $ids['autre']];
}

function ligneBoite(string $ws, string $id): object
{
    return WorkspaceContext::run($ws, fn () => DB::table('envoi_boites')->where('id', $id)->first());
}

function nbBoites(string $ws): int
{
    return WorkspaceContext::run($ws, fn () => DB::table('envoi_boites')->count());
}

function poserPause(string $ws, string $id, string $motif): void
{
    app(DepotBoites::class)->mettreEnPause($ws, $id, $motif);
}

test('liste et fiche suivent le contrat, sans secret, et ne montrent que leur espace', function () {
    $s = semerBoites($this);
    $moi = $this->compte($s['espace'], 'admin');
    $liste = $this->actingAs($moi, 'sanctum')->getJson('/api/v1/envoi/boites');
    $liste->assertOk();
    expect($liste->json('data'))->toHaveCount(1);
    foreach ($liste->json('data') as $ligne) {
        expect(ContratOpenApiEnvoi::ecarts('commun.yaml', 'BoiteResume', $ligne))->toBe([]);
    }
    expect($liste->json('data.0.id'))->toBe($s['boite']);
    $fiche = $this->actingAs($moi, 'sanctum')->getJson('/api/v1/envoi/boites/' . $s['boite']);
    $fiche->assertOk();
    expect(ContratOpenApiEnvoi::ecartsReponse('infrastructure.yaml', '/envoi/boites/{id}', 'get', 200, $fiche->json()))->toBe([]);
    foreach ([$liste->getContent(), $fiche->getContent()] as $corps) {
        foreach (MARQUEURS_SECRETS_BOITES as $m) {
            expect($corps)->not->toContain($m);
        }
        expect(preg_match('/identifiants?_chiffres|"hote"|"port"|"utilisateur"|"secret"|mot_de_passe/i', (string) $corps))->toBe(0);
    }
    expect($this->resolutions)->toBe(0);
});

test('GET /envoi/boites/{id} rend les réglages par défaut sans rien stocker', function () {
    $s = semerBoites($this);
    $avant = (array) ligneBoite($s['espace']->id, $s['boite']);
    $r = $this->actingAs($this->compte($s['espace'], 'admin'), 'sanctum')->getJson('/api/v1/envoi/boites/' . $s['boite'])->assertOk();
    expect($r->json('reglages'))->toBe(['signature' => '', 'mode_absence' => ['actif' => false, 'jusqu_au' => null, 'message' => null]]);
    expect((array) ligneBoite($s['espace']->id, $s['boite']))->toBe($avant);
});

test('un autre espace donne 404 pour la fiche, suspendre et réactiver, sans rien écrire', function () {
    $s = semerBoites($this);
    $owner = $this->compte($s['espace'], 'owner');
    $this->actingAs($owner, 'sanctum');
    $this->getJson('/api/v1/envoi/boites/' . $s['boiteAutre'])->assertNotFound();
    $this->postJson('/api/v1/envoi/boites/' . $s['boiteAutre'] . '/suspendre')->assertNotFound();
    $this->postJson('/api/v1/envoi/boites/' . $s['boiteAutre'] . '/reactiver')->assertNotFound();
    expect(ligneBoite($s['autre']->id, $s['boiteAutre'])->etat)->toBe('active');
});

test('permissions du contrat : lecture et suspendre users.manage, réactiver workspaces.manage', function () {
    $s = semerBoites($this);
    $url = '/api/v1/envoi/boites/' . $s['boite'];
    $lecteur = $this->compte($s['espace'], 'viewer');
    $this->actingAs($lecteur, 'sanctum')->getJson('/api/v1/envoi/boites')->assertForbidden();
    $this->actingAs($lecteur, 'sanctum')->getJson($url)->assertForbidden();
    $this->actingAs($lecteur, 'sanctum')->postJson("$url/suspendre")->assertForbidden();
    $admin = $this->compte($s['espace'], 'admin');
    $this->actingAs($admin, 'sanctum')->postJson("$url/suspendre")->assertOk();
    $this->actingAs($admin, 'sanctum')->postJson("$url/reactiver")->assertForbidden();
    expect(ligneBoite($s['espace']->id, $s['boite'])->etat)->toBe('en_pause');
    $this->actingAs($this->compte($s['espace'], 'owner'), 'sanctum')->postJson("$url/reactiver")->assertOk();
});

test('suspendre pose suspension_manuelle, est idempotent, et réactiver la lève', function () {
    $s = semerBoites($this);
    $ws = $s['espace']->id;
    $this->actingAs($this->compte($s['espace'], 'owner'), 'sanctum');
    $url = '/api/v1/envoi/boites/' . $s['boite'];
    $r = $this->postJson("$url/suspendre")->assertOk();
    expect(ContratOpenApiEnvoi::ecarts('commun.yaml', 'BoiteResume', $r->json()))->toBe([]);
    expect($r->json('statut'))->toBe('suspendue');
    $l = ligneBoite($ws, $s['boite']);
    expect($l->etat)->toBe('en_pause')->and($l->pause_motif)->toBe('suspension_manuelle');
    $this->postJson("$url/suspendre")->assertOk();
    $apres = ligneBoite($ws, $s['boite']);
    expect($apres->pause_le)->toBe($l->pause_le)->and($apres->maj_le)->toBe($l->maj_le);
    $r = $this->postJson("$url/reactiver")->assertOk();
    expect(ContratOpenApiEnvoi::ecarts('commun.yaml', 'BoiteResume', $r->json()))->toBe([]);
    $l = ligneBoite($ws, $s['boite']);
    expect($l->etat)->toBe('active')->and($l->pause_motif)->toBeNull();
});

test('le stop gagne : suspendre sur une boîte déjà en pause ne change pas le motif, et réactiver reste 409', function (string $motif) {
    $s = semerBoites($this);
    $ws = $s['espace']->id;
    poserPause($ws, $s['boite'], $motif);
    $avant = ligneBoite($ws, $s['boite']);
    $this->actingAs($this->compte($s['espace'], 'owner'), 'sanctum');
    $url = '/api/v1/envoi/boites/' . $s['boite'];
    $this->postJson("$url/suspendre")->assertOk();
    $apres = ligneBoite($ws, $s['boite']);
    expect($apres->pause_motif)->toBe($motif)->and($apres->maj_le)->toBe($avant->maj_le)->and($apres->pause_le)->toBe($avant->pause_le);
    $r = $this->postJson("$url/reactiver")->assertStatus(409);
    expect($r->json('error'))->toBe('pause_automatique')->and($r->json('message'))->not->toContain('envoi:');
    expect(ligneBoite($ws, $s['boite'])->etat)->toBe('en_pause');
})->with(['manuelle', 'refus_connexion_5xx', 'auto_3x5xx_10min']);

test('une pause automatique survenant après une suspension manuelle remplace le motif, et réactiver rend 409', function () {
    $s = semerBoites($this);
    $ws = $s['espace']->id;
    $this->actingAs($this->compte($s['espace'], 'owner'), 'sanctum');
    $url = '/api/v1/envoi/boites/' . $s['boite'];
    $this->postJson("$url/suspendre")->assertOk();
    poserPause($ws, $s['boite'], 'refus_connexion_5xx');
    expect(ligneBoite($ws, $s['boite'])->pause_motif)->toBe('refus_connexion_5xx');
    $this->postJson("$url/reactiver")->assertStatus(409);
    expect(ligneBoite($ws, $s['boite'])->etat)->toBe('en_pause');
});

test('réactiver une boîte active est idempotent (200, rien d’écrit)', function () {
    $s = semerBoites($this);
    $avant = ligneBoite($s['espace']->id, $s['boite']);
    $this->actingAs($this->compte($s['espace'], 'owner'), 'sanctum')->postJson('/api/v1/envoi/boites/' . $s['boite'] . '/reactiver')->assertOk();
    expect(ligneBoite($s['espace']->id, $s['boite'])->maj_le)->toBe($avant->maj_le);
});

test('POST /envoi/boites, test-connexion et PUT reglages répondent 501 serveur_reel_requis avant toute lecture, sans écrire', function () {
    $s = semerBoites($this);
    $avant = nbBoites($s['espace']->id);
    foreach (['admin', 'viewer'] as $role) {
        $this->actingAs($this->compte($s['espace'], $role), 'sanctum');
        foreach ([
            ['POST', '/api/v1/envoi/boites', []],
            ['POST', '/api/v1/envoi/boites', ['adresse' => 'pas-une-adresse']],
            ['POST', '/api/v1/envoi/boites/test-connexion', []],
            ['PUT', '/api/v1/envoi/boites/' . $s['boite'] . '/reglages', ['signature' => 'x']],
            ['PUT', '/api/v1/envoi/boites/' . $s['boiteAutre'] . '/reglages', []],
            ['PUT', '/api/v1/envoi/boites/00000000-0000-0000-0000-000000000000/reglages', []],
        ] as [$m, $u, $corps]) {
            $r = $this->json($m, $u, $corps)->assertStatus(501);
            expect($r->json('error'))->toBe('serveur_reel_requis');
        }
    }
    expect(nbBoites($s['espace']->id))->toBe($avant)->and(ligneBoite($s['espace']->id, $s['boite'])->limite_jour)->toBe(20);
    expect($this->resolutions)->toBe(0);
});

test('les trois entrées NonServiAuPalier0 des boîtes sont déclarées en 501', function () {
    $e = NonServiAuPalier0::entrees();
    foreach (['POST /envoi/boites', 'POST /envoi/boites/test-connexion', 'PUT /envoi/boites/{id}/reglages'] as $couple) {
        expect($e)->toHaveKey($couple);
        expect($e[$couple]['code'])->toBe(501);
    }
    expect($e['PUT /envoi/boites/{id}/reglages']['exemple'])->toBe('/envoi/boites/00000000-0000-0000-0000-000000000000/reglages');
});

test('garde : aucune route ni méthode d’action des boîtes ne s’appelle reprendre', function () {
    foreach (app('router')->getRoutes() as $route) {
        if (str_contains($route->uri(), 'envoi/boites')) {
            expect(strtolower($route->uri() . ' ' . $route->getActionName()))->not->toContain('reprendre');
        }
    }
});
