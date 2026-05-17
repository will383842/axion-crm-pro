<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $limit = (int) env('RATE_LIMIT_PER_MINUTE', 60);
            return Limit::perMinute($limit)->by(
                optional($request->user())->id ?: $request->ip()
            );
        });

        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('magic-link', fn (Request $r) => Limit::perMinute(3)->by($r->ip()));
        RateLimiter::for('internal', fn (Request $r) => Limit::perMinute(600)->by($r->ip()));

        // Sprint 19.6 — scraper endpoints (anti-abus + protection quotas externes).
        // scraper-launch : 10/min/user (~1 launch/6s), couvre /coverage/launch + cancel + retry.
        // scraper-list   : 60/min/user, lecture historique.
        RateLimiter::for(
            'scraper-launch',
            fn (Request $r) => Limit::perMinute((int) env('SCRAPER_LAUNCH_PER_MINUTE', 10))
                ->by(optional($r->user())->id ?: $r->ip())
        );
        RateLimiter::for(
            'scraper-list',
            fn (Request $r) => Limit::perMinute((int) env('SCRAPER_LIST_PER_MINUTE', 60))
                ->by(optional($r->user())->id ?: $r->ip())
        );
    }
}
