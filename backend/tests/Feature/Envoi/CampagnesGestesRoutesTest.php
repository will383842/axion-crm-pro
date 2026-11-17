<?php

use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Depot\DepotSocle;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\ContratOpenApiEnvoi;
use Tests\Support\LienCampagneSequence;
use Tests\TestCase;

/*
 * Lot L5-C — routes « lancer » et « arrêter » d'une campagne (cahier docs/envoi/lots/L5-L6-cahier.md § 4, PR #649).
 * Chaque test de comportement est joué sous le rôle applicatif (RLS) ET sous le propriétaire. Données fictives (example.test).
 * Aucun envoi : le drapeau d'envoi réel reste faux ; le cahier interdit d'y toucher.
 */
uses(TestCase::class, RefreshDatabase::class)->group('envoi');

const CGR_BASE = '/api/v1/envoi/campagnes';
const CGR_MODES = ['app', 'proprio'];

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
});

function cgrCompte(string $role, ?string $espace = null): User
{
    $espace ??= Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'cgr-' . Str::random(8), 'name' => 'Espace L5-C', 'settings' => ['produits' => ['envoi' => 'actif']]])->id;
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => $role . '-' . Str::random(6) . '@societe.example.test', 'name' => 'Compte Test',
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $espace, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($espace);
    $user->assignRole($role);

    return $user;
}

/** Joue $f sous le mode : « app » = rôle applicatif non propriétaire, sous RLS. */
function cgrSous(string $mode, Closure $f): mixed
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

/**
 * Campagne « programmee » prête à lancer : séquence (née en pause), préparation terminée, $inscrits inscrits.
 * Options : sequence (false = aucune), mat (terminee|en_cours|bloquee|aucune), statut.
 */
function cgrCampagne(string $ws, array $o = []): string
{
    $o += ['sequence' => true, 'mat' => 'terminee', 'inscrits' => 1, 'statut' => 'programmee'];
    $id = (string) Str::uuid();
    DB::table('envoi_campagnes')->insert(['id' => $id, 'workspace_id' => $ws, 'nom' => 'Campagne fictive', 'cible' => json_encode(['type' => 'liste', 'reference' => 'liste-fictive', 'libelle' => 'Liste fictive']),
        'nb_etapes' => 2, 'saisie' => json_encode(['objet' => 'Bonjour']), 'cree_par' => 'le fondateur']);
    $v = 1;
    foreach ($o['statut'] === 'brouillon' ? [] : ['a_valider', 'programmee'] as $etape) {
        DB::table('envoi_campagnes')->where('id', $id)->update(['statut' => $etape, 'version' => ++$v]);
    }
    $sequence = $o['sequence'] && $o['statut'] !== 'brouillon' ? LienCampagneSequence::poser(DB::connection(), $ws, $id, $v, 2)['sequence'] : null;
    if ($o['mat'] !== 'aucune') {
        DB::table('envoi_campagnes_materialisations')->insert(['campagne_id' => $id, 'workspace_id' => $ws, 'sequence_id' => $sequence]);
        $compteurs = json_encode(['inscrits' => $o['inscrits'], 'membres' => 30, 'retenus' => $o['inscrits']]);
        if ($o['mat'] === 'terminee') {
            DB::table('envoi_campagnes_materialisations')->where('campagne_id', $id)->update(['etat' => 'terminee', 'terminee_le' => now(), 'compteurs' => $compteurs]);
        } elseif ($o['mat'] === 'bloquee') {
            DB::table('envoi_campagnes_materialisations')->where('campagne_id', $id)->update(['etat' => 'bloquee', 'motif' => 'jours_non_applicables', 'compteurs' => $compteurs]);
        } else {
            DB::table('envoi_campagnes_materialisations')->where('campagne_id', $id)->update(['compteurs' => $compteurs]);
        }
    }

    return $id;
}

/** Empreinte de ce qu'un geste peut écrire : statut, version, journal, gestes de séquence. */
function cgrEtat(string $id): array
{
    $c = DB::table('envoi_campagnes')->where('id', $id)->first();
    $gestes = $c->sequence_id === null ? 0 : DB::table('envoi_sequences_gestes')->where('sequence_id', $c->sequence_id)->count();

    return [$c->statut, $c->version, DB::table('envoi_campagnes_journal')->where('campagne_id', $id)->count(), $gestes];
}

