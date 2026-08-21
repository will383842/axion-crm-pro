<?php

/**
 * GARDE G43-005 — LE VERROU EXISTAIT, UN SEUL ÉCRAN LE PORTAIT.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `EditionConcurrenteTest` prouve, en douze gardes, que `PUT /companies/{id}`
 * détecte une édition concurrente : `If-Match` périmé → 409, la première saisie
 * survit. Le mécanisme vit dans un TRAIT PARTAGÉ,
 * `App\Http\Controllers\Concerns\VerrouOptimiste`.
 *
 * Mesure du 2026-08-21 : **un seul contrôleur l'utilisait**. Les trois autres
 * `update()` vivants de l'API écrasaient toujours en silence —
 *
 *   PUT /tags/{tag}                 TagsController@update
 *   PUT /audiences/{audience}       AudiencesController@update
 *   PUT /campaigns/{campaign}       ScrapingCampaignsController@update
 *
 * Deux personnes ouvrent la même fiche, la modifient, enregistrent : la seconde
 * écrase la première, et **les deux reçoivent « succès »**. La saisie perdue ne
 * laisse aucune trace — ni journal, ni conflit, ni rien à quoi se raccrocher
 * pour comprendre pourquoi le travail a disparu.
 *
 * ── Ce que cette garde ajoute, et ce qu'elle n'ajoute pas ──────────────────
 *
 * Elle ne réécrit pas les douze gardes de `EditionConcurrenteTest` pour chaque
 * contrôleur : ce serait recopier un raisonnement au lieu de l'étendre. Elle
 * fait deux choses que l'autre ne peut pas faire :
 *
 *   1. elle MESURE le comportement sur un des contrôleurs nouvellement câblés ;
 *   2. elle RECENSE — tout `update()` vivant doit porter le trait, et le jour où
 *      un contrôleur neuf en écrit un sans lui, elle le nomme.
 *
 * Le verrou reste OPTIONNEL : sans en-tête `If-Match`, rien ne change. C'est la
 * promesse de compatibilité du trait, et le témoin ci-dessous la vérifie —
 * sinon « personne ne perd plus de saisie » voudrait dire « plus personne
 * n'enregistre ».
 */

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
});

function g43eCompte(): array
{
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'g43e-' . Str::random(8),
        'name' => 'Espace verrou',
    ]);

    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'g43e-' . Str::random(6) . '@verrou.test',
        'name' => 'Admin verrou',
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $workspace->id,
        'first_login_completed_at' => now(),
    ]);

    setPermissionsTeamId($workspace->id);
    $user->assignRole('admin');

    return [$user, $workspace->id];
}

