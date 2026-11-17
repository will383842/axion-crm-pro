<?php

namespace Tests\Feature\Envoi\Routes;

use App\Envoi\Boites\AjoutBoite;
use App\Envoi\Creation\ErreurCreation;
use App\Http\Middleware\AuditHashChainLogger;
use App\Socle\Alertes\CourrielTransactionnel;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\ContratOpenApiEnvoi;

/**
 * F3-B1 — ajout d'une boîte par l'écran (propriétaire seul, code de confirmation). Données fictives en `*.example.test`.
 * Les routes (HTTP) tournent sur la connexion de test (propriétaire) ; le service `AjoutBoite` tourne sur CHAQUE connexion
 * (propriétaire `pgsql_owner`, connexion à part et auto-commit : sur la connexion de test, la transaction du test bloquerait le nettoyage ; PUIS `pgsql_app`, RLS armée) avec des données committées par le propriétaire, nettoyées dans un `finally`.
 */
uses(BaseRoutesEnvoi::class)->group('envoi');

const BA_CLE = 'cle-de-test-f3-b1-seulement-0123456789abcdef';
const BA_MDP = 'mdp-fictif-f3b1';

beforeEach(function () {
    config(['envoi.cles_codes_campagne' => ['1' => BA_CLE], 'envoi.cle_codes_campagne_version' => 1]);
});

afterEach(function () {
    foreach (['pgsql_app', 'pgsql_owner'] as $c) {
        if (array_key_exists($c, DB::getConnections())) {
            DB::connection($c)->disconnect();
        }
    }
});

/** @return array<string, mixed> */
function baCorps(string $domaine, array $plus = []): array
{
    return array_merge(['adresse' => "contact@$domaine", 'hote' => '127.0.0.1', 'port' => 587, 'chiffrement' => 'tls', 'utilisateur' => 'contact'], $plus);
}

function baDomaine($t, string $wsId, string $nom): void
{
    WorkspaceContext::run($wsId, fn () => DB::table('envoi_domaines')->insert(['workspace_id' => $wsId, 'nom' => $nom, 'achete_le' => '2026-10-01']));
}

/** Rend le code lu dans le courriel simulé. */
function baCodeEnvoye(): string
{
    preg_match('/\b(\d{6})\b/', Mail::sent(CourrielTransactionnel::class)->last()->corps, $m);

    return $m[1];
}

function baNbBoites(string $ws): int
{
    return WorkspaceContext::run($ws, fn () => DB::table('envoi_boites')->where('workspace_id', $ws)->count());
}

// ───────────────────────── Routes (HTTP) ─────────────────────────

test('administrateur ⇒ 403 sur les deux routes ; viewer aussi ; rien n\'est écrit', function () {
    $ws = $this->espace(true);
    baDomaine($this, $ws->id, 'a.example.test');
    foreach (['admin', 'viewer'] as $role) {
        $this->actingAs($this->compte($ws, $role), 'sanctum');
        $this->postJson('/api/v1/envoi/boites/ajout/code', baCorps('a.example.test'))->assertForbidden();
        $this->postJson('/api/v1/envoi/boites', baCorps('a.example.test', ['secret' => BA_MDP, 'code' => '123456']))->assertForbidden();
    }
    expect(baNbBoites($ws->id))->toBe(0)->and(DB::table('envoi_boites_codes_ajout')->count())->toBe(0);
});

test('propriétaire sans code ⇒ 409 code_invalide ; forme fausse ⇒ 422 ; transport `log` ⇒ 503 sans code émis', function () {
    $ws = $this->espace(true);
    baDomaine($this, $ws->id, 'a.example.test');
    $this->actingAs($this->compte($ws, 'owner'), 'sanctum');
    $r = $this->postJson('/api/v1/envoi/boites', baCorps('a.example.test', ['secret' => BA_MDP, 'code' => '123456']))->assertStatus(409);
    expect($r->json('error'))->toBe('code_invalide');
    $this->postJson('/api/v1/envoi/boites', baCorps('a.example.test', ['secret' => BA_MDP, 'code' => 'abc']))->assertStatus(422);
    $this->postJson('/api/v1/envoi/boites/ajout/code', ['adresse' => 'pas-une-adresse'])->assertStatus(422);
    $this->postJson('/api/v1/envoi/boites/ajout/code', baCorps('a.example.test', ['chiffrement' => 'aucun']))->assertStatus(422);
    config(['mail.mailers.journal-test' => ['transport' => 'log'], 'mail.auth_mailer' => 'journal-test']);
    $r = $this->postJson('/api/v1/envoi/boites/ajout/code', baCorps('a.example.test'))->assertStatus(503);
    expect($r->json('error'))->toBe('transport_courriel_non_sur')->and(DB::table('envoi_boites_codes_ajout')->count())->toBe(0);
});

