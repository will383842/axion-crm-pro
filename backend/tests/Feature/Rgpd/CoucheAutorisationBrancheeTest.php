<?php

/**
 * GARDE F36-001 (S0) — « la couche d'autorisation du produit est du code mort ».
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUI ÉTAIT MESURÉ, LE 2026-08-21, AVANT CE LOT
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *   DELETE /api/v1/journalists/{id}   par un compte « Lecture seule » -> 204
 *   DELETE /api/v1/journalists/{id}   par un ADMIN                    -> 204
 *
 * Le même code pour les deux : **aucun contrôle n'était exercé**. Le journaliste
 * partait en corbeille sur la demande d'un compte dont le rôle s'appelle
 * littéralement « Lecture seule ».
 *
 * Sur les 118 routes de l'API, **42 écritures authentifiées** ne portaient aucun
 * middleware `permission:` — dont **25 VIVANTES** : créer et lancer une campagne
 * de prospection, lancer du scraping, supprimer un journaliste, gérer les
 * audiences. Les 17 autres rendaient 501, ce qui ne protège que jusqu'au jour où
 * quelqu'un les câble.
 *
 * ⚠️ ET LES 422 NE PROTÉGEAIENT RIEN. `POST /campaigns` rendait 422 à un viewer,
 * ce qui ressemble à un refus. Le corps disait la vérité :
 * `{"errors":{"sources":["validation.required"]}}` — un refus de FORME, pas de
 * DROIT. Avec un corps valide, le viewer créait la campagne. *Un code d'erreur
 * ne dit pas pourquoi on a dit non.*
 *
 * ── Pourquoi la pièce existait sans être branchée ──────────────────────────
 *
 * `F36-002` avait montré la cause mécanique : `$this->authorize()` levait
 * « Call to undefined method », parce que `ApiController` n'étend pas la classe
 * du dépôt qui porte `AuthorizesRequests`. Le trait a été posé — la méthode est
 * devenue appelable. **Personne ne l'appelait pour autant.**
 *
 * Le modèle de droits, lui, était complet et semé depuis le début
 * (`PermissionsAndRolesSeeder`) :
 *
 *   viewer   → companies.view, llm.view_usage, rgpd.view
 *   operator → + create/update, scraping.run, data.export   (« CRUD sans destruction »)
 *   admin    → + delete, users.manage, proxies.config, llm.config, rgpd.handle
 *   owner    → tout
 *
 * Ce lot ne l'invente donc pas : il le BRANCHE. 35 routes reçoivent le
 * middleware `permission:` qui leur manquait — les écritures sans contrôle
 * passent de 42 à 9, et les neuf restantes sont justifiées une par une
 * ci-dessous.
 */

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
});

function f36bCompte(string $role): User
{
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'f36b-' . Str::random(8),
        'name' => 'Espace autorisation',
    ]);

    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => $role . '-' . Str::random(6) . '@f36b.test',
        'name' => ucfirst($role),
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $workspace->id,
        'first_login_completed_at' => now(),
    ]);

    setPermissionsTeamId($workspace->id);
    $user->assignRole($role);

    return $user;
}

