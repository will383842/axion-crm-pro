<?php

use App\Envoi\Campagnes\CompteursCampagne;
use App\Envoi\Campagnes\DepotCampagnes;
use App\Envoi\Campagnes\LectureCampagnes;
use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Depot\DepotSocle;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\ContratOpenApiEnvoi;
use Tests\Support\LienCampagneSequence;
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
    foreach ($chemins[$statut] as $etape) {
        if ($etape === 'en_cours') {   // L5-A : « en cours » exige une préparation terminée (chemin légal, propriétaire de la base)
            DB::table('envoi_campagnes_materialisations')->insert(['campagne_id' => $id, 'workspace_id' => $ws, 'sequence_id' => DB::table('envoi_campagnes')->where('id', $id)->value('sequence_id')]);
            DB::table('envoi_campagnes_materialisations')->where('campagne_id', $id)->update(['etat' => 'terminee', 'terminee_le' => now(), 'compteurs' => '{"inscrits":1,"membres":1,"retenus":1}']);
        }
        DB::table('envoi_campagnes')->where('id', $id)->update(['statut' => $etape, 'version' => ++$v]);
        if ($etape === 'programmee') {   // chemin légal (X1-L1) : séquence créée avec campagne_id, puis lien
            $v = LienCampagneSequence::poser(DB::connection(), $ws, $id, $v, 2)['version'];
        }
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
    foreach (app(DepotChauffe::class)->pausesActives($ws, new DateTimeImmutable('now')) as $pause) {
        Artisan::call('envoi:lever-pause', ['workspace' => $ws, 'pause' => $pause->id, '--auteur' => 'test']);   // chemin journalisé
    }
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
    $sequences = DB::table('envoi_sequences')->count();   // 1 : créée par le chemin légal (X1-L1) ; les gestes n'en créent aucune
    $this->actingAs($u)->postJson(SUIV_BASE . "/{$id}/pause")->assertOk();
    $this->actingAs($u)->postJson(SUIV_BASE . "/{$id}/terminer")->assertOk();
    expect(DB::table('envoi_campagnes')->where('id', $id)->value('sequence_id'))->toBe($avant);
    expect(DB::table('envoi_sequences')->count())->toBe($sequences)->and(DB::table('envoi_inscriptions')->count())->toBe(0);
    foreach (['DepotCampagnes', 'GestesCampagne', 'LectureCampagnes'] as $classe) {
        $src = (string) file_get_contents(app_path("Envoi/Campagnes/{$classe}.php"));
        // DepotCampagnes : une seule écriture, dans lierSequence (X1-L3), gardée précisément par CampagnesModeleDbTest
        expect(substr_count($src, 'sequence_id\' =>'))->toBe($classe === 'DepotCampagnes' ? 1 : 0)->and($src)->not->toContain('ServeurEnvoi');
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
    foreach (['a_valider', 'programmee', 'en_cours'] as $etape) {
        $proprio->table('envoi_campagnes')->where('id', $camp)->update(['statut' => $etape, 'version' => ++$v]);
        if ($etape === 'programmee') {   // chemin légal (X1-L1) : séquence créée avec campagne_id, puis lien
            $v = LienCampagneSequence::poser($proprio, $ws, $camp, $v, 1)['version'];
        }
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
        expect($journal)->not->toBeEmpty();
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
        $proprio->table('envoi_sequences_gestes')->where('workspace_id', $ws)->delete();   // X1-L1 : la séquence liée, ses étapes et ses gestes sont committés aussi
        $proprio->table('envoi_sequence_etapes')->where('workspace_id', $ws)->delete();
        $proprio->table('envoi_campagnes')->where('workspace_id', $ws)->delete();
        $proprio->table('envoi_sequences')->where('workspace_id', $ws)->delete();
        $proprio->table('workspaces')->where('id', $ws)->delete();
        $proprio->statement("SET session_replication_role = 'origin'");
    }
});

/*
 * Lot L6-B — compteurs réels dans la liste et la fiche (cahier L5-L6 § 7, PR #649). Ajouts seulement.
 * Joués sous le rôle applicatif (RLS) ET sous le propriétaire. Données fictives (exemple.test), aucune adresse.
 */
const CPB_MODES = ['app', 'proprio'];

function cpbSous(string $mode, Closure $f): mixed
{
    if ($mode === 'proprio') {
        return $f();
    }
    DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    try {
        return $f();
    } finally {
        DB::statement('RESET ROLE');
    }
}

/** Campagne « programmee » reliée à une séquence ; rend [campagne, séquence]. */
function cpbCampagne(string $ws): array
{
    $c = suivCampagne($ws, 'programmee');

    return [$c, (string) DB::table('envoi_campagnes')->where('id', $c)->value('sequence_id')];
}

