<?php

namespace Tests\Feature\Envoi;

use App\Envoi\Chauffe\DecisionFrein;
use App\Envoi\Chauffe\Freins;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Envoi\Routes\BaseRoutesEnvoi;
use Tests\Support\ContratOpenApiEnvoi;

uses(BaseRoutesEnvoi::class)->group('envoi');

/** Constat fictif (ressource en .invalid : aucune donnée réelle). */
function constatR17(array $surcharge = []): array
{
    return array_merge([
        'source' => 'google_postmaster',
        'ressource_type' => 'domaine',
        'ressource' => 'envoi-1.example.invalid',
        'jour' => now()->format('Y-m-d'),
        'reputation' => 'mauvaise',
        'taux_plaintes' => 0.012,
        'erreurs_auth' => 3,
        'pieges' => null,
        'preuve' => 'Lu dans Postmaster Tools : réputation du domaine « mauvaise » ce jour.',
    ], $surcharge);
}

function pausesR17(string $ws): array
{
    return DB::table('envoi_pauses')->where('workspace_id', $ws)->whereNull('levee_le')->get()->all();
}

test('frein pur : seule « mauvaise » freine ; bonne, moyenne, inconnue ne font rien', function () {
    expect(Freins::reputationMauvaise('mauvaise', 'gmail'))->toBeInstanceOf(DecisionFrein::class)
        ->and(Freins::reputationMauvaise('mauvaise', 'gmail')->motif)->toBe('reputation_mauvaise')
        ->and(Freins::reputationMauvaise('mauvaise', 'gmail')->fournisseur)->toBe('gmail')
        ->and(Freins::reputationMauvaise('bonne', 'gmail'))->toBeNull()
        ->and(Freins::reputationMauvaise('moyenne', 'gmail'))->toBeNull()
        ->and(Freins::reputationMauvaise('inconnue', 'gmail'))->toBeNull();
});

test('saisie « mauvaise » : constat enregistré, pause vers le fournisseur de la source, contrat respecté', function (string $source, string $fournisseur) {
    $espace = $this->espace(true);
    $owner = $this->compte($espace, 'owner');

    $r = $this->actingAs($owner)->postJson('/api/v1/envoi/reputation/constats', constatR17(['source' => $source]));

    $r->assertCreated()->assertJsonPath('pause_ouverte', true)->assertJsonPath('saisie', 'manuelle');
    expect(ContratOpenApiEnvoi::ecarts('reputation.yaml', 'ConstatReputation', $r->json()))->toBe([]);
    $pauses = pausesR17($espace->id);
    expect($pauses)->toHaveCount(1)
        ->and($pauses[0]->motif)->toBe('reputation_mauvaise')
        ->and($pauses[0]->portee)->toBe('fournisseur')
        ->and($pauses[0]->fournisseur)->toBe($fournisseur);
    expect($this->resolutions)->toBe(0);
})->with([
    ['google_postmaster', 'gmail'],
    ['microsoft_snds', 'microsoft'],
    ['microsoft_jmrp', 'microsoft'],
    ['orange', 'autre'],
]);

test('saisie « bonne » (ou moyenne, inconnue) : aucune pause ouverte, et une pause existante n’est JAMAIS levée', function () {
    $espace = $this->espace(true);
    $owner = $this->compte($espace, 'owner');
    $this->actingAs($owner)->postJson('/api/v1/envoi/reputation/constats', constatR17())->assertCreated();
    $avant = pausesR17($espace->id);
    expect($avant)->toHaveCount(1);

    foreach (['bonne', 'moyenne', 'inconnue'] as $i => $valeur) {
        $r = $this->actingAs($owner)->postJson('/api/v1/envoi/reputation/constats', constatR17(['reputation' => $valeur, 'erreurs_auth' => $i]));
        $r->assertCreated()->assertJsonPath('pause_ouverte', false);
    }

    expect(pausesR17($espace->id))->toHaveCount(1)
        ->and(DB::table('envoi_pauses')->whereNotNull('levee_le')->count())->toBe(0);
});

test('preuve contenant une adresse e-mail (même déguisée) : 422 et rien d’écrit', function (string $preuve) {
    $espace = $this->espace(true);
    $this->actingAs($this->compte($espace, 'owner'))
        ->postJson('/api/v1/envoi/reputation/constats', constatR17(['preuve' => $preuve]))
        ->assertStatus(422);
    expect(DB::table('envoi_reputation_constats')->count())->toBe(0)
        ->and(pausesR17($espace->id))->toBeEmpty();
})->with([
    ['Rapport reçu de postmaster@exemple.test aujourd’hui.'],
    ['Rapport reçu de postmaster arobase exemple point test.'],
    ['court'],
]);

