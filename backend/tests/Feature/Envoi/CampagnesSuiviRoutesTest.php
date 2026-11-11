<?php

use App\Envoi\Campagnes\DepotCampagnes;
use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Depot\DepotSocle;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ContratOpenApiEnvoi;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('envoi');

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
});

const SUIV_BASE = '/api/v1/envoi/campagnes';

/** Compte fictif (exemple.test) d'un rôle donné, dans l'espace passé (neuf par défaut, produit Envoi actif). */
function suivCompte(string $role, ?string $espace = null): User
{
    $espace ??= Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'r09-' . Str::random(8), 'name' => 'Espace R09', 'settings' => ['produits' => ['envoi' => 'actif']]])->id;
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => $role . '-' . Str::random(6) . '@exemple.test', 'name' => 'Compte Test',
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $espace, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($espace);
    $user->assignRole($role);

    return $user;
}

/** Crée une campagne dans le statut voulu par le CHEMIN LÉGAL (la séquence factice n'est posée que par le propriétaire de la base). */
function suivCampagne(string $ws, string $statut = 'brouillon', string $nom = 'Campagne fictive'): string
{
    $chemins = [
        'brouillon' => [], 'a_valider' => ['a_valider'], 'programmee' => ['a_valider', 'programmee'],
        'en_cours' => ['a_valider', 'programmee', 'en_cours'], 'en_pause' => ['a_valider', 'programmee', 'en_cours', 'en_pause'],
        'terminee' => ['a_valider', 'programmee', 'en_cours', 'terminee'],
    ];
    $id = (string) Str::uuid();
    DB::table('envoi_campagnes')->insert(['id' => $id, 'workspace_id' => $ws, 'nom' => $nom, 'cible' => json_encode(['type' => 'liste', 'reference' => 'liste-fictive', 'libelle' => 'Liste fictive']),
        'boites' => '{' . (string) Str::uuid() . '}', 'nb_etapes' => 2, 'saisie' => json_encode(['objet' => 'Bonjour']), 'cree_par' => 'responsable']);
    $v = 1;
    if ($chemins[$statut] !== []) {
        DB::table('envoi_campagnes')->where('id', $id)->update(['sequence_id' => (string) Str::uuid(), 'version' => ++$v]);
    }
    foreach ($chemins[$statut] as $etape) {
        DB::table('envoi_campagnes')->where('id', $id)->update(['statut' => $etape, 'version' => ++$v]);
    }

    return $id;
}

function suivEspace(User $u): string
{
    return (string) $u->current_workspace_id;
}

test('401 sans session, 403 pour operator et viewer, 404 sans le produit', function () {
    $this->getJson(SUIV_BASE)->assertUnauthorized();
    $this->postJson(SUIV_BASE . '/' . Str::uuid() . '/pause')->assertUnauthorized();
    foreach (['operator', 'viewer'] as $role) {
        $u = suivCompte($role);
        $this->actingAs($u)->getJson(SUIV_BASE)->assertForbidden();
        $this->actingAs($u)->postJson(SUIV_BASE . '/' . Str::uuid() . '/pause')->assertForbidden();
    }
    // Lecture : owner et admin ; écriture : owner seul.
    $admin = suivCompte('admin');
    $this->actingAs($admin)->getJson(SUIV_BASE)->assertOk();
    $this->actingAs($admin)->postJson(SUIV_BASE . '/' . Str::uuid() . '/pause')->assertForbidden();
    $sans = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'r09-' . Str::random(8), 'name' => 'Sans produit', 'settings' => []]);
    $this->actingAs(suivCompte('owner', $sans->id))->getJson(SUIV_BASE)->assertNotFound();
});