function cpbInscrire(string $ws, string $seq, string $statut = 'active', ?string $motif = null): string
{
    static $n = 0;
    $n++;
    $id = (string) Str::uuid();
    DB::table('envoi_inscriptions')->insert(['id' => $id, 'workspace_id' => $ws, 'sequence_id' => $seq, 'contact_id' => $n, 'entreprise_id' => $n,
        'empreinte_adresse' => str_pad(dechex($n + 5000), 64, 'd', STR_PAD_LEFT), 'statut' => $statut, 'motif_arret' => $motif, 'prochaine_echeance' => now(), 'version_figee' => '{}']);

    return $id;
}

function cpbMessage(string $ws, string $ins, int $etape, string $statut): void
{
    DB::table('envoi_messages')->insert(['workspace_id' => $ws, 'inscription_id' => $ins, 'etape' => $etape, 'statut' => $statut, 'message_id' => 'm-' . Str::uuid() . '@example.test']);
}

function cpbTrace(string $ws, string $ins, string $evenement): void
{
    $i = DB::table('envoi_inscriptions')->where('id', $ins)->first();
    DB::table('envoi_inscriptions_evenements')->insert(['workspace_id' => $ws, 'inscription_id' => $ins, 'entreprise_id' => $i->entreprise_id, 'empreinte_adresse' => $i->empreinte_adresse, 'evenement' => $evenement]);
}

/** Préparation de la campagne : état, motif, compteurs (membres / retenus / inscrits). */
function cpbPreparation(string $ws, string $camp, string $etat, ?string $motif, int $inscrits, int $membres, int $retenus): void
{
    $seq = DB::table('envoi_campagnes')->where('id', $camp)->value('sequence_id');
    DB::table('envoi_campagnes_materialisations')->insert(['campagne_id' => $camp, 'workspace_id' => $ws, 'sequence_id' => $seq]);
    DB::table('envoi_campagnes_materialisations')->where('campagne_id', $camp)->update(['etat' => $etat, 'motif' => $motif, 'compteurs' => json_encode(['inscrits' => $inscrits, 'membres' => $membres, 'retenus' => $retenus]),
        'terminee_le' => $etat === 'terminee' ? now() : null]);
}

test('L6-B 1 fiche : compteurs réels = CompteursCampagne::pour, réponse conforme au contrat, clés historiques reliées', function () {
    foreach (CPB_MODES as $mode) {
        $u = suivCompte('owner');
        $ws = suivEspace($u);
        [$c, $s] = cpbCampagne($ws);
        cpbPreparation($ws, $c, 'terminee', null, 5, 8, 6);
        $i = array_map(fn () => cpbInscrire($ws, $s), range(1, 5));
        $stop = cpbInscrire($ws, $s, 'arretee', 'opposition');
        $camp = cpbInscrire($ws, $s, 'arretee', 'campagne_arretee');
        foreach ([0, 1, 2, 3] as $k) {
            cpbMessage($ws, $i[$k], 1, 'envoye');
        }
        cpbMessage($ws, $i[0], 2, 'envoye');
        cpbMessage($ws, $i[4], 1, 'refuse');
        cpbMessage($ws, $i[3], 2, 'reserve');
        cpbTrace($ws, $i[1], 'reponse');
        $attendu = (new CompteursCampagne)->pour($ws, $c);
        expect($attendu['inscrits'])->toBe(7)->and($attendu['envoyes'])->toBe(5)->and($attendu['touches'])->toBe(4);   // l'attendu n'est pas vide : la comparaison a un sens

        $r = cpbSous($mode, fn () => $this->actingAs($u)->getJson(SUIV_BASE . "/{$c}"))->assertOk();
        $k = $r->json('compteurs');
        foreach (['inscrits', 'envoyes', 'touches', 'reponses', 'rebonds_durs', 'rebonds_mous', 'oppositions', 'arretes_entreprise', 'arretes_campagne', 'exclus_preparation', 'issues', 'taux_refus', 'taux_desinscription'] as $cle) {
            expect($k[$cle])->toEqual($attendu[$cle], "$mode $cle");
        }
        expect($k['partis'])->toBe($attendu['touches'])->and($k['ont_repondu'])->toBe($attendu['reponses'])->and($k['desinscrits'])->toBe($attendu['oppositions'])
            ->and($k['exclus'])->toBe(2)->and($k['en_file'])->toBe($attendu['actives'])->and($k['refuses'])->toBe(1)->and($k['en_pause_absence'])->toBe(0)->and($k['limite_refus'])->toBe(0)
            ->and($k['oppositions'])->toBe(1)->and($k['arretes_campagne'])->toBe(1)->and($camp)->not->toBe($stop);
        expect($r->json('campagne.envoyes'))->toBe(5)->and($r->json('campagne.total'))->toBe(7)->and($r->json('campagne.reponses'))->toBe(1)
            ->and(ContratOpenApiEnvoi::ecartsReponse('campagnes.yaml', '/envoi/campagnes/{id}', 'get', 200, $r->json()))->toBe([]);
    }
});

