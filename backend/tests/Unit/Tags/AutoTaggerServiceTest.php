<?php

use App\Models\Company;
use App\Models\Tag;
use App\Models\Workspace;
use App\Services\Tags\AutoTaggerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->workspace = Workspace::create([
        'id' => Str::uuid()->toString(),
        'name' => 'Test', 'slug' => 'test-' . uniqid(),
    ]);
    $this->service = new AutoTaggerService;
});

function makeTaggerCompany(string $workspaceId, array $attrs = []): Company
{
    return Company::create(array_merge([
        'workspace_id' => $workspaceId,
        'siren' => str_pad((string) random_int(1, 999999999), 9, '0', STR_PAD_LEFT),
    ], $attrs));
}

it('creates dept and region tags from geo attributes', function () {
    $c = makeTaggerCompany($this->workspace->id, [
        'department_code' => '75', 'region_code' => '11',
    ]);

    $delta = $this->service->syncTags($c);

    expect($delta['added'])->toContain('dept-75')->toContain('region-11');
    expect(Tag::where('workspace_id', $this->workspace->id)->where('slug', 'dept-75')->exists())->toBeTrue();
});

it('creates size and sector tags', function () {
    $c = makeTaggerCompany($this->workspace->id, [
        'size_category' => 'pme', 'sector_main' => 'it_saas',
    ]);

    $this->service->syncTags($c);

    $slugs = $c->fresh()->tags->pluck('slug')->all();
    expect($slugs)->toContain('size-pme', 'sector-it-saas');
});

it('imports LLM classification tags as intent category', function () {
    $c = makeTaggerCompany($this->workspace->id, [
        'signals' => ['llm_classification' => ['tags' => ['Cible chaude', 'Scale-up']]],
    ]);

    $this->service->syncTags($c);

    $tags = Tag::where('workspace_id', $this->workspace->id)->get();
    $intentTags = $tags->where('category', 'intent');
    expect($intentTags)->toHaveCount(2);
    expect($intentTags->pluck('kind')->unique()->all())->toBe(['llm']);
});

it('preserves manual tags on resync', function () {
    $c = makeTaggerCompany($this->workspace->id, ['department_code' => '75']);

    // Manual tag créé par user
    $manualTag = Tag::create([
        'workspace_id' => $this->workspace->id,
        'slug' => 'vip-client',
        'name' => 'VIP Client',
        'category' => 'custom',
        'kind' => 'manual',
        'color' => 'rose',
        'rules' => [],
    ]);
    DB::table('company_tag')->insert([
        'company_id' => $c->id,
        'tag_id' => $manualTag->id,
        'workspace_id' => $this->workspace->id,
        'assigned_at' => now(),
        'assigned_by' => 'user',
    ]);

    $this->service->syncTags($c);

    expect($c->fresh()->tags->pluck('slug')->all())->toContain('vip-client');
});

it('removes auto-rule tag when attribute changes', function () {
    $c = makeTaggerCompany($this->workspace->id, ['department_code' => '75']);
    $this->service->syncTags($c);
    expect($c->fresh()->tags->pluck('slug')->all())->toContain('dept-75');

    // Change department
    $c->department_code = '92';
    $c->save();
    $delta = $this->service->syncTags($c);

    expect($delta['removed'])->toContain('dept-75');
    expect($delta['added'])->toContain('dept-92');
    $slugs = $c->fresh()->tags->pluck('slug')->all();
    expect($slugs)->toContain('dept-92')->not->toContain('dept-75');
});

/**
 * Régression de l'incident de production : une passe d'enrichissement
 * SUPPRIMAIT les tags de provenance `src:` — ils ne sont jamais « désirés »
 * par la synchro (ils ne se dérivent d'aucun attribut de la fiche), donc la
 * boucle de retrait les emportait. Un `src:` décrit un FAIT constaté (d'où
 * vient la fiche) : aucun recalcul ne doit l'effacer.
 */
it('never removes a src: provenance tag on resync', function () {
    $c = makeTaggerCompany($this->workspace->id, ['department_code' => '75']);

    // Tag de provenance tel que le pose le funnel d'ingestion
    // (cf. ScrapedRecordIngestService::attachSourceTag), mais NON verrouillé :
    // `tags.is_locked` a été ajouté le 2026-08-14 avec `DEFAULT false` et sans
    // reprise du stock existant — tous les `src:` posés AVANT cette migration
    // sont donc déverrouillés. C'est exactement ce cas-là que la garde par
    // NAMESPACE doit couvrir toute seule : si le test posait un tag verrouillé,
    // il resterait vert même en retirant la garde `src:`, donc il ne garderait
    // plus rien.
    $srcTag = Tag::create([
        'workspace_id' => $this->workspace->id,
        'slug' => 'src:scraping-pages-jaunes',
        'name' => 'Collecte — Pages Jaunes',
        'category' => 'intent',
        'kind' => 'auto',
        'color' => 'slate',
        'is_locked' => false,
        'rules' => [],
    ]);
    DB::table('company_tag')->insert([
        'company_id' => $c->id,
        'tag_id' => $srcTag->id,
        'workspace_id' => $this->workspace->id,
        'assigned_at' => now(),
        'assigned_by' => 'auto-rule',
    ]);

    $delta = $this->service->syncTags($c);

    expect($delta['removed'])->not->toContain('src:scraping-pages-jaunes');
    expect($c->fresh()->tags->pluck('slug')->all())->toContain('src:scraping-pages-jaunes');
});

/**
 * Même famille que l'incident `src:`, un cran plus large : les tags
 * VERROUILLÉS (`tags.is_locked`) sont le référentiel gouverné
 * (GovernedTagsSeeder) — `svc:audit` dit que cette entreprise a DEMANDÉ un
 * audit, c'est un fait posé par l'ingestion du site (SiteSyncIngestService),
 * pas une étiquette dérivable des attributs de la fiche. L'API refuse déjà de
 * les retirer (CompanyTagsBulkController → 422 « tag_verrouille ») ; la
 * synchro automatique doit s'aligner, sinon la première passe
 * d'enrichissement efface en silence ce que l'API protège.
 */
it('never removes a locked governed tag on resync', function () {
    $c = makeTaggerCompany($this->workspace->id, ['department_code' => '75']);

    $lockedTag = Tag::create([
        'workspace_id' => $this->workspace->id,
        'slug' => 'svc:audit',
        'name' => 'Intérêt — audit',
        'category' => 'intent',
        'kind' => 'auto',
        'color' => 'emerald',
        'is_locked' => true,
        'rules' => [],
    ]);
    DB::table('company_tag')->insert([
        'company_id' => $c->id,
        'tag_id' => $lockedTag->id,
        'workspace_id' => $this->workspace->id,
        'assigned_at' => now(),
        'assigned_by' => 'auto-rule',
    ]);

    $delta = $this->service->syncTags($c);

    expect($delta['removed'])->not->toContain('svc:audit');
    expect($c->fresh()->tags->pluck('slug')->all())->toContain('svc:audit');
});

it('idempotent on second call', function () {
    $c = makeTaggerCompany($this->workspace->id, [
        'department_code' => '75', 'size_category' => 'pme',
    ]);

    $delta1 = $this->service->syncTags($c);
    $delta2 = $this->service->syncTags($c);

    expect($delta1['added'])->not->toBeEmpty();
    expect($delta2['added'])->toBeEmpty();
    expect($delta2['removed'])->toBeEmpty();
});