test('parcours complet : 202 puis 201, boîte en pause, réponse au contrat sans secret, secret nulle part en clair', function () {
    Mail::fake();
    Log::spy();
    $ws = $this->espace(true);
    baDomaine($this, $ws->id, 'a.example.test');
    $this->actingAs($this->compte($ws, 'owner'), 'sanctum');
    $corps = baCorps('a.example.test');
    $r = $this->postJson('/api/v1/envoi/boites/ajout/code', $corps)->assertStatus(202);
    expect(ContratOpenApiEnvoi::ecartsReponse('infrastructure.yaml', '/envoi/boites/ajout/code', 'post', 202, $r->json()))->toBe([]);
    $code = baCodeEnvoye();
    $complet = $corps + ['secret' => BA_MDP, 'code' => $code];
    $r = $this->postJson('/api/v1/envoi/boites', $complet)->assertStatus(201);
    expect(ContratOpenApiEnvoi::ecartsReponse('infrastructure.yaml', '/envoi/boites', 'post', 201, $r->json()))->toBe([])
        ->and(array_keys($r->json()))->toEqualCanonicalizing(['id', 'etat', 'pause_motif'])
        ->and($r->getContent())->not->toContain(BA_MDP)->not->toMatch('/secret|identifiants/i');
    $l = WorkspaceContext::run($ws->id, fn () => DB::table('envoi_boites')->where('id', $r->json('id'))->first());
    expect($l->etat)->toBe('en_pause')->and($l->pause_motif)->toBe('suspension_manuelle')->and($l->identifiants_chiffres)->not->toContain(BA_MDP);
    // Aucune ligne d'audit ne porte le secret, le code, ni l'empreinte du corps ; une ligne propre par appel, sans corps.
    $audit = DB::table('audit_logs')->get()->map(fn ($x) => json_encode($x))->implode("\n");
    expect($audit)->not->toContain(BA_MDP)->not->toContain($code)
        ->not->toContain(AuditHashChainLogger::empreinteDuCorps($complet))->not->toContain(AuditHashChainLogger::empreinteDuCorps($corps))
        ->toContain('api/v1/envoi/boites/ajout/code')->toContain('api/v1/envoi/boites');
    // Le secret n'est dans aucun journal applicatif.
    Log::shouldNotHaveReceived('error', fn ($m, $c = []) => str_contains($m . json_encode($c), BA_MDP));
    Log::shouldNotHaveReceived('warning', fn ($m, $c = []) => str_contains($m . json_encode($c), BA_MDP));
    expect($this->resolutions)->toBe(0);   // aucune connexion SMTP : ServeurEnvoi jamais résolu, ENVOI_REEL_ACTIF intact
});

test('le journal HTTP générique est coupé dès l\'entrée (secret masqué : rien de plus que la ligne du contrôleur)', function () {
    Mail::fake();
    $ws = $this->espace(true);
    baDomaine($this, $ws->id, 'a.example.test');
    $this->actingAs($this->compte($ws, 'owner'), 'sanctum');
    $avant = DB::table('audit_logs')->count();
    $this->postJson('/api/v1/envoi/boites/ajout/code', baCorps('a.example.test'))->assertStatus(202);
    $lignes = DB::table('audit_logs')->orderByDesc('id')->limit(DB::table('audit_logs')->count() - $avant)->get();
    expect($lignes)->toHaveCount(1)->and($lignes->first()->payload_hash)->toBe(hash('sha256', '[]'));
});

// ───────────────────────── Service, sur chaque connexion ─────────────────────────

