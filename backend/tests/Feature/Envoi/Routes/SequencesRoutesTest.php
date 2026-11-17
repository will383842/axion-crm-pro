<?php

use App\Envoi\Chauffe\DecisionFrein;
use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Depot\DepotSocleDb;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ContratOpenApiEnvoi;
use Tests\Support\LienCampagneSequence;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('envoi');

/**
 * T-SEQ (D6) : liste, fiche, pause et reprise d'une séquence. Comptes et données fictifs (exemple.test), aucun réseau.
 * `ContratOpenApiEnvoi::ecartsReponse` n'est pas utilisé pour les 4xx (le contrat S2-CONTRATS n'est pas encore fusionné) : statut seul.
 */
beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->resolutions = 0;
    $this->app->bind(ServeurEnvoi::class, function () {
        $this->resolutions++;
        throw new RuntimeException('Une route de séquence ne doit pas résoudre ServeurEnvoi.');
    });
});

const SEQR_BASE = '/api/v1/envoi/sequences';

function seqrCompte(string $role, ?string $espace = null): User
{
    $espace ??= Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'tseq-' . Str::random(8), 'name' => 'Espace T-SEQ', 'settings' => ['produits' => ['envoi' => 'actif']]])->id;
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => $role . '-' . Str::random(6) . '@exemple.test', 'name' => 'Compte Test',
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $espace, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($espace);
    $user->assignRole($role);

    return $user;
}

/** Une séquence de 2 étapes avec une inscription par statut donné ; retourne son identifiant. */
function seqrSequence(string $ws, array $statuts = ['active', 'terminee']): string
{
    $id = (string) Str::uuid();
    DB::table('envoi_sequences')->insert(['id' => $id, 'workspace_id' => $ws, 'nom' => 'Séquence fictive', 'fenetre_debut' => 9, 'fenetre_fin' => 17]);
    foreach ([1, 2] as $rang) {
        DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $ws, 'sequence_id' => $id, 'rang' => $rang, 'delai_jours_ouvres' => $rang - 1, 'sujet' => "Objet visible {$rang}", 'corps' => "Corps secret {$rang}"]);
    }
    foreach ($statuts as $i => $statut) {
        DB::table('envoi_inscriptions')->insert([
            'workspace_id' => $ws, 'sequence_id' => $id, 'contact_id' => 1000 + $i, 'entreprise_id' => 2000 + $i,
            'empreinte_adresse' => str_repeat('a', 63) . $i, 'statut' => $statut, 'motif_arret' => $statut === 'arretee' ? 'test' : null,
            'prochaine_echeance' => '2026-11-20 10:00:00+01', 'version_figee' => '{}',
        ]);
    }

    return $id;
}

function seqrGestes(string $sequence): int
{
    return DB::table('envoi_sequences_gestes')->where('sequence_id', $sequence)->count();
}

test('401 sans session ; 403 pour operator et viewer ; pause : admin ; reprise : propriétaire seul', function () {
    $this->getJson(SEQR_BASE)->assertUnauthorized();
    $this->postJson(SEQR_BASE . '/' . Str::uuid() . '/pause')->assertUnauthorized();
    foreach (['operator', 'viewer'] as $role) {
        $u = seqrCompte($role);
        $this->actingAs($u)->getJson(SEQR_BASE)->assertForbidden();
        $this->actingAs($u)->getJson(SEQR_BASE . '/' . Str::uuid())->assertForbidden();
        $this->actingAs($u)->postJson(SEQR_BASE . '/' . Str::uuid() . '/pause')->assertForbidden();
        $this->actingAs($u)->postJson(SEQR_BASE . '/' . Str::uuid() . '/reprise')->assertForbidden();
    }
    $admin = seqrCompte('admin');
    $sid = seqrSequence((string) $admin->current_workspace_id);
    $this->actingAs($admin)->getJson(SEQR_BASE)->assertOk();
    $this->actingAs($admin)->postJson(SEQR_BASE . "/{$sid}/pause")->assertOk();
    $this->actingAs($admin)->postJson(SEQR_BASE . "/{$sid}/reprise")->assertForbidden();
    expect($this->resolutions)->toBe(0);
});

