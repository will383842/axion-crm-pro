# Stratégie mocks Axion CRM Pro (Sprint 1 → S12)

> **But :** coder l'intégralité de la plateforme Phase 1 SANS dépendre de services externes payants (LLM, proxies, captcha, SMTP). Tests verts en local + CI. Basculement vers vrais services en 1 ligne (`MOCK_MODE=false`).

---

## Principe directeur

Toute interface qui appelle un service externe doit avoir **2 implémentations** injectables :

| Service externe | Interface | Implémentation prod | Implémentation mock |
|----------------|-----------|---------------------|---------------------|
| LLM (Anthropic/Mistral/OpenAI) | `LLMClient` | `LLMRouterService` (HTTP appels Anthropic, etc.) | `MockLLMClient` retournant fixtures JSON |
| Proxies (Webshare/IPRoyal) | `ProxyProvider` | `WebshareProvider`, `IPRoyalProvider` | `MockProxyProvider` retournant `http://localhost:0` no-op |
| Captcha solver | `CaptchaSolver` | `TwoCaptchaSolver` | `MockCaptchaSolver` retournant token bidon |
| SMTP probe | `SmtpProber` | `RealSmtpProber` (port 25 sortant) | `MockSmtpProber` retournant 250 OK ou 550 selon liste hardcodée |
| Google Maps scraper | `GoogleMapsScraper` | Playwright + proxy résidentiel | `MockGoogleMapsScraper` retournant HTML fixtures local |
| Google Search Wrapper | `SearchEngine` | Playwright + IPRoyal + 2captcha | `MockSearchEngine` retournant SERP JSON fixtures |
| Sites web scraper | `WebsiteScraper` | Playwright + datacenter | `MockWebsiteScraper` retournant HTML fixtures (10 sites pré-enregistrés) |
| Direction Finder | `DirectionFinder` | 4 sources combinées | `MockDirectionFinder` retournant C-level fixtures pour 20 ETI |
| INSEE Sirene API | `InseeClient` | HTTP api.insee.fr | `MockInseeClient` retournant JSON fixtures 100 SIRENs |
| annuaire-entreprises API | `AnnuaireEntreprisesClient` | HTTP recherche-entreprises.api.gouv.fr | `MockAnnuaireEntreprisesClient` retournant fixtures |
| BODACC API | `BodaccClient` | HTTP bodacc-datafluide | `MockBodaccClient` retournant fixtures |
| BAN API | `BanGeocoder` | HTTP api-adresse.data.gouv.fr | `MockBanGeocoder` retournant lat/lon hardcodés |
| France Travail API | `FranceTravailClient` | HTTP api.francetravail.io | `MockFranceTravailClient` retournant fixtures |
| Cloudflare/Hetzner DNS | `DnsManager` | API Cloudflare/Hetzner | `MockDnsManager` no-op (logs only) |
| Email envoi (Phase 2) | `EmailSender` | AWS SES / Mailgun / Postmark | `MockEmailSender` log only |

---

## Variable d'environnement maîtresse

```env
# .env
MOCK_MODE=true       # tous les services externes → mocks
# MOCK_MODE=false    # vrais services (requiert credentials)
```

Ou plus granulaire si besoin :

```env
MOCK_LLM=true
MOCK_PROXIES=true
MOCK_SCRAPERS=true
MOCK_SMTP=true
MOCK_CAPTCHA=true
# Les APIs officielles peuvent rester en mock même en prod tests
MOCK_INSEE=false             # peut tourner en réel (gratuit, rate-limit léger)
MOCK_ANNUAIRE_ENTREPRISES=false
MOCK_BODACC=false
MOCK_BAN=false
MOCK_FRANCE_TRAVAIL=false
```

---

## Injection dans Laravel (DI container)

```php
// app/Providers/MockServicesProvider.php
class MockServicesProvider extends ServiceProvider
{
    public function register(): void
    {
        if (env('MOCK_LLM', false)) {
            $this->app->bind(LLMClient::class, MockLLMClient::class);
        } else {
            $this->app->bind(LLMClient::class, LLMRouterService::class);
        }

        if (env('MOCK_PROXIES', false)) {
            $this->app->bind(ProxyProvider::class, MockProxyProvider::class);
        }
        // ... idem pour les autres
    }
}
```

---

## Fixtures

Tous les mocks lisent depuis `tests/fixtures/<service>/*.json`. Exemple structure :

