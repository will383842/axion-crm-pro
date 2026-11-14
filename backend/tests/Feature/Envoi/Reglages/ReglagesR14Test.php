<?php

use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Reglages\AssouplissementRefuse;
use App\Envoi\Reglages\ReglesPrudence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Envoi\Routes\BaseRoutesEnvoi;
use Tests\Support\ContratOpenApiEnvoi;

/** R14b : réglages d'envoi. On ne peut que durcir ; rien ici ne fait partir un message. */
uses(BaseRoutesEnvoi::class)->group('envoi');

function reglesValides(array $m = []): array
{
    return array_merge([
        'pause_si_refus_pct' => 2.0, 'pause_si_plaintes_pour_mille' => 1.0, 'test_du_matin_bloquant' => true,
        'frein_reponses_par_jour' => 15, 'pression_max_par_organisation_30j' => 3, 'heure_de' => '08:00',
        'heure_a' => '19:00', 'jours_ouvres_seulement' => true,
    ], $m);
}

test('ReglesPrudence : un maximum ne peut que baisser, un minimum que monter (unitaire, sens de chaque seuil)', function () {
    $base = reglesValides();
    $durci = ReglesPrudence::durcir($base, reglesValides(['pause_si_refus_pct' => 1.5, 'frein_reponses_par_jour' => 10, 'heure_de' => '09:00', 'heure_a' => '17:30', 'pression_max_par_organisation_30j' => 2]));
    expect($durci['pause_si_refus_pct'])->toBe(1.5)->and($durci['heure_de'])->toBe('09:00')->and($durci['heure_a'])->toBe('17:30');
    foreach ([['pause_si_refus_pct', 2.5], ['pause_si_plaintes_pour_mille', 2.0], ['frein_reponses_par_jour', 16], ['pression_max_par_organisation_30j', 4],
        ['heure_de', '07:00'], ['heure_a', '19:30'], ['jours_ouvres_seulement', false], ['test_du_matin_bloquant', false]] as [$champ, $valeur]) {
        expect(fn () => ReglesPrudence::durcir($base, reglesValides([$champ => $valeur])))->toThrow(AssouplissementRefuse::class);
    }
});

test('assouplir un seuil répond 422 et le seuil reste inchangé ; durcir est enregistré dans envoi_seuils', function () {
    $ws = $this->espace(true);
    $owner = $this->compte($ws, 'owner');
    $depot = app(DepotChauffe::class);
    $this->actingAs($owner)->putJson('/api/v1/envoi/reglages/regles', reglesValides(['pause_si_refus_pct' => 1.0]))->assertOk();
    $apres = $depot->seuil($ws->id, 'rebonds_durs_taux_max');
    expect($apres)->toBe(0.01);

    $r = $this->actingAs($owner)->putJson('/api/v1/envoi/reglages/regles', reglesValides(['pause_si_refus_pct' => 1.8, 'frein_reponses_par_jour' => 12]));
    $r->assertStatus(422)->assertJsonPath('error', 'assouplissement_refuse');
    expect($depot->seuil($ws->id, 'rebonds_durs_taux_max'))->toBe($apres)
        ->and($depot->seuil($ws->id, 'humain_pause_a'))->toBeNull(); // rien n'est écrit à moitié

    $lu = $this->actingAs($owner)->getJson('/api/v1/envoi/reglages/regles')->assertOk();
    expect(ContratOpenApiEnvoi::ecarts('conformite.yaml', 'Regles', $lu->json()))->toBe([])
        ->and($lu->json('pause_si_refus_pct'))->toEqual(1.0);
});

test('règles : corps incomplet ou invalide => 422 validation ; écriture réservée à workspaces.manage', function () {
    $ws = $this->espace(true);
    $owner = $this->compte($ws, 'owner');
    $this->actingAs($owner)->putJson('/api/v1/envoi/reglages/regles', ['pause_si_refus_pct' => 1])->assertStatus(422)->assertJsonPath('error', 'validation');
    $this->actingAs($owner)->putJson('/api/v1/envoi/reglages/regles', reglesValides(['heure_de' => '25:99']))->assertStatus(422);
    $this->actingAs($this->compte($ws, 'admin'))->putJson('/api/v1/envoi/reglages/regles', reglesValides())->assertForbidden();
    $this->actingAs($this->compte($ws, 'admin'))->getJson('/api/v1/envoi/reglages/regles')->assertOk();
});

test('absence : ouvre puis lève une pause de motif absence SEULEMENT, sans toucher une pause plaintes', function () {
    $ws = $this->espace(true);
    $owner = $this->compte($ws, 'owner');
    DB::table('envoi_pauses')->insert(['workspace_id' => $ws->id, 'portee' => 'global', 'motif' => 'plaintes']);
    $r = $this->actingAs($owner)->putJson('/api/v1/envoi/reglages/absence', ['actif' => true, 'du' => '2026-12-20', 'au' => '2026-12-31'])->assertOk();
    expect(ContratOpenApiEnvoi::ecartsReponse('conformite.yaml', '/envoi/reglages/absence', 'put', 200, $r->json()))->toBe([]);
    expect(DB::table('envoi_pauses')->where('motif', 'absence')->whereNull('levee_le')->count())->toBe(1);

    $this->actingAs($owner)->putJson('/api/v1/envoi/reglages/absence', ['actif' => true, 'du' => null, 'au' => null])->assertOk(); // rejeu : pas de doublon
    expect(DB::table('envoi_pauses')->where('motif', 'absence')->count())->toBe(1);

    $this->actingAs($owner)->putJson('/api/v1/envoi/reglages/absence', ['actif' => false, 'du' => null, 'au' => null])->assertOk();
    expect(DB::table('envoi_pauses')->where('motif', 'absence')->whereNotNull('levee_le')->count())->toBe(1)
        ->and(DB::table('envoi_pauses')->where('motif', 'plaintes')->whereNull('levee_le')->count())->toBe(1);
    $this->actingAs($owner)->putJson('/api/v1/envoi/reglages/absence', ['actif' => true, 'du' => '2026-12-31', 'au' => '2026-12-20'])->assertStatus(422);
});