test('L5-C 1 droits : 401 sans session ; 403 admin, operator, viewer ; 404 sans le produit ; 404 campagne d\'un autre espace (IDOR)', function () {
    foreach (['lancer', 'arreter'] as $geste) {
        $this->postJson(CGR_BASE . '/' . Str::uuid() . "/{$geste}")->assertUnauthorized();
    }
    foreach (['lancer', 'arreter'] as $geste) {
        foreach (['admin', 'operator', 'viewer'] as $role) {
            $this->actingAs(cgrCompte($role))->postJson(CGR_BASE . '/' . Str::uuid() . "/{$geste}")->assertForbidden();
        }
    }
    $sansProduit = cgrCompte('owner', Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'cgr-sp-' . Str::random(6), 'name' => 'Sans produit', 'settings' => []])->id);
    $this->actingAs($sansProduit)->postJson(CGR_BASE . '/' . Str::uuid() . '/lancer')->assertNotFound();
    $this->actingAs($sansProduit)->postJson(CGR_BASE . '/' . Str::uuid() . '/arreter')->assertNotFound();

    foreach (CGR_MODES as $mode) {
        $u = cgrCompte('owner');
        $autre = cgrCompte('owner');
        $id = cgrCampagne((string) $u->current_workspace_id);
        $avant = cgrEtat($id);
        foreach (['lancer', 'arreter'] as $geste) {
            $r = cgrSous($mode, fn () => $this->actingAs($autre)->postJson(CGR_BASE . "/{$id}/{$geste}"));
            $r->assertNotFound()->assertJsonPath('error', 'non_trouve');
        }
        expect(cgrEtat($id))->toBe($avant);
        cgrSous($mode, fn () => $this->actingAs($u)->postJson(CGR_BASE . '/pas-un-uuid/lancer'))->assertNotFound();
    }
});

test('L5-C 2 lancer : 200, en_cours, envoi_ferme, contrat CampagneSuivi, séquence relancée, rien ne part', function () {
    foreach (CGR_MODES as $mode) {
        $u = cgrCompte('owner');
        $ws = (string) $u->current_workspace_id;
        $id = cgrCampagne($ws);
        $r = cgrSous($mode, fn () => $this->actingAs($u)->postJson(CGR_BASE . "/{$id}/lancer", [], ['Idempotency-Key' => 'lancer-' . $mode . '-0001']))->assertOk();
        expect(ContratOpenApiEnvoi::ecarts('campagnes.yaml', 'CampagneSuivi', $r->json()))->toBe([])
            ->and($r->json('statut'))->toBe('en_cours')->and($r->json('envoi_ferme'))->toBeTrue();
        $seq = DB::table('envoi_campagnes')->where('id', $id)->value('sequence_id');
        expect(DB::table('envoi_sequences_gestes')->where('sequence_id', $seq)->orderByDesc('cree_le')->orderByDesc('id')->value('geste'))->toBe('reprise')
            ->and(DB::table('envoi_campagnes_journal')->where('campagne_id', $id)->where('transition', 'programmee>en_cours')->where('motif', 'lancer')->count())->toBe(1);
        // Échec fermé : le drapeau d'envoi réel est éteint ; le cycle ne fait rien partir.
        expect((bool) config('envoi.reel_actif'))->toBeFalse();
        Artisan::call('envoi:cycle');
        expect(DB::table('envoi_messages')->where('workspace_id', $ws)->count())->toBe(0);
    }
});

