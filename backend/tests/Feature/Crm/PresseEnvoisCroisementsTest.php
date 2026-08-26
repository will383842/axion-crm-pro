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
 * SUIVI DES ENVOIS PRESSE — VÉRIFICATION APPROFONDIE.
 *
 * `PresseSuiviEnvoisTest` couvre le chemin nominal. Ce fichier couvre ce qui
 * casse EN SILENCE : l'étanchéité entre workspaces, les filtres qui ne filtrent
 * pas, l'ordre chronologique, et les états intermédiaires (contact non
 * rattaché). Aucun de ces défauts ne lève d'erreur — ils rendent seulement un
 * écran qui ment.
 */
beforeEach(function () {
    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'ws-croisements',
        'name' => 'Croisements',
        'settings' => [],
    ]);

    $this->user = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'croisements@example.invalid',
        'name' => 'Attache de presse',
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

    // ⚠️ TROIS gestes, pas un — c'est le motif des tests de `main` qui touchent
    // une route protégée (cf. ActionsDeMasseTagsTest) :
    //   1. SEMER les rôles : `RefreshDatabase` rend une base nue, et
    //      `assignRole('owner')` y lève `RoleDoesNotExist`.
    //   2. Poser le contexte d'ÉQUIPE : Spatie porte les rôles par workspace ;
    //      sans lui, le rôle est attribué à l'équipe `null` et ne sert à rien.
    //   3. Attribuer le rôle.
    // `user_workspaces.role_slug` est la table MAISON et ne suffit pas : le
    // middleware `permission:` interroge Spatie. Poser l'une sans l'autre
    // donnait un compte « owner » à qui l'API répondait 403 sur toute écriture.
    $this->seed(PermissionsAndRolesSeeder::class);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->id);
    $this->user->assignRole('owner');

    $this->actingAs($this->user);

    $this->mediaId = (int) DB::table('media')->insertGetId([
        'workspace_id' => $this->workspace->id,
        'name' => "Le Memorial de l'Isere",
        'media_type' => 'presse_hebdo',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

/**
 * ETANCHEITE. Le modele `Media` n'utilise PAS le trait `BelongsToWorkspace` :
 * aucune garde applicative ne l'isole, et le cloisonnement repose ENTIEREMENT
 * sur la RLS PostgreSQL (`relrowsecurity` ET `relforcerowsecurity` a `t`,
 * mesure du 2026-08-25). Une garde dont on ne depend qu'en un seul point doit
 * etre verifiee : un `FORCE` retire par megarde ouvrirait toutes les fiches de
 * tous les workspaces sans qu'aucun autre test ne rougisse.
 */
it("n'atteint pas la fiche d'une redaction d'un autre workspace", function () {
    $autre = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'ws-etranger',
        'name' => 'Etranger',
        'settings' => [],
    ]);

    $mediaEtranger = (int) DB::table('media')->insertGetId([
        'workspace_id' => $autre->id,
        'name' => 'Quotidien d-un autre workspace',
        'media_type' => 'presse_quotidien',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->getJson("/api/v1/media/{$mediaEtranger}")->assertNotFound();

    $this->postJson("/api/v1/media/{$mediaEtranger}/activities", [
        'kind' => 'press_release_sent',
        'title' => 'Tentative de fuite',
    ])->assertNotFound();

    expect(DB::table('activities')->where('title', 'Tentative de fuite')->count())->toBe(0);
});

/** Meme garde sur la fiche PERSONNE : c'est le meme trou, vu de l'autre cote. */
it("n'atteint pas la fiche d'un journaliste d'un autre workspace", function () {
    $autre = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'ws-etranger-2',
        'name' => 'Etranger 2',
        'settings' => [],
    ]);

    $journalisteEtranger = (int) DB::table('journalists')->insertGetId([
        'workspace_id' => $autre->id,
        'first_name' => 'Contact',
        'last_name' => 'Etranger',
        'source' => 'linkedin',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->getJson("/api/v1/journalists/{$journalisteEtranger}")->assertNotFound();

    $this->postJson("/api/v1/journalists/{$journalisteEtranger}/activities", [
        'kind' => 'press_release_sent',
        'title' => 'Fuite par la fiche personne',
    ])->assertNotFound();

    expect(DB::table('activities')->where('title', 'Fuite par la fiche personne')->count())->toBe(0);
});

/**
 * Le registre ne montre QUE les gestes de diffusion presse. Un appel consigne
 * sur la meme fiche est un touchpoint legitime, mais dans un ecran intitule
 * « communiques envoyes » il gonflerait le compteur et ferait croire a des
 * envois qui n'ont pas eu lieu. Il doit rester visible sur la FICHE.
 */
it('exclut du registre les gestes qui ne sont pas de la diffusion presse', function () {
    $this->postJson("/api/v1/media/{$this->mediaId}/activities", [
        'kind' => 'press_release_sent', 'title' => 'Un envoi',
    ])->assertStatus(201);

    $this->postJson("/api/v1/media/{$this->mediaId}/activities", [
        'kind' => 'call', 'title' => 'Un appel',
    ])->assertStatus(201);

    $registre = $this->getJson('/api/v1/presse/envois')->assertOk()->json();

    expect($registre['meta']['total'])->toBe(1)
        ->and($registre['data'][0]['title'])->toBe('Un envoi');

    $fiche = $this->getJson("/api/v1/media/{$this->mediaId}")->assertOk()->json();
    expect($fiche['timeline'])->toHaveCount(2);
});

it('filtre le registre par nature de geste', function () {
    $this->postJson("/api/v1/media/{$this->mediaId}/activities", [
        'kind' => 'press_release_sent', 'title' => 'Envoi initial',
    ])->assertStatus(201);
    $this->postJson("/api/v1/media/{$this->mediaId}/activities", [
        'kind' => 'press_followup', 'title' => 'Relance a J+7',
    ])->assertStatus(201);

    $relances = $this->getJson('/api/v1/presse/envois?kind=press_followup')->assertOk()->json();
    expect($relances['meta']['total'])->toBe(1)
        ->and($relances['data'][0]['title'])->toBe('Relance a J+7');

    // Une nature inconnue est IGNOREE, elle ne vide pas l'ecran : un filtre qui
    // rend zero sans rien dire se lit comme « aucun envoi », ce qui est faux.
    $inconnue = $this->getJson('/api/v1/presse/envois?kind=nawak')->assertOk()->json();
    expect($inconnue['meta']['total'])->toBe(2);
});

it("cherche dans l'objet des envois, sans tenir compte de la casse", function () {
    $this->postJson("/api/v1/media/{$this->mediaId}/activities", [
        'kind' => 'press_release_sent', 'title' => 'Communique AI Act',
    ])->assertStatus(201);
    $this->postJson("/api/v1/media/{$this->mediaId}/activities", [
        'kind' => 'press_release_sent', 'title' => 'Dossier recrutement',
    ])->assertStatus(201);

    $r = $this->getJson('/api/v1/presse/envois?q=ai+act')->assertOk()->json();

    expect($r['meta']['total'])->toBe(1)
        ->and($r['data'][0]['title'])->toBe('Communique AI Act');
});

it('pagine le registre sans perdre de ligne', function () {
    foreach (range(1, 5) as $i) {
        $this->postJson("/api/v1/media/{$this->mediaId}/activities", [
            'kind' => 'press_release_sent',
            'title' => "Envoi numero {$i}",
            // Dates distinctes : sans elles, l'empreinte d'idempotence
            // dedoublonne a la minute et il ne resterait qu'une ligne.
            'occurred_at' => now()->subDays($i)->toIso8601String(),
        ])->assertStatus(201);
    }

    $page1 = $this->getJson('/api/v1/presse/envois?per_page=2&page=1')->assertOk()->json();
    $page3 = $this->getJson('/api/v1/presse/envois?per_page=2&page=3')->assertOk()->json();

    expect($page1['meta']['total'])->toBe(5)
        ->and($page1['meta']['last_page'])->toBe(3)
        ->and($page1['data'])->toHaveCount(2)
        ->and($page3['data'])->toHaveCount(1);

    $titres = collect([...$page1['data'], ...$page3['data']])->pluck('title')->all();
    expect(count(array_unique($titres)))->toBe(3);
});

/**
 * Un echange consigne aujourd'hui mais date du mois dernier se range a SA place
 * dans l'histoire, pas en tete parce qu'on vient de le taper. Sans ce tri, la
 * fiche raconterait l'ordre de saisie et non l'ordre des faits.
 */
it("classe les echanges par date de l'echange, pas de la saisie", function () {
    $this->postJson("/api/v1/media/{$this->mediaId}/activities", [
        'kind' => 'press_release_sent',
        'title' => 'Envoi ancien',
        'occurred_at' => now()->subMonth()->toIso8601String(),
    ])->assertStatus(201);

    $this->postJson("/api/v1/media/{$this->mediaId}/activities", [
        'kind' => 'press_followup',
        'title' => 'Relance recente',
        'occurred_at' => now()->subDay()->toIso8601String(),
    ])->assertStatus(201);

    $fiche = $this->getJson("/api/v1/media/{$this->mediaId}")->assertOk()->json();

    expect($fiche['timeline'][0]['title'])->toBe('Relance recente')
        ->and($fiche['timeline'][1]['title'])->toBe('Envoi ancien');
});

/**
 * Un journaliste sans redaction rattachee : le registre doit rendre `null`
 * plutot que d'inventer un titre. C'est un arbitrage EN ATTENTE, que l'ecran
 * affiche en ambre — pas une donnee manquante qu'on masquerait.
 */
it('signale un journaliste dont la redaction reste a rattacher', function () {
    $orphelin = (int) DB::table('journalists')->insertGetId([
        'workspace_id' => $this->workspace->id,
        'media_id' => null,
        'first_name' => 'Sacha',
        'last_name' => 'Nogaret',
        'media_raw' => 'Chroniqueur BFM Business, CEO Le Crayon Groupe',
        'source' => 'linkedin',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson("/api/v1/journalists/{$orphelin}/activities", [
        'kind' => 'press_release_sent', 'title' => 'Envoi a un contact non rattache',
    ])->assertStatus(201);

    $r = $this->getJson('/api/v1/presse/envois')->assertOk()->json();

    expect($r['data'][0]['cible'])->toBe('Sacha Nogaret')
        ->and($r['data'][0]['cible_type'])->toBe('journaliste')
        ->and($r['data'][0]['redaction'])->toBeNull();
});

/**
 * La liste de saisie est un SOUS-ENSEMBLE volontaire du vocabulaire de la
 * timeline. Proposer moins que ce que la base accepte est sain ; l'inverse
 * serait faux. On verifie que le controleur refuse une nature hors
 * sous-ensemble, meme si le CHECK en base l'accepterait.
 */
it("refuse une nature d'echange hors du sous-ensemble presse", function () {
    $this->postJson("/api/v1/media/{$this->mediaId}/activities", [
        'kind' => 'gdpr_erasure',
        'title' => 'Nature valide en base, mais pas a la saisie',
    ])->assertStatus(422);
});

/** Un echange sans objet ne se relit pas : le titre est obligatoire. */
it('exige un objet', function () {
    $this->postJson("/api/v1/media/{$this->mediaId}/activities", [
        'kind' => 'press_release_sent',
    ])->assertStatus(422);
});

/**
 * Une redaction sans aucun journaliste ne doit pas faire tomber la requete
 * d'agregation : le `whereIn` sur une liste vide est le piege classique.
 */
it('rend une fiche sans journaliste sans lever d-erreur', function () {
    $fiche = $this->getJson("/api/v1/media/{$this->mediaId}")->assertOk()->json();

    expect($fiche['data']['name'])->toBe("Le Memorial de l'Isere")
        ->and($fiche['timeline'])->toBe([]);
});

/**
 * Le registre agrege DEUX populations. On verifie qu'il ne confond pas leurs
 * identifiants : un media #1 et un journaliste #1 coexistent, et chacun doit
 * rendre SON nom. Sans la discrimination par `subject_type`, la resolution
 * croiserait les deux tables sur un id commun.
 */
it('ne confond pas un media et un journaliste portant le meme identifiant', function () {
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
        'kind' => 'press_release_sent', 'title' => 'Cible redaction',
    ])->assertStatus(201);
    $this->postJson("/api/v1/journalists/{$journalisteId}/activities", [
        'kind' => 'press_release_sent', 'title' => 'Cible personne',
    ])->assertStatus(201);

    $r = $this->getJson('/api/v1/presse/envois')->assertOk()->json();
    $parTitre = collect($r['data'])->keyBy('title');

    expect($parTitre['Cible redaction']['cible'])->toBe("Le Memorial de l'Isere")
        ->and($parTitre['Cible personne']['cible'])->toBe('Camille Berthier');
});