test('connexion CRM : « interne » au palier 0, tester sans aucun socket sortant', function () {
    $ws = $this->espace(true);
    $admin = $this->compte($ws, 'admin');
    $r = $this->actingAs($admin)->getJson('/api/v1/envoi/reglages/connexion-crm')->assertOk();
    expect(ContratOpenApiEnvoi::ecarts('conformite.yaml', 'ConnexionCrm', $r->json()))->toBe([])->and($r->json('adaptateur'))->toBe('interne');
    Http::preventStrayRequests();
    $t = $this->actingAs($this->compte($ws, 'owner'))->postJson('/api/v1/envoi/reglages/connexion-crm/tester')->assertOk();
    expect($t->json('adaptateur'))->toBe('interne')->and($t->json('derniere_synchro_le'))->toBeNull();
});

test('carnet : 409 explicite en lecture comme en écriture, aucune donnée créée', function () {
    $ws = $this->espace(true);
    $owner = $this->compte($ws, 'owner');
    $this->actingAs($owner)->getJson('/api/v1/envoi/carnet')->assertStatus(409)->assertJsonPath('error', 'carnet_indisponible');
    $this->actingAs($owner)->postJson('/api/v1/envoi/carnet', ['adresse' => 'a@example.invalid'])->assertStatus(409);
});

test('identité : versions en ajout seul, la dernière fait foi, désinscription toujours présente', function () {
    $ws = $this->espace(true);
    $owner = $this->compte($ws, 'owner');
    $corps = ['raison_sociale' => 'Exemple SAS', 'forme' => 'SAS', 'adresse_postale' => '1 rue de l’Exemple, 00000 Ville', 'signature' => 'L’équipe', 'lien_desinscription' => true, 'lien_rendez_vous' => null, 'bloc_information' => 'Pourquoi ce message.'];
    $this->actingAs($owner)->putJson('/api/v1/envoi/reglages/identite', $corps)->assertOk();
    $this->actingAs($owner)->putJson('/api/v1/envoi/reglages/identite', array_merge($corps, ['signature' => 'Nouvelle']))->assertOk();
    $r = $this->actingAs($owner)->getJson('/api/v1/envoi/reglages/identite')->assertOk();
    expect($r->json('signature'))->toBe('Nouvelle')->and(ContratOpenApiEnvoi::ecarts('conformite.yaml', 'Identite', $r->json()))->toBe([])
        ->and(DB::table('envoi_identite_versions')->count())->toBe(2);
    $this->actingAs($owner)->putJson('/api/v1/envoi/reglages/identite', array_merge($corps, ['lien_desinscription' => false]))->assertStatus(422);
    expect(fn () => DB::table('envoi_identite_versions')->delete())->toThrow(QueryException::class);
});

test('réglages d’ensemble : plafond et jours ne se desserrent pas ; aperçu conforme au contrat', function () {
    $ws = $this->espace(true);
    $owner = $this->compte($ws, 'owner');
    $lu = $this->actingAs($owner)->getJson('/api/v1/envoi/reglages')->assertOk();
    expect(ContratOpenApiEnvoi::ecarts('conformite.yaml', 'Reglages', $lu->json()))->toBe([]);
    $corps = $lu->json();
    $corps['plafond_jour'] = max(1, $corps['plafond_jour'] - 1);
    $this->actingAs($owner)->putJson('/api/v1/envoi/reglages', $corps)->assertOk()->assertJsonPath('plafond_jour', $corps['plafond_jour']);
    $corps['plafond_jour'] += 5;
    $this->actingAs($owner)->putJson('/api/v1/envoi/reglages', $corps)->assertStatus(422)->assertJsonPath('error', 'assouplissement_refuse');
    $corps['plafond_jour'] -= 5;
    $corps['jours'] = ['lun', 'mar', 'mer', 'jeu', 'ven', 'sam'];
    $this->actingAs($owner)->putJson('/api/v1/envoi/reglages', $corps)->assertStatus(422);

    $a = $this->actingAs($this->compte($ws, 'admin'))->getJson('/api/v1/envoi/reglages/apercu')->assertOk();
    expect(ContratOpenApiEnvoi::ecarts('conformite.yaml', 'ApercuReglages', $a->json()))->toBe([]);
});

test('aucune de ces routes ne résout ServeurEnvoi', function () {
    $ws = $this->espace(true);
    $owner = $this->compte($ws, 'owner');
    foreach (['reglages', 'reglages/regles', 'reglages/apercu', 'reglages/identite', 'reglages/connexion-crm', 'alertes'] as $u) {
        $this->actingAs($owner)->getJson('/api/v1/envoi/' . $u)->assertOk();
    }
    expect($this->resolutions)->toBe(0);
});
