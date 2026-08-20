<?php

/**
 * GARDE — audit 360, B16-007 (S1) : le registre AI Act existait en base, et la
 * route qui le sert NE LE LISAIT PAS.
 *
 * Mesure du 2026-08-20, code casse
 * (`app/Http/Controllers/Api/AiActRegisterController.php`) :
 *
 *     public function index(Request $r): JsonResponse { return $this->ok(['data' => []]); }
 *     public function store(Request $r): JsonResponse { return $this->notImplemented('11'); }
 *
 * Autrement dit : la table `ai_act_register` existe (migration
 * `2026_05_16_000006`), elle porte une policy RLS, un trigger `updated_at`, un
 * seeder — et `GET /api/v1/ai-act/register` repondait `200 {"data":[]}` QUOI
 * QU'ELLE CONTIENNE. Une route qui rend toujours la liste vide ne dit pas
 * « le registre est vide » : elle dit « je n'ai pas regarde », et un 200 est
 * indistinguable d'un registre reellement tenu.
 *
 * Consequence reglementaire, et c'est la raison de la severite : le reglement
 * UE 2024/1689 (AI Act) demande une documentation TENUE des systemes d'IA
 * deployes. Une route de consultation qui rend systematiquement le vide est
 * la preuve d'une documentation non tenue — et pire, elle donne l'apparence
 * d'un registre en bon ordre a qui la consulte.
 *
 * L'ecriture, elle, repondait 501 : le registre n'avait aucun moyen d'etre
 * rempli par le produit. Vide, il l'etait donc par construction.
 */

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Un espace de travail nu. */
function espaceRegistreIa(string $marque): Workspace
{
    return Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'aia-' . $marque . '-' . Str::random(6),
        'name' => 'Espace registre IA ' . $marque,
    ]);
}

/** Un compte rattache a cet espace, portant le role demande. */
function compteRegistreIa(Workspace $espace, string $role = 'admin'): User
{
    test()->seed(PermissionsAndRolesSeeder::class);

    $utilisateur = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'aia-' . Str::random(6) . '@audit.test',
        'name' => 'Referent IA',
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $espace->id,
        'first_login_completed_at' => now(),
    ]);

    setPermissionsTeamId($espace->id);
    $utilisateur->assignRole($role);

    return $utilisateur;
}

