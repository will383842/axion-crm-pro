<?php

namespace App\Providers;

use App\Contracts\AnnuaireEntreprisesClient;
use App\Contracts\BanGeocoder;
use App\Contracts\BodaccClient;
use App\Contracts\CaptchaSolver;
use App\Contracts\DirectionFinder;
use App\Contracts\FranceTravailClient;
use App\Contracts\GoogleMapsScraper;
use App\Contracts\InseeClient;
use App\Contracts\LLMClient;
use App\Contracts\PagesJaunesScraper;
use App\Contracts\ProxyProvider;
use App\Contracts\SearchEngine;
use App\Contracts\SmtpProber;
use App\Contracts\WebsiteScraper;
use App\Services\AnnuaireEntreprises\HttpAnnuaireEntreprisesClient;
use App\Services\AnnuaireEntreprises\Mocks\MockAnnuaireEntreprisesClient;
use App\Services\Ban\HttpBanGeocoder;
use App\Services\Ban\Mocks\MockBanGeocoder;
use App\Services\Bodacc\HttpBodaccClient;
use App\Services\Bodacc\Mocks\MockBodaccClient;
use App\Services\Captcha\Mocks\MockCaptchaSolver;
use App\Services\Captcha\TwoCaptchaSolver;
use App\Services\FranceTravail\HttpFranceTravailClient;
use App\Services\FranceTravail\Mocks\MockFranceTravailClient;
use App\Services\Insee\HttpInseeClient;
use App\Services\Insee\Mocks\MockInseeClient;
use App\Services\LLM\LLMRouterService;
use App\Services\LLM\Mocks\MockLLMClient;
use App\Services\Proxies\Mocks\MockProxyProvider;
use App\Services\Proxies\WebshareProvider;
use App\Services\Scraping\Mocks\MockDirectionFinder;
use App\Services\Scraping\Mocks\MockGoogleMapsScraper;
use App\Services\Scraping\Mocks\MockPagesJaunesScraper;
use App\Services\Scraping\Mocks\MockSearchEngine;
use App\Services\Scraping\Mocks\MockWebsiteScraper;
use App\Services\Scraping\PlaywrightDirectionFinder;
use App\Services\Scraping\PlaywrightGoogleMapsScraper;
use App\Services\Scraping\PlaywrightPagesJaunesScraper;
use App\Services\Scraping\PlaywrightSearchEngine;
use App\Services\Scraping\PlaywrightWebsiteScraper;
use App\Services\Smtp\HunterSmtpProber;
use App\Services\Smtp\Mocks\MockSmtpProber;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Branche les implémentations RÉELLES ou SIMULÉES des services externes.
 *
 * 🔴 CONSTAT C18-016 / F37-002 (S0). CE FICHIER AVAIT LE DÉFAUT DANS LE MAUVAIS SENS.
 *
 * Il commençait par :
 *
 *     $master = (bool) env('MOCK_MODE', true);
 *
 * **Le défaut était `true`.** Une variable absente du conteneur, mal orthographiée, perdue lors
 * d'un redéploiement ou d'un `docker compose restart` (qui ne relit pas `env_file` — constat
 * A07-003), et les six services basculaient sur des simulacres. **En production. Sans que rien
 * ne le signale.**
 *
 * Le pire des six est le modèle de langage : `MockLLMClient` écrit des classifications
 * **fabriquées** dans la base — des données fausses, indiscernables des vraies, sur des fiches
 * de personnes réelles. Un simulacre qui remplit un écran se voit ; un simulacre qui remplit
 * une base ne se voit jamais.
 *
 * ── LE PRINCIPE, ET IL EST INVERSÉ PAR RAPPORT À AVANT ─────────────────────
 *
 * **Le défaut suit l'ENVIRONNEMENT, jamais l'inverse.** En `local` et en `testing`, simuler est
 * la norme : le défaut y reste `true`. En `production` et en `staging`, le service réel est la
 * norme : le défaut y est `false`.
 *
 * Et en **production**, un simulacre est refusé *même s'il est explicitement demandé*. Ce n'est
 * pas une précaution excessive : il n'existe aucune raison légitime de servir des données
 * fabriquées à des utilisateurs réels, et une variable posée par erreur ne doit pas pouvoir le
 * décider. Le refus est **journalisé au niveau critique**, une fois par processus.
 *
 * Garde : `backend/tests/Feature/Infra/AucunSimulacreEnProductionTest.php`.
 */
