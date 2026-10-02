<?php

/**
 * PALETTE ⌘K (`/search`) REJOUÉE SOUS LE RÔLE DE PRODUCTION (`axion_app`,
 * RLS forcée) — même défaut, même remède que la #292.
 *
 * Mesuré en production (2026-10-03, lecture seule) : sous `axion_app`, chaque
 * frappe parcourait `companies` (4,3 M de lignes, 3,9 à 4,7 s) et `contacts`
 * (1,3 M, ~1,25 s) : `ILIKE` / `LIKE '%x%'` ne sont pas « leakproof ».
 *
 * Ici : les fonctions `entreprises_siren_ids` et `contacts_recherche_ids` sous
 * `axion_app` (résultats, cloisonnement, contexte absent ou en majuscules),
 * leurs droits, et un test qui rougit si le contrôleur réémet un `LIKE`
 * direct sur `companies` ou `contacts`.
 *
 * Ce test COMMIT pour la partie `axion_app` (connexions hors transaction de
 * test) : il nettoie tout. Fixtures FICTIVES (dépôt public).
 */

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function rgrProprio(): Connection
{
    return DB::connection('pgsql_owner');
}

function rgrApp(): Connection
{
    return DB::connection('pgsql_app');
}

/** @return array{id: string, entreprises: array<string, int>, contacts: array<string, int>} */
function rgrEspace(string $prefixeSiren): array
{
    $owner = rgrProprio();
    $id = (string) Str::uuid();
    $marque = substr(str_replace('-', '', $id), 0, 8);
    $owner->table('workspaces')->insert([
        'id' => $id, 'slug' => 'zz-search-rls-' . $marque, 'name' => 'ZZ search RLS', 'settings' => '{}',
        'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $entreprises = [];
    foreach (['a' => '1', 'b' => '2', 'c' => '3'] as $cle => $fin) {
        $entreprises[$cle] = (int) $owner->table('companies')->insertGetId([
            'workspace_id' => $id, 'siren' => $prefixeSiren . '000000' . $fin, 'denomination' => 'ZZ RGR ' . strtoupper($cle),
            'discovery_source' => 'site', 'quality_score' => 0, 'signals' => '{}', 'metadata' => '{}',
            'relation_type' => 'prospect', 'lifecycle_stage' => 'nouveau', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $contacts = [];
    foreach (['bernadette' => ['Jeanne', 'Zzbernadette', 'jeanne.zz@example.invalid'], 'autre' => ['Paul', 'Zzautre', 'paul.zz@example.invalid']] as $cle => [$prenom, $nom, $email]) {
        $contacts[$cle] = (int) $owner->table('contacts')->insertGetId([
            'workspace_id' => $id, 'company_id' => $entreprises['a'], 'first_name' => $prenom, 'last_name' => $nom,
            'email' => $email, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return ['id' => $id, 'entreprises' => $entreprises, 'contacts' => $contacts];
}

/** @param  array{id: string}  $e */
function rgrNettoyer(array $e): void
{
    $owner = rgrProprio();
    $owner->table('contacts')->where('workspace_id', $e['id'])->delete();
    $owner->table('companies')->where('workspace_id', $e['id'])->delete();
    $owner->table('workspaces')->where('id', $e['id'])->delete();
}

function rgrContexte(?string $espace): void
{
    rgrApp()->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $espace ?? '']);
}

/** @return list<int> */
function rgrSiren(string $espace, string $chiffres): array
{
    return array_map(static fn ($l): int => (int) $l->id, rgrApp()->select(
        'SELECT t.id FROM public.entreprises_siren_ids(?::uuid, ?, 10) WITH ORDINALITY AS t(id, rang) ORDER BY t.rang',
        [$espace, $chiffres],
    ));
}

/** @return list<int> */
function rgrContacts(string $espace, string $terme): array
{
    return array_map(static fn ($l): int => (int) $l->id, rgrApp()->select(
        'SELECT t.id FROM public.contacts_recherche_ids(?::uuid, ?, 10) WITH ORDINALITY AS t(id, rang) ORDER BY t.rang',
        [$espace, $terme],
    ));
}

afterEach(function () {
    rgrContexte(null);
    rgrApp()->disconnect();
    rgrProprio()->disconnect();
});

test('sous axion_app : SIREN (égalité, préfixe) et personnes (nom, prénom, début d e-mail), rien d un autre espace', function () {
    $a = rgrEspace('77');
    $b = rgrEspace('77');

    try {
        $role = rgrApp()->selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        expect($role->rolsuper)->toBeFalse()->and($role->rolbypassrls)->toBeFalse();

        rgrContexte($a['id']);
        $sirenA = (string) rgrProprio()->table('companies')->where('id', $a['entreprises']['b'])->value('siren');

        // SIREN complet : égalité.
        expect(rgrSiren($a['id'], $sirenA))->toBe([$a['entreprises']['b']]);
        // Début de SIREN : préfixe, dans l'ordre des SIREN.
        expect(rgrSiren($a['id'], substr($sirenA, 0, 7)))
            ->toBe([$a['entreprises']['a'], $a['entreprises']['b'], $a['entreprises']['c']]);
        // Jamais de joker venu de la saisie.
        expect(rgrSiren($a['id'], '7%'))->toBe([]);

        // Personnes : nom, prénom, DÉBUT d'e-mail.
        expect(rgrContacts($a['id'], 'bernadet'))->toBe([$a['contacts']['bernadette']]);
        expect(rgrContacts($a['id'], 'jeanne'))->toBe([$a['contacts']['bernadette']]);
        expect(rgrContacts($a['id'], 'paul.zz'))->toBe([$a['contacts']['autre']]);
        // Sous 3 caractères : rien.
        expect(rgrContacts($a['id'], 'zz'))->toBe([]);

        // CLOISONNEMENT : rien de B sous le contexte de A, même en visant B.
        $deB = array_merge(array_values($b['entreprises']), array_values($b['contacts']));
        expect(array_intersect(array_merge(rgrSiren($a['id'], '77'), rgrContacts($a['id'], 'zzb')), $deB))->toBe([]);
        expect(rgrSiren($b['id'], '77'))->toBe([]);
        expect(rgrContacts($b['id'], 'zzbernadette'))->toBe([]);

        // Contexte vidé : rien.
        rgrContexte(null);
        expect(rgrSiren($a['id'], '77'))->toBe([])->and(rgrContacts($a['id'], 'jeanne'))->toBe([]);

        // Contexte JAMAIS posé (connexion neuve) : rien.
        rgrApp()->disconnect();
        expect(rgrSiren($a['id'], '77'))->toBe([])->and(rgrContacts($a['id'], 'jeanne'))->toBe([]);

        // UUID en MAJUSCULES dans le contexte : rien.
        rgrContexte(strtoupper($a['id']));
        expect(rgrSiren($a['id'], '77'))->toBe([])->and(rgrContacts($a['id'], 'jeanne'))->toBe([]);

        // TÉMOIN : B, dans son contexte, trouve les siens.
        rgrContexte($b['id']);
        expect(rgrContacts($b['id'], 'jeanne'))->toBe([$b['contacts']['bernadette']]);
    } finally {
        rgrContexte(null);
        rgrNettoyer($a);
        rgrNettoyer($b);
    }
});

test('les deux fonctions sont SECURITY DEFINER, search_path catalogue d abord, refusées à PUBLIC, accordées au rôle applicatif', function () {
    foreach (['entreprises_siren_ids' => 'uuid, text, integer', 'contacts_recherche_ids' => 'uuid, text, integer'] as $nom => $signature) {
        $f = DB::selectOne(<<<'SQL'
            SELECT p.prosecdef AS definer,
                   array_to_string(p.proconfig, ',') AS config,
                   p.proacl IS NULL AS droits_par_defaut,
                   EXISTS (SELECT 1 FROM aclexplode(p.proacl) a WHERE a.grantee = 0 AND a.privilege_type = 'EXECUTE') AS public_execute
            FROM   pg_proc p
            JOIN   pg_namespace n ON n.oid = p.pronamespace
            WHERE  n.nspname = 'public' AND p.proname = ?
        SQL, [$nom]);

        expect($f)->not->toBeNull()
            ->and((bool) $f->definer)->toBeTrue()
            ->and((string) $f->config)->toContain('search_path=pg_catalog, public')
            ->and((bool) $f->droits_par_defaut)->toBeFalse()
            ->and((bool) $f->public_execute)->toBeFalse();

        $peut = DB::selectOne(
            "SELECT has_function_privilege(?, 'public." . $nom . '(' . $signature . ")', 'EXECUTE') AS ok",
            [(string) config('database.connections.pgsql_app.username', 'axion_app')],
        );
        expect((bool) $peut->ok)->toBeTrue();
    }
});

test('la palette ne réémet JAMAIS de LIKE direct sur companies ou contacts (rougit si l ILIKE revient)', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $espace = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'zz-rgr-listen', 'name' => 'ZZ', 'settings' => []]);
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'rgr.listen@example.invalid', 'name' => 'ZZ',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $espace->id,
        'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($espace->id);
    $user->assignRole('admin');
    DB::table('user_workspaces')->insertOrIgnore([
        'user_id' => $user->id, 'workspace_id' => $espace->id, 'role_slug' => 'owner', 'invited_at' => now(), 'joined_at' => now(),
    ]);
    $entreprise = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $espace->id, 'siren' => '552100554', 'denomination' => 'Boulangerie Zzmartin',
        'discovery_source' => 'site', 'quality_score' => 0, 'signals' => '{}', 'metadata' => '{}',
        'relation_type' => 'prospect', 'lifecycle_stage' => 'nouveau', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('contacts')->insert([
        'workspace_id' => $espace->id, 'company_id' => $entreprise, 'first_name' => 'Jeanne', 'last_name' => 'Zzmartin',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $requetes = [];
    DB::listen(function ($q) use (&$requetes): void {
        $requetes[] = $q->sql;
    });

    $parNom = $this->actingAs($user)->getJson('/api/v1/search?q=zzmartin')->assertOk();
    $parSiren = $this->actingAs($user)->getJson('/api/v1/search?q=5521')->assertOk();

    // TÉMOINS : les familles trouvent bien.
    expect(collect($parNom->json('companies'))->pluck('id')->all())->toBe([$entreprise])
        ->and(collect($parNom->json('contacts'))->pluck('last_name')->all())->toBe(['Zzmartin'])
        ->and(collect($parSiren->json('companies'))->pluck('id')->all())->toBe([$entreprise]);

    $sql = collect($requetes);
    expect($sql->contains(fn (string $s): bool => str_contains($s, 'entreprises_choix_ids')))->toBeTrue()
        ->and($sql->contains(fn (string $s): bool => str_contains($s, 'entreprises_siren_ids')))->toBeTrue()
        ->and($sql->contains(fn (string $s): bool => str_contains($s, 'contacts_recherche_ids')))->toBeTrue();

    $direct = $sql->filter(fn (string $s): bool => (str_contains($s, 'from "companies"') || str_contains($s, 'from "contacts"'))
        && stripos($s, 'like') !== false);
    expect($direct->values()->all())->toBe([]);
});
