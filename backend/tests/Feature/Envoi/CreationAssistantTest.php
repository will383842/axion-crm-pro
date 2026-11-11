<?php

namespace Tests\Feature\Envoi;

use App\Envoi\Campagnes\DepotCampagnes;
use App\Envoi\Sequence\Sequenceur;
use App\Socle\Alertes\CourrielTransactionnel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Envoi\Routes\BaseRoutesEnvoi;
use Tests\Support\ContratOpenApiEnvoi;

/** R11 : assistant de création (brouillons, étapes, estimation, contrôles, code de validation). Fixtures exemple.test, aucun envoi réel. */
uses(BaseRoutesEnvoi::class)->group('envoi');

const CRE_CLE = 'cle-de-test-r11-seulement-0123456789abcdef';
const CRE = '/api/v1/envoi/brouillons';

beforeEach(function () {
    config(['envoi.cles_codes_campagne' => ['1' => CRE_CLE], 'envoi.cle_codes_campagne_version' => 1]);
    $this->ws = $this->espace(true);
    $this->owner = $this->compte($this->ws, 'owner');
});

/** Crée un brouillon par la route (prêt = cible non vide et boîte active, sans contrôle bloquant) ; rend [id, version]. */
function creBrouillon($t, $user = null, string $nom = 'Campagne de test', bool $pret = true): array
{
    $r = $t->actingAs($user ?? $t->owner)->postJson(CRE, ['nom' => $nom])->assertCreated();
    $id = $r->json('id');
    $v = (int) $r->json('version');
    if (! $pret) {
        return [$id, $v];
    }
    $ws = $t->ws->id;
    DB::statement('SET session_replication_role = replica');
    $audience = DB::table('email_audiences')->insertGetId(['workspace_id' => $ws, 'name' => 'Audience ' . Str::random(5), 'member_count' => 1]);
    DB::table('audience_members')->insert(['audience_id' => $audience, 'company_id' => 1, 'workspace_id' => $ws]);
    DB::statement('SET session_replication_role = origin');
    $boite = (string) Str::uuid();
    DB::table('envoi_boites')->insert(['id' => $boite, 'workspace_id' => $ws, 'adresse' => 'boite@exemple.test', 'hote' => 'smtp.exemple.test', 'port' => 587, 'identifiants_chiffres' => 'x', 'limite_jour' => 20]);
    $v = (int) creEtape($t, $id, $v, 1, ['cible' => ['type' => 'audience', 'reference' => (string) $audience, 'libelle' => 'Audience']])->assertOk()->json('version');
    $v = (int) creEtape($t, $id, $v, 3, ['boites' => [$boite], 'plafond_jour' => 10])->assertOk()->json('version');

    return [$id, $v];
}

function creEtape($t, string $id, int $v, int $etape, array $corps)
{
    return $t->actingAs($t->owner)->putJson(CRE . "/{$id}/etapes/{$etape}", $corps, ['If-Match' => (string) $v]);
}

/** Demande un code (courriel simulé) et rend le code à 6 chiffres lu dans le courriel. */
function creCode($t, string $id, $user = null): string
{
    Mail::fake();
    $t->actingAs($user ?? $t->owner)->postJson(CRE . "/{$id}/validation/code")->assertOk();
    $envoi = Mail::sent(CourrielTransactionnel::class)->last();
    preg_match('/\b(\d{6})\b/', $envoi->corps, $m);

    return $m[1];
}

test('401 sans session, 403 pour viewer, 403 pour admin sur écriture, 200 admin en lecture', function () {
    $this->postJson(CRE, ['nom' => 'x'])->assertUnauthorized();
    $admin = $this->compte($this->ws, 'admin');
    $viewer = $this->compte($this->ws, 'viewer');
    $this->actingAs($viewer)->getJson('/api/v1/envoi/creation/boites')->assertForbidden();
    $this->actingAs($admin)->getJson('/api/v1/envoi/creation/boites')->assertOk();
    $this->actingAs($admin)->postJson(CRE, ['nom' => 'x'])->assertForbidden();
    $this->actingAs($viewer)->postJson(CRE, ['nom' => 'x'])->assertForbidden();
});

