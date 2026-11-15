<?php

namespace Tests\Feature\Envoi\Routes;

use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\PasserelleLocale;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Http\Controllers\Api\EnvoiSocleController;
use App\Support\WorkspaceContext;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ContratOpenApiEnvoi;

uses(BaseRoutesEnvoi::class)->group('envoi');

const SOCLE_TITRE_ALERTE = '[ALERTE] Tout arrêter armé';

function journalArret(string $ws): int
{
    return (int) WorkspaceContext::run($ws, fn () => DB::table('envoi_journal_commandes')->where('workspace_id', $ws)->where('commande', 'tout_arreter')->count());
}

/** @return list<string> titres des alertes d'arrêt émises (journal de l'application) */
function ecouterAlertesArret(): object
{
    $vues = new \ArrayObject;
    Event::listen(MessageLogged::class, function (MessageLogged $e) use ($vues): void {
        if (str_starts_with($e->message, SOCLE_TITRE_ALERTE)) {
            $vues[] = $e->message;
        }
    });

    return $vues;
}

test('GET /envoi/etat : espace ouvert = actif, conforme au contrat, sans résoudre ServeurEnvoi', function () {
    Http::preventStrayRequests();
    $ws = $this->espace(true);
    $r = $this->actingAs($this->compte($ws, 'viewer'))->getJson('/api/v1/envoi/etat')->assertOk();
    expect($this->resolutions)->toBe(0)
        ->and(ContratOpenApiEnvoi::ecartsReponse('socle.yaml', '/envoi/etat', 'get', 200, $r->json()))->toBe([])
        ->and($r->json('etat'))->toBe('actif')->and($r->json('par'))->toBeNull()->and($r->json('motif'))->toBeNull();
});

test('GET /envoi/compteurs : chiffres lus en base pour cet espace seulement, conformes au contrat', function () {
    Http::preventStrayRequests();
    $ws = $this->espace(true);
    $autre = $this->espace(true);
    foreach ([[$ws, 'a@compteurs.example.test', 'active'], [$ws, 'b@compteurs.example.test', 'en_pause'], [$autre, 'c@compteurs.example.test', 'active']] as [$e, $adresse, $etat]) {
        WorkspaceContext::run($e->id, fn () => DB::table('envoi_boites')->insert([
            'id' => (string) Str::uuid(), 'workspace_id' => $e->id, 'adresse' => $adresse, 'hote' => 'smtp.example.test', 'port' => 587,
            'identifiants_chiffres' => 'x', 'etat' => $etat,
        ]));
    }
    $r = $this->actingAs($this->compte($ws, 'operator'))->getJson('/api/v1/envoi/compteurs')->assertOk();
    expect($this->resolutions)->toBe(0)
        ->and(ContratOpenApiEnvoi::ecartsReponse('socle.yaml', '/envoi/compteurs', 'get', 200, $r->json()))->toBe([])
        ->and($r->json('boites_total'))->toBe(2)->and($r->json('boites_pretes'))->toBe(1)
        ->and($r->json('plafond_jour'))->toBeGreaterThan(0)->and($r->json('envoyes_aujourdhui'))->toBe(0);
});

test('arret-general arme le drapeau de CET espace seulement, journalise une fois, alerte une fois, idempotent', function () {
    Http::preventStrayRequests();
    $alertes = ecouterAlertesArret();
    $ws = $this->espace(true);
    $autre = $this->espace(true);
    $depot = app(DepotSocle::class);
    $admin = $this->compte($ws, 'admin');
    $r = $this->actingAs($admin)->postJson('/api/v1/envoi/arret-general', ['motif' => 'Contrôle de routine'])->assertOk();
    expect(ContratOpenApiEnvoi::ecartsReponse('socle.yaml', '/envoi/arret-general', 'post', 200, $r->json()))->toBe([])
        ->and($r->json('etat'))->toBe('arrete')->and($r->json('feu'))->toBe('rouge')->and($r->json('par'))->toBe('Compte Test')
        ->and($depot->toutArreter($ws->id))->toBeTrue()
        ->and($depot->toutArreter($autre->id))->toBeFalse()
        ->and(journalArret($ws->id))->toBe(1)->and(journalArret($autre->id))->toBe(0)
        ->and(count($alertes))->toBe(1)->and($this->resolutions)->toBe(0);
    $depuis = $r->json('depuis');
    $deux = $this->actingAs($admin)->postJson('/api/v1/envoi/arret-general')->assertOk();
    expect($deux->json('depuis'))->toBe($depuis)->and(journalArret($ws->id))->toBe(1)->and(count($alertes))->toBe(1);
    $this->actingAs($this->compte($autre, 'owner'))->getJson('/api/v1/envoi/etat')->assertOk()->assertJsonPath('etat', 'actif');
    $this->actingAs($admin)->getJson('/api/v1/envoi/etat')->assertOk()->assertJsonPath('etat', 'arrete');
});

