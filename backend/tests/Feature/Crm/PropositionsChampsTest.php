<?php

/**
 * FILE DE PROPOSITIONS (N13, 03/10/2026) — une information venue d'un tiers
 * (`apporteur`, `commercial`, `societe` ; futur canal Axion Partners) qui
 * DIFFÈRE d'une valeur déjà présente n'écrase JAMAIS la fiche : elle devient
 * une proposition que le propriétaire (rôle owner) accepte ou refuse.
 *
 * Ce fichier prouve :
 *  - la table `propositions_champs` : RLS ENABLE + FORCE, `workspace_id`,
 *    vocabulaire des origines, rien n'y est jamais supprimé ;
 *  - la règle de `Propositions::proposer()` : vide non protégé → rempli ;
 *    identique → rien ; différent → proposition, l'existant intact ; champ
 *    protégé (déclaré par la personne, fiche protégée) → jamais rempli ;
 *  - accepter (valeur + `field_origins` + trace) et refuser (trace seule) ;
 *  - l'API : owner seul, 403 pour tout autre rôle ; le compteur du menu ;
 *  - aucune route publique nouvelle (rien n'est branché à Partners).
 *
 * Fixtures FICTIVES (dépôt public) : noms « ZZ », `.example.invalid`,
 * numéros en 01 99 (plage réservée aux fictions).
 */

use App\Crm\FichesProtegees;
use App\Crm\Propositions\PropositionDejaDecidee;
use App\Crm\Propositions\Propositions;
use App\Crm\Taxonomy;
use App\Models\User;
use App\Services\Audit\AuditHashChain;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['crm.console_v2' => true]);
    Cache::flush();
    $this->audits = [];
    $audits = &$this->audits;
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturnUsing(function (array $ligne) use (&$audits): int {
        $audits[] = $ligne;

        return count($audits);
    });
});

function pcService(): Propositions
{
    return app(Propositions::class);
}

/** @return array<string, string> */
function pcOrigines(string $table, int $id): array
{
    $brut = DB::table($table)->where('id', $id)->value('field_origins');
    $carte = json_decode(is_string($brut) ? $brut : '{}', true);

    return is_array($carte) ? $carte : [];
}

function pcCompte(string $ws, string $role): User
{
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'pc-' . $role . '-' . Str::random(6) . '@example.invalid', 'name' => 'ZZ ' . $role,
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $ws, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($ws);
    $user->assignRole($role);
    DB::table('user_workspaces')->insertOrIgnore([
        'user_id' => $user->id, 'workspace_id' => $ws, 'role_slug' => $role, 'invited_at' => now(), 'joined_at' => now(),
    ]);

    return $user;
}

// ── Schéma ──────────────────────────────────────────────────────────────────

test('la table est sous RLS forcée, porte workspace_id et n admet que les trois origines tiers', function () {
    $t = DB::selectOne("SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = 'public.propositions_champs'::regclass");
    expect($t->relrowsecurity)->toBeTrue()->and($t->relforcerowsecurity)->toBeTrue();

    $politique = DB::selectOne(
        "SELECT qual, with_check FROM pg_policies WHERE tablename = 'propositions_champs' AND policyname = 'propositions_champs_workspace_isolation'",
    );
    expect($politique)->not->toBeNull()
        ->and((string) $politique->qual)->toContain('app.current_workspace_id')
        ->and((string) $politique->qual)->not->toContain('IS NULL')
        ->and((string) $politique->with_check)->toContain('app.current_workspace_id');

    $colonne = DB::selectOne(
        "SELECT is_nullable FROM information_schema.columns WHERE table_name = 'propositions_champs' AND column_name = 'workspace_id'",
    );
    expect($colonne->is_nullable)->toBe('NO')
        ->and(Propositions::ORIGINES)->toBe(Taxonomy::FIELD_ORIGINS_TIERS)
        ->and(Propositions::STATUTS)->toBe(['en_attente', 'acceptee', 'refusee']);

    $ws = F::espace('zz-pc-check');
    $fiche = F::fiche($ws, 'ZZ Check');
    expect(fn () => DB::table('propositions_champs')->insert([
        'workspace_id' => $ws, 'entite' => 'entreprise', 'entite_id' => $fiche, 'champ' => 'phone',
        'valeur_proposee' => '0199000001', 'origine' => 'declared',
    ]))->toThrow(QueryException::class);
});