test('liste et fiche : contrat, compteurs seulement (aucune personne, aucun corps), isolation des espaces', function () {
    $u = seqrCompte('owner');
    $ws = (string) $u->current_workspace_id;
    $sid = seqrSequence($ws, ['active', 'active', 'terminee', 'arretee']);
    $autre = seqrCompte('owner');
    seqrSequence((string) $autre->current_workspace_id);

    $r = $this->actingAs($u)->getJson(SEQR_BASE)->assertOk();
    expect($r->json('sequences'))->toHaveCount(1);
    $s = $r->json('sequences.0');
    expect(ContratOpenApiEnvoi::ecarts('sequences.yaml', 'SequenceResume', $s))->toBe([])
        ->and($s['id'])->toBe($sid)->and($s['statut'])->toBe('active')->and($s['motif_pause'])->toBeNull()->and($s['nb_etapes'])->toBe(2)
        ->and($s['inscriptions'])->toBe(['total' => 4, 'en_cours' => 2, 'terminees' => 1, 'arretees' => 1, 'reportees' => 0]);

    $d = $this->actingAs($u)->getJson(SEQR_BASE . "/{$sid}")->assertOk();
    expect(ContratOpenApiEnvoi::ecarts('sequences.yaml', 'SequenceDetail', $d->json()))->toBe([])
        ->and($d->json('etapes'))->toHaveCount(2)->and($d->json('peut_reprendre'))->toBeFalse();
    expect($d->getContent())->not->toContain('Corps secret')->and($d->getContent())->not->toContain('@')->and($d->getContent())->not->toContain('contact_id')
        ->and($r->getContent())->not->toContain('Corps secret')->and($d->json('etapes.0.objet'))->toBe('Objet visible 1');

    $this->actingAs($autre)->getJson(SEQR_BASE . "/{$sid}")->assertNotFound();
    $this->actingAs($autre)->postJson(SEQR_BASE . "/{$sid}/pause")->assertNotFound();
    $this->actingAs($autre)->postJson(SEQR_BASE . "/{$sid}/reprise")->assertNotFound();
    $this->actingAs($u)->getJson(SEQR_BASE . '/' . Str::uuid())->assertNotFound();
    expect(seqrGestes($sid))->toBe(0);
});

test('pause puis reprise : état = dernier geste, gestes tracés avec l\'auteur, rejeu sans nouvelle ligne', function () {
    $u = seqrCompte('owner');
    $sid = seqrSequence((string) $u->current_workspace_id);

    $p = $this->actingAs($u)->postJson(SEQR_BASE . "/{$sid}/pause")->assertOk();
    expect(ContratOpenApiEnvoi::ecarts('sequences.yaml', 'SequenceDetail', $p->json()))->toBe([])
        ->and($p->json('statut'))->toBe('en_pause')->and($p->json('motif_pause'))->toBe('manuelle')->and($p->json('peut_reprendre'))->toBeTrue();
    $this->actingAs($u)->postJson(SEQR_BASE . "/{$sid}/pause")->assertOk();
    expect(seqrGestes($sid))->toBe(1)->and(DB::table('envoi_sequences_gestes')->value('auteur'))->toBe((string) $u->id);

    $r = $this->actingAs($u)->postJson(SEQR_BASE . "/{$sid}/reprise")->assertOk();
    expect($r->json('statut'))->toBe('active')->and($r->json('motif_pause'))->toBeNull();
    $this->actingAs($u)->postJson(SEQR_BASE . "/{$sid}/reprise")->assertOk();
    expect(seqrGestes($sid))->toBe(2)
        ->and($this->actingAs($u)->getJson(SEQR_BASE . "/{$sid}")->json('statut'))->toBe('active');
    expect($this->resolutions)->toBe(0);
});

test('reprise : 409 sous arrêt général, sous pause automatique et sous frein ; permise sinon ; rien n\'est écrit au refus', function () {
    $u = seqrCompte('owner');
    $ws = (string) $u->current_workspace_id;
    $sid = seqrSequence($ws);
    $this->actingAs($u)->postJson(SEQR_BASE . "/{$sid}/pause")->assertOk();
    expect(seqrGestes($sid))->toBe(1);

    // Arrêt général.
    WorkspaceContext::run($ws, fn () => (new DepotSocleDb)->armerToutArreter($ws, 'test'));
    $r = $this->actingAs($u)->postJson(SEQR_BASE . "/{$sid}/reprise")->assertStatus(409);
    expect($r->json('error'))->toBe('frein_arret_general');
    $d = $this->actingAs($u)->getJson(SEQR_BASE . "/{$sid}");
    expect($d->json('statut'))->toBe('en_pause')->and($d->json('peut_reprendre'))->toBeFalse()->and($d->json('raison_refus_reprise'))->toBeString();
    WorkspaceContext::run($ws, fn () => (new DepotSocleDb)->leverToutArreter($ws, 'test'));

    // Pause automatique, puis frein (deux motifs différents ; une pause active suffit, jamais levée ici).
    foreach ([DecisionFrein::SANTE_SILENCIEUSE, DecisionFrein::REBONDS] as $motif) {
        WorkspaceContext::run($ws, fn () => (new DepotChauffe)->ouvrirPause($ws, $motif, null, new DateTimeImmutable('now')));
        $this->actingAs($u)->postJson(SEQR_BASE . "/{$sid}/reprise")->assertStatus(409)->assertJsonPath('error', 'frein_pause_automatique');
        WorkspaceContext::run($ws, function () use ($ws): void {
            foreach ((new DepotChauffe)->pausesActives($ws, new DateTimeImmutable('now')) as $p) {
                // Chemin légal de la commande locale `envoi:lever-pause` : le journal d'abord, dans la même transaction.
                DB::transaction(function () use ($ws, $p): void {
                    (new DepotSocleDb)->journaliser($ws, 'pause_levee', 'test', ['pause' => (int) $p->id]);
                    (new DepotChauffe)->leverPause($ws, (int) $p->id, 'test');
                });
            }
        });
    }
    expect(seqrGestes($sid))->toBe(1);

    // Plus aucun frein : la reprise passe.
    $this->actingAs($u)->postJson(SEQR_BASE . "/{$sid}/reprise")->assertOk()->assertJsonPath('statut', 'active');
    expect(seqrGestes($sid))->toBe(2)->and($this->resolutions)->toBe(0);
});