class MockServicesProvider extends ServiceProvider
{
    public function register(): void
    {
        // En production et en préproduction, le service RÉEL est le défaut.
        // Ailleurs (local, testing), le simulacre l'est — sinon toute la suite
        // de tests tomberait, et ce serait un correctif pire que le défaut.
        $defautSimulacre = ! $this->app->environment(['production', 'staging']);

        $master = $this->drapeau('MOCK_MODE', $defautSimulacre);

        $bind = function (string $contract, string $real, string $mock, string $envFlag) use ($master) {
            $useMock = $this->drapeau($envFlag, $master);
            $this->app->bind($contract, $useMock ? $mock : $real);
        };

        // Sprint H15 (2026-05-18) — Activation LLM réel via LLMRouterService.
        // Le router gère lui-même la fallback chain (anthropic/mistral/openai)
        // configurée par use case dans llm_use_cases.fallback_chain.
        // Si MOCK_LLM=true (default suit MOCK_MODE), on retombe sur MockLLMClient
        // pour les tests Pest / dev local.
        $bind(LLMClient::class, LLMRouterService::class, MockLLMClient::class, 'MOCK_LLM');
        $this->app->bind(LLMRouterService::class, LLMRouterService::class);

        $bind(ProxyProvider::class, WebshareProvider::class, MockProxyProvider::class, 'MOCK_PROXIES');
        $bind(CaptchaSolver::class, TwoCaptchaSolver::class, MockCaptchaSolver::class, 'MOCK_CAPTCHA');
        // Sprint H2 — HunterSmtpProber remplace RealSmtpProber (probe direct → ban Spamhaus IP Hetzner).
        // RealSmtpProber gardé en classe pour fallback manuel mais plus wired par défaut.
        $bind(SmtpProber::class, HunterSmtpProber::class, MockSmtpProber::class, 'MOCK_SMTP');
        $bind(InseeClient::class, HttpInseeClient::class, MockInseeClient::class, 'MOCK_INSEE');
        $bind(AnnuaireEntreprisesClient::class, HttpAnnuaireEntreprisesClient::class, MockAnnuaireEntreprisesClient::class, 'MOCK_ANNUAIRE_ENTREPRISES');
        $bind(BodaccClient::class, HttpBodaccClient::class, MockBodaccClient::class, 'MOCK_BODACC');
        $bind(BanGeocoder::class, HttpBanGeocoder::class, MockBanGeocoder::class, 'MOCK_BAN');
        $bind(FranceTravailClient::class, HttpFranceTravailClient::class, MockFranceTravailClient::class, 'MOCK_FRANCE_TRAVAIL');

        $bind(GoogleMapsScraper::class, PlaywrightGoogleMapsScraper::class, MockGoogleMapsScraper::class, 'MOCK_SCRAPERS');
        $bind(PagesJaunesScraper::class, PlaywrightPagesJaunesScraper::class, MockPagesJaunesScraper::class, 'MOCK_SCRAPERS');
        $bind(WebsiteScraper::class, PlaywrightWebsiteScraper::class, MockWebsiteScraper::class, 'MOCK_SCRAPERS');
        $bind(SearchEngine::class, PlaywrightSearchEngine::class, MockSearchEngine::class, 'MOCK_SCRAPERS');
        $bind(DirectionFinder::class, PlaywrightDirectionFinder::class, MockDirectionFinder::class, 'MOCK_SCRAPERS');
    }

    /** N'alerte qu'une fois par processus : sinon le journal noie son propre signal. */
    private static bool $refusSignale = false;

    /**
     * Lit un drapeau de simulacre — et REFUSE toute simulation en production.
     *
     * ⚠️ `filter_var(..., FILTER_VALIDATE_BOOLEAN)` et non `(bool)`. L'ancien code faisait
     * `(bool) env($flag, $master)` : or `(bool) "false"` vaut **`true`** en PHP, comme
     * `(bool) "0.0"` ou `(bool) "off"`. Laravel normalise `"false"` dans `env()`, mais pas les
     * autres formes — et une variable posée à `"off"` par un opérateur qui croit la désactiver
     * l'activait. Le validateur, lui, reconnaît `false`, `0`, `off`, `no` et la chaîne vide.
     *
     * 🔴 LE REFUS EN PRODUCTION N'EST PAS CONTOURNABLE, ET C'EST VOULU.
     *
     * Il n'existe aucune raison légitime de servir des données fabriquées à des utilisateurs
     * réels. Rendre le refus contournable par une variable, c'est reconstruire exactement le
     * défaut qu'on répare : il suffirait qu'un `MOCK_LLM=true` traîne dans un `.env` pour que
     * la base se remplisse de classifications inventées.
     *
     * Le refus est journalisé au niveau **critique**, parce qu'il révèle une configuration de
     * production fautive — le silence ici redonnerait le défaut d'origine, qui était
     * précisément de ne rien dire.
     */
    private function drapeau(string $variable, bool $defaut): bool
    {
        $demande = filter_var(env($variable, $defaut), FILTER_VALIDATE_BOOLEAN);

        if (! $demande || ! $this->app->environment('production')) {
            return $demande;
        }

        if (! self::$refusSignale) {
            self::$refusSignale = true;
            Log::critical(
                "Simulacre REFUSE en production : {$variable} demande un service simule. "
                . 'Constat C18-016/F37-002 (S0) : un simulacre en production ecrit des donnees '
                . 'FABRIQUEES en base, indiscernables des vraies. Le service reel est branche a '
                . 'la place. Verifiez la configuration du conteneur (docker inspect), pas le '
                . '.env : `docker compose restart` ne relit pas env_file.',
                ['variable' => $variable, 'environnement' => $this->app->environment()],
            );
        }

        return false;
    }

    public function boot(): void
    {
        //
    }
}
