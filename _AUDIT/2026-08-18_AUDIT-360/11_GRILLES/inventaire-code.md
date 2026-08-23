# INVENTAIRE DU CODE — les quatre grilles du §4

> **Genere** par `generer-inventaire-code.py`. **Ne pas editer a la main** :
> corriger le script, puis rejouer. `--verifier` sort en 1 si ce fichier ne
> correspond plus au code.

> :warning: **CE QUE CES GRILLES SONT.** Un inventaire **mesure** : taille,
> methodes publiques, tables touchees, nombre de fichiers appelants, et si un
> test **nomme** l'objet.
>
> :red_circle: **CE QU'ELLES NE SONT PAS.** Un audit ligne a ligne. La colonne
> « nomme par un test » dit qu'un fichier de test prononce le nom — elle ne dit
> **rien** de ce qu'il verifie. `F36-009` le prouve : on peut reecrire les 11
> policies en refus total sans qu'aucune suite rougisse. Lire cette colonne
> comme « couvert » serait exactement le faux temoin que cet audit recense.

| famille | fichiers | attendu par l'audit |
|---|---:|---:|
| Policies | **11** | 11 |
| Services | **68** | 84 |
| Controleurs | **45** | 44 |
| Workers | **34** | 34 |

⚠️ **Les ecarts sont reels et ne sont pas corriges ici.** L'audit annonce 84
services et 44 controleurs ; `app/Services/` en porte 68 et
`app/Http/Controllers/` 45. Le compte de l'audit englobe probablement
d'autres dossiers (`app/Crm/`, `app/Support/`). **On inventorie ce qui existe,
on ne fabrique pas les lignes manquantes pour atteindre un chiffre.**

---

## 1. Policies — 11 fichiers

🔴 **Le resultat le plus parlant de cet inventaire : 9 des 11 policies sont des COQUILLES VIDES** — `class XPolicy extends BasePolicy {}`, cinq lignes, aucune methode propre. Toute la decision d'autorisation vit dans `BasePolicy`. Le constat `B12-003` disait « aucune policy n'est jamais appelee » ; la grille ajoute que, meme appelees, neuf d'entre elles n'auraient rien dit de particulier.

| Policy | Fichier | Lignes | Méthodes propres | Étend | Fichiers appelants | Nommée par un test |
|---|---|---|---|---|---|---|
| `AuditLogPolicy` | `backend/app/Policies/AuditLogPolicy.php` | 13 | viewAny | `BasePolicy` | 1 | **0** |
| `BasePolicy` | `backend/app/Policies/BasePolicy.php` | 98 | viewAny, view, create, update, delete | `—` | 10 | 1 |
| `CompanyPolicy` | `backend/app/Policies/CompanyPolicy.php` | 5 | — *(aucune : coquille vide)* | `BasePolicy` | 1 | **0** |
| `ContactPolicy` | `backend/app/Policies/ContactPolicy.php` | 5 | — *(aucune : coquille vide)* | `BasePolicy` | 1 | **0** |
| `LlmUseCasePolicy` | `backend/app/Policies/LlmUseCasePolicy.php` | 5 | — *(aucune : coquille vide)* | `BasePolicy` | 1 | **0** |
| `ProxyProviderPolicy` | `backend/app/Policies/ProxyProviderPolicy.php` | 5 | — *(aucune : coquille vide)* | `BasePolicy` | 1 | **0** |
| `RgpdRequestPolicy` | `backend/app/Policies/RgpdRequestPolicy.php` | 5 | — *(aucune : coquille vide)* | `BasePolicy` | 1 | **0** |
| `ScraperRunPolicy` | `backend/app/Policies/ScraperRunPolicy.php` | 5 | — *(aucune : coquille vide)* | `BasePolicy` | 1 | **0** |
| `TagPolicy` | `backend/app/Policies/TagPolicy.php` | 5 | — *(aucune : coquille vide)* | `BasePolicy` | 1 | **0** |
| `UserPolicy` | `backend/app/Policies/UserPolicy.php` | 5 | — *(aucune : coquille vide)* | `BasePolicy` | 1 | **0** |
| `WorkspacePolicy` | `backend/app/Policies/WorkspacePolicy.php` | 5 | — *(aucune : coquille vide)* | `BasePolicy` | 1 | **0** |

## 2. Contrôleurs — 45 fichiers

