<?php

use App\Crm\Taxonomy;
use App\Models\Candidate;
use Database\Seeders\GovernedTagsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * SOCLE DE DONNÉES CRM (lot L1) — taxonomie fermée, vivier étanche, RGPD.
 *
 * Ces tests attaquent les CONTRAINTES, pas le code applicatif : une taxonomie
 * « fermée » qu'un ORM peut contourner n'est pas fermée. Ils comparent aussi
 * les CHECK réellement posés en base à `App\Crm\Taxonomy` — ajouter une valeur
 * dans le PHP sans écrire la migration correspondante fait donc rougir la CI.
 */
function socleVivierWorkspaceId(): string
{
    return (string) DB::table('workspaces')
        ->where('slug', Taxonomy::VIVIER_WORKSPACE_SLUG)
        ->value('id');
}

function socleMakeBusinessWorkspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $id,
        'slug' => 'business-' . substr($id, 0, 8),
        'name' => 'Business',
        'settings' => '{}',
        'cost_cap_eur' => 100,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function socleCandidateRow(string $workspaceId, array $overrides = []): array
{
    return array_merge([
        'workspace_id' => $workspaceId,
        'last_name' => 'ZZ TEST',
        'relation_type' => 'candidat_commercial',
        'lifecycle_stage' => 'nouveau',
        'legal_basis' => 'precontractual',
        'attributes' => '{}',
        'experiences' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

/**
 * Valeurs autorisées par un CHECK, extraites de sa définition Postgres.
 *
 * @return list<string>
 */
function socleCheckValues(string $constraint): array
{
    $row = DB::selectOne(
        'SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = ?',
        [$constraint],
    );

    expect($row)->not->toBeNull("La contrainte {$constraint} n'existe pas en base.");

    // Les littéraux d'un CHECK sont typés par Postgres selon la colonne :
    // `'prospect'::text` sur une colonne text, `'geo'::character varying` sur
    // un varchar. On capture donc la chaîne, pas son cast.
    preg_match_all("/'([^']*)'::/", (string) $row->def, $matches);
    $values = array_values(array_unique($matches[1]));
    sort($values);

    return $values;
}

/**
 * @param  list<string>  $expected
 */
function socleExpectCheck(string $constraint, array $expected): void
{
    $sorted = $expected;
    sort($sorted);

    expect(socleCheckValues($constraint))->toBe($sorted);
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. La taxonomie en base EST celle du code (source de vérité unique)
// ─────────────────────────────────────────────────────────────────────────────

test('les CHECK en base correspondent exactement à App\\Crm\\Taxonomy', function () {
    socleExpectCheck('companies_relation_type_check', Taxonomy::BUSINESS_RELATION_TYPES);
    socleExpectCheck('companies_lifecycle_stage_check', Taxonomy::BUSINESS_LIFECYCLE_STAGES);
    socleExpectCheck('candidates_relation_type_check', Taxonomy::CANDIDATE_RELATION_TYPES);
    socleExpectCheck('candidates_lifecycle_stage_check', Taxonomy::CANDIDATE_LIFECYCLE_STAGES);
    socleExpectCheck('companies_legal_basis_check', Taxonomy::LEGAL_BASES);
    socleExpectCheck('contacts_legal_basis_check', Taxonomy::LEGAL_BASES);
    socleExpectCheck('activities_kind_check', Taxonomy::ACTIVITY_KINDS);
    socleExpectCheck('tags_category_check', Taxonomy::TAG_CATEGORIES);
    socleExpectCheck('opt_out_scope_check', ['business', 'vivier']);
});

test('Podcast et Autres sont des VUES, pas des types de relation', function () {
    // Mapping du préambule de préséance du plan : Podcast = vue par tag
    // `src:site-formulaire-podcast` ; Autres = vue par défaut (`prospect` sans
    // tag `svc:`). Les transformer en valeurs d'enum créerait deux types que
    // plus rien ne pourrait faire évoluer.
    foreach (['podcast', 'autres', 'autre', 'recrutement'] as $interdit) {
        expect(Taxonomy::BUSINESS_RELATION_TYPES)->not->toContain($interdit);
    }

    expect(array_keys(Taxonomy::TAG_NAMESPACES))->toContain('src');
});

test('le référentiel de tags gouvernés est entièrement namespacé et catégorisé', function () {
    $fautifs = [];

    foreach (GovernedTagsSeeder::referential() as $slug => $tag) {
        $namespace = str_contains($slug, ':') ? explode(':', $slug, 2)[0] : null;

        if ($namespace === null || ! array_key_exists($namespace, Taxonomy::TAG_NAMESPACES)) {
            $fautifs[] = $slug . ' (namespace hors référentiel)';

            continue;
        }
        if (Taxonomy::TAG_NAMESPACES[$namespace] !== $tag['category']) {
            $fautifs[] = $slug . ' (catégorie ' . $tag['category'] . ' au lieu de ' . Taxonomy::TAG_NAMESPACES[$namespace] . ')';
        }
    }

    expect($fautifs)->toBe([]);
});

test('les familles de métiers candidats forment une liste FERMÉE de quatre valeurs', function () {
    expect(Taxonomy::CANDIDATE_RELATION_TYPES)->toBe([
        'candidat_commercial',
        'candidat_video',
        'candidat_tech',
        'candidat_autre',
    ]);
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. Frontière entre univers : infranchissable par simple écriture
// ─────────────────────────────────────────────────────────────────────────────

test('une company ne peut PAS porter un relation_type candidat', function () {
    $ws = socleMakeBusinessWorkspace();

    $insert = fn () => DB::table('companies')->insert([
        'workspace_id' => $ws,
        'siren' => '900000001',
        'denomination' => 'ZZ TEST',
        'signals' => '{}',
        'metadata' => '{}',
        'quality_score' => 0,
        'relation_type' => 'candidat_commercial',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($insert)->toThrow(QueryException::class);
});

test('un candidat ne peut PAS porter un relation_type business', function () {
    $insert = fn () => DB::table('candidates')->insert(
        socleCandidateRow(socleVivierWorkspaceId(), ['relation_type' => 'prospect']),
    );

    expect($insert)->toThrow(QueryException::class);
});

test('un candidat ne peut PAS vivre dans un workspace business (trigger)', function () {
    $business = socleMakeBusinessWorkspace();

    $insert = fn () => DB::table('candidates')->insert(socleCandidateRow($business));

    expect($insert)->toThrow(QueryException::class);
});

test('déplacer un candidat vers un workspace business est refusé', function () {
    $vivier = socleVivierWorkspaceId();
    $business = socleMakeBusinessWorkspace();

    $id = DB::table('candidates')->insertGetId(socleCandidateRow($vivier));

    $move = fn () => DB::table('candidates')->where('id', $id)->update(['workspace_id' => $business]);

    expect($move)->toThrow(QueryException::class);
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. Étanchéité SQL du vivier (mêmes règles que le lot L0)
// ─────────────────────────────────────────────────────────────────────────────

test('candidates et candidate_tag portent FORCE RLS et une policy stricte', function () {
    foreach (['candidates', 'candidate_tag'] as $table) {
        $rls = DB::selectOne(
            'SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?',
            [$table],
        );

        expect($rls->relrowsecurity)->toBeTruthy("RLS non activée sur {$table}")
            ->and($rls->relforcerowsecurity)->toBeTruthy("FORCE RLS absente sur {$table}");

        $policy = DB::selectOne(
            'SELECT qual FROM pg_policies WHERE tablename = ? AND policyname = ?',
            [$table, $table . '_workspace_isolation'],
        );

        expect($policy)->not->toBeNull("Policy absente sur {$table}")
            ->and((string) $policy->qual)->not->toContain('IS NULL OR');
    }
});

test('le workspace du vivier existe et est distinct du business', function () {
    $vivier = DB::table('workspaces')->where('slug', Taxonomy::VIVIER_WORKSPACE_SLUG)->first();

    expect($vivier)->not->toBeNull()
        ->and($vivier->is_active)->toBeTruthy();
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. Idempotence et rapprochement des personnes
// ─────────────────────────────────────────────────────────────────────────────

test('external_ref est unique par workspace — rejouer un import ne duplique rien', function () {
    $vivier = socleVivierWorkspaceId();
    $ref = 'site:job_application:' . Str::uuid();

    DB::table('candidates')->insert(socleCandidateRow($vivier, ['external_ref' => $ref]));

    $again = fn () => DB::table('candidates')->insert(socleCandidateRow($vivier, ['external_ref' => $ref]));

    expect($again)->toThrow(QueryException::class);
});

test('person_key LIE les deux univers sans jamais les fusionner', function () {
    $vivier = socleVivierWorkspaceId();
    $business = socleMakeBusinessWorkspace();
    $personKey = hash('sha256', 'zz.test@example.invalid');

    $companyId = DB::table('companies')->insertGetId([
        'workspace_id' => $business,
        'siren' => '900000002',
        'denomination' => 'ZZ TEST',
        'signals' => '{}',
        'metadata' => '{}',
        'quality_score' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('contacts')->insert([
        'workspace_id' => $business,
        'company_id' => $companyId,
        'last_name' => 'ZZ TEST',
        'person_key' => $personKey,
        'sources' => '[]',
        'metadata' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('candidates')->insert(socleCandidateRow($vivier, ['person_key' => $personKey]));

    // La même personne existe des deux côtés — DEUX fiches, une par univers,
    // dans DEUX workspaces différents. Aucun mécanisme ne les fusionne.
    expect(DB::table('contacts')->where('person_key', $personKey)->count())->toBe(1)
        ->and(DB::table('candidates')->where('person_key', $personKey)->count())->toBe(1);

    $workspaces = DB::table('candidates')->where('person_key', $personKey)->pluck('workspace_id')
        ->merge(DB::table('contacts')->where('person_key', $personKey)->pluck('workspace_id'))
        ->unique()
        ->values();

    expect($workspaces)->toHaveCount(2);
});

// ─────────────────────────────────────────────────────────────────────────────
// 5. RGPD : base légale, versions de consentement, opposition par univers
// ─────────────────────────────────────────────────────────────────────────────

test('la conservation en vivier exige une version de consentement v2', function () {
    $vivier = socleVivierWorkspaceId();

    $v1 = new Candidate(socleCandidateRow($vivier, [
        'consent_version' => 'careers-v1-2026-06-09',
        'consent_vivier_at' => now(),
    ]));
    $v2 = new Candidate(socleCandidateRow($vivier, [
        'consent_version' => 'careers-v2-2026-08-13',
        'consent_vivier_at' => now(),
    ]));
    $sansHorodatage = new Candidate(socleCandidateRow($vivier, [
        'consent_version' => 'careers-v2-2026-08-13',
    ]));

    expect($v1->hasVivierConsent())->toBeFalse()
        ->and($v2->hasVivierConsent())->toBeTrue()
        ->and($sansHorodatage->hasVivierConsent())->toBeFalse();
});

test('une base légale hors référentiel est refusée', function () {
    $ws = socleMakeBusinessWorkspace();

    $insert = fn () => DB::table('companies')->insert([
        'workspace_id' => $ws,
        'siren' => '900000003',
        'denomination' => 'ZZ TEST',
        'signals' => '{}',
        'metadata' => '{}',
        'quality_score' => 0,
        'legal_basis' => 'parce_que',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($insert)->toThrow(QueryException::class);
});

test('l’opposition est portée par univers et par hash d’email', function () {
    DB::table('opt_out')->insert([
        'email' => 'zz.test@example.invalid',
        'email_hash' => hash('sha256', 'zz.test@example.invalid'),
        'scope' => 'vivier',
        'source' => 'test',
        'created_at' => now(),
    ]);

    expect(DB::table('opt_out')->where('scope', 'vivier')->count())->toBe(1)
        ->and(DB::table('opt_out')->where('scope', 'business')->count())->toBe(0);

    $mauvaisScope = fn () => DB::table('opt_out')->insert([
        'email_hash' => 'x',
        'scope' => 'les-deux',
        'source' => 'test',
        'created_at' => now(),
    ]);

    expect($mauvaisScope)->toThrow(QueryException::class);
});

// ─────────────────────────────────────────────────────────────────────────────
// 6. Tags gouvernés
// ─────────────────────────────────────────────────────────────────────────────

test('le namespace d’un tag est déduit de son slug, et vaut NULL sans namespace', function () {
    $ws = socleMakeBusinessWorkspace();

    DB::table('tags')->insert([
        ['workspace_id' => $ws, 'slug' => 'src:site-formulaire-audit', 'name' => 'Formulaire audit', 'category' => 'intent', 'kind' => 'auto', 'rules' => '{}', 'is_locked' => true, 'created_at' => now(), 'updated_at' => now()],
        ['workspace_id' => $ws, 'slug' => 'cand-offre:monteur-video', 'name' => 'Monteur vidéo', 'category' => 'candidate', 'kind' => 'auto', 'rules' => '{}', 'is_locked' => true, 'created_at' => now(), 'updated_at' => now()],
        ['workspace_id' => $ws, 'slug' => 'orphelin', 'name' => 'Orphelin', 'category' => 'custom', 'kind' => 'manual', 'rules' => '{}', 'is_locked' => false, 'created_at' => now(), 'updated_at' => now()],
    ]);

    expect(DB::table('tags')->where('slug', 'src:site-formulaire-audit')->value('namespace'))->toBe('src')
        ->and(DB::table('tags')->where('slug', 'cand-offre:monteur-video')->value('namespace'))->toBe('cand-offre')
        ->and(DB::table('tags')->where('slug', 'orphelin')->value('namespace'))->toBeNull();
});

test('tous les namespaces gouvernés se rattachent à une catégorie valide', function () {
    $inconnus = [];
    foreach (Taxonomy::TAG_NAMESPACES as $namespace => $category) {
        if (! in_array($category, Taxonomy::TAG_CATEGORIES, true)) {
            $inconnus[] = $namespace . ' => ' . $category;
        }
    }

    expect($inconnus)->toBe([]);
});

test('un candidat peut être tagué par le pivot dédié', function () {
    $vivier = socleVivierWorkspaceId();

    $tagId = DB::table('tags')->insertGetId([
        'workspace_id' => $vivier,
        'slug' => 'cand-dispo:immediate',
        'name' => 'Disponible immédiatement',
        'category' => 'candidate',
        'kind' => 'auto',
        'rules' => '{}',
        'is_locked' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $candidateId = DB::table('candidates')->insertGetId(socleCandidateRow($vivier));

    DB::table('candidate_tag')->insert([
        'candidate_id' => $candidateId,
        'tag_id' => $tagId,
        'workspace_id' => $vivier,
        'assigned_by' => 'auto-rule',
        'assigned_at' => now(),
    ]);

    expect(DB::table('candidate_tag')->where('candidate_id', $candidateId)->count())->toBe(1);

    $mauvaisAuteur = fn () => DB::table('candidate_tag')->insert([
        'candidate_id' => $candidateId,
        'tag_id' => $tagId,
        'workspace_id' => $vivier,
        'assigned_by' => 'magie',
        'assigned_at' => now(),
    ]);

    expect($mauvaisAuteur)->toThrow(QueryException::class);
});

// ─────────────────────────────────────────────────────────────────────────────
// 7. Inertie du lot
// ─────────────────────────────────────────────────────────────────────────────

test('les colonnes ajoutées portent des valeurs par défaut sûres', function () {
    $ws = socleMakeBusinessWorkspace();

    DB::table('companies')->insert([
        'workspace_id' => $ws,
        'siren' => '900000004',
        'denomination' => 'ZZ TEST',
        'signals' => '{}',
        'metadata' => '{}',
        'quality_score' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $row = DB::table('companies')->where('siren', '900000004')->first();

    // Une fiche créée sans rien préciser reste FROIDE : `prospect` / `nouveau`.
    // Le froid ne se réchauffe jamais tout seul.
    expect($row->relation_type)->toBe('prospect')
        ->and($row->lifecycle_stage)->toBe('nouveau')
        ->and($row->legal_basis)->toBeNull()
        ->and($row->external_ref)->toBeNull();
});
