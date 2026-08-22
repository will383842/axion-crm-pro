# LISTE DE TRAVAIL — la vérité des 420 constats

> Établie le 2026-08-22 par une vague de **30 vérificateurs en lecture seule**,
> chacun tenu de citer un `fichier:ligne` réellement lu. Sans preuve, le verdict
> est INDÉCIDABLE — un verdict honorable. *Un faux « déjà fermé » enterre un
> défaut : c'est le pire résultat possible.*
>
> Verdicts bruts : `verdicts-420.json`. Registre : `FILE-DE-TRAVAIL.md`.

## Ce que la vague a établi

| verdict | nombre | part |
|---|---:|---:|
| ouverts confirmés | 341 | 81 % |
| **déjà fermés — le registre mentait** | 64 | 15 % |
| indécidables, il manque une mesure | 15 | 4 % |

Les 64 déjà fermés se répartissent en 22 S1, 36 S2, 6 S3 : ils sont tous passés
à FERMÉ au registre, avec la preuve de l'agent et la mention explicite que la
fermeture vient d'une **lecture**, pas d'un correctif.

⚠️ **Contrôle de qualité fait avant d'y toucher.** Les 64 portent tous une
citation `fichier:ligne` ; 7 n'ont pas de garde nommée. Trois ont été revérifiés
à la main dans le code (`A08-001`, `B14-005`, `B12-006`) : les trois se
confirment.

## Les 341 réellement ouverts

| sévérité | nombre |
|---|---:|
| S0 | 6 |
| S1 | 44 |
| S2 | 213 |
| S3 | 78 |

Répartis en **225 gestes mécaniques** et **116 qui demandent un arbitrage humain**
(le correctif change une sémantique, touche la production, ou expose une décision
juridique).

