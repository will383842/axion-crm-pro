<?php

/**
 * GARDE DU JETON D'EXPORT — audit 360, B15-013.
 *
 * Le jeton de téléchargement ouvre l'archive chiffrée contenant **toutes** les
 * données personnelles d'une personne. Il partait EN CLAIR dans
 * `rgpd_requests.metadata` — alors que la colonne dédiée, elle, ne garde
 * délibérément qu'un hachage (`export_token`).
 *
 * Quiconque lisait cette table pouvait donc télécharger l'export complet de
 * n'importe qui. Et `GET /rgpd/requests` rend `metadata`.
 */

use App\Models\RgpdRequest;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function operateurRgpd(): User
{
    test()->seed(PermissionsAndRolesSeeder::class);

    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'jeton-' . Str::random(8),
        'name' => 'Espace jeton',
    ]);

    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'op-' . Str::random(6) . '@jeton.test',
        'name' => 'Opérateur',
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $workspace->id,
        'first_login_completed_at' => now(),
    ]);

    setPermissionsTeamId($workspace->id);
    $user->assignRole('admin');

    return $user;
}

test('B15-013 — le jeton de telechargement n est jamais ECRIT en base', function () {
    Storage::fake('local');
    $operateur = operateurRgpd();

    $demande = RgpdRequest::create([
        'workspace_id' => $operateur->current_workspace_id,
        'type' => 'portability',
        'status' => 'pending',
        'subject_email' => 'sujet@jeton.test',
        'requested_at' => now(),
    ]);

    $reponse = $this->actingAs($operateur)
        ->postJson('/api/v1/rgpd/requests/' . $demande->id . '/process')
        ->assertOk();

    $jeton = $reponse->json('result.token');
    expect($jeton)->toBeString()->not->toBeEmpty();

    // Le jeton est rendu à l'opérateur — il doit bien le transmettre — mais il
    // ne doit se retrouver NULLE PART en base, sous aucune forme lisible.
    $ligne = DB::table('rgpd_requests')->where('id', $demande->id)->first();

    expect((string) $ligne->metadata)->not->toContain($jeton);
    expect((string) $ligne->export_token)->not->toBe($jeton);

    // Ce qui EST stocké est le haché, et lui seul.
    expect((string) $ligne->export_token)->toBe(hash('sha256', $jeton));
});

test('B15-013 — TEMOIN : le reste du resultat est bien conserve', function () {
    Storage::fake('local');
    $operateur = operateurRgpd();

    $demande = RgpdRequest::create([
        'workspace_id' => $operateur->current_workspace_id,
        'type' => 'portability',
        'status' => 'pending',
        'subject_email' => 'sujet2@jeton.test',
        'requested_at' => now(),
    ]);

    $this->actingAs($operateur)->postJson('/api/v1/rgpd/requests/' . $demande->id . '/process')->assertOk();

    // Sans ce témoin, un correctif qui viderait TOUT `metadata` passerait pour
    // une réussite — et on perdrait la trace du traitement.
    $ligne = DB::table('rgpd_requests')->where('id', $demande->id)->first();
    expect((string) $ligne->metadata)->toContain('expires_at');
});
