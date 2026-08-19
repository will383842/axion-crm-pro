# AGENT 46 — Analyse statique et format

> Référence mesurée : `main = 8db8229` au moment de l'écriture (`git log --oneline -1`).
> **`git diff --stat c0c453d..HEAD -- backend/ frontend/ workers/ .github/` est VIDE** :
> aucun fichier de mon périmètre n'a bougé depuis la référence commune `c0c453d`.
> Les commits intermédiaires ne touchent que `_AUDIT/`. Toutes les mesures ci-dessous
> valent donc pour `c0c453d` comme pour `8db8229`.
>
> Preuves brutes : `_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/agent-46/`.
>
> **Deux affirmations du prompt d'audit étaient déjà réfutées** (`reportUnmatchedIgnoredErrors`
> vaut `true`, baseline de 1 321 lignes, `[OK] No errors`). Je les ai **re-jouées** pour ne pas
> partir d'un document (`phpstan-reference.txt` : `[OK] No errors`, exit 0, PHP 8.3.32) puis
> je suis parti de là. Elles ne sont pas re-rapportées comme constats.

---

## 1. Tableau de grille

| # | Objet | Question de grille | Mesure jouée | Résultat | Verdict |
|---|---|---|---|---|---|
| 1 | `backend/phpstan.neon` | niveau, chemins, exclusions | `head -29 phpstan.neon` | niveau **8**, chemins `app config database routes`, exclusions `vendor storage bootstrap/cache` | conforme |
| 2 | `backend/phpstan.neon` | `reportUnmatchedIgnoredErrors` | lecture ligne 27 | **`true`** (déjà réfuté au prompt) | conforme |
| 3 | `backend/phpstan.neon` | `ignoreErrors` hors baseline | lecture ligne 28-29 | **1 seule règle globale** : `identifier: missingType.generics` | à noter (§4, H46-011) |
| 4 | `backend/phpstan-baseline.neon` | taille réelle | `wc -l` | **1 321 lignes** (déjà réfuté au prompt) | conforme |
| 5 | baseline | nombre d'entrées / d'erreurs | comptage `path:` + somme des `count:` | **211 entrées / 248 erreurs**, cohérent avec l'en-tête | conforme |
| 6 | baseline | composition par **nature** | script `awk`+`python` → `baseline-composition.txt` | **23 identifiants**, ventilés en 7 familles (§2) | mesuré |
| 7 | baseline | composition par **module** | idem | `app/Services` 85, `app/Http` 59, `app/Console` 27, `app/Models` 17, `database` 6, `app/Providers` 3… (§2) | mesuré |
| 8 | baseline | **lesquelles cachent un vrai défaut** | relecture du code source de chaque entrée « dangereuse » | **20 nommées** (§3) ; 4 constats ouverts | mesuré |
| 9 | PHPStan sur le dépôt | l'outil rend-il vert ? | `docker exec axion-crm-api php vendor/bin/phpstan analyse` | **`[OK] No errors`**, exit 0 | conforme |
| 10 | témoin négatif PHPStan | l'outil sait-il trouver ? | niveau 8 sur un fichier jetable fautif, dans un conteneur `php:8.3-cli` **isolé**, avec le `phpstan.phar` du dépôt | **A.** sans baseline → `Found 1 error`, `argument.type`, exit **1**. **B.** `--generate-baseline` → entrée produite. **C.** même code, même niveau → **`[OK] No errors`, exit 0** | **témoin obtenu — et il montre le mécanisme entier** |
| 11 | `PhpstanBaselineNeGrossitPasTest` | la garde existe-t-elle et tourne-t-elle en CI ? | `phpunit-ci.xml` (`<directory>tests/Unit</directory>`) + `ci.yml:391` « Pest (BLOQUANT) » | **oui, bloquante, sans `continue-on-error`** | conforme |
| 12 | idem | **fait-elle rougir un dépassement de plafond ?** | ajout d'une entrée factice → `pest` | **ROUGE** : « 212 entrées pour un plafond de 211 » + en-tête 212/249 ≠ 211/248. **2 tests sur 5 échouent** | **garde vue rougir** |
| 13 | idem | **fait-elle rougir une entrée sur le socle CRM à compte constant ?** | sur copie jetable `/tmp/garde` : un `path:` basculé vers `app/Models/Company.php`, **compte inchangé 211/248** | **ROUGE** sur la seule assertion de chemin (`:212`). Les plafonds et l'en-tête, eux, restaient verts | **garde vue rougir sur le bon objet** |
| 14 | idem | mesure-t-elle le bon objet ? (piège 19) | lecture du test | **Non entièrement** : elle garde le **fichier**, pas le **code**. Une erreur PHPStan neuve non baselinée la laisse verte — c'est `composer analyse` (étape séparée, bloquante) qui la voit. La chaîne tient à deux étapes, pas une | conforme, avec réserve (§4, H46-010) |
| 15 | idem | restauration après casse | `Get-FileHash` avant/après + `git status --porcelain backend` | md5 **identique** (`5D96393C…C221`), aucun fichier suivi modifié | **restauré, prouvé** |
| 16 | `backend/pint.json` | preset et règles | lecture | preset **`laravel`** + 6 surcharges (`concat_space`, `method_argument_space`, `ordered_imports`, `no_unused_imports`, `single_quote`, `trailing_comma_in_multiline`) | mesuré |
| 17 | Pint — copie de travail | combien d'échecs ? | `pint --test` dans le conteneur (bind mount du poste) | **479 fichiers, 385 en échec** | mesuré |
| 18 | Pint — **côté dépôt (LF)** | combien d'échecs réels ? | `git archive HEAD backend` → conteneur → `pint --test` | **479 fichiers, 174 en échec, 0 `line_ending`** | **mesuré — c'est le nombre réel** |
| 19 | artefact CRLF | quelle part est un artefact ? | 385 − 174 | **211 fichiers ne rougissent que par CRLF.** Comptage indépendant : **210 fichiers `.php` en CRLF** dans la copie de travail, **0** dans `git archive` | **artefact confirmé, quantifié** |
| 20 | le chiffre « 14 fichiers » du prompt | vérifiable ? | mes deux runs | **contredit** : 385 (copie de travail) ou 174 (dépôt). Jamais 14. La mesure du 19/08 n'est pas reproductible ici | réfuté |
| 21 | le chiffre « 276 fichiers » de `ci.yml:20` | vérifiable ? | run LF | **contredit : 174** aujourd'hui. Écart de 102 | ouvert (H46-006) |
| 22 | Pint en CI | portée et caractère bloquant | `ci.yml:282-305` | **BLOQUANT**, mais uniquement sur les fichiers du diff, via le pathspec `'backend/**/*.php'` | conforme, avec réserve (H46-007) |
| 23 | Pint — violations réelles | quelles règles ? | run LF, `COLUMNS=400` | `binary_operator_spaces` 112, `blank_line_before_statement` 79, `unary_operator_spaces` 60, `not_operator_with_successor_space` 60, `braces_position` 45, … **`no_unused_imports` 7** | mesuré |
| 24 | `frontend/eslint.config.mjs` | règles clés | lecture | `recommendedTypeChecked`, `no-explicit-any: error`, `consistent-type-imports`, hooks, a11y ; type-checking désactivé sur `*.{js,mjs,cjs}` et `playwright.config.ts` | conforme |
| 25 | `frontend/eslint-suppressions.json` | combien, sur quoi ? | script python | **73 suppressions / 28 fichiers / 12 règles** (§3 bis ci-dessous et H46-008/009) | mesuré |
| 26 | idem | depuis quand ? | `git log --follow` | **un seul commit** : `b84100f`, **2026-08-13** (« CI réellement bloquante ») | mesuré |
| 27 | idem | la garde est-elle réellement active ? | fichier renommé → `pnpm lint` → restauré | **73 erreurs réapparaissent**, exit 1 | **témoin négatif obtenu** |
| 28 | idem | lesquelles masquent un vrai défaut ? | lecture des 61 sites en `src/` | **14 `no-floating-promises` + 5 `no-unsafe-return` + 10 `no-unsafe-assignment`** — dont RGPD, utilisateurs, réglages, `lib/api.ts` (H46-008 / H46-009) | mesuré |
| 29 | `pnpm lint` (frontend) | état | joué | **0 erreur** (avec suppressions), exit 0 | conforme |
| 30 | `pnpm lint` (workers) | état | joué | **0 erreur**, exit 0 ; **aucun fichier de suppressions** | conforme |
| 31 | `workers/eslint.config.mjs` | mêmes règles que le frontend ? | lecture | **NON** : `tseslint.configs.recommended` (non typé). Les règles `no-unsafe-*` **n'existent pas** dans ce paquet | ouvert (H46-012) |
| 32 | types `any` — `frontend/src` | combien ? | `grep -rnoE '\bany\b'` | **1 occurrence, et ce n'est pas un type** : `any?: AudienceCondition[]` (nom de champ du DSL de règles d'audience). **0 type `any`** | conforme |
| 33 | types `any` — `workers/src` | combien ? | idem | **0 occurrence** | conforme |
| 34 | témoin négatif `any` | le grep sait-il trouver ? | même grep sur `frontend/tests` | **trouve** `delete (window as any).Echo` (`tests/lib/echo.test.ts:31`) | **témoin obtenu** |
| 35 | `any` sur données personnelles / réponse d'API | y en a-t-il ? | croisement grep + suppressions ESLint | **aucun `any` explicite** ; mais **`any` implicite** via `axios` non générique sur les écrans RGPD / utilisateurs / réglages (H46-008) | ouvert (H46-008) |
| 36 | `tsc --noEmit` (frontend) | état | `pnpm exec tsc --noEmit` | **0 erreur**, exit 0 | conforme |
| 37 | `tsc --noEmit` (workers) | état | idem | **0 erreur**, exit 0 | conforme |
| 38 | typecheck en CI | bloquant ? | `ci.yml:453` et `ci.yml:512` | oui, les deux, sans `continue-on-error` | conforme |
| 39 | `continue-on-error` dans `ci.yml` | y en a-t-il ? | `grep -n` | **aucune occurrence active** (2 mentions, toutes deux dans le commentaire historique) | conforme |
| 40 | `env()` hors `config/` | quel risque réel ? | `entrypoint-prod.sh:44` + `php -r` dans le conteneur | l'entrypoint **prod fait bien `config:cache` au démarrage** ; l'atténuation est `variables_order=EGPCS` + injection docker, non gardée (§4, H46-002) | ouvert |

