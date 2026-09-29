<?php

/**
 * RELECTURE S1 (PR #261) — l'empreinte de vérification d'une adresse ne sort
 * pas par l'API pour qui ne voit les adresses que masquées, et elle n'est pas
 * un SHA-256 nu (qu'un compte en lecture seule pourrait recalculer depuis une
 * adresse devinée, pour la CONFIRMER). Fixtures FICTIVES.
 */

use App\Crm\Emails\Dns\ResolveurDns;
use App\Crm\Emails\VerificationEmail;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\ResolveurDnsSimule;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const VEMM_GENERIQUE = 'contact@zz-masque.example';
const VEMM_PERSONNE = 'zoe.zz@zz-masque.example';

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'zz-vemm-' . Str::random(5), 'name' => 'WS', 'settings' => [],
    ]);
    config(['crm.ingest.business_workspace' => $this->workspace->slug]);

    $ws = $this->workspace->id;
    $this->fiche = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $ws, 'siren' => '940009901', 'entity_nature' => 'federation', 'denomination' => 'ZZ Masque',
        'email_generic' => VEMM_GENERIQUE,
        'signals' => json_encode(['contact_channels' => ['emails' => ['canal@zz-masque.example']]]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('federations')->insert([
        'company_id' => $this->fiche, 'workspace_id' => $ws, 'famille' => 'federation_syndicat_pro', 'niveau' => 'national',
        'pertinence' => 'haute', 'contactabilite' => 'email_verifie',
    ]);
    $this->contact = (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $ws, 'company_id' => $this->fiche, 'first_name' => 'Zoe', 'last_name' => 'ZZMASQUE',
        'email' => VEMM_PERSONNE, 'created_at' => now(), 'updated_at' => now(),
    ]);

    app()->instance(ResolveurDns::class, new ResolveurDnsSimule);
    Artisan::call('crm:emails:verifier');
});

function vemmCompte(string $workspaceId, string $role): User
{
    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => $role . '-' . Str::random(6) . '@example.invalid',
        'name' => 'ZZ ' . $role,
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $workspaceId,
        'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($workspaceId);
    $user->assignRole($role);

    return $user;
}

/** @return list<string> les corps des trois réponses qui portent une vérification */
function vemmCorps(TestCase $t, int $fiche, int $contact): array
{
    return [
        (string) $t->getJson("/api/v1/companies/{$fiche}")->assertOk()->getContent(),
        (string) $t->getJson("/api/v1/contacts/{$contact}")->assertOk()->getContent(),
        (string) $t->getJson("/api/v1/federations/{$fiche}")->assertOk()->getContent(),
    ];
}

test('l empreinte est un HMAC a cle secrete, pas le SHA-256 de l adresse', function () {
    $signals = (array) json_decode((string) DB::table('companies')->where('id', $this->fiche)->value('signals'), true);
    $empreinte = $signals['email_generic_verification']['empreinte'] ?? null;

    expect($empreinte)->toBe(VerificationEmail::empreinte(VEMM_GENERIQUE))
        ->and($empreinte)->not->toBe(hash('sha256', VEMM_GENERIQUE))
        ->and($empreinte)->toBe(hash_hmac('sha256', VEMM_GENERIQUE, hash_hmac('sha256', 'crm:emails:verifier|empreinte', (string) config('app.key'), true)));

    // Une autre clé : une autre empreinte — elle ne se recalcule pas sans elle.
    config(['app.key' => 'base64:' . base64_encode(str_repeat('z', 32))]);
    expect(VerificationEmail::empreinte(VEMM_GENERIQUE))->not->toBe($empreinte);
});

test('un compte viewer ne recoit AUCUNE empreinte ; le proprietaire, si (temoin)', function () {
    $empreintes = [VerificationEmail::empreinte(VEMM_GENERIQUE), VerificationEmail::empreinte(VEMM_PERSONNE)];

    // TÉMOIN : la vérification a bien écrit, et un compte qui voit les
    // coordonnées reçoit les empreintes — sinon le test du viewer verdirait
    // par simple absence.
    $this->actingAs(vemmCompte($this->workspace->id, 'owner'));
    $proprietaire = implode("\n", vemmCorps($this, $this->fiche, $this->contact));
    expect($proprietaire)->toContain($empreintes[0])
        ->and($proprietaire)->toContain($empreintes[1]);

    $this->actingAs(vemmCompte($this->workspace->id, 'viewer'));
    foreach (vemmCorps($this, $this->fiche, $this->contact) as $corps) {
        expect($corps)->not->toContain('"empreinte"')
            ->and($corps)->not->toContain($empreintes[0])
            ->and($corps)->not->toContain($empreintes[1])
            // La vérification elle-même reste lisible (statut, motif) : seule
            // l'empreinte est retirée.
            ->and($corps)->toContain('"statut"');
    }
});
