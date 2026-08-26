<?php

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * SUIVI DES ENVOIS PRESSE — la fiche rédaction et le registre.
 *
 * Le besoin : « savoir ce qu'on a envoyé au Mémorial, et quand ». Trois
 * garanties, dans l'ordre où leur absence fait mal :
 *
 *   1. AGRÉGATION — un communiqué envoyé à un JOURNALISTE du Mémorial apparaît
 *      sur la fiche du MÉMORIAL. Sans elle, la fiche rédaction afficherait
 *      « aucun échange » alors qu'on a déjà écrit, et le communiqué repartirait
 *      une seconde fois au même titre. C'est le seul comportement de ce lot
 *      qu'on ne peut pas rattraper à la lecture : les deux autres se voient.
 *   2. IDEMPOTENCE — deux clics sur « consigner » ne créent qu'une ligne. Un
 *      historique soupçonné de compter double ne sert plus à décider.
 *   3. NOMS RÉSOLUS — le registre affiche « Le Mémorial de l'Isère », pas
 *      « media #12 ». Vérifié parce que la colonne attendue (`full_name`)
 *      N'EXISTE PAS en base : le nom se compose de `first_name`+`last_name`,
 *      et la version naïve rendait `journaliste #id` sans lever d'erreur.
 */
beforeEach(function () {
    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'ws-presse',
        'name' => 'Presse',
        'settings' => [],
    ]);

    $this->user = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'presse@example.invalid',
        'name' => 'Attaché de presse',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $this->workspace->id,
        'first_login_completed_at' => now(),
    ]);

    DB::table('user_workspaces')->insertOrIgnore([
        'user_id' => $this->user->id,
        'workspace_id' => $this->workspace->id,
        'role_slug' => 'owner',
        'invited_at' => now(),
        'joined_at' => now(),
    ]);

    // Voir PresseEnvoisCroisementsTest pour le détail : semer les rôles, poser
    // le contexte d'équipe, puis attribuer. Dans cet ordre, et les trois.
    $this->seed(PermissionsAndRolesSeeder::class);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->id);
    $this->user->assignRole('owner');

    $this->actingAs($this->user);

    $this->mediaId = (int) DB::table('media')->insertGetId([
        'workspace_id' => $this->workspace->id,
        'name' => "Le Mémorial de l'Isère",
        // NOT NULL, et sous CHECK fermé. `presse_hebdo` n'est pas un
        // remplissage : Le Mémorial est bien un hebdomadaire.
        'media_type' => 'presse_hebdo',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('consigne un envoi sur la rédaction et le rend sur sa fiche', function () {
    $reponse = $this->postJson("/api/v1/media/{$this->mediaId}/activities", [
        'kind' => 'press_release_sent',
        'title' => 'Communiqué AI Act + 30 recrutements',
        'content' => 'Envoyé à redaction@memorial-isere.fr avec le PDF 6 pages.',
    ]);

    $reponse->assertStatus(201);

    $fiche = $this->getJson("/api/v1/media/{$this->mediaId}")->assertOk()->json();

    expect($fiche['timeline'])->toHaveCount(1)
        ->and($fiche['timeline'][0]['kind'])->toBe('press_release_sent')
        ->and($fiche['timeline'][0]['title'])->toBe('Communiqué AI Act + 30 recrutements')
        // `via` null = l'échange vise la rédaction elle-même, pas une personne.
        ->and($fiche['timeline'][0]['via'])->toBeNull();
});

it('ne dédouble pas un envoi consigné deux fois', function () {
    $charge = [
        'kind' => 'press_release_sent',
        'title' => 'Communiqué AI Act + 30 recrutements',
        'occurred_at' => '2026-08-25T09:30:00+02:00',
    ];

    $this->postJson("/api/v1/media/{$this->mediaId}/activities", $charge)->assertStatus(201);
    $second = $this->postJson("/api/v1/media/{$this->mediaId}/activities", $charge)->assertOk();

    expect($second->json('deja_consigne'))->toBeTrue()
        ->and(DB::table('activities')
            ->where('subject_type', 'media')
            ->where('subject_id', $this->mediaId)
            ->count())->toBe(1);
});

it("remonte sur la fiche rédaction un envoi fait à l'un de ses journalistes", function () {
    $journalisteId = (int) DB::table('journalists')->insertGetId([
        'workspace_id' => $this->workspace->id,
        'media_id' => $this->mediaId,
        'first_name' => 'Camille',
        'last_name' => 'Berthier',
        'source' => 'linkedin',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson("/api/v1/journalists/{$journalisteId}/activities", [
        'kind' => 'press_release_sent',
        'title' => 'Communiqué envoyé en direct',
    ])->assertStatus(201);

    $fiche = $this->getJson("/api/v1/media/{$this->mediaId}")->assertOk()->json();

    // LE point du lot : l'échange n'a pas été consigné sur la rédaction, et
    // pourtant il est sur sa fiche — avec le nom de la personne par qui il est
    // passé, parce qu'on ne relance pas un journal comme on relance quelqu'un.
    expect($fiche['timeline'])->toHaveCount(1)
        ->and($fiche['timeline'][0]['via'])->toBe('Camille Berthier');
});

it('liste les envois avec le nom de leur cible dans le registre', function () {
    $journalisteId = (int) DB::table('journalists')->insertGetId([
        'workspace_id' => $this->workspace->id,
        'media_id' => $this->mediaId,
        'first_name' => 'Camille',
        'last_name' => 'Berthier',
        'source' => 'linkedin',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson("/api/v1/media/{$this->mediaId}/activities", [
        'kind' => 'press_release_sent',
        'title' => 'Envoi rédaction',
    ])->assertStatus(201);

    $this->postJson("/api/v1/journalists/{$journalisteId}/activities", [
        'kind' => 'press_release_sent',
        'title' => 'Envoi nominatif',
    ])->assertStatus(201);

    $registre = $this->getJson('/api/v1/presse/envois')->assertOk()->json();

    expect($registre['meta']['total'])->toBe(2);

    $parTitre = collect($registre['data'])->keyBy('title');

    expect($parTitre['Envoi rédaction']['cible'])->toBe("Le Mémorial de l'Isère")
        ->and($parTitre['Envoi rédaction']['cible_type'])->toBe('redaction')
        // Le nom composé : c'est ici que `full_name` aurait rendu « journaliste #id ».
        ->and($parTitre['Envoi nominatif']['cible'])->toBe('Camille Berthier')
        ->and($parTitre['Envoi nominatif']['cible_type'])->toBe('journaliste')
        // Le journaliste est rattaché : le registre dit à quel titre il écrit.
        ->and($parTitre['Envoi nominatif']['redaction'])->toBe("Le Mémorial de l'Isère");
});

it('refuse une date future — « on leur a écrit demain » est une faute de saisie', function () {
    $this->postJson("/api/v1/media/{$this->mediaId}/activities", [
        'kind' => 'press_release_sent',
        'title' => 'Envoi daté du futur',
        'occurred_at' => now()->addDay()->toIso8601String(),
    ])->assertStatus(422);
});