test('ressource invalide (adresse e-mail, source inconnue, jour futur, taux hors bornes) : 422', function (array $surcharge) {
    $espace = $this->espace(true);
    $this->actingAs($this->compte($espace, 'owner'))
        ->postJson('/api/v1/envoi/reputation/constats', constatR17($surcharge))
        ->assertStatus(422);
})->with([
    [['ressource' => 'abuse@exemple.test']],
    [['ressource' => 'pas un domaine']],
    [['ressource_type' => 'ip', 'ressource' => 'envoi-1.example.invalid']],
    [['source' => 'yahoo']],
    [['reputation' => 'excellente']],
    [['jour' => '2999-01-01']],
    [['taux_plaintes' => 1.5]],
    [['erreurs_auth' => -1]],
]);

test('permissions : opérateur, lecteur et admin 403 sur l’écriture ; lecture réservée à owner et admin', function () {
    $espace = $this->espace(true);
    foreach (['operator', 'viewer', 'admin'] as $role) {
        $this->actingAs($this->compte($espace, $role))->postJson('/api/v1/envoi/reputation/constats', constatR17())->assertForbidden();
    }
    foreach (['operator', 'viewer'] as $role) {
        $this->actingAs($this->compte($espace, $role))->getJson('/api/v1/envoi/reputation')->assertForbidden();
    }
    $this->actingAs($this->compte($espace, 'admin'))->getJson('/api/v1/envoi/reputation')->assertOk();
    expect(DB::table('envoi_reputation_constats')->count())->toBe(0);
});

test('espace sans le produit envoi : 404', function () {
    $this->actingAs($this->compte($this->espace(false), 'owner'))->getJson('/api/v1/envoi/reputation')->assertNotFound();
});

test('rejeu identique : une seule ligne, une seule pause, même constat renvoyé (200)', function () {
    $espace = $this->espace(true);
    $owner = $this->compte($espace, 'owner');

    $premier = $this->actingAs($owner)->postJson('/api/v1/envoi/reputation/constats', constatR17())->assertCreated();
    $rejeu = $this->actingAs($owner)->postJson('/api/v1/envoi/reputation/constats', constatR17())->assertOk();

    expect($rejeu->json('id'))->toBe($premier->json('id'))
        ->and($rejeu->json('pause_ouverte'))->toBe(true) // le constat d'origine a ouvert la pause
        ->and(DB::table('envoi_reputation_constats')->count())->toBe(1)
        ->and(pausesR17($espace->id))->toHaveCount(1)
        ->and(DB::table('envoi_pauses')->count())->toBe(1);
});

test('Idempotency-Key : même clé même contenu = rejeu ; même clé autre contenu = 409', function () {
    $espace = $this->espace(true);
    $owner = $this->compte($espace, 'owner');
    $h = ['Idempotency-Key' => 'cle-de-test-0001'];

    $this->actingAs($owner)->postJson('/api/v1/envoi/reputation/constats', constatR17(['reputation' => 'bonne']), $h)->assertCreated();
    $this->actingAs($owner)->postJson('/api/v1/envoi/reputation/constats', constatR17(['reputation' => 'bonne']), $h)->assertOk();
    $this->actingAs($owner)->postJson('/api/v1/envoi/reputation/constats', constatR17(['reputation' => 'mauvaise']), $h)->assertStatus(409);

    expect(DB::table('envoi_reputation_constats')->count())->toBe(1)
        ->and(pausesR17($espace->id))->toBeEmpty();
});

test('lecture : constats les plus récents d’abord, conformes au contrat, isolés par espace', function () {
    $a = $this->espace(true);
    $b = $this->espace(true);
    $this->actingAs($this->compte($a, 'owner'))->postJson('/api/v1/envoi/reputation/constats', constatR17(['reputation' => 'bonne']))->assertCreated();
    $this->actingAs($this->compte($a, 'owner'))->postJson('/api/v1/envoi/reputation/constats', constatR17(['jour' => now()->subDay()->format('Y-m-d'), 'reputation' => 'moyenne']))->assertCreated();
    $this->actingAs($this->compte($b, 'owner'))->postJson('/api/v1/envoi/reputation/constats', constatR17(['source' => 'orange']))->assertCreated();

    $r = $this->actingAs($this->compte($a, 'admin'))->getJson('/api/v1/envoi/reputation')->assertOk();

    expect($r->json('data'))->toHaveCount(2);
    foreach ($r->json('data') as $c) {
        expect(ContratOpenApiEnvoi::ecarts('reputation.yaml', 'ConstatReputation', $c))->toBe([]);
    }
});

test('table en ajout seul : UPDATE et DELETE refusés par la base', function () {
    $espace = $this->espace(true);
    $this->actingAs($this->compte($espace, 'owner'))->postJson('/api/v1/envoi/reputation/constats', constatR17(['reputation' => 'bonne']))->assertCreated();

    expect(fn () => DB::table('envoi_reputation_constats')->update(['preuve' => 'modifiée à la main']))->toThrow(QueryException::class);
    expect(fn () => DB::table('envoi_reputation_constats')->delete())->toThrow(QueryException::class);
    expect(fn () => DB::statement('TRUNCATE envoi_reputation_constats'))->toThrow(QueryException::class);
});
