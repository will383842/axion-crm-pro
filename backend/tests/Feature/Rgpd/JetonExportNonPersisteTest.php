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
use App\Services\Rgpd\GdprPortabilityService;
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

/*
 * ─── B15-012 — « one-shot » annoncé, rejeu illimité mesuré ─────────────────
 *
 * L'en-tête de `GdprPortabilityService` promettait un « token téléchargement
 * one-shot ». `retrieve()` ne consommait rien. Les deux gardes ci-dessous
 * tiennent les deux bouts : la première MESURE le sort réel du jeton, la
 * seconde interdit que l'ANNONCE et le GESTE se remettent à diverger — dans un
 * sens comme dans l'autre, car passer réellement en usage unique reste un
 * arbitrage produit, pas un oubli à rattraper en douce.
 */

test('B15-012 — MESURE : le jeton d export est REJOUABLE tant qu il n a pas expire', function () {
    Storage::fake('local');
    $operateur = operateurRgpd();

    $demande = RgpdRequest::create([
        'workspace_id' => $operateur->current_workspace_id,
        'type' => 'portability',
        'status' => 'pending',
        'subject_email' => 'sujet-rejeu@jeton.test',
        'requested_at' => now(),
    ]);

    $jeton = $this->actingAs($operateur)
        ->postJson('/api/v1/rgpd/requests/' . $demande->id . '/process')
        ->assertOk()
        ->json('result.token');

    $service = app(GdprPortabilityService::class);

    $premier = $service->retrieve($jeton);
    expect($premier)->toBeString()->not->toBeEmpty();

    // Le second appel doit rendre EXACTEMENT la même archive : c'est ce qui
    // sauve le double-clic, le prefetch du navigateur et la reprise d'un
    // téléchargement interrompu.
    $second = $service->retrieve($jeton);
    expect($second)->toBe(
        $premier,
        'le second téléchargement du même jeton ne rend plus l\'archive : si l\'usage unique '
        . 'vient d\'être décidé, ce n\'est pas cette garde qu\'il faut ajuster — il faut d\'abord '
        . 'porter la décision dans l\'en-tête de `retrieve()` (B15-012) et vérifier ce que devient '
        . 'la personne qui reclique son lien',
    );

    // TÉMOIN — sans lui, une garde qui compare deux `null` verdirait sur un
    // jeton qui n'ouvre rien du tout.
    expect(json_decode((string) $premier, true))->toHaveKey('subject');
});

test('B15-012 — l ANNONCE du service et le SORT du jeton ne peuvent plus diverger', function () {
    $chemin = base_path('app/Services/Rgpd/GdprPortabilityService.php');
    $source = (string) file_get_contents($chemin);

    $coupe = strpos($source, 'class GdprPortabilityService');
    expect($coupe !== false)->toBeTrue(
        'la classe `GdprPortabilityService` a été renommée : cette garde n\'inspecte plus rien. '
        . 'GESTE : réajuster le repère, ou la retirer si le service a disparu.',
    );

    // Ce que la classe PROMET, dans son en-tête et là seulement.
    $entete = substr($source, 0, (int) $coupe);
    $promet = preg_match('/one[-\s]?shot|usage unique|single[-\s]?use/iu', $entete) === 1;

    // Ce que `retrieve()` FAIT : consommer, c'est écrire — nullage du jeton,
    // date de consommation, ou suppression du fichier chiffré.
    $debutRetrieve = strpos($source, 'public function retrieve');
    expect($debutRetrieve !== false)->toBeTrue(
        '`retrieve()` a disparu de `GdprPortabilityService` : cette garde n\'inspecte plus rien. '
        . 'GESTE : réajuster le repère sur la méthode qui échange le jeton contre l\'archive.',
    );
    $corpsRetrieve = substr($source, (int) $debutRetrieve);
    $consomme = preg_match('/->update\(|Storage::disk\([^)]*\)->delete\(|export_consumed_at/', $corpsRetrieve) === 1;

    expect($promet)->toBe(
        $consomme,
        $promet
            ? 'l\'en-tête de `GdprPortabilityService` annonce un jeton à usage unique alors que '
              . '`retrieve()` n\'écrit rien : c\'est le défaut B15-012 qui revient — soit on '
              . 'consomme réellement le jeton, soit on retire la promesse de l\'en-tête'
            : 'la consommation du jeton a été implémentée sans que l\'en-tête de '
              . '`GdprPortabilityService` le dise : mets l\'annonce à jour, et vérifie que la '
              . 'personne qui reclique son lien de téléchargement ne reçoit plus un 404 muet',
    );
});