test('une séquence SANS étape n\'est ni listée ni lisible ni pausable (le contrat exige nb_etapes entre 1 et 3, jamais un 1 inventé)', function () {
    $u = seqrCompte('owner');
    $ws = (string) $u->current_workspace_id;
    $avecEtapes = seqrSequence($ws);
    $vide = (string) Str::uuid();
    DB::table('envoi_sequences')->insert(['id' => $vide, 'workspace_id' => $ws, 'nom' => 'Séquence vide fictive', 'fenetre_debut' => 9, 'fenetre_fin' => 17]);

    $ids = collect($this->actingAs($u)->getJson(SEQR_BASE)->assertOk()->json('sequences'))->pluck('id')->all();
    expect($ids)->toContain($avecEtapes)->not->toContain($vide);
    $this->actingAs($u)->getJson(SEQR_BASE . "/{$vide}")->assertNotFound();
    $this->actingAs($u)->postJson(SEQR_BASE . "/{$vide}/pause")->assertNotFound();
    expect(seqrGestes($vide))->toBe(0);
});

test('L5-C 6 reprise directe d\'une séquence née d\'une campagne : 409 pilotee_par_la_campagne, aucun geste ; pause directe permise ; séquence libre inchangée', function () {
    $u = seqrCompte('owner');
    $ws = (string) $u->current_workspace_id;
    $campagne = (string) Str::uuid();
    DB::table('envoi_campagnes')->insert(['id' => $campagne, 'workspace_id' => $ws, 'nom' => 'Campagne fictive', 'cible' => json_encode(['type' => 'liste', 'reference' => 'l', 'libelle' => 'l']), 'nb_etapes' => 2, 'cree_par' => 'le fondateur']);
    DB::table('envoi_campagnes')->where('id', $campagne)->update(['statut' => 'a_valider', 'version' => 2]);
    DB::table('envoi_campagnes')->where('id', $campagne)->update(['statut' => 'programmee', 'version' => 3]);
    $sid = LienCampagneSequence::poser(DB::connection(), $ws, $campagne, 3, 2)['sequence'];   // née en pause

    $avant = seqrGestes($sid);
    $r = $this->actingAs($u)->postJson(SEQR_BASE . "/{$sid}/reprise")->assertStatus(409)->assertJsonPath('error', 'pilotee_par_la_campagne');
    expect($r->json('message'))->toBe('Cette séquence appartient à une campagne : reprenez la campagne.')->and(seqrGestes($sid))->toBe($avant);

    // le stop gagne toujours : la pause directe reste permise (sur une séquence déjà en pause : aucun doublon, mais 200)
    $this->actingAs($u)->postJson(SEQR_BASE . "/{$sid}/pause")->assertOk();

    // le vrai danger : campagne en cours (préparation terminée, la base accepterait), séquence mise en pause à la main, puis reprise directe
    DB::table('envoi_campagnes_materialisations')->insert(['campagne_id' => $campagne, 'workspace_id' => $ws, 'sequence_id' => $sid]);
    DB::table('envoi_campagnes_materialisations')->where('campagne_id', $campagne)->update(['etat' => 'terminee', 'terminee_le' => now(), 'compteurs' => '{"inscrits":1,"membres":1,"retenus":1}']);
    DB::table('envoi_campagnes')->where('id', $campagne)->update(['statut' => 'en_cours', 'version' => 5]);
    $this->actingAs($u)->postJson(SEQR_BASE . "/{$sid}/pause")->assertOk();
    $avant = seqrGestes($sid);
    $this->actingAs($u)->postJson(SEQR_BASE . "/{$sid}/reprise")->assertStatus(409)->assertJsonPath('error', 'pilotee_par_la_campagne');
    expect(seqrGestes($sid))->toBe($avant);

    // séquence libre : reprise inchangée
    $libre = seqrSequence($ws);
    $this->actingAs($u)->postJson(SEQR_BASE . "/{$libre}/pause")->assertOk();
    $this->actingAs($u)->postJson(SEQR_BASE . "/{$libre}/reprise")->assertOk();
    expect($this->resolutions)->toBe(0);
});
