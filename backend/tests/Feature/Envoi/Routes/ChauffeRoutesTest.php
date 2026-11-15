<?php

namespace Tests\Feature\Envoi\Routes;

use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\JourEnvoi;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ContratOpenApiEnvoi;

uses(BaseRoutesEnvoi::class)->group('envoi');

/** $n jours d'envoi effectifs (20 par jour) se terminant la veille d'aujourd'hui (Paris) pour la ressource. */
function chaufferRoute(Workspace $ws, string $type, string $cle, int $n): void
{
    $depot = app(DepotChauffe::class);
    $aujourdhui = JourEnvoi::de(new DateTimeImmutable('now'));
    for ($i = 1; $i <= $n; $i++) {
        $depot->incrementer($ws->id, $cle, $aujourdhui->modify("-{$i} days"), 'autre', 'envoyes', 20, $type);
    }
}

function reculsEcrits(Workspace $ws): int
{
    return WorkspaceContext::run($ws->id, fn () => DB::table('envoi_chauffe_reculs')->where('workspace_id', $ws->id)->count());
}

function rangPalier(string $p): int
{
    return (int) substr($p, 1);
}

/** @return array{ws: Workspace, autre: Workspace, boite: string} */
function semerChauffe(object $t): array
{
    $nouvel = \Closure::bind(fn () => $this->espace(true), $t, BaseRoutesEnvoi::class);
    $ws = $nouvel();
    $autre = $nouvel();
    $boite = (string) Str::uuid();
    WorkspaceContext::run($ws->id, fn () => DB::table('envoi_boites')->insert([
        'id' => $boite, 'workspace_id' => $ws->id, 'adresse' => 'a@chauffe-a.exemple.test', 'hote' => 'smtp.exemple.invalid', 'port' => 587,
        'identifiants_chiffres' => 'x',
    ]));
    chaufferRoute($ws, 'ip', 'ip-a', 8);        // P2
    chaufferRoute($ws, 'domaine', $boite, 12);  // P3
    chaufferRoute($ws, 'ip', 'ip-neuve', 1);    // P1
    chaufferRoute($autre, 'ip', 'ip-autre', 8);

    return ['ws' => $ws, 'autre' => $autre, 'boite' => $boite];
}

test('GET /envoi/chauffe suit le contrat, ne résout pas ServeurEnvoi et ne montre que son espace', function () {
    Http::preventStrayRequests();
    $s = semerChauffe($this);
    $r = $this->actingAs($this->compte($s['ws'], 'admin'))->getJson('/api/v1/envoi/chauffe')->assertOk();
    $j = $r->json();
    expect($this->resolutions)->toBe(0);
    expect(ContratOpenApiEnvoi::ecartsReponse('infrastructure.yaml', '/envoi/chauffe', 'get', 200, $j))->toBe([]);
    $ids = array_column($j['ressources'], 'id');
    sort($ids);
    expect($ids)->toBe(['domaine:' . $s['boite'], 'ip:ip-a', 'ip:ip-neuve'])
        ->and(json_encode($j))->not->toContain('ip-autre')
        ->and($j['paliers'])->toHaveCount(8)->and($j['budget_du_jour'])->toBeGreaterThanOrEqual(0)
        ->and($j['plafond_boite'])->toBe(150);
    $parId = array_column($j['ressources'], null, 'id');
    expect($parId['ip:ip-a']['palier'])->toBe('P2')->and($parId['ip:ip-a']['peut_reculer'])->toBeTrue()
        ->and($parId['domaine:' . $s['boite']]['palier'])->toBe('P3')->and($parId['domaine:' . $s['boite']]['libelle'])->toBe('chauffe-a.exemple.test')
        ->and($parId['ip:ip-neuve']['palier'])->toBe('P1')->and($parId['ip:ip-neuve']['peut_reculer'])->toBeFalse();
    expect(json_encode($j))->not->toContain('a@chauffe-a');
});

test('reculer écrit un recul, le renvoie conforme, et un second appel le même jour est sans nouvel effet', function () {
    Http::preventStrayRequests();
    $s = semerChauffe($this);
    $proprio = $this->compte($s['ws'], 'owner');
    $r = $this->actingAs($proprio)->postJson('/api/v1/envoi/chauffe/ip:ip-a/reculer')->assertOk();
    expect(ContratOpenApiEnvoi::ecartsReponse('infrastructure.yaml', '/envoi/chauffe/{id}/reculer', 'post', 200, $r->json()))->toBe([]);
    expect($r->json('id'))->toBe('ip:ip-a')->and($r->json('palier'))->toBe('P1')->and(reculsEcrits($s['ws']))->toBe(1);
    $this->actingAs($proprio)->postJson('/api/v1/envoi/chauffe/ip:ip-a/reculer')->assertOk();
    expect(reculsEcrits($s['ws']))->toBe(1)->and($this->resolutions)->toBe(0);
});

test('reculer est réservé au propriétaire : administrateur, opérateur et lecteur reçoivent 403, rien n\'est écrit', function () {
    $s = semerChauffe($this);
    foreach (['admin', 'operator', 'viewer'] as $role) {
        $this->actingAs($this->compte($s['ws'], $role))->postJson('/api/v1/envoi/chauffe/ip:ip-a/reculer')->assertForbidden();
    }
    expect(reculsEcrits($s['ws']))->toBe(0);
});

test('la ressource d\'un autre espace ou inconnue répond 404 et rien n\'est écrit', function () {
    $s = semerChauffe($this);
    $proprio = $this->compte($s['ws'], 'owner');
    $this->actingAs($proprio)->postJson('/api/v1/envoi/chauffe/ip:ip-autre/reculer')->assertNotFound();
    $this->actingAs($proprio)->postJson('/api/v1/envoi/chauffe/ip:inconnue/reculer')->assertNotFound();
    $this->actingAs($proprio)->postJson('/api/v1/envoi/chauffe/n-importe-quoi/reculer')->assertNotFound();
    expect(reculsEcrits($s['ws']))->toBe(0)->and(reculsEcrits($s['autre']))->toBe(0);
});

test('une ressource en P1 : reculer rend 409 et rien n\'est écrit', function () {
    $s = semerChauffe($this);
    $this->actingAs($this->compte($s['ws'], 'owner'))->postJson('/api/v1/envoi/chauffe/ip:ip-neuve/reculer')->assertStatus(409);
    expect(reculsEcrits($s['ws']))->toBe(0);
});

test('aucun geste ne fait monter un palier : une seule action, reculer, et le palier ne monte jamais', function () {
    $s = semerChauffe($this);
    $chauffe = array_filter($this->routesEnvoi(), fn ($r) => str_starts_with($r[1], '/api/v1/envoi/chauffe'));
    sort($chauffe);
    expect(array_values($chauffe))->toBe([['GET', '/api/v1/envoi/chauffe'], ['POST', '/api/v1/envoi/chauffe/exemple/reculer']]);
    $proprio = $this->compte($s['ws'], 'owner');
    $avant = array_column($this->actingAs($proprio)->getJson('/api/v1/envoi/chauffe')->json('ressources'), 'palier', 'id');
    $this->actingAs($proprio)->postJson('/api/v1/envoi/chauffe/domaine:' . $s['boite'] . '/reculer')->assertOk();
    $apres = array_column($this->actingAs($proprio)->getJson('/api/v1/envoi/chauffe')->json('ressources'), 'palier', 'id');
    foreach ($avant as $id => $p) {
        expect(rangPalier($apres[$id]))->toBeLessThanOrEqual(rangPalier($p));
    }
    expect(rangPalier($apres['domaine:' . $s['boite']]))->toBe(2);
});
