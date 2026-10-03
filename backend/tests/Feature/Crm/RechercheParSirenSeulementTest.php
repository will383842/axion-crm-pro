<?php

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * LE CRM RANGE LES ENTREPRISES PAR SIREN (décision du 03/10/2026, ADR 0001, `_AUDIT/ADR/`).
 *
 * Un SIRET (14 chiffres) saisi dans le sélecteur « Entreprise »
 * (`GET /crm/entreprises/choix`) ou dans la palette Ctrl+K (`GET /search`)
 * est ramené à ses 9 premiers chiffres : il retrouve la fiche du SIREN —
 * celle du siège — même quand c'est le numéro d'une AGENCE (établissement
 * secondaire) qu'aucune fiche ne porte. Un SIRET dont le SIREN est inconnu
 * ne rend rien. Il n'y a ni index sur `siret` ni table d'établissements.
 *
 * Numéros et noms fictifs (dépôt PUBLIC) : les SIREN sont TIRÉS AU HASARD à
 * chaque test (`sirenFictif`, clé de Luhn valide), jamais un numéro écrit en
 * dur qui pourrait être celui d'une vraie entreprise ; le SIRET « inconnu »
 * (999 999 999 00011) n'a pas de clé de Luhn valide, donc n'est attribué à
 * personne.
 */
beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    config(['crm.console_v2' => true, 'crm.ingest.business_workspace' => 'axion-ia']);

    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'axion-ia', 'name' => 'Axion-IA', 'settings' => [],
    ]);
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'siren.seul@example.invalid', 'name' => 'Opérateur SIREN',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $this->workspace->id,
        'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($this->workspace->id);
    $user->assignRole('admin');
    DB::table('user_workspaces')->insertOrIgnore([
        'user_id' => $user->id, 'workspace_id' => $this->workspace->id, 'role_slug' => 'owner',
        'invited_at' => now(), 'joined_at' => now(),
    ]);
    $this->actingAs($user);

    // Le siège : SIREN tiré au hasard, SIRET du siège = SIREN + NIC 00017.
    $this->siren = sirenFictif();
    do {
        $voisin = sirenFictif();
    } while ($voisin === $this->siren);
    $this->siege = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->workspace->id, 'siren' => $this->siren, 'siret' => $this->siren . '00017',
        'denomination' => 'Zz Réseau Siège', 'discovery_source' => 'site', 'quality_score' => 0,
        'signals' => '{}', 'metadata' => '{}', 'relation_type' => 'prospect', 'lifecycle_stage' => 'nouveau',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    // TÉMOIN : un SIREN voisin, qui ne doit jamais remonter.
    DB::table('companies')->insert([
        'workspace_id' => $this->workspace->id, 'siren' => $voisin, 'denomination' => 'Zz Voisine',
        'discovery_source' => 'site', 'quality_score' => 0, 'signals' => '{}', 'metadata' => '{}',
        'relation_type' => 'prospect', 'lifecycle_stage' => 'nouveau', 'created_at' => now(), 'updated_at' => now(),
    ]);
});

/** Un SIREN fictif tiré au hasard (9 chiffres, clé de Luhn valide). */
function sirenFictif(): string
{
    $corps = (string) random_int(10000000, 99999999);
    for ($cle = 0; $cle <= 9; $cle++) {
        $n = $corps . $cle;
        $somme = 0;
        foreach (str_split(strrev($n)) as $i => $c) {
            $d = (int) $c * ($i % 2 === 1 ? 2 : 1);
            $somme += $d > 9 ? $d - 9 : $d;
        }
        if ($somme % 10 === 0) {
            return $n;
        }
    }

    throw new LogicException('Clé de Luhn introuvable.');
}

/** @return list<int> */
function sirenIds(TestResponse $reponse, string $cle): array
{
    /** @var list<array{id: int}> $lignes */
    $lignes = $reponse->json($cle) ?? [];

    return array_map(static fn (array $l): int => (int) $l['id'], $lignes);
}

test('sélecteur « Entreprise » : le SIRET d une agence retrouve la fiche du siège', function () {
    // Agence (établissement secondaire) : même SIREN, NIC 00025 — aucune
    // fiche ne porte ce SIRET.
    $agence = $this->siren . '00025';
    expect(DB::table('companies')->where('siret', $agence)->exists())->toBeFalse();

    $reponse = $this->getJson('/api/v1/crm/entreprises/choix?q=' . $agence)->assertOk();
    expect(sirenIds($reponse, 'data'))->toBe([$this->siege]);
    $reponse->assertJsonPath('indice', null);

    // Espaces et points tolérés.
    expect(sirenIds($this->getJson('/api/v1/crm/entreprises/choix?q=' . urlencode(implode(' ', str_split($this->siren, 3)) . ' 00025'))->assertOk(), 'data'))
        ->toBe([$this->siege]);
    // TÉMOIN : le SIRET du siège lui-même.
    expect(sirenIds($this->getJson('/api/v1/crm/entreprises/choix?q=' . $this->siren . '00017')->assertOk(), 'data'))->toBe([$this->siege]);
});

test('sélecteur « Entreprise » : un SIRET dont le SIREN est inconnu ne rend rien', function () {
    $this->getJson('/api/v1/crm/entreprises/choix?q=99999999900011')->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('indice', null);
});

test('palette Ctrl+K : le SIRET d une agence retrouve la fiche du siège', function () {
    $reponse = $this->getJson('/api/v1/search?q=' . $this->siren . '00025')->assertOk();
    expect(sirenIds($reponse, 'companies'))->toBe([$this->siege]);

    expect(sirenIds($this->getJson('/api/v1/search?q=' . urlencode(implode('.', str_split($this->siren, 3)) . '.00025'))->assertOk(), 'companies'))
        ->toBe([$this->siege]);
});

test('palette Ctrl+K : un SIRET dont le SIREN est inconnu ne rend aucune entreprise', function () {
    expect(sirenIds($this->getJson('/api/v1/search?q=99999999900011')->assertOk(), 'companies'))->toBe([]);
});
