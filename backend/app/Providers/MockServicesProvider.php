<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Contracts\LLMClient;
use App\Contracts\ProxyProvider;
use App\Contracts\CaptchaSolver;
use App\Contracts\SmtpProber;
use App\Contracts\InseeClient;
use App\Contracts\AnnuaireEntreprisesClient;
use App\Contracts\BodaccClient;
use App\Contracts\BanGeocoder;
use App\Contracts\FranceTravailClient;
use App\Contracts\GoogleMapsScraper;
use App\Contracts\PagesJaunesScraper;
use App\Contracts\WebsiteScraper;
use App\Contracts\SearchEngine;
use App\Contracts\DirectionFinder;
use App\Services\LLM\LLMRouterService;
use App\Services\LLM\Mocks\MockLLMClient;
use App\Services\Proxies\Mocks\MockProxyProvider;
use App\Services\Proxies\WebshareProvider;
use App\Services\Captcha\TwoCaptchaSolver;
use App\Services\Captcha\Mocks\MockCaptchaSolver;
use App\Services\Smtp\RealSmtpProber;
use App\Services\Smtp\Mocks\MockSmtpProber;
use App\Services\Insee\HttpInseeClient;
use App\Services\Insee\Mocks\MockInseeClient;
use App\Services\AnnuaireEntreprises\HttpAnnuaireEntreprisesClient;
use App\Services\AnnuaireEntreprises\Mocks\MockAnnuaireEntreprisesClient;
use App\Services\Bodacc\HttpBodaccClient;
use App\Services\Bodacc\Mocks\MockBodaccClient;
use App\Services\Ban\HttpBanGeocoder;
use App\Services\Ban\Mocks\MockBanGeocoder;
use App\Services\FranceTravail\HttpFranceTravailClient;
use App\Services\FranceTravail\Mocks\MockFranceTravailClient;
use App\Services\Scraping\Mocks\MockGoogleMapsScraper;
use App\Services\Scraping\Mocks\MockPagesJaunesScraper;
use App\Services\Scraping\Mocks\MockWebsiteScraper;
use App\Services\Scraping\Mocks\MockSearchEngine;
use App\Services\Scraping\Mocks\MockDirectionFinder;
use App\Services\Scraping\PlaywrightGoogleMapsScraper;
use App\Services\Scraping\PlaywrightPagesJaunesScraper;
use App\Services\Scraping\PlaywrightWebsiteScraper;
use App\Services\Scraping\PlaywrightSearchEngine;
use App\Services\Scraping\PlaywrightDirectionFinder;

/**
 * Bind real vs mock implementations based on env (.env MOCK_MODE / MOCK_<SERVICE>).
 * Cf. MOCKS-STRATEGY.md.
 */
class MockServicesProvider extends ServiceProvider
{
    public function register(): void
    {
        $master = (bool) env('MOCK_MODE', true);

        $bind = function (string $contract, string $real, string $mock, string $envFlag) use ($master) {
            $useMock = (bool) env($envFlag, $master);
            $this->app->bind($contract, $useMock ? $mock : $real);
        };

        $bind(LLMClient::class,                MockLLMClient::class, MockLLMClient::class, 'MOCK_LLM');
        // ↑ tant que LLMRouterService n'est pas testé en réseau, on garde mock par défaut.
        $this->app->bind(LLMRouterService::class, LLMRouterService::class);

        $bind(ProxyProvider::class,            WebshareProvider::class,           MockProxyProvider::class,           'MOCK_PROXIES');
        $bind(CaptchaSolver::class,            TwoCaptchaSolver::class,           MockCaptchaSolver::class,           'MOCK_CAPTCHA');
        $bind(SmtpProber::class,               RealSmtpProber::class,             MockSmtpProber::class,              'MOCK_SMTP');
        $bind(InseeClient::class,              HttpInseeClient::class,            MockInseeClient::class,             'MOCK_INSEE');
        $bind(AnnuaireEntreprisesClient::class,HttpAnnuaireEntreprisesClient::class,MockAnnuaireEntreprisesClient::class,'MOCK_ANNUAIRE_ENTREPRISES');
        $bind(BodaccClient::class,             HttpBodaccClient::class,           MockBodaccClient::class,            'MOCK_BODACC');
        $bind(BanGeocoder::class,              HttpBanGeocoder::class,            MockBanGeocoder::class,             'MOCK_BAN');
        $bind(FranceTravailClient::class,      HttpFranceTravailClient::class,    MockFranceTravailClient::class,     'MOCK_FRANCE_TRAVAIL');

        $bind(GoogleMapsScraper::class,        PlaywrightGoogleMapsScraper::class,MockGoogleMapsScraper::class,       'MOCK_SCRAPERS');
        $bind(PagesJaunesScraper::class,       PlaywrightPagesJaunesScraper::class,MockPagesJaunesScraper::class,     'MOCK_SCRAPERS');
        $bind(WebsiteScraper::class,           PlaywrightWebsiteScraper::class,   MockWebsiteScraper::class,          'MOCK_SCRAPERS');
        $bind(SearchEngine::class,             PlaywrightSearchEngine::class,     MockSearchEngine::class,            'MOCK_SCRAPERS');
        $bind(DirectionFinder::class,          PlaywrightDirectionFinder::class,  MockDirectionFinder::class,         'MOCK_SCRAPERS');
    }

    public function boot(): void
    {
        //
    }
}
