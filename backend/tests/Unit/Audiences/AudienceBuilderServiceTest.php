<?php

use App\Models\Company;
use App\Models\Contact;
use App\Models\EmailAudience;
use App\Models\Workspace;
use App\Services\Audiences\AudienceBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->ws = Workspace::create(['id' => Str::uuid()->toString(), 'name' => 'T', 'slug' => 'ws-' . uniqid()]);
    $this->service = new AudienceBuilderService();
});

function mkCompany(string $wsId, array $attrs = []): Company {
    return Company::create(array_merge([
        'workspace_id' => $wsId,
        'siren' => str_pad((string) random_int(1, 999999999), 9, '0', STR_PAD_LEFT),
    ], $attrs));
}

it('preview returns count for simple criteria', function () {
    mkCompany($this->ws->id, ['department_code' => '75', 'prospection_status' => 'ready_for_outreach']);
    mkCompany($this->ws->id, ['department_code' => '75', 'prospection_status' => 'pending']);
    mkCompany($this->ws->id, ['department_code' => '92', 'prospection_status' => 'ready_for_outreach']);

    $preview = $this->service->preview($this->ws->id, [
        'all' => [
            ['field' => 'department_code', 'op' => 'eq', 'value' => '75'],
            ['field' => 'prospection_status', 'op' => 'in', 'value' => ['ready_for_outreach']],
        ],
    ]);

    expect($preview['companies'])->toBe(1);
});

it('preview uses any combinator (OR)', function () {
    mkCompany($this->ws->id, ['department_code' => '75']);
    mkCompany($this->ws->id, ['department_code' => '92']);
    mkCompany($this->ws->id, ['department_code' => '69']);

    $preview = $this->service->preview($this->ws->id, [
        'any' => [
            ['field' => 'department_code', 'op' => 'eq', 'value' => '75'],
            ['field' => 'department_code', 'op' => 'eq', 'value' => '92'],
        ],
    ]);

    expect($preview['companies'])->toBe(2);
});

it('filters by quality_score range', function () {
    mkCompany($this->ws->id, ['quality_score' => 30]);
    mkCompany($this->ws->id, ['quality_score' => 60]);
    mkCompany($this->ws->id, ['quality_score' => 90]);

    $preview = $this->service->preview($this->ws->id, [
        'all' => [
            ['field' => 'quality_score', 'op' => 'gte', 'value' => 50],
            ['field' => 'quality_score', 'op' => 'lt', 'value' => 80],
        ],
    ]);

    expect($preview['companies'])->toBe(1);
});

it('filters by has_email contacts', function () {
    $cWithEmail = mkCompany($this->ws->id);
    Contact::create([
        'workspace_id' => $this->ws->id, 'company_id' => $cWithEmail->id,
        'last_name' => 'Doe', 'email' => 'a@b.fr', 'email_status' => 'valid',
    ]);
    mkCompany($this->ws->id);  // pas d'email

    $preview = $this->service->preview($this->ws->id, [
        'all' => [['field' => 'has_email', 'op' => 'eq', 'value' => true]],
    ]);
    expect($preview['companies'])->toBe(1);
});

it('refresh populates audience_members', function () {
    $c = mkCompany($this->ws->id, ['department_code' => '75']);
    Contact::create([
        'workspace_id' => $this->ws->id, 'company_id' => $c->id,
        'last_name' => 'Doe', 'email' => 'a@b.fr', 'email_status' => 'valid',
    ]);

    $audience = EmailAudience::create([
        'workspace_id' => $this->ws->id,
        'name' => 'Test',
        'criteria' => ['all' => [['field' => 'department_code', 'op' => 'eq', 'value' => '75']]],
    ]);

    $this->service->refresh($audience);

    $audience->refresh();
    expect($audience->member_count)->toBe(1);
    expect($audience->refreshed_at)->not->toBeNull();
});

it('refresh is idempotent (run twice = same count)', function () {
    mkCompany($this->ws->id, ['department_code' => '75']);
    mkCompany($this->ws->id, ['department_code' => '75']);

    $audience = EmailAudience::create([
        'workspace_id' => $this->ws->id,
        'name' => 'Test',
        'criteria' => ['all' => [['field' => 'department_code', 'op' => 'eq', 'value' => '75']]],
    ]);

    $this->service->refresh($audience);
    $this->service->refresh($audience);

    expect($audience->fresh()->member_count)->toBe(2);
});

it('evaluateForCompany returns matching audience IDs', function () {
    $c = mkCompany($this->ws->id, ['department_code' => '75', 'size_category' => 'pme']);

    $a1 = EmailAudience::create([
        'workspace_id' => $this->ws->id,
        'name' => 'IDF',
        'criteria' => ['all' => [['field' => 'department_code', 'op' => 'eq', 'value' => '75']]],
    ]);
    $a2 = EmailAudience::create([
        'workspace_id' => $this->ws->id,
        'name' => 'Other',
        'criteria' => ['all' => [['field' => 'department_code', 'op' => 'eq', 'value' => '92']]],
    ]);

    $matches = $this->service->evaluateForCompany($c);
    expect($matches)->toContain($a1->id)->not->toContain($a2->id);
});

it('skips invalid fields/ops silently', function () {
    mkCompany($this->ws->id);
    $preview = $this->service->preview($this->ws->id, [
        'all' => [
            ['field' => 'DROP TABLE users', 'op' => 'eq', 'value' => 'x'],
            ['field' => 'department_code', 'op' => 'INVALID', 'value' => '75'],
        ],
    ]);
    expect($preview['companies'])->toBe(1);
});