test('POST brouillons : statut brouillon, version 1, sans séquence, réponse conforme au contrat', function () {
    $r = $this->actingAs($this->owner)->postJson(CRE, ['nom' => 'Ma campagne'])->assertCreated();
    expect(ContratOpenApiEnvoi::ecarts('creation.yaml', 'Brouillon', $r->json()))->toBe([]);
    $ligne = DB::table('envoi_campagnes')->where('id', $r->json('id'))->first();
    expect($ligne->statut)->toBe('brouillon')->and($ligne->sequence_id)->toBeNull()->and($ligne->cree_par)->toBe($this->owner->id)
        ->and($r->headers->get('ETag'))->toBe('"1"');
    $this->actingAs($this->owner)->postJson(CRE, ['nom' => ''])->assertStatus(422);
});

test('GET brouillon : conforme ; autre espace = 404', function () {
    [$id] = creBrouillon($this);
    $r = $this->actingAs($this->owner)->getJson(CRE . "/{$id}")->assertOk();
    expect(ContratOpenApiEnvoi::ecarts('creation.yaml', 'Brouillon', $r->json()))->toBe([]);
    $autre = $this->compte($this->espace(true), 'owner');
    $this->actingAs($autre)->getJson(CRE . "/{$id}")->assertNotFound();
});

test('PUT étape : If-Match obligatoire (428), version périmée 409, succès incrémente la version', function () {
    [$id, $v] = creBrouillon($this, null, 'x', false);
    $this->actingAs($this->owner)->putJson(CRE . "/{$id}/etapes/3", ['plafond_jour' => 20])->assertStatus(428);
    creEtape($this, $id, $v, 3, ['plafond_jour' => 20])->assertOk()->assertJsonPath('version', $v + 1)->assertHeader('ETag', '"' . ($v + 1) . '"');
    creEtape($this, $id, $v, 3, ['plafond_jour' => 25])->assertStatus(409);   // même version lue deux fois
    $r = $this->actingAs($this->owner)->getJson(CRE . "/{$id}")->assertOk();
    expect($r->json('contenu.plafond_jour'))->toBe(20)->and($r->json('etapes_faites'))->toBe([3]);
});

test('PUT étape 1 : cible sans adresse, type connu ; étape 2 : 3 messages au plus ; étape hors 1-5 refusée', function () {
    [$id, $v] = creBrouillon($this, null, 'x', false);
    creEtape($this, $id, $v, 1, ['cible' => ['type' => 'liste', 'reference' => 'a@exemple.test', 'libelle' => 'x']])->assertStatus(422);
    creEtape($this, $id, $v, 1, ['cible' => ['type' => 'inconnu', 'reference' => '1', 'libelle' => 'x']])->assertStatus(422);
    $quatre = array_map(fn ($i) => ['etape' => $i, 'message_id' => (string) Str::uuid(), 'delai_jours' => $i === 1 ? 0 : 3], [1, 2, 3, 4]);
    creEtape($this, $id, $v, 2, ['sequence' => $quatre])->assertStatus(422);
    creEtape($this, $id, $v, 6, [])->assertNotFound();
    $r = creEtape($this, $id, $v, 1, ['nom' => 'Renommée', 'cible' => ['type' => 'liste', 'reference' => '12', 'libelle' => 'Ma liste']])->assertOk();
    expect($r->json('nom'))->toBe('Renommée')->and(DB::table('envoi_campagnes')->where('id', $id)->value('cible'))->toContain('Ma liste');
});

test('PUT étape sur une campagne qui n’est plus un brouillon : 409', function () {
    [$id, $v] = creBrouillon($this);
    creCode($this, $id);
    creEtape($this, $id, $v + 1, 3, ['plafond_jour' => 5])->assertStatus(409);
});

test('estimation : comptage libellé « estimation », borné à la cible demandée ; 100 000 fiches sous la limite de temps', function () {
    [$id] = creBrouillon($this, null, 'x', false);
    $ws = $this->ws->id;
    DB::statement('SET session_replication_role = replica');
    $audience = DB::table('email_audiences')->insertGetId(['workspace_id' => $ws, 'name' => 'Audience R11', 'member_count' => 0]);
    DB::statement('INSERT INTO audience_members (audience_id, company_id, workspace_id) SELECT ?, g, ? FROM generate_series(1, 100000) g', [$audience, $ws]);
    DB::statement('SET session_replication_role = origin');
    $debut = microtime(true);
    $r = $this->actingAs($this->owner)->postJson(CRE . "/{$id}/cible/estimation", ['type' => 'audience', 'reference' => (string) $audience])->assertOk();
    expect(microtime(true) - $debut)->toBeLessThan(5.0)
        ->and($r->json('total'))->toBe(100000)->and($r->json('estimation'))->toBeTrue()
        ->and($r->json('restant'))->toBe($r->json('total') - $r->json('exclus'));
    $inconnue = $this->actingAs($this->owner)->postJson(CRE . "/{$id}/cible/estimation", ['type' => 'audience', 'reference' => '999999'])->assertOk();
    expect($inconnue->json('total'))->toBe(0);
});