Un contrôleur sans aucun fichier appelant n'est pas forcément mort : il peut être monté par `routes/api.php` sous une forme que ce comptage ne voit pas. La colonne mesure, elle ne conclut pas.

| Objet | Fichier | Lignes | Méthodes publiques | Tables | Fichiers appelants | Nommé par un test |
|---|---|---|---|---|---|---|
| `AiActRegisterController` | `backend/app/Http/Controllers/Api/AiActRegisterController.php` | 167 | index, store | `ai_act_register` | 1 | **0** |
| `ApiController` | `backend/app/Http/Controllers/Api/ApiController.php` | 198 | — | — | 36 | 2 |
| `AudiencesController` | `backend/app/Http/Controllers/Api/AudiencesController.php` | 223 | __construct, index, store, show, update, destroy… | — | 1 | **0** |
| `AuditLogsController` | `backend/app/Http/Controllers/Api/AuditLogsController.php` | 100 | __construct, index, verifyChain | — | 1 | **0** |
| `AuthController` | `backend/app/Http/Controllers/Api/Auth/AuthController.php` | 126 | __construct, login, logout, me, completeOnboardingTour | — | 1 | **0** |
| `MagicLinkController` | `backend/app/Http/Controllers/Api/Auth/MagicLinkController.php` | 71 | __construct, request, verify | — | 1 | **0** |
| `PasswordResetController` | `backend/app/Http/Controllers/Api/Auth/PasswordResetController.php` | 144 | forgot, reset | `password_reset_tokens` | 1 | 1 |
| `TwoFactorController` | `backend/app/Http/Controllers/Api/Auth/TwoFactorController.php` | 81 | __construct, setup, confirm, verify | — | 1 | **0** |
| `CompaniesController` | `backend/app/Http/Controllers/Api/CompaniesController.php` | 666 | __construct, index, export, store, show, update… | — | 1 | 1 |
| `CompanyTagsBulkController` | `backend/app/Http/Controllers/Api/CompanyTagsBulkController.php` | 170 | __invoke | `companies`, `company_tag`, `tags` | 1 | **0** |
| `ContactsController` | `backend/app/Http/Controllers/Api/ContactsController.php` | 180 | index, show, update, destroy | — | 1 | **0** |
| `CoverageController` | `backend/app/Http/Controllers/Api/CoverageController.php` | 322 | __construct, index, nextZone, launch, enrich, showCell | `departments` | 1 | **0** |
| `ArbitrageController` | `backend/app/Http/Controllers/Api/Crm/ArbitrageController.php` | 310 | __construct, index, attach, dismiss | `activities`, `companies` | 1 | **0** |
| `BulkController` | `backend/app/Http/Controllers/Api/Crm/BulkController.php` | 329 | __construct, __invoke | `activities`, `tags` | 1 | **0** |
| `CandidatesController` | `backend/app/Http/Controllers/Api/Crm/CandidatesController.php` | 233 | index, counts | `candidates` | 1 | **0** |
| `ConsoleController` | `backend/app/Http/Controllers/Api/Crm/ConsoleController.php` | 77 | — | — | 5 | **0** |
| `ContactsHubController` | `backend/app/Http/Controllers/Api/Crm/ContactsHubController.php` | 447 | index, counts | `tags` | 1 | 1 |
| `PersonTimelineController` | `backend/app/Http/Controllers/Api/Crm/PersonTimelineController.php` | 285 | show | `activities`, `candidates`, `contacts` | 1 | 1 |
| `DashboardController` | `backend/app/Http/Controllers/Api/DashboardController.php` | 182 | stats | — | 1 | **0** |
| `FeaturesController` | `backend/app/Http/Controllers/Api/FeaturesController.php` | 52 | index | — | 1 | **0** |
| `GlobalSearchController` | `backend/app/Http/Controllers/Api/GlobalSearchController.php` | 250 | index | `companies`, `contacts`, `tags` | 1 | **0** |
| `JournalistsController` | `backend/app/Http/Controllers/Api/JournalistsController.php` | 328 | index, export, show, optOut, destroy | — | 1 | **0** |
| `LlmUsageController` | `backend/app/Http/Controllers/Api/LlmUsageController.php` | 228 | index, summary | `llm_usage` | 1 | **0** |
| `LlmUseCasesController` | `backend/app/Http/Controllers/Api/LlmUseCasesController.php` | 261 | index, update, prompts, updatePrompt | `llm_use_cases` | 1 | **0** |
| `MediaController` | `backend/app/Http/Controllers/Api/MediaController.php` | 276 | index, export, show | — | 1 | **0** |
| `NotificationsController` | `backend/app/Http/Controllers/Api/NotificationsController.php` | 213 | index, markRead, markAllRead | `notifications` | 1 | 1 |
| `ObservabilityController` | `backend/app/Http/Controllers/Api/ObservabilityController.php` | 272 | summary | `activities`, `business_events`, `companies`, `crm_outbound_events`, `email_verification_logs`, `scraper_runs` | 1 | 1 |
| `ColdEmailController` | `backend/app/Http/Controllers/Api/Phase2/ColdEmailController.php` | 21 | __invoke | — | 1 | **0** |
| `LinkedInController` | `backend/app/Http/Controllers/Api/Phase2/LinkedInController.php` | 21 | __invoke | — | 1 | **0** |
| `ProxyProvidersController` | `backend/app/Http/Controllers/Api/ProxyProvidersController.php` | 196 | index, update, test | `proxy_providers_config` | 1 | **0** |
| `ReferentielsGeoController` | `backend/app/Http/Controllers/Api/ReferentielsGeoController.php` | 78 | index | — | 1 | **0** |
| `RgpdRequestsController` | `backend/app/Http/Controllers/Api/RgpdRequestsController.php` | 347 | __construct, index, store, process, export | — | 1 | 1 |
| `RotationsController` | `backend/app/Http/Controllers/Api/RotationsController.php` | 183 | index, update | `rotations` | 1 | **0** |
| `SavedViewsController` | `backend/app/Http/Controllers/Api/SavedViewsController.php` | 368 | index, store, show, update, destroy | `saved_views` | 1 | 1 |
| `ScraperRunsController` | `backend/app/Http/Controllers/Api/ScraperRunsController.php` | 245 | index, show, cancel, retry | — | 1 | **0** |
| `ScrapingCampaignsController` | `backend/app/Http/Controllers/Api/ScrapingCampaignsController.php` | 505 | index, store, show, update, destroy, start… | `scraper_runs` | 1 | **0** |
| `TagsController` | `backend/app/Http/Controllers/Api/TagsController.php` | 201 | index, store, update, destroy | `company_tag` | 1 | **0** |
| `UsersController` | `backend/app/Http/Controllers/Api/UsersController.php` | 303 | index, store, update, destroy | `user_workspaces` | 1 | **0** |
| `WorkspaceController` | `backend/app/Http/Controllers/Api/WorkspaceController.php` | 141 | show, update | `workspaces` | 1 | **0** |
| `VerrouOptimiste` | `backend/app/Http/Controllers/Concerns/VerrouOptimiste.php` | 235 | — | — | 4 | 1 |
| `Controller` | `backend/app/Http/Controllers/Controller.php` | 20 | — | — | 2 | 1 |
| `ScraperResultController` | `backend/app/Http/Controllers/Internal/ScraperResultController.php` | 225 | __construct, store | — | 1 | 1 |
| `SiteGdprController` | `backend/app/Http/Controllers/Internal/SiteGdprController.php` | 104 | __construct, store | — | 1 | **0** |
| `SiteSyncController` | `backend/app/Http/Controllers/Internal/SiteSyncController.php` | 95 | __construct, store | — | 1 | **0** |
| `ZeptoMailWebhookController` | `backend/app/Http/Controllers/Internal/ZeptoMailWebhookController.php` | 239 | store | — | 1 | **0** |