test('le motif est borné en longueur et refuse « @ » : 422, rien n\'est écrit, pas d\'alerte', function () {
    $alertes = ecouterAlertesArret();
    $ws = $this->espace(true);
    $admin = $this->compte($ws, 'owner');
    foreach (['pierre@example.test', str_repeat('a', 201), ['x'], 12] as $mauvais) {
        $this->actingAs($admin)->postJson('/api/v1/envoi/arret-general', ['motif' => $mauvais])->assertStatus(422);
    }
    expect(journalArret($ws->id))->toBe(0)->and(app(DepotSocle::class)->toutArreter($ws->id))->toBeFalse()->and(count($alertes))->toBe(0);
    $this->actingAs($admin)->postJson('/api/v1/envoi/arret-general', ['motif' => str_repeat('a', 200)])->assertOk();
});

test('opérateur et lecteur : 403, rien n\'est armé', function () {
    $ws = $this->espace(true);
    foreach (['operator', 'viewer'] as $role) {
        $this->actingAs($this->compte($ws, $role))->postJson('/api/v1/envoi/arret-general')->assertForbidden();
    }
    expect(app(DepotSocle::class)->toutArreter($ws->id))->toBeFalse()->and(journalArret($ws->id))->toBe(0);
});

test('après l\'arrêt, une injection est refusée (EnvoiRefuse::toutArreter), la porte étant ouverte par fermeture', function () {
    $ws = $this->espace(true);
    $faux = new FauxServeurEnvoi;
    $passerelle = new PasserelleLocale($faux, app(DepotSocle::class), fn () => true, 300, null, '', 300);
    $this->actingAs($this->compte($ws, 'admin'))->postJson('/api/v1/envoi/arret-general')->assertOk();
    $message = new MessageAEnvoyer($ws->id, 'x@societe.example.test', 'boite@example.test', 'S', 'C', (string) Str::uuid());
    expect(fn () => $passerelle->injecter($message))->toThrow(EnvoiRefuse::class, 'Tout arrêter est actif')
        ->and($faux->appels)->toBe(0)->and($this->resolutions)->toBe(0);
});

test('deux appels CONCURRENTS (processus réels) = une seule ligne de journal et une seule alerte', function () {
    $proprio = DB::connection('pgsql_owner');
    $ws = (string) Str::uuid();
    $fichier = sys_get_temp_dir() . '/arret-general-' . $ws . '.log';
    $proprio->table('workspaces')->insert(['id' => $ws, 'slug' => 'zz-ag-' . substr($ws, 0, 6), 'name' => 'Concurrence arrêt', 'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    try {
        $depart = microtime(true) + 4;
        $procs = $tubes = [];
        foreach ([1, 2, 3] as $i) {
            $procs[$i] = proc_open([PHP_BINARY, base_path('tests/Support/arret-general-concurrent.php'), $ws, $fichier, sprintf('%.4F', $depart)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes[$i]);
        }
        foreach ($procs as $i => $p) {
            $sortie = stream_get_contents($tubes[$i][1]) . stream_get_contents($tubes[$i][2]);
            expect(proc_close($p))->toBe(0, $sortie);
        }
        $alertes = substr_count((string) @file_get_contents($fichier), SOCLE_TITRE_ALERTE);
        expect($proprio->table('envoi_journal_commandes')->where('workspace_id', $ws)->where('commande', 'tout_arreter')->count())->toBe(1)
            ->and($alertes)->toBe(1)
            ->and($proprio->table('envoi_drapeaux')->where('workspace_id', $ws)->value('tout_arreter'))->toBeTrue();
    } finally {
        @unlink($fichier);
        $proprio->statement("SET session_replication_role = 'replica'");
        foreach (['envoi_journal_commandes', 'envoi_drapeaux'] as $t) {
            $proprio->table($t)->where('workspace_id', $ws)->delete();
        }
        $proprio->table('workspaces')->where('id', $ws)->delete();
        $proprio->statement("SET session_replication_role = 'origin'");
    }
});

test('GARDE : aucune route ne lève l\'arrêt, le contrôleur n\'a ni reprendre, ni armer, ni lever', function () {
    $socle = array_values(array_filter($this->routesEnvoi(), fn ($r) => in_array($r[1], ['/api/v1/envoi/etat', '/api/v1/envoi/compteurs', '/api/v1/envoi/arret-general'], true)));
    sort($socle);
    expect($socle)->toBe([['GET', '/api/v1/envoi/compteurs'], ['GET', '/api/v1/envoi/etat'], ['POST', '/api/v1/envoi/arret-general']]);
    foreach (app('router')->getRoutes() as $route) {
        if (str_contains($route->uri(), 'envoi/') && $route->getActionName() !== 'Closure' && str_contains($route->getActionName(), 'EnvoiSocleController')) {
            expect(strtolower($route->getActionName()))->not->toMatch('/reprendre|armer|lever|reprise/');
        }
    }
    $methodes = array_map('strtolower', get_class_methods(EnvoiSocleController::class));
    expect($methodes)->toContain('arretgeneral')->and(implode(' ', $methodes))->not->toMatch('/reprendre|armer|lever|reprise/');
});

test('l\'arrêt est cloisonné : un compte d\'un autre espace ne l\'arme pas par erreur', function () {
    $a = $this->espace(true);
    $b = $this->espace(true);
    $this->actingAs($this->compte($b, 'admin'))->postJson('/api/v1/envoi/arret-general')->assertOk();
    expect(app(DepotSocle::class)->toutArreter($a->id))->toBeFalse();
});