test('GET liste : contrat CampagneSuivi, filtre par statut, recherche, comptes par statut, isolation des espaces', function () {
    $u = suivCompte('owner');
    $ws = suivEspace($u);
    suivCampagne($ws, 'brouillon', 'Alpha');
    suivCampagne($ws, 'programmee', 'Bravo');
    $autre = suivCompte('owner');
    suivCampagne(suivEspace($autre), 'brouillon', 'Alpha étrangère');

    $r = $this->actingAs($u)->getJson(SUIV_BASE)->assertOk();
    expect($r->json('data'))->toHaveCount(2)->and($r->json('par_statut.brouillon'))->toBe(1)->and($r->json('par_statut.programmee'))->toBe(1);
    foreach ($r->json('data') as $c) {
        expect(ContratOpenApiEnvoi::ecarts('campagnes.yaml', 'CampagneSuivi', $c))->toBe([]);
        expect($c)->not->toHaveKey('ab_objet')->and($c['envoi_ferme'])->toBeTrue()->and($c['envoyes'])->toBe(0);
    }
    expect($this->actingAs($u)->getJson(SUIV_BASE . '?statut=programmee')->json('data.0.nom'))->toBe('Bravo');
    expect($this->actingAs($u)->getJson(SUIV_BASE . '?recherche=alph')->json('data'))->toHaveCount(1);
    $this->actingAs($u)->getJson(SUIV_BASE . '?statut=nimporte')->assertStatus(422);
});

test('GET calendrier : semaine en cours par défaut, plage invalide refusée, rien de prévu au palier 0', function () {
    $u = suivCompte('owner');
    suivCampagne(suivEspace($u), 'programmee');
    $r = $this->actingAs($u)->getJson(SUIV_BASE . '/calendrier')->assertOk();
    expect($r->json('data'))->toBe([])->and($r->json('du'))->toMatch('/^\d{4}-\d{2}-\d{2}$/')
        ->and(date('N', strtotime($r->json('du'))))->toBe('1')->and(date('N', strtotime($r->json('au'))))->toBe('7');
    $this->actingAs($u)->getJson(SUIV_BASE . '/calendrier?du=2026-12-10&au=2026-12-01')->assertStatus(422);
    $this->actingAs($u)->getJson(SUIV_BASE . '/calendrier?du=2026-01-01&au=2026-12-31')->assertStatus(422);
});

test('GET fiche : contrat, compteurs à 0, destinataires vides tant que sequence_id est nul, 404 hors espace', function () {
    $u = suivCompte('owner');
    $id = suivCampagne(suivEspace($u), 'brouillon');
    $r = $this->actingAs($u)->getJson(SUIV_BASE . "/{$id}")->assertOk();
    expect(ContratOpenApiEnvoi::ecarts('campagnes.yaml', 'CampagneSuivi', $r->json('campagne')))->toBe([]);
    expect($r->json('sequence'))->toBe([])->and($r->json('derniers_evenements'))->toBe([])->and($r->json('ab'))->toBeNull()
        ->and($r->json('compteurs.partis'))->toBe(0)->and($r->json('par_jour'))->toBe([])->and($r->json('par_boite'))->toBe([]);
    $d = $this->actingAs($u)->getJson(SUIV_BASE . "/{$id}/destinataires")->assertOk();
    expect($d->json('data'))->toBe([])->and($d->json('meta.total'))->toBe(0);
    $autre = suivCompte('owner');
    $this->actingAs($autre)->getJson(SUIV_BASE . "/{$id}")->assertNotFound();
    $this->actingAs($autre)->getJson(SUIV_BASE . "/{$id}/destinataires")->assertNotFound();
    $this->actingAs($autre)->postJson(SUIV_BASE . "/{$id}/pause")->assertNotFound();
    $this->actingAs($u)->getJson(SUIV_BASE . '/pas-un-uuid')->assertNotFound();
});