test('L5-C 3 lancer : un refus par code, bon statut HTTP, message français, rien d\'écrit', function () {
    foreach (CGR_MODES as $mode) {
        $u = cgrCompte('owner');
        $ws = (string) $u->current_workspace_id;
        $lancer = fn (string $id, array $h = []) => cgrSous($mode, fn () => $this->actingAs($u)->postJson(CGR_BASE . "/{$id}/lancer", [], $h));
        $refus = function (string $id, string $code, array $h = []) use ($lancer): void {
            $avant = cgrEtat($id);
            $r = $lancer($id, $h)->assertStatus(409)->assertJsonPath('error', $code);
            expect($r->json('message'))->toBeString()->not->toBe('')->and(cgrEtat($id))->toBe($avant);
        };
        $refus(cgrCampagne($ws, ['statut' => 'brouillon', 'sequence' => false, 'mat' => 'aucune']), 'transition_refusee');
        $refus(cgrCampagne($ws, ['sequence' => false, 'mat' => 'aucune']), 'sans_sequence');
        $refus(cgrCampagne($ws, ['mat' => 'aucune']), 'materialisation_en_cours');
        $refus(cgrCampagne($ws, ['mat' => 'en_cours', 'inscrits' => 20]), 'materialisation_en_cours');
        $refus(cgrCampagne($ws, ['mat' => 'bloquee']), 'materialisation_bloquee');
        $refus(cgrCampagne($ws, ['inscrits' => 0]), 'aucun_destinataire');   // tous les destinataires écartés (opposés compris) : on ne lance jamais une campagne vide

        $frein = cgrCampagne($ws);
        app(DepotSocle::class)->armerToutArreter($ws, 'test');
        $refus($frein, 'frein_arret_general');
        app(DepotSocle::class)->leverToutArreter($ws, 'test');
        app(DepotChauffe::class)->ouvrirPause($ws, 'plaintes', null, new DateTimeImmutable('now'), 24);
        $refus($frein, 'frein_pause_automatique');
        foreach (app(DepotChauffe::class)->pausesActives($ws, new DateTimeImmutable('now')) as $pause) {
            Artisan::call('envoi:lever-pause', ['workspace' => $ws, 'pause' => $pause->id, '--auteur' => 'test']);
        }

        // clé déjà prise par une AUTRE campagne
        $a = cgrCampagne($ws);
        $b = cgrCampagne($ws);
        $lancer($a, ['Idempotency-Key' => 'cle-partagee-01'])->assertOk();
        $refus($b, 'cle_reutilisee', ['Idempotency-Key' => 'cle-partagee-01']);

        // refus de la base converti : P0001 sur l'insertion du geste de séquence, tout est annulé
        $base = cgrCampagne($ws);
        $armee = new ArrayObject(['oui' => true]);
        DB::beforeExecuting(function (string $sql) use ($armee): void {
            if ($armee['oui'] && str_starts_with(ltrim($sql), 'INSERT INTO envoi_sequences_gestes')) {
                $armee['oui'] = false;
                $pdo = new PDOException('refus simulé');
                (new ReflectionProperty($pdo, 'code'))->setValue($pdo, 'P0001');
                throw new QueryException('pgsql', $sql, [], $pdo);
            }
        });
        $refus($base, 'reprise_refusee_base');

        // verrou occupé : lock_timeout (55P03) rendu en concurrence, jamais en 500
        $occupe = cgrCampagne($ws);
        $armee = new ArrayObject(['oui' => true]);
        DB::beforeExecuting(function (string $sql) use ($armee): void {
            if ($armee['oui'] && str_contains($sql, 'pg_advisory_xact_lock')) {
                $armee['oui'] = false;
                $pdo = new PDOException('verrou simulé');
                (new ReflectionProperty($pdo, 'code'))->setValue($pdo, '55P03');
                throw new QueryException('pgsql', $sql, [], $pdo);
            }
        });
        $refus($occupe, 'concurrence');

        // campagne modifiée entre la lecture et l'écriture : version périmée
        $perimee = cgrCampagne($ws);
        $armee = new ArrayObject(['oui' => true]);
        DB::beforeExecuting(function (string $sql) use ($armee, $perimee, $ws): void {
            if ($armee['oui'] && str_contains($sql, 'FROM envoi_campagnes_materialisations')) {
                $armee['oui'] = false;
                DB::table('envoi_campagnes')->where('id', $perimee)->where('workspace_id', $ws)->increment('version');
            }
        });
        $avant = cgrEtat($perimee);
        $lancer($perimee)->assertStatus(409)->assertJsonPath('error', 'version_perimee');
        expect(cgrEtat($perimee)[0])->toBe($avant[0]);

        $lancer(cgrCampagne($ws), ['Idempotency-Key' => 'x y'])->assertStatus(422)->assertJsonPath('error', 'validation');
    }
});