test('L6-B 2 liste de 25 campagnes : envoyés, total, réponses exacts ; nombre de requêtes de la page constant (≤ 4)', function () {
    $vues = [];
    DB::listen(function ($q) use (&$vues) {   // un seul écouteur pour les deux modes (sinon chaque requête est comptée deux fois)
        $sql = strtoupper(trim($q->sql));
        if (! str_contains($sql, 'SET_CONFIG') && ! str_starts_with($sql, 'SET ') && ! str_starts_with($sql, 'RESET ')) {
            $vues[] = $q->sql;
        }
    });
    foreach (CPB_MODES as $mode) {
        $u = suivCompte('owner');
        $ws = suivEspace($u);
        $attendu = [];
        foreach (range(0, 24) as $k) {
            [$c, $s] = cpbCampagne($ws);
            $nb = 1 + ($k % 3);
            $attendu[$c] = ['envoyes' => 0, 'total' => $nb, 'reponses' => 0];
            foreach (range(1, $nb) as $j) {
                $ins = cpbInscrire($ws, $s);
                if ($j <= 1 + ($k % 2)) {
                    cpbMessage($ws, $ins, 1, 'envoye');
                    $attendu[$c]['envoyes']++;
                }
                if ($j === 1 && $k % 5 === 0) {
                    cpbTrace($ws, $ins, 'reponse');
                    $attendu[$c]['reponses']++;
                }
            }
        }
        expect(array_sum(array_column($attendu, 'envoyes')))->toBeGreaterThan(20);
        $r = cpbSous($mode, fn () => $this->actingAs($u)->getJson(SUIV_BASE . '?par_page=25'))->assertOk();
        expect($r->json('data'))->toHaveCount(25);
        foreach ($r->json('data') as $ligne) {
            expect(['envoyes' => $ligne['envoyes'], 'total' => $ligne['total'], 'reponses' => $ligne['reponses']])->toBe($attendu[$ligne['id']], "$mode {$ligne['id']}")
                ->and(ContratOpenApiEnvoi::ecarts('campagnes.yaml', 'CampagneSuivi', $ligne))->toBe([]);
        }
        $nombres = [];
        foreach ([3, 25] as $parPage) {
            $vues = [];
            $lues = cpbSous($mode, fn () => (new LectureCampagnes)->liste($ws, null, null, 1, $parPage));
            expect($lues['data'])->toHaveCount($parPage);
            $nombres[$parPage] = count($vues);
        }
        expect($nombres[3])->toBe($nombres[25], $mode)->and($nombres[25])->toBeLessThanOrEqual(4)
            ->and(implode(' ', $vues))->not->toMatch('/\b(INSERT|UPDATE|DELETE|TRUNCATE)\b/i');
    }
});

test('L6-B 3 préparation : en_cours avec progression, bloquee avec motif, terminee, null sans matérialisation', function () {
    foreach (CPB_MODES as $mode) {
        $u = suivCompte('owner');
        $ws = suivEspace($u);
        [$enCours] = cpbCampagne($ws);
        cpbPreparation($ws, $enCours, 'en_cours', null, 20, 30, 25);
        [$bloquee] = cpbCampagne($ws);
        cpbPreparation($ws, $bloquee, 'bloquee', 'jours_non_applicables', 4, 30, 30);
        [$terminee] = cpbCampagne($ws);
        cpbPreparation($ws, $terminee, 'terminee', null, 30, 30, 30);
        [$aucune] = cpbCampagne($ws);
        $voir = fn (string $id) => cpbSous($mode, fn () => $this->actingAs($u)->getJson(SUIV_BASE . "/{$id}"))->assertOk();
        expect($voir($enCours)->json('preparation'))->toBe(['etat' => 'en_cours', 'motif' => null, 'inscrits' => 20, 'membres' => 30]);
        expect($voir($bloquee)->json('preparation'))->toBe(['etat' => 'bloquee', 'motif' => 'jours_non_applicables', 'inscrits' => 4, 'membres' => 30]);
        $t = $voir($terminee);
        expect($t->json('preparation'))->toBe(['etat' => 'terminee', 'motif' => null, 'inscrits' => 30, 'membres' => 30])
            ->and(ContratOpenApiEnvoi::ecartsReponse('campagnes.yaml', '/envoi/campagnes/{id}', 'get', 200, $t->json()))->toBe([]);
        $a = $voir($aucune);
        expect($a->json())->toHaveKey('preparation')->and($a->json('preparation'))->toBeNull()
            ->and(ContratOpenApiEnvoi::ecartsReponse('campagnes.yaml', '/envoi/campagnes/{id}', 'get', 200, $a->json()))->toBe([]);
    }
});