test('contrôles : 7 contrôles dans l’ordre du contrat, boîtes absentes = bloquant, jamais « ok » sans preuve', function () {
    [$id] = creBrouillon($this, null, 'x', false);
    $r = $this->actingAs($this->owner)->getJson(CRE . "/{$id}/controles")->assertOk();
    expect($r->json('controles'))->toHaveCount(7);
    foreach ($r->json('controles') as $c) {
        expect(ContratOpenApiEnvoi::ecarts('creation.yaml', 'Controle', $c))->toBe([]);
    }
    $par = array_column($r->json('controles'), 'etat', 'code');
    expect($par['boites_pretes'])->toBe('bloquant')->and($par['cible_non_vide'])->toBe('bloquant')
        ->and($par['opposition_retiree'])->not->toBe('ok')->and($par['domaines_sains'])->not->toBe('ok')
        ->and($r->json('bloquants'))->toBe(count(array_filter($par, fn ($e) => $e === 'bloquant')));
});

test('apercu : rendu sans envoi ; test : 409 envoi_ferme_palier_0 sans aucun effet', function () {
    [$id] = creBrouillon($this);
    Mail::fake();
    $avant = DB::table('envoi_campagnes')->count();
    $this->actingAs($this->owner)->postJson(CRE . "/{$id}/test", ['etape' => 1])->assertStatus(409)->assertJsonPath('error', 'envoi_ferme_palier_0');
    Mail::assertNothingSent();
    expect(DB::table('envoi_campagnes')->count())->toBe($avant)->and($this->resolutions)->toBe(0);
    $this->actingAs($this->owner)->postJson(CRE . "/{$id}/apercu", ['etape' => 1])->assertStatus(501)->assertJsonPath('error', 'apercu_non_branche');
});

test('destinataires-exemples : 3 fiches au plus, champs du contrat seulement ; cibles et boîtes conformes', function () {
    $ws = $this->ws->id;
    DB::statement('SET session_replication_role = replica');
    foreach ([1, 2, 3, 4, 5] as $i) {
        DB::table('contacts')->insert(['id' => $i, 'workspace_id' => $ws, 'company_id' => 100 + $i, 'first_name' => "Prenom{$i}", 'last_name' => "Nom{$i}", 'email' => "p{$i}@exemple.test"]);
    }
    DB::statement('SET session_replication_role = origin');
    $r = $this->actingAs($this->owner)->getJson('/api/v1/envoi/creation/destinataires-exemples')->assertOk();
    expect($r->json('data'))->toHaveCount(3);
    foreach ($r->json('data') as $d) {
        expect(ContratOpenApiEnvoi::ecarts('creation.yaml', 'DestinataireExemple', $d))->toBe([])->and(array_keys($d))->toEqualCanonicalizing(['id', 'libelle', 'prenom', 'organisation']);
    }
    expect(json_encode($r->json()))->not->toContain('@');
    $this->actingAs($this->owner)->getJson('/api/v1/envoi/creation/cibles')->assertOk()->assertJsonStructure(['data']);
    $b = $this->actingAs($this->owner)->getJson('/api/v1/envoi/creation/boites')->assertOk();
    expect($b->json('data'))->toBe([]);
});

test('code : envoyé à l’adresse de connexion de l’auteur seulement, gardé en empreinte HMAC, 3 par 15 minutes', function () {
    [$id] = creBrouillon($this);
    $code = creCode($this, $id);
    Mail::assertSent(CourrielTransactionnel::class, fn ($m) => $m->hasTo($this->owner->email) && count($m->to) === 1);
    $ligne = DB::table('envoi_campagne_codes')->where('campagne_id', $id)->first();
    expect($ligne->empreinte)->toBe(DepotCampagnes::empreinteCode($code, 1))->and(json_encode($ligne))->not->toContain($code)
        ->and(DB::table('envoi_campagnes')->where('id', $id)->value('statut'))->toBe('a_valider');
    $this->actingAs($this->owner)->postJson(CRE . "/{$id}/validation/code")->assertOk();
    $this->actingAs($this->owner)->postJson(CRE . "/{$id}/validation/code")->assertOk();
    $this->actingAs($this->owner)->postJson(CRE . "/{$id}/validation/code")->assertStatus(429);
    // un autre propriétaire de l’espace n’est pas l’auteur : refus, aucun courriel
    $autre = $this->compte($this->ws, 'owner');
    Mail::fake();
    $this->actingAs($autre)->postJson(CRE . "/{$id}/validation/code")->assertForbidden();
    Mail::assertNothingSent();
});

