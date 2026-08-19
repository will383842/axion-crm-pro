# AGENT 19 — Sécurité et légalité de la collecte

> Grille et constats. Sorties brutes : `_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/agent-19/`.

## 0. Référence réellement mesurée — elle a bougé

Le dossier commun donne `main = c0c453d`. **Relu moi-même au début de la session** (règle §1 : « n'utilise
aucun SHA écrit dans un document ») :

```
$ git rev-parse HEAD
e8924b81ad64c0b236acd99ac5cbac4cd68eada7
$ git log --oneline -3
e8924b8 fix(rgpd+acces): rectification du registre + acces CRM rendu (#189)
9d273cd fix(rgpd+acces): le registre affirmait une chose FAUSSE en sa faveur — rectifié le jour même
1145473 docs(rgpd): registre des violations, notification non retenue (#188)
```

**`main` est à `e8924b8`, soit 6 commits devant `c0c453d`.** Aucun de ces 6 commits ne touche mon
périmètre (ils sont tous `docs(rgpd)` / `docs(cnil)` + un correctif d'accès). Tous mes constats sont
donc valides pour `c0c453d` comme pour `e8924b8`, mais **je les référence à `e8924b8`, la mesure**.

Base jetable créée et utilisée : **`axion_crm_a19`** (116 tables, migrée par
`docker exec -e DB_DATABASE=axion_crm_a19 axion-crm-api php artisan migrate --force`).
`axion_crm` n'a été ni écrite, ni migrée, ni purgée. Aucune requête n'a été émise vers un site tiers
réel : les deux seuls serveurs HTTP joués sont des `php -S` sur `127.0.0.1` **dans** le conteneur.

---

## 1. Grille — les 9 points, objet par objet

| # | Objet du périmètre | Garde SSRF appliquée ? | robots.txt | Tempo / quota par domaine | User-Agent | Verdict |
|---|---|---|---|---|---|---|
| 1 | `backend/app/Services/Http/SsrfGuard.php` | — (c'est la garde) | — | — | — | Correcte sur IPv4 (31/31 cas bloqués, 3/3 témoins positifs passent) ; **aucune plage IPv6** → C19-002 |
| 2 | `workers/src/utils/ssrf-guard.ts` | — (c'est la garde) | — | — | — | Idem IPv4 ; heuristique IPv6 par préfixe, incomplète → C19-002 |
| 3 | `backend/app/Services/Http/ProxiedHttpClient.php` | **NON — 0 appel** | non lu | aucun | hérité de l'appelant | C19-001 |
| 4 | `Services/Legal/MentionsLegalesScraperService.php` | **NON — 0 appel**, sur `$company->website` (donnée) | non lu | **aucun** — 8 requêtes **concurrentes** / site | 4 UA navigateurs tournants | C19-001, C19-004, C19-005, C19-006 |
| 5 | `Services/Domain/DomainFinderService.php` (hors périmètre nominatif, atteint par la mesure) | **NON — 0 appel** | non lu | **aucun** — 400 req/salve × 20 shards CI | 1 UA Chrome fixe | C19-001, C19-005, C19-006 |
| 6 | `Services/Insee/HttpInseeClient.php` (atteint par C19-010) | oui, mais sur `self::BASE_URL` (constante) | n/a (API) | `sleep(20)` sur 429, `usleep` pagination | — | Seul quota honnête du produit ; filtre ND partiel → C19-010 |
| 7 | `workers/src/scrapers/http-source.ts` | oui — sur gabarit en dur | non lu | aucun | **honnête** : `Axion-CRM-Pro/1.0 (+https://axion-crm-pro.com)` | Seul collecteur qui s'annonce |
| 8 | `workers/src/scrapers/website.playwright.ts` | **oui — sur `req.target_url` (donnée)** | non lu | aucun — 8 chemins séquentiels / site | UA du job / défaut Chrome | Garde bien placée côté TS |
| 9 | `workers/src/scrapers/direction-finder.playwright.ts` | **oui — sur URL construite (donnée)** | non lu | aucun — 14 chemins séquentiels / site | idem | Garde bien placée côté TS |
| 10 | `workers/src/scrapers/{google-maps,google-search,pages-jaunes}.playwright.ts` | **NON — 0 appel** | non lu | aucun | UA Chrome + `STEALTH_INIT` | C19-006 |
| 11 | `workers/src/browser/launcher.ts` | — | — | — | UA Chrome 131 + masquage `navigator.webdriver` | C19-006 |
| 12 | `Services/Proxies/{IPRoyal,Webshare}Provider.php` | non (API du fournisseur) | — | — | — | `verify => false` → C19-011 ; inactifs (`MOCK_PROXIES=true`) |
| 13 | `Api/ProxyProvidersController` + `POST /{p}/test` | — | — | — | — | `test()` **ment** → C19-008 ; **aucun identifiant en base** → pas de fuite (mesuré) |
| 14 | `Services/Captcha/TwoCaptchaSolver.php` | — | — | — | — | Stub inerte, jamais bindé, jamais injecté → C19-012 (rassurant, mais non arbitré) |
| 15 | `Console/Commands/ProspectionPurgeNonDiffusible.php` | — | — | — | — | Égalité de chaîne exacte : **1 variante purgée sur 5** → C19-010 |
| 16 | `Console/Commands/ProspectionPurgeNonCommercial.php` | — | — | — | — | Fait ce qu'elle dit (`left(legal_form,1) <> '5'`) ; non joué faute d'enjeu de conformité — voir §4 |
| 17 | Base légale (art. 6.1.f) + information art. 14 | — | — | — | — | Base déclarée partout, **balance jamais écrite**, **information jamais délivrée** → C19-007 |

### 1.bis Matrice SSRF — 31 cas joués sur chaque garde

Sorties brutes : `04_PREUVES/agent-19/ssrf-matrice-php.txt` et `ssrf-matrice-ts.txt`.

| Cas | URL | PHP | TS | Écart |
|---|---|---|---|---|
| **Témoin positif** IP publique | `http://93.184.216.34/` | **PASSE** | **PASSE** | — |
| **Témoin positif** DNS public | `https://example.com/` | **PASSE** | **PASSE** | — |
| **Témoin positif** API métier | `https://api.insee.fr/` | **PASSE** | **PASSE** | — |
| Boucle locale | `http://127.0.0.1/` | BLOQUÉ `deny_host` | BLOQUÉ `deny_host` | — |
| Boucle locale par nom | `http://localhost/` | BLOQUÉ `deny_host` | BLOQUÉ `deny_host` | — |
| Boucle locale décimale | `http://2130706433/` | BLOQUÉ **`dns_no_records`** | BLOQUÉ **`deny_host:127.0.0.1`** | ⚠️ mécanisme différent |
| Boucle locale octale | `http://0177.0.0.1/` | BLOQUÉ **`dns_no_records`** | BLOQUÉ **`deny_host:127.0.0.1`** | ⚠️ mécanisme différent |
| Boucle locale courte | `http://127.1/` | BLOQUÉ **`dns_no_records`** | BLOQUÉ **`deny_host:127.0.0.1`** | ⚠️ mécanisme différent |
| Privée 10/8 | `http://10.0.0.5/` | BLOQUÉ `deny_cidr` | BLOQUÉ `deny_cidr` | — |
| Privée 192.168/16 | `http://192.168.1.1/` | BLOQUÉ `deny_cidr` | BLOQUÉ `deny_cidr` | — |
| Privée 172.16/12 | `http://172.16.0.1/` | BLOQUÉ `deny_cidr` | BLOQUÉ `deny_cidr` | — |
| Lien local | `http://169.254.1.1/` | BLOQUÉ `deny_cidr` | BLOQUÉ `deny_cidr` | — |
| **IMDS AWS/GCP/Hetzner** | `http://169.254.169.254/latest/meta-data/` | **BLOQUÉ** `deny_host` | **BLOQUÉ** `deny_host` | — |
| **IMDS GCP par nom** | `http://metadata.google.internal/` | **BLOQUÉ** `deny_host` | **BLOQUÉ** `deny_host` | — |
| **IMDS Alibaba** | `http://100.100.100.200/` | **BLOQUÉ** `deny_host` | **BLOQUÉ** `deny_host` | — |
| **IMDS Azure par nom** | `http://metadata.azure.com/` | **BLOQUÉ** `deny_host` | **BLOQUÉ** `deny_host` | — |
| IPv6 boucle locale | `http://[::1]/` | BLOQUÉ **`dns_no_records`** | BLOQUÉ **`dns_no_records`** | ⚠️ **accident** (crochets) |
| **IPv6 mappée boucle locale** | `http://[::ffff:127.0.0.1]/` | BLOQUÉ **`dns_no_records`** | BLOQUÉ **`dns_no_records`** | ⚠️ **accident** |
| **IPv6 mappée IMDS** | `http://[::ffff:169.254.169.254]/` | BLOQUÉ **`dns_no_records`** | BLOQUÉ **`dns_no_records`** | ⚠️ **accident** |
| IPv6 ULA | `http://[fd00::1]/` | BLOQUÉ **`dns_no_records`** | BLOQUÉ **`dns_no_records`** | ⚠️ **accident** |
| IPv6 lien local | `http://[fe80::1]/` | BLOQUÉ **`dns_no_records`** | BLOQUÉ **`dns_no_records`** | ⚠️ **accident** |
| **IPv6 PUBLIQUE légitime** | `http://[2606:4700:4700::1111]/` | **BLOQUÉ** `dns_no_records` | **BLOQUÉ** `dns_no_records` | 🔴 **faux positif** : aucun site IPv6 n'est joignable |
| Schéma `file:` | `file:///etc/passwd` | BLOQUÉ `invalid_url` | BLOQUÉ `bad_scheme:file:` | libellé différent |
| Schéma `gopher:` | `gopher://169.254.169.254/_GET%20/` | BLOQUÉ `invalid_url` | BLOQUÉ `bad_scheme:gopher:` | libellé différent |
| Schéma `dict:` | `dict://127.0.0.1:11211/stat` | BLOQUÉ `invalid_url` | BLOQUÉ `bad_scheme:dict:` | libellé différent |
| Schéma `ftp:` | `ftp://example.com/x` | BLOQUÉ `invalid_url` | BLOQUÉ `bad_scheme:ftp:` | libellé différent |
| Confusion `userinfo@` | `http://example.com@169.254.169.254/` | BLOQUÉ `deny_host` | BLOQUÉ `deny_host` | — |
| Hôte Docker | `http://host.docker.internal/` | BLOQUÉ `deny_cidr:192.168.65.254` | `dns_no_records` (résolveur Windows) | résolveur, pas la garde |
| Service interne Postgres | `http://axion-crm-postgres:5432/` | BLOQUÉ `deny_cidr:172.23.0.4` | `dns_no_records` (résolveur Windows) | résolveur, pas la garde |
| Service interne Redis | `http://axion-crm-redis:6379/` | BLOQUÉ `deny_cidr:172.23.0.5` | `dns_no_records` (résolveur Windows) | résolveur, pas la garde |
| **DNS → privé (rebinding)** | `http://127.0.0.1.nip.io/` | **BLOQUÉ** `deny_cidr:127.0.0.1` | **BLOQUÉ** `deny_cidr:127.0.0.1` | — |
| **Redirection → hôte interne** | 302 vers un autre hôte | 🔴 **NON VÉRIFIÉE — atteinte** | idem par construction | C19-003 |

**Lecture honnête de ce tableau.** Aucune case « PASSE » sur un cas d'attaque : sur IPv4 les deux
gardes tiennent, et les trois témoins positifs passent — la garde ne bloque donc pas le travail
légitime en IPv4. **Mais trois réserves majeures, chacune mesurée :**

1. Les 6 cas IPv6 sont bloqués par **`dns_no_records`**, jamais par la règle. `parse_url` / `URL`
   rendent l'hôte **avec ses crochets** (`[::1]`), la résolution échoue, la garde ferme par défaut.
   C'est un **accident heureux**, pas une protection (piège 19). Preuve : `ipv6-cidr-php.txt`,
   `ipv6-cidr-ts.txt`.
2. Le cas IPv6 **public** est bloqué lui aussi : **aucun site en IPv6 seul n'est atteignable** par le
   produit.
3. Les 3 formes de boucle locale non pointées (décimale, octale, courte) sont bloquées côté PHP par
   `dns_no_records` alors que **curl, lui, sait les résoudre** : la garde et le client qui suit ne
   comprennent pas la même URL.

### 1.ter L'écart PHP ↔ TypeScript, mesuré sur le même objet

Preuve : `ipv6-cidr-php.txt` (par réflexion sur `SsrfGuard::ipInDenyCidr`) et `ipv6-cidr-ts.txt`
(copie octet-pour-octet de `ssrf-guard.ts` + une ligne d'export ; `diff` joint la preuve d'identité).
On donne à chaque implémentation **l'hôte déjà dé-crocheté**, c'est-à-dire ce qu'elles verraient si
quelqu'un corrigeait un jour le parsing des crochets :

| Adresse (dé-crochetée) | PHP `ipInDenyCidr` | TS `ipInDenyCidr` |
|---|---|---|
| `169.254.169.254` | refuse | refuse |
| `10.0.0.1` | refuse | refuse |
| `::1` | 🔴 **ACCEPTE** | refuse |
| `::ffff:169.254.169.254` | 🔴 **ACCEPTE** | refuse |
| `::ffff:127.0.0.1` | 🔴 **ACCEPTE** | refuse |
| `fd00::1` | 🔴 **ACCEPTE** | refuse |
| `fe80::1` | 🔴 **ACCEPTE** | refuse |
| `fc00::1` | 🔴 **ACCEPTE** | refuse |
| `0:0:0:0:0:ffff:169.254.169.254` (forme développée) | 🔴 **ACCEPTE** | 🔴 **ACCEPTE** |
| `0:0:0:0:0:ffff:127.0.0.1` | 🔴 **ACCEPTE** | 🔴 **ACCEPTE** |
| `64:ff9b::a9fe:a9fe` (NAT64 vers l'IMDS) | 🔴 **ACCEPTE** | 🔴 **ACCEPTE** |
| `2002:a9fe:a9fe::1` (6to4 vers l'IMDS) | 🔴 **ACCEPTE** | 🔴 **ACCEPTE** |
| `2606:4700:4700::1111` (public, doit passer) | accepte ✅ | accepte ✅ |

**Deux implémentations de la même règle, deux comportements différents sur 6 entrées sur 13.**
Le commentaire d'en-tête de `ssrf-guard.ts:5` affirme pourtant : « **équivalent fonctionnel de
backend/app/Services/Http/SsrfGuard.php** ». C'est faux, et rien ne le signalait (piège 15).

**Écart n° 2, plus grave que le premier — où la garde est posée :**

| | PHP | TypeScript |
|---|---|---|
| Appelants de la garde | 5 | 3 |
| Appels sur une **constante en dur** | **5 / 5** | 1 / 3 |
| Appels sur une **URL issue de la donnée** | **0 / 5** | **2 / 3** (`website.playwright.ts:23` sur `req.target_url`, `direction-finder.playwright.ts:56` sur `https://${domain}${path}`) |

Côté TypeScript la garde est au bon endroit. **Côté PHP elle ne garde que des adresses qu'aucun
attaquant ne contrôle** — et les trois services PHP qui, eux, vont chercher une URL venue de la base
(`MentionsLegalesScraperService`, `DomainFinderService`, `ProxiedHttpClient`) ne l'appellent pas une
seule fois. C'est le piège 19 en clair.

### 1.quater robots.txt, tempo, en-têtes, captcha — réponses directes

| Question | Réponse mesurée |
|---|---|
| robots.txt **lu** ? | **Non. Zéro occurrence** de `robots` dans `backend/app`, `workers/src`, `composer.json`, `workers/package.json`. Témoin négatif : la même recherche trouve bien `frontend/public/robots.txt` et le `<meta name="robots">` de `frontend/index.html:10` — elle sait donc trouver. Preuve : `robots-et-user-agents.txt`. |
| robots.txt **respecté** ? | Sans objet : il n'est pas lu. Aucun `Crawl-delay`, aucun `Disallow` n'est jamais consulté. |
| Limitation de débit **par domaine cible** ? | **Aucune, nulle part.** `Redis::throttle` : 0 occurrence dans `backend/app`. `WithoutOverlapping` : 0. Aucun compteur par hôte. |
| Délai entre requêtes ? | `MentionsLegalesScraperService` : **0 ms** — 8 requêtes **simultanées** (`Http::pool`, ligne 245). Le seul `usleep(100–300 ms)` « pour ne pas marteler le serveur » est ligne 318, dans `fetch()`, **méthode jamais appelée** (0 appelant). |
| Qu'est-ce qui empêche de marteler un site ? | **Rien.** Le seul étalement réel (`LaunchCampaignJob`, `max_requests_per_minute` défaut 30) espace les **jobs de zone**, pas les requêtes HTTP. Les quotas honnêtes existants (INSEE `sleep(20)` sur 429, Google Places, Wikidata `usleep(300 ms)`) visent tous des **API amies**, jamais un site d'entreprise scrapé. |
| Volume de front | 8 requêtes concurrentes / site (`MentionsLegales`) ; **400 par salve** (`DomainFinderService:197` et `:264`) × **20 shards CI** (`prospection-find-websites-distributed.yml:37`) × jusqu'à 10 process Horizon. |
| User-Agent honnête ? | **3 collecteurs honnêtes** : `http-source.ts:79` `Axion-CRM-Pro/1.0 (+https://axion-crm-pro.com)`, `HibpChecker.php:38`, `ImportMediaEmissionsFromWikidata.php:46` `AxionCRM/1.0 (contact@axion-ia.com)`. **Tous les collecteurs de masse sont déguisés** : 4 UA navigateurs tournants (`MentionsLegales:74-79`), 1 UA Chrome fixe (`DomainFinderService:42`), UA Chrome 131 + `STEALTH_INIT` masquant `navigator.webdriver` (`launcher.ts:6-11`). |
| Déguisement **écrit et assumé** ? | **Non.** Aucun répertoire d'ADR n'existe (`docs/adr`, `_ADR` : absents). Aucune décision écrite sur la furtivité, les proxies résidentiels, le contournement de captcha ni le robots.txt. |
| Captcha actif ? | **Non.** `TwoCaptchaSolver::solve()` ne fait que `throw new \LogicException`. Il n'est bindé que si `MOCK_CAPTCHA=false`, or `MOCK_CAPTCHA=true` partout — y compris en production (`infra/scripts/configure-prod-env.sh:46`). Et **le contrat `CaptchaSolver` n'a aucun consommateur** : 0 injection, 0 `app(CaptchaSolver::class)`. `TWOCAPTCHA_API_KEY` est vide **et n'est lue par aucune ligne de code**. |
| Identifiants de proxy en base ? | **Ils n'y sont pas.** Mesuré : `proxy_providers_config` a 13 colonnes, **aucune** `username`/`password`/`api_key`. Témoin négatif : la même requête trouve bien `users.password_hash`, `users.totp_secret`, `invitations.token_hash`… Preuve : `proxy-providers-colonnes.txt`. |
| Fuite dans un journal ? | **Aucune.** `toProxyUrl()` a exactement 2 appelants, tous deux `Http::withOptions(['proxy' => …])`. Jamais loggé, jamais rendu. |

---

## 2. Constats

### [C19-001] Côté PHP, la garde SSRF n'est jamais appliquée à une URL issue de la donnée : les trois services qui en consomment une ne l'appellent pas
- Sévérité      : S1 grave
- Domaine       : sécurité
- Référence     : main e8924b8 (identique sur c0c453d — aucun des 6 commits d'écart ne touche ces fichiers)
- Emplacement   : `backend/app/Services/Legal/MentionsLegalesScraperService.php:245-256` ; `backend/app/Services/Domain/DomainFinderService.php:197-208` ; `backend/app/Services/Http/ProxiedHttpClient.php:22-34`
- Constat       : les 5 appels à `SsrfGuard::ensure()` du dépôt portent tous sur `self::BASE_URL`, une constante de classe, tandis que les trois services qui vont chercher une URL venue de la base (`$company->website`, domaine deviné, client proxifié) n'appellent la garde aucune fois.
- Preuve        : `grep -rn "SsrfGuard" backend/ | grep -v vendor` → 5 appels, tous de la forme `SsrfGuard::ensure(self::BASE_URL)` (`HttpInseeClient.php:32`, `HttpBanGeocoder.php:16`, `HttpBodaccClient.php:16`, `HttpAnnuaireEntreprisesClient.php:20`, `HttpFranceTravailClient.php:21`). Aucune occurrence de `SsrfGuard` dans `MentionsLegalesScraperService.php`, `DomainFinderService.php`, `ProxiedHttpClient.php` (fichiers lus intégralement). Voir `04_PREUVES/agent-19/robots-et-user-agents.txt` et la grille §1.ter.
- Témoin négatif: le contrôle sait trouver un appel bien placé — côté TypeScript la **même** recherche remonte `website.playwright.ts:23` (`await ensureSsrf(req.target_url ?? '')`) et `direction-finder.playwright.ts:56` (`await ensureSsrf(url)` sur `https://${domain}${path}`), c'est-à-dire deux gardes posées sur une URL réellement contrôlée par la donnée. La recherche distingue donc bien les deux situations.
- Impact        : `companies.website` est renseignée par le scraping et éditable depuis la console. Une valeur choisie (`http://169.254.169.254/…`, `http://axion-crm-postgres:5432/`, `http://127.0.0.1:9200/`) fait émettre par le backend, depuis l'intérieur du réseau Docker et du VPS, jusqu'à 8 requêtes concurrentes vers la cible, dont le corps est ensuite parsé et **persisté** dans `signals.contact_channels`. La garde existe, elle est correcte, et elle ne protège rien de ce qui est exposé.
- Reproduction  : 1. `git rev-parse HEAD` → `e8924b8`. 2. `grep -rn "SsrfGuard" backend/ | grep -v vendor` : constater que les 5 appels portent sur une constante. 3. Lire `MentionsLegalesScraperService.php:239-259` : `$base = rtrim($website, '/')` puis `$pool->get($base . $path)` — aucune vérification entre les deux.
- Correctif     : appeler `SsrfGuard::ensure($website)` en tête de `fetchAnyMentionsLegalesPage()`, de `guessDomainsBatch()`/`revalidateBatch()`, et dans `ProxiedHttpClient::request()`. ~0,5 j avec les tests. Ne referme pas C19-003 (redirections), qui demande le correctif séparé.
- Statut        : ouvert

### [C19-002] Les deux gardes ne bloquent aucune adresse IPv6 par la règle : elles ferment par accident de parsing, et divergent l'une de l'autre
- Sévérité      : S2 défaut
- Domaine       : sécurité
- Référence     : main e8924b8
- Emplacement   : `backend/app/Services/Http/SsrfGuard.php:25-37` et `:100-127` ; `workers/src/utils/ssrf-guard.ts:25-58`
- Constat       : `DENY_CIDR` (PHP) ne contient aucune plage IPv6 et `ipInDenyCidr()` abandonne toute plage dont la longueur `inet_pton` diffère, si bien que les 7 adresses IPv6 privées testées sont **acceptées** ; côté TypeScript une heuristique de préfixe de chaîne en refuse 5 sur 7 mais laisse passer les formes développées, NAT64 et 6to4 ; aujourd'hui aucune n'est atteinte parce que `parse_url`/`URL` rendent l'hôte **avec ses crochets**, ce qui fait échouer la résolution DNS et fermer la garde par défaut.
- Preuve        : `04_PREUVES/agent-19/ipv6-cidr-php.txt` (réflexion sur la méthode privée `SsrfGuard::ipInDenyCidr` : `::1`, `::ffff:169.254.169.254`, `::ffff:127.0.0.1`, `fd00::1`, `fe80::1`, `fc00::1` → **tous « FALSE (ACCEPTE) »**) et `ipv6-cidr-ts.txt` (mêmes entrées sur une copie de `ssrf-guard.ts` identique au fichier d'origine à une ligne d'export près — `diff` : `119a120,121`). Matrices complètes : `ssrf-matrice-php.txt`, `ssrf-matrice-ts.txt` — les 6 cas IPv6 y sortent en `dns_no_records`, jamais en `deny_cidr`.
- Témoin négatif: la même méthode, sur les mêmes appels, refuse bien `169.254.169.254` et `10.0.0.1` (« true (refuse) ») dans les deux implémentations. Le harnais sait donc faire rougir la garde quand la règle s'applique : le « FALSE » sur IPv6 est un vrai trou, pas un défaut de montage.
- Impact        : double. (a) Un site légitime joignable en IPv6 seul est **définitivement inatteignable** par le produit — mesuré sur `[2606:4700:4700::1111]`, bloqué lui aussi. (b) Le jour où quelqu'un corrigera le parsing des crochets — un refactor évident et bien intentionné, « la garde ne sait pas lire l'IPv6 » — la garde PHP s'ouvrira **en grand** sur la boucle locale, les ULA, le lien local et l'IMDS mappée, sans qu'aucun test existant ne rougisse (`SsrfGuardTest.php` et `SsrfGuardExtendedTest.php` ne contiennent pas un seul cas IPv6). Et le commentaire `ssrf-guard.ts:5` continuera d'affirmer que les deux implémentations sont « équivalent fonctionnel ».
- Reproduction  : `docker exec axion-crm-api php -r 'require "/var/www/html/vendor/autoload.php"; $m=new ReflectionMethod("App\Services\Http\SsrfGuard","ipInDenyCidr"); $m->setAccessible(true); var_dump($m->invoke(null,"::ffff:169.254.169.254"));'` → `bool(false)`.
- Correctif     : (1) normaliser l'hôte avant contrôle — retirer les crochets, replier les formes mappées `::ffff:x.x.x.x` sur leur IPv4 ; (2) ajouter à `DENY_CIDR` les plages `::1/128`, `fc00::/7`, `fe80::/10`, `::ffff:0:0/96`, `64:ff9b::/96`, `2002::/16` et implémenter la comparaison sur 16 octets ; (3) porter les mêmes plages en TypeScript et **remplacer l'heuristique de préfixe par la comparaison de masque** ; (4) ajouter les 13 cas du tableau §1.ter aux deux suites de tests. ~1 j. Ordre impératif : (2)+(3)+(4) **avant** (1), sinon la correction du parsing ouvre la garde.
- Statut        : ouvert

### [C19-003] La garde ne regarde que l'URL de départ : une redirection vers un autre hôte n'est pas re-vérifiée, et la cible interne est atteinte
- Sévérité      : S1 grave
- Domaine       : sécurité
- Référence     : main e8924b8
- Emplacement   : `backend/app/Services/Http/SsrfGuard.php:47-90` (contrôle unique, à l'entrée) ; aucun contrôle de redirection dans tout `backend/app`
- Constat       : `SsrfGuard::check()` s'applique une seule fois, à l'URL fournie, et le client HTTP suit ensuite les redirections vers n'importe quel hôte sans nouvelle vérification.
- Preuve        : `04_PREUVES/agent-19/redirection-non-verifiee.txt`. Deux serveurs `php -S` **locaux au conteneur** (`127.0.0.1:9911` → 302 → `127.0.0.1:9912`) ; aucune requête vers un tiers réel. Sortie : `SsrfGuard::check(URL de départ) = {"ok":false,...}` puis `Corps reçu après suivi de la 302 = CIBLE-INTERNE-ATTEINTE`, `Statut final = 200`. Par ailleurs `grep -rn "allow_redirects\|withoutRedirecting\|on_redirect\|max_redirects\|RedirectMiddleware" backend/app backend/config` → **aucun résultat**.
- Témoin négatif: le même `grep`, réduit à `withOptions([`, remonte bien 3 lignes (`ProxiedHttpClient.php:29`, `IPRoyalProvider.php:56`, `WebshareProvider.php:70`). La recherche sait donc trouver une option Guzzle quand il y en a une : l'absence de contrôle de redirection est réelle, pas un angle mort de la recherche.
- Impact        : un site tiers dont l'URL passe la garde (donc n'importe quel site public) peut renvoyer un `302 Location: http://169.254.169.254/latest/meta-data/` et faire lire le service de métadonnées de l'hébergeur par le backend, jusqu'à 5 sauts (défaut Guzzle). Le corps est ensuite parsé et persisté. Cette voie ne dépend d'aucun accès à la console : elle est déclenchée par le site scrapé lui-même. Elle survit au correctif de C19-001.
- Reproduction  : les 3 commandes du fichier de preuve, rejouables telles quelles dans `axion-crm-api`.
- Correctif     : poser `allow_redirects => ['max' => 3, 'on_redirect' => fn($req,$resp,$uri) => SsrfGuard::ensure((string)$uri)]` dans un client HTTP central, et faire passer les collecteurs par lui. Côté Node, même chose : `maxRedirects: 0` sur axios + boucle explicite re-gardée, et `page.on('request')` pour Playwright. ~1 j.
- Statut        : ouvert

### [C19-004] Aucun collecteur ne lit le robots.txt des sites qu'il moissonne
- Sévérité      : S2 défaut
- Domaine       : conformité
- Référence     : main e8924b8
- Emplacement   : `backend/app/Services/**`, `workers/src/**` (absence)
- Constat       : la chaîne `robots` n'apparaît nulle part dans le code de collecte ni dans les manifestes de dépendances, et aucune bibliothèque d'analyse de robots.txt n'est installée.
- Preuve        : `04_PREUVES/agent-19/robots-et-user-agents.txt`, section 1 : `grep -rni "robots" backend/app workers/src backend/composer.json workers/package.json` → **aucun résultat**.
- Témoin négatif: la même recherche élargie au dépôt trouve immédiatement `frontend/public/robots.txt`, `frontend/dist/robots.txt` et `frontend/index.html:10` (`<meta name="robots" content="noindex,nofollow" />`). Le contrôle sait donc voir le mot ; son silence sur le code de collecte est un vrai zéro.
- Impact        : la collecte se réclame de l'intérêt légitime (art. 6.1.f). Le respect du robots.txt est l'un des rares signaux objectifs qu'un exploitant peut produire, devant la CNIL ou devant un site qui se plaint, pour montrer que la mise en balance a été faite en pratique et pas seulement affirmée. Ici il n'y a rien à produire. À cela s'ajoute le risque contractuel des CGU, déjà relevé sans décision dans `spec/AUDIT_v1.md:405` (« certains sites ETI ont des CGU interdisant scraping »).
- Reproduction  : `grep -rni "robots" backend/app workers/src` puis `grep -rni "robots" frontend/` pour le témoin.
- Correctif     : lire et mettre en cache le `robots.txt` de chaque domaine avant la première requête, honorer `Disallow` et `Crawl-delay` (le second alimente naturellement C19-005), tracer la décision par domaine dans `scraping_sources`. ~1,5 j. Décision de dirigeant requise si l'on choisit de **ne pas** le respecter : alors il faut l'écrire (voir C19-006).
- Statut        : ouvert

### [C19-005] Aucune limitation de débit par domaine cible ; le seul délai de politesse du code est dans une méthode morte, et la documentation affirme le contraire
- Sévérité      : S2 défaut
- Domaine       : conformité
- Référence     : main e8924b8
- Emplacement   : `backend/app/Services/Legal/MentionsLegalesScraperService.php:239-259` (chemin vivant) et `:295-321` (méthode morte) ; `backend/app/Services/Domain/DomainFinderService.php:197` et `:264`
- Constat       : le chemin de collecte vivant émet 8 requêtes concurrentes vers un même hôte sans aucun délai, tandis que le `usleep(random_int(100_000, 300_000))` commenté « pour ne pas marteler le serveur » se trouve dans `fetch()`, méthode qui n'a aucun appelant.
- Preuve        : `grep -n "this->fetch(\|->fetch(" backend/app/Services/Legal/MentionsLegalesScraperService.php` → **aucun résultat** (la méthode `fetch()` déclarée ligne 295 n'est appelée nulle part ; les trois points d'entrée `scrape()`, `fetchPagesText()`, `harvestFromWebsite()` passent tous par `fetchAnyMentionsLegalesPage()`). `grep -rn "Redis::throttle\|WithoutOverlapping" backend/app` → **aucun résultat**.
- Témoin négatif: la même recherche de tempo trouve bien les quotas réellement en place ailleurs — `HttpInseeClient.php:124` `sleep(20)` sur 429, `:211` `usleep($delayMs * 1000)`, `ImportMediaEmissionsFromWikidata.php:132` `usleep(300_000)`, `EnrichCompanyJob.php:34-38` `backoff() → [60, 300, 1800]`. Le contrôle sait reconnaître un délai quand il existe ; il n'en trouve aucun sur le chemin des sites scrapés.
- Impact        : rien n'empêche de marteler un site. La docblock ligne 224-228 assume la salve concurrente, mais celle de la ligne 187 promet toujours « rotation UA, **délais polis**, early-exit » — un délai qui n'existe plus. Un lecteur — ou un auditeur — qui se fie au commentaire conclura que la politesse est traitée. Ordre de grandeur du front : 8 requêtes simultanées par site, 400 par salve dans `DomainFinderService`, multipliées par 20 shards dans `prospection-find-websites-distributed.yml:37`. Le seul étalement du produit (`LaunchCampaignJob:66-67`, `max_requests_per_minute` défaut 30) espace les **jobs de zone**, pas les requêtes HTTP — son commentaire ligne 16 (« anti-blacklist ») survend donc son effet.
- Reproduction  : 1. `grep -n "private function fetch" backend/app/Services/Legal/MentionsLegalesScraperService.php` → ligne 295. 2. `grep -n "fetch(" ` sur le même fichier → aucun appel. 3. Lire `:239-259` : `Http::pool` sur `array_slice(self::PATHS, 0, 8)`, sans `usleep`.
- Correctif     : supprimer la méthode morte `fetch()` et corriger la docblock ligne 186-188 (30 min, referme le mensonge) ; poser un limiteur par hôte de destination — `Cache::lock`/`Redis::throttle` sur `scrape:host:{domaine}` avec un intervalle minimal, alimenté par le `Crawl-delay` de C19-004 — et abaisser la salve de 8 concurrentes à une séquence espacée (~1 j).
- Statut        : ouvert

### [C19-006] Les collecteurs de masse se déguisent en navigateur et masquent leur automatisation, sans qu'aucune décision écrite ne l'assume
- Sévérité      : S2 défaut
- Domaine       : conformité
- Référence     : main e8924b8
- Emplacement   : `backend/app/Services/Legal/MentionsLegalesScraperService.php:71-79` ; `backend/app/Services/Domain/DomainFinderService.php:42` ; `workers/src/browser/launcher.ts:6-11` et `:33` et `:46-48`
- Constat       : trois collecteurs envoient des User-Agent de navigateurs réels (4 en rotation aléatoire pour l'un, 1 fixe pour l'autre, Chrome 131 pour le troisième) et le lanceur Playwright injecte un script qui redéfinit `navigator.webdriver` sur `undefined`, ajoute de faux `plugins`, de fausses `languages` et un faux `window.chrome`, en plus du drapeau `--disable-blink-features=AutomationControlled` ; aucun document de décision ne couvre ce choix.
- Preuve        : `04_PREUVES/agent-19/robots-et-user-agents.txt`, section 2 (19 lignes, valeurs littérales incluses). `MentionsLegalesScraperService.php:71` commente explicitement l'intention : « *Pool d'User-Agents rotation aléatoire pour réduire fingerprint* ». `launcher.ts:6-11` : `Object.defineProperty(navigator, 'webdriver', { get: () => undefined });`. Côté décisions : aucun répertoire `docs/adr` ni `_ADR` n'existe dans le dépôt (`find . -type d -iname "*adr*" -o -iname "*decision*"` hors `node_modules` → aucun résultat) ; les deux registres de décisions existants (`_AUDIT/2026-08-18_AUDIT-360/05_DECISIONS.md`, `_REPORTS/2026-08-18_ARBITRAGES-PREALABLES-SECTION-4.md`) ne traitent ni de la furtivité, ni des proxies, ni du captcha, ni du robots.txt.
- Témoin négatif: le contrôle sait distinguer un en-tête honnête d'un en-tête déguisé — la même recherche remonte trois collecteurs qui s'annoncent : `workers/src/scrapers/http-source.ts:79` `'Axion-CRM-Pro/1.0 (+https://axion-crm-pro.com)'`, `HibpChecker.php:38` `'Axion-CRM-Pro/1.0 (security@axion-crm-pro.com)'`, `ImportMediaEmissionsFromWikidata.php:46` `'AxionCRM/1.0 (contact@axion-ia.com)'`. Le déguisement des trois autres est donc un choix, pas une absence de convention dans le dépôt.
- Impact        : un déguisement subi est indéfendable ; un déguisement assumé par écrit est discutable mais tenable. Aujourd'hui c'est le premier cas. En cas de plainte d'un site ou de contrôle, l'exploitant n'a aucun document montrant que le choix a été pesé — alors qu'il en a un, honnête, pour trois autres collecteurs. La contradiction interne (`http-source.ts` s'annonce, `MentionsLegales` se cache) est elle-même le signe qu'aucune règle n'a été arrêtée.
- Reproduction  : `grep -rn "User-Agent'\s*=>\|'User-Agent':\|userAgent:\|USER_AGENT = " backend/app workers/src` puis `sed -n '6,11p' workers/src/browser/launcher.ts`.
- Correctif     : décision de dirigeant, à écrire (0,5 j) — soit (a) aligner les collecteurs sur l'UA honnête déjà employé par `http-source.ts` et accepter le taux d'échec, soit (b) assumer le déguisement dans une décision datée qui en donne le motif, le périmètre et la limite. Aucune des deux ne se décide dans un correctif technique.
- Statut        : ouvert

### [C19-007] La base légale invoquée pour 1 319 567 personnes est l'intérêt légitime, dont la mise en balance n'est écrite nulle part et dont l'information de l'article 14 n'est jamais délivrée
- Sévérité      : S0 bloquant (non-conformité RGPD)
- Domaine       : conformité
- Référence     : main e8924b8
- Emplacement   : base légale déclarée : `backend/app/Crm/Taxonomy.php:109`, `backend/app/Crm/Scraping/ScrapedRecordIngestService.php:224` et `:462` (`'legal_basis' => 'legitimate_interest_b2b'`), `spec/17_rgpd_aiact_owasp.md:10-12` ; absence : aucun fichier de mise en balance, aucun déclencheur d'information
- Constat       : la collecte repose sur l'intérêt légitime (art. 6.1.f), affirmé dans le code, la spec, le README et le CHANGELOG, sans qu'aucun test de mise en balance ne soit écrit, et sans qu'aucun mécanisme ne délivre aux personnes l'information exigée par l'article 14 pour une collecte indirecte.
- Preuve        : l'AIPD **en vigueur** — `_REPORTS/AIPD_2026-08-18.md` — le dit elle-même, deux fois. Ligne 724 : « *Le fondement est défendable ; il n'est **écrit nulle part**.* » Ligne 344 : « *Information (art. 13-14) — ⚠️ NON VÉRIFIÉ : aucune mention d'information n'est produite par ce dépôt. Pour la collecte indirecte (art. 14), l'information doit être délivrée dans le mois ou au premier contact. **Rien dans le code ne la déclenche.*** » Volume : `_REPORTS/REGISTRE-DES-VIOLATIONS-DE-DONNEES.md:80` — « ***1 319 567 personnes physiques*** ». Registre art. 30 : la table `data_processing_log` que `spec/17_rgpd_aiact_owasp.md:14-27` prétend seeder **n'est créée par aucune migration** (absente de `2026_05_16_000006_create_coverage_rgpd_aiact_schema.php`).
- Témoin négatif: le contrôle sait reconnaître une obligation RGPD **effectivement outillée** dans ce dépôt — la même recherche trouve `rgpd_requests` (table créée, contrôleur, purges `RgpdPurgeVivier`, `RgpdPurgeBusinessProspects`, `RetentionPurge`, `AnonymizeOldIps` réellement branchés). Les droits des art. 15-22 sont donc servis par du code ; l'art. 14 et la balance de l'art. 6.1.f sont les seuls à n'avoir aucun support. Le silence n'est pas un défaut de méthode.
- Impact        : 1 319 567 personnes physiques sont dans une base de prospection sans avoir jamais été informées de l'origine de leurs données, et sans que l'exploitant puisse produire le raisonnement qui justifie de se passer de leur consentement. C'est l'obligation la plus directement contrôlable par la CNIL, et la plus simple à constater : il n'y a rien à montrer. Le volume à lui seul qualifie le « traitement à grande échelle ».
- Reproduction  : lire `_REPORTS/AIPD_2026-08-18.md` lignes 297, 344, 663-664, 723-726 ; puis `grep -rn "data_processing_log" backend/database/migrations/` → aucun résultat.
- Correctif     : (1) écrire la mise en balance de l'art. 6.1.f — l'AIPD la chiffre à 0,5 j et l'a déjà inscrite en P2 ligne 663 ; (2) écrire et **déclencher** la mention d'information art. 14 (1 j, P2 ligne 664) ; (3) créer réellement le registre art. 30 en s'appuyant sur les 17 sources de `ScrapingSourcesSeeder` (0,5 j, P3 ligne 668). Ces trois actions sont **déjà arbitrées et priorisées dans l'AIPD du 18/08** : ce constat ne demande pas une décision nouvelle, il mesure que rien n'a encore été fait.
- Statut        : ouvert
- Note          : le prompt de mission désignait `_REPORTS/DPIA_2026-05-17.md`. Ce document porte depuis le 2026-08-18 un bandeau « **🔴 DOCUMENT OBSOLÈTE — NE PAS UTILISER** » (ligne 1) et renvoie à `AIPD_2026-08-18.md`. Il ne chiffre d'ailleurs aucun volume. J'ai mesuré sur l'AIPD en vigueur. À signaler aussi : l'AIPD annonce « 665 771 fiches contact » (ligne 118) là où la production en compte **1 319 567** — elle sous-estime le nombre de personnes d'un facteur ≈ 2. Ce point relève de l'agent chargé des documents de conformité ; je le signale sans l'ouvrir en constat.
- Voisinage     : ne pas confondre avec **A05-002** (colonnes `first_info_at` mortes, S2), déjà ouvert par l'agent 5. A05-002 constate l'absence d'**horodatage** de l'information ; C19-007 constate l'absence de l'**information elle-même** et de la **base légale écrite**. Je ne re-rapporte pas A05-002.

### [C19-008] `POST /proxy-providers/{p}/test`, documenté « health check live », renvoie `healthy: true` en dur sans contacter le fournisseur
- Sévérité      : S1 grave
- Domaine       : backend
- Référence     : main e8924b8
- Emplacement   : `backend/app/Http/Controllers/Api/ProxyProvidersController.php:47` (et sa docblock `:42-46`)
- Constat       : la méthode s'écrit `public function test(ProxyProvider $p): JsonResponse { return $this->ok(['healthy' => true]); }`, tandis que sa documentation OpenAPI annonce « Health check live d'un provider », et que les deux vraies implémentations `healthCheck()` existantes ne sont jamais appelées.
- Preuve        : fichier lu intégralement (48 lignes) — la ligne 47 est reproduite ci-dessus telle quelle. `grep -rn "healthCheck" backend/app` → `WebshareProvider.php:67` et `IPRoyalProvider.php:53` définissent bien un contrôle réel (requête via le proxy vers `api.ipify.org`), **et aucun contrôleur ne les appelle**. Route : `backend/routes/api.php:180`.
- Témoin négatif: le contrôle sait distinguer une méthode honnête d'une méthode qui ment — dans le **même fichier**, `update()` ligne 39 renvoie `$this->notImplemented('4')` et sa docblock annonce franchement `response=501`. Le contrôleur sait donc dire « pas implémenté » quand il le veut ; `test()` a été écrit pour dire « sain » à la place.
- Impact        : même famille que A-002 (`GET /saved-views` qui répond 200 liste vide). Un exploitant qui teste un fournisseur de proxy depuis la console obtient un vert inconditionnel : identifiants absents, quota épuisé, fournisseur injoignable, tout répond « healthy ». Le seul bouton de diagnostic du sous-système proxy est celui auquel on ne peut pas se fier — et il ne rougira jamais, y compris le jour où le proxy est la cause de la panne.
- Reproduction  : lire `backend/app/Http/Controllers/Api/ProxyProvidersController.php:41-47` ; comparer à `WebshareProvider::healthCheck()` (`:67-77`). La route est `POST /api/v1/proxy-providers/{p}/test`, sous `auth:sanctum` + `workspace` + `first-login` (`api.php:83`, `:180`).
- Correctif     : soit brancher `test()` sur le `healthCheck()` du fournisseur correspondant (~0,5 j), soit — tant que les proxies sont désactivés (`MOCK_PROXIES=true`, `WEBSHARE_ENABLED=false`) — renvoyer `notImplemented('4')` comme le fait `update()` juste au-dessus (~5 min). La seconde option est immédiate et supprime le mensonge.
- Statut        : ouvert

### [C19-009] La même variable `SSRF_GUARD_DENY_PRIVATE` a deux valeurs par défaut opposées : l'auto-contrôle de sécurité déclare la garde inactive alors qu'elle est active
- Sévérité      : S3 finition
- Domaine       : sécurité
- Référence     : main e8924b8
- Emplacement   : `backend/app/Services/Http/SsrfGuard.php:41` (`env('SSRF_GUARD_DENY_PRIVATE', true)`) contre `backend/app/Console/Commands/PentestSelfCheck.php:71` (`env('SSRF_GUARD_DENY_PRIVATE', false)`)
- Constat       : la garde considère par défaut qu'elle est active, l'auto-contrôle qui la vérifie considère par défaut qu'elle ne l'est pas, si bien qu'en l'absence de la variable les deux se contredisent.
- Preuve        : `04_PREUVES/agent-19/defaut-divergent-ssrf.txt` — trois états joués. Variable **absente** : « garde active = oui | auto-contrôle dit active = non | *** INCOHERENT *** ». Variable à `"true"` : cohérent. Variable à `"false"` : cohérent.
- Témoin négatif: le harnais rend « coherent » sur les deux états où les deux lectures s'accordent (`"true"` et `"false"`). Il ne crie donc pas à l'incohérence par construction : il ne la signale que dans le seul état où elle existe.
- Impact        : `pentest:self-check` remonte « SSRF_GUARD_DENY_PRIVATE not enabled in .env » sur un environnement où la garde est en réalité armée. Un faux positif de sécurité use la confiance dans l'outil et pousse à poser la variable pour faire taire l'alerte plutôt que pour changer quoi que ce soit ; à l'inverse, il masque le fait que l'auto-contrôle ne mesure pas l'état réel de la garde mais seulement la présence d'une variable. C'est l'exemple type de la constante dupliquée qui a divergé sans que rien ne le signale (piège 15).
- Reproduction  : rejouer `04_PREUVES/agent-19/defaut-divergent-ssrf.txt`.
- Correctif     : remplacer le corps de `PentestSelfCheck::checkSsrfGuard()` par un appel à `SsrfGuard::enabled()` — une seule source de vérité, et l'auto-contrôle mesure alors la garde et non la variable. ~10 min. Mieux encore : lui faire jouer deux cas réels (`check('http://169.254.169.254/')` doit être refusé, `check('https://example.com/')` accepté), ce qui le ferait mesurer l'objet plutôt que sa configuration.
- Statut        : ouvert

### [C19-010] L'opposition « non diffusible » de l'INSEE n'est filtrée que sur une des deux voies de collecte, et la purge de rattrapage ne reconnaît qu'une variante de marquage sur cinq
- Sévérité      : S1 grave
- Domaine       : conformité
- Référence     : main e8924b8
- Emplacement   : `backend/app/Services/Insee/HttpInseeClient.php:152` (filtre, branche `/siret`), `:30-60` (`fetchBySiren()`, sans filtre) et `:188-200` (branche `/siren`, sans filtre) ; `backend/app/Console/Commands/ProspectionPurgeNonDiffusible.php:21-22`
- Constat       : le filtre `statutDiffusionUniteLegale !== 'O'` est la seule occurrence du dépôt et ne s'applique qu'à la branche géographique (`/siret`, empruntée quand `department` ou `commune` est fourni) ; les **deux autres** voies de collecte du même client — `fetchBySiren()` et la branche `/siren` — n'en appliquent aucun, et la commande de purge ne supprime que les fiches dont la dénomination vaut **exactement** `[ND]`.
- Preuve        : `grep -rn "statutDiffusion" backend workers | grep -v vendor` → **une seule ligne**, `HttpInseeClient.php:152` (témoin négatif ci-dessous). Purge jouée sur ma base jetable : `04_PREUVES/agent-19/purge-non-diffusible.txt`. Sept fiches semées, cinq portant une marque INSEE de non-diffusion ; sortie de la commande : « ✅ **1** entreprises « [ND] » (non diffusibles) supprimées. » Survivants mesurés : `[ND] [ND]`, `Jean [ND]`, `[ND] ` (espace final), `[nd]` (casse) — **4 sur 5**. Le contact rattaché à la fiche `[ND] [ND]` survit avec elle (`contacts_restants = 1`).
- Témoin négatif: la commande **a bien supprimé** la fiche `[ND]` exacte et **n'a pas touché** `BOULANGERIE DUPONT SARL` : elle sait donc supprimer, et sait ne pas trop supprimer. Le « 1 sur 5 » n'est pas un montage raté. Par ailleurs le `grep` sur `statutDiffusion` n'est pas aveugle : la même recherche sur `etatAdministratifUniteLegale`, un champ voisin du même fichier, rend 7 occurrences.
- Impact        : le « non diffusible » de l'INSEE n'est pas une préférence, c'est une **opposition exercée** au titre de l'article L.123-52 du code de commerce, et la traiter comme une donnée ordinaire est directement opposable. Deux trous distincts, et ils se referment l'un sur l'autre : (a) sur les trois voies de collecte du client INSEE, **une seule filtre la diffusion** — `fetchBySiren()` (`:30-60`) et la branche `/siren` (`:188-200`) laissent entrer les unités non diffusibles ; (b) le rattrapage repose sur une égalité de chaîne exacte, alors que `fetchBySiren()` construit précisément la dénomination d'une personne physique par `trim(prenom1 . ' ' . nom)` (`:51-52`, même patron ligne 174) — deux champs masqués donnent **`[ND] [ND]`**, exactement la forme que la purge laisse passer. La voie qui n'a pas de filtre est donc celle qui produit la forme que la purge ne reconnaît pas. Le nom de la commande et son message de succès (« ✅ … non diffusibles supprimées ») donnent l'assurance que l'opposition est honorée ; la mesure dit qu'elle l'est à 20 % sur mon échantillon.
- Reproduction  : `docker exec axion-crm-postgres psql -U axion -d postgres -c "CREATE DATABASE axion_crm_a19 OWNER axion;"` ; `docker exec -e DB_DATABASE=axion_crm_a19 axion-crm-api php artisan migrate --force` ; semer les 7 fiches du fichier de preuve ; `docker exec -e DB_DATABASE=axion_crm_a19 axion-crm-api php artisan prospection:purge-non-diffusible` ; relire `companies`.
- Correctif     : (1) remonter le filtre de diffusion dans `fetchBySiren()` **et** dans la branche `/siren` de `HttpInseeClient` — 6 lignes, c'est la vraie correction, celle qui empêche l'entrée ; (2) élargir la purge à `denomination ~* '\[ND\]'` (expression régulière insensible à la casse, qui couvre les cinq variantes) plutôt qu'à l'égalité ; (3) ajouter une colonne `non_diffusible` renseignée à l'ingestion, plutôt que de déduire une opposition juridique de l'apparence d'un libellé — c'est le seul correctif durable, les deux premiers étant des rattrapages. ~1 j pour les trois.
- Statut        : ouvert

### [C19-011] Les deux fournisseurs de proxy désactivent la vérification du certificat TLS de leur propre sonde de santé
- Sévérité      : S3 finition
- Domaine       : sécurité
- Référence     : main e8924b8
- Emplacement   : `backend/app/Services/Proxies/WebshareProvider.php:70` et `backend/app/Services/Proxies/IPRoyalProvider.php:56`
- Constat       : les deux `healthCheck()` passent `'verify' => false` à Guzzle, ce qui désactive la validation du certificat du point d'arrivée `https://api.ipify.org`.
- Preuve        : `grep -rn "withOptions(\[" backend/app` → 3 résultats, dont `WebshareProvider.php:70` et `IPRoyalProvider.php:56`, tous deux de la forme `Http::withOptions(['proxy' => $endpoint->toProxyUrl(), 'verify' => false])`.
- Témoin négatif: la troisième occurrence de la même recherche, `ProxiedHttpClient.php:29`, passe **uniquement** `['proxy' => $proxy]` sans désactiver la vérification. Le dépôt sait donc écrire un client proxifié qui valide son TLS : le `verify => false` des deux fournisseurs est un choix local, pas une contrainte de la bibliothèque.
- Impact        : limité aujourd'hui — la sonde ne transporte aucun secret et les deux fournisseurs sont désactivés (`MOCK_PROXIES=true`, `WEBSHARE_ENABLED=false`). Mais l'opérateur du proxy peut se faire passer pour `api.ipify.org` et rendre le contrôle de santé toujours vert, ce qui prive l'exploitant de son seul signal réel sur l'état du proxy — d'autant que le bouton de la console ment déjà (C19-008). Et le motif d'un `verify => false` est de se taire sur une erreur de certificat : personne ne saura jamais qu'il y en avait une.
- Reproduction  : `grep -rn "verify.*false" backend/app/Services/Proxies/`.
- Correctif     : retirer `'verify' => false` des deux appels. Si une chaîne de certification propre au fournisseur est nécessaire, passer `'verify' => '/chemin/vers/ca.pem'` plutôt que de tout désactiver. ~15 min.
- Statut        : ouvert

### [C19-012] Le contournement de captcha n'est pas actif — mais rien ne l'interdit par construction, et aucune décision ne l'a arbitré
- Sévérité      : S3 finition
- Domaine       : conformité
- Référence     : main e8924b8
- Emplacement   : `backend/app/Services/Captcha/TwoCaptchaSolver.php:9-12` ; `backend/app/Providers/MockServicesProvider.php:57` et `:73` ; `infra/scripts/configure-prod-env.sh:46`
- Constat       : `TwoCaptchaSolver::solve()` ne fait que lever une `LogicException`, il n'est bindé que si `MOCK_CAPTCHA=false` — valeur jamais employée, y compris en production — et le contrat `CaptchaSolver` n'a aucun consommateur dans le code.
- Preuve        : fichier `TwoCaptchaSolver.php` lu intégralement (13 lignes) : le corps est `throw new \LogicException('TwoCaptchaSolver requires MOCK_CAPTCHA=false + Sprint 7 implementation.');`. `grep -rn "CaptchaSolver" backend/app backend/config backend/routes backend/tests` → 5 fichiers seulement : le contrat, les deux implémentations, et 3 lignes du provider — **aucune injection, aucun `app(CaptchaSolver::class)`, aucun test**. `MOCK_CAPTCHA=true` dans `.env:12`, `.env.example:12`, et `infra/scripts/configure-prod-env.sh:46` **alors que la ligne 35 du même script pose `MOCK_MODE=false`**. `TWOCAPTCHA_API_KEY` est vide et n'apparaît dans aucun `config/services.php`.
- Témoin négatif: le contrôle sait repérer un service de collecte réellement branché — la même méthode appliquée à `MentionsLegalesScraperService` remonte son appelant (`WaterfallOrchestrator.php:237`, `$found = $this->mentionsLegales->scrape($company);`). L'absence d'appelant pour `CaptchaSolver` est donc mesurée, pas supposée.
- Impact        : c'est **le bon état** — le produit ne contourne aucun captcha aujourd'hui, et je le note pour qu'on ne suppose pas l'inverse. Deux réserves. (a) Le seul rempart en production est la ligne 46 du script d'environnement : `MOCK_MODE=false` y est posé onze lignes plus haut, donc retirer ou oublier cette ligne bascule le binding sur `TwoCaptchaSolver` — qui, lui, ne fait qu'exploser, mais le garde-fou tient à une ligne de script, pas à une décision. (b) La spec réclame l'inverse : `spec/05_scrapers_14_sources.md:1002` porte « **2captcha intégration OBLIGATOIRE (P0 audit)** ». La seule ligne d'évaluation juridique du dépôt, `spec/17_rgpd_aiact_owasp.md:67`, dit « **à évaluer juridiquement** » à propos de 2captcha (Ltd, Russie, hors UE, « pas de DPA standard ») — c'est-à-dire l'aveu qu'aucune décision n'a été prise. Contourner un captcha est le franchissement délibéré d'une mesure technique de protection : cela se décide, ou cela se renonce ; cela ne se laisse pas dépendre d'une variable d'environnement.
- Reproduction  : `grep -rn "CaptchaSolver" backend/app` ; `grep -rn "MOCK_CAPTCHA" . --include=*.sh --include=*.yml --include=.env*`.
- Correctif     : écrire la décision (0,5 j) — soit renoncer explicitement au contournement et **supprimer** `TwoCaptchaSolver` ainsi que la ligne P0 de la spec, soit l'assumer avec son périmètre et son analyse de transfert hors UE. Dans les deux cas, ne pas laisser un stub qui n'attend qu'une variable pour devenir une intention.
- Statut        : ouvert

---

## 3. Ce qui va bien — mesuré, et à ne pas casser

Ces points ne sont pas des constats ; ils sont ici pour qu'un correctif ne les défasse pas par
inadvertance, et parce qu'un audit qui ne rapporte que du rouge n'est pas croyable.

1. **Sur IPv4, les deux gardes tiennent** : 25 cas d'attaque bloqués sur 25, y compris l'IMDS des
   quatre fournisseurs de nuage, la confusion `userinfo@`, les schémas exotiques et le rebinding DNS
   via `nip.io` — et les **3 témoins positifs passent**, donc la garde ne bloque pas le travail.
2. **Aucun identifiant de proxy n'est stockable en base** : `proxy_providers_config` n'a pas de
   colonne de secret (mesuré, avec témoin négatif qui trouve bien `users.password_hash`,
   `users.totp_secret`, `invitations.token_hash`). `GET /proxy-providers` ne peut donc rien fuiter.
3. **`toProxyUrl()` n'est jamais journalisé** : 2 appelants, tous deux vers Guzzle.
4. **Le captcha n'est pas contourné** (C19-012), les proxies sont inactifs (`MOCK_PROXIES=true`).
5. **Côté TypeScript, la garde SSRF est posée au bon endroit** — sur `req.target_url` et sur l'URL
   construite depuis un domaine. C'est le modèle à copier côté PHP (C19-001).
6. **`ProspectionPurgeNonCommercial` fait exactement ce qu'elle annonce** : le prédicat
   `(legal_form IS NULL OR left(legal_form, 1) <> '5')` correspond ligne pour ligne à sa docblock.
7. **Les quotas honnêtes existent** là où le partenaire l'exige : INSEE (`sleep(20)` sur 429),
   Wikidata (UA descriptif + `usleep(300 ms)`, conformément à la politique WMF), Google Places
   (quota mensuel compté). Le savoir-faire est dans le dépôt ; il n'a simplement jamais été appliqué
   aux sites scrapés (C19-005).

---

## 4. Ce que je n'ai PAS pu vérifier, et pourquoi

Cette liste est un livrable.

1. **`GET /v1/proxy-providers` joué en HTTP réel.** Non vérifié. Le conteneur `axion-crm-api` a cessé
   de répondre en cours de session (`curl -m 20 http://localhost:58080/up` → `HTTP 000`, idem via
   `https://api.localhost/up`), probablement saturé par la reconstruction de `axion_crm` menée en
   parallèle par un autre agent, plus mes propres appels `artisan` (~3 min chacun). J'ai contourné en
   mesurant **ce que la réponse peut contenir** : le contrôleur sérialise le modèle brut (lu
   verbatim), sans Resource ni `$hidden`, donc les colonnes de la table *sont* la réponse — et je les
   ai listées (`proxy-providers-colonnes.txt`). La conclusion « aucun identifiant ne peut fuiter »
   tient sur cette mesure. Ce qui reste non mesuré : la forme exacte de l'enveloppe JSON, et le code
   de statut sans authentification (couvert par A-001).
2. **Le nombre réel de fiches `[ND]` en production.** Non vérifié : la production est en lecture
   seule et je n'ai pas de justificatif d'accès à sa base ; la base locale `axion_crm` est en cours de
   reconstruction, donc son décompte n'aurait rien voulu dire. C19-010 est donc mesuré sur un
   échantillon **construit** (7 fiches, 5 marquées), pas sur le stock. La proportion « 1 sur 5 » est
   celle de mon échantillon et ne doit pas être extrapolée au stock ; ce qui est établi sans réserve,
   c'est **quelles formes** la purge laisse passer.
3. **Le comportement de Playwright sur une redirection.** Non joué. C19-003 est mesuré côté PHP
   (Guzzle) uniquement. Côté Node, `ensureSsrf` est appelé avant `page.goto` et `axios.get`, tous
   deux suivent les redirections par défaut (`maxRedirects: 5` pour axios) : le défaut est le même
   **par construction**, mais je ne l'ai pas fait rougir. À jouer avant de clore C19-003.
4. **`prospection:purge-non-commercial` jouée.** Non jouée. Son prédicat SQL est lisible et sans
   ambiguïté, et elle ne porte aucun enjeu de conformité (elle filtre des formes juridiques, pas une
   opposition de personne). J'ai préféré consacrer le temps de `artisan` — très lent sur ce poste —
   à la purge « non diffusible », qui, elle, touche un droit exercé. Réserve honnête : je n'ai donc
   pas mesuré son volume de suppression, ni le fait qu'elle supprime aussi, en cascade, les contacts
   rattachés (le `ON DELETE CASCADE` est établi, l'ampleur ne l'est pas).
5. **Les workers Playwright en fonctionnement.** Non observés : ils sont **retirés du déploiement**
   depuis le 2026-08-14 (`docker-compose.yml:182-188`, décision actée) et absents de
   `docker-compose.prod.yml`. Mes constats C19-002 et C19-006 les concernant portent donc sur du code
   **testé en CI mais non déployé**. Ils redeviennent immédiatement actifs si quelqu'un redéclare les
   services — et le code, lui, est resté.
6. **Les voies `fetchBySiren()` et `/siren` de `HttpInseeClient` jouées contre l'API réelle.** Non
   jouées : interdiction d'émettre vers un tiers réel. L'absence de filtre de diffusion y est établie
   par lecture (`:30-60` et `:188-200`, comparées à `:152`) et par le `grep` exhaustif à une seule
   occurrence, pas par une réponse INSEE observée. Ce qui n'est donc pas mesuré, c'est la **fréquence
   réelle** d'unités non diffusibles renvoyées par ces deux voies — seulement le fait qu'aucune n'est
   écartée.
7. **`ScrapingIngestFile` et les autres voies d'ingestion.** Hors périmètre nominatif, non explorées.
   Je ne peux donc pas affirmer que les deux voies INSEE sont les **seules** entrées de fiches
   non-diffusibles ; C19-010 ne prétend rien au-delà de ce qu'il a mesuré.
8. **Le comportement des gardes sous un résolveur DNS Linux pour la matrice TypeScript.** La matrice
   TS a été jouée depuis l'hôte Windows (`npx tsx`), faute de conteneur `workers` en fonctionnement.
   Les trois lignes `host.docker.internal`, `axion-crm-postgres`, `axion-crm-redis` y sortent en
   `dns_no_records` **à cause du résolveur de l'hôte**, pas de la garde ; je les ai signalées comme
   telles dans le tableau et ne les compte pas comme un écart PHP↔TS. Tout le reste de la matrice est
   indépendant du résolveur.
