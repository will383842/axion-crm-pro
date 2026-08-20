<?php

/**
 * GARDE DE L'ÉCRAN D'ACCUEIL ET DE LA RECHERCHE — constats P6-UI-001 et
 * P6-UI-002 (S0), relevés par la passe P6 « regard neuf ».
 *
 * CE QUE LA PASSE À REGARD NEUF A TROUVÉ, ET QUE 46 AGENTS AVAIENT MANQUÉ.
 *
 * `GET /dashboard/stats` et `GET /search` n'étaient pas des contrôleurs : c'étaient
 * **deux closures dans `routes/api.php` qui renvoyaient des zéros et des tableaux
 * vides écrits en dur**. Aucun `DashboardController` n'existait.
 *
 *   - `DashboardPage.tsx` teste `companies_total === 0` pour décider d'afficher
 *     son état vide. **L'écran d'accueil du CRM annonçait donc en permanence
 *     « Lance ton premier scrape — aucune entreprise collectée »**, sur une base
 *     de 4,29 millions de fiches. Les quatre vignettes, les trois graphiques et
 *     les deux cartes latérales étaient du code injoignable.
 *   - La palette ⌘K, présente sur tous les écrans et vantée par la visite
 *     guidée, **ne pouvait rien trouver**.
 *
 * 🔑 **POURQUOI PERSONNE NE L'AVAIT VU.** Le mandat de l'audit exige (§12, point 3)
 * que chaque écran soit ouvert à la main dans un vrai navigateur. *La console ne
 * tourne pas.* Le défaut qui saute aux yeux en trois secondes d'usage a donc
 * survécu à un audit de 46 agents. Et le test e2e de la recherche **mocke
 * l'endpoint** — *un test qui mocke précisément la pièce qui n'existe pas
 * certifie son existence.*
 *
 * CE QUE CETTE GARDE MESURE.
 *
 * Pas la présence d'un contrôleur : **ce que la route rend, sur des données
 * réelles**. On crée des lignes, on appelle, on compare. Un contrôleur qui
 * existerait en renvoyant des zéros échouerait ici exactement comme la closure.
 *
 * Et elle mesure aussi le **cloisonnement** — leçon du jour : les listes
 * fuyaient parce que la garde de complétude n'énumérait que les méthodes
 * recevant un modèle par liaison de route.
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

/** @return array{0: User, 1: string} */
function compteAccueil(string $nom): array
{
    $espace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => strtolower($nom) . '-' . Str::random(8),
        'name' => $nom,
    ]);

    $compte = User::create([
        'id' => (string) Str::uuid(),
        'email' => strtolower($nom) . '-' . Str::random(6) . '@accueil.test',
        'name' => $nom,
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $espace->id,
        'first_login_completed_at' => now(),
    ]);

    setPermissionsTeamId($espace->id);
    $compte->assignRole('admin');

    return [$compte, (string) $espace->id];
}