Effort estimé sur les gestes mécaniques : **120 petits** (moins d'une heure),
77 moyens, 28 gros.

---

## 1. L'ordre de traitement — par RENDEMENT, pas par sévérité

*Un correctif d'une ligne qui ferme une écriture non cloisonnée passe devant un
chantier d'une semaine, même si le second est plus grave.* Le classement croise
effort d'abord, sévérité ensuite.

### Les petits — moins d'une heure chacun

| # | constat | sev | effort | piste |
|---:|---|---|---|---|
| 1 | `A-013 / A06-001` | S1 | PETIT | Coller au §5 du rapport la mesure réellement faite le 2026-08-19 (celle qui est recopiée dans docker-compose.prod.yml:143-147), retirer ou requalifier |
| 2 | `B14-001 / I49-001` | S1 | PETIT | Trancher entre les deux etats et l'ecrire : soit `consent_optin` est une reserve assumee (alors un commentaire au-dessus de la ligne 33 dit qui l'emet |
| 3 | `D30-001` | S1 | PETIT | Remplacer `overflow-x-hidden` par `overflow-x-auto` a la ligne 101, puis verifier a 375 px les ecrans qui n'utilisent PAS TableScroll (detail entrepri |
| 4 | `E31-003` | S1 | PETIT | Dans enqueue.ts:81, sortir `event_type === "opt_out"` du second verrou : une opposition est un droit qui s'exerce, jamais une capture de lead, et le C |
| 5 | `E33-002` | S1 | PETIT | Dans capturer-lead.ts:156, ajouter `consent: { version: CONSENT_VERSION, at: <horodatage de la capture>, textRef: "chatbot" }` en reprenant exactement |
| 6 | `G43-004` | S1 | PETIT | Passer `$workspaceId` à `poser()` depuis `__invoke()` (il est déjà en main, ligne 42) et l'inclure dans chaque ligne du tableau, comme le font Scraped |
| 7 | `A-003 / A09-005` | S2 | PETIT | Sur l'arbre principal et arbre propre : `git add --renormalize -- '*.sh'` puis commit ; ou, plus chirurgical, `rm` des huit fichiers suivi de `git che |
| 8 | `A06-008` | S2 | PETIT | Reprendre les 16 lignes une à une, et n'y laisser ✅ que si le livrable de la ligne se déclare lui-même complet ; passer en 🟡 toute ligne qui porte un  |
| 9 | `A09-003` | S2 | PETIT | Remplacer les deux lignes 38-39 par l'etat mesure (tables `crm_activites` / `crm_motifs`, constantes `App\Crm\ActivitesEtMotifs`, migration 2026_08_19 |
| 10 | `A09-004` | S2 | PETIT | Sortir `saved_views` du tableau de l'echafaudage mort (ou l'y garder avec la mention exacte : controleur + routes existent, `index`/`show` implementes |
| 11 | `A09-006` | S2 | PETIT | Deplacer les deux lignes de « Quality gates » vers une section « Objectifs non gardes », en citant vitest.config.ts:39-45 et ci.yml:388 comme preuve — |
| 12 | `B13-005` | S2 | PETIT | Dans attachTags(), remplacer le `continue` nu par la collecte du slug dans un tableau `$ignores` + un `Log::notice('crm.ingest.tag_ignore', ['slug' => |
| 13 | `B14-008` | S2 | PETIT | Ajouter `'schema_version' => self::SCHEMA_VERSION` au tableau de CrmFlushOutbound::dispatchOne(), en le tirant d'une constante partagee ; puis un test |
| 14 | `B14-012` | S2 | PETIT | Aux trois sites d'appel, resoudre la person_key du sujet (elle existe deja sur contacts/candidates, cf. ContactUpserter.php:61-63) et la passer en arg |
| 15 | `B15-007` | S2 | PETIT | Corriger l'en-tete :11-17 pour qu'il n'annonce que les trois taches reellement jouees, et deplacer `audit_logs` et `llm_usage` dans une rubrique expli |
| 16 | `B15-012` | S2 | PETIT | Dans retrieve(), après lecture réussie, invalider dans une transaction : `update(['export_token' => null, 'export_expires_at' => now()])` puis `Storag |
| 17 | `B17-005` | S2 | PETIT | Ajouter au WHERE une condition qui exclut le déjà-fait, p. ex. `AND ip <> host(network(set_masklen(ip::cidr, CASE WHEN family(ip)=4 THEN 24 ELSE 48 EN |
| 18 | `B17-011` | S2 | PETIT | Ajouter `public function failed(\Throwable $e): void` qui re-dispatche `new self($this->campaignId)` avec `->delay(60s)` et un compteur d'echecs conse |
| 19 | `C18-002` | S2 | PETIT | Ajouter `public readonly int $personsSkipped = 0` au constructeur, compter la branche `'skipped' => $skipped++` dans le match :145-152, et l'exposer d |
| 20 | `C18-014` | S2 | PETIT | Declarer `SENTRY_LARAVEL_DSN=` dans .env.example a cote de VITE_SENTRY_DSN, et ajouter une sonde de demarrage (ou une ligne dans la sonde de configura |
| 21 | `C18-017` | S2 | PETIT | Extraire la ligne 7 dans un unique helper (`workers/src/config/mocks.ts`) qui interprete le drapeau une seule fois et de facon tolerante, et faire app |
| 22 | `D22-004` | S2 | PETIT | Recuperer `isError`/`error` de useConsoleFeaturesQuery() et inserer, avant la branche `!features.console_v2`, un etat distinct : « Impossible de verif |
| 23 | `D22-005` | S2 | PETIT | Dans le composant de contactsRoute, lire useConsoleFeatures() et, si console_v2 est ouvert, rediriger vers /console/contacts (avec l'etat de chargemen |
| 24 | `D22-007` | S2 | PETIT | Remplacer la route `path: '/*'` par un `notFoundComponent` sur rootRoute (ou un `defaultNotFoundComponent` dans createRouter, main.tsx:39), et rattach |
| 25 | `D23-010` | S2 | PETIT | Deplacer `sectionOuverte` dans un petit magasin partage (ou exposer un `openSection(id)` via contexte), puis, dans le `callback` Joyride, ouvrir la se |
| 26 | `D23-011` | S2 | PETIT | Ajouter dans le pied de `Sidebar.tsx` un lien permanent vers la console axionia (URL par variable Vite, pas en dur), avec `target="_blank" rel="noopen |
| 27 | `D25-008` | S2 | PETIT | Supprimer `placeholderData` (le garde-fou `const stats = data ?? {…}` de la l. 93 suffit déjà à protéger le rendu), et ajouter `period` à la `queryKey |
| 28 | `D25-011` | S2 | PETIT | Deux gestes : `company.contacts?.length ?? 0` sur les cinq sites, et — plus durable — valider la réponse d'API à la frontière (un schéma zod par vue d |
| 29 | `D26-005` | S2 | PETIT | Garder l'etat en chaine pendant la saisie (`useState<string>`) et n'appliquer `Math.max(min, Math.min(max, v))` que sur `onBlur` (ou a la validation d |
| 30 | `D26-008` | S2 | PETIT | Filtrer a l'envoi — dans `buildPayload()`, ne retenir que `Object.fromEntries(Object.entries(perSourceLimits).filter(([s]) => sources.includes(s as Ca |
| 31 | `D26-009` | S2 | PETIT | Declarer `validateSearch: (s) => ({ step: Number(s.step ?? 1) })` sur `campaignsNewRoute`, lire l'etape par `useSearch` et la changer par `navigate({  |
| 32 | `D26-010` | S2 | PETIT | Porter le compteur dans le composant `Input`/`FormField` (« 87 / 120 »), affiche des que la saisie depasse ~80 % de la borne, et signaler visuellement |
| 33 | `D27-001` | S2 | PETIT | Dans `CoveragePage.tsx`, supprimer la fonction locale l. 380-387 et importer `Stat` depuis `@/components/ui` ; décider ensuite pour `CardFooter` — soi |
| 34 | `D27-007` | S2 | PETIT | Remplacer les six littéraux de features/coverage/ par `shadow-[var(--shadow-card)]` / `hover:shadow-[var(--shadow-card-hover)]` / `shadow-[var(--shado |
| 35 | `D28-004` | S2 | PETIT | Deux voies : soit remplacer les cinq déclencheurs par des `<span>` sur le modèle de `UserMenu.tsx:81` en remontant le nom accessible ; soit — plus pro |
| 36 | `D28-005` | S2 | PETIT | Completer `.skip-link:focus` avec un fond et une couleur explicites plus un rembourrage et un contour de focus (par ex. fond `--color-sidebar-active`  |
| 37 | `D28-007` | S2 | PETIT | Ajouter à SearchInput une propriété `label: string` (obligatoire, pour que l'oubli devienne impossible) rendue soit en `<label class="sr-only" htmlFor |
| 38 | `D28-013` | S2 | PETIT | Le plus sûr : poser `defaultNotFoundComponent: NotFoundPage` dans `createRouter` (`main.tsx:39-43`) et supprimer `notFoundRoute`. Sinon, corriger `pat |
| 39 | `D29-008` | S2 | PETIT | Trier sur l'instant et non sur la chaine : `usort(..., fn($a,$b) => strtotime((string) $b['occurred_at']) <=> strtotime((string) $a['occurred_at']))`, |
| 40 | `D29-010` | S2 | PETIT | Remplacer la l. 102 par une lecture qui traite le vide comme l'absence — par ex. `'timezone' => (($tz = env('DB_TIMEZONE')) === '' ? null : $tz)` — pu |
| 41 | `D30-005` | S2 | PETIT | Trois gestes indépendants : ajouter dans `RootLayout` un `useEffect` sur le chemin courant (`useRouterState`) qui appelle `setMobileSidebarOpen(false) |
| 42 | `D30-008` | S2 | PETIT | Remplacer `shrink-0` par `min-w-0` (ou rien) sur le div des actions, en gardant `flex-wrap`, pour que le repli écrit puisse réellement se produire ; v |
| 43 | `E31-005` | S2 | PETIT | Ajouter `"podcast_request"` à `CrmSyncFamily` (l. 72-73), son libellé dans `FAMILY_LABELS`, et un sixième `compareFamily({ family: "podcast_request",  |
| 44 | `E31-010` | S2 | PETIT | Après la pose du drapeau, si `email === null`, journaliser en erreur avec l'applicationId et rendre un résultat distinct (`{ ok: false, reason: 'pii_u |
| 45 | `E32-006` | S2 | PETIT | Ajouter un champ `external?: true` (ou `hrefAbsolu`) au type d'item de `admin-nav.ts`, y declarer l'entree avec un libelle qui dit ce qu'elle est (« A |
| 46 | `E32-010` | S2 | PETIT | Déplacer `Route::get('/config/features', …)` hors du groupe auth:sanctum (une route anonyme ne rendant que `console_v2`), et poser explicitement CRM_C |
| 47 | `E33-004` | S2 | PETIT | Ajouter `"podcast_request"` a `CrmSyncFamily` + `FAMILY_LABELS`, puis un sixieme `compareFamily({ family: "podcast_request", universe: "business", loa |
| 48 | `E33-007` | S2 | PETIT | Réduire le corps Telegram à des éléments non identifiants plus un lien profond vers la fiche : `🟢 Nouveau lead chatbot — Submission #<id>` + structure |
| 49 | `E34-006` | S2 | PETIT | Soit renormaliser la copie de travail (`git rm --cached -r . && git reset --hard`, arbre propre), soit — plus robuste — rendre le test indifférent aux |
| 50 | `F38-006` | S2 | PETIT | D'abord verifier a la main que l'URL rend 200 et que `lhci autorun` produit un score ; ensuite poser un `.lighthouserc.json` avec des assertions chiff |
| 51 | `F38-008` | S2 | PETIT | Ajouter `timeout-minutes:` sous chaque `runs-on:` des huit workflows, calibre sur la duree p95 reelle de chaque job majoree d'une marge (par ex. 30 po |
| 52 | `F38-013` | S2 | PETIT | Jouer une fois `semgrep ci` sur main pour compter les découvertes bloquantes ; si zéro, retirer `continue-on-error: true` (comme cela a été fait pour  |
| 53 | `F39-007` | S2 | PETIT | Dans chaque catch : `Log::warning('observability.<rubrique> indisponible', ['exception' => $e->getMessage()])`, puis rendre la rubrique sous la forme  |
| 54 | `F39-009` | S2 | PETIT | Corriger `Makefile:160` en « DR drill (RPO reel ≤ 24 h, RTO ≤ 4 h) » et reecrire les deux puces de `ARCHITECTURE.md:94-95` sur le modele deja pose dan |
| 55 | `F39-010` | S2 | PETIT | Retirer le défaut (`TARGET_DB="${2:?base cible requise — jamais de defaut}"`) et, si la cible vaut `axion_crm` ou si DB_CONTAINER vaut axion-crm-postg |
| 56 | `G41-010` | S2 | PETIT | Poser le predicat dans `buildFilteredQuery()` des deux controleurs sur le modele exact de `RgpdRequestsController.php:64-71` (y compris le `whereRaw(' |
| 57 | `G41-011` | S2 | PETIT | Verifier d'abord par EXPLAIN que `idx_company_tag_tag` sert bien le `GROUP BY` de `TagsController:49-53` (et l'inscrire dans `IndexServentLesRequetesT |
| 58 | `G41-012` | S2 | PETIT | Sur `ScraperRunsPage.tsx:237` et `CampaignsListPage.tsx:75`, remplacer la constante par une expression sur le modele de `CampaignDetailPage.tsx:96-97` |
| 59 | `G42-005` | S2 | PETIT | Remplacer la forme objet par une fonction `manualChunks(id)` qui teste `id.includes('node_modules/react-dom')` / `node_modules/react/`, ou retirer pur |
| 60 | `G42-011` | S2 | PETIT | Trois gestes independants : (a) `const LOG = import.meta.env.DEV ? (...a) => console.log('[FranceMap]', ...a) : () => {}` ; (b) dans le mousemove, ne  |
| 61 | `G43-007` | S2 | PETIT | Reecrire le runbook pour viser un environnement dedie (variable `LOAD_TEST_TARGET` obligatoire, refus explicite du domaine de production) et un compte |
| 62 | `H45-003` | S2 | PETIT | Ligne 62 : `expect($captured->getHeaderLine('User-Agent'))->toContain('Axion')` (ou la valeur exacte posee par HibpChecker). Ligne 45 : compter les en |
| 63 | `H45-004` | S2 | PETIT | Retirer les commentaires avant l'assertion (`preg_replace('~//[^\n]*//\*.*?\*/~s', '', $source)` cote PHP, `^\s*#` cote NEON — le meme depouillement e |
| 64 | `H45-005` | S2 | PETIT | Recopier les huit `<env name="MOCK_*" value="true"/>` manquants dans phpunit.xml et phpunit-ci.xml, poser `force="true"` sur MOCK_MODE, puis ajouter u |
| 65 | `H45-006` | S2 | PETIT | Lire `relforcerowsecurity` en plus de `relrowsecurity` ; alimenter la liste des tables depuis la meme source que la migration de durcissement plutot q |
| 66 | `H46-003` | S2 | PETIT | Aligner PentestSelfCheck.php:71 sur `SsrfGuard::enabled()` : appeler la methode plutot que de relire `env()` (`$denyPrivate = SsrfGuard::enabled();`). |
| 67 | `H46-008` | S2 | PETIT | Poser le generique sur les cinq appels `api.post`/`api.put` concernes (`api.post<{ data: RgpdRequest }>(...)`) et supprimer les cinq entrees de fronte |
| 68 | `H47-005` | S2 | PETIT | Retirer `{ image: worker, … }` de la matrice de deploy-staging.yml et le dire en commentaire au meme endroit que docker-compose.yml:308-313, de sorte  |
| 69 | `I48-006` | S2 | PETIT | Corriger _REPORTS/2026-08-19_INVENTAIRE-ETAPE-1A.md:27 en disant la mesure : « table sans ecrivain — lue par la cloche (NotificationsController) et l' |
| 70 | `I49-002` | S2 | PETIT | Ecrire dans inbound.ts l'equivalent d'`assertOnlyKeys` (refus 422 de toute cle hors des sept attendues, avec le meme code d'erreur `unknown_field` que |
| 71 | `I49-009` | S2 | PETIT | Retirer le gras des trois mentions dans le §22.2 du CDC (« numero client attribue », « reinscription », « fiche creee ou rapprochee »). Quinze minutes |
| 72 | `P5-35-005` | S2 | PETIT | Ajouter a ScriptMotDePasseVerdictsTest un cas F35-007 qui lit `infra/scripts/definir-mot-de-passe-crm.sh` et exige qu'aucun `docker exec` ne porte le  |
| 73 | `P5-35-008` | S2 | PETIT | Ajouter `->middleware('permission:rgpd.view')` ou `'permission:rgpd.handle'` sur api.php:293 — la decision entre les deux revient a Will, puisque c'es |
| 74 | `P5-35-010` | S2 | PETIT | Ajouter `HIBP_FAIL_MODE=closed/open-audited` (defaut `closed`) lu par `config/`, et en mode `open-audited` accepter le mot de passe EN ECRIVANT une li |
| 75 | `A-005` | S3 | PETIT | Soit retirer les deux routes SPA + les deux controleurs et rediriger `/cold-email` et `/linkedin` vers `/`, soit inscrire l'arbitrage dans un ADR date |
| 76 | `A05-007` | S3 | PETIT | Ajouter une table de six lignes (« lot du plan » ↔ « lot de session » ↔ « PR ») en TETE du journal 2026-08-13 ET en tete du rapport de cloture, pas au |
| 77 | `A06-005` | S3 | PETIT | Ajouter dans le job `backend` de .github/workflows/ci.yml, apres la suite Pest et sur le conteneur Postgres du job, un pas qui joue `php artisan migra |
| 78 | `A06-009` | S3 | PETIT | Remplacer « et sa valeur en production » par « (l'état du serveur n'est pas observable depuis ce dépôt — cf. RlsTest.php:341-346) », et renvoyer expli |
| 79 | `A06-011` | S3 | PETIT | Ajouter au §A.1 du CDC une colonne « ref. plan » portant le F correspondant pour chacune des 15 lignes (la correspondance est deja construite au §1 te |
| 80 | `A06-013` | S3 | PETIT | Reecrire la ligne F16 du §2 : `no_show` remonte automatiquement, seul `completed` est manuel et pourquoi ; y consigner la fenetre de rattrapage de 48  |
| 81 | `A07-009` | S3 | PETIT | Remplacer les quatre occurrences de `C:/Users/willi/Documents/Projets/crmpro-wt-etape0/` par un chemin relatif au dépôt principal (`-f docker-compose. |
| 82 | `A09-010` | S3 | PETIT | Remplacer les trois lignes par la forme hors conteneur (`cd workers && pnpm typecheck` / `pnpm test`) et retirer « + workers » de README.md:13. |
| 83 | `A09-011` | S3 | PETIT | Remplacer le statut par « implémentée, cf. ARCHITECTURE.md » et régénérer la colonne « Lignes » par un `wc -l` scripté (ou la supprimer : une colonne  |
| 84 | `A09-012` | S3 | PETIT | Supprimer le commentaire :219-221 et :227, remplacer par une ligne disant ce qui se passe reellement (« la commande existe depuis <commit> ; cette tac |
| 85 | `A09-013` | S3 | PETIT | Poser en tête des §3.1 et §6.5 un encart « ⚠️ DÉPASSÉ le 2026-08-19 en fin de journée — cf. §11 », et remplacer la ligne 1001 par le SHA courant ou pa |
| 86 | `B12-017` | S3 | PETIT | Supprimer backend/app/Http/Controllers/Api/Phase2/CampaignsController.php et regenerer la spec OpenAPI ; verifier au passage que la note de routes/api |
| 87 | `B13-008` | S3 | PETIT | Le moins cher et le plus honnete : ecrire dans le docbloc de `Taxonomy::BUSINESS_RELATION_TYPES` que `fournisseur` est reserve a la saisie manuelle en |
| 88 | `B14-014` | S3 | PETIT | Dans la branche de retour anticipe, fusionner le nouveau `payload` dans la ligne en attente (`array_merge` du JSON existant) et rafraichir `updated_at |
| 89 | `B15-009` | S3 | PETIT | Inscrire dans la definition de fini de L7 : une facade unique d'envoi, plus un test architectural qui rougit si un fichier de `app/` ecrit dans `email |
| 90 | `B15-011` | S3 | PETIT | Ajouter dans `handle()` un troisième `DB::affectingStatement` sur `users` conditionné à `last_login_at < ?`, avec le même `host(network(set_masklen(.. |
| 91 | `B16-013` | S3 | PETIT | Migration `ALTER TABLE audit_logs ALTER COLUMN prev_hash DROP DEFAULT`, avec un `down()` qui remet `repeat('0',64)` (pas 'GENESIS'). Aligner au passag |
| 92 | `B16-014` | S3 | PETIT | Corriger AuditHashChain.php:24-26 (le defaut vaut desormais `repeat('0',64)`, et il sera retire par le correctif de B16-013). Dans infra/runbooks/05-r |
| 93 | `B17-007` | S3 | PETIT | Fusionner avec A09-012 : un seul lot corrige le commentaire :219-221/:227 et retire les deux fermetures `skip()` mortes. Ecrire dans le commentaire ce |
| 94 | `C19-011` | S3 | PETIT | Remplacer `'verify' => false` par `'verify' => (bool) config('crm.proxies.verify_tls', true)`, poser la variable à `false` seulement pour le fournisse |
| 95 | `C19-012` | S3 | PETIT | Écrire une décision datée (contournement de captcha refusé, ou autorisé sous conditions) dans _AUDIT/05_DECISIONS.md, puis la rendre mécanique : une g |
| 96 | `D23-006` | S3 | PETIT | Compléter LABELS pour les dix chemins et retirer les deux entrées mortes ; puis dériver la table du même objet que Sidebar (une seule source de libell |
| 97 | `D26-012` | S3 | PETIT | Choisir un seul mecanisme par champ : soit garder les attributs HTML et retirer les regles react-hook-form mortes, soit l'inverse ; et passer `error={ |
| 98 | `D27-012` | S3 | PETIT | Même correctif qu'A09-011 : régénérer la colonne « Lignes » par `wc -l`, ou la supprimer plutôt que d'entretenir un chiffre qui se périme à chaque édi |
| 99 | `D28-012` | S3 | PETIT | Dans `Sidebar`, remplacer les `<h3>` de groupe par un `<div>` porteur de l'`id` et rattacher chaque `<ul>` a un `<nav aria-labelledby=…>` ; envelopper |
| 100 | `D28-014` | S3 | PETIT | `role="status" aria-live="polite"` sur `CompaniesTableSkeleton`, `role="img"` sur le `<svg>` de `Spinner`, et une seule region `aria-live="polite"` da |
| 101 | `D28-015` | S3 | PETIT | Ajouter `min-h-6 min-w-6` (24 px) — ou `inline-flex items-center justify-center size-6` — sur le `<button>` de :45, et couvrir la règle par un test ax |
| 102 | `D28-016` | S3 | PETIT | Ajouter en fin de frontend/src/styles/index.css un bloc `@media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: 0.01ms |
| 103 | `D29-003` | S3 | PETIT | Trancher UNE direction et l'ecrire. Le moins couteux et le plus honnete aujourd'hui : supprimer les 15 cles mortes de fr.json et en.json, pour que le  |
| 104 | `D29-005` | S3 | PETIT | Remplacer les deux occurrences par un formateur partagé, p. ex. `new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(x)`, po |
| 105 | `E32-001` | S3 | PETIT | Corriger la liste du §4.11 / §A du mandat : retirer « Rendez-vous + calendrier », ajouter « Tout » ; et rejouer `buildAdminNav` une fois pour figer le |
| 106 | `E32-008` | S3 | PETIT | Choisir UN nom (« Recrutement » ou « Commercial »), l'appliquer au label de admin-nav.ts:494 ET au titre rendu par SubmissionsV2 pour ce basePath (auj |
| 107 | `F37-005` | S3 | PETIT | Faire du défaut la seule origine de production (`https://app.axion-crm-pro.com`), et poser `CORS_ALLOWED_ORIGINS=https://app.localhost,https://app.axi |
| 108 | `F38-014` | S3 | PETIT | Remplacer la ligne 24 par `pnpm install --frozen-lockfile` seul, et vérifier au préalable que le lock est à jour ; si l'on tient à un repli, le rendre |
| 109 | `F40-012` | S3 | PETIT | Soit rétablir les services workers dans docker-compose.yml (ce que le commentaire ligne 315 prévoit), soit retirer d'un même geste Dockerfile.worker + |
| 110 | `G42-009` | S3 | PETIT | Retirer les cinq entrees confirmees de frontend/package.json, regenerer pnpm-lock.yaml, et poser une garde qui relit package.json et exige que chaque  |
| 111 | `H44-008` | S3 | PETIT | Remplacer par `pnpm install --frozen-lockfile` seul (comme les autres jobs), et laisser l'échec parler ; si un filet est jugé nécessaire, garder l'éch |
| 112 | `H44-009` | S3 | PETIT | Retirer les deux lignes 26-27 de MOCKS-STRATEGY.md (ou les marquer explicitement « Phase 2 — non implémenté »), et remplacer `RealSmtpProber` par `Hun |
| 113 | `H44-010` | S3 | PETIT | Passer à `include: ['**/*.test.ts']` avec l'`exclude` existant (node_modules, dist), ou — moins intrusif — ajouter une garde qui échoue si un `*.test. |
| 114 | `H46-006` | S3 | PETIT | Remplacer le nombre figé par la façon de l'obtenir (« compter avec `pint --test` ; à la date X : N ») et le dater, ou mieux : supprimer le chiffre en  |
| 115 | `H46-007` | S3 | PETIT | Remplacer `'backend/**/*.php'` par `':(glob)backend/**/*.php'` (qui rend `**` réellement récursif y compris zéro segment) ou plus simplement par `'bac |
| 116 | `H46-010` | S3 | PETIT | Ne pas faire jouer PHPStan dans Pest : renommer la garde et son en-tête pour dire ce qu'elle garde réellement (« la baseline ne grossit pas ET le socl |
| 117 | `H46-012` | S3 | PETIT | Appliquer `recommendedTypeChecked` dans un bloc `files: ['src/**/*.ts', 'tests/**/*.ts']` avec `parserOptions.projectService` (ou un tsconfig.eslint.j |
| 118 | `H47-003` | S3 | PETIT | Ajouter dans .github/dependabot.yml apres la ligne 70, et dans le rapport de politique, un encart date « RECTIFICATION 2026-08-xx » qui dit ce qui a c |
| 119 | `H47-004` | S3 | PETIT | Deux gestes independants : (1) ajouter `- { nom: poc, path: poc/05_dedup_performance }` a la matrice de security.yml:284 ; (2) soit ajouter un bloc `d |
| 120 | `P5-35-012` | S3 | PETIT | Separer les deux branches : `isError ? <QueryErrorState error={error} /> : items.length === 0 ? <EmptyState .../> : ...`, en recuperant `error` du `us |

### Les moyens

| # | constat | sev | effort | piste |
|---:|---|---|---|---|
| 1 | `E33-006` | S0 | MOYEN | Refaire exactement ce que capturer-lead a fait : ajouter `contactEmailHash String? @map("contact_email_hash")` (+ index) au model ChatEscalation avec  |
| 2 | `B14-004 / I49-006` | S1 | MOYEN | Ne pas passer par Prometheus tant que la pile n'est pas debout : ajouter a `crm:flush-outbound` une sortie non nulle (ou un appel au meme canal Telegr |
| 3 | `D22-002` | S1 | MOYEN | Reprendre le patron deja pose sur les ecrans console (ArbitragePage, CandidatesPage, ContactsHubPage…) : destructurer `isError`/`error`, et rendre `is |
| 4 | `D24-003` | S1 | MOYEN | Rendre chaque `<li>` de la timeline navigable vers sa source (route de l'activité / de la soumission via `external_ref`), lier `subject.company` à la  |
| 5 | `D24-004` | S1 | MOYEN | Remplacer l'`Input` numerique par un champ de recherche qui interroge /companies avec le nom et le code postal DEJA presents dans l'evenement en arbit |
| 6 | `D25-002` | S1 | MOYEN | Meme geste que D22-002, et surtout : poser la garde d'ENUMERATION avant les corrections, pour que la liste des ecrans restants soit produite par le co |
| 7 | `E32-002` | S1 | MOYEN | Le champ `payload?: Record<string, unknown>` existe deja des deux cotes et est libre : SiteSyncEvent.php:171 le lit via `self::assocArray($raw['payloa |
| 8 | `E32-003 / E31-004 / E33-003` | S1 | MOYEN | Ajouter cote CRM une route interne signee (meme patron HMAC que /api/internal/scraper-result) qui prend une liste de `subject_ref` et rend ceux presen |
| 9 | `G42-001` | S1 | MOYEN | Remplacer les 30 imports statiques par `const X = lazy(() => import('@/features/.../XPage'))` et poser un `<Suspense fallback={...}>` dans RootLayout  |
| 10 | `A05-006` | S2 | MOYEN | Une commande `crm:sonde-vivier` sur le patron exact de CrmSondeNonDiffusibles (prefixe d'alerte constant, planification quotidienne, crochet onFailure |
| 11 | `A06-007` | S2 | MOYEN | Deux gestes independants. (a) Remplacer dans RUNBOOK-CONSOLE-LOCALE.md:41,331,344 les chemins `crmpro-wt-etape0/...` par le docker-compose.local.yml e |
| 12 | `A07-008` | S2 | MOYEN | Declarer dans un document versionne du depot (par ex. ARCHITECTURE.md ou un _REPORTS/ dedie) que la numerotation qui fait foi est celle du CDC §A.1 (1 |
| 13 | `A07-011` | S2 | MOYEN | Ne pas automatiser depuis Calendly. Deux voies honnetes : soit deriver `completed` d'un signal AVAL et constate (devis emis, facture, note d'appel sai |
| 14 | `B11-005` | S2 | MOYEN | Deriver le nom de la base d'un jeton de processus — `axion_crm_test_{TEST_TOKEN/getmypid()}` dans bootstrap.php — et adapter la garde de TestCase::set |
| 15 | `B14-006` | S2 | MOYEN | Faire ecrire a la tache un battement de coeur date (cle Redis ou ligne d'une table `taches_planifiees`) en fin de `handle()`, et exposer son age en me |
| 16 | `B16-011` | S2 | MOYEN | Ne rien ajouter en base : renommer côté écran `previous_hash` → `prev_hash` et `payload` → `payload_hash`, retirer les colonnes actor/target (ou les d |
| 17 | `B16-012` | S2 | MOYEN | Injecter AuditHashChain dans le handle() de chaque commande destructive et écrire UNE ligne par exécution (event_type = nom de la commande, payload_ha |
| 18 | `B17-006` | S2 | MOYEN | Sur les sept taches, ajouter `->withoutOverlapping(N)` avec un TTL dimensionne sur la duree plausible de chacune (jamais au-dela de 360 min, plancher  |
| 19 | `C18-010` | S2 | MOYEN | Deux gestes séparables : donner au run un statut `dispatched`/`pending` quand le travail part chez le worker Node, que /internal/scraper-result fera p |
| 20 | `C18-012` | S2 | MOYEN | Ajouter a `LaunchZoneScrapingJob` un `failed(\Throwable $e)` qui referme SON run (`status='failed'`, `finished_at`) de facon idempotente, et planifier |
| 21 | `C18-013` | S2 | MOYEN | Introduire une méthode unique qui rend le MOTIF en SQL (`CASE WHEN email_generic IS NULL THEN 'sans_adresse' WHEN EXISTS(opt_out…) THEN 'opposition' W |
| 22 | `C19-002` | S2 | MOYEN | Côté PHP : retirer les crochets avant comparaison (`trim($host,'[]')`), et ajouter à DENY_CIDR les plages IPv6 (::1/128, ::/128, fc00::/7, fe80::/10,  |
| 23 | `C19-005` | S2 | MOYEN | Poser un compteur par hôte dans Redis (Laravel `RateLimiter::attempt("scrape:{$host}", 5, …)` ou un jeton glissant), consulté dans fetchAnyMentionsLeg |
| 24 | `D23-003` | S2 | MOYEN | Le geste le moins cher : renommer l'entree du hub d'apres ce qu'elle montre reellement (« Entreprises & contacts » ou « Portefeuille ») et supprimer l |
| 25 | `D23-004` | S2 | MOYEN | Passer les 38 chaines une par une en distinguant identifiants techniques et texte affiche, ScraperRunsPage.tsx:352 et DashboardPage.tsx:140 compris. P |
| 26 | `D23-005` | S2 | MOYEN | Court terme, faire pointer la selection vers la fiche de l'entreprise porteuse avec l'ancre du contact (`/companies/$companyId#contact-$id`) — l'API d |
| 27 | `D23-007` | S2 | MOYEN | Ajouter `count?: number` à NavItem, un endpoint unique `/nav-counters` qui rend les six valeurs en une requête (avec React Query, staleTime généreux e |
| 28 | `D23-008` | S2 | MOYEN | Trancher d'abord la question de conception (Will) : soit `NavSection` gagne un niveau `groups: NavGroup[]` et la regle des sept se lit par groupe et n |
| 29 | `D23-012` | S2 | MOYEN | Écrire un petit lexique partagé des deux consoles (le mot, la notion, la console) et lever l'ambiguïté par qualification plutôt que par invention : «  |
| 30 | `D26-006` | S2 | MOYEN | Lier l'erreur (`} catch (e) {`), appeler `qualifierErreur(e)` déjà présente dans src/lib/api.ts, et faire dire au toast ce que la nature signifie — «  |
| 31 | `D26-007` | S2 | MOYEN | Envelopper chaque étape dans son propre `<form onSubmit={…}>` qui avance d'une étape (et soumet à la dernière), typer explicitement `type="button"` to |
| 32 | `D26-013` | S2 | MOYEN | Remplacer les trois `window.location.assign` par un renvoi du routeur avec la route d'origine en paramètre de retour, et sortir csrfFetched du loquet  |
| 33 | `D27-003` | S2 | MOYEN | Migrer un composant à la fois en comparant les propriétés (commencer par Stat et SegmentedControl, les plus simples), et si l'apparence particulière d |
| 34 | `D27-006` | S2 | MOYEN | Traiter d'abord les deux composants du systeme (une variante `dark:bg-*-950/40 dark:text-*-300` par entree des tables `STYLES`), car ils sont rendus d |
| 35 | `D27-008` | S2 | MOYEN | Etendre `Input` d'une prop de largeur/variante (ou accepter `className` en fusion), remplacer d'abord les sept champs texte/date/datetime listes ci-de |
| 36 | `D27-013` | S2 | MOYEN | Trancher d'abord : `PageHeader` a 27 consommateurs, `PageShell` en a 1 vivant — soit `PageShell` devient l'enveloppe unique (elle englobe `PageHeader` |
| 37 | `D28-009` | S2 | MOYEN | Définir deux ou trois jetons de petite taille en rem dans styles/index.css (p. ex. --text-xxs: 0.625rem, --text-xs2: 0.6875rem), remplacer `text-[11px |
| 38 | `D29-002` | S2 | MOYEN | Ajouter un sélecteur de langue dans `features/settings/SettingsPage.tsx` (et/ou le `UserMenu`) qui appelle `i18n.changeLanguage(l)` — la persistance e |
| 39 | `D29-004` | S2 | MOYEN | Creer un module unique `lib/formats.ts` exposant `formatDate`, `formatDateTime`, `formatNombre`, qui lisent la locale active de i18next (deja amorce,  |
| 40 | `D29-006` | S2 | MOYEN | i18next est deja amorce (frontend/src/main.tsx:18) : declarer chaque libelle comme une cle avec ses formes `_one`/`_other` et l'appeler par `t('zones. |
| 41 | `D30-004` | S2 | MOYEN | Ajouter dans `RootLayout` un `<nav>` de bas d'écran affiché sous `lg` uniquement, à cinq entrées tirées de la même source que `Sidebar` (pour ne pas d |
| 42 | `D30-007` | S2 | MOYEN | Sur mobile, ne pas faire tenir le fil entier dans la rangée : n'y afficher que le dernier segment (le nom de l'écran courant) en `truncate`, et report |
| 43 | `E31-007` | S2 | MOYEN | Dans `reconcile.ts`, scinder la famille `submission` : exclure du `where` les soumissions dont `details.subType = "candidature-commerciale"` (et celle |
| 44 | `E32-004` | S2 | MOYEN | Poser un `SiteSetting` `crm_sync_kill_switch` lu par `isCrmSyncEnabled()` avec un cache court (30 s), plus un bouton dans la console `/synchro-crm` ;  |
| 45 | `E32-009` | S2 | MOYEN | Ne pas patcher unilateralement : porter l'arbitrage a Will. Si fusion decidee, garder une seule entree « Messages » avec une rangee d'onglets (ou un p |
| 46 | `E33-005` | S2 | MOYEN | Meme correctif que E31-007, a faire une seule fois : sortir de la famille `submission` les enregistrements d'univers vivier (`details.subType = "candi |
| 47 | `E33-008` | S2 | MOYEN | Dériver un slug stable par finalité dans le formulaire unifié (`unified-contact:<data.type>[:<subType>]`) et le passer à syncFormSubmissionToCrm ; fai |
| 48 | `E34-002` | S2 | MOYEN | Ecrire `scripts/check-crm-sync-mocks.ts` : lister les fichiers de `src` qui importent `@/server/crm-sync`, pour chacun chercher un test voisin qui con |
| 49 | `F38-009` | S2 | MOYEN | Deux gestes dans le même lot : (1) mesurer une fois la couverture réelle (pcov + `pest --coverage`, `vitest run --coverage`), (2) selon le résultat, s |
| 50 | `F39-012` | S2 | MOYEN | Faire produire les comptages par `backup-postgres.sh` AU MOMENT du dump, dans un petit manifeste depose a cote de l'archive (meme nom, suffixe `.count |
| 51 | `G41-013` | S2 | MOYEN | Materialiser le code departement plutot que de le recalculer : ajouter une colonne `department` a `coverage_matrix_cells` (derivee correctement, DOM e |
| 52 | `G42-002` | S2 | MOYEN | Sortir OnboardingTour derriere un `lazy()` monte seulement quand `onboarding_tour_completed_at === null` ; charger `@/lib/echo` par `await import()` d |
| 53 | `G42-013` | S2 | MOYEN | Poser un `lighthouserc.json` avec `collect.startServerCommand: vite preview` + `collect.url: http://127.0.0.1:4173/...` et une section `assert` sur le |
| 54 | `G43-008` | S2 | MOYEN | Un test qui ouvre une seconde connexion Postgres nommee, `BEGIN` + `SELECT ... FOR UPDATE` sur la meme activite avec `SET lock_timeout`, puis frappe l |
| 55 | `H44-004` | S2 | MOYEN | Autoriser un suffixe, jamais un nom libre : lire `AXION_TEST_DB_SUFFIXE` (motif `^[a-z0-9_]{1,20}$`) et epingler `axion_crm_test` . suffixe, en gardan |
| 56 | `H44-011` | S2 | MOYEN | Ajouter dans ci.yml un job nocturne (`schedule`) qui joue `pest --configuration phpunit.xml` (ordre aleatoire, graine journalisee) et ouvre une issue  |
| 57 | `H45-009` | S2 | MOYEN | Deux options a mesurer avant de trancher : (a) copier le code dans l'image au lancement (`docker cp` ou un `--mount type=volume` alimente par un rsync |
| 58 | `H45-010` | S2 | MOYEN | Pour chacune des 12, un test minimal qui appelle la commande via `$this->artisan(...)` sur un jeu vide et assure le code de sortie plus une invariance |
| 59 | `H46-004` | S2 | MOYEN | Typer les trois fermetures d'export avec le modele concret et declarer les proprietes manquantes en `@property` sur les modeles ; retirer les 39 entre |
| 60 | `H46-009` | S2 | MOYEN | Traiter fichier par fichier plutot qu'en bloc : `void` explicite la ou l'oubli est deliberement voulu, `await` la ou l'echec doit etre vu, et retirer  |
| 61 | `I48-007` | S2 | MOYEN | Renommer `/console/*` en `/crm/*` cote frontend (le commentaire routeTree.tsx:39-46 dit lui-meme que `/crm` est desormais libre cote routes d'ecran),  |
| 62 | `I49-005` | S2 | MOYEN | A cout faible et valeur immediate : ajouter cote CRM le lien permanent manquant vers la console du site et renommer celui du site, puis incarner la ca |
| 63 | `I49-008` | S2 | MOYEN | Trois gestes, dans cet ordre : (a) poser `DB_TIMEZONE=Europe/Paris` dans les docker-compose (~30 min), prerequis de toute mesure ; (b) un ADR qui tran |
| 64 | `A-014` | S3 | MOYEN | Une migration dediee qui (a) pose ENABLE + FORCE RLS et la policy `workspace_id` sur `audit_logs` ET sur chaque partition existante, (b) inscrit la po |
| 65 | `A06-006` | S3 | MOYEN | Ajouter `app/Services/Audiences/` a `BASELINE_CHEMINS_INTERDITS`, puis resorber les 9 entrees de baseline (+ celles de AudienceMember, RefreshAudience |
| 66 | `B13-007` | S3 | MOYEN | Ajouter `schema_version` et `request_id` à ALLOWED_KEYS, les rendre optionnels d'abord ; côté service, une table (ou une clé Redis à TTL > 300 s) `sit |
| 67 | `D22-008` | S3 | MOYEN | Soit supprimer l'entrée « Profil » du menu (correctif immédiat, honnête), soit ouvrir `/settings` sur un onglet compte via `navigate({ to: '/settings' |
| 68 | `D25-010` | S3 | MOYEN | Une seule copie de `extractApiMessage` dans `src/lib/api.ts`, importee par les 8 ecrans ; puis la brancher dans le composant d'etat d'erreur de D25-00 |
| 69 | `D26-011` | S3 | MOYEN | Deux lignes d'abord : `aria-invalid={invalid // undefined}` dans `Input.tsx` et `useId()` dans `FormField.tsx`. Ensuite, faire converger les `Field` l |
| 70 | `D27-009` | S3 | MOYEN | Ajouter au composant les deux variantes qui manquent (`accent` bleu pour les segments sky-500, `success`) plutot que de les laisser vivre dehors, puis |
| 71 | `D27-011` | S3 | MOYEN | Réécrire §1 de la spec à partir du contenu réel de index.css:7-53 (noms `brand-*`, notation oklch, jetons sidebar/radius/shadow), et supprimer les deu |
| 72 | `D30-009` | S3 | MOYEN | Donner a `GlobalSearch` des props `open`/`onOpenChange` (etat interne conserve en repli), et sur telephone ouvrir directement la palette au lieu de la |
| 73 | `E31-012` | S3 | MOYEN | Ajouter `sourceSlug` aux six appels avec les valeurs deja gouvernees (`site-formulaire-<type>`, `newsletter`, `avis-client`), et creer un slug propre  |
| 74 | `G42-012` | S3 | MOYEN | Dériver la hauteur du squelette de la même constante que la ligne réelle (une variable partagée `ROW_H` utilisée par le virtualiseur ET par le squelet |
| 75 | `H44-007` | S3 | MOYEN | D'abord MESURER sans bloquer : ajouter une étape `pnpm test:coverage` non bloquante et `coverage: pcov` + `--coverage-text` côté Pest, publier les deu |
| 76 | `H46-005` | S3 | MOYEN | Reformater en un commit unique et étiqueté `style(pint)`, puis remplacer la garde « fichiers modifiés seulement » de ci.yml:425-448 par un `pint --tes |
| 77 | `H46-011` | S3 | MOYEN | Convertir la suppression globale en entrées datées et comptées de phpstan-baseline.neon (là où la garde les plafonne et les fait décroître), ou à défa |

### Les gros

| # | constat | sev | effort | piste |
|---:|---|---|---|---|
| 1 | `I48-001` | S0 | GROS | Ajouter `Route::post('/contacts', ...)->middleware('permission:companies.create')` et implementer store/update/destroy en reutilisant `App\Crm\Ingest\ |
| 2 | `A-011` | S1 | GROS | Ne pas chercher a « fermer » ce constat mais a le rendre mesurable : tenir dans le depot un inventaire des cas A-011 (le numero de cas est deja ecrit  |
| 3 | `C21-008` | S1 | GROS | Deux voies exclusives, a arbitrer avec Will avant tout code : (a) assumer le modele company-centrique et corriger le docbloc menteur de la migration + |
| 4 | `D26-002` | S1 | GROS | Commencer par le moins couteux et le plus visible : un garde-fou de sortie (`useBlocker` de TanStack Router + `beforeunload`) sur les formulaires long |
| 5 | `D28-002` | S1 | GROS | Deux gestes distincts, dans cet ordre : (1) reparer les cinq entrees du SOCLE (le `role="grid"` manquant de CompanyRow/CompaniesListPage ferme a lui s |
| 6 | `D29-001` | S1 | GROS | Ne pas tout extraire d'un coup : instrumenter d'abord (une garde qui compte les chaînes visibles hors `t()` par écran et interdit toute hausse), puis  |
| 7 | `E34-007` | S1 | GROS | Deux gestes distincts, dans cet ordre. D'abord corriger l'en-tete mensonger scripts/qualiopi/isolation-check.ts:24 (retirer « + pre-push », qui est fa |
| 8 | `I49-007` | S1 | GROS | Immediat et PETIT : ajouter `podcast_request` a `CrmSyncFamily`, a `FAMILY_LABELS` et a la liste des familles comparees. Le vrai correctif : ajouter u |
| 9 | `A05-005` | S2 | GROS | Le geste juste ici est de corriger le REFERENTIEL, pas le code : marquer le §2.5 (et les §2.2-2.4) comme non implemente, avec la table de correspondan |
| 10 | `B11-010` | S2 | GROS | Dissocier la connexion de SCHEMA de la connexion de REQUETE : faire porter le migrate/drop de RefreshDatabase par pgsql_owner (surcharge du trait dans |
| 11 | `B12-015` | S2 | GROS | Deux gestes separes. (1) Uniformiser d'abord la forme d'erreur : un unique helper d'enveloppe {error, message} et bannir les 500/erreurs deguisees en  |
| 12 | `B14-011` | S2 | GROS | Ne pas elargir EVENT_TYPES d'un bloc. Prendre une famille a la fois, en commencant par celle dont le sens inverse fonctionne deja (Rendez-vous : le si |
| 13 | `D22-006` | S2 | GROS | Faire rendre les permissions effectives par /auth/me, les mettre dans un contexte React unique, exposer un `usePermission('x.y')` et un composant `<Si |
| 14 | `D23-002` | S2 | GROS | Deux gestes, dans cet ordre. (1) Immediat et sans backend : supprimer le repli invente de :189 (afficher « — » quand companies_new_7d est absent) — un |
| 15 | `D24-005` | S2 | GROS | Declarer `validateSearch` (schema Zod) sur `companiesRoute`, lire l'etat par `useSearch`, ecrire par `navigate({ search })` avec `replace: true` pour  |
| 16 | `D24-006` | S2 | GROS | Traiter par familles plutôt qu'écran par écran : (1) rendre les lignes des tableaux de liste cliquables vers leur fiche quand une route de détail exis |
| 17 | `D25-009` | S2 | GROS | Deux gestes séparés : (1) borner côté requête — passer `params: { per_page: 100 }` et rendre la pagination visible, ce qui protège d'emblée du cas 100 |
| 18 | `D27-004` | S2 | GROS | Traiter par type de balisage plutôt qu'écran par écran, en commençant par le plus rentable (l'en-tête de page et `CoveragePage`, qui à lui seul porte  |
| 19 | `D27-005` | S2 | GROS | Extraire d'abord la seule chose vraiment identique — l'en-tête de tableau en grille — en un `TableHeader` (ou une constante de classes exportée), ce q |
| 20 | `D28-011` | S2 | GROS | Réparer d'abord le cas nommé (`dark:text-slate-200` sur les deux `<kbd>` de `GlobalSearch.tsx:94` et `:119`), puis attaquer la cause : introduire des  |
| 21 | `D30-003` | S2 | GROS | Ne pas grossir les boîtes visibles mais leur ZONE TACTILE : ajouter à `IconButton` un pseudo-élément d'extension (`before:absolute before:-inset-…`) p |
| 22 | `E31-008` | S2 | GROS | Ajouter un type `appointment_status` a `EVENT_TYPES` + un champ de statut dans `CrmInboundPayload`, cable sur `CalendlyEvent` cote site ; et le declar |
| 23 | `E32-005` | S2 | GROS | Trancher d'abord par ecrit quelles familles migrent (le registre a deja les 17), puis, ecran par ecran, remplacer la `page.tsx` par un `redirect()` ve |
| 24 | `E34-005` | S2 | GROS | Remplacer le `skipIf` binaire par un plafond chiffre et decroissant : la suite tourne en CI et n'echoue que si le nombre de villes hors seuils depasse |
| 25 | `E34-008` | S2 | GROS | Ne rien supprimer sans un inventaire préalable : lister les modèles Prisma et tables du module mort, prouver par requête qu'ils sont vides (ou archivé |
| 26 | `H46-001` | S2 | GROS | Le cliquet existe deja (PhpstanBaselineNeGrossitPasTest + `reportUnmatchedIgnoredErrors: true`). Le geste utile est de sortir la baseline par LOTS the |
| 27 | `I49-003` | S2 | GROS | Commencer par le seul geste borne et sans prerequis : un lien permanent CRM -> console (symetrique de AdminSidebarNav.tsx:772), pose sur la barre late |
| 28 | `D30-010` | S3 | GROS | Traiter par vagues : d'abord les quatre écrans d'authentification (formulaires étroits, gain immédiat), puis les listes (ContactsListPage, UsersPage,  |

---

## 2. Ce qui demande un ARBITRAGE, et n'appartient pas à un agent

Ces 116 constats ont un correctif qui **change une sémantique**, **touche la
production**, ou **expose une décision juridique**. Les corriger sans décision
humaine reviendrait à trancher à la place de Will.

| # | constat | sev | effort | piste |
|---:|---|---|---|---|
| 1 | `B11-001` | S0 | GROS | Reproduire le patron deja pose par B11-003 sur `retention:purge` (backend/app/Console/Commands/RetentionPurge.php:73-74, 139-162) : classer d'abord le |
| 2 | `B16-002` | S0 | MOYEN | Ancre EXTERNE, qui ne touche pas au format de hachage : a chaque passage de `audit:verify-chain` (et/ou toutes les N ecritures), persister hors de la  |
| 3 | `B16-003` | S0 | GROS | Deux formes possibles. (a) Migration versionnée de format : ajouter une colonne `hash_version` à `audit_logs`, faire porter la version dans le canonic |
| 4 | `C19-007` | S0 | GROS | Deux gestes separes. (a) Rediger la mise en balance art. 6.1.f (finalite, necessite, attentes raisonnables des dirigeants publies au registre, mesures |
| 5 | `A07-004` | S1 | PETIT | Commiter le fichier dans `Axion-IA/axionia/_PLANS/` (le seul `_PLANS/` reellement suivi) ou dans `Axion-CRM-Pro/_REPORTS/`, puis corriger la ligne 42  |
| 6 | `B13-001 / A05-003` | S1 | GROS | Deux voies, non exclusives : (a) ajouter un champ SIREN facultatif aux formulaires B2B du site (unified-contact, roi-report, podcast) et le passer a ` |
| 7 | `B13-002 / I48-003` | S1 | MOYEN | Le plus sobre : sortir le bloc `pending_match` de la condition `$subjectId === null` et l'ecrire aussi quand l'entreprise est connue mais la personne  |
| 8 | `B14-003 / B14-009` | S1 | PETIT | Ajouter `outbound: { pending: number; gave_up: number }` a l'interface ligne 6-20 et une `KpiCard` « File sortante abandonnée » qui rougit des que `ga |
| 9 | `B15-014` | S1 | GROS | Trois gestes séparables : (a) écrire une commande d'alerte préalable qui liste, N jours avant, ce que les purges vont effacer (et l'informer au candid |
| 10 | `C21-003` | S1 | GROS | L'ordre est écrit dans CrmDoublonsPersonnes.php:41-50 et il n'est pas négociable : poser CRM_PERSON_KEY_SECRET et jouer crm:remplir-cle-personne (clé  |
| 11 | `C21-006` | S1 | GROS | Deux gestes distincts, et le second n'est pas de l'ingénierie : (a) jouer crm:mesure-base-legale par source et figer le chiffre pour voir s'il empire  |
| 12 | `D24-007 / I48-004` | S1 | MOYEN | Migration additive sur `activities` : `motif_id BIGINT NULL REFERENCES crm_motifs(id)` et `activite_id BIGINT NULL REFERENCES crm_activites(id)`, NULL |
| 13 | `D25-001` | S1 | GROS | Porter les 27 ecrans restants sur le patron deja ecrit : `if (query.isError) return <QueryErrorState error={query.error} />` AVANT le test `rows.lengt |
| 14 | `D26-004` | S1 | PETIT | Deux gestes distincts a ne pas confondre. Court terme, honnete : que l'API expose l'etat du transport (par exemple `{ delivered: false, reason: 'mail_ |
| 15 | `D28-001` | S1 | MOYEN | Porter la correction dans TableScroll plutot qu'en neuf copies : lui faire rendre `role="table"` (avec `aria-rowcount`/`aria-colcount` optionnels) sur |
| 16 | `E31-002` | S1 | MOYEN | Cote site : faire porter au chemin « recrutement » du formulaire unifie un texte de consentement v2 et sa version (`careers-v2-2026-08-13` ou une nouv |
| 17 | `E33-001` | S1 | GROS | Traiter les quatre points separement, par ordre de valeur : le tunnel de reservation d'abord (un rendez-vous pris est le signal commercial le plus for |
| 18 | `E34-003` | S1 | PETIT | Remplacer Dockerfile:255 par `ARG QUALIOPI_CERTIFICATION_OBTENUE` + `ENV QUALIOPI_CERTIFICATION_OBTENUE=${QUALIOPI_CERTIFICATION_OBTENUE:-false}` dans |
| 19 | `F36-007` | S1 | MOYEN | Le correctif n'est pas du code : le drapeau et le role existent deja et la barriere est prete (RolePorteurDeLaRlsTest.php:101-121 le demontre). Le ges |
| 20 | `F36-010` | S1 | GROS | Par etapes, la plus utile d'abord : implementer `update()` (attribution et retrait de role sur un compte existant) — c'est le geste qui debloque l'exp |
| 21 | `F38-001` | S1 | PETIT | Remplacer 127.0.0.1 par `${STAGING_BIND_IP:-172.17.0.1}` aux lignes 204-205 et 208-209, en laissant la ligne 215 telle quelle. Pour l'API, ne pas curl |
| 22 | `F39-001` | S1 | GROS | Deux gestes independants : (1) poser un endpoint /metrics dans le backend et ajouter postgres-exporter/redis-exporter a docker-compose.observability.y |
| 23 | `F40-006` | S1 | PETIT | Deux gestes : (1) ajouter au deploiement un `git status --porcelain --untracked-files=all -- backend/` qui ECHOUE si un fichier non suivi traine sous  |
| 24 | `F40-007` | S1 | MOYEN | Passer la ligne en `POSTGRES_PASSWORD: ${POSTGRES_PASSWORD:?mot de passe requis}` (ou avec defaut `axion_dev_only` pour ne pas casser le poste de dev  |
| 25 | `G41-005` | S1 | PETIT | Ajouter un etat `page` au composant, le mettre dans `queryKey` ET dans les `URLSearchParams`, et rendre un pied de liste « page X / meta.last_page » a |
| 26 | `G43-001` | S1 | MOYEN | D'abord annoter le §3 du rapport (« mesuré sous le rôle axion, BYPASSRLS ; ne décrit pas la configuration cible ») et le §5 pour qu'il rejoue sous `-U |
| 27 | `I49-004` | S1 | GROS | Ne pas concevoir la fédération maintenant : solder d'abord A-012 + A07-001 pour qu'un humain ouvre le CRM une première fois, rejouer le critère 23 du  |
| 28 | `A-006` | S2 | PETIT | Remplacer le tableau du §4.8 par les six sections réelles de Sidebar.tsx (Aujourd'hui · Contacts · Collecte · Pilotage · Conformité · Réglages), en da |
| 29 | `A05-002` | S2 | MOYEN | Poser l'écriture au SEUL endroit qui émet vers une fiche — l'émetteur outbound (cf. backend/tests/Feature/Crm/CrmOutboundTest.php et la table `crm_out |
| 30 | `A05-004` | S2 | MOYEN | Une commande `crm:cycle-de-vie-dormant` planifiee mensuellement, portee OBLIGATOIRE par --workspace= ou --all-workspaces (meme forme que retention:pur |
| 31 | `A06-002` | S2 | PETIT | Retitrer le §8.7 en « ligne 3 ter — PARTIELLE » et y inscrire les trois sous-criteres non tenus avec leur preuve (docker-compose.staging.yml:164,202,2 |
| 32 | `A06-004` | S2 | GROS | Deux temps. D'abord retirer `audit_logs` de `ABSENTES_PAR_CONSTRUCTION` pour l'inscrire en `DEFAUTS_CONNUS` avec sa date, afin que la dérogation se pé |
| 33 | `A06-010` | S2 | PETIT | Relire le fichier de bout en bout pour en retirer tout secret, puis le committer dans un dépôt PRIVÉ (le plus naturel : le dépôt du chantier qu'il pil |
| 34 | `A06-012` | S2 | PETIT | Dans `compareFamily`, ajouter `status: true` au `select` et distinguer trois compteurs au lieu de deux : `delivered` (status = sent), `stuck` (pending |
| 35 | `A07-005` | S2 | PETIT | Constater d'abord EN PRODUCTION les trois requêtes de la « condition d'entrée » du rapport (deux comptages à 0, et les deux contraintes `*_empreinte_o |
| 36 | `A07-007` | S2 | PETIT | Ajouter `GovernedTagsSeeder::class` dans DatabaseSeeder apres `OwnerUserSeeder` (qui cree l'espace), et — pour couvrir la production — repliquer le pa |
| 37 | `A08-002` | S2 | MOYEN | Envelopper le corps de `handle()` de chaque commande d'ecriture planifiee dans `WorkspaceContext::run($workspaceId, fn () => ...)` sur le modele de Sc |
| 38 | `A08-004` | S2 | GROS | Trancher explicitement par une migration NEUVE (l'ancienne ne rejouera pas) : soit `CREATE EXTENSION pg_partman` + partitionnement, soit `ENABLE`+`FOR |
| 39 | `A09-007` | S2 | PETIT | Remplacer les quatre occurrences de `C:/Users/willi/Documents/Projets/crmpro-wt-etape0/` par le chemin du depot principal (ou, mieux, par un chemin re |
| 40 | `A09-008` | S2 | PETIT | Remplacer les quatre mentions « 30 tables » par la description du mecanisme reel (« toutes les tables portant workspace_id, hors quatre exclusions nom |
| 41 | `B10-006` | S2 | MOYEN | Poser le trait sur les modeles reellement cloisonnes (les onze hors AuditLog/User/Workspace), en traitant LlmUseCase a part (surcharge du scope tolera |
| 42 | `B10-007` | S2 | MOYEN | Une migration qui, table par table, (1) compte puis rattache ou supprime les lignes dont le workspace_id n'existe plus, en journalisant le compte, (2) |
| 43 | `B10-009` | S2 | PETIT | Une migration qui cree `UNIQUE (name, guard_name)` puis retire `permissions_name_key`, avec un CREATE UNIQUE INDEX CONCURRENTLY hors transaction si la |
| 44 | `B10-011` | S2 | PETIT | Reduire la liste `update` de l'upsert aux seules colonnes dont le referentiel est vraiment la source de verite (name, legal_note), et sortir `ttl_days |
| 45 | `B10-015` | S2 | MOYEN | Ajouter a `.github/workflows/ci.yml` un job dedie (service Postgres ephemere) qui joue `php artisan db:partman-relocaliser` puis deux `migrate:fresh - |
| 46 | `B11-007` | S2 | GROS | Trancher table par table entre trois statuts, ecrits dans une migration unique et documentes : (a) reellement scopees → ADD COLUMN workspace_id + back |
| 47 | `B12-005` | S2 | PETIT | Livrer tout de suite ->middleware('throttle:internal') sur backend/routes/api.php:393. Pour le rejeu, migrer ScraperResultController sur HmacSignature |
| 48 | `B12-008` | S2 | PETIT | Ajouter 'throttle:api' dans $middleware->api(append: […]) de backend/bootstrap/app.php:40, en portant d'abord RATE_LIMIT_PER_MINUTE a une valeur large |
| 49 | `B12-011` | S2 | MOYEN | Un fichier tests/Feature/Audiences/RoutesAudiencesTest.php qui parcourt les huit routes du groupe (200 nominal + 403 hors workspace + 404 identifiant  |
| 50 | `B12-013` | S2 | PETIT | Remplacer la boucle nue par une resolution scopee : `$ids = Company::query()->whereIn('id', $ids)->where('workspace_id', $workspaceId)->pluck('id')->a |
| 51 | `B12-018` | S2 | PETIT | Remplacer le `where('current_workspace_id', ...)` par une jointure sur `user_workspaces` (workspace_id = espace courant, `revoked_at IS NULL`), et exp |
| 52 | `B13-004` | S2 | MOYEN | Ajouter une migration `site_sync_rejections` (event_id si lisible, empreinte du corps, `error_code`, message, statut HTTP, ip, `received_at`) ecrite d |
| 53 | `B14-007` | S2 | GROS | Deux gestes separables : (a) court terme, scoper l'observabilite et l'anti-doublon en ajoutant `workspace_id` (nullable au depart) a la table, a `Cons |
| 54 | `B16-010` | S2 | PETIT | Ajouter `'user_agent' => self::normalizeText($row['user_agent'] ?? null)` dans canonical(), puis prévoir la rupture : soit un re-chaînage hors ligne d |
| 55 | `B17-004` | S2 | PETIT | Deplacer la condition dans routes/console.php sous forme de `->skip(fn () => ...)` sur chacune des trois taches, en laissant dans la commande une gard |
| 56 | `B17-013` | S2 | GROS | Prioriser par degat potentiel plutot que par nombre : d'abord les taches qui ecrivent ou suppriment (campaigns:start-scheduled, media:sync-from-compan |
| 57 | `B17-014` | S2 | MOYEN | Rendre `--all` explicitement dangereux : exiger `--force` en plus (ou `$this->confirmToProceed()` de ConfirmableTrait, que Laravel fournit déjà), ajou |
| 58 | `C18-004` | S2 | MOYEN | Cesser de recalculer le hash en PHP : faire poser la comparaison par Postgres, p. ex. `->whereRaw("normalized_hash = encode(digest(normalize_name(coal |
| 59 | `C18-018` | S2 | GROS | Trancher d'abord entre reactiver et supprimer. Si reactivation : commencer par les scrapers HTTP (http-source.ts et ses derives crunchbase/infogreffe/ |
| 60 | `C19-004` | S2 | GROS | Un service unique `RobotsTxtGate` : récupération du /robots.txt de l'hôte, cache Redis par domaine (TTL 24 h), analyse des règles User-agent/Disallow/ |
| 61 | `C19-006` | S2 | PETIT | Deux gestes distincts, à ne pas confondre : écrire une décision datée qui assume (ou refuse) le déguisement, en nommant les trois sites ci-dessus ; et |
| 62 | `C21-002` | S2 | MOYEN | Ne pas toucher à la collation. Normaliser en amont, une seule fois : refuser (ou translittérer en ASCII) toute adresse portant un caractère non-ASCII  |
| 63 | `C21-005` | S2 | MOYEN | Mesurer d'abord la distribution reelle de `quality_score` (histogramme par decile), poser les paliers sur des quantiles observes plutot que sur 90/50, |
| 64 | `C21-007` | S2 | GROS | Ne PAS commencer par une contrainte. D'abord une cle de rapprochement calculee et INDEXEE (denomination normalisee + code INSEE commune, telephone E.1 |
| 65 | `D23-009` | S2 | PETIT | Traiter ce constat avec D22-007 en un seul geste : brancher le fourre-tout via `notFoundComponent` sur layoutRoute (barre laterale conservee) plutot q |
| 66 | `D25-005` | S2 | PETIT | Reprendre littéralement le patron d'`AudienceDetailPage` : distinguer `error.response.status === 404` (garder l'`EmptyState` « introuvable ») de tout  |
| 67 | `D25-007` | S2 | GROS | Déclarer `validateSearch` (schéma Zod) sur les routes de liste les plus utilisées d'abord (`/companies`, `/contacts`, `/campaigns`), remplacer les `us |
| 68 | `D28-006` | S2 | MOYEN | Poser le titre depuis la coquille plutôt qu'écran par écran : `RootLayout` connaît déjà le fil d'Ariane (`AutoBreadcrumbs`) ; un `useEffect` qui écrit |
| 69 | `D28-008` | S2 | MOYEN | Dans `DropdownMenu` : memoriser le declencheur dans une ref, poser le focus sur le premier `menuitem` a l'ouverture, gerer Fleche haut/bas + Home/End  |
| 70 | `D28-010` | S2 | PETIT | Extraire applyTheme/lireTheme dans un module (comme lib/densite.ts) et l'appeler dans main.tsx juste avant appliquerDensite ; ou mieux, un petit scrip |
| 71 | `D29-007` | S2 | PETIT | Extraire un utilitaire unique (`formaterInstant(iso)` s'appuyant sur `Intl.DateTimeFormat` avec le fuseau du navigateur) et l'employer aux deux sites  |
| 72 | `D29-009` | S2 | PETIT | Deux gestes, cumulables : déplacer la tâche hors de la fenêtre de bascule (par ex. `->dailyAt('01:50')` ou `->dailyAt('03:20')`), et/ou lui ajouter `- |
| 73 | `D30-006` | S2 | MOYEN | Faire respecter l'accordeon aussi en mode replie (`const deplie = ouverte;` et n'afficher en replie que les icones de la section ouverte), ou l'invers |
| 74 | `E31-006` | S2 | MOYEN | Élargir la clé de comparaison : au lieu de tester l'existence d'une ligne par `subjectRef`, charger `(subjectRef, eventType)` et comparer à l'ensemble |
| 75 | `E31-009` | S2 | MOYEN | Faire dériver l'univers de l'opposition comme le fait déjà oppositionScope() (SiteSyncIngestService.php:492-504 : scope « vivier » si payload.scope=vi |
| 76 | `E31-011` | S2 | PETIT | Passer `subjectRef` dans l'appel de `crm-sync-worker.ts:150` (la ligne `emitOutboxRow` connait deja l'`outboxId`, il suffit de relire `subjectRef`). E |
| 77 | `E32-007` | S2 | GROS | Choisir UNE fiche de detail pour la candidature commerciale (la fiche candidature, pas la fiche submission), y rediriger l'autre route, et projeter le |
| 78 | `E34-004` | S2 | PETIT | Faire lire `isFacturationHubEnabled()` par `buildAdminNav()` pour ne declarer ces quatre entrees que si le drapeau est ouvert ; et enrichir `check-adm |
| 79 | `F36-009` | S2 | PETIT | Étendre backend/tests/Unit/Policies/CloisonnementBasePolicyTest.php (ou un fichier voisin) avec une table rôle × méthode : viewAny/create attendus vra |
| 80 | `F37-004` | S2 | MOYEN | Porter le bloc header d'app.localhost sur app.axion-crm-pro.com en réécrivant connect-src pour le single-origin de prod (self + les tuiles cartographi |
| 81 | `F37-007` | S2 | MOYEN | Deux serrures complémentaires : (1) restreindre le port 443 de la règle hcloud (infra/terraform/main.tf:61-63) aux plages publiées de Cloudflare, rafr |
| 82 | `F37-010` | S2 | PETIT | Générer un secret long (≥ 32 caractères aléatoires), le poser dans le .env de production ET dans la configuration Redis au même redémarrage, puis remp |
| 83 | `F37-011` | S2 | MOYEN | Passer à `POSTGRES_PASSWORD: ${POSTGRES_PASSWORD:?POSTGRES_PASSWORD requis}` dans docker-compose.yml, la valeur venant d'un .env non versionné (et d'u |
| 84 | `F39-002` | S2 | MOYEN | Ajouter au script un controle d'integrite BON MARCHE et le faire tous les jours (`gunzip -t` sur la copie rapatriee, + `zcat / tail` pour verifier le  |
| 85 | `F39-003` | S2 | MOYEN | Creer `.github/workflows/exercice-restauration.yml` avec `on: schedule` mensuel + `workflow_dispatch`, `timeout-minutes` explicite et `concurrency`, q |
| 86 | `F39-004` | S2 | PETIT | `git add --renormalize .` puis re-checkout des `infra/scripts/*.sh`, dans chaque worktree. Et poser une garde d'enumeration (sur le modele de `backend |
| 87 | `F40-013` | S2 | MOYEN | Sur le service api de docker-compose.prod.yml : `read_only: true` + `tmpfs: [/tmp]` + volumes nommés déjà existants pour storage, et un volume (ou tmp |
| 88 | `G41-009` | S2 | PETIT | Ajouter un index partiel `CREATE INDEX CONCURRENTLY idx_activities_arbitrage ON activities (workspace_id, occurred_at, id) WHERE subject_id IS NULL AN |
| 89 | `G42-006` | S2 | PETIT | Soit `sourcemap: 'hidden'` dans vite.config.ts + televersement des cartes vers Sentry a l'etape build, soit garder la generation et ajouter dans front |
| 90 | `G42-008` | S2 | GROS | Cibler d'abord les listes virtualisees (@tanstack/react-virtual est deja en place) : memoiser la ligne, et stabiliser par useCallback les rappels pass |
| 91 | `H44-002` | S2 | PETIT | Deux gestes : (a) appeler e2e.yml en `workflow_call` depuis deploy-direct-ssh.yml et l'ajouter aux `needs` du job `deploy`, sur le modele exact de `ci |
| 92 | `H44-006` | S2 | MOYEN | Sept tests de montage dans frontend/tests/screens/ sur le modele des dix existants (rendu + un assert sur l'etat vide et l'etat d'erreur) ; et traiter |
| 93 | `H45-007` | S2 | PETIT | Deux gestes : (a) refuser explicitement la desactivation — soit `throw` a la construction si la valeur configuree est <= 0, soit un plancher (`max($ma |
| 94 | `H46-002` | S2 | MOYEN | Ajouter une entree `config/` pour chaque variable encore lue par `env()` hors config (le patron est deja pose pour `services.worker_internal.hmac_secr |
| 95 | `I48-005` | S2 | GROS | Ne pas corriger : ARBITRER. Un ADR unique qui prend les quinze objets un par un et tranche « on defait / on garde et on amende la cible / on reporte » |
| 96 | `I48-008` | S2 | MOYEN | Separer les deux cas : (a) `/cold-email` et `/linkedin` — supprimer les deux routes `Route::any` et les deux stubs frontend, ou les gater derriere un  |
| 97 | `P5-35-001` | S2 | PETIT | Remplacer l'assertion par `expect($reponse->json('data'))->toHaveCount(1)` et `expect($reponse->json('degraded'))->toBeNull()` ; corriger la phrase du |
| 98 | `P5-35-002` | S2 | PETIT | Deux gestes, l'un ou l'autre : soit assumer par ecrit (« la 2FA protege la session, pas le jeton porteur ») dans le docbloc ET dans le registre, soit  |
| 99 | `P5-35-003` | S2 | PETIT | Obtenir le hachage factice par `Cache::rememberForever` clef-par-cout plutot que par une statique de processus (ou le figer en `const` avec un test qu |
| 100 | `A-004` | S3 | PETIT | Scinder en `Caddyfile.local` (blocs .localhost seuls) et `Caddyfile.prod`, et faire pointer le montage de docker-compose.yml vers le premier, l'overla |
| 101 | `A05-008` | S3 | PETIT | Poser `DB_TIMEZONE: ${DB_TIMEZONE:-Europe/Paris}` dans le service `api` de docker-compose.yml et dans horizon/scheduler, et remplacer l'en-tete du pla |
| 102 | `B10-012` | S3 | GROS | Ne rien supprimer d'abord : ecrire le classement des 37-42 tables en trois familles (a supprimer / squelette date / referentiel) dans une constante pa |
| 103 | `B11-008` | S3 | PETIT | Ajouter au-dessus de :51 un encart « ⚠️ POLICY REMPLACÉE le 2026-08-14 par 2026_08_14_000001_harden_workspace_isolation — ce bloc est historique, NE P |
| 104 | `B12-019` | S3 | PETIT | Charger `routes/channels.php` avec `config(['broadcasting.default' => 'reverb'])` dans le test, puis recuperer les deux fermetures via `Broadcast::get |
| 105 | `C18-003` | S3 | PETIT | Le geste sur : documenter les deux effets (sequences avancees, requetes DNS emises depuis l'IP de production) dans le docbloc de `DryRunRollback`. Si  |
| 106 | `C18-005` | S3 | PETIT | Encapsuler les deux `orWhere` dans un groupe fermé : `->where(function ($q) use (...) { $q->when(...)->when(...); })`, et ajouter un cas de test avec  |
| 107 | `C18-009` | S3 | MOYEN | Choisir le tiret comme forme canonique unique (c'est celle du registre `scraping_sources`, du REGISTRY Node et des files Redis), remplacer le `str_rep |
| 108 | `C18-015` | S3 | PETIT | Retirer `ttl_days` (et vraisemblablement `quota_per_day`, meme nature : budget d'exploitation) de la liste des colonnes mises a jour, avec le meme com |
| 109 | `C19-009` | S3 | PETIT | Faire lire aux deux la même source — soit `SsrfGuard::denyPrivate()` appelée par PentestSelfCheck, soit une entrée `config('crm.ssrf.deny_private')` u |
| 110 | `D23-013` | S3 | GROS | Poser d'abord le palier 0, qui ne coûte rien et ne casse rien : un drapeau `CONSOLE_RELATION_DEPRECATED` dans env.ts et un bandeau « cet écran migre v |
| 111 | `D29-011` | S3 | PETIT | Traiter les deux tables separement. `email_suppressions` : migration `ALTER COLUMN first_seen_at/last_seen_at TYPE TIMESTAMPTZ` (precision par defaut  |
| 112 | `E31-013` | S3 | MOYEN | Trancher et ecrire la decision : soit un premier usage utile (les emetteurs de candidature posent `cand-zone:<dept>`, namespace deja derivable cote CR |
| 113 | `F38-012` | S3 | PETIT | Trancher par la suppression plutôt que par l'armement : si GlitchTip n'est pas déployé et si aucune analyse dynamique n'est voulue, retirer release-tr |
| 114 | `G41-015` | S3 | MOYEN | Ne pas réécrire l'API : borner la profondeur (refuser ou plafonner `page` au-delà de N côté contrôleur, avec un message qui renvoie vers les filtres/l |
| 115 | `H44-005` | S3 | PETIT | Poser un `zz-axion-memory.ini` copié dans /usr/local/etc/php/conf.d par Dockerfile.laravel, avec une valeur explicite et commentée (ex. 512M) — et une |
| 116 | `P5-35-011` | S3 | PETIT | Dans `confirm()`, apres `confirmEnrolment()` et avant le `return`, ajouter `if ($request->hasSession()) { $request->session()->put('2fa_passed_at', no |

---

## 3. Les indécidables — il manque une mesure

- **`A07-010`** (S2) — Le constat porte sur l'ETAT de la base locale `axion_crm_test`, pas sur le depot — et cet etat n'est pas lisible dans le code. Ce que j'ai pu verifier ne tranche pas : le fichier existe et compte bien 11 tests (C:/Users/
- **`A08-005`** (S2) — L'objet du constat n'est pas dans le depot. Le depot le dit lui-meme : .github/PROTECTION-DE-BRANCHE.md:8-11 — « La protection de branche est un réglage de l'API GitHub. Aucun fichier versionné ne la décrit, aucun test n
- **`B10-014`** (S3) — Le constat porte sur des statistiques d'exécution PostgreSQL (`pg_relation_size` : 1,5 Go d'index pour 624 Mo de données ; `pg_stat_user_indexes.idx_scan = 0` sur vingt index). Rien de cela n'est lisible dans l'arbre, et
- **`B14-013`** (S1) — Le constat porte sur un ETAT DE PRODUCTION (son « ou » dit : `backend/config/crm.php:79,145,148 - environnement de production`), et la production n'est pas lisible depuis le depot — je n'ai le droit de me connecter a auc
- **`C21-009`** (S3) — Le constat porte sur des DONNEES de production (`contacts.id = 265192`, `last_name = 'BÃ¿HLER (BÃ¿HLER)'` ; 59 tags orphelins sur 217 dans `public.tags`), mesurees le 2026-08-19. Je n'ai pas le droit d'interroger la base
- **`D23-001`** (S1) — Le constat porte sur l'ETAT D'UN CONTENEUR EN MARCHE (« conteneur axion-crm-app, /srv/app/dist/assets/index-DPQz8SpC.js »), pas sur un fichier du depot. Le verifier exigerait un `docker exec` ou un `docker inspect`, que 
- **`F37-008`** (S2) — Les deux CAUSES lisibles dans le dépôt sont fermées, mais l'OBJET du constat est un fichier de production que je ne dois ni ne peux lire. Fermé : (a) la source de PII — backend/config/telescope.php:162 `'enabled' => $tel
- **`F37-009`** (S2) — Le constat porte sur les droits observés dans le conteneur de production en exécution (/var/www/html=1777, storage=777, bootstrap/cache=777). Rien dans le dépôt ne les produit ni ne les corrige : Dockerfile.laravel:109 n
- **`F38-002`** (S1) — L'objet du constat est un reglage de l'API GitHub (`GET /repos/will383842/axion-crm-pro/branches/main/protection`), pas un fichier du depot — et je n'ai pas le droit de me connecter. Ce que j'ai pu lire : .github/PROTECT
- **`F38-010`** (S3) — La moitié vérifiable l'est : .github/workflows/ci.yml:20 porte toujours « Pint : le dépôt compte 276 fichiers non formatés », inchangée, et aucun commit ne cite F38-010. Mais l'autre moitié — « la mesure en donne 386 » —
- **`F40-009`** (S2) — L'objet du constat — la présence du paquet ufw sur le serveur de production — n'est lisible qu'en se connectant à la production, ce que le mandat interdit. Côté dépôt, les prémisses sont partiellement caduques : infra/sc
- **`F40-010`** (S2) — Le constat porte sur l'etat d'une machine (serveur 46.62.248.239, fichier /etc/docker/daemon.json) : mon mandat m'interdit toute connexion, et rien dans le depot ne peut prouver ni refuter l'existence de ce fichier. Ce q
- **`F40-011`** (S3) — Constat de mesure serveur : 961 Mo dans /var/log/axion-enrich/ (sept fichiers shard{0..6}.log figes au 2026-07-12 03:05) et 3,786 Go de cache de build Docker. Je n'ai pas le droit de me connecter, et aucun etat de disque
- **`G43-002`** (S1) — Le constat est une MESURE (pgbench, p95 1 413 ms → 5 794 ms à 10 sessions) sur une base jetable `axion_crm_g43` qui n'existe plus dans l'arbre ; aucune lecture de code ne peut la ré-établir, et l'interdit de jouer une co
- **`G43-003`** (S2) — Le constat est une MESURE (pgbench sur la base axion_crm_perf4m, 2 800 000 fiches, p95 2 192 ms -> 6 169 ms a 10 sessions — 11_GRILLES/agent-43_charge-concurrence.md ligne 5 du tableau). Le mandat m'interdit de jouer quo
