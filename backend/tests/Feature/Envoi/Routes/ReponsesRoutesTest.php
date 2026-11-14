<?php

namespace Tests\Feature\Envoi\Routes;

use App\Envoi\Imap\ClasseRetour;
use App\Envoi\Imap\RecepteurRetoursReponses;
use App\Envoi\Imap\RetourDetecte;
use App\Envoi\Reponses\StockerReponse;
use App\Models\Workspace;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ContratOpenApiEnvoi;

/** R12 : réponses (stockage chiffré, lecture, gestes). Fixtures fictives (exemple.test). */
uses(BaseRoutesEnvoi::class)->group('envoi');

const R12_ADRESSE = 'personne@exemple.test';

/** Événement + réponse stockée ; renvoie [id réponse, id événement]. */
function r12Reponse(Workspace $ws, string $classe = 'reponse_humaine', string $statut = 'a_rattacher', string $texte = 'Merci de ne plus me contacter, STOP.'): array
{
    $cle = hash('sha256', Str::random(20));
    $evenement = WorkspaceContext::run($ws->id, fn () => DB::table('envoi_evenements')->insertGetId([
        'workspace_id' => $ws->id, 'boite' => 'b@exemple.test', 'uid_validity' => 1, 'uid' => random_int(1, 1_000_000),
        'empreinte_message' => $cle, 'classe' => $classe, 'statut' => 'a_rattacher',
        'empreinte_expediteur' => ListeSuppression::empreinte(R12_ADRESSE), 'recu_le' => now(),
    ]));
    app(StockerReponse::class)->enregistrer($ws->id, $cle, 'Objet de test', $texte, R12_ADRESSE, $classe);

    return [(int) WorkspaceContext::run($ws->id, fn () => DB::table('envoi_reponses')->where('evenement_id', $evenement)->value('id')), $evenement];
}

beforeEach(function () {
    $this->ws = $this->espace(true);
    $this->owner = $this->compte($this->ws, 'owner');
    $this->admin = $this->compte($this->ws, 'admin');
});

test('l’extrait, l’objet et l’adresse ne sont jamais en clair en base (lecture SQL brute)', function () {
    [$id] = r12Reponse($this->ws);
    $brut = (array) WorkspaceContext::run($this->ws->id, fn () => DB::table('envoi_reponses')->where('id', $id)->first());
    $tout = json_encode($brut);
    expect($tout)->not->toContain('STOP')->not->toContain('Objet de test')->not->toContain('exemple.test');
});

test('l’extrait est borné à 4 000 caractères et un second stockage du même message est sans effet', function () {
    [$id] = r12Reponse($this->ws, 'reponse_humaine', 'a_rattacher', str_repeat('é', 6000));
    $r = $this->actingAs($this->admin)->getJson("/api/v1/envoi/reponses/{$id}")->assertOk();
    expect(mb_strlen($r->json('reponse.extrait')))->toBe(4000);
    $cle = WorkspaceContext::run($this->ws->id, fn () => DB::table('envoi_evenements')->value('empreinte_message'));
    expect(app(StockerReponse::class)->enregistrer($this->ws->id, $cle, 'x', 'y', R12_ADRESSE, 'reponse_humaine'))->toBeFalse();
    expect(WorkspaceContext::run($this->ws->id, fn () => DB::table('envoi_reponses')->count()))->toBe(1);
});

test('seules les quatre classes de réponse sont stockées (jamais un rebond)', function () {
    $cle = hash('sha256', 'rebond');
    WorkspaceContext::run($this->ws->id, fn () => DB::table('envoi_evenements')->insert([
        'workspace_id' => $this->ws->id, 'boite' => 'b@exemple.test', 'uid_validity' => 1, 'uid' => 9, 'empreinte_message' => $cle,
        'classe' => 'rebond_dur', 'statut' => 'a_rattacher', 'empreinte_expediteur' => str_repeat('a', 64), 'recu_le' => now(),
    ]));
    expect(app(StockerReponse::class)->enregistrer($this->ws->id, $cle, 'x', 'y', R12_ADRESSE, 'rebond_dur'))->toBeFalse();
});

test('le maillon de la chaîne stocke la réponse d’un retour détecté', function () {
    $cle = hash('sha256', 'maillon');
    WorkspaceContext::run($this->ws->id, fn () => DB::table('envoi_evenements')->insert([
        'workspace_id' => $this->ws->id, 'boite' => 'b@exemple.test', 'uid_validity' => 1, 'uid' => 3, 'empreinte_message' => $cle,
        'classe' => 'reponse_humaine', 'statut' => 'a_rattacher', 'empreinte_expediteur' => str_repeat('b', 64), 'recu_le' => now(),
    ]));
    $retour = new RetourDetecte(ClasseRetour::ReponseHumaine, (string) Str::uuid(), R12_ADRESSE, null, $cle, 'Objet', 'Corps');
    app(RecepteurRetoursReponses::class)->recevoir($this->ws->id, $retour);
    expect(WorkspaceContext::run($this->ws->id, fn () => DB::table('envoi_reponses')->count()))->toBe(1);
});