test('valider : sans code 422, mauvais code puis 5 tentatives = verrou même avec le bon code', function () {
    [$id] = creBrouillon($this);
    $code = creCode($this, $id);
    $this->actingAs($this->owner)->postJson(CRE . "/{$id}/valider", [])->assertStatus(422);
    $faux = $code === '000000' ? '111111' : '000000';
    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($this->owner)->postJson(CRE . "/{$id}/valider", ['code' => $faux])->assertStatus(422);
    }
    $this->actingAs($this->owner)->postJson(CRE . "/{$id}/valider", ['code' => $code])->assertStatus(422);
    expect(DB::table('envoi_campagnes')->where('id', $id)->value('statut'))->toBe('a_valider');
});

test('valider : code expiré refusé (10 minutes)', function () {
    [$id] = creBrouillon($this);
    creCode($this, $id);
    app(DepotCampagnes::class)->emettreCode($this->ws->id, $id, '424242', -1);   // déjà expiré
    $this->actingAs($this->owner)->postJson(CRE . "/{$id}/valider", ['code' => '424242'])->assertStatus(422);
    expect(DB::table('envoi_campagnes')->where('id', $id)->value('statut'))->toBe('a_valider');
});

test('valider : bon code = programmée sans séquence ni inscription ; second usage du même code refusé', function () {
    [$id] = creBrouillon($this);
    $code = creCode($this, $id);
    $r = $this->actingAs($this->owner)->postJson(CRE . "/{$id}/valider", ['code' => $code])->assertOk();
    expect(ContratOpenApiEnvoi::ecarts('commun.yaml', 'CampagneResume', $r->json('campagne')))->toBe([]);
    $ligne = DB::table('envoi_campagnes')->where('id', $id)->first();
    expect($ligne->statut)->toBe('programmee')->and($ligne->sequence_id)->toBeNull()
        ->and(Carbon::parse($r->json('annulable_jusqua'))->diffInSeconds(now(), true))->toBeLessThanOrEqual(300)
        ->and(DB::table('envoi_sequences')->count())->toBe(0)->and(DB::table('envoi_inscriptions')->count())->toBe(0);
    $this->actingAs($this->owner)->postJson(CRE . "/{$id}/valider", ['code' => $code])->assertStatus(409);
    expect($this->resolutions)->toBe(0);
});

test('annuler-validation : dans les 5 minutes retour brouillon ; après 5 minutes 409', function () {
    [$id] = creBrouillon($this);
    $code = creCode($this, $id);
    $this->actingAs($this->owner)->postJson(CRE . "/{$id}/valider", ['code' => $code])->assertOk();
    $this->actingAs($this->owner)->postJson("/api/v1/envoi/campagnes/{$id}/annuler-validation")->assertOk()->assertJsonPath('id', $id);
    expect(DB::table('envoi_campagnes')->where('id', $id)->value('statut'))->toBe('brouillon');

    [$id2] = creBrouillon($this);
    $code2 = creCode($this, $id2);
    $this->actingAs($this->owner)->postJson(CRE . "/{$id2}/valider", ['code' => $code2])->assertOk();
    Carbon::setTestNow(now()->addMinutes(6));
    $this->actingAs($this->owner)->postJson("/api/v1/envoi/campagnes/{$id2}/annuler-validation")->assertStatus(409);
    Carbon::setTestNow();
    expect(DB::table('envoi_campagnes')->where('id', $id2)->value('statut'))->toBe('programmee');
});

test('aucune route de création n’appelle le Séquenceur ni le serveur d’envoi', function () {
    $this->app->bind(Sequenceur::class, fn () => throw new \RuntimeException('Sequenceur interdit'));
    [$id, $v] = creBrouillon($this);
    $code = creCode($this, $id);
    $this->actingAs($this->owner)->postJson(CRE . "/{$id}/valider", ['code' => $code])->assertOk();
    expect($this->resolutions)->toBe(0);
});

test('code et valider refusés (422) tant qu’un contrôle est bloquant', function () {
    [$id] = creBrouillon($this, null, 'x', false);
    $this->actingAs($this->owner)->postJson(CRE . "/{$id}/validation/code")->assertStatus(422)->assertJsonPath('error', 'controles_bloquants');
});