## 3. Services — 68 fichiers

La colonne « tables » liste ce que le fichier touche par `DB::table(...)`. Elle ne voit **pas** l'accès par modèle Eloquent : un service à zéro table peut parfaitement écrire en base.

| Objet | Fichier | Lignes | Méthodes publiques | Tables | Fichiers appelants | Nommé par un test |
|---|---|---|---|---|---|---|
| `AlerteTelegram` | `backend/app/Services/Alertes/AlerteTelegram.php` | 157 | envoyer | — | 2 | 3 |
| `HttpAnnuaireEntreprisesClient` | `backend/app/Services/AnnuaireEntreprises/HttpAnnuaireEntreprisesClient.php` | 80 | fetchBySiren | — | 1 | 4 |
| `MockAnnuaireEntreprisesClient` | `backend/app/Services/AnnuaireEntreprises/Mocks/MockAnnuaireEntreprisesClient.php` | 25 | fetchBySiren | — | 1 | 1 |
| `AudienceBuilderService` | `backend/app/Services/Audiences/AudienceBuilderService.php` | 723 | preview, refresh, buildPublicQuery, evaluateForCompany | `audience_members`, `contacts` | 5 | 5 |
| `CritereAudienceInvalide` | `backend/app/Services/Audiences/CritereAudienceInvalide.php` | 43 | — | — | 1 | 2 |
| `AuditHashChain` | `backend/app/Services/Audit/AuditHashChain.php` | 231 | __construct, secretEstUtilisable, raisonSecretInutilisable, record, verifyChain | `audit_logs` | 8 | 8 |
| `AuthService` | `backend/app/Services/Auth/AuthService.php` | 157 | attemptLogin, logout | — | 1 | 1 |
| `HibpChecker` | `backend/app/Services/Auth/HibpChecker.php` | 126 | __construct, getBreachCount, isBreached | — | 1 | 5 |
| `MagicLinkService` | `backend/app/Services/Auth/MagicLinkService.php` | 99 | issue, consume | `magic_links` | 1 | 4 |
| `TwoFactorService` | `backend/app/Services/Auth/TwoFactorService.php` | 134 | __construct, startEnrolment, confirmEnrolment, verify | — | 1 | 1 |
| `HttpBanGeocoder` | `backend/app/Services/Ban/HttpBanGeocoder.php` | 44 | geocode | — | 1 | 2 |
| `MockBanGeocoder` | `backend/app/Services/Ban/Mocks/MockBanGeocoder.php` | 23 | geocode | — | 1 | 1 |
| `HttpBodaccClient` | `backend/app/Services/Bodacc/HttpBodaccClient.php` | 55 | fetchAnnouncementsBySiren | — | 1 | 2 |
| `MockBodaccClient` | `backend/app/Services/Bodacc/Mocks/MockBodaccClient.php` | 13 | fetchAnnouncementsBySiren | — | 1 | 1 |
| `MockCaptchaSolver` | `backend/app/Services/Captcha/Mocks/MockCaptchaSolver.php` | 13 | solve | — | 1 | 1 |
| `TwoCaptchaSolver` | `backend/app/Services/Captcha/TwoCaptchaSolver.php` | 13 | solve | — | 1 | 3 |
| `AutoClassifierService` | `backend/app/Services/Classification/AutoClassifierService.php` | 250 | classify | — | 1 | 1 |
| `AutoTagApplier` | `backend/app/Services/Classification/AutoTagApplier.php` | 122 | apply, matches | — | 1 | 1 |
| `ClassifierService` | `backend/app/Services/Classification/ClassifierService.php` | 98 | __construct, classify | — | 0 | **0** |
| `DeduplicationService` | `backend/app/Services/Dedup/DeduplicationService.php` | 391 | findCompanyBySiren, findContactByNormalizedHash, computeContactHash, isScrapeFresh, buildDedupKey, ttlDays… | `coverage_zones`, `email_validations`, `opt_out`, `scraping_sources` | 5 | 9 |
| `DomainFinderService` | `backend/app/Services/Domain/DomainFinderService.php` | 584 | find, guessDomainsBatch, revalidateBatch | — | 3 | 5 |
| `EmailConfidenceService` | `backend/app/Services/Email/EmailConfidenceService.php` | 166 | score | — | 6 | 1 |
| `EmailFinderService` | `backend/app/Services/Email/EmailFinderService.php` | 295 | __construct, verifyEmail, find, generateCandidates, renderPattern, detectPatternFromKnownEmails | — | 1 | 2 |
| `HunterEmailVerifier` | `backend/app/Services/Email/HunterEmailVerifier.php` | 193 | verify | `email_verification_logs` | 3 | 3 |
| `MxEmailValidator` | `backend/app/Services/Email/MxEmailValidator.php` | 243 | validate, quickStatus, isValidSyntax, resolveMxRecords | — | 5 | 4 |
| `FranceTravailDiscoveryClient` | `backend/app/Services/FranceTravail/FranceTravailDiscoveryClient.php` | 223 | __construct, searchEntreprisesByDept | — | 1 | 5 |
| `HttpFranceTravailClient` | `backend/app/Services/FranceTravail/HttpFranceTravailClient.php` | 73 | fetchOffersBySiren | — | 1 | 2 |
| `MockFranceTravailClient` | `backend/app/Services/FranceTravail/Mocks/MockFranceTravailClient.php` | 13 | fetchOffersBySiren | — | 1 | 1 |
| `ProxiedHttpClient` | `backend/app/Services/Http/ProxiedHttpClient.php` | 80 | request, isProxyEnabled | — | 1 | 3 |
| `SsrfGuard` | `backend/app/Services/Http/SsrfGuard.php` | 294 | — | — | 11 | 6 |
| `HttpInseeClient` | `backend/app/Services/Insee/HttpInseeClient.php` | 401 | fetchBySiren, searchByCriteria, iterateByCriteria | — | 2 | 5 |
| `MockInseeClient` | `backend/app/Services/Insee/Mocks/MockInseeClient.php` | 42 | fetchBySiren, searchByCriteria, iterateByCriteria | — | 1 | 1 |
| `MentionsLegalesScraperService` | `backend/app/Services/Legal/MentionsLegalesScraperService.php` | 616 | __construct, scrape, fetchPagesText, harvestFromWebsite | `contacts` | 3 | 4 |
| `LLMRouterService` | `backend/app/Services/LLM/LLMRouterService.php` | 260 | complete | `llm_usage`, `prompt_template_versions`, `prompt_templates`, `workspaces` | 1 | 1 |
| `MockLLMClient` | `backend/app/Services/LLM/Mocks/MockLLMClient.php` | 65 | complete | — | 1 | 2 |
| `ProviderFactory` | `backend/app/Services/LLM/ProviderFactory.php` | 24 | — | — | 1 | **0** |
| `AnthropicProvider` | `backend/app/Services/LLM/Providers/AnthropicProvider.php` | 88 | __construct, complete, lastUsage | — | 1 | 1 |
| `GroqProvider` | `backend/app/Services/LLM/Providers/GroqProvider.php` | 59 | __construct, complete, lastUsage | — | 1 | 1 |
| `MistralProvider` | `backend/app/Services/LLM/Providers/MistralProvider.php` | 68 | __construct, complete, lastUsage | — | 1 | 1 |
| `OpenAIProvider` | `backend/app/Services/LLM/Providers/OpenAIProvider.php` | 60 | __construct, complete, lastUsage | — | 1 | 1 |
| `TogetherProvider` | `backend/app/Services/LLM/Providers/TogetherProvider.php` | 58 | __construct, complete, lastUsage | — | 1 | 1 |
| `SectorClassifier` | `backend/app/Services/Prospection/SectorClassifier.php` | 49 | — | — | 2 | **0** |
| `IPRoyalProvider` | `backend/app/Services/Proxies/IPRoyalProvider.php` | 77 | listEndpoints, pickEndpoint, healthCheck | — | 0 | 2 |
| `MockProxyProvider` | `backend/app/Services/Proxies/Mocks/MockProxyProvider.php` | 26 | listEndpoints, pickEndpoint, healthCheck | — | 1 | 2 |
| `VerificationTlsSonde` | `backend/app/Services/Proxies/VerificationTlsSonde.php` | 53 | — | — | 2 | 1 |
| `WebshareProvider` | `backend/app/Services/Proxies/WebshareProvider.php` | 100 | listEndpoints, pickEndpoint, healthCheck | — | 1 | 3 |
| `GdprErasureService` | `backend/app/Services/Rgpd/GdprErasureService.php` | 311 | __construct, erase | `activities`, `candidates`, `contacts`, `email_messages`, `email_validations`, `email_verification_logs`, `health_practitioners`, `invitations`, `journalists`, `magic_links`, `media`, `notifications`, `password_reset_tokens`, `rgpd_requests`, `sessions`, `users` | 1 | 8 |
| `GdprPortabilityService` | `backend/app/Services/Rgpd/GdprPortabilityService.php` | 284 | export, retrieve | `activities`, `candidates`, `contacts`, `crm_outbound_events`, `dnc_entries`, `email_messages`, `email_suppressions`, `email_validations`, `email_verification_logs`, `health_practitioners`, `invitations`, `journalists`, `magic_links`, `media`, `notifications`, `opt_out`, `password_reset_tokens`, `rgpd_requests`, `sessions`, `unsubscribes`, `users` | 1 | 4 |
| `SearchEngineRotator` | `backend/app/Services/Rotations/SearchEngineRotator.php` | 33 | pick | `search_engines` | 0 | 1 |
| `WeightedRoundRobin` | `backend/app/Services/Rotations/WeightedRoundRobin.php` | 59 | pick | `rotations` | 0 | 1 |
| `ZoneRotator` | `backend/app/Services/Rotations/ZoneRotator.php` | 43 | pickNextZone | — | 1 | **0** |
| `GooglePlacesClient` | `backend/app/Services/Scraping/GooglePlacesClient.php` | 303 | searchText, monthlyQuotaLimit, currentMonthUsage, isQuotaExceeded, flatten | — | 4 | 4 |
| `MockDirectionFinder` | `backend/app/Services/Scraping/Mocks/MockDirectionFinder.php` | 20 | findCLevel | — | 1 | 1 |
| `MockGoogleMapsScraper` | `backend/app/Services/Scraping/Mocks/MockGoogleMapsScraper.php` | 23 | scrape | — | 1 | 1 |
| `MockPagesJaunesScraper` | `backend/app/Services/Scraping/Mocks/MockPagesJaunesScraper.php` | 21 | scrape | — | 1 | 1 |
| `MockSearchEngine` | `backend/app/Services/Scraping/Mocks/MockSearchEngine.php` | 21 | search, name | — | 1 | 1 |
| `MockWebsiteScraper` | `backend/app/Services/Scraping/Mocks/MockWebsiteScraper.php` | 21 | scrape | — | 1 | 1 |
| `PlaywrightDirectionFinder` | `backend/app/Services/Scraping/PlaywrightDirectionFinder.php` | 14 | findCLevel | — | 1 | 1 |
| `PlaywrightGoogleMapsScraper` | `backend/app/Services/Scraping/PlaywrightGoogleMapsScraper.php` | 15 | scrape | — | 1 | 1 |
| `PlaywrightPagesJaunesScraper` | `backend/app/Services/Scraping/PlaywrightPagesJaunesScraper.php` | 15 | scrape | — | 1 | 1 |
| `PlaywrightSearchEngine` | `backend/app/Services/Scraping/PlaywrightSearchEngine.php` | 18 | search, name | — | 1 | 1 |
| `PlaywrightWebsiteScraper` | `backend/app/Services/Scraping/PlaywrightWebsiteScraper.php` | 15 | scrape | — | 1 | 1 |
| `HunterSmtpProber` | `backend/app/Services/Smtp/HunterSmtpProber.php` | 58 | __construct, probe | — | 1 | 2 |
| `MockSmtpProber` | `backend/app/Services/Smtp/Mocks/MockSmtpProber.php` | 61 | probe | — | 1 | 3 |
| `RealSmtpProber` | `backend/app/Services/Smtp/RealSmtpProber.php` | 160 | probe | — | 1 | 1 |
| `AutoTaggerService` | `backend/app/Services/Tags/AutoTaggerService.php` | 288 | syncTags | `company_tag` | 2 | 2 |
| `TriageAutoService` | `backend/app/Services/Triage/TriageAutoService.php` | 93 | triage | `contacts` | 3 | **0** |
| `WaterfallOrchestrator` | `backend/app/Services/Waterfall/WaterfallOrchestrator.php` | 748 | __construct, enrich | `contacts`, `email_audiences`, `scraper_runs` | 3 | 6 |

