<?php

namespace App\Providers;

use App\Support\Partners\ConfigurationCanalPartners;
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
                optional($request->user())->id ?: $request->ip(),
            );
        });

        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('magic-link', fn (Request $r) => Limit::perMinute(3)->by($r->ip()));
        // Changement de mot de passe connecté : 5/min PAR COMPTE (l'ancien mot de
        // passe s'y devine aussi bien qu'à la connexion).
        RateLimiter::for('password-change', fn (Request $r) => Limit::perMinute(5)->by(optional($r->user())->id ?: $r->ip()));
        RateLimiter::for('internal', fn (Request $r) => Limit::perMinute(600)->by($r->ip()));
        // Lot N11 — canal Partners. En mode `off`, AUCUNE limite : le limiteur
        // ne compte rien et ne pose aucun en-tête `X-RateLimit-*`, et ne rend
        // jamais 429 — la route doit répondre exactement comme une route absente
        // (le tri de priorité de Laravel 12 place ce limiteur AVANT le
        // vérificateur). Ouvert : même plafond que les autres canaux internes.
        RateLimiter::for('partners', fn (Request $r) => config('crm.partners.mode') === ConfigurationCanalPartners::MODE_OFF
            ? Limit::none()
            : Limit::perMinute(600)->by($r->ip()));

        // Sprint 19.6 — scraper endpoints (anti-abus + protection quotas externes).
        // scraper-launch : 10/min/user (~1 launch/6s), couvre /coverage/launch + cancel + retry.
        // scraper-list   : 60/min/user, lecture historique.
        RateLimiter::for(
            'scraper-launch',
            fn (Request $r) => Limit::perMinute((int) env('SCRAPER_LAUNCH_PER_MINUTE', 10))
                ->by(optional($r->user())->id ?: $r->ip()),
        );
        RateLimiter::for(
            'scraper-list',
            fn (Request $r) => Limit::perMinute((int) env('SCRAPER_LIST_PER_MINUTE', 60))
                ->by(optional($r->user())->id ?: $r->ip()),
        );
    }
}
