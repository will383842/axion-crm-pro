<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

/**
 * Telescope est ÉTEINT par défaut, et REFUSÉ hors `local` / `testing`.
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
 *
 * ── ET UN DÉFAUT N'EST PAS UN REFUS ─────────────────────────────────────────
 *
 * Le correctif ci-dessus ne fermait que le cas « la variable est absente ».
 * Mesuré le 2026-08-21, processus neuf, sur le dépôt déjà corrigé :
 *
 *     APP_ENV=production TELESCOPE_ENABLED=true → enabled=true, enregistre=true
 *
 * `config/telescope.php` neutralise désormais la demande hors `local` /
 * `testing`, comme `config/app.php` le fait pour `APP_DEBUG` et comme
 * `MockServicesProvider::drapeau()` le fait pour les simulacres. Ce fichier
 * n'a plus qu'une chose à ajouter : **ne pas laisser ce refus muet.**
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
        // ── LE REFUS DE TELESCOPE NE DOIT PAS ETRE SILENCIEUX — A-007 (S1) ──
        //
        // `config/telescope.php` neutralise `TELESCOPE_ENABLED=true` hors
        // `local`/`testing`. Sans ce signal, l'operateur qui pose la variable
        // sur la preproduction pour regarder une requete ne verrait RIEN, et
        // il chercherait la panne ailleurs — ou il « reparerait » la garde.
        //
        // Le drapeau est lu dans la CONFIGURATION et non par `env()` : en
        // production `config:cache` est actif et `env()` hors configuration
        // rend `null`. Meme raison que `app.debug_refuse` (F37-003).
        //
        // ⚠️ Ce test precede le court-circuit : la journalisation doit avoir
        // lieu PRECISEMENT quand Telescope est eteint contre la demande.
        if (config('telescope.enabled_refuse') === true && ! self::$refusSignale) {
            self::$refusSignale = true;
            Log::critical(
                'Telescope REFUSE hors poste de developpement : TELESCOPE_ENABLED est a vrai '
                . 'en environnement « ' . $this->app->environment() . ' ». Constat A-007/F40-003 '
                . "(S1) : aucune migration Telescope n'existe dans ce depot, donc chaque "
                . 'enregistrement echoue sur `relation "telescope_entries" does not exist` — '
                . '270 Mo de journal, environ 90 Mo par jour, 100 % du meme defaut. Et Telescope '
                . 'journalise les requetes, les requetes SQL avec leurs parametres, les jobs et '
                . 'les courriels : donc des donnees personnelles et des secrets. Telescope reste '
                . 'eteint. Corrigez la configuration du conteneur (docker inspect), pas le .env : '
                . '`docker compose restart` ne relit pas env_file.',
                ['environnement' => $this->app->environment()],
            );
        }

        if (! (bool) config('telescope.enabled')) {
            return;
        }
        parent::boot();
    }

    /** N'alerte qu'une fois par processus : sinon le journal noie son propre signal. */
    private static bool $refusSignale = false;

    protected function gate(): void
    {
        Gate::define('viewTelescope', function ($user) {
            return $user && $user->hasRole('owner');
        });
    }
}
