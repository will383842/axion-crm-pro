<?php

/**
 * GARDE : LE TOTAL DE LA PAGINATION NE SE RECALCULE PAS A CHAQUE AFFICHAGE
 * — constat `G41-006` (S1, audit 360).
 *
 * ── LE DEFAUT, MESURE, PAS SUPPOSE ──────────────────────────────────────────
 *
 * `CompaniesController::index()` appelait `->paginate($perPage)`. Laravel emet
 * TOUJOURS un `select count(*)` complet avant d'aller chercher la page
 * (`Eloquent\Builder::paginate()`, l. 1120 du vendor :
 * `$total = value($total) ?? $this->toBase()->getCountForPagination();`).
 *
 * Rejoue le 2026-08-20 sur `axion_crm_perf4m` (2 800 000 fiches, un seul
 * workspace), les DEUX requetes au MEME instant, sur la MEME table :
 *
 *     select count(*) ... where deleted_at is null and workspace_id = ?
 *       -> Parallel Index Only Scan using idx_companies_ws_counts
 *          Heap Fetches: 0     Buffers: shared read=2472
 *          Execution Time: 1 818,064 ms (froid) / 490,431 ms (chaud)
 *
 *     select * ... order by quality_score desc limit 25 offset 0
 *       -> Index Scan using idx_companies_workspace_score
 *          Buffers: shared read=4
 *          Execution Time: 3,310 ms
 *
 * Soit **148 fois** le prix de la page a chaud, **550 fois** a froid, pour un
 * nombre que personne ne lit a l'unite pres. L'audit annoncait 2 193 ms ; je
 * mesure 1 818 ms sur le meme jeu, meme ordre de grandeur.
 *
 * 🔑 ET LE POINT QUI TRANCHE LE CORRECTIF : ce `count(*)` est DEJA servi par un
 * `Index Only Scan` sur le meilleur index possible (`idx_companies_ws_counts`,
 * 20 Mo, pose le 2026-08-19 pour les pastilles du hub). **Aucun index ne peut
 * donc le reparer** : compter 2,8 M de lignes exige de visiter 2,8 M d'entrees
 * d'index, par construction. La piste « poser un index » est refutee par la
 * mesure, pas par un raisonnement.
 *
 * ── CE QUE CETTE GARDE MESURE, ET POURQUOI PAS UN CHRONOMETRE ────────────────
 *
 * Un chronometre sur la base de test ne prouverait rien : elle porte quelques
 * lignes, et un `count(*)` y coute 0,2 ms. Pire, il mesurerait l'histoire du
 * banc (pages du fichier, statistiques) et non le produit. On compte donc les
 * REQUETES `count(*)` emises sur `companies` pendant l'affichage — c'est le
 * meme instrument que `CompteursHubTest`, qui a servi pour `G41-001`.
 *
 * Cinq gardes, et deux d'entre elles sont des TEMOINS :
 *
 *   1. TEMOIN     — l'instrument voit bien un `count(*)` la ou il y en a un ;
 *                   sans lui, tout ce fichier serait vert sur une base muette.
 *   2. CACHE      — deux affichages de suite n'emettent pas deux `count(*)`.
 *   3. TEMOIN     — un FILTRE different a son propre total : le cache est
 *                   indexe sur la requete, pas global.
 *   4. ETANCHEITE — le total ne fuit jamais d'un espace de travail a l'autre.
 *   5. CONTRAT    — la forme de la reponse (`total`, `per_page`,
 *                   `current_page`, `last_page`) est INCHANGEE, et les valeurs
 *                   sont JUSTES. C'est ce qui interdit un passage a
 *                   `simplePaginate()`, qui aurait supprime `total` et
 *                   `last_page` — donc casse `CompaniesListPage.tsx:751`.
 */

use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use App\Support\TotalListe;
use App\Support\WorkspaceContext;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    Cache::flush();

    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-total', 'name' => 'Total', 'settings' => [],
    ]);
    $this->user = totalListeUser($this->workspace->id, 'total@example.invalid');
    $this->actingAs($this->user);
});

// ── Fabriques locales : un test ne depend jamais d'un autre fichier ──────────

function totalListeUser(string $workspaceId, string $email): User
{
    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => $email,
        'name' => 'Operateur',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $workspaceId,
        'first_login_completed_at' => now(),
    ]);

    DB::table('user_workspaces')->insertOrIgnore([
        'user_id' => $user->id,
        'workspace_id' => $workspaceId,
        'role_slug' => 'admin',
        'invited_at' => now(),
        'joined_at' => now(),
    ]);

    setPermissionsTeamId($workspaceId);
    $user->assignRole('admin');

    return $user;
}

