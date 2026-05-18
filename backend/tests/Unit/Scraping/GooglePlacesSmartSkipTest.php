<?php

use App\Models\Company;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeSmartSkipWorkspace(): Workspace
{
    return Workspace::create([
        'id'   => (string) Str::uuid(),
        'slug' => 'ws-' . Str::random(6),
        'name' => 'WS Smart Skip',
    ]);
}

function makeSmartSkipCompany(string $workspaceId, array $overrides = []): Company
{
    return Company::create(array_merge([
        'id'           => (string) Str::uuid(),
        'workspace_id' => $workspaceId,
        'siren'        => str_pad((string) random_int(100000000, 999999999), 9, '0', STR_PAD_LEFT),
        'denomination' => 'Acme SA',
        'city_name'    => 'Paris',
    ], $overrides));
}

beforeEach(function () {
    Cache::flush();
    Config::set('services.google.places.api_key', 'fake-key');
    Config::set('services.google.places.smart_skip', true);
});

it('skips Google Places when company already has email_generic (H16 doctrine)', function () {
    Http::fake();
    $ws = makeSmartSkipWorkspace();
    $company = makeSmartSkipCompany($ws->id, [
        'email_generic' => 'contact@acme.fr',
        // pas de phone, pas de website → quand même skip car email = essentiel
    ]);

    /** @var \App\Services\Waterfall\WaterfallOrchestrator $orch */
    $orch = app(\App\Services\Waterfall\WaterfallOrchestrator::class);
    $reflection = new ReflectionClass($orch);
    $method = $reflection->getMethod('step3d_google_places');
    $method->setAccessible(true);
    $method->invoke($orch, $company);

    Http::assertNothingSent();
    $company->refresh();
    expect($company->signals['google_places_skipped']['reason'] ?? null)->toBe('has_essential_data');
});

it('does NOT skip when company is missing email (H16 — appelle Google)', function () {
    Http::fake([
        'places.googleapis.com/*' => Http::response(['places' => [['id' => 'X']]], 200),
    ]);
    $ws = makeSmartSkipWorkspace();
    $company = makeSmartSkipCompany($ws->id, [
        'phone'   => '+33 1 23 45 67 89',
        'website' => 'https://acme.fr',
        // pas d'email → Google Places appelé pour tenter d'enrichir
    ]);

    $orch = app(\App\Services\Waterfall\WaterfallOrchestrator::class);
    $reflection = new ReflectionClass($orch);
    $method = $reflection->getMethod('step3d_google_places');
    $method->setAccessible(true);
    $method->invoke($orch, $company);

    Http::assertSentCount(1);
});

it('skips Google Places even if phone+website are missing, as long as email exists (H16)', function () {
    Http::fake();
    $ws = makeSmartSkipWorkspace();
    $company = makeSmartSkipCompany($ws->id, [
        'email_generic' => 'hello@acme.fr',
        // ni phone ni website → skip car email = essentiel selon H16
    ]);

    $orch = app(\App\Services\Waterfall\WaterfallOrchestrator::class);
    $reflection = new ReflectionClass($orch);
    $method = $reflection->getMethod('step3d_google_places');
    $method->setAccessible(true);
    $method->invoke($orch, $company);

    Http::assertNothingSent();
});

it('skip is disabled when smart_skip config is false', function () {
    Config::set('services.google.places.smart_skip', false);
    Http::fake([
        'places.googleapis.com/*' => Http::response(['places' => [['id' => 'X']]], 200),
    ]);
    $ws = makeSmartSkipWorkspace();
    $company = makeSmartSkipCompany($ws->id, [
        'email_generic' => 'contact@acme.fr',
    ]);

    $orch = app(\App\Services\Waterfall\WaterfallOrchestrator::class);
    $reflection = new ReflectionClass($orch);
    $method = $reflection->getMethod('step3d_google_places');
    $method->setAccessible(true);
    $method->invoke($orch, $company);

    Http::assertSentCount(1);  // smart_skip=false → on appelle quand même
});

it('considers contact with email_status=valid as sufficient email', function () {
    Http::fake();
    $ws = makeSmartSkipWorkspace();
    $company = makeSmartSkipCompany($ws->id, []);
    DB::table('contacts')->insert([
        'workspace_id' => $ws->id,
        'company_id'   => $company->id,
        'last_name'    => 'Dupont',
        'email'        => 'jean.dupont@acme.fr',
        'email_status' => 'valid',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $orch = app(\App\Services\Waterfall\WaterfallOrchestrator::class);
    $reflection = new ReflectionClass($orch);
    $method = $reflection->getMethod('step3d_google_places');
    $method->setAccessible(true);
    $method->invoke($orch, $company);

    Http::assertNothingSent();  // smart skip activé
});