test('pause : en_cours devient en_pause ; double clic avec la même clé = un seul événement ; brouillon et programmée refusés (409)', function () {
    $u = suivCompte('owner');
    $ws = suivEspace($u);
    $id = suivCampagne($ws, 'en_cours');
    $cle = ['Idempotency-Key' => 'double-clic-0001'];
    $a = $this->actingAs($u)->postJson(SUIV_BASE . "/{$id}/pause", [], $cle)->assertOk();
    $b = $this->actingAs($u)->postJson(SUIV_BASE . "/{$id}/pause", [], $cle)->assertOk();
    expect(ContratOpenApiEnvoi::ecarts('campagnes.yaml', 'CampagneSuivi', $a->json()))->toBe([])
        ->and($a->json('statut'))->toBe('en_pause')->and($b->json('statut'))->toBe('en_pause');
    expect(DB::table('envoi_campagnes_journal')->where('campagne_id', $id)->where('transition', 'en_cours>en_pause')->count())->toBe(1);
    // Sans clé : la seconde pression se heurte à l'état (409), jamais deux événements.
    $this->actingAs($u)->postJson(SUIV_BASE . "/{$id}/pause")->assertStatus(409);
    foreach (['brouillon', 'programmee'] as $statut) {
        $autre = suivCampagne($ws, $statut);
        $this->actingAs($u)->postJson(SUIV_BASE . "/{$autre}/pause")->assertStatus(409)->assertJsonPath('error', 'transition_refusee');
    }
    // Une clé déjà prise par une AUTRE campagne est refusée.
    $c2 = suivCampagne($ws, 'en_cours');
    $this->actingAs($u)->postJson(SUIV_BASE . "/{$c2}/pause", [], $cle)->assertStatus(409);
    $this->actingAs($u)->postJson(SUIV_BASE . "/{$c2}/pause", [], ['Idempotency-Key' => 'x y'])->assertStatus(422);
});

test('reprise : 409 sous arrêt général, 409 sous pause automatique, 200 sinon', function () {
    $u = suivCompte('owner');
    $ws = suivEspace($u);
    $id = suivCampagne($ws, 'en_pause');
    $socle = app(DepotSocle::class);
    $socle->armerToutArreter($ws, 'test');
    $this->actingAs($u)->postJson(SUIV_BASE . "/{$id}/reprise")->assertStatus(409)->assertJsonPath('error', 'frein_arret_general');
    $socle->leverToutArreter($ws, 'test');
    app(DepotChauffe::class)->ouvrirPause($ws, 'plaintes', null, new DateTimeImmutable('now'), 24);
    $this->actingAs($u)->postJson(SUIV_BASE . "/{$id}/reprise")->assertStatus(409)->assertJsonPath('error', 'frein_pause_automatique');
    expect(DB::table('envoi_campagnes')->where('id', $id)->value('statut'))->toBe('en_pause');
    DB::table('envoi_pauses')->where('workspace_id', $ws)->update(['levee_le' => now(), 'levee_par' => 'test']);
    $this->actingAs($u)->postJson(SUIV_BASE . "/{$id}/reprise")->assertOk()->assertJsonPath('statut', 'en_cours');
});

test('terminer est irréversible : plus de pause, reprise ni nouvelle fin', function () {
    $u = suivCompte('owner');
    $id = suivCampagne(suivEspace($u), 'en_pause');
    $this->actingAs($u)->postJson(SUIV_BASE . "/{$id}/terminer")->assertOk()->assertJsonPath('statut', 'terminee');
    foreach (['pause', 'reprise', 'terminer', 'date'] as $geste) {
        $this->actingAs($u)->postJson(SUIV_BASE . "/{$id}/{$geste}", ['le' => now()->addDay()->toIso8601String()])->assertStatus(409);
    }
    expect(DB::table('envoi_campagnes')->where('id', $id)->value('statut'))->toBe('terminee');
});