---

## 2. Composition réelle de la baseline PHPStan

**211 entrées, 248 erreurs, 1 321 lignes, 23 identifiants.**
Fichier complet : `04_PREUVES/agent-46/baseline-composition.txt`.

### 2.1 Par nature — sept familles

| Famille | Identifiants | Entrées | Erreurs | Ce que ça vaut vraiment |
|---|---|---:|---:|---|
| **A — Annotation absente** | `missingType.iterableValue` 41, `missingType.return` 14, `missingType.parameter` 6 | **61** | **61** | Cosmétique. Aucun risque d'exécution. **29 %** de la baseline. |
| **B — Propriété/méthode non résolue** | `property.notFound` 53, `method.notFound` 2 | **55** | **57** | **Ambigu, et c'est le piège.** Sur les 53 `property.notFound` : **40** visent `Illuminate\…\Eloquent\Model::$colonne` (type concret perdu — **39 dans les trois exports CSV**, 1 dans `AudienceBuilderService`), **6** visent `object::$colonne` (lignes SQL brutes de `EmailFinderService`), **7 seulement** nomment un modèle réel. Là où le type est perdu, une colonne renommée ne fait plus rien rougir. |
| **C — `env()` hors `config/`** | `larastan.noEnvCallsOutsideOfConfig` | **31** | **45** | **Bloc cohérent** : c'est *toute* la couche de lecture des secrets et des drapeaux (clés LLM, proxies, SMTP, INSEE, HMAC worker, secret de chaîne d'audit, `MOCK_MODE`, limites de débit). 31 fichiers. |
| **D — Conversion de type non gardée** | `argument.type` 32, `return.type` 5 | **37** | **52** | `string\|false → string` (~13), `string\|null → string` (~10), `resource\|false → resource` (**14 erreurs**, uniquement les 3 exports CSV). Chacune est un `TypeError` PHP 8 potentiel. |
| **E — Garde morte / code mort** | `nullCoalesce.offset` 7, `nullsafe.neverNull` 2 (8 err.), `identical.alwaysFalse` 3, `identical.alwaysTrue`, `notIdentical.alwaysTrue`, `booleanOr.alwaysTrue`, `empty.offset`, `deadCode.unreachable`, `foreach.nonIterable`, `arrayValues.list` | **19** | **25** | **La famille la plus intéressante** : chacune dit « ce `if` ne sert à rien » ou « ce code n'est jamais atteint ». |
| **F — Appel sur `null`** | `method.nonObject` 2, `property.nonObject` 1 | **3** | **3** | Trois `Fatal error` en attente d'une course. |
| **G — Style / performance** | `classConstant.phpDocType` 3, `method.unused` 1, `larastan.noUnnecessaryCollectionCall` 1 | **5** | **5** | Négligeable. |
| **Total** | 23 | **211** | **248** | |

**Lecture d'ensemble.** 66 entrées sur 211 (**31 %**) sont purement cosmétiques (A + G).
Les 145 autres décrivent un comportement : **la baseline n'est pas « de la dette de typage »,
c'est un inventaire de 145 endroits où le programme peut se comporter autrement qu'écrit.**

### 2.2 Par module

| Entrées | Erreurs | Module |
|---:|---:|---|
| 56 | 68 | `app/Http/Controllers` (dont `CompaniesController` 22, `MediaController` 21, `JournalistsController` 16) |
| 27 | 30 | `app/Console/Commands` |
| 25 | 25 | `app/Services/LLM` |
| 11 | 11 | `app/Services/Email` |
| 9 | 11 | `app/Services/Audiences` |
| 6 | 6 | `app/Services/Classification` |
| 6 | 6 | `app/Models/ScrapingCampaign.php` |
| 5 | 11 | `database/seeders` (dont `OwnerUserSeeder` 4 entrées / 10 erreurs) |
| 4 | 6 | `app/Services/Smtp` |
| 4 | 5 | `app/Services/Waterfall` |
| 4 | 5 | `app/Services/Dedup` |
| 4 | 4 | `app/Policies/BasePolicy.php` |
| … | … | 27 autres modules à ≤ 3 entrées |

**Agrégat racine** : `app/Services` 85/97 · `app/Http` 59/71 · `app/Console` 27/30 ·
`app/Models` 17/17 · `database` 6/12 · `app/Events` 5/5 · `app/Policies` 4/4 ·
`app/Providers` 3/7 · `app/Jobs` 3/3 · `app/Rules` 1/1 · `config` 1/1.

**Ce que la répartition dit.** L'invariant « aucune entrée ne vise le socle CRM »
(`app/Crm/`, `Contact`, `Candidate`, `Company`, `Activity`, `Tag`) est **tenu** — je l'ai
vérifié en le faisant rougir (§ grille ligne 13). Mais **les contrôleurs qui exportent ce socle
vers l'extérieur (CSV) concentrent 59 des 211 entrées** : le socle est propre, la porte de sortie
ne l'est pas.