function entrepriseAccueil(string $espaceId, string $denomination, ?string $taille = null): int
{
    return (int) DB::table('companies')->insertGetId([
        'workspace_id' => $espaceId,
        'denomination' => $denomination,
        'siren' => (string) random_int(100000000, 999999999),
        'size_category' => $taille,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// P6-UI-001 — l'écran d'accueil
// ─────────────────────────────────────────────────────────────────────────────

test('P6-UI-001 — /dashboard/stats compte les entreprises REELLES, pas zero', function () {
    [$compte, $espace] = compteAccueil('ALPHA');

    entrepriseAccueil($espace, 'Alpha Un', 'pme');
    entrepriseAccueil($espace, 'Alpha Deux', 'pme');
    entrepriseAccueil($espace, 'Alpha Trois', 'tpe');

    $reponse = $this->actingAs($compte)->getJson('/api/v1/dashboard/stats');
    $reponse->assertOk();

    // C'EST L'ASSERTION QUI COMPTE. `DashboardPage.tsx` teste
    // `companies_total === 0` pour afficher « aucune entreprise collectee ».
    // Tant que cette route rend 0, l'ecran d'accueil ment a son utilisateur.
    expect($reponse->json('companies_total'))->toBe(
        3,
        "L'ecran d'accueil affichera « Lance ton premier scrape — aucune entreprise "
        . "collectee » alors que la base en porte trois. En production, elle en porte "
        . '4 295 349.'
    );

    // La repartition par taille doit refleter les donnees, pas un gabarit fige.
    expect($reponse->json('size_distribution.pme'))->toBe(2);
    expect($reponse->json('size_distribution.tpe'))->toBe(1);
});

test('P6-UI-001 — TEMOIN : une base VIDE rend bien zero, et c est legitime', function () {
    [$compte] = compteAccueil('VIDE');

    // Sans ce temoin, un correctif qui renverrait un nombre arbitraire non nul
    // passerait le test precedent. L'etat vide doit rester atteignable : c'est
    // un vrai etat du produit, pas un defaut.
    $reponse = $this->actingAs($compte)->getJson('/api/v1/dashboard/stats');
    $reponse->assertOk();
    expect($reponse->json('companies_total'))->toBe(0);
});

test('P6-UI-001 — /dashboard/stats ne compte PAS les entreprises d un autre espace', function () {
    [, $espaceA] = compteAccueil('ALPHA');
    [$b, $espaceB] = compteAccueil('BETA');

    entrepriseAccueil($espaceA, 'Alpha Un');
    entrepriseAccueil($espaceA, 'Alpha Deux');
    entrepriseAccueil($espaceB, 'Beta Un');

    // Lecon du jour : quatre LISTES fuyaient parce que la garde de completude
    // n'enumerait que les methodes recevant un modele par liaison de route. Un
    // compteur est encore plus discret qu'une liste -- il ne montre rien, il
    // REVELE UN VOLUME. On le mesure donc explicitement.
    $reponse = $this->actingAs($b)->getJson('/api/v1/dashboard/stats');
    $reponse->assertOk();

    expect($reponse->json('companies_total'))->toBe(
        1,
        "Le compteur d'accueil revele le volume d'activite d'un AUTRE client. "
        . "Un compteur ne montre aucune fiche, mais il en dit le nombre."
    );
});

test('P6-UI-001 — la forme du contrat attendue par l ecran est respectee', function () {
    [$compte] = compteAccueil('FORME');

    // `DashboardPage.tsx` lit ces clés. Une clé absente rend `undefined`, que
    // l'ecran affiche en `NaN` ou fait planter au calcul de moyenne.
    $this->actingAs($compte)->getJson('/api/v1/dashboard/stats')
        ->assertOk()
        ->assertJsonStructure([
            'companies_total',
            'companies_enriched_24h',
            'contacts_qualified',
            'scraper_runs_24h',
            'llm_cost_eur_month',
            'quality_distribution' => ['complete', 'partielle', 'basique'],
            'size_distribution',
        ]);
});

// ─────────────────────────────────────────────────────────────────────────────
// P6-UI-002 — la palette ⌘K
// ─────────────────────────────────────────────────────────────────────────────

test('P6-UI-002 — /search retrouve une entreprise par sa denomination', function () {
    [$compte, $espace] = compteAccueil('ALPHA');

    entrepriseAccueil($espace, 'Boulangerie Martin');
    entrepriseAccueil($espace, 'Garage Dupont');

    $reponse = $this->actingAs($compte)->getJson('/api/v1/search?q=Martin');
    $reponse->assertOk();

    $noms = collect($reponse->json('companies'))->pluck('denomination')->all();

    $this->assertContains(
        'Boulangerie Martin',
        $noms,
        'La palette ⌘K est presente sur TOUS les ecrans et vantee par la visite guidee. '
        . "Tant que cette route rend un tableau vide, elle ne peut RIEN trouver."
    );
    $this->assertNotContains('Garage Dupont', $noms, 'La recherche ne filtre pas : elle rend tout.');
});

test('P6-UI-002 — /search retrouve une personne par son nom', function () {
    [$compte, $espace] = compteAccueil('ALPHA');

    $entreprise = entrepriseAccueil($espace, 'Employeur');
    DB::table('contacts')->insert([
        'workspace_id' => $espace,
        'company_id' => $entreprise,
        'first_name' => 'Jeanne',
        'last_name' => 'Bernadette',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $reponse = $this->actingAs($compte)->getJson('/api/v1/search?q=Bernadette');
    $reponse->assertOk();

    expect(collect($reponse->json('contacts'))->pluck('last_name')->all())
        ->toContain('Bernadette');
});

test('P6-UI-002 — /search ne rend RIEN d un autre espace de travail', function () {
    [, $espaceA] = compteAccueil('ALPHA');
    [$b, $espaceB] = compteAccueil('BETA');

    entrepriseAccueil($espaceA, 'Secret Alpha SARL');
    entrepriseAccueil($espaceB, 'Public Beta SARL');

    $reponse = $this->actingAs($b)->getJson('/api/v1/search?q=SARL');
    $reponse->assertOk();

    $noms = collect($reponse->json('companies'))->pluck('denomination')->all();

    // TEMOIN INTEGRE : B doit voir la sienne, sinon on ne prouverait que la
    // panne de la route.
    $this->assertContains('Public Beta SARL', $noms);
    $this->assertNotContains(
        'Secret Alpha SARL',
        $noms,
        "La recherche globale rend une fiche d'un AUTRE espace. Une palette de recherche "
        . 'est le pire endroit ou fuir : elle balaie tout, sur une saisie libre.'
    );
});

test('P6-UI-002 — TEMOIN : sous deux caracteres, on ne balaie pas la base', function () {
    [$compte, $espace] = compteAccueil('ALPHA');
    entrepriseAccueil($espace, 'Alpha Un');

    // Le frontend s'en garde deja (`if (search.length < 2)`), mais une garde
    // cote client n'est pas une garde : l'API est appelable directement.
    $reponse = $this->actingAs($compte)->getJson('/api/v1/search?q=a');
    $reponse->assertOk();

    expect($reponse->json('companies'))->toBe([]);
    expect($reponse->json('contacts'))->toBe([]);
    expect($reponse->json('tags'))->toBe([]);
});
