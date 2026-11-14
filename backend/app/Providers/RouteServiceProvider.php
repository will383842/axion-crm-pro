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

        // Désinscription publique (RFC 8058) : JAMAIS de 429 pour un POST (une opposition ne doit
        // pas être refusée, y compris depuis un NAT d'entreprise). Le GET est limité PAR JETON, pas par IP.
        RateLimiter::for('desinscription', function (Request $r) {
            if (! $r->isMethod('GET')) {
                return Limit::none();
            }

            return Limit::perMinute(120)->by('desinscription:' . hash('sha256', (string) $r->route('jeton')));
        });
        // R04 : recherche d'un envoi par adresse, 10 par minute et par compte.
        RateLimiter::for('envoi-chercher', fn (Request $r) => Limit::perMinute(10)->by('envoi-chercher:' . (optional($r->user())->id ?: $r->ip())));
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
        // origines-export / origines-historique : compteurs PROPRES (60/min/user chacun), pour qu'un
        // changement de filtre ne fasse pas tomber les autres lectures en 429 en cascade.
        foreach (['origines-export', 'origines-historique'] as $nom) {
            RateLimiter::for(
                $nom,
                fn (Request $r) => Limit::perMinute(60)->by($nom . '|' . (optional($r->user())->id ?: $r->ip())),
            );
        }
        // Envoi R05 : export du registre d'opposition (2 par heure et par compte) ; « Puis-je écrire à… ? » (30 par minute et par compte).
        RateLimiter::for('envoi-opposition-export', fn (Request $r) => Limit::perHour(2)->by('envoi-opposition-export|' . (optional($r->user())->id ?: $r->ip())));
        // Envoi R15 : écritures d'infrastructure (30 par minute) et contrôle DNS (6 par minute), par compte.
        RateLimiter::for('envoi-infra-ecriture', fn (Request $r) => Limit::perMinute(30)->by('envoi-infra-ecriture|' . (optional($r->user())->id ?: $r->ip())));
        RateLimiter::for('envoi-dns-verifier', fn (Request $r) => Limit::perMinute(6)->by('envoi-dns-verifier|' . (optional($r->user())->id ?: $r->ip())));
        RateLimiter::for('envoi-puis-je-ecrire', fn (Request $r) => Limit::perMinute(30)->by('envoi-puis-je-ecrire|' . (optional($r->user())->id ?: $r->ip())));
        RateLimiter::for(
            'scraper-list',
            fn (Request $r) => Limit::perMinute((int) env('SCRAPER_LIST_PER_MINUTE', 60))
                ->by(optional($r->user())->id ?: $r->ip()),
        );
    }
}
