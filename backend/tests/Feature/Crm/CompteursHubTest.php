<?php

use App\Crm\Console\CompteursHub;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * COMPTEURS DU HUB — étape 1a, première pièce (§27).
 *
 * Le défaut corrigé est MESURÉ, pas supposé
 * (`_REPORTS/2026-08-18_MESURE-PERFORMANCE-REFERENCE.md` §4 n°1, rejoué le
 * 2026-08-19) : `GROUP BY relation_type, lifecycle_stage` était un balayage
 * séquentiel complet de `companies` — 337 à 476 ms sur 300 000 fiches, donc de
 * l'ordre de 3 s sur les 4,29 M de la production, à chaque rendu de navigation.
 *
 * Quatre gardes, chacune vue ROUGE avant d'être verte (§28.3 — « une garde ne
 * vaut que si elle rougit ») ; les sorties rouges sont consignées dans
 * `_SESSIONS/2026-08-19_CRM-ETAPE-1A.md` §2.
 *
 *   1. PLAN       — la requête est servie par `idx_companies_ws_counts`, en
 *                   `Index Only Scan`, et ne balaye pas la table ;
 *   2. CACHE      — deux affichages de suite ne font pas deux fois le calcul ;
 *   3. ÉTANCHÉITÉ — le cache ne fuit JAMAIS d'un workspace à l'autre ;
 *   4. FRAÎCHEUR  — une action de masse qui déplace des fiches d'une étape à
 *                   l'autre fait bouger la pastille TOUT DE SUITE, sans
 *                   attendre la fin de la fenêtre de fraîcheur.
 */
beforeEach(function () {
    config(['crm.console_v2' => true]);
    Cache::flush();

    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'ws-compteurs',
        'name' => 'Compteurs',
        'settings' => [],
    ]);

    $this->user = compteursUser($this->workspace->id);
    $this->actingAs($this->user);
});

// ── Fabriques (locales à ce fichier : un test ne dépend pas d'un autre) ──────

function compteursUser(string $workspaceId, string $email = 'compteurs@example.invalid'): User
{
    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => $email,
        'name' => 'Opérateur',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $workspaceId,
        'first_login_completed_at' => now(),
    ]);

    DB::table('user_workspaces')->insertOrIgnore([
        'user_id' => $user->id,
        'workspace_id' => $workspaceId,
        'role_slug' => 'owner',
        'invited_at' => now(),
        'joined_at' => now(),
    ]);

    return $user;
}