test('lecture : files, contrat, permissions et produit', function () {
    r12Reponse($this->ws);
    $r = $this->actingAs($this->admin)->getJson('/api/v1/envoi/reponses?file=a_rattacher')->assertOk();
    expect($r->json('par_file.a_rattacher'))->toBe(1)->and($r->json('data'))->toHaveCount(1);
    expect(ContratOpenApiEnvoi::ecarts('commun.yaml', 'ReponseResume', $r->json('data.0')))->toBe([]);
    $this->actingAs($this->compte($this->ws, 'viewer'))->getJson('/api/v1/envoi/reponses')->assertForbidden();
    $this->actingAs($this->compte($this->espace(false), 'owner'))->getJson('/api/v1/envoi/reponses')->assertNotFound();
});

test('réponse d’un autre espace : 404 sur la lecture et sur chaque geste', function () {
    $autre = $this->espace(true);
    [$id] = r12Reponse($autre);
    $this->actingAs($this->owner)->getJson("/api/v1/envoi/reponses/{$id}")->assertNotFound();
    foreach (['classer' => ['classement' => 'stop'], 'rattacher' => ['type' => 'contact', 'reference' => (string) Str::uuid()],
        'creer-fiche' => ['prenom' => 'A', 'nom' => 'B', 'provenance' => 'reponse'], 'repondre' => ['corps' => 'x']] as $geste => $corps) {
        $this->actingAs($this->owner)->postJson("/api/v1/envoi/reponses/{$id}/{$geste}", $corps)->assertNotFound();
    }
});

test('stop humain rejoué deux fois : une seule opposition, effets idempotents ; données gardées', function () {
    [$id] = r12Reponse($this->ws);
    for ($i = 0; $i < 2; $i++) {
        $r = $this->actingAs($this->owner)->postJson("/api/v1/envoi/reponses/{$id}/classer", ['classement' => 'stop'])->assertOk();
        expect($r->json('classement'))->toBe('stop')->and($r->json('traitee'))->toBeTrue()->and($r->json('resume_crm.opposition'))->toBeTrue();
    }
    $n = WorkspaceContext::run($this->ws->id, fn () => DB::table('envoi_oppositions')->where('empreinte_adresse', ListeSuppression::empreinte(R12_ADRESSE))->count());
    expect($n)->toBe(1);
    expect(DB::table('email_suppressions')->where('email_hash', ListeSuppression::empreinte(R12_ADRESSE))->sum('occurrences'))->toBe(1);
    expect(WorkspaceContext::run($this->ws->id, fn () => DB::table('envoi_reponses')->count()))->toBe(1);
    expect(WorkspaceContext::run($this->ws->id, fn () => DB::table('envoi_reponses_classements')->where('auteur', 'humain')->count()))->toBe(1);
});

test('un classement du classificateur n’est jamais un stop sans confiance haute et ne ferme pas la réponse', function () {
    [$id] = r12Reponse($this->ws, 'desinscription');
    $r = $this->actingAs($this->admin)->getJson("/api/v1/envoi/reponses/{$id}")->assertOk();
    expect($r->json('reponse.traitee'))->toBeFalse();
    [$id2] = r12Reponse($this->ws, 'hostile');
    $r2 = $this->actingAs($this->admin)->getJson("/api/v1/envoi/reponses/{$id2}")->assertOk();
    expect($r2->json('reponse.suggestion.classement'))->not->toBe('stop');
    expect(WorkspaceContext::run($this->ws->id, fn () => DB::table('envoi_oppositions')->count()))->toBe(0);
});

test('classer : valeur inconnue 422, admin 403, classement non-stop sans opposition', function () {
    [$id] = r12Reponse($this->ws);
    $this->actingAs($this->owner)->postJson("/api/v1/envoi/reponses/{$id}/classer", ['classement' => 'n_importe_quoi'])->assertStatus(422);
    $this->actingAs($this->admin)->postJson("/api/v1/envoi/reponses/{$id}/classer", ['classement' => 'interesse'])->assertForbidden();
    $this->actingAs($this->owner)->postJson("/api/v1/envoi/reponses/{$id}/classer", ['classement' => 'interesse'])->assertOk()->assertJsonPath('classement', 'interesse');
    expect(WorkspaceContext::run($this->ws->id, fn () => DB::table('envoi_oppositions')->count()))->toBe(0);
});

