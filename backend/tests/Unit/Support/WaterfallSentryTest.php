<?php

use App\Support\WaterfallSentry;

it('captures without throwing when Sentry class missing', function () {
    // En environnement de test sans Sentry SDK bound, doit être no-op
    $e = new \RuntimeException('test');
    expect(fn () => WaterfallSentry::capture(null, 'service', $e))->not->toThrow(\Throwable::class);
});

it('handles null company without crashing', function () {
    $e = new \RuntimeException('test');
    expect(fn () => WaterfallSentry::capture(null, 'auto-classify', $e))
        ->not->toThrow(\Throwable::class);
});