test('le rôle applicatif ne peut rien supprimer de la file (ni DELETE ni TRUNCATE)', function () {
    $role = (string) config('database.connections.pgsql_app.username', 'axion_app');
    $existe = DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$role]);
    expect($existe)->not->toBeNull();

    $droit = fn (string $p): bool => (bool) DB::selectOne(
        'SELECT has_table_privilege(?, ?, ?) AS ok',
        [$role, 'public.propositions_champs', $p],
    )->ok;
    expect($droit('SELECT'))->toBeTrue()
        ->and($droit('INSERT'))->toBeTrue()
        ->and($droit('UPDATE'))->toBeTrue()
        ->and($droit('DELETE'))->toBeFalse()
        ->and($droit('TRUNCATE'))->toBeFalse();
});

test('une proposition désigne une fiche du MÊME espace', function () {
    $a = F::espace('zz-pc-a');
    $b = F::espace('zz-pc-b');
    $ficheB = F::fiche($b, 'ZZ B');

    expect(fn () => DB::table('propositions_champs')->insert([
        'workspace_id' => $a, 'entite' => 'entreprise', 'entite_id' => $ficheB, 'champ' => 'phone',
        'valeur_proposee' => '0199000001', 'origine' => 'apporteur',
    ]))->toThrow(QueryException::class);
});

// ── La règle de proposer() ──────────────────────────────────────────────────

test('valeur DIFFÉRENTE : une proposition, la valeur existante reste intacte', function () {
    $ws = F::espace('zz-pc-diff');
    $fiche = F::fiche($ws, 'ZZ Diff', ['phone' => '0199000001', 'field_origins' => json_encode(['phone' => 'collected'])]);

    $r = pcService()->proposer($ws, 'entreprise', $fiche, 'phone', '0199000002', 'apporteur', 'zz-ref-1');

    expect($r)->toBe(Propositions::PROPOSEE)
        ->and(DB::table('companies')->where('id', $fiche)->value('phone'))->toBe('0199000001')
        ->and(pcOrigines('companies', $fiche))->toBe(['phone' => 'collected']);

    $p = DB::table('propositions_champs')->where('workspace_id', $ws)->sole();
    expect($p->entite)->toBe('entreprise')
        ->and((int) $p->entite_id)->toBe($fiche)
        ->and($p->champ)->toBe('phone')
        ->and($p->valeur_actuelle)->toBe('0199000001')
        ->and($p->valeur_proposee)->toBe('0199000002')
        ->and($p->origine)->toBe('apporteur')
        ->and($p->reference_externe)->toBe('zz-ref-1')
        ->and($p->statut)->toBe('en_attente')
        ->and($p->decidee_par)->toBeNull()
        ->and($p->decidee_le)->toBeNull();
});

test('la même proposition reçue deux fois n ouvre qu une ligne', function () {
    $ws = F::espace('zz-pc-rejeu');
    $fiche = F::fiche($ws, 'ZZ Rejeu', ['city' => 'Lyon']);

    expect(pcService()->proposer($ws, 'entreprise', $fiche, 'city', 'Villeurbanne', 'commercial'))->toBe(Propositions::PROPOSEE)
        ->and(pcService()->proposer($ws, 'entreprise', $fiche, 'city', 'Villeurbanne', 'societe'))->toBe(Propositions::DEJA_PROPOSEE)
        ->and(DB::table('propositions_champs')->where('workspace_id', $ws)->count())->toBe(1);
});

test('valeur IDENTIQUE : rien ne bouge', function () {
    $ws = F::espace('zz-pc-ident');
    $fiche = F::fiche($ws, 'ZZ Ident', ['city' => 'Lyon']);

    expect(pcService()->proposer($ws, 'entreprise', $fiche, 'city', '  Lyon ', 'apporteur'))->toBe(Propositions::IDENTIQUE)
        ->and(DB::table('propositions_champs')->count())->toBe(0)
        ->and(pcOrigines('companies', $fiche))->toBe([]);
});

test('valeur VIDE sur une fiche ordinaire : remplissage direct, origine tiers notée', function () {
    $ws = F::espace('zz-pc-vide');
    $fiche = F::fiche($ws, 'ZZ Vide');
    $c = F::contact($ws, $fiche, 'Zoe', 'ZZVIDE');

    expect(pcService()->proposer($ws, 'entreprise', $fiche, 'phone', '0199000003', 'societe'))->toBe(Propositions::REMPLI)
        ->and(DB::table('companies')->where('id', $fiche)->value('phone'))->toBe('0199000003')
        ->and(pcOrigines('companies', $fiche))->toBe(['phone' => 'societe'])
        ->and(pcService()->proposer($ws, 'personne', $c, 'role', 'Gérante', 'apporteur'))->toBe(Propositions::REMPLI)
        ->and(DB::table('contacts')->where('id', $c)->value('role'))->toBe('Gérante')
        ->and(pcOrigines('contacts', $c))->toBe(['role' => 'apporteur'])
        ->and(DB::table('propositions_champs')->count())->toBe(0);
});