test('L6-B 4 cloisonnement : campagne d\'un autre espace = 404 ; aucun compteur d\'un autre espace dans la liste ni la fiche', function () {
    foreach (CPB_MODES as $mode) {
        $a = suivCompte('owner');
        $b = suivCompte('owner');
        [$ca, $sa] = cpbCampagne(suivEspace($a));
        [$cb, $sb] = cpbCampagne(suivEspace($b));
        cpbMessage(suivEspace($a), cpbInscrire(suivEspace($a), $sa), 1, 'envoye');
        foreach (range(1, 6) as $_) {
            $i = cpbInscrire(suivEspace($b), $sb);
            cpbMessage(suivEspace($b), $i, 1, 'envoye');
            cpbTrace(suivEspace($b), $i, 'reponse');
        }
        cpbSous($mode, fn () => $this->actingAs($a)->getJson(SUIV_BASE . "/{$cb}"))->assertNotFound();
        $liste = cpbSous($mode, fn () => $this->actingAs($a)->getJson(SUIV_BASE))->assertOk();
        expect(array_column($liste->json('data'), 'id'))->toBe([$ca])
            ->and($liste->json('data.0.envoyes'))->toBe(1)->and($liste->json('data.0.total'))->toBe(1)->and($liste->json('data.0.reponses'))->toBe(0);
        $fiche = cpbSous($mode, fn () => $this->actingAs($a)->getJson(SUIV_BASE . "/{$ca}"))->assertOk();
        expect($fiche->json('compteurs.inscrits'))->toBe(1)->and($fiche->json('compteurs.reponses'))->toBe(0);
        // Côté B, ses propres compteurs sont bien là : le cloisonnement n'est pas un zéro partout.
        expect(cpbSous($mode, fn () => $this->actingAs($b)->getJson(SUIV_BASE . "/{$cb}"))->json('compteurs.reponses'))->toBe(6);
    }
});

test('L6-B 5 ouverts null et suivi_ouvertures faux partout ; une campagne sans séquence garde la forme à zéro ; les gestes rendent la forme', function () {
    $u = suivCompte('owner');
    $ws = suivEspace($u);
    [$c, $s] = cpbCampagne($ws);
    cpbMessage($ws, cpbInscrire($ws, $s), 1, 'envoye');
    $sans = suivCampagne($ws, 'brouillon');
    foreach ([$c, $sans] as $id) {
        $k = $this->actingAs($u)->getJson(SUIV_BASE . "/{$id}")->assertOk()->json('compteurs');
        expect($k)->toHaveKey('ouverts')->and($k['ouverts'])->toBeNull()->and($k['suivi_ouvertures'])->toBeFalse();
    }
    expect($this->actingAs($u)->getJson(SUIV_BASE . "/{$sans}")->json('compteurs.inscrits'))->toBe(0);
    $g = $this->actingAs($u)->postJson(SUIV_BASE . '/' . suivCampagne($ws, 'en_cours') . '/pause')->assertOk();
    expect($g->json('envoyes'))->toBe(0)->and($g->json('total'))->toBe(0)->and($g->json('reponses'))->toBe(0)
        ->and(ContratOpenApiEnvoi::ecarts('campagnes.yaml', 'CampagneSuivi', $g->json()))->toBe([]);
});

test('L6-B 6 contrat : le schéma de la fiche déclare les nouvelles clés et la préparation ; LectureCampagnes ne fait aucune écriture', function () {
    $y = Yaml::parseFile(base_path('openapi/envoi/campagnes.yaml'));
    $fiche = $y['paths']['/envoi/campagnes/{id}']['get']['responses']['200']['content']['application/json']['schema'];
    $compteurs = $fiche['properties']['compteurs'];
    foreach (['inscrits', 'envoyes', 'touches', 'ouverts', 'suivi_ouvertures', 'reponses', 'rebonds_durs', 'rebonds_mous', 'oppositions', 'arretes_campagne', 'exclus_preparation', 'issues'] as $cle) {
        expect($compteurs['properties'])->toHaveKey($cle)->and($compteurs['required'])->toContain($cle);
    }
    expect(array_keys($compteurs['properties']['issues']['properties']))->toBe(['opposition', 'rebond_dur', 'reponse', 'rebond_mou', 'arretee_autre', 'terminee', 'active'])
        ->and($fiche['properties'])->toHaveKey('preparation')->and($fiche['required'])->toContain('preparation');
    $src = (string) file_get_contents(base_path('app/Envoi/Campagnes/LectureCampagnes.php'));
    expect($src)->not->toContain('->insert(')->not->toContain('->update(')->not->toContain('->delete(');
});