/** @param  array<string, mixed>  $overrides */
function compteursCompany(string $workspaceId, string $siren, array $overrides = []): int
{
    return (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $workspaceId,
        'siren' => $siren,
        'denomination' => 'ZZ COMPTEURS ' . $siren,
        'discovery_source' => 'site',
        'quality_score' => 0,
        'signals' => '{}',
        'metadata' => '{}',
        'relation_type' => 'prospect',
        'lifecycle_stage' => 'nouveau',
        'legal_basis' => 'legitimate_interest_b2b',
        'field_origins' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/**
 * Compte les requêtes SQL d'agrégat qui lisent `companies` pendant l'exécution
 * de `$travail`. C'est la seule façon honnête de prouver qu'un cache sert : un
 * chronomètre sur une base de test vide ne prouverait rien du tout.
 */
function requetesDeComptage(callable $travail): int
{
    $vues = 0;

    DB::listen(function ($requete) use (&$vues): void {
        if (stripos($requete->sql, 'from "companies"') !== false && stripos($requete->sql, 'count(*)') !== false) {
            $vues++;
        }
    });

    $travail();

    return $vues;
}

// ── 1. PLAN — l'index couvrant est bien celui qui sert ───────────────────────

test('les compteurs sont servis par un index couvrant, jamais par un balayage', function () {
    compteursCompany($this->workspace->id, '910000001', ['relation_type' => 'client', 'lifecycle_stage' => 'client']);
    compteursCompany($this->workspace->id, '910000002', ['relation_type' => 'presse_media', 'lifecycle_stage' => 'qualifie']);

    // `enable_seqscan = off` : sur une table de test à deux lignes, PostgreSQL
    // préfère TOUJOURS le balayage — il a raison, deux lignes tiennent dans une
    // page. On lui retire ce choix pour lui poser la seule question qui compte
    // ici et qui vaudra à 4,29 M de lignes : « cette requête a-t-elle un index
    // qui la COUVRE ? ». Sans la migration `2026_08_19_000001`, la réponse est
    // non et le plan retombe sur `Seq Scan on companies` malgré la consigne —
    // c'est exactement la rougeur attendue.
    DB::statement('SET enable_seqscan = off');

    // ⚠️ `ANALYZE` AVANT DE LIRE LE PLAN — ce test etait INTERMITTENT sans lui.
    //
    // Mesure du 2026-08-21 : ce test a rougi en CI en attendant
    // `Index Only Scan using idx_companies_ws_counts` et en recevant
    // `Index Scan using idx_companies_workspace_relation_type` — sur un commit
    // qui ne touchait NI `companies`, NI ses index, NI ce fichier.
    //
    // La cause est la meme que celle mesuree le matin meme sur `C21-001` :
    // `RefreshDatabase` annule les DONNEES entre deux tests, il n'annule pas les
    // STATISTIQUES. Un test voisin qui insere dans `companies` laisse donc au
    // planificateur une idee fausse de la table, et le planificateur change
    // d'index — legitimement, sur des chiffres qui ne correspondent plus a rien.
    //
    // `ANALYZE` remet les statistiques en accord avec les deux lignes que ce
    // test vient d'ecrire. Le plan redevient une fonction de ce que le test
    // MONTRE, et non de l'ordre dans lequel Pest l'a tire.
    DB::statement('ANALYZE companies');

    $plan = collect(DB::select(
        'EXPLAIN SELECT relation_type, lifecycle_stage, count(*) AS total
           FROM companies
          WHERE workspace_id = ? AND deleted_at IS NULL
          GROUP BY relation_type, lifecycle_stage',
        [$this->workspace->id],
    ))->map(static fn ($ligne): string => (string) reset($ligne))->implode(PHP_EOL);

    DB::statement('SET enable_seqscan = on');

    expect($plan)->toContain('Index Only Scan using idx_companies_ws_counts');
    expect($plan)->not->toContain('Seq Scan on companies');
});

// ── 2. CACHE — le calcul sort du chemin d'affichage ──────────────────────────

test('deux affichages de suite ne recalculent pas les compteurs', function () {
    compteursCompany($this->workspace->id, '910000010', ['relation_type' => 'client', 'lifecycle_stage' => 'client']);

    $premier = requetesDeComptage(function (): void {
        $this->getJson('/api/v1/crm/contacts-hub/counts')->assertOk()->assertJsonPath('total', 1);
    });

    $second = requetesDeComptage(function (): void {
        $this->getJson('/api/v1/crm/contacts-hub/counts')->assertOk()->assertJsonPath('total', 1);
    });

    expect($premier)->toBe(1, 'le premier affichage calcule');
    expect($second)->toBe(0, 'le second est servi par le cache — sinon le balayage revient à chaque rendu');
});

test('la réponse dit à quand les chiffres remontent', function () {
    compteursCompany($this->workspace->id, '910000011');

    $reponse = $this->getJson('/api/v1/crm/contacts-hub/counts')->assertOk();

    // Un compteur en cache qui se présente comme instantané est un mensonge
    // d'interface : l'écran doit pouvoir écrire « chiffres arrêtés à … ».
    expect($reponse->json('computed_at'))->toBeString();
    expect($reponse->json('fresh_for_seconds'))->toBe(CompteursHub::FRAIS_SECONDES);
    // §29 n°16 : tout horodatage circule en temps universel.
    expect($reponse->json('computed_at'))->toEndWith('Z');
});

// ── 3. ÉTANCHÉITÉ — un compteur n'appartient qu'à son workspace ─────────────

test('le cache des compteurs ne fuit pas vers un autre workspace', function () {
    $autre = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'ws-compteurs-autre',
        'name' => 'Autre',
        'settings' => [],
    ]);

    compteursCompany($this->workspace->id, '910000020', ['relation_type' => 'client', 'lifecycle_stage' => 'client']);
    compteursCompany($autre->id, '910000021', ['relation_type' => 'client', 'lifecycle_stage' => 'client']);
    compteursCompany($autre->id, '910000022', ['relation_type' => 'client', 'lifecycle_stage' => 'client']);

    // Le premier passage remplit le cache pour le workspace courant.
    $this->getJson('/api/v1/crm/contacts-hub/counts')->assertOk()->assertJsonPath('total', 1);

    // L'autre workspace ne doit RIEN recevoir de ce cache — une clé sans
    // identifiant de workspace lui servirait « 1 » au lieu de « 2 ».
    $utilisateurAutre = compteursUser($autre->id, 'compteurs-autre@example.invalid');
    $this->actingAs($utilisateurAutre)
        ->getJson('/api/v1/crm/contacts-hub/counts')
        ->assertOk()
        ->assertJsonPath('total', 2);

    // Et dans l'autre sens : le premier workspace garde SES chiffres.
    $this->actingAs($this->user)
        ->getJson('/api/v1/crm/contacts-hub/counts')
        ->assertOk()
        ->assertJsonPath('total', 1);
});