test('dupliquer : crée un BROUILLON (jamais validé), sans séquence, version 1 ; garder_cible / garder_messages', function () {
    $u = suivCompte('owner');
    $ws = suivEspace($u);
    $source = suivCampagne($ws, 'en_cours');
    $r = $this->actingAs($u)->postJson(SUIV_BASE . "/{$source}/dupliquer", ['nom' => 'Copie'], ['Idempotency-Key' => 'dupli-0001'])->assertCreated();
    expect(ContratOpenApiEnvoi::ecarts('campagnes.yaml', 'CampagneSuivi', $r->json()))->toBe([])
        ->and($r->json('statut'))->toBe('brouillon')->and($r->json('version'))->toBe(1)->and($r->json('id'))->not->toBe($source)->and($r->json('cible.reference'))->toBe('liste-fictive');
    $ligne = DB::table('envoi_campagnes')->where('id', $r->json('id'))->first();
    expect($ligne->sequence_id)->toBeNull()->and(json_decode($ligne->saisie, true))->toBe(['objet' => 'Bonjour']);
    // Rejeu avec la même clé : la même copie, pas une seconde.
    $bis = $this->actingAs($u)->postJson(SUIV_BASE . "/{$source}/dupliquer", ['nom' => 'Copie'], ['Idempotency-Key' => 'dupli-0001'])->assertCreated();
    expect($bis->json('id'))->toBe($r->json('id'))->and(DB::table('envoi_campagnes')->where('nom', 'Copie')->count())->toBe(1);
    $sans = $this->actingAs($u)->postJson(SUIV_BASE . "/{$source}/dupliquer", ['nom' => 'Sans', 'garder_cible' => false, 'garder_messages' => false])->assertCreated();
    expect(json_decode((string) DB::table('envoi_campagnes')->where('id', $sans->json('id'))->value('saisie'), true))->toBe([])
        ->and($sans->json('cible.reference'))->not->toBe('liste-fictive');
    $this->actingAs($u)->postJson(SUIV_BASE . "/{$source}/dupliquer", [])->assertStatus(422);
    $this->actingAs($u)->postJson(SUIV_BASE . "/{$source}/dupliquer", ['nom' => str_repeat('x', 201)])->assertStatus(422);
    // Aucune copie n'est validée : le statut reste brouillon, rien dans le journal de transitions.
    expect(DB::table('envoi_campagnes_journal')->where('campagne_id', $r->json('id'))->count())->toBe(0);
});

test('date : refusée dans le passé et hors fenêtre, acceptée sur une campagne programmée, journalisée', function () {
    $u = suivCompte('owner');
    $ws = suivEspace($u);
    $id = suivCampagne($ws, 'programmee');
    $url = SUIV_BASE . "/{$id}/date";
    $this->actingAs($u)->postJson($url, ['le' => now()->subHour()->toIso8601String()])->assertStatus(422);
    $this->actingAs($u)->postJson($url, ['le' => now()->addDays(91)->toIso8601String()])->assertStatus(422);
    $this->actingAs($u)->postJson($url, ['le' => 'pas une date'])->assertStatus(422);
    $this->actingAs($u)->postJson($url, [])->assertStatus(422);
    $cible = now()->addDays(3)->startOfHour();
    $r = $this->actingAs($u)->postJson($url, ['le' => $cible->toIso8601String()])->assertOk();
    expect(ContratOpenApiEnvoi::ecarts('campagnes.yaml', 'CampagneSuivi', $r->json()))->toBe([])
        ->and(strtotime($r->json('prochain_envoi_le')))->toBe($cible->getTimestamp());
    expect(DB::table('envoi_journal_commandes')->where('workspace_id', $ws)->where('commande', 'campagne.date')->count())->toBe(1);
    $this->actingAs($u)->postJson(SUIV_BASE . '/' . suivCampagne($ws, 'brouillon') . '/date', ['le' => $cible->toIso8601String()])->assertStatus(409);
});