/** Une entree de registre ecrite directement en base. */
function inscrireSystemeIa(Workspace $espace, string $nom, string $classe = 'limited'): int
{
    return (int) DB::table('ai_act_register')->insertGetId([
        'workspace_id' => $espace->id,
        'system_name' => $nom,
        'purpose' => 'Classification B2B a partir de donnees publiques.',
        'risk_class' => $classe,
        'provider' => 'Anthropic',
        'model' => 'claude-sonnet-4-6',
        'impact_assessment' => json_encode(['human_oversight' => 'systematic'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// LECTURE
// ─────────────────────────────────────────────────────────────────────────────

test('B16-007 — la route REND ce que le registre contient', function () {
    $espace = espaceRegistreIa('lecture');
    inscrireSystemeIa($espace, 'Routeur LLM — classification');

    // TEMOIN 1 — la ligne est bien en base. Sans lui, un `data: []` pourrait
    // aussi bien venir d'un semis rate que de la route.
    $this->assertSame(
        1,
        DB::table('ai_act_register')->where('workspace_id', $espace->id)->count(),
        'Le semis a echoue : la garde ne mesure rien.'
    );

    $reponse = $this->actingAs(compteRegistreIa($espace))->getJson('/api/v1/ai-act/register');

    $reponse->assertOk();
    $this->assertCount(
        1,
        (array) $reponse->json('data'),
        'La route rend le vide alors que le registre contient une entree (constat B16-007).'
    );
    $this->assertSame('Routeur LLM — classification', $reponse->json('data.0.system_name'));
    // Le champ est un JSONB : il doit sortir en objet, pas en chaine echappee,
    // sinon l'analyse d'impact est illisible pour qui consomme la route.
    $this->assertSame('systematic', $reponse->json('data.0.impact_assessment.human_oversight'));
});

test('B16-007 — TEMOIN : un registre reellement vide rend une liste vide', function () {
    // Sans ce temoin, une route qui rendrait une entree fabriquee de toutes
    // pieces passerait la garde precedente.
    $espace = espaceRegistreIa('vide');

    $reponse = $this->actingAs(compteRegistreIa($espace))->getJson('/api/v1/ai-act/register');

    $reponse->assertOk();
    $this->assertSame([], (array) $reponse->json('data'));
});

test('B16-007 — TEMOIN : le registre d un AUTRE espace reste invisible', function () {
    // Le registre AI Act nomme des systemes, des fournisseurs et des analyses
    // d'impact : c'est de la documentation interne, cloisonnee comme le reste
    // (meme exigence que B16-004 sur le journal d'audit).
    $mien = espaceRegistreIa('mien');
    $autre = espaceRegistreIa('autre');
    inscrireSystemeIa($autre, 'Systeme du voisin');

    $reponse = $this->actingAs(compteRegistreIa($mien))->getJson('/api/v1/ai-act/register');

    $reponse->assertOk();
    $this->assertSame(
        [],
        (array) $reponse->json('data'),
        "Le registre AI Act d'un autre espace de travail est rendu."
    );
});

// ─────────────────────────────────────────────────────────────────────────────
// ECRITURE — sans quoi le registre reste vide par construction
// ─────────────────────────────────────────────────────────────────────────────

test('B16-007 — le registre peut etre RENSEIGNE par le produit', function () {
    $espace = espaceRegistreIa('ecriture');

    $reponse = $this->actingAs(compteRegistreIa($espace))->postJson('/api/v1/ai-act/register', [
        'system_name' => 'Extraction ours — journalistes',
        'purpose' => 'Extraction de contacts de redaction depuis des pages publiques.',
        'risk_class' => 'limited',
        'provider' => 'Mistral',
        'model' => 'mistral-large-latest',
        'impact_assessment' => ['human_oversight' => 'systematic'],
    ]);

    $reponse->assertCreated();

    // On verifie EN BASE, pas dans la reponse : une route peut rendre 201 sans
    // rien ecrire.
    $ligne = DB::table('ai_act_register')->where('workspace_id', $espace->id)->first();
    $this->assertNotNull($ligne, "Le 201 n'a rien ecrit dans le registre.");
    $this->assertSame('Extraction ours — journalistes', $ligne->system_name);
});

test('B16-007 — une classe de risque inventee est REFUSEE', function () {
    // TEMOIN — sans validation, l'ecriture se ferait rejeter par la contrainte
    // CHECK de Postgres, donc par un 500 : la route doit trancher elle-meme.
    $espace = espaceRegistreIa('classe');

    $this->actingAs(compteRegistreIa($espace))->postJson('/api/v1/ai-act/register', [
        'system_name' => 'Systeme sans classe',
        'purpose' => 'Peu importe.',
        'risk_class' => 'tres-dangereux',
    ])->assertStatus(422);

    $this->assertSame(0, DB::table('ai_act_register')->count());
});

test('B16-007 — l espace de l entree est IMPOSE, jamais choisi par l appelant', function () {
    // Un `workspace_id` accepte depuis le corps ecrirait dans le registre du
    // voisin. C'est le meme motif que les ecritures RGPD (L4).
    $mien = espaceRegistreIa('impose');
    $autre = espaceRegistreIa('vise');

    $this->actingAs(compteRegistreIa($mien))->postJson('/api/v1/ai-act/register', [
        'workspace_id' => $autre->id,
        'system_name' => 'Tentative de depot chez le voisin',
        'purpose' => 'Peu importe.',
        'risk_class' => 'minimal',
    ])->assertCreated();

    $this->assertSame(
        0,
        DB::table('ai_act_register')->where('workspace_id', $autre->id)->count(),
        "Une entree a ete ecrite dans le registre d'un autre espace."
    );
    $this->assertSame(1, DB::table('ai_act_register')->where('workspace_id', $mien->id)->count());
});

test('B16-007 — un compte en lecture seule ne peut pas ecrire au registre', function () {
    // Le registre AI Act est une piece de conformite : il se tient, il ne
    // s'improvise pas. Meme droit que le traitement RGPD (`rgpd.handle`), que
    // `viewer` ne porte pas.
    $espace = espaceRegistreIa('viewer');

    $this->actingAs(compteRegistreIa($espace, 'viewer'))->postJson('/api/v1/ai-act/register', [
        'system_name' => 'Ecriture par un lecteur',
        'purpose' => 'Peu importe.',
        'risk_class' => 'minimal',
    ])->assertForbidden();

    $this->assertSame(0, DB::table('ai_act_register')->count());
});
