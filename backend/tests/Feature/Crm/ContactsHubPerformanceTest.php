<?php

/**
 * GARDE : LA LISTE « CONTACTS » NE PARCOURT PLUS 4,3 M DE FICHES — constat
 * prod du 2026-10-02 (« la page ne charge jamais »).
 *
 * La vue par défaut (`temperature=actifs`) filtrait par
 *
 *     lifecycle_stage <> 'nouveau' OR EXISTS (… company_tag …)
 *     ORDER BY updated_at DESC, id DESC LIMIT 51
 *
 * Un OU entre une colonne et un sous-select corrélé ne se sert d'aucun index :
 * Postgres parcourait `idx_companies_ws_updated_id` dans l'ordre du tri en
 * testant chaque fiche (`Filter: … OR (hashed SubPlan …)`). UNE fiche active
 * sur 4 346 269 : tout l'index, plus de 100 s.
 *
 * Correctif : énumérer d'abord les actives (UNION de deux branches indexées),
 * puis trier ce petit ensemble — 2,2 ms mesurées en production.
 *
 * Trois gardes :
 *   1. JUSTESSE — la définition « actif / froid » est INCHANGÉE (audit §B.2) ;
 *   2. FORME    — la requête émise ne contient plus de `OR EXISTS`, ni de
 *                 sous-plan corrélé dans son plan ;
 *   3. PROJECTION — plus de `select *`, et chaque champ rendu reste rempli.
 */

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['crm.console_v2' => true]);

    $this->espace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'ws-perf-hub-' . Str::random(6),
        'name' => 'Perf hub',
        'settings' => [],
    ]);

    $utilisateur = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'perf-hub@example.invalid',
        'name' => 'Operateur perf',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $this->espace->id,
        'first_login_completed_at' => now(),
    ]);

    DB::table('user_workspaces')->insertOrIgnore([
        'user_id' => $utilisateur->id,
        'workspace_id' => $this->espace->id,
        'role_slug' => 'owner',
        'invited_at' => now(),
        'joined_at' => now(),
    ]);

    $this->actingAs($utilisateur);
});

/** @param  array<string, mixed>  $champs */
function perfHubFiche(string $espace, string $siren, array $champs = []): int
{
    return (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $espace,
        'denomination' => 'Fiche ' . $siren,
        'siren' => $siren,
        'relation_type' => 'prospect',
        'lifecycle_stage' => 'nouveau',
        'legal_basis' => 'legitimate_interest_b2b',
        'discovery_source' => 'site',
        'quality_score' => 0,
        'signals' => '{}', 'metadata' => '{}', 'field_origins' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ], $champs));
}