test('une information vide venue du tiers n efface jamais rien', function () {
    $ws = F::espace('zz-pc-rien');
    $fiche = F::fiche($ws, 'ZZ Rien', ['city' => 'Lyon']);

    expect(pcService()->proposer($ws, 'entreprise', $fiche, 'city', '   ', 'apporteur'))->toBe(Propositions::IGNOREE)
        ->and(pcService()->proposer($ws, 'entreprise', $fiche, 'city', null, 'apporteur'))->toBe(Propositions::IGNOREE)
        ->and(DB::table('companies')->where('id', $fiche)->value('city'))->toBe('Lyon')
        ->and(DB::table('propositions_champs')->count())->toBe(0);
});

test('champ PROTÉGÉ (déclaré par la personne) : jamais rempli, même vide — il devient une proposition', function () {
    $ws = F::espace('zz-pc-decl');
    $fiche = F::fiche($ws, 'ZZ Declare');
    $c = F::contact($ws, $fiche, 'Zoe', 'ZZDECL', ['field_origins' => json_encode(['phone' => 'declared'])]);

    expect(pcService()->proposer($ws, 'personne', $c, 'phone', '0199000004', 'commercial'))->toBe(Propositions::PROPOSEE)
        ->and(DB::table('contacts')->where('id', $c)->value('phone'))->toBeNull()
        ->and(pcOrigines('contacts', $c))->toBe(['phone' => 'declared']);
});

test('fiche PROTÉGÉE : ni l entreprise ni ses personnes ne sont jamais remplies directement', function () {
    $ws = F::espace('zz-pc-prot');
    $fiche = F::fiche($ws, 'ZZ Protegee');
    F::proteger($ws, $fiche, FichesProtegees::TAG_FEDERATIONS);
    $c = F::contact($ws, $fiche, 'Zoe', 'ZZPROT');

    expect(pcService()->proposer($ws, 'entreprise', $fiche, 'phone', '0199000005', 'societe'))->toBe(Propositions::PROPOSEE)
        ->and(pcService()->proposer($ws, 'personne', $c, 'role', 'Présidente', 'apporteur'))->toBe(Propositions::PROPOSEE)
        ->and(DB::table('companies')->where('id', $fiche)->value('phone'))->toBeNull()
        ->and(DB::table('contacts')->where('id', $c)->value('role'))->toBeNull()
        ->and(pcOrigines('companies', $fiche))->toBe([])
        ->and(DB::table('propositions_champs')->where('workspace_id', $ws)->count())->toBe(2);
});

