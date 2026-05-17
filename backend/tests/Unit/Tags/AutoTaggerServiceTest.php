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
    $this->service = new AutoTaggerService();
});

function makeCompany(string $workspaceId, array $attrs = []): Company {
    return Company::create(array_merge([
        'workspace_id' => $workspaceId,
        'siren' => str_pad((string) random_int(1, 999999999), 9, '0', STR_PAD_LEFT),
    ], $attrs));
}

it('creates dept and region tags from geo attributes', function () {
    $c = makeCompany($this->workspace->id, [
        'department_code' => '75', 'region_code' => '11',
    ]);

    $delta = $this->service->syncTags($c);

    expect($delta['added'])->toContain('dept-75')->toContain('region-11');
    expect(Tag::where('workspace_id', $this->workspace->id)->where('slug', 'dept-75')->exists())->toBeTrue();
});

it('creates size and sector tags', function () {
    $c = makeCompany($this->workspace->id, [
        'size_category' => 'pme', 'sector_main' => 'it_saas',
    ]);

    $this->service->syncTags($c);

    $slugs = $c->fresh()->tags->pluck('slug')->all();
    expect($slugs)->toContain('size-pme', 'sector-it-saas');
});

it('imports LLM classification tags as intent category', function () {
    $c = makeCompany($this->workspace->id, [
        'signals' => ['llm_classification' => ['tags' => ['Cible chaude', 'Scale-up']]],
    ]);

    $this->service->syncTags($c);

    $tags = Tag::where('workspace_id', $this->workspace->id)->get();
    $intentTags = $tags->where('category', 'intent');
    expect($intentTags)->toHaveCount(2);
    expect($intentTags->pluck('kind')->unique()->all())->toBe(['llm']);
});

it('preserves manual tags on resync', function () {
    $c = makeCompany($this->workspace->id, ['department_code' => '75']);

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
    $c = makeCompany($this->workspace->id, ['department_code' => '75']);
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

it('idempotent on second call', function () {
    $c = makeCompany($this->workspace->id, [
        'department_code' => '75', 'size_category' => 'pme',
    ]);

    $delta1 = $this->service->syncTags($c);
    $delta2 = $this->service->syncTags($c);

    expect($delta1['added'])->not->toBeEmpty();
    expect($delta2['added'])->toBeEmpty();
    expect($delta2['removed'])->toBeEmpty();
});