function perfHubEtiqueter(string $espace, int $fiche, string $slug): void
{
    $tag = DB::table('tags')->where('workspace_id', $espace)->where('slug', $slug)->value('id')
        ?? DB::table('tags')->insertGetId([
            'workspace_id' => $espace,
            'slug' => $slug, 'name' => $slug,
            'category' => 'custom', 'kind' => 'auto',
            'rules' => '{}', 'is_locked' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

    DB::table('company_tag')->insert([
        'company_id' => $fiche, 'tag_id' => $tag, 'workspace_id' => $espace,
        'assigned_at' => now(), 'assigned_by' => 'user',
    ]);
}

/**
 * Le jeu : deux actives (une par branche), deux froides, une active supprimée.
 *
 * @return array{froide: int, froideScrapee: int, activeEtape: int, activeProvenance: int, supprimee: int}
 */
function perfHubJeu(string $espace): array
{
    $froide = perfHubFiche($espace, '800000001');
    $froideScrapee = perfHubFiche($espace, '800000002');
    perfHubEtiqueter($espace, $froideScrapee, 'src:scraping-pages-jaunes');

    $activeEtape = perfHubFiche($espace, '800000003', [
        'lifecycle_stage' => 'qualifie',
        'city_name' => 'Lyon',
        'department_code' => '69',
        'size_category' => 'pme',
    ]);

    $activeProvenance = perfHubFiche($espace, '800000004');
    perfHubEtiqueter($espace, $activeProvenance, 'src:scraping-pages-jaunes');
    perfHubEtiqueter($espace, $activeProvenance, 'src:site-formulaire-audit');

    $supprimee = perfHubFiche($espace, '800000005', ['lifecycle_stage' => 'client', 'deleted_at' => now()]);

    return compact('froide', 'froideScrapee', 'activeEtape', 'activeProvenance', 'supprimee');
}

/** @return list<int> */
function perfHubIds(object $test, string $url): array
{
    $ids = array_map('intval', array_column($test->getJson($url)->assertOk()->json('data'), 'id'));
    sort($ids);

    return $ids;
}

/** Le SQL littéral de la requête de liste réellement émise. */
function perfHubSql(object $test, string $url): string
{
    $requetes = [];
    DB::listen(function ($r) use (&$requetes): void {
        $requetes[] = $r;
    });

    $test->getJson($url)->assertOk();

    $liste = array_values(array_filter(
        $requetes,
        static fn ($r): bool => str_starts_with(strtolower(ltrim($r->sql)), 'select')
            && str_contains($r->sql, 'from "companies"'),
    ));

    expect($liste)->not->toBeEmpty('Aucune requête de liste interceptée : la garde ne mesure rien.');
    $derniere = end($liste);

    return DB::connection()->getQueryGrammar()->substituteBindingsIntoRawSql($derniere->sql, $derniere->bindings);
}

// ── 1. JUSTESSE ─────────────────────────────────────────────────────────────

test('actifs = etape au-dela de nouveau OU provenance humaine — definition inchangee', function () {
    $j = perfHubJeu($this->espace->id);

    $attendu = [$j['activeEtape'], $j['activeProvenance']];
    sort($attendu);

    expect(perfHubIds($this, '/api/v1/crm/contacts-hub'))->toBe($attendu);
    expect(perfHubIds($this, '/api/v1/crm/contacts-hub?temperature=actifs'))->toBe($attendu);
});

test('froids = nouveau ET aucune provenance humaine — et tous = tout sauf les supprimees', function () {
    $j = perfHubJeu($this->espace->id);

    $froids = [$j['froide'], $j['froideScrapee']];
    sort($froids);
    expect(perfHubIds($this, '/api/v1/crm/contacts-hub?temperature=froids'))->toBe($froids);

    $tous = [$j['froide'], $j['froideScrapee'], $j['activeEtape'], $j['activeProvenance']];
    sort($tous);
    expect(perfHubIds($this, '/api/v1/crm/contacts-hub?temperature=tous'))->toBe($tous);
});

test('actifs sans AUCUNE etiquette de provenance humaine dans l espace : l etape seule decide', function () {
    $active = perfHubFiche($this->espace->id, '800000010', ['lifecycle_stage' => 'client']);
    perfHubFiche($this->espace->id, '800000011');

    expect(perfHubIds($this, '/api/v1/crm/contacts-hub'))->toBe([$active]);
});

test('les filtres se combinent avec la vue active (type de relation)', function () {
    $j = perfHubJeu($this->espace->id);
    DB::table('companies')->where('id', $j['activeEtape'])->update(['relation_type' => 'client']);

    expect(perfHubIds($this, '/api/v1/crm/contacts-hub?relation_type=client'))->toBe([$j['activeEtape']]);
});

// ── 2. FORME ────────────────────────────────────────────────────────────────

test('la vue par defaut n emet plus de OR EXISTS : elle enumere les actives en UNION', function () {
    perfHubJeu($this->espace->id);

    $sql = strtolower(perfHubSql($this, '/api/v1/crm/contacts-hub'));

    $this->assertStringNotContainsString(' or exists', $sql, "Retour du OR + EXISTS non indexable (prod : > 100 s).\n\n{$sql}");
    expect($sql)->not->toContain('<> \'nouveau\'');
    expect($sql)->not->toContain('!= \'nouveau\'');
    expect($sql)->toContain(' union ');
});

test('le plan de la vue par defaut n a plus de sous-plan correle', function () {
    perfHubJeu($this->espace->id);
    DB::statement('ANALYZE companies');
    DB::statement('ANALYZE company_tag');

    $sql = perfHubSql($this, '/api/v1/crm/contacts-hub');
    $plan = collect(DB::select('EXPLAIN ' . $sql))->map(fn ($l) => (array) $l)->flatten()->implode("\n");

    // Le défaut avait une signature exacte : `Filter: (… OR (hashed SubPlan N))`
    // sur le parcours de l'index de tri. Un `IN (… UNION …)` devient une
    // semi-jointure, sans sous-plan.
    $this->assertStringNotContainsString('SubPlan', $plan, "Sous-plan corrélé dans le plan de l'écran d'accueil.\n\n{$plan}");
});

// ── 3. PROJECTION ───────────────────────────────────────────────────────────

test('la liste ne lit plus toutes les colonnes, et chaque champ affiche reste rempli', function () {
    $j = perfHubJeu($this->espace->id);

    $sql = strtolower(perfHubSql($this, '/api/v1/crm/contacts-hub'));
    expect($sql)->not->toContain('select * from "companies"');

    $ligne = collect($this->getJson('/api/v1/crm/contacts-hub')->assertOk()->json('data'))
        ->firstWhere('id', $j['activeEtape']);

    // Une colonne oubliée dans `COLONNES_LISTE` sortirait `null` SANS erreur :
    // c'est exactement ce que ces lignes attrapent.
    expect($ligne['siren'])->toBe('800000003');
    expect($ligne['denomination'])->toBe('Fiche 800000003');
    expect($ligne['lifecycle_stage'])->toBe('qualifie');
    expect($ligne['relation_type'])->toBe('prospect');
    expect($ligne['legal_basis'])->toBe('legitimate_interest_b2b');
    expect($ligne['city_name'])->toBe('Lyon');
    expect($ligne['department_code'])->toBe('69');
    expect($ligne['size_category'])->toBe('pme');
    expect($ligne['updated_at'])->not->toBeNull();
});

test('la pagination par curseur traverse la vue active sans perte ni doublon', function () {
    $ids = [];
    for ($i = 0; $i < 7; $i++) {
        $ids[] = perfHubFiche($this->espace->id, (string) (810000000 + $i), [
            'lifecycle_stage' => 'qualifie',
            'updated_at' => now()->subMinutes($i % 3),
        ]);
    }
    perfHubFiche($this->espace->id, '819999999');

    $vus = [];
    $url = '/api/v1/crm/contacts-hub?per_page=3';
    for ($tour = 0; $tour < 5; $tour++) {
        $reponse = $this->getJson($url)->assertOk();
        foreach ($reponse->json('data') as $ligne) {
            $vus[] = (int) $ligne['id'];
        }
        $suivant = $reponse->json('meta.next_cursor');
        if ($suivant === null) {
            break;
        }
        $url = '/api/v1/crm/contacts-hub?per_page=3&cursor=' . urlencode((string) $suivant);
    }

    sort($ids);
    sort($vus);
    expect($vus)->toBe($ids);
});