// ── 4. FRAÎCHEUR — le produit ne contredit pas ce qu'on vient de faire ──────

test('une action de masse fait bouger la pastille tout de suite', function () {
    $id = compteursCompany($this->workspace->id, '910000030', ['relation_type' => 'client', 'lifecycle_stage' => 'nouveau']);

    $this->getJson('/api/v1/crm/contacts-hub/counts')
        ->assertOk()
        ->assertJsonPath('by_lifecycle_stage.nouveau', 1)
        ->assertJsonPath('by_lifecycle_stage.qualifie', 0);

    $this->postJson('/api/v1/crm/bulk', [
        'entity' => 'company',
        'ids' => [$id],
        'action' => 'set_lifecycle',
        'params' => ['stage' => 'qualifie'],
    ])->assertOk();

    // Sans l'oubli du cache après COMMIT, l'écran afficherait encore
    // « nouveau : 1 » pendant cinq minutes — le produit contredirait le geste
    // que l'opérateur vient de faire.
    $this->getJson('/api/v1/crm/contacts-hub/counts')
        ->assertOk()
        ->assertJsonPath('by_lifecycle_stage.nouveau', 0)
        ->assertJsonPath('by_lifecycle_stage.qualifie', 1);
});

// ── 5. RLS — le calcul ne dépend pas de la requête HTTP qui l'a déclenché ────

test('le calcul pose lui-même le contexte de workspace', function () {
    compteursCompany($this->workspace->id, '910000040', ['relation_type' => 'client', 'lifecycle_stage' => 'client']);

    // On se place dans la situation du rafraîchissement DIFFÉRÉ de
    // `Cache::flexible` : il s'exécute après la réponse, donc après le
    // `terminate()` du middleware `SetCurrentWorkspace`, qui a retiré
    // `app.current_workspace_id`. `companies` porte une policy RLS en FORCE :
    // sans contexte, le rôle applicatif voit ZÉRO ligne et le cache figerait
    // « total : 0 » pour une heure, sans erreur nulle part.
    WorkspaceContext::clear();
    expect(WorkspaceContext::current())->toBeNull();

    $contextePendantLaRequete = 'jamais observé';

    DB::listen(function ($requete) use (&$contextePendantLaRequete): void {
        if (stripos($requete->sql, 'from "companies"') !== false && stripos($requete->sql, 'count(*)') !== false) {
            $contextePendantLaRequete = WorkspaceContext::current();
        }
    });

    $compteurs = CompteursHub::calculer($this->workspace->id);

    expect($contextePendantLaRequete)->toBe($this->workspace->id);
    expect($compteurs['total'])->toBe(1);

    // Et l'état d'avant est rendu : le calcul ne laisse pas de contexte derrière
    // lui — un contexte résiduel serait pire que pas de contexte du tout.
    expect(WorkspaceContext::current())->toBeNull();
});