function g43eEtiquette(string $espace): int
{
    return (int) DB::table('tags')->insertGetId([
        'workspace_id' => $espace,
        'name' => 'Etiquette initiale',
        'slug' => 'etq-' . Str::random(6),
        'kind' => 'manual',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ---------------------------------------------------------------------------
// 1. LE COMPORTEMENT, sur un contrôleur nouvellement câblé
// ---------------------------------------------------------------------------

test('G43-005 — TEMOIN de presence : la route ecrit vraiment', function () {
    [$u, $espace] = g43eCompte();
    $id = g43eEtiquette($espace);

    $this->actingAs($u)
        ->putJson("/api/v1/tags/{$id}", ['name' => 'Ecrit par le temoin'])
        ->assertOk();

    expect((string) DB::table('tags')->where('id', $id)->value('name'))->toBe('Ecrit par le temoin');
});

test('G43-005 — TEMOIN de compatibilite : SANS jeton, rien ne change', function () {
    [$u, $espace] = g43eCompte();
    $id = g43eEtiquette($espace);

    // Deux saisies concurrentes, aucune n'annonce l'etat sur lequel elle
    // travaille : le comportement historique doit tenir — sinon on aurait
    // remplace une perte silencieuse par un refus generalise.
    $this->actingAs($u)->putJson("/api/v1/tags/{$id}", ['name' => 'Premiere'])->assertOk();
    $this->actingAs($u)->putJson("/api/v1/tags/{$id}", ['name' => 'Seconde'])->assertOk();

    expect((string) DB::table('tags')->where('id', $id)->value('name'))->toBe('Seconde');
});

test('G43-005 — If-Match perime : la seconde saisie est REFUSEE, la premiere survit', function () {
    [$u, $espace] = g43eCompte();
    $id = g43eEtiquette($espace);

    // ⚠️ CE CONTROLEUR N'EXPOSE PAS DE `show()` : `GET /tags/{id}` n'existe
    // pas. La reponse du PUT est donc le SEUL endroit ou un jeton peut etre
    // remis — et c'est precisement pour cela que l'`ETag` y a ete pose. Sans
    // lui, le verrou serait inatteignable depuis l'exterieur : un client
    // n'aurait aucun moyen d'annoncer l'etat sur lequel il travaille.
    $lecture = $this->actingAs($u)->putJson("/api/v1/tags/{$id}", ['name' => 'Etat lu par Alice']);
    $jetonAlice = $lecture->headers->get('ETag');

    expect($jetonAlice)->not->toBeNull(
        'La reponse ne porte aucun `ETag` : aucun client ne peut obtenir de jeton, '
        . 'et le verrou est du decor.',
    );

    // Bob modifie entre-temps.
    $this->actingAs($u)
        ->putJson("/api/v1/tags/{$id}", ['name' => 'Saisie de Bob'])
        ->assertOk();

    // Alice enregistre, en annoncant l'etat qu'elle avait lu.
    $this->actingAs($u)
        ->withHeaders(['If-Match' => $jetonAlice])
        ->putJson("/api/v1/tags/{$id}", ['name' => 'Saisie d Alice'])
        ->assertStatus(409);

    // 🔑 L'ASSERTION QUI COMPTE : le travail de Bob est INTACT.
    expect((string) DB::table('tags')->where('id', $id)->value('name'))->toBe(
        'Saisie de Bob',
        'Le 409 est rendu mais l ecriture a eu lieu quand meme : le refus arrive trop tard.',
    );
});

test('G43-005 — TEMOIN anti-409-systematique : un jeton A JOUR passe', function () {
    [$u, $espace] = g43eCompte();
    $id = g43eEtiquette($espace);

    $lecture = $this->actingAs($u)->putJson("/api/v1/tags/{$id}", ['name' => 'Etat lu']);
    $jeton = $lecture->headers->get('ETag');

    // Personne n'a rien touche entre-temps : la saisie DOIT passer. Sans ce
    // temoin, un verrou qui refuserait tout le monde passerait pour une
    // reussite.
    $this->actingAs($u)
        ->withHeaders(['If-Match' => $jeton])
        ->putJson("/api/v1/tags/{$id}", ['name' => 'Saisie legitime'])
        ->assertOk();

    expect((string) DB::table('tags')->where('id', $id)->value('name'))->toBe('Saisie legitime');
});

// ---------------------------------------------------------------------------
// 2. LE RECENSEMENT — par le code, jamais par une liste écrite à la main
// ---------------------------------------------------------------------------

test('G43-005 — RECENSEMENT : tout update() vivant porte le verrou, ou est nomme ici', function () {
    /**
     * `update()` vivants qui NE portent PAS le verrou, avec leur raison.
     *
     * La liste est vide, et doit le rester. Ce n'est pas une dérogation : c'est
     * le registre de ce qui reste à faire.
     */
    $justifies = [];

    // ⚠️ `scandir` recursif : sur le montage Docker de Windows,
    // `RecursiveDirectoryIterator` ne rend qu'une partie des repertoires
    // fournis (mesure du 2026-08-21 : 14 fichiers sur 56 pour
    // `app/Console/Commands`). Une enumeration batie dessus se declarerait
    // complete sur un echantillon.
    $balayer = function (string $dossier) use (&$balayer): array {
        $trouves = [];
        foreach (scandir($dossier) ?: [] as $entree) {
            if ($entree === '.' || $entree === '..') {
                continue;
            }
            $chemin = $dossier . DIRECTORY_SEPARATOR . $entree;
            if (is_dir($chemin)) {
                $trouves = array_merge($trouves, $balayer($chemin));
            } elseif (str_ends_with($entree, 'Controller.php')) {
                $trouves[] = $chemin;
            }
        }

        return $trouves;
    };

    $fichiers = $balayer(app_path('Http/Controllers'));

    // TEMOIN DE COUVERTURE : un balayage vide certifierait le neant.
    expect(count($fichiers))->toBeGreaterThanOrEqual(
        25,
        'Seulement ' . count($fichiers) . ' controleurs vus, contre 38 releves le 2026-08-21 : '
        . 'le balayage ne voit pas ce qu il croit voir.',
    );

    $sansVerrou = [];
    $avecUpdate = 0;

    foreach ($fichiers as $chemin) {
        $source = (string) file_get_contents($chemin);

        if (preg_match('/public function update\s*\(/', $source) !== 1) {
            continue;
        }

        // Un `update()` qui rend 501 n'ecrit rien : il n'y a pas de saisie a
        // perdre. Le jour ou il est cable, il apparaitra ici.
        $corps = substr($source, (int) strpos($source, 'public function update'), 700);
        if (str_contains($corps, 'notImplemented')) {
            continue;
        }

        $avecUpdate++;

        if (! str_contains($source, 'VerrouOptimiste')) {
            $sansVerrou[] = basename($chemin);
        }
    }

    expect($avecUpdate)->toBeGreaterThanOrEqual(
        4,
        "Seulement {$avecUpdate} update() vivants vus, contre 4 releves le 2026-08-21.",
    );

    sort($sansVerrou);
    sort($justifies);

    expect($sansVerrou)->toBe(
        $justifies,
        "Des controleurs ecrivent sans verrou optimiste :\n  "
        . implode("\n  ", $sansVerrou) . "\n"
        . 'Deux saisies concurrentes y perdent du travail EN SILENCE. Posez le trait '
        . '`VerrouOptimiste` et appelez `refuserSiVersionPerimee()` avant l ecriture.',
    );
});
