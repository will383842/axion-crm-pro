# Agent 37 — Auditeur des secrets et des échanges

> Référence code : `main = e8924b8` (relu au démarrage ; `gh`/`git log` re-mesurés — 3 commits `#187/#188/#189` poussés pendant l'audit ne touchent pas mon périmètre code).
> Mesures production : SSH lecture seule `root@46.62.248.239`, 2026-08-19 ~11:1x–11:5xZ. **Aucune écriture, aucune valeur de secret copiée.**
> Preuves brutes : `04_PREUVES/agent-37/`.

---

## 1. Tableau de grille

| Objet | Condition mesurée | Prod | Préprod (staging) | Local (atelier) | Verdict |
|---|---|---|---|---|---|
| **MockServicesProvider** | provider a-t-il un garde d'environnement ? | **AUCUN** (`grep` → 0) | idem | idem | **F37-002** : rien n'empêche structurellement un mock en prod |
| Mocks liés **réellement** dans l'app qui tourne | résolution du conteneur | **6/14 = Mock** (LLM, Proxy, Captcha, Smtp, FranceTravail, +5 scrapers) | MOCK_MODE=true | MOCK_MODE=true | mocks actifs en prod par drapeaux explicites |
| **WORKER_INTERNAL_HMAC_SECRET** | longueur en prod | **0 (vide)** | — | 0 (vide) | **F37-001 S0** |
| `ScraperResultController` HMAC | fail-open sur secret vide ? | **OUI** (pas de garde `$secret===''`) | idem | idem | forgeable — funnel `=true` en prod |
| `HmacSignature::verify` (SiteSync/Gdpr) | fail-closed sur secret vide ? | OUI (`$secret===''→false`) ; `SITE_SYNC_HMAC_SECRET`=64c | — | — | **solide** (agent 13 confirmé) |
| `ZeptoMailWebhookController` | fail-closed sur jeton vide ? | OUI (503) ; `MAIL_WEBHOOK_TOKEN`=64c | — | — | solide |
| **Telescope /telescope** | atteignable de l'extérieur ? | 403 (gate `owner`) | 404 | — | UI fermée, mais **recording actif** → F37-006 |
| **Horizon /horizon** | atteignable ? auth ? | 500 (APP_DEBUG=false, 6,6 Ko) | **500 page debug 880 Ko publique** | — | **F37-003 S1** (staging) |
| `config('telescope.enabled')` résolu | prod | **true** (défaut vendor) | — | — | **F37-006** : cause de A-007 |
| **CSP** | en-tête présent ? | **ABSENT** (api & app) | absent | **présent & strict** | **F37-004 S2** : inversion prod↔local |
| HSTS | présent ? | oui (max-age 1 an, preload) | oui | oui | conforme |
| X-Frame-Options | présent ? | DENY | DENY | DENY | conforme |
| X-Content-Type-Options | présent ? | nosniff | nosniff | nosniff | conforme |
| Referrer-Policy | présent ? | strict-origin-when-cross-origin | idem | idem | conforme |
| Permissions-Policy / COOP / CORP | présents ? | **ABSENTS** | absents | **présents** | F37-004 |
| **CORS** | origine étrangère `evil.example.com` ? | refusée (pas d'ACAO) | refusée | — | pas de wildcard |
| CORS origine `app.localhost` | acceptée en **prod** ? | **OUI** `ACAO: https://app.localhost` + credentials | — | — | **F37-005 S3** |
| **Cookie session** | Secure/HttpOnly/SameSite/domaine/durée | Secure+HttpOnly+SameSite=Lax, `.axion-crm-pro.com`, 7200s | idem (domaine vide) | — | correctement borné |
| **TLS** | versions acceptées | **TLS1.0/1.1 refusés, 1.2+1.3 OK** (edge & origine) | idem | — | conforme |
| Certificat / origine directe | Cloudflare contournable ? | **origine 46.62.248.239:443 sert directement** (cert LE `api.axion-crm-pro.com`) | idem | — | **F37-007 S2** |
| **.env dans git** | suivi ou historique ? | seuls `.env.example` ; historique vide | — | — | propre |
| **gitleaks** | check requis bloquant ? couvre quoi ? | requis sur `main`, couvre git-tracked+historique | — | — | ne voit ni `.env` prod ni `laravel.log` |
| **laravel.log 272 Mo** | secrets/PII dedans ? | mots de passe **masqués** ; **PII** (14 e-mails, 129 cookies session, IP+UA) via Telescope | — | — | **F37-008 S2** |
| Perms `laravel.log` | hôte / conteneur | hôte **1,0 Go `-rwxrwxrwx`** ; conteneur 272 Mo `-rw-r--r--` www-data | — | — | F37-008 |
| `/var/www/html` 1777 (suite F40-013) | ce que ça permet | webroot **1777 www-data** ; `public/`755 root, `index.php`644 root ; `storage`+`bootstrap/cache` **777** | — | — | **F37-009 S2** |
| Redis auth | mot de passe exigé ? longueur | `requirepass` posé mais **`REDIS_PASSWORD`=4 car. minuscules** | — | — | **F37-010 S2** |

---

## 2. Les 21 clés absentes du `.env` de production — verdict clé par clé

Prod `.env` = **111 clés** ; `.env.example` = **116**. Diff `comm` (preuve `env-keys-diff.txt`) → **21 clés déclarées dans l'exemple, absentes du serveur**. Pour chacune : défaut du framework/code, et si ce défaut est le bon en production.

| # | Clé | Défaut appliqué (code) | Défaut correct en prod ? |
|---|---|---|---|
| 1 | `TELESCOPE_ENABLED` | **`true`** (vendor `config/telescope.php:19` `env(...,true)`) | ❌ **NON — le seul défaut franchement dangereux.** Cause racine de A-007/F40-003 : Telescope enregistre dans des tables absentes → 169 653 erreurs dans le log. Doit être `false` explicite. |
| 2 | `MOCK_RPPS` | `env('MOCK_RPPS', env('MOCK_MODE', true))` → prod MOCK_MODE=false → **false** | ✅ OK par ricochet (car MOCK_MODE=false). Mais **fragile** : si MOCK_MODE repassait à true, l'import RPPS se mockerait sans que personne l'ait écrit. |
| 3 | `CRM_INGEST_MAX_CLOCK_SKEW` | `300` (crm.php:85) | ✅ fenêtre anti-rejeu de 5 min, raisonnable. |
| 4 | `CRM_INGEST_BUSINESS_WORKSPACE` | `'axion-ia'` (crm.php:82) | ✅ correspond au workspace métier. |
| 5 | `CRM_OUTBOUND_BATCH` | `100` | ✅ borne de lot raisonnable. |
| 6 | `CRM_OUTBOUND_MAX_ATTEMPTS` | `8` | ✅ raisonnable. |
| 7 | `CRM_OUTBOUND_TIMEOUT` | `10` s | ✅ raisonnable. |
| 8 | `CRM_SCRAPE_VALIDATE_MX` | `true` (crm.php:105) | ✅ « jamais confiance au collecteur » — bon en prod. |
| 9 | `DB_APP_USERNAME` | `'axion_app'` (database.php:35) | ⚠️ **à surveiller** : le rôle applicatif RLS (`CRM_DB_APP_ROLE_ENABLED=true` en prod, B11-010) dépend de ce nom ; retombant sur un défaut au lieu d'être écrit, un renommage du rôle Postgres casserait silencieusement le cloisonnement. Le nom coïncide aujourd'hui, mais par chance, pas par contrat. |
| 10 | `SANTE_INGESTION_ENABLED` | `false` (services.php:115) | ✅ fonctionnalité santé désactivée — cohérent. |
| 11 | `EMAIL_FINDER_SPECULATIVE_ENABLED` | `false` (services.php:103) | ✅ conservateur (pas d'e-mails devinés). |
| 12 | `GOOGLE_PLACES_CACHE_TTL_DAYS` | `30` | ✅ |
| 13 | `GOOGLE_PLACES_MONTHLY_QUOTA_LIMIT` | `11500` | ⚠️ garde-fou de coût **subi** : si le vrai quota du compte diffère, l'alerte se déclenche au mauvais seuil. À écrire. |
| 14 | `GOOGLE_PLACES_PENDING_ALERT_THRESHOLD` | `5000` | ⚠️ idem, seuil d'alerte non aligné sur une valeur choisie. |
| 15 | `GOOGLE_PLACES_SMART_SKIP` | `true` (services.php:86) | ✅ économise le quota. |
| 16 | `WEBSHARE_ENABLED` | `false` (services.php:37) | ✅ mais **incohérent avec l'usage** : `MOCK_PROXIES=true` en prod → le proxy est mocké de toute façon (F37-002). |
| 17 | `WEBSHARE_ENDPOINT` | `'p.webshare.io:80'` | ✅ inerte tant que WEBSHARE_ENABLED=false. |
| 18 | `WEBSHARE_USERNAME` | `null` | ✅ inerte. |
| 19 | `WEBSHARE_PASSWORD` | `null` | ✅ inerte. |
| 20 | `HUNTER_API_KEY` | `null` (services.php:29) | ❌ **incohérence fonctionnelle** : `MOCK_SMTP=true` en prod → `HunterSmtpProber` n'est pas lié, c'est `MockSmtpProber` qui tourne. La clé absente est donc sans effet **aujourd'hui**, mais le jour où `MOCK_SMTP=false`, la sonde SMTP partira sans clé et échouera silencieusement. |
| 21 | `BRAVE_SEARCH_API_KEY` | `null` (services.php:21) | ⚠️ moteur de recherche sans clé → appels non authentifiés/échec si sollicité ; masqué tant que `MOCK_SCRAPERS=true`. |

**Synthèse** : **1 défaut réellement nuisible** (`TELESCOPE_ENABLED`, déjà = A-007). Les 20 autres retombent sur des défauts *acceptables aujourd'hui*, mais **6 le sont par coïncidence avec un drapeau MOCK ou un nom qui « tombe juste »** (#2, #9, #16, #20, #21, et #13/#14 pour les seuils). Le motif « l'atelier n'a pas la config de la prod » se double ici d'un motif jumeau : **la prod n'a pas sa propre config, elle emprunte des défauts de framework** — et une valeur implicite est une décision que personne ne peut relire.

---

## 3. « Un mock peut-il s'activer en production ? » — OUI, et 6 le sont déjà

**Preuve directe, dans le conteneur de production qui tourne** (`prod-bindings-raw.txt`, résolution du conteneur d'injection Laravel, `APP_ENV=production`) :

```
BIND LLMClient            => App\Services\LLM\Mocks\MockLLMClient
BIND ProxyProvider        => App\Services\Proxies\Mocks\MockProxyProvider
BIND CaptchaSolver        => App\Services\Captcha\Mocks\MockCaptchaSolver
BIND SmtpProber           => App\Services\Smtp\Mocks\MockSmtpProber
BIND FranceTravailClient  => App\Services\FranceTravail\Mocks\MockFranceTravailClient
BIND GoogleMapsScraper    => App\Services\Scraping\Mocks\MockGoogleMapsScraper
BIND PagesJaunesScraper   => ...Mocks\MockPagesJaunesScraper
BIND WebsiteScraper       => ...Mocks\MockWebsiteScraper
BIND SearchEngine         => ...Mocks\MockSearchEngine
BIND DirectionFinder      => ...Mocks\MockDirectionFinder
BIND InseeClient/Annuaire/Bodacc/Ban => implémentations HTTP réelles
BIND APP_ENV=production MOCK_MODE=false configCached=true
```

Le mécanisme (lu dans `MockServicesProvider.php`) : chaque service est lié par `env($flag, $master)` où `$master = env('MOCK_MODE', true)`. En prod `MOCK_MODE=false`, mais **six drapeaux explicites** sont à `true` (`MOCK_LLM, MOCK_PROXIES, MOCK_SCRAPERS, MOCK_SMTP, MOCK_CAPTCHA, MOCK_FRANCE_TRAVAIL`). **Le provider n'a aucun garde d'environnement** (`grep environment|isProduction|APP_ENV` → 0 résultat) : il est enregistré inconditionnellement dans `bootstrap/providers.php`. Rien dans le code n'empêche un mock en production ; **seule la discipline des variables d'env** le contient — et le défaut d'usine de tout drapeau absent est `MOCK_MODE`, dont la valeur *dans `.env.example`* est **`true`**. Un serveur provisionné à partir de l'exemple mocke donc **les 14 services**.

C'est le scénario que le prompt demandait de jouer ; je l'ai joué non pas sur une réplique locale mais **sur la production elle-même** — le témoin est la production. Que ces 6 mocks soient *voulus* aujourd'hui (produit non lancé) ne retire rien au défaut : **il n'existe aucune barrière** entre un `.env` mal rempli et un mock qui répond à la place du service réel en production.

---

## 4. Tableau des en-têtes de sécurité sur les 3 environnements

Mesuré : `headers-prod-staging.txt` (prod/staging via Cloudflare) et `headers-local.txt` (atelier, `https://app.localhost` / `https://api.localhost`).

| En-tête | Prod `api`/`app` | Préprod `staging`/`staging-api` | Local `api.localhost`/`app.localhost` |
|---|---|---|---|
| Strict-Transport-Security | ✅ max-age=31536000; includeSubDomains; preload | ✅ idem | ✅ idem |
| X-Frame-Options | ✅ DENY | ✅ DENY | ✅ DENY |
| X-Content-Type-Options | ✅ nosniff | ✅ nosniff | ✅ nosniff |
| Referrer-Policy | ✅ strict-origin-when-cross-origin | ✅ idem | ✅ idem |
| **Content-Security-Policy** | ❌ **ABSENT** | ❌ **ABSENT** | ✅ **présent, strict** (api: `default-src 'none'…` ; app: `default-src 'self'…`) |
| **Permissions-Policy** | ❌ absent | ❌ absent | ✅ `geolocation=(self), microphone=(), camera=()…` |
| **Cross-Origin-Opener-Policy** | ❌ absent | ❌ absent | ✅ same-origin |
| **Cross-Origin-Resource-Policy** | ❌ absent | ❌ absent | ✅ same-site |
| X-Robots-Tag | — (prod indexable) | ✅ noindex,nofollow,noarchive | — |

**Inversion** : l'environnement le mieux durci est **l'atelier local**, l'environnement le moins protégé est **la production**. Cause dans `infra/caddy/Caddyfile` : les blocs `header` de `api.localhost`/`app.localhost` (l. 20-31, 76-85) portent CSP+Permissions-Policy+COOP+CORP ; les blocs `app.axion-crm-pro.com`/`api.axion-crm-pro.com` (l. 111-117, 125-131) ne portent que HSTS+XFO+XCTO+Referrer. La spec `spec/17_rgpd_aiact_owasp.md:424,544` **exige** la CSP. Détail S0-adjacent : le même Caddy sert la préprod (`host.docker.internal`), donc préprod hérite du même manque.

---

## 5. Constats

### [F37-001] Le canal `POST /api/internal/scraper-result` accepte une signature HMAC forgée par quiconque — secret vide + contrôle fail-open + funnel actif
- Sévérité      : **S0**
- Domaine       : sécurité / canal
- Référence     : `main e8924b8` ; production 2026-08-19 11:4xZ
- Emplacement   : `backend/app/Http/Controllers/Internal/ScraperResultController.php:37-45`
- Constat       : `$secret = env('WORKER_INTERNAL_HMAC_SECRET', '')` vaut **la chaîne vide** dans l'application de production qui tourne, le contrôle `hash_equals(hash_hmac('sha256',$body,''), $sig)` **ne rejette pas un secret vide**, et `CRM_SCRAPE_FUNNEL_ENABLED=true` : une requête dont la signature vaut `hash_hmac('sha256', body, '')` — calculable par n'importe qui — passe et **est ingérée dans la base de production**.
- Preuve        :
  - App de prod (tinker lecture seule) : `WORKER_INTERNAL_HMAC_SECRET len=0 isEmpty=true`, `funnel.enabled=true`, `expected_MAC_for_empty_key=22f8eea9…` (le serveur *me donne* le MAC qu'il attend).
  - Forgeabilité (local, aucun appel prod) : `php -r` → `hash_equals(MAC(body,""), MAC(body,"")) = true`.
  - Reachabilité (non-mutant, requête **non signée** → 401 avant toute ingestion) : `curl -X POST /api/internal/scraper-result` sans en-tête → **HTTP 401**. Fichiers : `prod-bindings-raw.txt`, terminal §preuve.
  - ⛔ Je n'ai **pas** envoyé de requête signée-forgée : elle aurait écrit en base (interdit).
- Témoin négatif: le même patron durci existe et **refuse** l'attaque — `HmacSignature::verify()` (l. 26-29) commence par `if ($secret === '' … ) return false;`. Les deux contrôleurs qui l'emploient (`SiteSyncController`, `SiteGdprController`) utilisent un `SITE_SYNC_HMAC_SECRET` de **64 caractères**. `ZeptoMailWebhookController` répond **503** si son jeton est vide. Trois canaux fail-closed prouvent que la vérification *sait* rejeter ; `ScraperResultController` est le seul resté fail-open, avec le seul secret vide.
- Impact        : un tiers non authentifié peut injecter des `ScrapedRecord` arbitraires (companies, contacts, tags) dans la base de production — pollution du CRM, faux contacts, contournement du cloisonnement de collecte. La classe `HmacSignature` a précisément été écrite « pour reprendre le patron de `scraper-result` en y ajoutant l'horodatage » : la migration a été faite pour SiteSync et **jamais rétroportée sur l'original**.
- Reproduction  : `env('WORKER_INTERNAL_HMAC_SECRET')` = "" en prod ; poser `X-Worker-Signature: <hash_hmac('sha256', corps, '')>` ; `POST /api/internal/scraper-result` ; funnel actif → ingestion.
- Correctif     : (a) **immédiat** — poser `WORKER_INTERNAL_HMAC_SECRET` (≥32 c) dans le `.env` de prod **et** `.env.example`, recréer `api horizon scheduler` (`up -d --force-recreate --no-deps`, pas `restart` — piège 8) ; (b) **fond** — remplacer les 5 lignes inline de `ScraperResultController` par `HmacSignature::verify($secret, $body, $sig)` (fail-closed gratuit) ; (c) **garde** — contrôle de démarrage qui rougit si un canal HMAC a un secret vide alors que son funnel est actif. Coût : 30 min. ⛔ Pas une rotation (D-005) : le secret est **vide**, il n'existe pas encore — le poser n'est pas tourner.
- Statut        : ouvert

### [F37-002] `MockServicesProvider` n'a aucun garde d'environnement — 6 services sont mockés en production
- Sévérité      : **S1** (S0 si un `.env` mal rempli mocke un service critique côté données)
- Domaine       : sécurité / backend
- Référence     : `main e8924b8` ; production 2026-08-19
- Emplacement   : `backend/app/Providers/MockServicesProvider.php:56-92` ; `backend/bootstrap/providers.php`
- Constat       : le provider lie mock-ou-réel par `env($flag, env('MOCK_MODE', true))` sans jamais tester l'environnement ; enregistré inconditionnellement. En prod, 6/14 contrats résolvent vers une classe `Mock*` (preuve directe conteneur).
- Preuve        : `prod-bindings-raw.txt` (LLMClient→MockLLMClient, ProxyProvider→MockProxyProvider, Captcha, Smtp, FranceTravail, 5 scrapers → Mock) ; `grep environment|isProduction|APP_ENV MockServicesProvider.php` → **0**.
- Témoin négatif: 4 contrats résolvent bien vers l'implémentation **réelle** (Insee, Annuaire, Bodacc, Ban → `Http*`), donc la mesure distingue mock et réel ; ce n'est pas un artefact.
- Impact        : le défaut d'usine de tout drapeau absent est `MOCK_MODE`, `=true` dans `.env.example`. Un serveur provisionné depuis l'exemple mocke les 14 services, dont l'INSEE/BODACC (données d'entreprise) et le SMTP — sans aucune alerte. Aujourd'hui « voulu » car produit non lancé, mais aucune barrière ne sépare l'intention d'un accident.
- Reproduction  : lire les bindings du conteneur ; comparer `.env.example:MOCK_MODE=true`.
- Correctif     : envelopper `register()` par `if ($this->app->environment('production') && ! $master) { /* interdire les mocks silencieux : loguer/rougir tout MOCK_*=true */ }`, ou au minimum un contrôle de démarrage listant les mocks actifs en prod. Coût : 1 h.
- Statut        : ouvert

### [F37-003] La préproduction sert des pages de debug Laravel complètes en accès public (APP_DEBUG=true)
- Sévérité      : **S1**
- Domaine       : sécurité
- Référence     : production/préprod 2026-08-19 11:1xZ
- Emplacement   : conteneur `axion-crm-staging-api` (`APP_DEBUG=true`, `APP_ENV=staging`)
- Constat       : `GET https://staging-api.axion-crm-pro.com/horizon` et `/api/v1/auth/me` renvoient une **page d'erreur Laravel de 880 Ko** exposant trace complète, chemins `vendor/laravel/*`, en-têtes de requête (dont `cf-connecting-ip`, IP réelle du client), contexte de route et middleware.
- Preuve        : `staging-horizon-body.html` (880 065 o), `resp-staging-api-authme2.html` (867 784 o vs **6 665 o** en prod). Extrait masqué : `## Stack Trace … RouteNotFoundException … ## Request GET /horizon … ## Headers host/user-agent/cf-connecting-ip …`. `telescope-horizon.txt`.
- Témoin négatif: la **production** (`APP_DEBUG=false`) renvoie 6 665 o sans trace pour la même URL — la différence est bien `APP_DEBUG`, pas l'URL.
- Impact        : divulgation de structure interne, versions (`Laravel 12.66.0`, `PHP 8.3.33`), chemins et IP clientes, à tout visiteur non authentifié. Reconnaissance offerte à un attaquant. Staging est indexé `noindex` mais **publiquement joignable**.
- Reproduction  : `curl https://staging-api.axion-crm-pro.com/horizon`.
- Correctif     : `APP_DEBUG=false` sur les 3 conteneurs staging + recréation. Coût : 10 min.
- Statut        : ouvert

### [F37-004] La production n'émet ni CSP, ni Permissions-Policy, ni COOP/CORP — alors que l'atelier local les émet
- Sévérité      : **S2**
- Domaine       : sécurité
- Référence     : `main e8924b8` ; mesuré 2026-08-19
- Emplacement   : `infra/caddy/Caddyfile:111-117` (app prod), `:125-131` (api prod), `:182-206` (staging)
- Constat       : les blocs `header` de prod/préprod ne portent que HSTS+XFO+XCTO+Referrer ; CSP, Permissions-Policy, COOP, CORP présents seulement sur `*.localhost` (l. 20-31, 76-85). La spec §17 exige la CSP.
- Preuve        : `headers-prod-staging.txt` vs `headers-local.txt` (tableau §4).
- Témoin négatif: `app.localhost` renvoie une CSP de 300+ caractères → le contrôle sait détecter une CSP quand elle existe ; son absence sur `app.axion-crm-pro.com` est réelle.
- Impact        : aucune défense en profondeur contre XSS/clickjacking-via-frame-src/injection sur le domaine qui porte les vraies données ; non-conformité à la spec OWASP interne.
- Reproduction  : `curl -D - https://app.axion-crm-pro.com/` → aucun `Content-Security-Policy`.
- Correctif     : recopier les 4 lignes d'en-têtes des blocs `*.localhost` dans les blocs prod+staging du Caddyfile ; valider la CSP de l'app sur le SPA réel (Vite a besoin de `unsafe-inline`/`unsafe-eval` en dev, à durcir en prod). ⚠️ Caddy sert 80/443 pour prod ET préprod : un rechargement les couvre les deux. Coût : 1-2 h (dont validation CSP réelle). 
- Statut        : ouvert

### [F37-005] La production accepte l'origine CORS `https://app.localhost` avec `credentials`
- Sévérité      : **S3**
- Domaine       : sécurité
- Référence     : `main e8924b8` ; prod 2026-08-19
- Emplacement   : `backend/config/cors.php:6-9` (défaut `'https://app.localhost,https://app.axion-crm-pro.com'`) ; prod n'a **pas** de `CORS_ALLOWED_ORIGINS` (absent du `.env` **et** de `.env.example`) → défaut appliqué.
- Constat       : `OPTIONS /api/v1/auth/login` avec `Origin: https://app.localhost` renvoie `Access-Control-Allow-Origin: https://app.localhost` + `Access-Control-Allow-Credentials: true` **en production**. Config résolue prod : `["https://app.localhost","https://app.axion-crm-pro.com"]`.
- Preuve        : `cors-localhost.txt`, `cors.txt` ; tinker : `config('cors.allowed_origins')=["https:\/\/app.localhost",…]`.
- Témoin négatif: `Origin: https://evil.example.com` et `https://staging.axion-crm-pro.com` → **aucun** ACAO (204 nu). Le filtre n'est donc pas un wildcard ; `app.localhost` est spécifiquement listé.
- Impact        : faible en pratique (un attaquant doit contrôler `app.localhost` chez la victime, généralement 127.0.0.1), mais `supports_credentials=true` + une origine non-production dans la liste de prod est une scorie de config. Sanctum stateful est correctement borné à `app.axion-crm-pro.com` seul.
- Reproduction  : `curl -X OPTIONS -H 'Origin: https://app.localhost' … /api/v1/auth/login`.
- Correctif     : poser `CORS_ALLOWED_ORIGINS=https://app.axion-crm-pro.com` dans le `.env` de prod (et l'ajouter à `.env.example`). Coût : 5 min.
- Statut        : ouvert

### [F37-006] `TELESCOPE_ENABLED` absent → `config('telescope.enabled')` retombe sur `true` : Telescope enregistre malgré le garde du provider applicatif
- Sévérité      : **S2** (confirme et précise A-007 / F40-003)
- Domaine       : sécurité / performance
- Référence     : `main e8924b8` ; prod 2026-08-19
- Emplacement   : `backend/vendor/laravel/telescope/config/telescope.php:19` (`env('TELESCOPE_ENABLED', true)`) vs `backend/app/Providers/TelescopeServiceProvider.php:19` (`env(...,false)`)
- Constat       : le provider **applicatif** court-circuite si `TELESCOPE_ENABLED` est faux, mais la voie d'enregistrement **du paquet** (auto-découverte) lit `config('telescope.enabled')` qui résout à **`true`** en prod (clé absente → défaut vendor). Telescope enregistre donc à la terminaison de chaque requête, dans des tables `telescope_entries` inexistantes.
- Preuve        : tinker prod `telescope.enabled=true` ; scan `laravel.log` → **169 653** lignes `telescope_entries does not exist` (`log-scan-resume.txt`). L'UI `/telescope` renvoie 403 (gate `owner`), donc l'interface est fermée mais **le recording est actif**.
- Témoin négatif: `docker-compose.local.yml:75` pose `TELESCOPE_ENABLED:"false"` → l'atelier n'a pas ces erreurs ; la variable *quand elle est écrite* éteint bien le recording.
- Impact        : cause racine de la croissance du `laravel.log` (A-007) et double stockage des traces (F40-010) ; charge inutile à chaque requête sur une prod déjà sérialisée (A-010).
- Correctif     : `TELESCOPE_ENABLED=false` dans `.env` prod + `.env.example` ; recréer les conteneurs. Coût : 10 min.
- Statut        : ouvert

### [F37-007] L'origine (46.62.248.239:443) sert directement l'application, en contournement de Cloudflare
- Sévérité      : **S2**
- Domaine       : sécurité
- Référence     : prod 2026-08-19
- Emplacement   : VPS Hetzner 46.62.248.239, Caddy exposé 443
- Constat       : `curl --resolve api.axion-crm-pro.com:443:46.62.248.239 https://…/up` → **200**, avec un certificat Let's Encrypt valide `CN=api.axion-crm-pro.com` émis par le Caddy de l'origine (issuer YE1), distinct du certificat servi par l'edge Cloudflare (`CN=axion-crm-pro.com`, issuer YE2). L'IP d'origine est donc joignable et sert l'app sans passer par le WAF/rate-limiting Cloudflare.
- Preuve        : `tls3.txt` (origine directe 200 sur les 3 noms ; certificats comparés), `tls4.txt`.
- Témoin négatif: TLS1.0/1.1 refusés aussi bien à l'edge qu'à l'origine (`tls4.txt`) → la mesure sait distinguer un refus d'un succès ; les 200 directs sont réels.
- Impact        : toutes les protections de bord (challenge, filtrage, purge, masquage IP) sont contournables par quiconque connaît l'IP d'origine (triviale à trouver via historiques DNS). Le rate-limit applicatif reste, mais A-010 (mono-processus) rend l'origine directe attaquable en déni de service.
- Reproduction  : `curl --resolve api.axion-crm-pro.com:443:46.62.248.239 https://api.axion-crm-pro.com/up`.
- Correctif     : restreindre l'entrée 443 de l'origine aux plages Cloudflare (pare-feu Hetzner cloud, puisque `ufw` absent — F40-009), ou Authenticated Origin Pulls. Coût : 1 h. ⚠️ Docker insère ses règles avant tout pare-feu local (cf. faille du 19/08).
- Statut        : ouvert

### [F37-008] Le `laravel.log` de production contient de la PII (e-mails, cookies de session, IP+UA) via les entrées Telescope ; le fichier hôte est en `-rwxrwxrwx` et non tourné
- Sévérité      : **S2**
- Domaine       : sécurité / conformité
- Référence     : prod 2026-08-19
- Emplacement   : conteneur `/var/www/html/storage/logs/laravel.log` (272 Mo) ; hôte `/opt/axion-crm-pro/backend/storage/logs/laravel.log` (**1,0 Go, `-rwxrwxrwx`**)
- Constat       : scan single-pass (1 416 759 lignes). **Aucun** secret en clair (mots de passe **masqués** `********` — 21 occ. ; 0 Bearer/JWT/APP_KEY/Authorization). **Mais** les payloads Telescope capturés (169 653 lignes) portent : **14 adresses e-mail distinctes** (dont le compte propriétaire), **129 valeurs distinctes de cookie de session** `axion_crm_session=` en clair (258 occ.), **24 055 lignes avec IP client + user-agent**, 5 IP distinctes.
- Preuve        : `log-scan-resume.txt` ; témoin positif sur fichier leurre 9 lignes → 10/10 catégories détectées (le scan n'est pas aveugle). Valeurs jamais copiées.
- Témoin négatif: le fichier leurre contenait 1 hash bcrypt, 1 Bearer, 1 JWT, 1 APP_KEY, 1 cookie — tous détectés par le même awk ; leur **absence** dans le vrai log est donc une mesure, pas un angle mort. Les mots de passe apparaissent **déjà masqués** par le middleware Laravel.
- Impact        : les 129 cookies de session en clair sont des **jetons de session réutilisables** (fenêtre 7200 s) exposés à tout ce qui lit le fichier ; les e-mails + IP + UA sont de la **donnée personnelle** conservée sans durée ni rotation (enjeu RGPD). Le fichier hôte `-rwxrwxrwx` est lisible et modifiable par tout compte du serveur ; il est **exécutable** (bit x) sans raison.
- Reproduction  : `docker exec axion-crm-api awk -f scan.awk /var/www/html/storage/logs/laravel.log`.
- Correctif     : (a) `TELESCOPE_ENABLED=false` (F37-006) tarit la source ; (b) rotation `logging.php`/logrotate + `LOG_LEVEL=warning` ; (c) `chmod 640` sur les logs, retirer le bit exécutable ; (d) purge du log actuel après revue. Coût : 30 min.
- Statut        : ouvert

### [F37-009] Racine du webroot en 1777 et `storage`/`bootstrap/cache` en 777 : write-what-you-want dans le conteneur de production (suite de F40-013)
- Sévérité      : **S2**
- Domaine       : sécurité
- Référence     : prod 2026-08-19
- Emplacement   : conteneur `axion-crm-api` : `/var/www/html`=**1777** www-data ; `/var/www/html/storage`=**777** ; `/var/www/html/bootstrap/cache`=**777** ; `public/`=755 root, `public/index.php`=644 root, `.env`=(hôte 644 root)
- Constat       : le processus PHP tourne en `www-data` (`ps` : `php -S 0.0.0.0:80`). Le webroot 1777 (sticky) autorise `www-data` à créer des fichiers à la racine du code servi ; `bootstrap/cache` 777 contient `config.php`, `routes-v7.php`, `packages.php`, `services.php` **inscriptibles** par le process web.
- Preuve        : `stat` conteneur (terminal §preuve) ; mounts : `storage` = volume `axion-crm-pro_api-storage`.
- Témoin négatif: `public/index.php` (644 root) et `public/` (755 root) ne sont **pas** inscriptibles par www-data → la mesure distingue les répertoires laxistes des stricts ; 1777/777 sont donc réels et non un artefact d'umask global.
- Impact        : une exécution de code arbitraire dans PHP (p.ex. via F37-001) peut écrire un fichier PHP dans le webroot et l'atteindre par HTTP, ou empoisonner le cache de config/routes compilé — persistance et escalade. `ReadonlyRootfs=false` (F40-013) ne l'empêche pas.
- Correctif     : `storage`/`bootstrap/cache` en 775 (www-data:www-data), webroot en 755 root avec seuls `storage`+`cache` inscriptibles ; retirer le sticky 1777. Coût : 30 min + validation que l'écriture runtime se limite à `storage`.
- Statut        : ouvert

### [F37-010] Le mot de passe Redis de production fait 4 caractères minuscules
- Sévérité      : **S2**
- Domaine       : sécurité
- Référence     : prod 2026-08-19
- Emplacement   : `.env` prod `REDIS_PASSWORD` (longueur 4, forme minuscules) ; Redis `requirepass` posé
- Constat       : Redis exige bien un mot de passe (`config get requirepass` → défini), mais ce mot de passe fait **4 caractères minuscules** — force dérisoire. Redis stocke sessions et files (`SESSION_DRIVER=redis`).
- Preuve        : mesure de **longueur et forme uniquement** (valeur jamais lue) ; `redis-cli dbsize`→41 depuis l'intérieur du conteneur (auth locale implicite). Le port Redis n'est plus exposé à l'extérieur (faille du 19/08 fermée, §6 dossier).
- Témoin négatif: `DB_APP_PASSWORD` et `SITE_SYNC_HMAC_SECRET` mesurés à 64 caractères par le même awk → la mesure de longueur discrimine bien ; 4 est réel.
- Impact        : si le port Redis se retrouvait à nouveau exposé (F40-008 : l'overlay observabilité peut rouvrir des ports), un mot de passe de 4 caractères minuscules tombe en secondes. Défense en profondeur nulle.
- Correctif     : mot de passe Redis fort (≥24 c). ⛔ **C'est une rotation → REFUSÉE (D-005)** : je ne la propose pas comme action, je **nomme le défaut**. À poser le jour où une rotation sera décidée (voir aussi ci-dessous « rotation impossible »).
- Statut        : ouvert

### [F37-011] Le mécanisme qui rendrait toute rotation du secret Postgres impossible (relecture de F40-007)
- Sévérité      : **S2** (défaut structurel, distinct de la rotation elle-même)
- Domaine       : sécurité / infrastructure
- Référence     : `main e8924b8`
- Emplacement   : `docker-compose.yml:17` `POSTGRES_PASSWORD: axion_dev_only` sous `environment:` ; `deploy-direct-ssh.yml` recrée `api app horizon scheduler` avec `--no-deps`
- Constat       : le mot de passe Postgres de prod est celui, en clair, du dépôt public (`axion_dev_only`). Comme il est sous `environment:` (et non `env_file:`), **le `.env` du serveur ne peut pas le corriger** ; et comme le déploiement de prod passe `--no-deps` sans recréer `postgres`, **aucun changement du compose sur `postgres` ne s'applique**. Deux verrous indépendants rendent une future rotation *mécaniquement inapplicable* par les outils existants.
- Preuve        : `docker inspect` (F40-007, agent 40) ; piège 18 du dossier ; `deploy-direct-ssh.yml` ne contient pas `up -d postgres`.
- Témoin négatif: `deploy-staging.yml:143` contient bien `docker compose up -d --no-deps postgres redis` → la préprod *peut* recréer postgres ; la prod ne le peut pas. Le contraste prouve le manque côté prod.
- Impact        : ⛔ la rotation est REFUSÉE (D-005), je ne la propose pas. Mais **le jour où elle sera décidée, elle échouera** : changer la valeur ne suffira pas, il faudra d'abord (a) déplacer `POSTGRES_PASSWORD` de `environment:` vers `env_file:`, (b) donner au déploiement de prod la capacité de recréer `postgres` (comme la préprod), et (c) faire un `ALTER ROLE` manuel sur la base existante (le mot de passe n'est appliqué qu'à l'initialisation du volume). Sans ces trois gestes, une rotation est impossible.
- Correctif     : rendre la rotation *possible* (les 3 gestes ci-dessus), sans l'exécuter. Coût : 2 h. 
- Statut        : ouvert

---

## 6. Ce que je n'ai PAS pu vérifier, et pourquoi

1. **Requête HMAC signée-forgée réellement envoyée à `/api/internal/scraper-result`** : ce serait une **écriture en base de production** (interdit). J'ai prouvé la forgeabilité en local et la reachabilité par une requête non signée (401). Le maillon « ingestion effective » repose sur la lecture du code (`fromArray`→`ingest`) et `funnel.enabled=true`, non sur un POST réussi.
2. **gitleaks rejoué en entier sur l'historique** : le scan Docker a expiré (>500 s sur un dépôt volumineux, chemins Windows). Je me suis rabattu sur l'inspection : check requis sur `main`, couverture git-tracked+historique, `.gitleaksignore` documenté, aucun `.env` réel suivi ni dans l'historique. La **valeur** des secrets couverts par gitleaks n'a pas été rejouée ligne à ligne.
3. **Empreintes/hachages des secrets** : je n'ai mesuré que **longueurs et formes** (jamais les valeurs, ni un sha256) — conforme à la consigne.
4. **CSP réelle applicable au SPA de prod** : je constate l'absence, mais je n'ai pas déterminé la CSP exacte sans casser Vite en prod (nécessite un test sur build réel).
5. **TLS suites de chiffrement détaillées** : `curl`/`openssl` de ce poste refusaient `%{ssl_cipher}` ; j'ai mesuré les **versions** (1.0/1.1 refusés, 1.2/1.3 acceptés) mais pas l'énumération complète des suites.
6. **Les 4 autres backups `.env.bak*` sur le serveur** : signalés (présents dans `/opt/axion-crm-pro/`, `-rw-r--r-- root`), non fouillés pour d'anciens secrets — ils peuvent contenir des valeurs pré-correction.
7. **Le compte des mocks côté workers Node** (`MOCK_*` de `workers/`) : hors de l'app Laravel, non mesuré ici.