test('rattacher écrit un rattachement décidé par l’utilisateur ; refusé si déjà rattaché', function () {
    [$id, $evt] = r12Reponse($this->ws);
    $injection = (string) Str::uuid();
    $this->actingAs($this->owner)->postJson("/api/v1/envoi/reponses/{$id}/rattacher", ['type' => 'contact', 'reference' => $injection])->assertStatus(422);
    $this->actingAs($this->owner)->postJson("/api/v1/envoi/reponses/{$id}/rattacher", ['type' => 'entreprise', 'reference' => '1'])->assertStatus(422);
    $boite = (string) Str::uuid();
    WorkspaceContext::run($this->ws->id, function () use ($boite, $injection) {
        DB::table('envoi_boites')->insert([
            'workspace_id' => $this->ws->id, 'id' => $boite, 'adresse' => 'zz@test.invalid', 'hote' => 'smtp.exemple.test',
            'port' => 587, 'chiffrement' => 'starttls', 'identifiants_chiffres' => 'zz',
        ]);
        DB::table('envoi_injections_smtp')->insert([
            'workspace_id' => $this->ws->id, 'boite_id' => $boite, 'id_injection' => $injection, 'cle_idempotence' => 'zz-r12',
        ]);
    });
    $this->actingAs($this->owner)->postJson("/api/v1/envoi/reponses/{$id}/rattacher", ['type' => 'contact', 'reference' => $injection])->assertOk();
    $ligne = WorkspaceContext::run($this->ws->id, fn () => DB::table('envoi_rattachements')->where('evenement_id', $evt)->first());
    expect($ligne->decide_par)->toBe('utilisateur:' . $this->owner->id)->and($ligne->id_injection)->toBe($injection);
    $this->actingAs($this->owner)->postJson("/api/v1/envoi/reponses/{$id}/rattacher", ['type' => 'contact', 'reference' => $injection])->assertStatus(409);
    expect(WorkspaceContext::run($this->ws->id, fn () => DB::table('envoi_rattachements')->count()))->toBe(1);
});

test('repondre : 409 envoi_ferme_palier_0, aucun effet, aucune résolution de ServeurEnvoi', function () {
    [$id] = r12Reponse($this->ws);
    $avant = WorkspaceContext::run($this->ws->id, fn () => DB::table('envoi_reponses_classements')->count());
    $this->actingAs($this->owner)->postJson("/api/v1/envoi/reponses/{$id}/repondre", ['corps' => 'Bonjour'])
        ->assertStatus(409)->assertJsonPath('error', 'envoi_ferme_palier_0');
    expect(WorkspaceContext::run($this->ws->id, fn () => DB::table('envoi_reponses_classements')->count()))->toBe($avant)->and($this->resolutions)->toBe(0);
});

test('creer-fiche : organisation inconnue 422 ; fiche existante renvoyée sans doublon', function () {
    [$id] = r12Reponse($this->ws);
    $corps = ['prenom' => 'Alex', 'nom' => 'Exemple', 'organisation' => 'Société Exemple', 'provenance' => 'reponse'];
    $this->actingAs($this->owner)->postJson("/api/v1/envoi/reponses/{$id}/creer-fiche", $corps)->assertStatus(422);
    $societe = WorkspaceContext::run($this->ws->id, fn () => DB::table('companies')->insertGetId([
        'workspace_id' => $this->ws->id, 'siren' => '123456782', 'denomination' => 'Société Exemple',
    ]));
    $this->actingAs($this->owner)->postJson("/api/v1/envoi/reponses/{$id}/creer-fiche", $corps)->assertOk();
    $this->actingAs($this->owner)->postJson("/api/v1/envoi/reponses/{$id}/creer-fiche", $corps)->assertOk();
    expect(WorkspaceContext::run($this->ws->id, fn () => DB::table('contacts')->where('company_id', $societe)->count()))->toBe(1);
});

test('raccourcis : liste vide au contrat', function () {
    $this->actingAs($this->admin)->getJson('/api/v1/envoi/reponses/raccourcis')->assertOk()->assertJsonStructure(['data', 'collegues']);
});

test('le journal ajout seul refuse UPDATE et DELETE', function () {
    [$id] = r12Reponse($this->ws);
    expect(fn () => WorkspaceContext::run($this->ws->id, fn () => DB::table('envoi_reponses')->where('id', $id)->delete()))->toThrow(QueryException::class);
});
