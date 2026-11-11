<?php

namespace Tests\Feature\Envoi;

use App\Envoi\Resultats\DepotResultats;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Envoi\Routes\BaseRoutesEnvoi;
use Tests\Support\ContratOpenApiEnvoi;

uses(BaseRoutesEnvoi::class)->group('envoi');

/** Ligne de compteurs d'une boîte pour un jour (fictif). */
function ligne(string $ws, string $boite, string $jour, int $env, int $durs = 0, int $plaintes = 0, int $rep = 0, string $f = 'gmail'): void
{
    WorkspaceContext::run($ws, fn () => DB::table('envoi_chauffe_jours')->insert([
        'workspace_id' => $ws, 'boite' => $boite, 'jour' => $jour, 'fournisseur' => $f,
        'envoyes' => $env, 'rebonds_durs' => $durs, 'plaintes' => $plaintes, 'reponses' => $rep,
    ]));
}

beforeEach(function () {
    Carbon::setTestNow('2026-11-11 10:00:00');
    $this->ws = $this->espace(true);
    $this->admin = $this->compte($this->ws, 'admin');
});
afterEach(fn () => Carbon::setTestNow());

test('lecture réservée à users.manage : opérateur et lecteur 403, admin 200', function () {
    foreach (['/api/v1/envoi/resultats?par=boite', '/api/v1/envoi/resultats/courbe', '/api/v1/envoi/envois/chercher?adresse=a@exemple.test'] as $uri) {
        $this->actingAs($this->compte($this->ws, 'operator'))->getJson($uri)->assertForbidden();
        $this->actingAs($this->compte($this->ws, 'viewer'))->getJson($uri)->assertForbidden();
        $this->actingAs($this->admin)->getJson($uri)->assertOk();
    }
});

test('regroupement par boîte : remis = envoyés − rebonds durs − rebonds mous, période précédente de même longueur', function () {
    $id = $this->ws->id;
    ligne($id, 'b1', '2026-11-10', 100, 4, 1, 6);
    ligne($id, 'b1', '2026-10-20', 50, 0, 0, 2);   // dans les 30 jours (du 2026-10-13 au 2026-11-11)
    ligne($id, 'b1', '2026-10-05', 40, 2, 0, 1);   // période précédente (du 2026-09-13 au 2026-10-12)
    ligne($id, 'b1', '2026-08-01', 999);            // hors des deux périodes
    ligne($id, '(retours)', '2026-11-10', 5000);    // pseudo-boîte des retours : jamais comptée

    $r = $this->actingAs($this->admin)->getJson('/api/v1/envoi/resultats?par=boite');
    $r->assertOk();
    expect(ContratOpenApiEnvoi::ecarts('pilotage.yaml', 'Resultats', $r->json()))->toBe([]);
    expect($r->json('periode'))->toBe(['du' => '2026-10-13', 'au' => '2026-11-11']);
    $l = $r->json('data.0');
    expect($r->json('data'))->toHaveCount(1)
        ->and($l)->toMatchArray(['cle' => 'b1', 'envoyes' => 150, 'rebonds_durs' => 4, 'rebonds_mous' => 0, 'remis' => 146, 'plaintes' => 1, 'reponses' => 8])
        ->and($r->json('totaux.envoyes'))->toBe(150)
        ->and($r->json('precedent'))->toMatchArray(['envoyes' => 40, 'remis' => 38, 'rebonds_durs' => 2])
        ->and($r->json('entonnoir'))->toBe(['envoyes' => 150, 'reponses' => 8, 'rendez_vous' => null, 'gagnes' => null]);
});

test('jours : 7, 30 ou 90 seulement ; par obligatoire et connu', function () {
    $this->actingAs($this->admin)->getJson('/api/v1/envoi/resultats?par=boite&jours=15')->assertUnprocessable();
    $this->actingAs($this->admin)->getJson('/api/v1/envoi/resultats?par=inconnu')->assertUnprocessable();
    $this->actingAs($this->admin)->getJson('/api/v1/envoi/resultats')->assertUnprocessable();
    $this->actingAs($this->admin)->getJson('/api/v1/envoi/resultats?par=boite&jours=90')->assertOk();
});

test('par domaine : regroupe sur la partie après @ ; par campagne, liste, message : data vide sans erreur (avant R08)', function () {
    $id = $this->ws->id;
    ligne($id, 'a@envoi1.exemple.test', '2026-11-10', 10);
    ligne($id, 'b@envoi1.exemple.test', '2026-11-10', 20);
    $d = $this->actingAs($this->admin)->getJson('/api/v1/envoi/resultats?par=domaine')->assertOk();
    expect($d->json('data'))->toHaveCount(1)->and($d->json('data.0.cle'))->toBe('envoi1.exemple.test')->and($d->json('data.0.envoyes'))->toBe(30);
    foreach (['campagne', 'liste', 'message'] as $par) {
        $r = $this->actingAs($this->admin)->getJson("/api/v1/envoi/resultats?par={$par}")->assertOk();
        expect($r->json('data'))->toBe([])->and(ContratOpenApiEnvoi::ecarts('pilotage.yaml', 'Resultats', $r->json()))->toBe([]);
    }
});