test('champ hors liste, origine inconnue ou fiche absente : refus franc', function () {
    $ws = F::espace('zz-pc-refus');
    $fiche = F::fiche($ws, 'ZZ Refus');

    expect(fn () => pcService()->proposer($ws, 'entreprise', $fiche, 'siren', '940000001', 'apporteur'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => pcService()->proposer($ws, 'entreprise', $fiche, 'phone', '0199000001', 'declared'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => pcService()->proposer($ws, 'devis', $fiche, 'phone', '0199000001', 'apporteur'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => pcService()->proposer($ws, 'entreprise', $fiche + 999999, 'phone', '0199000001', 'apporteur'))->toThrow(InvalidArgumentException::class);
});

// ── Accepter / refuser ──────────────────────────────────────────────────────

test('ACCEPTER : la valeur est écrite, field_origins prend l origine tiers, la décision est tracée', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $ws = F::espace('zz-pc-acc');
    $owner = pcCompte($ws, 'owner');
    $fiche = F::fiche($ws, 'ZZ Accepter', ['phone' => '0199000001', 'field_origins' => json_encode(['phone' => 'collected', 'city' => 'declared'])]);
    pcService()->proposer($ws, 'entreprise', $fiche, 'phone', '0199000002', 'apporteur');
    $id = (int) DB::table('propositions_champs')->where('workspace_id', $ws)->value('id');

    pcService()->accepter($ws, $id, $owner);

    $p = DB::table('propositions_champs')->where('id', $id)->first();
    expect(DB::table('companies')->where('id', $fiche)->value('phone'))->toBe('0199000002')
        ->and(pcOrigines('companies', $fiche))->toEqual(['phone' => 'apporteur', 'city' => 'declared'])
        ->and($p->statut)->toBe('acceptee')
        ->and($p->decidee_par)->toBe($owner->id)
        ->and($p->decidee_le)->not->toBeNull()
        ->and($this->audits)->toHaveCount(1)
        ->and($this->audits[0]['method'])->toBe('proposition.acceptee')
        // La trace ne recopie AUCUNE valeur (ni l'ancienne ni la nouvelle).
        ->and(json_encode($this->audits[0]))->not->toContain('0199000001')
        ->and(json_encode($this->audits[0]))->not->toContain('0199000002');

    // Une décision prise ne se reprend pas.
    expect(fn () => pcService()->refuser($ws, $id, $owner))->toThrow(PropositionDejaDecidee::class);
});

test('REFUSER : la fiche reste intacte, seule la décision est tracée', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $ws = F::espace('zz-pc-ref');
    $owner = pcCompte($ws, 'owner');
    $fiche = F::fiche($ws, 'ZZ Refuser', ['city' => 'Lyon']);
    pcService()->proposer($ws, 'entreprise', $fiche, 'city', 'Bron', 'societe');
    $id = (int) DB::table('propositions_champs')->where('workspace_id', $ws)->value('id');

    pcService()->refuser($ws, $id, $owner);

    expect(DB::table('companies')->where('id', $fiche)->value('city'))->toBe('Lyon')
        ->and(pcOrigines('companies', $fiche))->toBe([])
        ->and(DB::table('propositions_champs')->where('id', $id)->value('statut'))->toBe('refusee')
        ->and(DB::table('propositions_champs')->where('id', $id)->value('decidee_par'))->toBe($owner->id)
        ->and($this->audits)->toHaveCount(1)
        ->and($this->audits[0]['method'])->toBe('proposition.refusee');

    expect(fn () => pcService()->accepter($ws, $id, $owner))->toThrow(PropositionDejaDecidee::class);
});

test('une proposition décidée ne se réécrit plus en base', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $ws = F::espace('zz-pc-fige');
    $owner = pcCompte($ws, 'owner');
    $fiche = F::fiche($ws, 'ZZ Fige', ['city' => 'Lyon']);
    pcService()->proposer($ws, 'entreprise', $fiche, 'city', 'Bron', 'societe');
    $id = (int) DB::table('propositions_champs')->where('workspace_id', $ws)->value('id');
    pcService()->refuser($ws, $id, $owner);

    expect(fn () => DB::table('propositions_champs')->where('id', $id)->update(['statut' => 'en_attente']))
        ->toThrow(QueryException::class);
});

// ── API owner seul ──────────────────────────────────────────────────────────

test('API : l owner liste, accepte et refuse', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $ws = F::espace('zz-pc-api');
    $fiche = F::fiche($ws, 'ZZ Api', ['city' => 'Lyon', 'phone' => '0199000001']);
    $c = F::contact($ws, $fiche, 'Zoe', 'ZZAPI', ['role' => 'Gérante']);
    pcService()->proposer($ws, 'entreprise', $fiche, 'city', 'Bron', 'societe');
    pcService()->proposer($ws, 'entreprise', $fiche, 'phone', '0199000002', 'apporteur');
    pcService()->proposer($ws, 'personne', $c, 'role', 'Présidente', 'commercial');

    $this->actingAs(pcCompte($ws, 'owner'));
    $r = $this->getJson('/api/v1/crm/propositions?per_page=2')->assertOk();
    expect($r->json('meta.total'))->toBe(3)
        ->and($r->json('meta.per_page'))->toBe(2)
        ->and($r->json('data'))->toHaveCount(2)
        ->and($r->json('data.0.fiche'))->toBe('ZZ Api')
        ->and($r->json('data.0.champ'))->toBe('city')
        ->and($r->json('data.0.libelle_champ'))->toBe('Ville')
        ->and($r->json('data.0.valeur_actuelle'))->toBe('Lyon')
        ->and($r->json('data.0.valeur_proposee'))->toBe('Bron')
        ->and($r->json('data.0.origine'))->toBe('societe');
    $page2 = $this->getJson('/api/v1/crm/propositions?per_page=2&page=2')->assertOk();
    expect($page2->json('data'))->toHaveCount(1)
        ->and($page2->json('data.0.fiche'))->toBe('Zoe ZZAPI');

    $ids = DB::table('propositions_champs')->where('workspace_id', $ws)->orderBy('id')->pluck('id')->all();
    $this->postJson("/api/v1/crm/propositions/{$ids[0]}/accepter")->assertOk()->assertJsonPath('statut', 'acceptee');
    $this->postJson("/api/v1/crm/propositions/{$ids[1]}/refuser")->assertOk()->assertJsonPath('statut', 'refusee');
    $this->postJson("/api/v1/crm/propositions/{$ids[1]}/accepter")->assertStatus(409);

    expect(DB::table('companies')->where('id', $fiche)->value('city'))->toBe('Bron')
        ->and(DB::table('companies')->where('id', $fiche)->value('phone'))->toBe('0199000001')
        ->and($this->getJson('/api/v1/crm/propositions')->assertOk()->json('meta.total'))->toBe(1);
});