---

## 3. Les 20 messages ignorés les plus dangereux

Classés par ce qui casse, pas par identifiant. Chaque ligne a été **relue dans le code source**.

| # | Emplacement | Message baseliné | Ce qu'il cache réellement |
|---:|---|---|---|
| **1** | `app/Services/Audit/AuditHashChain.php:33` | `larastan.noEnvCallsOutsideOfConfig` | `env('AUDIT_HASH_CHAIN_SECRET', 'dev-only-secret-change-me')`. **Le secret de la chaîne d'inaltérabilité de la piste d'audit a un défaut public écrit en clair.** Aucune garde ne refuse le défaut. (Croise B16-001 — réfuté en prod par l'agent 40, **vrai en local** : `getenv()` rend `false` dans `axion-crm-api`.) |
| **2** | `app/Http/Controllers/Internal/ScraperResultController.php:37` | idem | `env('WORKER_INTERNAL_HMAC_SECRET', '')` puis `hash_hmac(…, $secret)` **sans vérifier que le secret est non vide** : secret vide ⇒ signature calculable par quiconque. Mesuré : la variable **n'existe pas** dans le conteneur local. (Croise le constat de `routes.md`.) |
| **3** | `app/Services/Auth/MagicLinkService.php:44` | idem | `if (env('MOCK_MODE', true))` — **défaut `true`**. Branche prise ⇒ le lien de connexion à usage unique est **écrit en clair dans le journal applicatif** et **aucun courriel n'est envoyé**. Le défaut est celui de la branche non sécurisée. |
| **4** | `app/Http/Controllers/Api/Auth/PasswordResetController.php:46` | idem | Même motif : le jeton de réinitialisation part **dans le journal**, la réponse HTTP répond quand même `{'sent': true}`. Fonctionnalité qui ment ET jeton divulgué. |
| **5** | `app/Providers/MockServicesProvider.php:56` | idem (×2) | `$master = (bool) env('MOCK_MODE', true)` — **l'interrupteur maître de 17 contrats** (LLM, proxies, captcha, SMTP, INSEE, BODACC, BAN, France Travail, 5 scrapers…). Défaut = **tout en simulacre**. |
| **6** | `app/Services/Waterfall/WaterfallOrchestrator.php:406` | idem (×2) | `if (env('MOCK_MODE', true) \|\| env('MOCK_SCRAPERS', true)) return;` — l'étape 4 (dépêche des scrapes Node) **s'arrête silencieusement**. |
| **7** | `app/Providers/RouteServiceProvider.php:20,35,40` | idem (×3) | Les **trois limiteurs de débit** (`RATE_LIMIT_PER_MINUTE`, `SCRAPER_LAUNCH_PER_MINUTE`, `SCRAPER_LIST_PER_MINUTE`) sont lus par `env()` **à chaque bootstrap**, avec repli silencieux. |
| **8** | `app/Services/Http/SsrfGuard.php:41` **vs** `app/Console/Commands/PentestSelfCheck.php:71` | idem (×2) | Même variable `SSRF_GUARD_DENY_PRIVATE`, **défauts divergents** : `true` dans la garde, **`false`** dans l'auto-contrôle censé la vérifier. Piège 15 réalisé : **l'auto-contrôle de pentest peut rapporter l'inverse de ce que fait la garde.** |
| **9** | `app/Services/Rgpd/GdprPortabilityService.php:31` | `argument.type` (`string\|false` → `string`) | `Crypt::encryptString(json_encode($data, …))`. Un seul octet UTF-8 invalide dans un contact ⇒ `json_encode` rend `false` ⇒ **`TypeError` PHP 8** ⇒ **500 sur la portabilité RGPD art. 20**. Chemin de conformité. |
| **10** | `app/Http/Controllers/Api/ScrapingCampaignsController.php:413` | `notIdentical.alwaysTrue` (`mixed !== null`) | PHPStan déclare mort le chemin « pas de workspace » de `workspaceIdOrNull()`. Or ligne 65 : `if ($workspaceId !== null) { $query->where('workspace_id', …); }` — **si la résolution échoue, AUCUN filtre de locataire n'est appliqué** et la liste rend les campagnes de tous les workspaces. Défaut ouvert-par-défaut. Méthode **dupliquée à l'identique** dans `ScraperRunsController:184`. |
| **11** | `app/Rules/NotPwnedPassword.php` (via `HibpChecker`) | `method.nonObject` : `Cannot call method getBreachCount() on …\HibpChecker\|null` | Chemin de politique de mot de passe. **Faux positif à l'exécution** (le constructeur fait `??= app(…)`), mais l'entrée gèle un signal sur la **seule** règle de sécurité de mot de passe : si quelqu'un déplace l'initialisation, plus rien ne rougit. |
| **12** | `app/Http/Controllers/Api/CompaniesController.php:414` | `method.nonObject` : `load()` sur `Company\|null` | `enrich()` : `$company->fresh()->load('contacts')`. Le waterfall peut fusionner/supprimer la fiche (dédoublonnage) pendant l'enrichissement ⇒ **`fresh()` rend `null` ⇒ 500** sur une action de la console. |
| **13** | `app/Console/Commands/AudiencesFullRefreshCommand.php:44` | `property.nonObject` sur `EmailAudience\|null` | Même motif dans la commande de rafraîchissement d'audiences : `$audience->fresh()->member_count` ⇒ **la commande plante** si l'audience disparaît en cours de boucle. |
| **14** | `app/Services/Auth/AuthService.php:70` | `return.type` | `attemptLogin()` est **déclarée** rendre `array{user: User, …}` mais rend `$user->fresh()` c.-à-d. `User\|null`. **Le type de retour du service d'authentification ment** : tout appelant qui déréférence `['user']` sans garde casse. |
| **15** | `app/Services/LLM/LLMRouterService.php:72,75` | `method.notFound` ×2 : `object::complete()`, `object::lastUsage()` | `ProviderFactory::make()` rend `object` **non typé** : **aucun contrat n'existe entre le routeur LLM et ses 5 fournisseurs**. Ajouter un fournisseur sans `complete()` ⇒ `Fatal error` à l'exécution, invisible au niveau 8. |
| **16** | `app/Console/Commands/ProspectionCollect.php:68-69` | `identical.alwaysTrue` + `deadCode.unreachable` | PHPStan conclut que `$flush()` **retourne toujours tôt**, donc que **le `DB::table('companies')->upsert(...)` par lots de 500 est du code mort**. Conséquence réelle : **tout le corps du lot d'insertion des sociétés n'est PAS analysé** au niveau 8. C'est un trou d'analyse, pas seulement un message. |
| **17** | `app/Services/Classification/AutoTagApplier.php:102` | `foreach.nonIterable` (`list<string>\|false`) + `identical.alwaysFalse` | Moteur de règles d'étiquetage : `preg_split()` peut rendre `false` (⇒ `foreach` sur `false`), et une garde `=== null` **ne se déclenche jamais**. Le chemin qui applique automatiquement des étiquettes à des personnes. |
| **18** | `CompaniesController` (14) + `MediaController` (16) + `JournalistsController` (9) — 39 entrées `property.notFound` | `Access to an undefined property Illuminate\…\Model::$colonne` | Dans les fermetures `chunk()` des **exports CSV**, le type concret est perdu. **Une colonne renommée par une migration produit une colonne CSV silencieusement vide** — Eloquent rend `null`, personne ne rougit. Exactement ce que PHPStan disait, gelé. |
| **19** | `app/Services/Email/EmailFinderService.php` (6 entrées) | `Access to an undefined property object::$status / $score / $is_disposable / $is_role / $is_catchall / $mx_host` | Lignes SQL brutes (`stdClass`). **Le verdict de validité d'une adresse devient `null` silencieusement** si une colonne bouge — donc une adresse invalide peut être traitée comme envoyable (délivrabilité, réputation, RGPD). |
| **20** | `app/Services/Dedup/DeduplicationService.php` (`md5(string\|false)`, `preg_replace(…, string\|null)`) et `app/Console/Commands/RetentionPurge.php` (`Connection::selectOne(string\|null)`, `preg_replace(…, null)`) | `argument.type` ×5 | **Clé de dédoublonnage** et **purge de rétention RGPD** : deux chemins où un `null` non géré produit soit une fusion erronée de fiches, soit un `TypeError` au milieu d'une purge. |

**Mention hors classement** — les 14 erreurs `fputcsv/fwrite/fclose` sur `resource|false`
(`Companies`/`Journalists`/`Media`) : pratiquement inoffensives (`fopen('php://output')`
n'échoue pas), mais elles représentent **27 % des erreurs `argument.type`** et gonflent
artificiellement la perception de la dette.

**Ce qui n'est PAS dangereux, et qu'il ne faut pas traiter** : les 61 entrées de la famille A,
les 14 `missingType.return` sur les relations Eloquent, les 3 `classConstant.phpDocType`, et
les 7 `nullsafe.neverNull` de `OwnerUserSeeder` (`$this->command?->warn`, purement redondants).
**Les trois colonnes JSONB volontairement hors `@property`** (`Tag::$rules`,
`EmailAudience::$criteria`, `Media::$socials`) sont documentées dans les modèles et dans
l'en-tête de la baseline : **ne pas les « corriger »**, ça changerait le comportement.

---

## 3 bis. Les suppressions ESLint du frontend, et les types `any`

### Volume et nature — 73 suppressions, 28 fichiers, 12 règles

Toutes posées par **un seul commit, `b84100f` du 2026-08-13** (« CI réellement bloquante ») :
le fichier n'a jamais été retouché depuis. Contrairement à la baseline PHPStan, il ne porte
**ni en-tête, ni date, ni plafond, ni test de garde**.

| Règle supprimée | Occ. | Ce que ça vaut |
|---|---:|---|
| `no-unnecessary-type-assertion` | **21** | Cosmétique — 12 sur le seul `QualityScoreCard.tsx`, 4 sur `EnrichmentTimeline.tsx`. Assertions devenues inutiles. |
| `no-floating-promises` | **14** | **Réel** — cf. H46-009. **5 sont des `qc.invalidateQueries()` placés juste après un `toast.success`** (RGPD ×2, Utilisateurs, Réglages, Fiche société), 12 sur 14 sont en `src/`. |
| `no-unsafe-assignment` | **10** | **Réel** — dont 4 sur `FranceCoverageMap.tsx` (données géographiques chargées par `fetch`), 1 sur `lib/api.ts:3`, 1 sur `lib/sentry.ts:13`, 1 sur `main.tsx:45`. |
| `no-misused-promises` | **7** | Gestionnaires `onSubmit={async …}` — 5 sur les écrans d'authentification (`Login`, `MagicLink`, `PasswordReset`, `TwoFactor`) et 2 sur `AudienceBuilder`/`TagsManager`. |
| `no-redundant-type-constituents` | **6** | Cosmétique — toutes sur `ScraperRunsPage.tsx`. |
| `no-unsafe-return` | **5** | **Réel** — cf. H46-008 : RGPD ×2, Utilisateurs, Réglages, Fiche société. |
| `no-unsafe-member-access` | **5** | 1 en production (`lib/api.ts:30`, l'intercepteur 401), 4 dans les tests. |
| `no-unsafe-argument` | 1 | `FranceCoverageMap.tsx:166` — un `any` passé à `fetch()`. |
| `no-base-to-string` | 1 | `SettingsPage.tsx:146` — `fd.get('name') ?? ''` peut rendre `[object Object]`. |
| `prefer-promise-reject-errors` | 1 | `lib/api.ts:33` — l'intercepteur rejette avec une valeur non-`Error`. |
| `unbound-method` | 1 | test. |
| `no-explicit-any` | **1** | `tests/lib/echo.test.ts:31` — **le seul `any` explicite de tout le dépôt frontend**. |

**Répartition production / tests** : **61 en `src/`** (23 fichiers — les seules qui comptent), **12 dans `tests/`** (5 fichiers).

**Croisement à signaler** : `lib/api.ts:30` supprime `no-unsafe-member-access` sur
`error?.response?.status === 401`, c'est-à-dire sur **le seul mécanisme qui renvoie
l'utilisateur vers `/login`**. Le constat **A-001 déjà ouvert** établit que ces routes
répondent **500 et non 401** : l'intercepteur ne se déclenche donc jamais. Je ne rouvre pas
A-001 ; je note que la suppression ESLint porte exactement sur la ligne concernée, et
qu'aucun typage ne pouvait le révéler.

### Types `any` — le compte est zéro, et le témoin le prouve

| Portée | `\bany\b` trouvés | Vrais types `any` |
|---|---:|---:|
| `frontend/src` (`.ts`/`.tsx`) | 1 | **0** — l'unique occurrence est `any?: AudienceCondition[]` (`AudiencesListPage.tsx:38`), **un nom de champ** du DSL de règles d'audience (`all` / `any`), pas un type |
| `workers/src` (`.ts`) | 0 | **0** |
| `frontend/tests` | 1 | 1 — `delete (window as any).Echo` (`echo.test.ts:31`), supprimé dans `eslint-suppressions.json` |
| `workers/tests` | 0 | 0 |

**Témoin négatif** : le même `grep -rnoE "\bany\b"` trouve bien le `as any` des tests, il
n'est donc pas aveugle. **Aucun `any` explicite ne porte sur des données personnelles ni sur
une réponse d'API** — `@typescript-eslint/no-explicit-any` est à `error` dans les deux paquets
et la règle tient. **Le danger est ailleurs, et il est réel** : les `any` **implicites**
produits par `axios` sans paramètre générique, que seules les règles `no-unsafe-*` voient — et
qui sont supprimées en `src/` (H46-008) ou absentes du paquet `workers` (H46-012).

---

## 4. Constats

### [H46-001] La baseline PHPStan gèle 145 messages de comportement, dont 20 sur des chemins de sécurité, de conformité ou d'export

- Sévérité      : S2 défaut
- Domaine       : backend
- Référence     : main 8db8229 (périmètre identique à c0c453d)
- Emplacement   : `backend/phpstan-baseline.neon` (211 entrées, 248 erreurs)
- Constat       : sur les 211 entrées, **66 sont cosmétiques** et **145 décrivent un comportement possible du programme** — dont 45 lectures d'`env()` hors `config/`, 52 conversions de type non gardées, 25 gardes mortes ou code mort, et 3 appels sur `null`.
- Preuve        : `04_PREUVES/agent-46/baseline-composition.txt` (ventilation complète, 286 l.) ; `phpstan-reference.txt` (`[OK] No errors`, exit 0) prouve que **rien de tout cela ne rougit aujourd'hui**.
- Témoin négatif: **deux niveaux.** (a) La ventilation a été produite par un script lisant les 211 blocs, vérifié contre les compteurs indépendants `grep -c "path:"` = 211 et `Σcount:` = 248, eux-mêmes identiques à ce qu'annonce l'en-tête du fichier et à ce que lit la garde Pest. (b) **Surtout** : `temoin-negatif-phpstan-niveau8.txt` prouve le mécanisme de bout en bout sur un cas jetable, dans un conteneur `php:8.3-cli` isolé, avec le `phpstan.phar` du dépôt — **A.** un `strlen(?string)` au niveau 8 sans baseline rend `Found 1 error / argument.type`, exit **1** ; **B.** `--generate-baseline` en fait une entrée de quatre lignes ; **C.** le même code, au même niveau, avec cette baseline, rend **`[OK] No errors`, exit 0**. L'outil sait donc parfaitement trouver, et `argument.type` — l'identifiant démontré — est précisément la deuxième famille de la vraie baseline (32 entrées / 46 erreurs). Le `[OK] No errors` du dépôt ne dit pas « pas d'erreur », il dit « pas d'erreur hors des 248 ».
- Impact        : l'en-tête de la baseline présente le fichier comme « de la dette figée » et le test de garde ne mesure qu'un volume. Personne n'a de vue sur **ce que** contient le fichier : un lecteur pressé conclut « 248 erreurs de typage » là où il y a 20 chemins de sécurité gelés.
- Reproduction  : `cd backend && python3` sur `phpstan-baseline.neon`, ventilation par `identifier:` et par préfixe de `path:` (script complet reproduit dans `baseline-composition.txt`).
- Correctif     : ajouter au **haut de `phpstan-baseline.neon`** une ventilation par famille et un tableau des entrées « comportementales », et la faire vérifier par le test de garde existant (une famille supplémentaire = un plafond supplémentaire). ~3 h.
- Statut        : ouvert

---

### [H46-002] L'entrypoint de production exécute `config:cache`, alors que 31 fichiers lisent leurs secrets et leurs drapeaux par `env()` — 45 entrées gelées dans la baseline

- Sévérité      : S2 défaut
- Domaine       : sécurité
- Référence     : main 8db8229
- Emplacement   : `infra/docker/entrypoint-prod.sh:44` ; `Dockerfile.laravel:86-95` ; 31 fichiers listés dans `baseline-composition.txt` (identifiant `larastan.noEnvCallsOutsideOfConfig`)
- Constat       : `Dockerfile.laravel:86` interdit explicitement `config:cache` au build en écrivant « sous config mise en cache `env()` retourne NUL », puis `entrypoint-prod.sh:44` **fait `config:cache` au démarrage du conteneur** ; le code, lui, lit `MOCK_MODE`, `AUDIT_HASH_CHAIN_SECRET`, `WORKER_INTERNAL_HMAC_SECRET`, les trois limites de débit, `SSRF_GUARD_DENY_PRIVATE` et les clés des 5 fournisseurs LLM par `env()` **hors de `config/`**.
- Preuve        : `sed -n '1,60p' infra/docker/entrypoint-prod.sh` (boucle `for etape in config route view; do php artisan "${etape}:cache"`). Ce que la baseline gèle : 31 entrées / 45 erreurs, liste complète dans `baseline-composition.txt`.
- Témoin négatif: `docker exec axion-crm-api php -r '…'` mesure l'atténuation et sait dire non : elle rend `variables_order=EGPCS`, `$_ENV[MOCK_MODE]='true'` **et** `getenv('AUDIT_HASH_CHAIN_SECRET')=false`, `getenv('WORKER_INTERNAL_HMAC_SECRET')=false`. La sonde distingue donc bien une variable présente d'une variable absente — et elle montre que **deux secrets sont absents de l'atelier local**, donc que les défauts en dur (`'dev-only-secret-change-me'`, `''`) y sont réellement employés.
- Impact        : le bon fonctionnement de 31 fichiers repose sur une propriété **non gardée** de l'infrastructure — que chaque variable atteigne l'**environnement du processus PHP** et pas seulement un fichier `.env`. Un changement d'image PHP, de `variables_order`, ou un passage de `env_file:` à un `.env` monté suffit à basculer 31 fichiers sur leurs défauts, dont `MOCK_MODE=true` (17 contrats en simulacre) et les 3 limites de débit. Aucun test ne détecte ce basculement.
- Reproduction  : `docker exec axion-crm-api php -r 'echo ini_get("variables_order"), var_export($_ENV["MOCK_MODE"] ?? null, true);'` puis lire `infra/docker/entrypoint-prod.sh:41-49`.
- Correctif     : déplacer ces 45 lectures dans `config/*.php` (c'est exactement ce que dit la règle Larastan gelée) et injecter par `config('…')`. Coût ~1 j pour les 31 fichiers, mais il retire 45 erreurs de la baseline **et** rend `config:cache` inoffensif. Variante minimale (~2 h) : ne traiter que les 8 fichiers de secrets et de sécurité (n° 1, 2, 3, 4, 5, 7, 8 du §3).
- Statut        : ouvert

---

### [H46-003] `SSRF_GUARD_DENY_PRIVATE` a deux défauts contradictoires : `true` dans la garde, `false` dans l'auto-contrôle censé la vérifier

- Sévérité      : S2 défaut
- Domaine       : sécurité
- Référence     : main 8db8229
- Emplacement   : `backend/app/Services/Http/SsrfGuard.php:41` et `backend/app/Console/Commands/PentestSelfCheck.php:71`
- Constat       : `SsrfGuard` fait `(bool) env('SSRF_GUARD_DENY_PRIVATE', true)` et `PentestSelfCheck` fait `(bool) env('SSRF_GUARD_DENY_PRIVATE', false)` — la commande d'auto-contrôle de pentest lit la **même** variable avec le **défaut inverse**.
- Preuve        : `grep -n "env(" app/Services/Http/SsrfGuard.php app/Console/Commands/PentestSelfCheck.php` → les deux lignes ci-dessus. Les deux sont gelées dans la baseline (identifiant `larastan.noEnvCallsOutsideOfConfig`, 1 entrée chacune).
- Témoin négatif: le même `grep` sur les 7 autres fichiers de sécurité rend leurs `env()` respectifs (`AuditHashChain`, `MagicLinkService`, `PasswordResetController`, `ScraperResultController`, `RouteServiceProvider` ×3) — il n'est pas aveugle, il trouve bien tous les appels ; c'est bien une divergence isolée sur cette variable-là.
- Impact        : si la variable n'est pas posée, la garde **bloque** les IP privées tandis que l'auto-contrôle rapporte qu'elle ne les bloque **pas** — ou inversement le jour où l'un des deux défauts change. Un auto-contrôle de sécurité qui peut contredire l'objet qu'il contrôle ne vaut rien (piège 19 du dossier, réalisé sur une constante dupliquée, piège 15).
- Reproduction  : `docker exec axion-crm-api sh -c 'unset SSRF_GUARD_DENY_PRIVATE; php artisan pentest:self-check'` puis comparer avec le comportement réel de `SsrfGuard::denyPrivate()`.
- Correctif     : une seule source, `config/security.php`, lue par les deux. ~20 min.
- Statut        : ouvert

---

### [H46-004] Trois écrans de la console exportent en CSV à travers un type perdu : une colonne renommée produit une colonne vide, silencieusement

- Sévérité      : S2 défaut
- Domaine       : backend
- Référence     : main 8db8229
- Emplacement   : `backend/app/Http/Controllers/Api/CompaniesController.php` (14 entrées), `MediaController.php` (16), `JournalistsController.php` (9)
- Constat       : dans les fermetures `chunk()` des trois exports CSV, le modèle est vu comme `Illuminate\Database\Eloquent\Model` générique ; les 39 entrées `property.notFound` gelées disent précisément que `$c->city_name`, `$c->denomination`, `$c->email_generic`, `$c->contacts`… ne sont pas vérifiables.
- Preuve        : `baseline-composition.txt`, section détail, filtre `property.notFound` ; code lu à `CompaniesController.php:201-248` (`streamDownload` → `chunk` → `fputcsv`).
- Témoin négatif: la même famille `property.notFound` **sait nommer un modèle concret** quand le type n'est pas perdu — **7 entrées sur 53** nomment `App\Models\Media::$socials`, `App\Models\Tag::$rules`, `App\Models\EmailAudience::$criteria`, `ScrapingCampaignResource::$runs`… L'identifiant n'est donc pas systématiquement générique : les 39 entrées `Eloquent\Model::$…` de ces trois contrôleurs signalent bien une perte de type propre à leurs fermetures.
- Impact        : une migration qui renomme ou supprime une colonne ne fait rougir ni PHPStan (gelé) ni aucun test ; Eloquent rend `null` et le CSV livré au client porte une colonne vide. Perte silencieuse de données livrées, sur les trois exports de la console.
- Reproduction  : renommer `companies.email_generic` dans une migration jetable et relancer `phpstan analyse` → toujours `[OK] No errors` ; l'export continue de produire une colonne, vide.
- Correctif     : typer les fermetures (`/** @param \Illuminate\Support\Collection<int, \App\Models\Company> $lot */`) ou passer par `Company::query()->…->chunkById()` avec le générique Larastan. ~2 h pour les trois contrôleurs, et cela retire 39 entrées de la baseline.
- Statut        : ouvert

---

### [H46-005] `pint --test` échoue sur 174 fichiers du dépôt — les 211 échecs supplémentaires observés sur ce poste sont un artefact CRLF, pas un défaut du dépôt

- Sévérité      : S3 finition
- Domaine       : backend
- Référence     : main 8db8229
- Emplacement   : `backend/pint.json` ; 174 fichiers listés dans `04_PREUVES/agent-46/pint-test-checkout-LF-large.txt`
- Constat       : sur la copie de travail Windows, `pint --test` rend **479 fichiers, 385 en échec** ; sur le contenu **du dépôt** (`git archive HEAD backend`, donc LF), le même Pint rend **479 fichiers, 174 en échec et 0 `line_ending`**.
- Preuve        : `pint-test-container.txt` (`FAIL … 479 files, 385 style issues`) et `pint-test-checkout-LF.txt` (`FAIL … 479 files, 174 style issues`, `grep -c line_ending` = **0**). Comptage indépendant des fins de ligne : `210` fichiers `.php` en CRLF dans la copie de travail contre **0** dans `git archive` — soit exactement l'écart de 211 échecs, à un fichier près.
- Témoin négatif: le comptage CRLF **sait dire non** : la même sonde PHP rend `CRLF=0 LF=481` sur l'archive git et `CRLF=210 LF=203` sur la copie de travail. Elle distingue donc bien les deux états ; le `0` sur l'archive n'est pas un aveuglement de la sonde. (Une première tentative en `sh` avec `grep -qU $"\r"` avait rendu `CRLF=0` **des deux côtés** — piège 1 du dossier attrapé en flagrant délit ; la sonde a été refaite en PHP.)
- Impact        : les 174 échecs réels sont de la finition (`binary_operator_spaces` 112, `blank_line_before_statement` 79, `unary_operator_spaces` 60…), sauf **`no_unused_imports` sur 7 fichiers** — dont `app/Services/Auth/AuthService.php`, `app/Http/Controllers/Api/AudiencesController.php` et `app/Jobs/RefreshAudienceChunkJob.php` — qui signalent du code mort. **Le vrai impact est ailleurs** : sur ce poste, `pint --test` est inutilisable comme outil de décision (385 échecs dont 211 faux), donc personne ne s'en sert.
- Reproduction  : `git archive HEAD backend -o /tmp/b.tar` ; `docker cp` ; `tar -xf` ; `php vendor/bin/pint --test --config <copie>/pint.json <copie>`.
- Correctif     : renormaliser la copie de travail (`git add --renormalize .` après le `.gitattributes` déjà posé — c'est le correctif d'**A-003**, déjà ouvert) ; puis, séparément, `vendor/bin/pint` sur les 7 fichiers à imports morts (~15 min).
- Statut        : ouvert

---

### [H46-006] Le commentaire de `ci.yml` annonce 276 fichiers non formatés ; la mesure d'aujourd'hui en donne 174

- Sévérité      : S3 finition
- Domaine       : tests
- Référence     : main 8db8229
- Emplacement   : `.github/workflows/ci.yml:20`
- Constat       : le commentaire qui justifie de ne contrôler Pint que sur les fichiers modifiés écrit « le dépôt compte 276 fichiers non formatés » ; la mesure sur le contenu du dépôt en LF en rend **174**.
- Preuve        : `sed -n '20,21p' .github/workflows/ci.yml` vs `pint-test-checkout-LF.txt` (`479 files, 174 style issues`).
- Témoin négatif: la même commande sait rendre un autre nombre — jouée sur la copie de travail CRLF elle rend **385**. Elle n'est donc pas figée sur 174 ; ni 385 ni 174 ne valent 276.
- Impact        : le chiffre qui **justifie une exception dans la CI** n'est vérifiable par aucune commande. Ce n'est pas grave en soi ; c'est le motif exact qui a déjà fait dériver l'en-tête de la baseline PHPStan (339/443 annoncés pour 337/441 réels), et là aussi personne ne pouvait le voir.
- Reproduction  : cf. H46-005.
- Correctif     : soit retirer le nombre du commentaire, soit le faire vérifier — le motif existe déjà et fonctionne : `PhpstanBaselineNeGrossitPasTest` vérifie que l'en-tête de la baseline annonce les nombres réellement présents. ~30 min.
- Statut        : ouvert

---

### [H46-007] Le pathspec `'backend/**/*.php'` du contrôle Pint de la CI ne couvre pas les fichiers PHP situés directement sous `backend/`

- Sévérité      : S3 finition
- Domaine       : tests
- Référence     : main 8db8229
- Emplacement   : `.github/workflows/ci.yml:297`
- Constat       : `git diff --name-only --diff-filter=ACMR "$base"...HEAD -- 'backend/**/*.php'` emploie un pathspec git sans magie `:(glob)` ; le segment `**/` exige au moins un répertoire intermédiaire, donc un fichier modifié comme `backend/artisan` ou tout `.php` posé à la racine de `backend/` n'entre jamais dans la liste contrôlée.
- Preuve        : `sed -n '282,305p' .github/workflows/ci.yml` (l'étape complète). L'étape sort en `exit 0` avec « Aucun fichier PHP modifié — contrôle Pint sans objet » quand la liste est vide.
- Témoin négatif: **non produit** — je n'ai pas ouvert de PR de démonstration, cf. §5. Le constat repose sur la sémantique du pathspec git, pas sur une exécution.
- Impact        : marginal aujourd'hui (peu de `.php` à la racine de `backend/`), mais le gate déclaré BLOQUANT rend silencieusement `exit 0` sur une liste vide — la même branche qui rend vert quand rien n'est modifié rend vert quand le filtre rate.
- Reproduction  : `git diff --name-only HEAD~1 -- 'backend/**/*.php'` vs `git diff --name-only HEAD~1 -- ':(glob)backend/**/*.php'` sur un commit touchant un fichier racine.
- Correctif     : `-- 'backend/*.php' 'backend/**/*.php'` ou magie `:(glob)`. ~5 min.
- Statut        : ouvert

---

### [H46-008] Les écrans RGPD, Utilisateurs, Réglages et Société reçoivent des réponses d'API entièrement non typées — 5 `no-unsafe-return` gelés dans les suppressions ESLint

- Sévérité      : S2 défaut
- Domaine       : interface
- Référence     : main 8db8229
- Emplacement   : `frontend/src/features/rgpd/RgpdRequestsPage.tsx:82,95` · `features/users/UsersPage.tsx:69` · `features/settings/SettingsPage.tsx:107` · `features/companies/CompanyDetailPage.tsx:69` · `frontend/src/lib/api.ts:3,30,33`
- Constat       : ces mutations appellent `api.post(...)` **sans paramètre générique** ; `axios` rend alors `AxiosResponse<any>` et `.data` est un `any` qui traverse toute la mutation — la règle `@typescript-eslint/no-unsafe-return` le dit, et les 5 messages sont dans `eslint-suppressions.json`.
- Preuve        : `04_PREUVES/agent-46/eslint-sans-suppressions-complet.txt` — p. ex. `src\features\rgpd\RgpdRequestsPage.tsx 82:7 error Unsafe return of a value of type 'any'`. Code lu : `RgpdRequestsPage.tsx:80-82` (`api.post('/rgpd/requests', { type, subject_email })`) — la requête **d'effacement ou de portabilité d'une personne**, dont la réponse n'est pas typée. À comparer avec la ligne 77 juste au-dessus, qui, elle, est typée : `api.get<{ data: RgpdRequest[] }>(…)`. Le motif correct existe donc dans le même fichier.
- Témoin négatif: `frontend/eslint-suppressions.json` renommé → `pnpm lint` rend **73 erreurs, exit 1** ; restauré → **0 erreur, exit 0**. Les suppressions sont bien actives et l'outil sait bien voir ces 5 messages (`eslint-sans-suppressions.txt`).
- Impact        : aucune vérification à la compilation que la réponse du serveur a la forme attendue, sur les écrans qui manipulent des demandes RGPD nominatives et les comptes utilisateurs. Un changement de forme côté API passe inaperçu jusqu'au comportement observé en console.
- Reproduction  : `cd frontend && mv eslint-suppressions.json /tmp && pnpm lint` (73 erreurs) puis restaurer.
- Correctif     : ajouter le générique aux 5 appels (`api.post<{ data: RgpdRequest }>(…)`) et retirer les entrées correspondantes des suppressions. ~1 h.
- Statut        : ouvert

---

### [H46-009] 14 promesses non attendues sont gelées, dont 5 invalidations de cache placées juste après un message de succès

- Sévérité      : S2 défaut
- Domaine       : interface
- Référence     : main 8db8229
- Emplacement   : `frontend/eslint-suppressions.json` (règle `@typescript-eslint/no-floating-promises`, 14 occurrences dans 11 fichiers) — notamment `features/rgpd/RgpdRequestsPage.tsx:88,100`, `features/users/UsersPage.tsx:75`, `features/settings/SettingsPage.tsx:110`, `features/companies/CompanyDetailPage.tsx:72`, `features/auth/LoginPage.tsx`, `features/auth/TwoFactorPage.tsx`, `components/ui/GlobalSearch.tsx`, `lib/i18n.ts:7`
- Constat       : 12 des 14 occurrences sont en `src/` ; **5 d'entre elles** sont un `qc.invalidateQueries({...})` appelé sans `void` ni `await` dans un `onSuccess`, immédiatement après `toast.success(...)` — vérifiées ligne à ligne (`RgpdRequestsPage.tsx:88` et `:100`, `UsersPage.tsx:75`, `SettingsPage.tsx:110`, `CompanyDetailPage.tsx:72`). Les 7 autres sont des `navigate()` / `refetch()` d'authentification et de recherche globale, plus `lib/i18n.ts:7`.
- Preuve        : `eslint-sans-suppressions-complet.txt` (les 14 lignes `Promises must be awaited…`) ; code lu à `RgpdRequestsPage.tsx:83-91`.
- Témoin négatif: même témoin que H46-008 — sans le fichier de suppressions, ESLint rend bien ces 14 messages ; avec, il rend 0.
- Impact        : l'utilisateur voit « Requête traitée » **avant** que la liste ait été rafraîchie, et si l'invalidation échoue rien ne le lui dit : l'écran affiche l'ancien état avec un message de succès. Sur les demandes RGPD (effacement / portabilité), un écran qui dit « traité » en montrant « en attente » fait perdre l'opérateur — au minimum S2 par la règle des sévérités du dossier. `lib/i18n.ts:7` est du même ordre : l'initialisation des traductions n'est ni attendue ni rattrapée.
- Reproduction  : cf. H46-008.
- Correctif     : préfixer par `void` là où l'oubli est délibéré (12 cas) et `await` là où l'ordre compte (les 2 invalidations RGPD). ~45 min, dont la moitié de relecture.
- Statut        : ouvert

---

### [H46-010] La garde « la baseline ne grossit pas » garde le fichier, jamais le code : une erreur PHPStan neuve la laisse verte

- Sévérité      : S3 finition
- Domaine       : tests
- Référence     : main 8db8229
- Emplacement   : `backend/tests/Unit/PhpstanBaselineNeGrossitPasTest.php`
- Constat       : les 5 tests lisent `phpstan-baseline.neon` et `phpstan.neon` et n'exécutent **jamais** PHPStan ; ils vérifient un volume (211/248), la cohérence de l'en-tête, l'absence d'entrée sur le socle CRM et la présence de `reportUnmatchedIgnoredErrors: true`.
- Preuve        : lecture intégrale du test ; **et deux mises au rouge** : (a) plafond dépassé → `garde-baseline-ROUGE-plafond.txt`, « phpstan-baseline.neon contient 212 entrées pour un plafond de 211 » + en-tête 212/249, **2 tests sur 5 en échec** ; (b) à **compte constant** (211/248), un seul `path:` basculé vers `app/Models/Company.php` → `garde-baseline-ROUGE-socle-crm.txt`, **seule** l'assertion de chemin (`:212`) rougit, plafonds et en-tête restant verts.
- Témoin négatif: la preuve (b) **est** le témoin croisé : elle montre que le compteur ne suffit pas et que l'invariant de chemin attrape ce que le compteur laisse passer — exactement ce que l'en-tête du test annonce. Elle a été jouée sur une **copie jetable** (`/tmp/garde`, liens symboliques vers `app/`, `vendor/`…), donc sans toucher au produit.
- Impact        : aucun défaut de la garde en elle-même — mais le nom « la baseline ne grossit pas » laisse croire qu'elle empêche l'ajout d'erreurs. La protection contre le code neuf repose entièrement sur une **autre** étape de la CI (`composer analyse`, `ci.yml:278-280`). Si cette étape est un jour désarmée, le test Pest continue de passer au vert : la CI reste verte alors que la dette croît, dans le sens précis que le test prétend interdire.
- Reproduction  : dans une copie jetable, `sed -i "s|path: app/Console/Commands/AnomalyDetect.php|path: app/Models/Company.php|"` puis `php vendor/bin/pest --filter=socle tests/Unit/PhpstanBaselineNeGrossitPasTest.php`.
- Correctif     : ajouter au test un sixième invariant qui lit `.github/workflows/ci.yml` et exige que l'étape `composer analyse` y soit présente **et sans `continue-on-error`** — le test lit déjà `phpstan.neon` pour un motif identique. ~1 h.
- Statut        : ouvert

---

### [H46-011] `phpstan.neon` neutralise `missingType.generics` globalement, sans compteur ni date, hors du champ de la garde

- Sévérité      : S3 finition
- Domaine       : tests
- Référence     : main 8db8229
- Emplacement   : `backend/phpstan.neon:28-29`
- Constat       : au-delà de la baseline, `phpstan.neon` porte un `ignoreErrors` global — `- identifier: missingType.generics` — sans chemin, sans `count:`, sans date, sans commentaire.
- Preuve        : `head -29 backend/phpstan.neon` (le fichier fait 29 lignes ; les deux dernières sont cet `ignoreErrors`).
- Témoin négatif: le test `PhpstanBaselineNeGrossitPasTest` **lit bien `phpstan.neon`** (test « reportUnmatchedIgnoredErrors reste activé ») — il aurait donc pu voir cette clé ; il ne la regarde pas. La garde est capable d'ouvrir le fichier, elle ne contrôle simplement pas cette ligne.
- Impact        : c'est le seul endroit du dispositif où l'on peut faire taire une famille entière d'erreurs **sans que rien ne compte ni ne date** — l'inverse exact de la discipline imposée à la baseline. Le motif est identique à celui qui avait rendu la baseline muette avant le 18/08.
- Reproduction  : ajouter un second `- identifier: X` sous celui-ci et relancer la suite Pest : elle reste verte.
- Correctif     : soit documenter la ligne comme l'en-tête de la baseline (date, motif, volume), soit ajouter au test de garde un invariant « `phpstan.neon` ne contient qu'un seul `identifier:` sous `ignoreErrors` ». ~30 min.
- Statut        : ouvert

---

### [H46-012] Le paquet `workers` est linté sans les règles typées : les `no-unsafe-*` n'y existent pas, alors qu'il manipule les données scrapées

- Sévérité      : S3 finition
- Domaine       : tests
- Référence     : main 8db8229
- Emplacement   : `workers/eslint.config.mjs:24` (`...tseslint.configs.recommended`) vs `frontend/eslint.config.mjs:10` (`...tseslint.configs.recommendedTypeChecked`)
- Constat       : le frontend est linté avec les règles **typées** (`recommendedTypeChecked` — d'où ses 10 `no-unsafe-assignment`, 5 `no-unsafe-return`, 5 `no-unsafe-member-access` suppressés) ; `workers`, lui, emploie `recommended` **non typé**, où ces règles ne sont pas chargées. Le commentaire du fichier explique le choix (les fichiers de configuration hors projet TS font échouer ESLint entier) mais ne mentionne pas ce que la simplification coûte.
- Preuve        : lecture des deux configurations ; `cd workers && pnpm lint` → **0 erreur, exit 0** ; `pnpm exec tsc --noEmit` → **0 erreur, exit 0** ; aucun `eslint-suppressions.json` dans `workers/`.
- Témoin négatif: le grep `\bany\b` sur `workers/src` rend **0** — et il **sait trouver** puisque le même grep rend `delete (window as any).Echo` dans `frontend/tests/lib/echo.test.ts:31`. Le vert de `workers` n'est donc pas seulement un angle mort du linter : il n'y a effectivement aucun `any` explicite. Ce qui n'est pas mesuré, ce sont les `any` **implicites** venant de `axios`/`cheerio`, que seules les règles typées verraient.
- Impact        : `workers/src/bridge/result-sender.ts` construit et signe les charges utiles renvoyées à l'API interne ; `cheerio` et `axios` y produisent des valeurs dont le type se perd. Le frontend a été jugé assez sensible pour mériter les règles typées ; le paquet qui manipule les données scrapées avant insertion ne les a pas.
- Reproduction  : `cd workers && pnpm lint` (vert), puis remplacer `recommended` par `recommendedTypeChecked` + `parserOptions.project` et relancer.
- Correctif     : passer `workers` en `recommendedTypeChecked` avec le motif déjà employé côté frontend (bloc `disableTypeChecked` pour `*.mjs` / `*.config.ts`), mesurer le résultat, et supprimer plutôt que baseliner. ~2 h.
- Statut        : ouvert

---

## 5. Ce que je n'ai PAS pu vérifier, et pourquoi

1. **Le témoin PHPStan a été obtenu, mais dans un conteneur isolé, pas dans l'atelier.** Trois
   tentatives dans `axion-crm-api` ont expiré (600 s, 240 s, puis en arrière-plan) : le
   conteneur était saturé — `ps aux` y montrait un **autre agent de l'audit** faisant tourner
   `phpstan analyse app/Models/User.php --level=9`, plus une vingtaine de processus `[php]`
   résiduels. J'ai donc joué le témoin dans un `php:8.3-cli` neuf, avec le `phpstan.phar` du
   dépôt copié dedans. **Ce que cela prouve** : l'outil, à ce niveau, sur ce binaire, voit
   l'erreur et la perd dès qu'elle entre en baseline. **Ce que cela ne prouve pas** : que la
   configuration complète du dépôt (avec l'extension Larastan, qui n'a pas pu être chargée
   dans le temps imparti) se comporte identiquement. La différence est faible mais elle existe.
2. **Une conséquence de cette saturation, à porter au rapport de méthode** : l'atelier local est
   partagé par plusieurs agents de l'audit simultanément, et les mesures s'y gênent. Ma
   première expérience de mise au rouge a modifié `backend/phpstan-baseline.neon` **pendant**
   qu'un autre agent analysait le même dépôt ; je l'ai interrompue et restaurée dès que je
   l'ai vu (`ps aux`), mais **rien dans l'atelier ne signale ce genre de collision**. Un
   `[OK] No errors` mesuré par un agent pendant qu'un autre a le fichier ouvert n'a aucune
   valeur, et personne ne peut le savoir après coup.
3. **Le nombre 276 de `ci.yml:20` n'a pas été expliqué**, seulement contredit (174 aujourd'hui,
   385 en CRLF). Je n'ai pas cherché à quelle date ni sur quelle machine il aurait pu valoir 276 ;
   il n'est vérifiable par aucune commande que j'aie trouvée.
4. **Le pathspec de H46-007 n'a pas de témoin d'exécution.** Il aurait fallu ouvrir une PR
   modifiant un `.php` à la racine de `backend/` pour voir l'étape Pint l'ignorer. Je n'ai pas
   ouvert de PR (hors mandat).
5. **La valeur réelle des variables d'environnement en production** (`MOCK_MODE`,
   `SSRF_GUARD_DENY_PRIVATE`, `RATE_LIMIT_PER_MINUTE`…). La production est en lecture seule et
   je n'y ai pas exécuté de commande. H46-002 décrit donc un **mécanisme mesuré en local**
   (`variables_order=EGPCS`, `config:cache` dans l'entrypoint) et non un état de la production.
   L'agent 40 a déjà mesuré `AUDIT_HASH_CHAIN_SECRET` = 64 caractères en production ; **les
   30 autres variables du bloc C n'ont été mesurées par personne.**
6. **Les 174 fichiers non formatés n'ont pas été corrigés ni chiffrés en diff.** Je n'ai pas
   joué `pint` sans `--test` : cela aurait réécrit 174 fichiers du produit.
7. **`eslint-suppressions.json` n'a pas d'historique exploitable** : un seul commit
   (`b84100f`, 2026-08-13). Impossible de dire si des suppressions ont été ajoutées puis
   retirées entre-temps — il n'y a pas d'`--suppress-all` daté ligne par ligne. Le fichier n'est
   par ailleurs **gardé par aucun test** : rien n'empêche `eslint --suppress-all` de le regonfler,
   à l'inverse exact de ce que fait la baseline PHPStan. Je n'ai pas ouvert de constat séparé
   pour ne pas doubler H46-010, mais **c'est le même défaut, côté frontend, et sans garde du tout**.
8. **Restauration : prouvée.** `phpstan-baseline.neon` a été modifié deux fois puis restauré ;
   `Get-FileHash -Algorithm MD5` rend `5D96393C52BA1F75BF2D7D072505C221`, identique à la
   valeur relevée avant l'expérience, et `git status --porcelain backend` ne montre **aucun**
   fichier suivi modifié. `frontend/eslint-suppressions.json` a été renommé deux fois puis
   restauré ; `git status` ne le montre pas modifié. Le worktree `crmpro-wt-etape1a` n'a été
   ni lu ni approché.