test('bornes de jour en Europe/Paris autour du changement d’heure', function () {
    Carbon::setTestNow('2026-10-25 23:30:00'); // UTC : déjà le 26 à Paris
    $id = $this->ws->id;
    ligne($id, 'b1', '2026-10-26', 10);
    ligne($id, 'b1', '2026-10-20', 20);
    ligne($id, 'b1', '2026-10-19', 30); // veille de la période : va dans « précédent »
    $r = $this->actingAs($this->admin)->getJson('/api/v1/envoi/resultats?par=boite&jours=7')->assertOk();
    expect($r->json('periode'))->toBe(['du' => '2026-10-20', 'au' => '2026-10-26'])
        ->and($r->json('totaux.envoyes'))->toBe(30)
        ->and($r->json('precedent.envoyes'))->toBe(30);
});

test('courbe : 30 jours continus (zéros compris), conforme au contrat', function () {
    ligne($this->ws->id, 'b1', '2026-11-10', 100, 5, 0, 3);
    $r = $this->actingAs($this->admin)->getJson('/api/v1/envoi/resultats/courbe')->assertOk();
    expect($r->json('data'))->toHaveCount(30)->and($r->json('data.29'))->toBe(['jour' => '2026-11-11', 'remis' => 0, 'rebonds' => 0, 'reponses' => 0])
        ->and($r->json('data.28'))->toBe(['jour' => '2026-11-10', 'remis' => 95, 'rebonds' => 5, 'reponses' => 3]);
    expect(ContratOpenApiEnvoi::ecarts('pilotage.yaml', 'CourbeResultats', $r->json()))->toBe([]);
});

test('chercher : trouve par empreinte, journalise sans l’adresse, réponse identique pour inconnue et autre espace', function () {
    $adresse = 'personne@exemple.test';
    $autre = $this->espace(true);
    foreach ([$this->ws->id, $autre->id] as $i => $ws) {
        WorkspaceContext::run($ws, fn () => DB::table('envoi_evenements')->insert([
            'workspace_id' => $ws, 'boite' => 'b1', 'uid_validity' => 1, 'uid' => 1, 'empreinte_message' => str_repeat('a', 63) . $i,
            'classe' => 'rebond_dur', 'statut' => 'a_rattacher', 'empreinte_expediteur' => str_repeat('b', 64),
            'empreinte_destinataire' => ListeSuppression::empreinte($adresse), 'recu_le' => '2026-11-09 08:00:00+01',
        ]));
    }
    $trouve = $this->actingAs($this->admin)->getJson('/api/v1/envoi/envois/chercher?adresse=' . urlencode($adresse))->assertOk();
    expect($trouve->json('data'))->toHaveCount(1)->and($trouve->json('data.0.classe'))->toBe('rebond_dur');
    expect(ContratOpenApiEnvoi::ecarts('pilotage.yaml', 'RechercheEnvoi', $trouve->json()))->toBe([]);

    $espaceVide = $this->espace(true);
    $inconnue = $this->actingAs($this->compte($espaceVide, 'admin'))->getJson('/api/v1/envoi/envois/chercher?adresse=' . urlencode($adresse));
    $absente = $this->actingAs($this->compte($espaceVide, 'admin'))->getJson('/api/v1/envoi/envois/chercher?adresse=' . urlencode('inconnue@exemple.test'));
    expect($inconnue->getContent())->toBe($absente->getContent())->and($inconnue->json())->toBe(['data' => []]);

    $journal = DB::table('business_events')->where('action', 'envoi.recherche')->where('workspace_id', $this->ws->id)->first();
    expect($journal)->not->toBeNull()->and($journal->actor_user_id)->toBe($this->admin->id);
    expect($journal->context)->toContain(ListeSuppression::empreinte($adresse))->not->toContain('personne@')->not->toContain($adresse);
});

test('chercher : 10 recherches par minute et par compte, la 11e répond 429', function () {
    RateLimiter::clear('envoi-chercher:' . $this->admin->id);
    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($this->admin)->getJson("/api/v1/envoi/envois/chercher?adresse=x{$i}@exemple.test")->assertOk();
    }
    $this->actingAs($this->admin)->getJson('/api/v1/envoi/envois/chercher?adresse=x11@exemple.test')->assertStatus(429);
    $this->actingAs($this->admin)->getJson('/api/v1/envoi/envois/chercher')->assertStatus(429);
});

test('chercher : adresse obligatoire et de forme valide (422)', function () {
    $this->actingAs($this->admin)->getJson('/api/v1/envoi/envois/chercher')->assertUnprocessable();
    $this->actingAs($this->admin)->getJson('/api/v1/envoi/envois/chercher?adresse=pas-une-adresse')->assertUnprocessable();
});

test('90 jours sur 10 000 lignes : moins de 300 ms, avec l’index de lecture', function () {
    $id = $this->ws->id;
    WorkspaceContext::run($id, fn () => DB::statement(
        "INSERT INTO envoi_chauffe_jours (workspace_id, boite, jour, fournisseur, envoyes, rebonds_durs, plaintes, reponses)
         SELECT ?::uuid, 'b' || b, (date '2026-11-11' - d), 'gmail', 50, 1, 0, 2 FROM generate_series(1, 112) b, generate_series(0, 89) d",
        [$id],
    ));
    expect(DB::table('envoi_chauffe_jours')->count())->toBeGreaterThanOrEqual(10000);
    expect(DB::selectOne("SELECT 1 AS e FROM pg_indexes WHERE indexname = 'idx_envoi_resultats_chauffe'"))->not->toBeNull();
    $depot = app(DepotResultats::class);
    $depot->parChauffe($id, 'boite', '2026-08-14', '2026-11-11'); // échauffement
    $t = microtime(true);
    $lignes = $depot->parChauffe($id, 'boite', '2026-08-14', '2026-11-11');
    expect((microtime(true) - $t) * 1000)->toBeLessThan(300)->and($lignes)->toHaveCount(112);
});