test('API : 403 pour tout rôle autre que owner, sur chacune des trois routes', function (string $role) {
    $this->seed(PermissionsAndRolesSeeder::class);
    $ws = F::espace('zz-pc-403');
    $fiche = F::fiche($ws, 'ZZ Interdit', ['city' => 'Lyon']);
    pcService()->proposer($ws, 'entreprise', $fiche, 'city', 'Bron', 'societe');
    $id = (int) DB::table('propositions_champs')->where('workspace_id', $ws)->value('id');

    $this->actingAs(pcCompte($ws, $role));
    $this->getJson('/api/v1/crm/propositions')->assertForbidden();
    $this->postJson("/api/v1/crm/propositions/{$id}/accepter")->assertForbidden();
    $this->postJson("/api/v1/crm/propositions/{$id}/refuser")->assertForbidden();

    expect(DB::table('companies')->where('id', $fiche)->value('city'))->toBe('Lyon')
        ->and(DB::table('propositions_champs')->where('id', $id)->value('statut'))->toBe('en_attente');
})->with(['admin', 'operator', 'viewer']);

test('API : une proposition d un autre espace est introuvable pour l owner', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $a = F::espace('zz-pc-ea');
    $b = F::espace('zz-pc-eb');
    $ficheB = F::fiche($b, 'ZZ Autre', ['city' => 'Lyon']);
    pcService()->proposer($b, 'entreprise', $ficheB, 'city', 'Bron', 'societe');
    $id = (int) DB::table('propositions_champs')->where('workspace_id', $b)->value('id');

    $this->actingAs(pcCompte($a, 'owner'));
    $this->getJson('/api/v1/crm/propositions')->assertOk()->assertJsonPath('meta.total', 0);
    $this->postJson("/api/v1/crm/propositions/{$id}/accepter")->assertNotFound();
    expect(DB::table('companies')->where('id', $ficheB)->value('city'))->toBe('Lyon');
});

test('compteur « À traiter » : l owner voit le nombre de propositions, les autres rôles rien', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $ws = F::espace('zz-pc-cpt');
    $fiche = F::fiche($ws, 'ZZ Compteur', ['city' => 'Lyon', 'phone' => '0199000001']);
    pcService()->proposer($ws, 'entreprise', $fiche, 'city', 'Bron', 'societe');
    pcService()->proposer($ws, 'entreprise', $fiche, 'phone', '0199000002', 'apporteur');

    $this->actingAs(pcCompte($ws, 'owner'));
    $this->getJson('/api/v1/crm/a-traiter/compteurs')->assertOk()->assertJsonPath('propositions', 2);

    $this->actingAs(pcCompte($ws, 'admin'));
    $this->getJson('/api/v1/crm/a-traiter/compteurs')->assertOk()->assertJsonPath('propositions', null);

    // Le geste vide la pastille sans attendre la fin du cache.
    $this->actingAs(pcCompte($ws, 'owner'));
    $id = (int) DB::table('propositions_champs')->where('workspace_id', $ws)->orderBy('id')->value('id');
    $this->postJson("/api/v1/crm/propositions/{$id}/refuser")->assertOk();
    $this->getJson('/api/v1/crm/a-traiter/compteurs')->assertOk()->assertJsonPath('propositions', 1);
});

test('rien n est branché à Partners : les routes de propositions sont authentifiées et derrière la console', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(static fn ($r): bool => str_contains($r->uri(), 'propositions'));

    expect($routes)->toHaveCount(3);
    foreach ($routes as $route) {
        expect($route->uri())->toStartWith('api/v1/crm/propositions')
            ->and($route->gatherMiddleware())->toContain('auth:sanctum')
            ->and($route->gatherMiddleware())->toContain('crm-console');
    }
});