```
tests/fixtures/
├── insee/
│   ├── siren_axion_ia.json          (réponse INSEE pour AXION-IA OÜ)
│   ├── siren_total_energies.json
│   └── ...
├── annuaire-entreprises/
│   ├── dirigeants_eti_test_001.json
│   └── ...
├── google-maps/
│   ├── boulangerie_paris.html
│   ├── plomberie_lyon.html
│   └── ...
├── google-search/
│   ├── linkedin_company_carrefour.html
│   ├── linkedin_person_patrick_pouyanne.html
│   └── ...
├── llm/
│   ├── classify_company_axion__pme_tech.json
│   ├── extract_team_from_page__totalenergies.json
│   └── ...
├── direction-finder/
│   ├── totalenergies__c_level_response.json
│   └── ...
└── smtp/
    └── email_status_map.json        (mapping email → status retourné par mock)
```

---

## Exemple `MockLLMClient`

```php
// app/Services/LLM/Mocks/MockLLMClient.php
class MockLLMClient implements LLMClient
{
    public function complete(LLMRequestData $req): LLMResponseData
    {
        $fixturePath = base_path("tests/fixtures/llm/{$req->useCaseSlug}.json");
        if (!file_exists($fixturePath)) {
            // Fallback générique selon use case
            return $this->genericFixture($req);
        }
        $data = json_decode(file_get_contents($fixturePath), true);
        return LLMResponseData::from([
            ...$data,
            'cacheHit' => false,
            'providerUsed' => 'mock',
            'modelUsed' => 'mock-fixture',
            'costEur' => 0,
            'latencyMs' => 10,
        ]);
    }

    private function genericFixture(LLMRequestData $req): LLMResponseData
    {
        // Retourne réponse minimaliste cohérente par use case
        $text = match ($req->useCaseSlug) {
            'classify_company_axion' => json_encode([
                'ia_maturity' => ['score' => 60, 'label' => 'en_cours', 'justification' => 'mock'],
                'axion_offer_match' => ['offer_code' => 'mission_pme', 'score' => 65, 'justification' => 'mock'],
                'priority' => 'moyenne',
            ]),
            'sector_classification' => json_encode(['secteur_metier_axion' => 'tech', 'maturite_ia_visible' => 'en_cours']),
            'extract_team_from_page' => json_encode([]),
            'detect_email_pattern' => json_encode(['pattern' => '{first}.{last}@{domain}', 'confidence' => 80]),
            default => '{}',
        };
        return LLMResponseData::from([
            'text' => $text, 'providerUsed' => 'mock', 'modelUsed' => 'mock-generic',
            'tokensInput' => 100, 'tokensOutput' => 50, 'costEur' => 0, 'latencyMs' => 5,
            'cacheHit' => false, 'requestHash' => null,
            'promptTemplateSlug' => $req->useCaseSlug, 'promptTemplateVersion' => 1,
        ]);
    }
}
```

---

## Tests E2E avec mocks

Tous les tests Playwright/Pest E2E tournent avec `MOCK_MODE=true` :
- ✅ Reproductibilité 100 % (mêmes fixtures → mêmes résultats)
- ✅ Pas de coût $ par run CI
- ✅ Pas de dépendance internet/services tiers
- ✅ Rapide (~30 sec/test au lieu de plusieurs minutes)

---

## Basculement réel : checklist

Quand Will sera prêt à basculer en réel (après POCs validés) :

1. [ ] Souscrire services tiers (cf. `poc/README.md` § Services)
2. [ ] Charger credentials dans Doppler/Infisical
3. [ ] `MOCK_MODE=false` dans `.env` prod
4. [ ] Run POCs réels (POC #1 à #5) pour valider l'intégration
5. [ ] Run tests E2E avec `MOCK_MODE=false` sur staging (smoke tests)
6. [ ] Si tout vert → promotion prod

**Tout le code Sprint 1-12 reste inchangé.** Seules les bindings DI changent selon `MOCK_MODE`.

---

## Avantages stratégie mocks

1. ✅ **Démarrage immédiat** : code Sprint 1-12 sans attendre souscriptions services
2. ✅ **Budget zéro** pour le dev (0 $ jusqu'à la promotion prod)
3. ✅ **CI gratuit** : GitHub Actions runs sans clés API
4. ✅ **Reproductibilité** : mêmes inputs → mêmes outputs
5. ✅ **Démonstration live** : UI fonctionnelle locale avec data factices
6. ✅ **Onboarding dev rapide** : `pnpm install && pnpm run dev` suffit

## Limitations mocks

1. ⚠️ **Hypothèses non validées** : mock dit toujours OK, vrai Google peut bannir → POCs RÉELS obligatoires avant prod
2. ⚠️ **Performance réelle** non testée (POC #5 a déjà validé la DB en revanche)
3. ⚠️ **Bugs spécifiques providers** non détectés en mock (typage API change, etc.)
4. ⚠️ **Fixtures à maintenir** si format API externe évolue (annuaire-entreprises.data.gouv.fr refonte annuelle)

---

**Convention adoptée Sprint 1 → S12 par mandat utilisateur 2026-05-16.**