dataset('connexions', ['propriétaire (pgsql_owner)' => ['pgsql_owner'], 'applicatif (pgsql_app)' => ['pgsql_app']]);

/** Espaces et comptes committés par le propriétaire ; $f reçoit [wsA, userA, wsB, userB]. Nettoyage dans un finally. */
function baScenario(string $conn, \Closure $f): void
{
    $ids = ['wsA' => (string) Str::uuid(), 'uA' => (string) Str::uuid(), 'wsB' => (string) Str::uuid(), 'uB' => (string) Str::uuid()];
    $avant = DB::getDefaultConnection();
    $owner = DB::connection('pgsql_owner');
    try {
        foreach (['A' => 'a', 'B' => 'b'] as $k => $x) {
            $owner->statement("INSERT INTO workspaces (id, name, slug, settings, created_at, updated_at) VALUES (?, 'BA', ?, '{}', now(), now())", [$ids["ws$k"], 'ba-' . substr($ids["ws$k"], 0, 8)]);
            $owner->statement("INSERT INTO users (id, email, name, password_hash, current_workspace_id, created_at, updated_at) VALUES (?, ?, 'Compte', 'x', ?, now(), now())", [$ids["u$k"], "ba-$x-" . substr($ids["u$k"], 0, 6) . '@example.test', $ids["ws$k"]]);
            $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $ids["ws$k"]]);
            $owner->statement("INSERT INTO envoi_domaines (workspace_id, nom, achete_le) VALUES (?, ?, '2026-10-01')", [$ids["ws$k"], "ba-$x.example.test"]);
        }
        $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
        DB::setDefaultConnection($conn);
        Mail::fake();
        config(['mail.auth_mailer' => 'array']);
        $f($ids['wsA'], $ids['uA'], $ids['wsB'], $ids['uB']);
    } finally {
        DB::setDefaultConnection($avant);
        foreach (['envoi_boites_codes_ajout', 'envoi_boites', 'envoi_domaines'] as $t) {
            $owner->transaction(function () use ($owner, $t, $ids): void {
                $owner->statement("ALTER TABLE {$t} DISABLE TRIGGER USER");
                $owner->statement("DELETE FROM {$t} WHERE workspace_id IN (?, ?)", [$ids['wsA'], $ids['wsB']]);
                $owner->statement("ALTER TABLE {$t} ENABLE TRIGGER USER");
            });
        }
        $owner->statement('DELETE FROM users WHERE id IN (?, ?)', [$ids['uA'], $ids['uB']]);
        $owner->statement('DELETE FROM workspaces WHERE id IN (?, ?)', [$ids['wsA'], $ids['wsB']]);
    }
}

/** @return array{expire_le: string} */
function baDemander(string $ws, string $u, array $corps): array
{
    return app(AjoutBoite::class)->demanderCode($ws, $u, 'owner@example.test', $corps);
}

function baRefus(\Closure $f): string
{
    try {
        $f();
    } catch (ErreurCreation $e) {
        return $e->erreur . ':' . $e->statut;
    }

    return 'aucun';
}

test('code juste ⇒ boîte en pause ; le même code une seconde fois ⇒ 409', function (string $conn) {
    baScenario($conn, function ($wsA, $uA) {
        $corps = baCorps('ba-a.example.test');
        baDemander($wsA, $uA, $corps);
        $code = baCodeEnvoye();
        $r = app(AjoutBoite::class)->creer($wsA, $uA, $corps, BA_MDP, $code);
        $l = WorkspaceContext::run($wsA, fn () => DB::table('envoi_boites')->where('workspace_id', $wsA)->where('id', $r['id'])->first());
        expect($r['etat'])->toBe('en_pause')->and($l->etat)->toBe('en_pause')->and($l->pause_motif)->toBe('suspension_manuelle');
        expect(baRefus(fn () => app(AjoutBoite::class)->creer($wsA, $uA, baCorps('ba-a.example.test', ['adresse' => 'autre@ba-a.example.test']), BA_MDP, $code)))->toBe('code_invalide:409');
        expect(baNbBoites($wsA))->toBe(1);
    });
})->with('connexions');