test('les gestes ne touchent ni sequence_id, ni les séquences, ni les inscriptions', function () {
    $u = suivCompte('owner');
    $ws = suivEspace($u);
    $id = suivCampagne($ws, 'en_cours');
    $avant = DB::table('envoi_campagnes')->where('id', $id)->value('sequence_id');
    $this->actingAs($u)->postJson(SUIV_BASE . "/{$id}/pause")->assertOk();
    $this->actingAs($u)->postJson(SUIV_BASE . "/{$id}/terminer")->assertOk();
    expect(DB::table('envoi_campagnes')->where('id', $id)->value('sequence_id'))->toBe($avant);
    expect(DB::table('envoi_sequences')->count())->toBe(0)->and(DB::table('envoi_inscriptions')->count())->toBe(0);
    foreach (['DepotCampagnes', 'GestesCampagne', 'LectureCampagnes'] as $classe) {
        $src = (string) file_get_contents(app_path("Envoi/Campagnes/{$classe}.php"));
        expect($src)->not->toContain('sequence_id\' =>')->and($src)->not->toContain('ServeurEnvoi');
    }
    expect(DepotCampagnes::TRANSITIONS['terminee'])->toBe([]);
});

test('course pause contre reprise : deux processus réels sous le verrou de l\'espace, la chaîne du journal reste cohérente', function () {
    $proprio = DB::connection('pgsql_owner');
    $ws = (string) Str::uuid();
    $camp = (string) Str::uuid();
    $proprio->table('workspaces')->insert(['id' => $ws, 'slug' => 'zz-r09-' . substr($ws, 0, 6), 'name' => 'Course R09', 'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $proprio->table('envoi_campagnes')->insert(['id' => $camp, 'workspace_id' => $ws, 'nom' => 'x', 'cible' => json_encode(['type' => 'liste', 'reference' => 'r', 'libelle' => 'l']), 'nb_etapes' => 1, 'cree_par' => 'r']);
    $v = 1;
    $proprio->table('envoi_campagnes')->where('id', $camp)->update(['sequence_id' => (string) Str::uuid(), 'version' => ++$v]);
    foreach (['a_valider', 'programmee', 'en_cours'] as $etape) {
        $proprio->table('envoi_campagnes')->where('id', $camp)->update(['statut' => $etape, 'version' => ++$v]);
    }
    try {
        $depart = microtime(true) + 4;
        $procs = [];
        foreach (['pause', 'reprise', 'pause', 'reprise'] as $i => $geste) {
            $procs[$i] = proc_open([PHP_BINARY, base_path('tests/Support/campagne-geste-concurrent.php'), $ws, $camp, $geste, sprintf('%.4F', $depart)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes[$i]);
        }
        foreach ($procs as $i => $p) {
            $sortie = stream_get_contents($tubes[$i][1]) . stream_get_contents($tubes[$i][2]);
            expect(proc_close($p))->toBeIn([0, 3], $sortie);   // 0 appliqué, 3 refusé proprement (409) ; jamais une erreur SQL
        }
        $journal = $proprio->table('envoi_campagnes_journal')->where('campagne_id', $camp)->orderBy('id')->pluck('transition')->all();
        $statut = $proprio->table('envoi_campagnes')->where('id', $camp)->value('statut');
        // Chaîne valide : alternance stricte pause / reprise à partir de « en_cours », et le statut final est le dernier arrivé.
        $attendu = 'en_cours';
        foreach ($journal as $t) {
            [$de, $vers] = explode('>', $t);
            expect($de)->toBe($attendu);
            $attendu = $vers;
        }
        expect($statut)->toBe($attendu)->and($proprio->table('envoi_campagnes')->where('id', $camp)->value('version'))->toBe($v + count($journal));
    } finally {
        $proprio->statement("SET session_replication_role = 'replica'");
        $proprio->table('envoi_campagnes_journal')->where('workspace_id', $ws)->delete();
        $proprio->table('envoi_campagnes')->where('workspace_id', $ws)->delete();
        $proprio->table('workspaces')->where('id', $ws)->delete();
        $proprio->statement("SET session_replication_role = 'origin'");
    }
});
