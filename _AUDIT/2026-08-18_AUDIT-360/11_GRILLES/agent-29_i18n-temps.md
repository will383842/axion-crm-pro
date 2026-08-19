# AGENT 29 — Internationalisation et temps

> Référence mesurée au démarrage : `git log --oneline -1` → **`e8924b8`** (`fix(rgpd+acces)…` #189),
> la référence annoncée par le dossier commun. Aucun fichier du produit n'a été modifié.
> Base de mesure dédiée : **`axion_crm_a29`** (créée, migrée, `select count(*) from migrations` = **58**).
> Sorties brutes : `_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/agent-29/`.

---

## 0. Ce que ce rapport ajoute, et ce qu'il se contente de vérifier

| Déjà mesuré par un autre agent | Ce que j'en fais |
|---|---|
| **B13-006** — le canal entrant décale `occurred_at` et `consent_at` de +7 200 s ; la garde mesure sa propre fixture | **Vérifié et étendu** : rejoué sur base dédiée, table réelle `activities`, et **sur les deux bascules horaires**. J'ajoute que l'écart vaut **+3 600 s** la moitié de l'année, pas +7 200 s. |
| **A05-008** — `DB_TIMEZONE` posée en production, absente des `docker-compose` | **Vérifié**, non re-rapporté. J'y ajoute un constat neuf : la variable **posée à vide** met l'API à terre (D29-010). |
| « il n'y a pas de test de bascule horaire » | **Établi par la mesure** : `grep -rlin '2026-03-29\|2026-10-25\|daylight\|heure d.été'` sur `backend/tests/` → **0 fichier** (les 3 fichiers qui contiennent « bascule » parlent de bascules d'état, pas d'heure). **Le critère 16 du §29 n'a jamais été joué dans ce dépôt. Je l'ai joué.** |

---

## 1. GRILLE — internationalisation, écran par écran

**Méthode.** Deux passes d'analyse syntaxique (AST TypeScript, pas de `grep`), sur les 84 `.tsx`/`.ts`
non-test de `frontend/src` :

- **Passe 1** — texte visible dans le JSX : nœuds `JsxText` + attributs `placeholder`, `title`,
  `aria-label`, `alt`, `label`. Script : `scratchpad/scan-hardcoded.cjs`, sortie
  `04_PREUVES/agent-29/chaines-en-dur-detail.txt`.
- **Passe 2** — libellés hors JSX : propriétés d'objet (`label:`, `title:`, `description:`…),
  maps `*_LABEL*`, `toast.*('…')`, `confirm('…')`. Sortie `chaines-hors-jsx.txt`.
- **Témoin positif du compteur** : `LoginPage.tsx` utilise réellement `t()` ; la passe 1 n'y compte
  **que 2** chaînes (le logo « Axion CRM Pro » et un séparateur) et marque le fichier « utilise
  i18next ». Le compteur voit donc bien la différence entre `{t('auth.login.title')}` et
  `<h1>Connexion</h1>`.

| # | Écran (route) | Fichier | Passe 1 (JSX) | Passe 2 (hors JSX) | **Total** | Passe par `t()` ? |
|---:|---|---|---:|---:|---:|---|
| 1 | `/audiences/new` | `features/audiences/AudienceBuilderPage.tsx` | 31 | 47 | **78** | non |
| 2 | `/companies` | `features/companies/CompaniesListPage.tsx` | 36 | 38 | **74** | non |
| 3 | `/campaigns/new` | `features/campaigns/CampaignWizardPage.tsx` | 48 | 20 | **68** | non |
| 4 | `/settings` | `features/settings/SettingsPage.tsx` | 24 | 36 | **60** | non |
| 5 | `/media` | `features/media/MediaListPage.tsx` | 25 | 32 | **57** | non |
| 6 | `/campaigns/$campaignId` | `features/campaigns/CampaignDetailPage.tsx` | 47 | 10 | **57** | non |
| 7 | `/scraper-runs` | `features/scraping/ScraperRunsPage.tsx` | 36 | 10 | **46** | non |
| 8 | `/rgpd/requests` | `features/rgpd/RgpdRequestsPage.tsx` | 27 | 10 | **37** | non |
| 9 | `/companies/$companyId` | `features/companies/CompanyDetailPage.tsx` | 29 | 6 | **35** | non |
| 10 | `/international/roumanie` | `features/international/RoumaniePage.tsx` | 20 | 13 | **33** | non |
| 11 | `/audit-logs` | `features/rgpd/AuditLogsPage.tsx` | 30 | 3 | **33** | non |
| 12 | `/campaigns` | `features/campaigns/CampaignsListPage.tsx` | 18 | 15 | **33** | non |
| 13 | `/tags` | `features/tags/TagsManagerPage.tsx` | 19 | 14 | **33** | non |
| 14 | `/coverage` | `features/coverage/CoveragePage.tsx` | 20 | 11 | **31** | non |
| 15 | `/audiences/$audienceId` | `features/audiences/AudienceDetailPage.tsx` | 21 | 8 | **29** | non |
| 16 | `/llm/router` | `features/llm/LlmRouterPage.tsx` | 21 | 4 | **25** | non |
| 17 | `/rgpd/ai-act` | `features/rgpd/AiActRegisterPage.tsx` | 25 | 0 | **25** | non |
| 18 | `/users` | `features/users/UsersPage.tsx` | 17 | 6 | **23** | non |
| 19 | `/contacts` | `features/contacts/ContactsListPage.tsx` | 14 | 7 | **21** | non |
| 20 | `/journalists` | `features/media/JournalistsListPage.tsx` | 17 | 2 | **19** | non |
| 21 | `/media/$mediaId` | `features/media/MediaDetailPage.tsx` | 18 | 0 | **18** | non |
| 22 | `/audiences` | `features/audiences/AudiencesListPage.tsx` | 13 | 5 | **18** | non |
| 23 | `/` (tableau de bord) | `features/dashboard/DashboardPage.tsx` | 9 | 7 | **16** | non |
| 24 | `/admin/observability` | `features/observability/ObservabilityPage.tsx` | 15 | 0 | **15** | non |
| 25 | `/console/arbitrage` | `features/crm-console/ArbitragePage.tsx` | 12 | 3 | **15** | non |
| 26 | `/llm/rotations` | `features/llm/RotationsPage.tsx` | 9 | 5 | **14** | non |
| 27 | `/console/contacts` | `features/crm-console/ContactsHubPage.tsx` | 9 | 4 | **13** | non |
| 28 | `/llm/proxy-providers` | `features/llm/ProxyProvidersPage.tsx` | 11 | 1 | **12** | non |
| 29 | `/console/vivier` | `features/crm-console/CandidatesPage.tsx` | 11 | 1 | **12** | non |
| 30 | `/console/personnes/$personKey` | `features/crm-console/PersonTimelinePage.tsx` | 11 | 0 | **11** | non |
| 31 | `/password-reset` | `features/auth/PasswordResetPage.tsx` | 8 | 2 | **10** | non |
| 32 | `/magic-link` | `features/auth/MagicLinkPage.tsx` | 6 | 1 | **7** | **oui** |
| 33 | `/2fa` | `features/auth/TwoFactorPage.tsx` | 2 | 1 | **3** | **oui** |
| 34 | `/login` | `features/auth/LoginPage.tsx` | 2 | 0 | **2** | **oui** |
| 35 | `/*` (404) | `features/misc/NotFoundPage.tsx` | 2 | 0 | **2** | non |
| 36 | `/cold-email` | `features/phase2-scaffold/ColdEmailStub.tsx` | 1 | 0 | **1** | **oui** |
| 37 | `/linkedin` | `features/phase2-scaffold/LinkedInStub.tsx` | 1 | 0 | **1** | **oui** |
| | **TOTAL 37 écrans** | | **665** | **322** | **987** | **5 / 37** |

**Composants partagés** (hors écrans, 34 fichiers + `ui/`) : passe 1 = **76**, passe 2 = **354**
(dont `features/campaigns/fr-zones.ts` = 169, référentiel géographique — à retrancher si l'on
considère que les noms de départements ne se traduisent pas ; **185** sans lui).
Les plus lourds : `components/layout/Sidebar.tsx` (**6 + 29 = 35** libellés de navigation en dur),
`components/layout/AutoBreadcrumbs.tsx` (**19**), `features/crm-console/types.ts` (**24**),
`features/campaigns/types.ts` (**25**).

**Total dépôt, toutes passes** : **741 + 676 = 1 417** chaînes visibles écrites hors dictionnaire
(**1 248** en retranchant `fr-zones.ts`).

### 1 bis. Le dictionnaire lui-même

| Point de grille | Mesure |
|---|---|
| Clés dans `fr.json` | **27** (fichier de 45 lignes) |
| Clés dans `en.json` | **27** (fichier de 45 lignes) |
| **Clés présentes en FR et absentes en EN** | **0** |
| **Clés présentes en EN et absentes en FR** | **0** |
| Valeurs identiques FR/EN (non traduites) | **3** (`app.name`, `nav.contacts`, `nav.scraperRuns`) |
| Appels `t('…')` dans tout `src` | **22** bruts, dont **3 faux positifs** (`t('a')` dans `ScraperRunsPage.tsx:197` = une variable de lien `<a>`, `t('name')` et `t('cost_cap_eur')` dans `SettingsPage.tsx:146-147` = `FormData.get`) → **19 appels réels** |
| Clés distinctes réellement appelées | **12 sur 27** |
| **Clés mortes** (jamais appelées) | **15** : `app.name`, `app.tagline`, les **7** `nav.*`, `common.empty`, `common.retry`, `common.save`, `common.cancel`, `common.delete`, `common.search` |
| Fichiers appelant `useTranslation` | **5 sur 84** (`LoginPage`, `MagicLinkPage`, `TwoFactorPage`, `ColdEmailStub`, `LinkedInStub`) |
| Sélecteur de langue dans l'interface | **aucun** — `grep -rn "changeLanguage\|i18n.language"` sur `src` → 0 hit hors `lib/i18n.ts` |
| `changeLanguage` existe quelque part ? | **oui, une seule fois** : `frontend/tests/helpers/renderScreen.tsx:222`, qui **force `fr`** avant chaque test. Les 37 fichiers de test frontend ne peuvent donc structurellement jamais voir un défaut EN. |
| `<html lang>` | **`fr` en dur** (`frontend/index.html:2`) — ne suit pas `i18n.language` |
| Négociation de langue côté API | **aucune** : `grep -rn "Accept-Language\|App::setLocale"` sur `backend/app` + `backend/bootstrap` → 0 hit |
| Détection | `lib/i18n.ts` : `LanguageDetector`, `order: ['localStorage','navigator']`, `fallbackLng: 'fr'`, **pas de `supportedLngs`** |
| Test d'i18n | **aucun**. `tests/e2e/console-locale.spec.ts` porte à confusion : « locale » y signifie **« en local »** (la console tourne sur `app.localhost`), pas « internationalisation ». |

### 1 ter. Pluriels, genres, concaténations

| Point de grille | Mesure |
|---|---|
| Formes plurielles i18next (`_one` / `_other`) | **0** dans `fr.json` et `en.json` |
| Pluriels faits à la main (`> 1 ? 's' : ''`) | **8** occurrences, dans 6 écrans : `CampaignWizardPage.tsx:492` (deux fois sur la même ligne) et `:516`, `CompaniesListPage.tsx:599-600` et `:644`, `RoumaniePage.tsx:171`, `ScraperRunsPage.tsx:392`, `TagsManagerPage.tsx:221` |
| Concaténations rendant la traduction impossible | Oui, systématiques. Exemple type, `CampaignWizardPage.tsx:492` : `{selectedZones.length} zone{selectedZones.length > 1 ? 's' : ''} sélectionnée{selectedZones.length > 1 ? 's' : ''}` — **trois fragments** dont deux morphèmes isolés. Autre : `CompaniesListPage.tsx:599-600` coupe « filtre**s** actif**s** » sur deux lignes JSX. `CampaignWizardPage.tsx:516` empile en plus un ternaire de **genre** (`'département' : 'région' : 'ville'`) devant le `s`. |
| Genres | Aucun mécanisme. Les accords sont figés dans la chaîne française (« sélectionnée**s** », « complète**s** », « affiché**s** »). |
| Justesse de la règle appliquée | `> 1 ? 's'` est **la règle française** (0 et 1 au singulier). Elle est **fausse en anglais** (`0 runs`). Le code postule donc le français, ce qui est cohérent avec le reste — et incompatible avec `en.json`. |

### 1 quater. Formats — dates, nombres, montants, téléphones

| Point de grille | Mesure |
|---|---|
| Appels de formatage (`toLocaleString`/`toLocaleDateString`/`toLocaleTimeString`) | **63**, dans 27 fichiers |
| Combien passent par `i18n.language` ? | **0** — les 63 sont figés sur la chaîne littérale `'fr-FR'` (ou `"fr-FR"`) |
| Combien passent une option `timeZone` ? | **0** |
| `Intl.NumberFormat` / `Intl.DateTimeFormat` | **0 occurrence** dans tout `frontend/src` |
| Montants | **5 occurrences de `€`**, dans 2 fichiers. Le seul rendu calculé est `LlmRouterPage.tsx:222` et `:264` : `` `${(usage.data?.total_eur ?? 0).toFixed(2)} €` `` → **point décimal anglais** (`12.34 €` et non `12,34 €`), **espace ordinaire** avant `€` |
| **Piège 17 — espace fine insécable U+202F** | **0 occurrence** de U+202F dans `frontend/src` et `backend/app` ; **0 occurrence** de U+00A0 dans `frontend/src`. **Méthode validée dans les deux sens** avant de conclure : fichier témoin fabriqué contenant une ligne avec U+202F et une ligne avec l'espace ordinaire (`od -c` : `342 200 257`) ; `grep $' '` rend **la ligne A seulement**, `grep -c ' '` rend **2**. Le contrôle sait donc distinguer les deux espaces. **Corollaire important** : les 63 `toLocaleString('fr-FR')` **produisent** de l'U+202F à l'exécution (séparateur de milliers `fr-FR` depuis CLDR 42) — un contrôle d'affichage qui chercherait `1 234` avec une espace ordinaire ne trouverait rien. Le défaut est donc **dans les montants écrits à la main**, pas dans les nombres formatés. |
| Téléphones | **Aucun formatage**. `ContactsListPage.tsx:263-269` et `CompanyDetailPage.tsx:197` impriment `{c.phone}` tel quel, et construisent `href={`tel:${c.phone}`}` sans normalisation E.164. |

---

## 2. GRILLE — le temps

### 2.1 Où les dates sont stockées, et dans quel type

Mesuré sur la base réelle (`information_schema.columns` restreint aux `BASE TABLE`, 114 tables),
sortie `04_PREUVES/agent-29/colonnes-types-resume.txt` et `colonnes-dates-detail.txt`.

| Type de colonne | Colonnes | Tables |
|---|---:|---:|
| `timestamp with time zone` | **203** | 93 |
| `timestamp without time zone` | **0** | 0 |
| `date` | **3** | 3 |
| `time with/without time zone` | **0** | 0 |

**Le stockage est bon, et il ne vient pas de Laravel.** Le constructeur de schéma de Laravel 12
produirait `without time zone` (`vendor/…/Schema/Grammars/PostgresGrammar.php:1062` :
`return 'timestamp'.(…).' without time zone';`). Les 203 colonnes sont donc écrites en **SQL brut**
dans les migrations. Les **3 seules** colonnes créées par le constructeur Laravel le sont via
`timestampTz()` (`2026_07_06_000001_add_website_status_to_companies.php:21`,
`2026_08_15_000004_email_suppressions.php:61-62`) — d'où leur précision `(0)`.

Répartition par origine d'écriture :

| Type | Valeur par défaut | Colonnes | Qui écrit |
|---|---|---:|---|
| `timestamptz` | `now()` | **110** | **Postgres** — toujours juste, jamais affecté par le décalage |
| `timestamptz` | `CURRENT_TIMESTAMP` | **2** | **Postgres** — idem |
| `timestamptz` | `now() + '30 days'` / `+ '90 days'` | **2** | **Postgres** |
| `timestamptz` | *(aucun)* | **89** | **l'application** — c'est la population exposée |
| `date` | *(aucun)* | **3** | l'application (`analytics_cohorts.cohort_period`, `analytics_daily_rollups.day`, `analytics_funnel_snapshots.snapshot_date`) |

Colonnes à **précision tronquée à la seconde** (`timestamp(0)`), soit une perte d'information
silencieuse : `companies.website_checked_at`, `email_suppressions.first_seen_at`,
`email_suppressions.last_seen_at`. Toutes les autres sont en précision 6.

### 2.2 Où elles sont écrites — et avec quel fuseau

| Chemin d'écriture | Fuseau effectif | Mesure |
|---|---|---|
| `DEFAULT now()` / `CURRENT_TIMESTAMP` Postgres (**112 colonnes**) | fuseau du serveur, **absolu et juste** | `HorodatagesFuseauTest` « ce que Postgres écrit lui-même reste juste » |
| `now()` PHP / Carbon (**89 colonnes**) | `date_default_timezone_get()` = **`Europe/Paris`** | `04_PREUVES/agent-29/local-fuseaux.txt` : `PHP now() = 2026-08-19 13:14:40 +02:00 Europe/Paris` |
| Sérialisation vers PDO | **chaîne nue, sans décalage** : `Grammar::getDateFormat()` = `Y-m-d H:i:s` | `serialisation-dates.txt` : `Carbon=2026-03-29 14:00:00 +02:00` → `vers SQL=2026-03-29 14:00:00` |
| Lecture de cette chaîne par Postgres | fuseau **de la session** | `SHOW TimeZone` = **`Etc/UTC`** dans l'atelier ; **`Europe/Paris`** en production (A05-008) |
| Valeur venue du client (canal site) | ramenée dans `app.timezone` par `SiteSyncEvent::parseDate()` (`app/Crm/Ingest/SiteSyncEvent.php:430`) puis écrite nue | idem ci-dessus |

`APP_TIMEZONE=Europe/Paris` (`backend/.env:27`, `config/app.php:8`) — l'application ne vit **pas**
en temps universel. Le seul endroit du dépôt qui pose `DB_TIMEZONE` est `phpunit.xml:52` et
`phpunit-ci.xml:60` ; **aucun `docker-compose*.yml` ne la pose** (déjà relevé, A05-008).

### 2.3 Où elles sont lues et affichées

| Chemin de lecture | Ce qui arrive au SPA | Ce que le SPA en fait |
|---|---|---|
| Modèle Eloquent avec cast `'datetime'` | **ISO 8601 zoulou** : `2026-03-29T12:00:00.000000Z` (mesuré, `serialisation-dates.txt`) | `new Date(...)` puis `.toLocaleString('fr-FR')` → **converti dans le fuseau du navigateur**. Correct. |
| `DB::table(...)->get()` (constructeur de requêtes, **sans cast**) | **la chaîne brute de Postgres** : `2026-03-29 14:00:00+00` (mesuré) | **Deux écrans l'impriment telle quelle** — voir D29-007 |
| Colonnes `timestamp(0)` | tronquées à la seconde | — |

Écrans qui affichent une date **sans aucune conversion** :
`features/crm-console/PersonTimelinePage.tsx:114` (`{entry.occurred_at ?? '—'}`, la **fiche 360°**)
et `features/crm-console/ArbitragePage.tsx:132` (`{row.occurred_at ?? '—'}`).
Côté serveur : `PersonTimelineController.php:113` et `ArbitrageController.php:70`, tous deux en
`DB::table('activities')`.

### 2.4 🔴 Le critère 16 du §29, joué sur les deux bascules

**Ce qui a été joué.** Base dédiée `axion_crm_a29` (58 migrations), **table réelle `activities`**,
**chemin réel** : `SiteSyncEvent::fromArray()` — le seul point d'entrée des dates extérieures — puis
`DB::table('activities')->insertGetId([... 'occurred_at' => $event->occurredAt ...])`, ce que fait
mot pour mot `SiteSyncIngestService.php:587-594`. Six cas : « 14:00 heure de Paris » aux deux
bascules et à deux témoins hors bascule, plus les deux heures pathologiques (02:30 le 29/03, qui
**n'existe pas** à Paris ; 02:30 le 25/10, qui existe **deux fois**).
Script : `scratchpad/a29-bascules.php`.

#### (a) Configuration de l'atelier — `DB_TIMEZONE` absente

Sortie : `04_PREUVES/agent-29/bascules-SANS-db-timezone.txt`
(`PG SHOW TimeZone = Etc/UTC`, `app.timezone = Europe/Paris`)

| Cas | Instant voulu | Relu → heure de Paris | Écart | Ce que la fiche 360° affiche |
|---|---|---|---:|---|
| témoin été 2026-06-15 14:00 | `14:00:00 +02:00` | `16:00:00` | **+7 200 s** | `2026-06-15 14:00:00+00` |
| **BASCULE ÉTÉ 2026-03-29 14:00** | `14:00:00 +02:00` | `16:00:00` | **+7 200 s** | `2026-03-29 14:00:00+00` |
| **BASCULE HIVER 2026-10-25 14:00** | `14:00:00 +01:00` | `15:00:00` | **+3 600 s** | `2026-10-25 14:00:00+00` |
| témoin hiver 2026-01-15 14:00 | `14:00:00 +01:00` | `15:00:00` | **+3 600 s** | `2026-01-15 14:00:00+00` |
| 2026-03-29 02:30 (heure **inexistante**) | `03:30:00 +02:00` | `05:30:00` | **+7 200 s** | `2026-03-29 03:30:00+00` |
| 2026-10-25 02:30 (heure **ambiguë**) | `02:30:00 +01:00` | `03:30:00` | **+3 600 s** | `2026-10-25 02:30:00+00` |

**6 cas sur 6 décalés.** Le décalage n'est pas de 2 h : il vaut **le décalage de Paris à la date
considérée**, donc **+3 600 s du dernier dimanche d'octobre au dernier dimanche de mars**. La
formule usuelle « le décalage de 2 h » est donc fausse la moitié de l'année — et c'est bien ce que
fait `CorrigerHorodatages` (`:39` « applique le décalage en vigueur À CETTE DATE-LÀ »), vérifié.

#### (b) Configuration de la production — `DB_TIMEZONE=Europe/Paris`

Sortie : `04_PREUVES/agent-29/bascules-AVEC-db-timezone.txt`
(`PG SHOW TimeZone = Europe/Paris`, `DB_TIMEZONE (env) = 'Europe/Paris'`)

| Cas | Instant voulu | Relu → heure de Paris | Écart | Ce que la fiche 360° affiche |
|---|---|---|---:|---|
| témoin été 2026-06-15 14:00 | `14:00:00 +02:00` | `14:00:00` | **0 s** | `2026-06-15 14:00:00+02` |
| **BASCULE ÉTÉ 2026-03-29 14:00** | `14:00:00 +02:00` | `14:00:00` | **0 s** | `2026-03-29 14:00:00+02` |
| **BASCULE HIVER 2026-10-25 14:00** | `14:00:00 +01:00` | `14:00:00` | **0 s** | `2026-10-25 14:00:00+01` |
| témoin hiver 2026-01-15 14:00 | `14:00:00 +01:00` | `14:00:00` | **0 s** | `2026-01-15 14:00:00+01` |
| 2026-03-29 02:30 (heure **inexistante**) | `03:30:00 +02:00` | `03:30:00` | **0 s** | `2026-03-29 03:30:00+02` |
| 2026-10-25 02:30 (heure **ambiguë**) | `02:30:00 +01:00` | `02:30:00` | **0 s** | `2026-10-25 02:30:00+01` |

**6 cas sur 6 justes.** C'est le **témoin négatif** de la mesure (a) : la même sonde, sur la même
base, la même table et le même chemin de code, rend **0 s** dès que la session Postgres est alignée.
Elle sait donc mesurer autre chose que « décalé ».

#### VERDICT SUR LE CRITÈRE 16

| Volet du critère | Atelier (`DB_TIMEZONE` absente) | Production (`DB_TIMEZONE=Europe/Paris`, cf. A05-008) |
|---|---|---|
| « toute date est **stockée en temps universel** » | ❌ l'instant stocké est faux de +3 600 s ou +7 200 s | ⚠️ **le type est juste** (`timestamptz`, 203/203) et **l'instant est juste** (0 s sur 6 cas), mais l'écriture passe par une **heure nue interprétée**, pas par un temps universel explicite : la justesse dépend d'une variable d'environnement, pas du code |
| « **affichée dans le fuseau de l'utilisateur** » | ❌ | ❌ — la fiche 360° affiche le fuseau **du serveur**, pas celui de l'utilisateur (D29-007) ; les 35 autres écrans affichent le fuseau **du navigateur** sans le fixer (D29-004) |
| « un événement émis à 14:00 apparaît à **14:00** sur la fiche » | ❌ affiche `14:00:00+00`, qui **n'est pas** 14:00 à Paris | ⚠️ affiche `14:00:00+02` — le bon cadran, mais **par coïncidence** (le serveur est à Paris), dans un format brut qu'aucun autre écran n'utilise |
| « **y compris aux changements d'heure** » | ❌ 6/6 décalés | ✅ **0 s sur les deux bascules et sur les deux heures pathologiques** — mesuré ici pour la première fois |
| « test joué sur les deux bascules de l'année » | **jamais joué avant ce rapport** ; `grep` sur `backend/tests/` → 0 fichier | idem |



#### (c) Ce que Postgres et PHP font des deux heures pathologiques

Sortie : `04_PREUVES/agent-29/postgres-heures-nues-bascules.txt` et `serialisation-dates.txt`.

| Heure nue envoyée | Postgres (session `Europe/Paris`) | PHP/Carbon (`Europe/Paris`) | Accord ? |
|---|---|---|---|
| `2026-03-29 02:30:00` (n'existe pas) | `2026-03-29 03:30:00+02` (epoch 1774747800) | `03:30:00 +02:00` → JSON `01:30Z` (epoch 1774747800) | **oui** |
| `2026-10-25 02:30:00` (existe 2 fois) | `2026-10-25 02:30:00+01` (epoch 1792891800) | `02:30:00 +01:00` → JSON `01:30Z` (epoch 1792891800) | **oui** |

**Bonne nouvelle mesurée** : la convention « heure nue lue comme heure de Paris » ne perd rien aux
deux bascules — PHP et Postgres résolvent l'heure inexistante et l'heure ambiguë **de la même
façon**. Le correctif `DB_TIMEZONE=Europe/Paris` n'introduit donc **pas** d'indétermination
supplémentaire. C'est un point qu'il fallait mesurer avant de l'affirmer ; il est mesuré.

### 2.5 Les tâches planifiées

`backend/routes/console.php` déclare **35 tâches**. **Aucune** n'appelle `->timezone(...)` :
`grep -rn "timezone" routes/console.php app/Console/Kernel.php bootstrap/app.php` → **0 hit**.

Le fuseau effectif est donc celui de `Date::now()`, c'est-à-dire `date_default_timezone_get()`,
c'est-à-dire **`app.timezone` = `Europe/Paris`** — mesuré, pas déduit :
`Illuminate/Console/Scheduling/Event.php:297-306` (`$date = Date::now(); if ($this->timezone) …`).
Le conteneur `axion-crm-scheduler` tourne `php artisan schedule:work`, horloge système **UTC**
(`docker exec … date` → `Wed Aug 19 11:05:37 UTC 2026`), ce qui est sans effet : c'est PHP qui
traduit.

**Conséquence aux bascules**, mesurée en énumérant chaque minute de temps universel des deux
journées et en la traduisant en heure locale de Paris
(`04_PREUVES/agent-29/planificateur-bascules.txt`) :

| Heure planifiée | 2026-03-29 | 2026-10-25 | Tâches concernées |
|---|---|---|---|
| **`dailyAt('02:00')`** | **0 fois — JAMAIS** | **2 fois** | `signals:nightly-scan` (`console.php:18`) |
| `03:00`, `04:00`, `04:20`, `04:30`, `04:45`, `05:00`, `05:05`, `05:15`, `05:20`, `05:25`, `05:40` | 1 fois | 1 fois | tout le reste |

Les purges RGPD sont **hors de danger** : `rgpd:purge-vivier` → `monthlyOn(2, '03:30')`,
`rgpd:purge-business-prospects` → `monthlyOn(2, '04:15')`, `retention:purge` → `dailyAt('04:00')`,
`rgpd:anonymize-ips` → `dailyAt('04:30')`, `retention:prune-scraper-runs` → `dailyAt('04:20')`.
Aucune ne tombe dans l'heure escamotée. Les imports hebdomadaires de 02:15/02:30/02:45 sont posés
sur `weeklyOn(1, …)` (lundi) alors que les bascules tombent un dimanche : hors danger également.
**La seule tâche affectée est `signals:nightly-scan`**, et elle est aussi la seule tâche `dailyAt`
du fichier **sans `withoutOverlapping()` ni `onOneServer()`**.

---

## 3. CONSTATS

### [D29-001] Le CRM n'est pas bilingue : 27 clés de dictionnaire pour 1 417 chaînes visibles écrites en dur
- Sévérité      : S1
- Domaine       : interface
- Référence     : main `e8924b8`
- Emplacement   : `frontend/src/locales/{fr,en}.json` · `frontend/src/lib/i18n.ts` · les 37 écrans
- Constat       : `en.json` et `fr.json` comptent **27 clés chacun, sans aucune clé manquante dans un sens ni dans l'autre**, mais seuls **5 fichiers sur 84** appellent `useTranslation`, **12 clés sur 27** sont réellement utilisées, et les 37 écrans portent **987 chaînes visibles** écrites directement dans le code (1 417 avec les composants partagés).
- Preuve        : `node scan-hardcoded.cjs frontend/src --detail` → `04_PREUVES/agent-29/chaines-en-dur-detail.txt` (741) ; `node scan2.cjs frontend/src` → `chaines-hors-jsx.txt` (676) ; tableau par écran dans `par-ecran.md` ; diff des clés → `0` dans les deux sens ; `grep -rho "t('[a-zA-Z0-9_.]*')" | sort -u | wc -l` → **15**, dont 3 faux positifs.
- Témoin négatif: le compteur voit la différence — sur `LoginPage.tsx`, qui utilise réellement `t()`, la passe 1 ne compte que **2** chaînes et marque le fichier « utilise i18next », alors qu'elle en compte **48** sur `CampaignWizardPage.tsx`. Le diff de clés, lui, a été validé en ajoutant mentalement une clé fictive : la fonction d'aplatissement rend bien 27 clés pour 45 lignes de JSON imbriqué.
- Impact        : un utilisateur dont le navigateur annonce `en` obtient une console **mi-anglaise mi-française** : l'écran de connexion, le 2FA et les deux écrans Phase 2 en anglais, les **33 autres écrans** en français, avec `<html lang="fr">`. `en.json` n'est pas un vestige inerte : il est **actif par détection automatique** et produit ce panachage sans qu'aucun utilisateur l'ait demandé.
- Reproduction  : `localStorage.setItem('i18nextLng','en')` puis rechargement de `https://app.localhost` ; la page de connexion passe en anglais, la barre latérale reste en français.
- Correctif     : deux voies exclusives. (a) **Assumer le monolingue** : retirer `i18next`, `react-i18next`, `i18next-browser-languagedetector` et `en.json`, remplacer les 19 `t()` par du français — ~3 h, et le panachage disparaît. (b) **Rendre le produit réellement bilingue** : extraire 1 417 chaînes, ~15-20 j. Le choix est une décision de dirigeant, pas une décision technique. Dans les deux cas, **la voie (a) doit être appliquée immédiatement** comme mesure conservatoire, sous peine de laisser une interface incohérente en production.
- Statut        : ouvert

### [D29-002] Aucun moyen de choisir sa langue : la bascule est subie, jamais offerte
- Sévérité      : S2
- Domaine       : UX
- Référence     : main `e8924b8`
- Emplacement   : `frontend/src/lib/i18n.ts:16` · `frontend/index.html:2`
- Constat       : `detection: { order: ['localStorage','navigator'] }` sans `supportedLngs`, aucun appel à `changeLanguage` dans l'application (le seul du dépôt est `tests/helpers/renderScreen.tsx:222`, qui force `fr`), et `<html lang="fr">` est figé.
- Preuve        : `grep -rn "changeLanguage\|i18n.language\|Language" frontend/src --include=*.tsx --include=*.ts` → aucun hit hors `lib/i18n.ts` (sortie jointe au dossier de preuves) ; `grep -n "lang=" frontend/index.html` → `<html lang="fr">`.
- Témoin négatif: le même `grep` trouve bien `changeLanguage` dans `frontend/tests/helpers/renderScreen.tsx:222` — il sait donc détecter l'appel quand il existe.
- Impact        : un utilisateur qui subit la bascule ne peut pas revenir en arrière depuis l'interface ; il faut vider le `localStorage`. Et `lang="fr"` ment aux lecteurs d'écran et aux correcteurs orthographiques quand l'interface est partiellement en anglais.
- Reproduction  : ouvrir la console avec un navigateur configuré en anglais ; chercher un sélecteur de langue.
- Correctif     : si la voie (a) de D29-001 est retenue, forcer `lng: 'fr'` et supprimer le détecteur (10 min). Sinon, ajouter un sélecteur dans `UserMenu` et lier `<html lang>` à `i18n.language` (~2 h).
- Statut        : ouvert

### [D29-003] Quinze des vingt-sept clés du dictionnaire sont mortes, dont les sept libellés de navigation
- Sévérité      : S3
- Domaine       : interface
- Référence     : main `e8924b8`
- Emplacement   : `frontend/src/locales/fr.json` · `frontend/src/components/layout/Sidebar.tsx:88-157`
- Constat       : `nav.dashboard`, `nav.companies`, `nav.contacts`, `nav.coverage`, `nav.scraperRuns`, `nav.users`, `nav.settings`, `app.name`, `app.tagline`, `common.empty`, `common.retry`, `common.save`, `common.cancel`, `common.delete`, `common.search` ne sont appelées nulle part ; la barre latérale écrit ses **29** libellés en dur (« Collectes », « Journaux de collecte », « Requêtes RGPD »…).
- Preuve        : intersection des 27 clés du dictionnaire et des 15 clés distinctes appelées → 12 utilisées, 15 mortes (détail en §1 bis) ; `grep -n "label:" frontend/src/components/layout/Sidebar.tsx` → 29 libellés littéraux.
- Témoin négatif: les 12 clés effectivement appelées apparaissent bien dans la même liste extraite (`auth.login.*`, `auth.twoFactor.*`, `common.error`, `common.loading`, `phase2.stub.*`) — la méthode n'est pas aveugle.
- Impact        : le dictionnaire donne l'illusion d'une couverture de la navigation qui n'existe pas. Une revue qui lit `fr.json` conclut « la barre latérale est traduite » ; elle ne l'est pas.
- Reproduction  : ouvrir `fr.json`, chercher `nav.dashboard` dans `src/` → 0 occurrence.
- Correctif     : supprimer les clés mortes (5 min) ou les brancher (~1 h). À trancher avec D29-001.
- Statut        : ouvert

### [D29-004] Les 63 formatages de date et de nombre sont figés sur `fr-FR`, et aucun ne fixe de fuseau
- Sévérité      : S2
- Domaine       : interface
- Référence     : main `e8924b8`
- Emplacement   : 27 fichiers de `frontend/src/features` (liste complète dans le dossier de preuves)
- Constat       : les 63 appels à `toLocaleString`/`toLocaleDateString`/`toLocaleTimeString` passent la chaîne littérale `'fr-FR'`, aucun ne consulte `i18n.language`, aucun ne passe d'option `timeZone`, et `Intl.NumberFormat`/`Intl.DateTimeFormat` n'apparaît **pas une seule fois** dans le dépôt frontend.
- Preuve        : `grep -rn "toLocaleDateString\|toLocaleString\|toLocaleTimeString\|Intl\." frontend/src --include=*.ts --include=*.tsx` → 63 lignes, toutes en `'fr-FR'` ou `"fr-FR"`, zéro `Intl.` ; sortie jointe.
- Témoin négatif: le même motif de recherche trouve les deux formes de guillemets (`'fr-FR'` dans `features/audiences/*`, `"fr-FR"` dans `features/media/*`) — il n'a pas manqué la moitié du corpus par un problème de quote.
- Impact        : (1) même si le dictionnaire était complet, les dates et nombres resteraient français ; (2) l'absence d'option `timeZone` rend l'affichage dépendant du réglage du poste de l'utilisateur — un poste mal réglé affiche des heures fausses sans que rien ne le signale, et **aucun contrôle du dépôt ne fixe le fuseau du navigateur de test**.
- Reproduction  : régler le fuseau de Windows sur `America/New_York`, ouvrir `/audit-logs` : les horodatages reculent de 6 h sans avertissement.
- Correctif     : centraliser dans un module `lib/dates.ts` un `formatDateTime(iso)` qui prend la locale de `i18n.language` et fixe explicitement `timeZone` (fuseau choisi par l'utilisateur, à défaut `Europe/Paris`), puis remplacer les 63 appels — ~4 h, mécanique.
- Statut        : ouvert

### [D29-005] Les montants en euros sont rendus avec un point décimal anglais et une espace ordinaire
- Sévérité      : S3
- Domaine       : interface
- Référence     : main `e8924b8`
- Emplacement   : `frontend/src/features/llm/LlmRouterPage.tsx:222` et `:264`
- Constat       : `` `${(usage.data?.total_eur ?? 0).toFixed(2)} €` `` produit `12.34 €` — séparateur décimal anglais, espace ordinaire U+0020 avant le symbole — au lieu de `12,34 €` avec espace fine insécable U+202F.
- Preuve        : `grep -rn "€\|toFixed(2)" frontend/src` → 5 occurrences de `€` dans 2 fichiers, dont 2 rendus calculés ; recherche U+202F sur `frontend/src` + `backend/app` → **0**.
- Témoin négatif: **le contrôle U+202F a été validé dans les deux sens avant de conclure** (piège 17). Fichier témoin fabriqué : ligne A avec U+202F, ligne B avec l'espace ordinaire ; `od -c` confirme les octets `342 200 257` ; `grep $' '` rend **la ligne A seulement** ; `grep -c ' '` rend **2**. Le zéro mesuré est donc un vrai zéro, pas un aveuglement d'outil. Sortie : `scratchpad/temoin-202f.txt`.
- Impact        : la seule page de coûts du produit affiche des montants dans une typographie qui n'est pas française. Portée faible (2 lignes), mais c'est la page que le dirigeant regarde pour le plafond LLM.
- Reproduction  : ouvrir `/llm/router`, lire la carte « Coût total ».
- Correctif     : `new Intl.NumberFormat('fr-FR', { style:'currency', currency:'EUR' }).format(v)` — 10 min, et l'espace fine vient gratuitement.
- Statut        : ouvert

### [D29-006] Les pluriels sont fabriqués par concaténation de morphèmes isolés : la traduction est impossible par construction
- Sévérité      : S2
- Domaine       : interface
- Référence     : main `e8924b8`
- Emplacement   : `features/campaigns/CampaignWizardPage.tsx:492` et `:516` · `features/companies/CompaniesListPage.tsx:599-600` et `:644` · `features/international/RoumaniePage.tsx:171` · `features/scraping/ScraperRunsPage.tsx:392` · `features/tags/TagsManagerPage.tsx:221`
- Constat       : 8 occurrences de `{n} mot{n > 1 ? 's' : ''}`, dont une qui empile un ternaire de genre (`'département' : 'région' : 'ville'`) devant le `s` (`CampaignWizardPage.tsx:516`) et une qui coupe « filtre**s** actif**s** » sur deux lignes JSX (`CompaniesListPage.tsx:599-600`) ; aucune forme plurielle i18next (`_one`/`_other`) n'existe dans `fr.json` ni `en.json`.
- Preuve        : `grep -rn "> 1 ? 's'\|> 1 ? \"s\"" frontend/src` → 8 lignes ; `grep -c "_one\|_other" frontend/src/locales/*.json` → 0.
- Témoin négatif: la même recherche trouve bien les deux écritures de quote et les deux styles d'espacement (`>1?'s'` n'existe pas ici, mais le motif le couvre) ; et elle rend 8 lignes, pas 0, donc elle voit.
- Impact        : la règle codée (`> 1`) est **la règle française** — 0 et 1 au singulier. Elle est **fausse en anglais** (« 0 runs »), et les morphèmes isolés `s` ne peuvent être portés dans aucun dictionnaire. Tant que ces 8 sites existent, le produit **ne peut pas** être traduit, même si D29-001 était corrigé partout ailleurs.
- Reproduction  : ouvrir `/campaigns/new`, sélectionner une zone puis deux, lire « 1 zone sélectionnée » / « 2 zones sélectionnées ».
- Correctif     : passer par `t('zones', { count: n })` avec les formes `zones_one` / `zones_other`, i18next les gère nativement — ~1 h pour les 8 sites, à faire en même temps que D29-001.
- Statut        : ouvert

### [D29-007] La fiche 360° affiche la chaîne brute de Postgres : elle montre le fuseau du serveur, jamais celui de l'utilisateur
- Sévérité      : S2
- Domaine       : interface
- Référence     : main `e8924b8`
- Emplacement   : `frontend/src/features/crm-console/PersonTimelinePage.tsx:114` · `frontend/src/features/crm-console/ArbitragePage.tsx:132` · `backend/app/Http/Controllers/Api/Crm/PersonTimelineController.php:98-113` · `backend/app/Http/Controllers/Api/Crm/ArbitrageController.php:59-70`
- Constat       : les deux contrôleurs lisent `activities` par `DB::table(...)`, donc **sans cast Eloquent**, et renvoient la chaîne brute que Postgres a formatée **dans le fuseau de la session serveur** ; les deux écrans l'impriment telle quelle (`{entry.occurred_at ?? '—'}`), sans `new Date()`, sans `toLocaleString`, sans conversion.
- Constat, dit autrement : ces deux écrans sont les **seuls** des 37 à afficher une date sans la convertir. En production (session `Europe/Paris`) un événement de 14:00 s'affiche `2026-03-29 14:00:00+02` : le bon cadran **pour un lecteur parisien**, mais dans un format brut qu'aucun autre écran n'emploie, et **indifférent au fuseau du lecteur**. Dans l'atelier (session `Etc/UTC`) le même événement s'affiche `2026-03-29 14:00:00+00`, qui **n'est pas** 14:00 à Paris.
- Preuve        : `04_PREUVES/agent-29/bascules-AVEC-db-timezone.txt` et `bascules-SANS-db-timezone.txt`, colonne « ce que la fiche 360 affiche », six lignes chacune — la **même** donnée s'affiche `+02` ou `+00` selon la seule configuration du serveur ; `04_PREUVES/agent-29/affichage-brut-timeline.txt` : une même valeur `timestamptz` se rend `2026-03-29 12:00:00+00` en session UTC et `2026-03-29 14:00:00+02` en session Paris ; `grep` des champs de date affichés sans conversion sur les 37 écrans → exactement ces deux lignes.
- Témoin négatif: le même `grep` **ne** signale **pas** `AuditLogsPage.tsx:173`, `RgpdRequestsPage.tsx:189` ni `UsersPage.tsx:166`, qui font `new Date(...).toLocaleString('fr-FR')` — le contrôle distingue donc bien les écrans qui convertissent de ceux qui n'ont rien. Et il rend 2 lignes, pas 0.
- Impact        : la fiche 360° est **l'écran cible du critère 16 du §29** et l'écran de référence de la conformité (c'est là qu'on lit quand un consentement a été donné). Un exploitant situé hors de France y lit l'heure de Paris sans le savoir. Le volet « affichée dans le fuseau de l'utilisateur » du critère 16 n'est donc **pas** satisfait, même en production où le stockage l'est. Sur l'écran « À arbitrer », c'est sur cette date qu'on décide de fusionner ou non deux fiches.
- Reproduction  : `GET /api/crm/personnes/{personKey}/timeline`, observer `occurred_at` (chaîne `YYYY-MM-DD HH:MM:SS+OO`, pas de `T`, pas de `Z`) ; ouvrir `/console/personnes/{personKey}`, lire la colonne de gauche de la timeline.
- Correctif     : (1) côté serveur, sérialiser explicitement en zoulou : `Carbon::parse($row->occurred_at)->toJSON()` dans les deux contrôleurs (~20 min) ; (2) côté SPA, passer par le `formatDateTime()` de D29-004 (~20 min). Les deux sont nécessaires : la première seule rendrait la chaîne encore moins lisible.
- Statut        : ouvert

### [D29-008] La timeline 360° trie ses événements en comparant des chaînes : l'ordre s'inverse dans l'heure rejouée du 25 octobre
- Sévérité      : S2
- Domaine       : backend
- Référence     : main `e8924b8`
- Emplacement   : `backend/app/Http/Controllers/Api/Crm/PersonTimelineController.php:69-71`
- Constat       : `usort($activities, fn($a,$b) => strcmp((string)($b['occurred_at'] ?? ''), (string)($a['occurred_at'] ?? '')))` compare **les chaînes** renvoyées par Postgres. En production ces chaînes portent le décalage de Paris (`+02` l'été, `+01` l'hiver) ; le 25 octobre, l'heure 02:00–02:59 est rendue **deux fois**, une fois en `+02` et une fois en `+01`, et `strcmp` classe `+02` **après** `+01` dans l'ordre alphabétique — donc **avant** dans l'ordre décroissant, alors que `+02` est l'instant **antérieur**.
- Preuve        : `04_PREUVES/agent-29/tri-timeline-bascule.txt`. `2026-10-25 02:30:00+02` = `2026-10-25T00:30:00Z` ; `2026-10-25 02:30:00+01` = `2026-10-25T01:30:00Z`, donc postérieur. Ordre rendu par `strcmp` : `+02` puis `+01`. Ordre attendu (plus récent d'abord) : `+01` puis `+02`. **Inversé.**
- Témoin négatif: la même comparaison, jouée hors bascule sur `2026-10-25 03:30:00+01` contre `2026-10-25 02:30:00+01`, rend l'ordre **correct**. Le contrôle n'accuse donc pas `strcmp` en général : il isole exactement l'heure rejouée.
- Impact        : sur la fiche 360°, les événements survenus entre 02:00 et 03:00 le dernier dimanche d'octobre apparaissent **dans le désordre**, une fois par an. La fusion des deux univers (business + vivier) se fait par ce même tri : un « touchpoint » vivier peut donc passer devant un événement business plus récent. Faible fréquence, mais c'est la timeline qui sert de preuve d'antériorité.
- Reproduction  : insérer deux `activities` aux instants `2026-10-25T00:30:00Z` et `2026-10-25T01:30:00Z` avec `DB_TIMEZONE=Europe/Paris`, appeler `GET /api/crm/personnes/{key}/timeline`.
- Correctif     : trier sur l'instant, pas sur la chaîne : `usort(..., fn($a,$b) => strtotime($b['occurred_at']) <=> strtotime($a['occurred_at']))`, ou mieux, normaliser en zoulou au moment de construire le tableau (ce que fait déjà le correctif de D29-007, qui **résout aussi celui-ci**). ~15 min.
- Statut        : ouvert

### [D29-009] `signals:nightly-scan` ne tourne jamais le 29 mars et tourne deux fois le 25 octobre
- Sévérité      : S2
- Domaine       : backend
- Référence     : main `e8924b8`
- Emplacement   : `backend/routes/console.php:18`
- Constat       : `Schedule::command('signals:nightly-scan')->dailyAt('02:00')` est évalué en heure locale de Paris (`Illuminate/Console/Scheduling/Event.php:299`, `Date::now()` sans `->timezone()`), et l'heure locale 02:00 **n'existe pas** le 29 mars 2026 à Paris et **existe deux fois** le 25 octobre. C'est de plus la seule tâche `dailyAt` du fichier sans `withoutOverlapping()` ni `onOneServer()`.
- Preuve        : `04_PREUVES/agent-29/planificateur-bascules.txt` — énumération de chaque minute de temps universel sur une fenêtre de 36 h autour de chaque bascule, traduite en heure de Paris : `dailyAt('02:00')` atteint **0 fois** le 29/03 et **2 fois** le 25/10 ; toutes les autres heures planifiées (03:00 à 05:40) sont atteintes **exactement 1 fois** les deux jours.
- Témoin négatif: la même énumération rend **1** pour les onze autres heures planifiées les deux jours — la méthode ne rend pas 0 ou 2 partout, elle isole la seule heure concernée. Et elle rend bien **2** le jour où l'heure est rejouée, ce qui prouve qu'elle sait compter au-delà de 1.
- Impact        : le balayage nocturne des signaux saute une journée par an, et se dédouble une autre — sans verrou d'exclusion, deux exécutions concurrentes du même balayage. Les purges RGPD, elles, sont hors de danger (vérifié : `04:00`, `04:20`, `04:30`, et `monthlyOn(2, …)` pour les deux purges d'univers) — **la population purgée ne change pas**.
- Reproduction  : `docker exec axion-crm-api php artisan schedule:list` puis comparer les heures à l'énumération ci-dessus.
- Correctif     : déplacer à `dailyAt('01:30')` ou `dailyAt('03:15')` (hors de l'heure escamotée) **et** ajouter `->withoutOverlapping()->onOneServer()` — 5 min. Poser en même temps une non-régression qui refuse toute heure planifiée dans `[02:00, 03:00[`.
- Statut        : ouvert

### [D29-010] `DB_TIMEZONE` posée à vide met toute l'API à terre, et c'est la façon naturelle de la « désactiver »
- Sévérité      : S2
- Domaine       : backend
- Référence     : main `e8924b8`
- Emplacement   : `backend/config/database.php:102` (`'timezone' => env('DB_TIMEZONE')`)
- Constat       : `PostgresConnector::configureTimezone()` teste `isset($config['timezone'])`, or `isset('')` vaut **vrai** ; une variable posée mais vide fait émettre `SET TIME ZONE ''`, que Postgres refuse. **Toute requête échoue**, y compris `php artisan migrate`.
- Preuve        : `04_PREUVES/agent-29/db-timezone-vide.txt` — `docker exec -e DB_TIMEZONE= … php -r '… DB::selectOne("SHOW TimeZone")'` → `SQLSTATE[22023]: Invalid parameter value: 7 ERROR: invalid value for parameter "TimeZone": ""`. Rencontré pour de vrai pendant cet audit : le même `-e DB_TIMEZONE=` a fait échouer `php artisan migrate --force` sur `axion_crm_a29`.
- Témoin négatif: la **même commande sans** `-e DB_TIMEZONE=` se connecte et rend `Etc/UTC` (`04_PREUVES/agent-29/local-fuseaux.txt`). L'échec vient donc bien de la valeur vide, pas de la base ni du conteneur.
- Impact        : le commentaire de `config/database.php:78-84` explique longuement que la variable est « inerte par défaut » et qu'on la pose pour activer le correctif. Un exploitant qui voudrait **revenir en arrière** écrira naturellement `DB_TIMEZONE=` dans le `.env` du serveur plutôt que de supprimer la ligne — et mettra la production à terre au redémarrage suivant. Le mode d'emploi documenté ne dit nulle part qu'il faut **supprimer** la ligne.
- Reproduction  : `docker exec -e DB_TIMEZONE= axion-crm-api php artisan migrate --force`.
- Correctif     : `'timezone' => env('DB_TIMEZONE') ?: null` (une ligne), et une phrase dans le commentaire : « pour revenir en arrière, **supprimer** la ligne — ne pas la laisser vide ». ~10 min.
- Statut        : ouvert

### [D29-011] Trois colonnes d'horodatage sont tronquées à la seconde, sans que rien ne le signale
- Sévérité      : S3
- Domaine       : backend
- Référence     : main `e8924b8`
- Emplacement   : `backend/database/migrations/2026_07_06_000001_add_website_status_to_companies.php:21` · `2026_08_15_000004_email_suppressions.php:61-62`
- Constat       : sur les 203 colonnes `timestamptz` de la base, **200 sont en précision 6** et **3 en précision 0** — `companies.website_checked_at`, `email_suppressions.first_seen_at`, `email_suppressions.last_seen_at`. Ce sont exactement les trois seules créées par le constructeur de schéma Laravel (`timestampTz()`), qui pose `timestamp(0)` par défaut ; toutes les autres sont écrites en SQL brut dans les migrations.
- Preuve        : `04_PREUVES/agent-29/colonnes-dates-detail.txt` et la requête `datetime_precision <> 6` (6 lignes rendues : 3 colonnes `date` et ces 3 colonnes).
- Témoin négatif: la même requête rend bien les 3 colonnes de type `date` — elle n'est pas restreinte par erreur aux `timestamptz`, et elle voit donc l'ensemble des colonnes hors précision 6.
- Impact        : le discriminant des microsecondes est **précisément** ce sur quoi repose `horodatages:corriger` pour distinguer une ligne écrite par l'application d'une ligne écrite par Postgres (cf. `config/database.php:95-100`). Sur ces trois colonnes, ce discriminant n'existe pas : une reprise de données ne pourrait pas les traiter. `email_suppressions` porte les suppressions d'envoi — c'est une donnée de conformité.
- Reproduction  : `\d email_suppressions` sur la base.
- Correctif     : `ALTER TABLE ... ALTER COLUMN ... TYPE timestamptz` (sans précision) en migration — ~30 min, verrou court sur `companies` (table volumineuse, à jouer en fenêtre calme).
- Statut        : ouvert

---

## 4. Ce que je n'ai PAS pu vérifier, et pourquoi

1. **Le comportement réel de la production aux bascules.** Mes six cas ont été joués dans l'atelier,
   dans les **deux** configurations (`DB_TIMEZONE` absente et posée). C'est la meilleure
   approximation possible, mais **ce n'est pas la production** : le rappel du dossier commun (§5 bis)
   vaut ici pleinement — l'atelier ne reproduit pas la production, et A05-008 l'a déjà établi pour
   cette variable précise. Le verdict « en production, un événement de 14:00 est stocké au bon
   instant » repose donc sur la configuration mesurée par l'agent 5 (`DB_TIMEZONE=Europe/Paris` posée
   sur `axion-crm-api`), **pas sur une mesure que j'aurais faite en production** — ce qui est
   interdit en écriture.
2. **Le rendu visuel effectif des écrans en anglais.** Je n'ai pas ouvert la console avec
   `i18nextLng=en` dans un navigateur réel : l'atelier local sert l'API par `php -S`
   mono-processus (A-009 / A-010) et a expiré à plusieurs reprises pendant cet audit (trois
   commandes `php` ont dépassé 400 s). Le panachage FR/EN est **déduit du code** — 5 fichiers sur 84
   passent par `t()` — et non observé à l'écran. La déduction est solide, l'observation manque.
3. **Le fuseau des postes des utilisateurs réels.** L'absence d'option `timeZone` (D29-004) rend
   l'affichage dépendant du réglage de chaque poste. Je n'ai pas de mesure de ces réglages.
4. **Les colonnes `date` (3) sous l'angle du fuseau.** `analytics_daily_rollups.day` et consorts
   agrègent par journée ; savoir si la frontière de journée est calculée à Paris ou en UTC demande
   de lire les commandes d'agrégation, ce qui relève du périmètre analytique d'un autre agent. Je
   signale la question sans la trancher.
5. **Les tâches planifiées à la minute et aux quarts d'heure** (`everyMinute`,
   `everyFifteenMinutes`, `everyFiveMinutes`, `everyTwoHours`, `everyThreeHours`, `hourly`) aux
   bascules : elles ne portent pas d'heure fixe, donc le raisonnement du §2.5 ne s'y applique pas
   directement. Je n'ai pas énuméré leur comportement — a priori sans risque (elles tournent en
   continu), mais ce n'est pas mesuré.
6. **La complétude de mes deux passes.** Une chaîne visible peut échapper aux deux : par exemple un
   texte construit par une fonction, ou un libellé passé en propriété dont le nom n'est pas dans ma
   liste de 20. Mes chiffres sont donc des **planchers**, jamais des plafonds. Je les donne comme
   tels.