test('6e essai ⇒ 409 même avec le bon code (5 essais faux consomment le plafond)', function (string $conn) {
    baScenario($conn, function ($wsA, $uA) {
        $corps = baCorps('ba-a.example.test');
        baDemander($wsA, $uA, $corps);
        $code = baCodeEnvoye();
        $faux = $code === '000000' ? '000001' : '000000';
        for ($i = 0; $i < 5; $i++) {
            expect(baRefus(fn () => app(AjoutBoite::class)->creer($wsA, $uA, $corps, BA_MDP, $faux)))->toBe('code_invalide:409');
        }
        expect(baRefus(fn () => app(AjoutBoite::class)->creer($wsA, $uA, $corps, BA_MDP, $code)))->toBe('code_invalide:409')
            ->and(baNbBoites($wsA))->toBe(0);
    });
})->with('connexions');

test('code demandé pour l\'adresse A, création de l\'adresse B ⇒ 409 ; la demande A reste servie', function (string $conn) {
    baScenario($conn, function ($wsA, $uA) {
        $a = baCorps('ba-a.example.test');
        baDemander($wsA, $uA, $a);
        $code = baCodeEnvoye();
        $b = baCorps('ba-a.example.test', ['adresse' => 'autre@ba-a.example.test']);
        expect(baRefus(fn () => app(AjoutBoite::class)->creer($wsA, $uA, $b, BA_MDP, $code)))->toBe('code_invalide:409')
            ->and(baNbBoites($wsA))->toBe(0);
        // même adresse, autre hôte : autre demande, refusée aussi
        expect(baRefus(fn () => app(AjoutBoite::class)->creer($wsA, $uA, baCorps('ba-a.example.test', ['port' => 465]), BA_MDP, $code)))->toBe('code_invalide:409');
        app(AjoutBoite::class)->creer($wsA, $uA, $a, BA_MDP, $code);
        expect(baNbBoites($wsA))->toBe(1);
    });
})->with('connexions');

test('4e demande en 15 minutes ⇒ 429 (3 codes au plus)', function (string $conn) {
    baScenario($conn, function ($wsA, $uA) {
        $corps = baCorps('ba-a.example.test');
        for ($i = 0; $i < 3; $i++) {
            baDemander($wsA, $uA, $corps);
        }
        expect(baRefus(fn () => baDemander($wsA, $uA, $corps)))->toBe('code_trop_frequent:429');
    });
})->with('connexions');

test('domaine d\'un autre espace ⇒ 409 ; un code de B ne crée rien dans A ; A ne voit pas les codes de B', function (string $conn) {
    baScenario($conn, function ($wsA, $uA, $wsB, $uB) {
        expect(baRefus(fn () => baDemander($wsA, $uA, baCorps('ba-b.example.test'))))->toBe('domaine_inconnu:409');
        $corpsB = baCorps('ba-b.example.test');
        baDemander($wsB, $uB, $corpsB);
        $codeB = baCodeEnvoye();
        // Même corps, mais le domaine est inconnu de A, et le code appartient à B : refus, rien créé.
        expect(baRefus(fn () => app(AjoutBoite::class)->creer($wsA, $uA, $corpsB, BA_MDP, $codeB)))->toBe('code_invalide:409')
            ->and(baNbBoites($wsA))->toBe(0)->and(baNbBoites($wsB))->toBe(0);
        app(AjoutBoite::class)->creer($wsB, $uB, $corpsB, BA_MDP, $codeB);
        expect(baNbBoites($wsB))->toBe(1)->and(baNbBoites($wsA))->toBe(0);
    });
})->with('connexions');

test('adresse déjà présente ⇒ 409 boite_existante et le code n\'est pas consommé', function (string $conn) {
    baScenario($conn, function ($wsA, $uA) {
        $corps = baCorps('ba-a.example.test');
        baDemander($wsA, $uA, $corps);
        $code = baCodeEnvoye();
        app(AjoutBoite::class)->creer($wsA, $uA, $corps, BA_MDP, $code);
        baDemander($wsA, $uA, $corps);
        expect(baRefus(fn () => app(AjoutBoite::class)->creer($wsA, $uA, $corps, BA_MDP, baCodeEnvoye())))->toBe('boite_existante:409')
            ->and(baNbBoites($wsA))->toBe(1);
    });
})->with('connexions');
