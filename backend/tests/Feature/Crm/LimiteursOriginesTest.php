<?php

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class);

test('export et historique des origines ont leur propre limiteur', function () {
    $mw = static fn (string $uri): array => collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => $r->uri() === $uri)->gatherMiddleware();

    expect($mw('api/v1/crm/origines/export'))->toContain('throttle:origines-export')->not->toContain('throttle:scraper-list')
        ->and($mw('api/v1/crm/personnes/{personneId}/origines'))->toContain('throttle:origines-historique')->not->toContain('throttle:scraper-list');
});