/** @param  array<string, mixed>  $overrides */
function totalListeCompany(string $workspaceId, string $siren, array $overrides = []): int
{
    return (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $workspaceId,
        'siren' => $siren,
        'denomination' => 'ZZ TOTAL ' . $siren,
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
 * Compte les `count(*)` emis sur `companies` pendant `$travail`.
 *
 * C'est la seule facon honnete de prouver qu'un total sort du chemin
 * d'affichage. Le motif cherche est celui que Laravel compile lui-meme dans
 * `runPaginationCountQuery()` : `select count(*) as aggregate from "companies"`.
 */
function countsDePagination(callable $travail): int
{
    $vus = 0;

    DB::listen(function ($requete) use (&$vus): void {
        $sql = strtolower($requete->sql);
        if (str_contains($sql, 'from "companies"') && str_contains($sql, 'count(*)')) {
            $vus++;
        }
    });

    $travail();

    return $vus;
}

// ── 1. TEMOIN — l'instrument voit un count(*) la ou il y en a un ─────────────

test('G41-006 — TEMOIN : le compteur de requetes voit bien le count(*) de la pagination', function () {
    totalListeCompany($this->workspace->id, '920000001');

    // PREMIER affichage, cache vide : le total DOIT etre calcule, donc un
    // `count(*)` DOIT partir. Si ce nombre etait zero, la garde n°2 ci-dessous
    // serait verte pour une raison sans rapport -- table absente, route qui
    // n'emet rien, ecouteur mal branche -- et ne prouverait RIEN.
    $premier = countsDePagination(function (): void {
        $this->getJson('/api/v1/companies?per_page=25')->assertOk();
    });

    expect($premier)->toBeGreaterThanOrEqual(
        1,
        "L'ecouteur SQL n'a vu aucun count(*) sur `companies` alors que le cache etait vide. "
        . "L'instrument ne mesure rien, et tout ce fichier avec lui.",
    );
});

// ── 2. LE CONSTAT — deux affichages, un seul comptage ────────────────────────

test('G41-006 — deux affichages de la liste entreprises ne comptent pas deux fois', function () {
    totalListeCompany($this->workspace->id, '920000010');
    totalListeCompany($this->workspace->id, '920000011');

    $premier = countsDePagination(function (): void {
        $this->getJson('/api/v1/companies?per_page=25')->assertOk()->assertJsonPath('meta.total', 2);
    });

    $second = countsDePagination(function (): void {
        $this->getJson('/api/v1/companies?per_page=25')->assertOk()->assertJsonPath('meta.total', 2);
    });

    expect($premier)->toBe(1, 'le premier affichage calcule le total : c\'est normal, et c\'est le plancher');
    expect($second)->toBe(
        0,
        "Le second affichage a REEMIS un count(*) complet sur `companies`. C'est le constat "
        . 'G41-006 : 1 818 ms a froid / 490 ms a chaud sur les 2 800 000 fiches de '
        . '`axion_crm_perf4m`, contre 3,3 ms pour la page elle-meme, au meme instant. '
        . 'Et cet appel est deja servi par un Index Only Scan : aucun index ne le reparera.',
    );
});

// ── 3. TEMOIN — le total appartient a SA requete, pas a l'ecran ──────────────

test('G41-006 — TEMOIN : un filtre different obtient son propre total', function () {
    totalListeCompany($this->workspace->id, '920000020', ['naf' => '6201Z']);
    totalListeCompany($this->workspace->id, '920000021', ['naf' => '6201Z']);
    totalListeCompany($this->workspace->id, '920000022', ['naf' => '4711D']);

    $this->getJson('/api/v1/companies?per_page=25')->assertOk()->assertJsonPath('meta.total', 3);

    // Si le total etait garde sous une cle qui ignore les filtres, cette
    // seconde liste rendrait « 3 » -- et l'ecran annoncerait trois fiches en
    // n'en affichant que deux. Un cache mal indexe est PIRE que pas de cache.
    $this->getJson('/api/v1/companies?per_page=25&filter[naf]=6201Z')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    $this->getJson('/api/v1/companies?per_page=25&filter[naf]=4711D')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    // Et la liste non filtree n'a pas ete abimee au passage.
    $this->getJson('/api/v1/companies?per_page=25')->assertOk()->assertJsonPath('meta.total', 3);
});

// ── 4. ETANCHEITE — un total n'appartient qu'a son espace de travail ────────

test('G41-006 — le total ne fuit pas d un espace de travail a l autre', function () {
    $autre = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-total-autre', 'name' => 'Autre', 'settings' => [],
    ]);

    totalListeCompany($this->workspace->id, '920000030');
    totalListeCompany($autre->id, '920000031');
    totalListeCompany($autre->id, '920000032');
    totalListeCompany($autre->id, '920000033');

    $this->getJson('/api/v1/companies?per_page=25')->assertOk()->assertJsonPath('meta.total', 1);

    // Une cle sans identifiant d'espace servirait « 1 » a l'autre locataire :
    // ce serait un defaut d'ISOLATION introduit pour reparer une lenteur, donc
    // un tres mauvais echange.
    $utilisateurAutre = totalListeUser($autre->id, 'total-autre@example.invalid');
    $this->actingAs($utilisateurAutre)
        ->getJson('/api/v1/companies?per_page=25')
        ->assertOk()
        ->assertJsonPath('meta.total', 3);

    setPermissionsTeamId($this->workspace->id);
    $this->actingAs($this->user)
        ->getJson('/api/v1/companies?per_page=25')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

// ── 5. CONTRAT — la forme de la reponse ne bouge pas d'un octet ─────────────

test('G41-006 — la reponse garde total, per_page, current_page et last_page', function () {
    foreach (range(1, 7) as $n) {
        totalListeCompany($this->workspace->id, '92000004' . $n);
    }

    $reponse = $this->getJson('/api/v1/companies?per_page=3&page=2')->assertOk();

    // `simplePaginate()` (la piste que l'audit proposait) supprime `total` ET
    // `last_page`. `CompaniesListPage.tsx:751` passe `lastPage` au composant
    // `Pagination` et `total` a la tuile de KPI : la liste perdrait sa
    // pagination par numero de page ET son compteur. Cette garde interdit ce
    // chemin -- le correctif doit tenir le contrat, pas le raboter.
    expect($reponse->json('meta.total'))->toBe(7);
    expect($reponse->json('meta.per_page'))->toBe(3);
    expect($reponse->json('meta.current_page'))->toBe(2);
    expect($reponse->json('meta.last_page'))->toBe(3);
    expect($reponse->json('data'))->toHaveCount(3);
});

// ── 6. FRAICHEUR — le produit ne contredit pas le geste qu'on vient de faire ─

test('G41-006 — une creation fait bouger le total tout de suite', function () {
    totalListeCompany($this->workspace->id, '920000050');

    $this->getJson('/api/v1/companies?per_page=25')->assertOk()->assertJsonPath('meta.total', 1);

    $this->postJson('/api/v1/companies', ['siren' => '920000051'])->assertCreated();

    // Sans oubli du total apres l'ecriture, l'ecran afficherait encore « 1 »
    // pendant toute la fenetre de fraicheur : le produit contredirait la fiche
    // que l'operateur vient de creer et qui s'affiche sous ses yeux.
    $this->getJson('/api/v1/companies?per_page=25')->assertOk()->assertJsonPath('meta.total', 2);
});

// ── 7. RLS — le calcul differe pose lui-meme son contexte d'espace ──────────

test('G41-006 — le calcul du total pose lui-meme le contexte de workspace', function () {
    totalListeCompany($this->workspace->id, '920000060');

    // Situation du rafraichissement DIFFERE de `Cache::flexible` : il part
    // apres la reponse, donc apres le `terminate()` de `SetCurrentWorkspace`,
    // qui a retire `app.current_workspace_id`. `companies` porte une policy RLS
    // en FORCE : sans contexte, le role applicatif voit ZERO ligne et le total
    // se figerait a 0 pour toute la fenetre de peremption -- un ecran vide,
    // sans erreur nulle part. C'est exactement le piege paye sur
    // `CompteursHub` le 2026-08-19 ; on porte la lecon ici.
    WorkspaceContext::clear();
    expect(WorkspaceContext::current())->toBeNull();

    $contextePendantLaRequete = 'jamais observe';

    DB::listen(function ($requete) use (&$contextePendantLaRequete): void {
        $sql = strtolower($requete->sql);
        if (str_contains($sql, 'from "companies"') && str_contains($sql, 'count(*)')) {
            $contextePendantLaRequete = WorkspaceContext::current();
        }
    });

    $total = TotalListe::pour(
        Company::query()->whereNull('deleted_at')->where('workspace_id', $this->workspace->id)->toBase(),
        $this->workspace->id,
    );

    expect($contextePendantLaRequete)->toBe($this->workspace->id);
    expect($total)->toBe(1);

    // Et l'etat d'avant est rendu : un contexte residuel serait pire que pas de
    // contexte du tout.
    expect(WorkspaceContext::current())->toBeNull();
});