test('L5-C 4 arreter : 200, clôture sans effacer ; second appel sans clé 409 ; même clé deux fois = 200 deux fois, 1 journal ; clé mal formée 422', function () {
    foreach (CGR_MODES as $mode) {
        $u = cgrCompte('owner');
        $ws = (string) $u->current_workspace_id;
        $arreter = fn (string $id, array $h = []) => cgrSous($mode, fn () => $this->actingAs($u)->postJson(CGR_BASE . "/{$id}/arreter", [], $h));

        $id = cgrCampagne($ws);
        $r = $arreter($id, ['Idempotency-Key' => 'arret-' . $mode . '-0001'])->assertOk();
        expect(ContratOpenApiEnvoi::ecarts('campagnes.yaml', 'CampagneSuivi', $r->json()))->toBe([])->and($r->json('statut'))->toBe('arretee');
        $arreter($id, ['Idempotency-Key' => 'arret-' . $mode . '-0001'])->assertOk()->assertJsonPath('statut', 'arretee');
        expect(DB::table('envoi_campagnes_journal')->where('campagne_id', $id)->where('transition', 'programmee>arretee')->count())->toBe(1);
        $arreter($id)->assertStatus(409)->assertJsonPath('error', 'transition_refusee');
        $arreter(cgrCampagne($ws), ['Idempotency-Key' => 'arret-' . $mode . '-0001'])->assertStatus(409)->assertJsonPath('error', 'cle_reutilisee');
        $arreter(cgrCampagne($ws), ['Idempotency-Key' => 'x y'])->assertStatus(422)->assertJsonPath('error', 'validation');
        // sans séquence : refus clair, rien d'écrit
        $sans = cgrCampagne($ws, ['sequence' => false, 'mat' => 'aucune']);
        $avant = cgrEtat($sans);
        $arreter($sans)->assertStatus(409)->assertJsonPath('error', 'sans_sequence');
        expect(cgrEtat($sans))->toBe($avant);
    }
});

test('L5-C 4b arreter une campagne en cours : les inscriptions restent en base (aucune suppression), la séquence est en pause', function () {
    $u = cgrCompte('owner');
    $ws = (string) $u->current_workspace_id;
    $id = cgrCampagne($ws);
    $this->actingAs($u)->postJson(CGR_BASE . "/{$id}/lancer")->assertOk();
    $seq = (string) DB::table('envoi_campagnes')->where('id', $id)->value('sequence_id');
    DB::table('envoi_inscriptions')->insert(['workspace_id' => $ws, 'sequence_id' => $seq, 'contact_id' => 1, 'entreprise_id' => 1,
        'empreinte_adresse' => str_repeat('b', 64), 'statut' => 'active', 'prochaine_echeance' => '2026-12-01 10:00:00+01', 'version_figee' => '{}']);
    $this->actingAs($u)->postJson(CGR_BASE . "/{$id}/arreter")->assertOk()->assertJsonPath('statut', 'arretee');
    expect(DB::table('envoi_inscriptions')->where('sequence_id', $seq)->count())->toBe(1)
        ->and(DB::table('envoi_sequences_gestes')->where('sequence_id', $seq)->orderByDesc('cree_le')->orderByDesc('id')->value('geste'))->toBe('pause');
});

test('L5-C 5 routes : lancer et arreter sont des POST sous workspaces.manage, uuid exigé ; aucune URI « temoin », « armer », « reprendre »', function () {
    foreach (['lancer', 'arreter'] as $geste) {
        $trouvee = null;
        foreach (Route::getRoutes() as $r) {
            if ($r->uri() === "api/v1/envoi/campagnes/{id}/{$geste}") {
                $trouvee = $r;
            }
        }
        expect($trouvee)->not->toBeNull()->and($trouvee->methods())->toContain('POST')->and($trouvee->gatherMiddleware())->toContain('permission:workspaces.manage')
            ->and($trouvee->wheres)->toHaveKey('id');
    }
});