function f36bJournaliste(string $espace): int
{
    return (int) DB::table('journalists')->insertGetId([
        'workspace_id' => $espace,
        'source' => 'test',
        'opt_out' => false,
        'email' => 'cible-' . Str::random(6) . '@f36b.test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ---------------------------------------------------------------------------
// 1. LE GESTE EXACT QUI A ÉTÉ MESURÉ ROUGE
// ---------------------------------------------------------------------------

test('F36-001 — un compte LECTURE SEULE ne supprime PAS un journaliste', function () {
    $viewer = f36bCompte('viewer');
    $id = f36bJournaliste($viewer->current_workspace_id);

    $this->actingAs($viewer)->deleteJson("/api/v1/journalists/{$id}")->assertForbidden();

    // 🔑 L'ASSERTION QUI COMPTE. Un 403 sur une ligne déjà partie ne vaudrait
    // rien : c'est la SURVIE de la fiche qui prouve que le refus a mordu.
    expect((int) DB::table('journalists')->where('id', $id)->whereNull('deleted_at')->count())->toBe(
        1,
        'Le journaliste est parti en corbeille malgre le 403 : le refus arrive trop tard.',
    );
});

test('F36-001 — TEMOIN : un ADMIN supprime bien, lui', function () {
    $admin = f36bCompte('admin');
    $id = f36bJournaliste($admin->current_workspace_id);

    $this->actingAs($admin)->deleteJson("/api/v1/journalists/{$id}")->assertNoContent();

    // Sans ce temoin, un correctif qui refuserait TOUT LE MONDE passerait pour
    // une reussite. C'est la faute que la garde F36-003 nomme deja.
    expect((int) DB::table('journalists')->where('id', $id)->whereNull('deleted_at')->count())->toBe(0);
});

test('F36-001 — un OPERATEUR ne detruit pas non plus : « CRUD sans destruction »', function () {
    $operator = f36bCompte('operator');
    $id = f36bJournaliste($operator->current_workspace_id);

    $this->actingAs($operator)->deleteJson("/api/v1/journalists/{$id}")->assertForbidden();

    expect((int) DB::table('journalists')->where('id', $id)->whereNull('deleted_at')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// 2. LE 422 QUI RESSEMBLAIT À UN REFUS
// ---------------------------------------------------------------------------

test('F36-001 — lancer une campagne est refuse pour DROIT, et non pour forme', function () {
    $viewer = f36bCompte('viewer');

    $r = $this->actingAs($viewer)->postJson('/api/v1/campaigns', [
        'name' => 'Campagne du viewer', 'query' => 'test', 'zones' => ['75'],
    ]);

    // 🔴 AVANT CE LOT, CETTE MEME REQUETE RENDAIT 422 — et 422 ressemble a un
    // refus. Le corps disait pourtant `validation.required` sur `sources` : la
    // requete etait mal formee, pas interdite. Un corps valide serait passe.
    $r->assertForbidden();

    // ⚠️ `assertStringNotContainsString` ET NON `->not->toContain(...)`.
    //
    // En Pest, `toContain` est VARIADIQUE : le second argument n'est pas un
    // message d'echec, c'est une SECONDE valeur cherchee. Ecrit avec `toContain`,
    // ce test aurait exige que la reponse ne contienne pas non plus la phrase
    // explicative — absente, evidemment : il serait passe au vert pour la
    // mauvaise raison. La garde `AucunMessageDansToContainTest` traque
    // exactement cela, et c'est elle qui a rattrape cette ligne en CI.
    $this->assertStringNotContainsString(
        'validation.required',
        (string) $r->getContent(),
        'La requete est refusee pour sa FORME et non pour le DROIT : avec un corps valide, '
        . 'ce compte lancerait la campagne.',
    );
});

test('F36-001 — TEMOIN : un OPERATEUR a bien le droit de lancer une campagne', function () {
    $operator = f36bCompte('operator');

    $r = $this->actingAs($operator)->postJson('/api/v1/campaigns', [
        'name' => 'Campagne', 'query' => 'test', 'zones' => ['75'],
    ]);

    // `scraping.run` fait partie de ses droits : il ne doit PAS etre arrete par
    // la couche d'autorisation. Qu'il bute ensuite sur la validation est normal
    // et sans rapport — c'est meme la preuve qu'il a franchi la porte.
    expect($r->status())->not->toBe(
        403,
        "L'operateur porte `scraping.run` : le brancher ne doit pas lui fermer la porte.",
    );
});

// ---------------------------------------------------------------------------
// 3. LE RECENSEMENT — par le ROUTEUR, jamais par une liste écrite à la main
// ---------------------------------------------------------------------------

test('F36-001 — RECENSEMENT : toute ecriture authentifiee porte une permission, ou est nommee ici', function () {
    /**
     * Les seules écritures authentifiées SANS `permission:`, et la raison de
     * chacune. Ce ne sont pas des dérogations : ce sont des gestes qu'un compte
     * pose SUR LUI-MÊME, ou des routes qui n'existent pas encore.
     */
    $justifiees = [
        // Son propre compte — exiger une permission ici enfermerait dehors un
        // compte neuf, qui n'a pas encore franchi sa première connexion.
        'api/v1/auth/2fa/setup',
        'api/v1/auth/2fa/confirm',
        'api/v1/auth/logout',
        'api/v1/auth/onboarding/complete',
        // Ses propres notifications.
        'api/v1/notifications/read-all',
        'api/v1/notifications/{n}/read',
        // Stubs Phase 2 : le contrôleur entier rend 501. Le jour où l'un d'eux
        // est câblé, cette garde le dira.
        'api/v1/cold-email{any?}',
        'api/v1/linkedin{any?}',
        'api/v1/crm/bulk',
    ];

    $sansPermission = [];
    $total = 0;

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();
        if (! str_starts_with($uri, 'api/')) {
            continue;
        }

        $ecrit = (bool) array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods());

        // ⚠️ `gatherMiddleware()` rend les ALIAS, PAS les classes resolues.
        //
        // Mesure du 2026-08-21 sur `api/v1/journalists` :
        //   gatherMiddleware()   -> « api | auth:sanctum | workspace | first-login »
        //   route:list --json    -> « ... | Illuminate\Auth\Middleware\Authenticate:sanctum | ... »
        //
        // Une premiere version de cette garde cherchait `Authenticate` — le nom
        // de la CLASSE, celui que rend `route:list`. Elle a donc balaye 118
        // routes et n'en a retenu AUCUNE : `$total` valait zero, et sans le
        // temoin de couverture ci-dessous, l'inventaire vide serait passe pour
        // « aucune route sans permission ». Le pire des verts.
        $mw = implode(' ', $route->gatherMiddleware());

        // Les routes NON authentifiées ont leur propre garde (signature HMAC,
        // jeton d'export, ou ce sont les portes d'entrée elles-mêmes) : elles
        // sont hors du périmètre de CETTE garde-ci.
        if (! $ecrit || ! str_contains($mw, 'auth:')) {
            continue;
        }

        $total++;

        if (! str_contains($mw, 'permission:')) {
            $sansPermission[] = $uri;
        }
    }

    // TEMOIN DE COUVERTURE : un balayage qui ne voit rien certifierait le vide.
    expect($total)->toBeGreaterThanOrEqual(
        50,
        "Seulement {$total} ecritures authentifiees vues, contre 60 relevees le 2026-08-21 : "
        . 'le balayage du routeur ne voit pas ce qu il croit voir.',
    );

    $sansPermission = array_values(array_unique($sansPermission));
    sort($sansPermission);
    sort($justifiees);

    expect($sansPermission)->toBe(
        $justifiees,
        "L inventaire des ecritures sans controle d autorisation a change.\n"
        . 'Vues sans permission : ' . implode(', ', $sansPermission) . "\n"
        . 'Attendues            : ' . implode(', ', $justifiees) . "\n"
        . 'Une route neuve qui ecrit doit porter `permission:`. Si elle ne le peut pas, '
        . 'ajoutez-la ci-dessus AVEC SA RAISON — jamais sans.',
    );
});
