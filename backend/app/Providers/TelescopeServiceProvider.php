<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

/**
 * Telescope est ÉTEINT par défaut. Le drapeau `TELESCOPE_ENABLED` le rallume.
 *
 * ── 🔴 CE QUE CE DOCBLOC AFFIRMAIT, ET QUI ÉTAIT FAUX (constat A-007) ────────
 *
 * Il disait : « Telescope est désactivé par défaut en prod via
 * `TELESCOPE_ENABLED=false` (.env) ». Trois choses clochaient :
 *
 *  1. aucun `.env` de production ne portait cette clé — le défaut s'appliquait ;
 *  2. le défaut n'était pas `false`. `backend/config/` ne contenait aucun
 *     `telescope.php`, donc la configuration servie était celle du paquet :
 *     `'enabled' => env('TELESCOPE_ENABLED', true)` ;
 *  3. et surtout, le court-circuit ci-dessous NE PROTÉGEAIT RIEN.
 *     `composer.json` porte `extra.laravel.dont-discover: []`, donc
 *     `Laravel\Telescope\TelescopeServiceProvider` — le fournisseur du PAQUET —
 *     est découvert automatiquement et s'enregistre À CÔTÉ de celui-ci. Lui ne
 *     lit pas `env()`, il lit `config('telescope.enabled')`, et il appelle
 *     `Telescope::start()`.
 *
 * Mesuré le 2026-08-20, dans un processus neuf, sans `TELESCOPE_ENABLED` :
 *
 *     enabled=true
 *     fournisseurs=Laravel\Telescope\TelescopeServiceProvider,App\Providers\TelescopeServiceProvider
 *     enregistre=true
 *
 * Ce fichier économisait donc quelques `require` pendant que le fournisseur du
 * paquet démarrait Telescope juste à côté. Coût en production : 270 Mo de
 * journal, ~90 Mo par jour, 100 % du même défaut — `relation
 * "telescope_entries" does not exist`, aucune migration Telescope n'existant
 * dans ce dépôt.
 *
 * Le correctif est `backend/config/telescope.php`, qui ramène le défaut à
 * FALSE. Garde : `tests/Feature/Infra/TelescopeDesactiveParDefautTest.php`.
 *
 * ── POURQUOI `config()` ET PLUS `env()` ─────────────────────────────────────
 *
 * Ces deux méthodes lisaient `env('TELESCOPE_ENABLED', false)` là où le
 * fournisseur du paquet lit `config('telescope.enabled')`. Sous `config:cache`
 * — qui est actif en production — `env()` hors configuration rend **null** :
 * les deux fournisseurs pouvaient donc se contredire, l'un s'éteignant pendant
 * que l'autre démarrait Telescope. Dans cet état, `Telescope::auth()` et le
 * portillon `viewTelescope` n'étaient jamais définis alors que les routes du
 * paquet, elles, l'étaient. On lit désormais la MÊME source que lui.
 */
class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    public function register(): void
    {
        // Évite le chargement des observateurs quand Telescope est éteint.
        // ⚠️ Ce court-circuit est un CONFORT, pas une protection : la
        // protection réelle est `config/telescope.php`, que le fournisseur du
        // paquet lit lui aussi.
        if (! (bool) config('telescope.enabled')) {
            return;
        }
        parent::register();
        Telescope::night();
    }

    public function boot(): void
    {
        if (! (bool) config('telescope.enabled')) {
            return;
        }
        parent::boot();
    }

    protected function gate(): void
    {
        Gate::define('viewTelescope', function ($user) {
            return $user && $user->hasRole('owner');
        });
    }
}