## 4. Workers — 34 fichiers

🔴 Rappel de `C18-018` : **aucun des 13 scrapers n'est couvert par un test, et aucun n'est déployé.** La colonne « nommé par un test » le confirme fichier par fichier.

| Objet | Fichier | Lignes | Méthodes publiques | Tables | Fichiers appelants | Nommé par un test |
|---|---|---|---|---|---|---|
| `queues` | `workers/src/bridge/queues.ts` | 20 | QUEUES | — | 11 | 1 |
| `redis` | `workers/src/bridge/redis.ts` | 10 | getRedis | — | 3 | 1 |
| `result-sender` | `workers/src/bridge/result-sender.ts` | 34 | sendResult | — | 13 | 3 |
| `launcher` | `workers/src/browser/launcher.ts` | 85 | getBrowser, createContext, closeBrowser | — | 6 | 1 |
| `mocks` | `workers/src/config/mocks.ts` | 84 | drapeauSimulacresAmbigu, useMockScrapers | — | 12 | 1 |
| `healthcheck-server` | `workers/src/healthcheck-server.ts` | 46 | tickJob, startHealthcheckServer | — | 2 | **0** |
| `healthcheck` | `workers/src/healthcheck.ts` | 17 | — | — | 2 | **0** |
| `main` | `workers/src/main.ts` | 65 | — | — | 1 | 3 |
| `MockDirectionFinderScraper` | `workers/src/mocks/MockDirectionFinderScraper.ts` | 23 | MockDirectionFinderScraper | — | 1 | **0** |
| `MockGoogleMapsScraper` | `workers/src/mocks/MockGoogleMapsScraper.ts` | 23 | MockGoogleMapsScraper | — | 1 | **0** |
| `MockHttpSourceScraper` | `workers/src/mocks/MockHttpSourceScraper.ts` | 27 | MockHttpSourceScraper | — | 6 | **0** |
| `MockPagesJaunesScraper` | `workers/src/mocks/MockPagesJaunesScraper.ts` | 15 | MockPagesJaunesScraper | — | 1 | **0** |
| `MockSearchScraper` | `workers/src/mocks/MockSearchScraper.ts` | 22 | MockSearchScraper | — | 1 | **0** |
| `MockWebsiteScraper` | `workers/src/mocks/MockWebsiteScraper.ts` | 15 | MockWebsiteScraper | — | 1 | **0** |
| `base` | `workers/src/scrapers/base.ts` | 319 | startWorker, PREFIXE_ANNULATION | — | 25 | 2 |
| `crunchbase.worker` | `workers/src/scrapers/crunchbase.worker.ts` | 13 | startCrunchbaseWorker | — | 0 | **0** |
| `direction-finder.playwright` | `workers/src/scrapers/direction-finder.playwright.ts` | 118 | PlaywrightDirectionFinder | — | 0 | **0** |
| `direction-finder.worker` | `workers/src/scrapers/direction-finder.worker.ts` | 13 | startDirectionFinderWorker | — | 0 | **0** |
| `france-travail.worker` | `workers/src/scrapers/france-travail.worker.ts` | 13 | startFranceTravailWorker | — | 0 | **0** |
| `google-maps.playwright` | `workers/src/scrapers/google-maps.playwright.ts` | 56 | PlaywrightGoogleMapsScraper | — | 1 | **0** |
| `google-maps.worker` | `workers/src/scrapers/google-maps.worker.ts` | 13 | startGoogleMapsWorker | — | 0 | **0** |
| `google-search.playwright` | `workers/src/scrapers/google-search.playwright.ts` | 78 | PlaywrightSearchScraper | — | 0 | **0** |
| `google-search.worker` | `workers/src/scrapers/google-search.worker.ts` | 13 | startGoogleSearchWorker | — | 0 | **0** |
| `http-source` | `workers/src/scrapers/http-source.ts` | 92 | HttpSourceScraper | — | 5 | **0** |
| `infogreffe.worker` | `workers/src/scrapers/infogreffe.worker.ts` | 13 | startInfogreffeWorker | — | 0 | **0** |
| `mesri.worker` | `workers/src/scrapers/mesri.worker.ts` | 13 | startMesriWorker | — | 0 | **0** |
| `pages-jaunes.playwright` | `workers/src/scrapers/pages-jaunes.playwright.ts` | 45 | PlaywrightPagesJaunesScraper | — | 0 | **0** |
| `pages-jaunes.worker` | `workers/src/scrapers/pages-jaunes.worker.ts` | 13 | startPagesJaunesWorker | — | 0 | **0** |
| `social-light.worker` | `workers/src/scrapers/social-light.worker.ts` | 13 | startSocialLightWorker | — | 0 | **0** |
| `societe-com.worker` | `workers/src/scrapers/societe-com.worker.ts` | 13 | startSocieteComWorker | — | 0 | **0** |
| `website.playwright` | `workers/src/scrapers/website.playwright.ts` | 71 | PlaywrightWebsiteScraper | — | 0 | **0** |
| `website.worker` | `workers/src/scrapers/website.worker.ts` | 13 | startWebsiteWorker | — | 0 | **0** |
| `extract` | `workers/src/utils/extract.ts` | 22 | extractEmails, extractPhones | — | 3 | 2 |
| `ssrf-guard` | `workers/src/utils/ssrf-guard.ts` | 119 | checkSsrf, ensureSsrf | — | 2 | 2 |

