# AGENT 6 — Auditeur de l'étape 0

> **Périmètre** : les **16 lignes** du §3 de
> `C:\Users\willi\Documents\Projets\Axion-IA\_PLANS\2026-08-18_PREALABLES-AVANT-CHANTIER-CRM-CIBLE.md`,
> confrontées à leur **critère de sortie**, mesurées sur `main = e8924b8`.
> **Journal d'exécution audité** :
> `C:\Users\willi\Documents\Projets\Axion-IA\_SESSIONS\2026-08-18_PREALABLES-CRM-ETAPE-0.md` (§4, §8).
> **Preuves brutes** : `_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/agent-06/`.

---

## 0. La référence — mesurée, et le mandat rectifié

🔴 **Le mandat d'audit affirmait « l'étape 0 n'est pas sur `main` ». C'est FAUX, et mesuré :**

| Fait | Commande | Résultat |
|---|---|---|
| `main` au moment de l'audit | `git rev-parse HEAD` | `e8924b81ad64c0b236acd99ac5cbac4cd68eada7` |
| PR #174 (étape 0 CRM) | `gh pr view 174` | **MERGED** le 2026-08-18 18:44:41Z, commit de fusion `e577828` |
| `e577828` ancêtre de `main` ? | `git merge-base --is-ancestor e577828 HEAD` | **OUI** |
| Les 16 commits d'étape 0 | idem, un par un | **16/16 ANCÊTRES de `main`** |
| Avancement depuis | `git log e577828..HEAD` | **41 commits** |

Rien de l'étape 0 n'existe « seulement sur une branche ». La fusion n'a rien produit
d'inattendu : les 16 commits sont sur `main`, et trois rouges de CI corrigés en route
(`c4323be`, `702253c`) le sont aussi.

**La CI a réellement tourné sur cette référence.** `ci.yml` est déclenché en
`workflow_call` par `deploy-direct-ssh.yml` : run `32241133570` sur `e8924b8`, job
`Backend Laravel` **success** → `Tests: 780 passed (6503 assertions)`, **0 ignoré**,
job `Frontend` **success** → `21 fichiers / 118 tests`. Toutes les gardes d'étape 0
y apparaissent en `PASS` (preuve : `04_PREUVES/agent-06/ci_backend_e8924b8.log`).

**Méthode.** Travail documentaire, `git`, `gh`, lecture du code, et **relecture des
journaux de CI de la référence**. Conformément à A-009, la pile locale
(`axion-crm-api`) n'a **pas** été utilisée : une sortie d'une instance précédente
(`04_PREUVES/agent-06/ligne-07-routes.txt`) montre `000` sur les cinq sondes HTTP —
mesure impossible, non concluante, **et donc non utilisée pour conclure**.

---

## 1. La grille des 16 lignes

Légende du verdict : **CLOS** = le critère de sortie est atteint et re-mesuré ·
**PARTIEL** = une partie du critère est atteinte, une partie ne l'est pas ·
**OUVERT** = le critère n'est pas atteint · **BLOQUÉ-ACCÈS** = non mesurable ici.

| # | Ce que le plan exige | Critère de sortie (verbatim, abrégé) | Commande jouée | Résultat mesuré | Verdict | L'objet mesuré est-il le bon ? | Preuve |
|---|---|---|---|---|---|---|---|
| **1** | F4 — surcouche locale, SPA + API sous une origine | « console tourne en local, connexion, **2FA**, **tous** les écrans v2, sans `NODE_TLS_REJECT_UNAUTHORIZED=0` ; **documenté dans le runbook** » | `git ls-files`, lecture `console-locale.spec.ts`, `grep 'playwright test' .github/workflows/` | `docker-compose.local.yml` + `_REPORTS/RUNBOOK-CONSOLE-LOCALE.md` sur `main` ; spec réelle (login + TOTP RFC 6238 calculé, 33 captures versionnées, garde TLS l.285) ; **mais `console-locale.spec.ts` n'est lancé par AUCUN workflow** (2 specs sur 16 tournent : `a11y` + `navigation`) et le runbook démarre la pile depuis `.../crmpro-wt-etape0/...` (l. 41, 331, 344, 575), worktree **résiduel** | **PARTIEL** | 🟡 **Oui pour le geste du 18/08, non pour l'entretien.** Le critère porte sur un état (« la console tourne »), la preuve est un geste unique, non rejouable depuis `main`. | `01_mesures.txt` §L1 |
| **2** | F10 — base locale reconstructible | « `migrate:fresh` réussit **de zéro**, y compris `pg_partman` ; **joué deux fois de suite** » | `php artisan migrate:fresh` ×2 (base jetable, 19/08) | **PASSE 1 → `EXIT1=0`, PASSE 2 → `EXIT2=0`** ; 58 migrations | **CLOS** | 🟡 Oui pour la mesure du 19/08. **Non pour la garde CI** : `ReconstructionBaseTest` **ne joue pas `migrate:fresh`** — il lance `migrate` puis interroge `pg_depend` pour vérifier qu'aucune table d'extension n'est sur le chemin du `DROP … CASCADE`. C'est un **proxy** de la cause, pas l'effet. Et la CI part d'un conteneur vierge : **le cas historique (2ᵉ reconstruction sur base déjà migrée) n'est joué nulle part automatiquement.** | `ligne-02-migrate-fresh.txt` |
| **3** | F1 — harnais de tests d'interface | « un test de **rendu** ET un test de **parcours** par écran existant (**≈ 20 écrans**) ; **CI rougit sur un écran cassé** (preuve : casser, voir le rouge, réparer) » | `git ls-files frontend/tests/screens/`, comptage `routeTree.tsx`, log CI | Socle réel (MSW, `renderScreen`, `passWithNoTests` implicite) ; motif « rendu + parcours » correctement appliqué ; **118 tests verts en CI** ; mais **6 écrans de route sur 37** — le README du lot l'écrit lui-même : « **31 sur 37** restant à couvrir ». Aucune preuve archivée du témoin rouge. Seuils de couverture **décoratifs** (`vitest.config.ts:45`, la CI lance `pnpm test`, jamais `test:coverage`) | **PARTIEL** | ✅ Oui — le lot mesure exactement ce qu'il prétend, et le dit. C'est la **clôture** qui est fausse : le critère demandait ≈ 20 écrans, il y en a 6. | `ligne-03-vitest.txt`, `ci_frontend_e8924b8.txt` |
| **3 bis** | F17 — ranger la navigation | « la barre **correspond aux groupes cibles du CDC §23.3** (+ Collecte) ; aucune entrée ne mène à un cadenas ; test de rendu de la barre ; **captures avant / après archivées** » | Lecture `Sidebar.tsx`, `git show --stat da97826`, `a11y.yml` | **6 sections** (Aujourd'hui · Contacts · Collecte · Pilotage · Conformité · Réglages) ✅ · **une seule** entrée « Contacts » ✅ · **aucun cadenas** ✅ · `navigation.spec.ts` **branché et BLOQUANT** dans `a11y.yml` ✅ · `SidebarAccordeon.test.tsx` ✅ · **AUCUNE capture dans `da97826`** (9 fichiers, 0 `.png`) ❌ · **conformité §23.3 non atteinte** (A-006 : groupe ÉCHANGES entier, Boîte de réception, Mes rendez-vous, Mes tâches, vues épinglées, Organisations, Prospection, Canal, Coûts, ↗ Console axionia, Fiches récentes, **tous les compteurs**) ❌ | **PARTIEL** | ✅ Oui sur ce qui est mesuré (la structure de la barre). Le critère comportait deux exigences que rien ne mesure : la **conformité §23.3** et les **captures**. | `01_mesures.txt` §L3bis |
| **3 ter** | F18 — configurer le mailer du CRM | « **un e-mail de test part de préproduction et arrive** ; un rebond simulé crée une ligne de suppression ; **`MAIL_MAILER` n'est plus `log` en préproduction ni en production** ; aucune fonctionnalité d'envoi exposée » | `grep -rn MAIL_MAILER backend/config/mail.php docker-compose.staging.yml`, journal §8.7 | `config/mail.php:4` → défaut `log` ; **`docker-compose.staging.yml:130,156,177` → `MAIL_MAILER: log`** sur les trois services de **préproduction** ; journal §8.7 : « **`MAIL_MAILER` reste `log` : rien n'envoie** » en production. Seul le **volet entrant** est fait (webhook `/api/internal/email/zeptomail`, 8 tests `PASS` en CI) | **OUVERT** | ✅ Oui : le webhook mesure bien le webhook. Mais **3 des 4 sous-critères sont contredits par le dépôt**, et la ligne est déclarée « **CLOSE en production** » (journal §8.7). Voir **A06-002**. | `01_mesures.txt` §L3ter |
| **4** | F2 — tests de la collecte | « Dédoublonnage, liste d'exclusion, journal, arrêt d'urgence, registre des sources couverts ; **chacun vu rouge puis vert** » | `git ls-files workers/tests`, `grep SIGTERM src/`, `git ls-files backend/tests` | 6 fichiers workers (`base-worker`, `extract`, `registre-sources`, `result-sender`, `ssrf-guard`, `ssrf-guard-dns`), **61/61** ; `passWithNoTests: false` (une suite vide ROUGIT) ; lint `--max-warnings 0` ; **arrêt gracieux réellement implémenté** (`scrapers/base.ts:250-251`, `process.once('SIGTERM'/'SIGINT')`) **et testé** (`describe('arrêt gracieux')`, dont « un job tiré ALORS QUE l'arrêt est demandé est REMIS en file ») ; dédoublonnage / exclusion / journal couverts **côté Laravel** (`DeduplicationServiceTest`, `OptOutTest`, `ScraperRunsCancelRetryTest`) | **CLOS** (réserve) | ✅ Oui. Réserve : les 3 mécanismes Laravel **préexistaient** — « chacun vu rouge puis vert » n'a pas de preuve archivée pour eux. Le lot a corrigé la vraie cécité (`--passWithNoTests`), ce que le critère ne demandait pas. | `01_mesures.txt` §L4 |
| **5** | Non-régression sur ce qui a été réparé | « suite « ne-doit-pas-régresser » qui rejoue : isolation sans contexte → 0 ligne ; **restauration d'un dump** ; horodatage UTC ; export RGPD par empreinte » | Lecture `NeDoitPasRegresserTest.php`, log CI `e8924b8` | Les **4 acquis** présents. `ACQUIS 2` exécute un **vrai** `pg_dump → psql` (options exactes de `backup-postgres.sh`) sur base jetable, vérifie tables + **données** + `pg_policies` + `relforcerowsecurity` : **`✓ ACQUIS 2 … 2.44s`** en CI sur `e8924b8`, **non ignoré**. Témoins de charge partout. Attente **volontairement inversée** sur les GRANT | **CLOS** | 🟡 **Une réserve d'objet.** §1 mesure la connexion `pgsql_app`. Le fichier écrit lui-même (l. 35-37, et `EtancheiteParTableTest` l. 29-34) que « `CRM_DB_APP_ROLE_ENABLED` … **sa valeur en production** » vaut `false` — **ce que B11-010 contredit** (`true` en production). Une des deux affirmations est fausse ; **je n'ai pas l'accès pour trancher**. Voir A06-009. | `ligne-05-ci-acquis2.txt` |
| **6** | F3 — dégonfler la baseline PHPStan | « **Zéro entrée** de baseline sur `Crm/*`, modèles `Contact/Candidate/Company/Activity/Tag`, contrôleurs console ; règle CI : la baseline **ne peut plus grossir** » | `grep -cP '^\t{3}message: '`, somme des `count:`, `grep reportUnmatchedIgnoredErrors`, log CI | **211 entrées / 248 erreurs / 1 321 lignes** ; en-tête concordant ; `phpstan.neon:13` **level 8**, `:27` **`reportUnmatchedIgnoredErrors: true`** ; garde `PhpstanBaselineNeGrossitPasTest` (5 tests dont un **anti-vacuité** et un qui **throw** si le format change) → **`PASS` en CI** ; les 7 chemins interdits existent (sauf `Activity.php`, absence **délibérée et documentée**) ; contrôleurs console bien sous `app/Http/Controllers/Api/Crm/` | **CLOS** | 🟡 Oui, avec une **lacune de périmètre** : la liste interdite **omet `app/Services/Audiences/AudienceBuilderService.php` — 9 entrées de baseline** — alors que c'est le service que la **ligne 10 de l'étape 0 a elle-même** touché, et que le chantier touchera. Voir **A06-006**. | `01_mesures.txt` §L6 |
| **7** | F7 — retirer les stubs 501 `/crm`, `/analytics` | « **Aucune route 501** sous ces préfixes ; test » | Lecture `PasDeStub501SousCrmEtAnalyticsTest.php`, log CI | Garde à **trois volets** : (A) statique — aucun contrôleur `Api\Phase2\*`, aucun fourre-tout `{any?}` ; (B) **témoin anti-vacuité** — les 6 routes réelles de la console v2 sous `/crm` sont exigées, sinon « tout supprimer » ferait passer A ; (C) **dynamique** — appel HTTP réel, 5 verbes, plus « sous-chemin inexistant → **404, pas 501** ». `PASS` en CI. `/cold-email` et `/linkedin` **constatés 501 à dessein** par un cas dédié (arbitrage D-008, A-005) | **CLOS** | ✅ **Oui, et c'est la meilleure garde de l'étape 0.** Elle mesure la route servie, pas le texte de `routes/api.php` ; elle a un témoin négatif ; elle épingle le choix assumé pour qu'on ne le « nettoie » pas. | `pest-etape0-v3.txt`, `ci_backend_e8924b8.log` |
| **8** | F8 — étanchéité **par table** | « **Pour CHAQUE table à `workspace_id`** : sans contexte → 0 ligne ; contexte A → lignes de A seulement ; le test **rougit si on retire FORCE RLS** » | Lecture `EtancheiteParTableTest.php` + `Tests\Support\EtancheiteWorkspace`, log CI | Inventaire **calculé** (`pg_class` × `pg_attribute`), ≥ 50 tables, semeur exhaustif gardé, **deux témoins réels** (RLS retirée → la sonde change d'avis ; FORCE sur un propriétaire non-superutilisateur). `PASS` en CI. **MAIS le périmètre réellement contrôlé est `tablesScopees` MOINS `DEFAUTS_CONNUS`** : `email_verification_logs` **FUIT — mesuré** (policy `email_verif_workspace_isolation` en `COALESCE`, ratée par le `DROP` parce que son nom est raccourci) et est **épinglée hors contrôle** ; `audit_logs` (**journal d'audit RGPD**) est écartée parce que `relkind='p'` et que **le scan ne retient que `relkind='r'`** ; la boucle FORCE porte le même filtre | **PARTIEL** | 🔴 **Non sur le mot « chaque ».** L'objet mesuré est « les tables que le scan retient », pas « les tables portant `workspace_id` ». L'exclusion d'`audit_logs` est **la cécité du scan promue en décision** (« absente par construction »). Voir **A06-004**, et B11-006 qui le confirme indépendamment. | `01_mesures.txt` §L8 |
| **9** | F13 — traiter les 20 Dependabot | « **0 PR Dependabot ouverte**, **OU** politique écrite “figé jusqu'à fin de chantier” » | `gh pr list --author app/dependabot --state open`, lecture `.github/dependabot.yml` + politique | **0 PR ouverte**, **0 branche `dependabot/*` sur `origin`** ; politique écrite, datée, chiffrée (441 l.) ; `.github/dependabot.yml` retient **`ignore` + `update-types`** (et **refuse** `open-pull-requests-limit: 0`) précisément pour **ne pas couper le canal de sécurité**, avec la source citée et le précédent maison du 16/08 ; **aucun `groups:`** ; procédure de dégel en 6 étapes ; alertes Dependabot désormais **`enabled`** | **CLOS** | ✅ **Oui.** La garde du critère est le fichier de configuration — **et ici le fichier EST l'objet** (c'est lui que Dependabot lit). Le raisonnement sur `ignore` vs `limit` est le seul de l'étape 0 qui a explicitement cherché le faux-ami. | `ligne-09-dependabot.txt` |
| **10** | F6 — appliquer les deux arbitrages | « `not_in` sur NULL défini et testé ; **colonne e-mail en clair d'`opt_out` SUPPRIMÉE** après migration vers l'empreinte ; test d'anti-réinsertion toujours vert » | `git show --stat dd91665`, `grep opt_out backend/database/migrations/` | Volet 1 **fait** : `EligibiliteCampagne.php`, `ListeSuppression.php`, `SymetrieEvaluateursTest` (342 l.), `EmpreinteSqlEtPhpTest`, `OppositionEmpreinteSeuleTest` (410 l.). Volet 2 **NON fait** : la migration `2026_08_18_000001` **remplit** `email_hash`, elle ne **supprime rien** ; aucun `DROP COLUMN opt_out.email` sur `main` ; le report est écrit et daté (`_REPORTS/2026-08-18_OPT-OUT-DROP-COLUMN-TEMPS-2.md`, décision 3 : « en DEUX temps », conditionné à `count(*) where email_hash is null = 0` en production) | **PARTIEL** | ✅ Oui sur les deux volets mesurés. Le second sous-critère est **explicitement reporté par arbitrage**, pas oublié — mais la ligne est cochée ✅ dans le journal §4 sans mentionner le report. | `01_mesures.txt` §L10 |
| **11** | F5 — mesure de performance de référence | « jeu de données au volume du CDC (50 000 fiches, 5 ans) ; **p95 mesuré et conservé dans le dépôt** pour liste, recherche, timeline, export ; c'est **le témoin de tous les critères du §29**» | Lecture `_REPORTS/2026-08-18_MESURE-PERFORMANCE-REFERENCE.md` + `grep -nE '^CMD' Dockerfile.laravel` | Témoin **réel et conservé** : 300 000 entreprises / 50 000 actives / 500 000 activités, p50+p95 sur **10 endpoints × 15 itérations**, `EXPLAIN ANALYZE`, scripts rejouables `backend/database/perf/`. Deux points chauds nommés (compteurs en Seq Scan ≈ 3 s sur la prod ; export ≈ 3,4 ms/ligne). **MAIS** l.16 : mesure « **séquentielle**, un seul utilisateur » ; l.19 : « **sur la production (Linux, php-fpm + opcache)**, la ligne de base est de l'ordre de **quelques dizaines de millisecondes** » — or **`Dockerfile.laravel:121`, cible `prod` : `CMD ["php","-S","0.0.0.0:80","-t","public"]`**. La production **n'a pas** php-fpm | **PARTIEL** | 🔴 **Non sur la conclusion.** Le rapport mesure **la base**, puis soustrait une « ligne de base » qu'il impute au montage Windows, en projetant une production **php-fpm + opcache qui n'existe pas**. A-010 mesure la conséquence : requêtes sérialisées, compteurs du hub à **17,5 s cache froid**. Le rapport dit lui-même que le critère §29 n° 17 est « impossible sur un serveur mono-thread » **sans voir que la production en est un**. Voir **A06-003**. | `01_mesures.txt` §L11 |
| **12** | F9 — éprouver le canal (B.10→B.12, 4 pannes, parité) | « Preuves archivées ; **écart de parité = 0 ou expliqué ligne à ligne**. **Si un point de capture est muet, c'est le premier bug du chantier** » | Lecture de `axionia/src/server/crm-sync/reconcile.ts` sur `main = eb754332` (§1 bis) | La réconciliation lit bien les **tables métier** (l. 171, 192, 232, 245, 258) et cherche ce qui manque dans l'outbox (l. 311-317) : **elle n'est PAS aveugle**, l'hypothèse la plus grave est réfutée. Mais : (a) le `findMany` l. 311 **ne filtre aucun statut** — une ligne `gave_up` compte comme émise ; (b) `podcast_request` **émet et n'est pas réconciliée** (6 familles émettrices, 5 réconciliées) ; (c) **B.10 → B.12 et les 4 pannes non joués** ; (d) parité annoncée sur **3 événements en 7 jours** | **PARTIEL** | 🔴 **Non sur le point (a).** Le critère compare la source aux « événements **reçus par le CRM** » ; la mesure s'arrête à **l'outbox**. Une livraison définitivement échouée affiche `missing: 0`. Le versant livraison existe (`health.ts`) mais **les deux mesures ne sont jamais jointes**. Voir **A06-012**. | §1 bis |
| **13** | 3 défauts du site (F14 chatbot PII · F15 export CSV · F16 Calendly) | « un **no-show déclaré dans Calendly arrive dans le CRM sans geste** ; l'export rend **N lignes pour N soumissions** ; le chatbot (même éteint) écrit chiffré — **trois tests** » | `git merge-base --is-ancestor` ×4, `gh pr view 735`, lecture de `capturer-lead.ts`, `actions.ts`, `enrich.ts`, `refresh.ts` sur `eb754332` (§1 bis) | 4 commits **ancêtres de `main`** ; PR #735 **MERGED** 18/08 20:26:28Z. PII **chiffrées** (l. 127-130) · export **par curseur**, plafond 50 000 **bruyant sur 3 canaux** · `enrich.ts:45` importe enfin `@/server/crm-sync`, `no_show` émis (l. 235-248), sondage 10 min + fenêtre **48 h** · **27 `it()`**, aucun ne pré-insère son résultat | **CLOS** | ✅ **Oui, et remarquablement.** Le test du chatbot inspecte la **valeur écrite** et balaie tout le payload à la recherche de clair ; le test d'export exerce la **vraie Server Action** avec une pagination honorée. Réserves : no-show coché à **> 48 h** jamais rattrapé ; le test Calendly **mocke** `@/server/crm-sync` (pas de bout-en-bout jusqu'à l'outbox). | §1 bis |
| **14** | F11 — AIPD refaite · F12 — état réel du pare-feu | « **Document daté dans `_REPORTS`** ; **`ufw status` et `fail2ban` dans le runbook** » | Lecture `_REPORTS/AIPD_2026-08-18.md`, `_REPORTS/2026-08-18_ETAT-PARE-FEU.md`, `grep -rn 'ufw\|fail2ban' _REPORTS/RUNBOOK-CONSOLE-LOCALE.md` | **AIPD** : existe, datée, v2.0, remplace la DPIA obsolète — mais en-tête l.8 : « 🔴 **PROJET — non validé.** Rédigé par un agent, **à valider par Will** ». **Toujours non validée au 19/08.** **PARE-FEU** : le document porte en tête « 🔴 **CE DOCUMENT NE CONSTATE RIEN SUR LE SERVEUR** », son **§5 « CONSTAT MESURÉ » est VIDE**, et son §7.1 écrit : « **tant que ce n'est pas fait, F12 n'est PAS soldé** ». `ufw` / `fail2ban` : **0 occurrence** dans `RUNBOOK-CONSOLE-LOCALE.md` ; le §6 du rapport dit lui-même que l'entrée de runbook est « **hors périmètre d'écriture de cet agent** » et reste **à écrire** | **OUVERT** | 🔴 **Le mandat d'audit se trompe sur ce document** — voir **A06-001**. Il ne concluait **pas** « le pare-feu est en ordre » : il désignait la faille (§2.1, §2.2, §2.3), donnait la commande qui la prouve (§4.3, §4.4) et la procédure de fermeture (§4.6). **La ligne a été déclarée ✅ close par-dessus un document qui déclarait le contraire.** | `ligne-14-pare-feu.txt` |

---

## 1 bis. Lignes 12 et 13 — mesuré sur le dépôt du site

Dépôt : `C:\Users\willi\Documents\Projets\Axion-IA\axionia`, **`main = eb754332`**.
Les 4 commits annoncés (`f067c059`, `1bcde6a4`, `8209abff`, `d9795e8b`) sont **tous
ancêtres de `main`** ; PR **#735 MERGED** le 2026-08-18 20:26:28Z (merge `1dadc242`) —
conforme à l'annonce, à la minute près.

### Ligne 13 — les trois défauts du site → **CLOS**

| Sous-critère | Mesure | Verdict |
|---|---|---|
| « le chatbot (même éteint) **écrit chiffré** » | `capturer-lead.ts:17` importe `encryptPii` ; l. 127-130 : `contactName/contactEmail/contactPhone` chiffrés, `contactEmailHash` sur le clair (index RGPD art. 15/17 — choix correct) | ✅ |
| « l'export rend **N lignes pour N soumissions** » | `take: 5000` **supprimé**, remplacé par une pagination **par curseur** (`actions.ts:585-609`, `TAILLE_PAGE_EXPORT=500`). Plafond dur relevé à **50 000** et rendu **bruyant sur trois canaux** : ligne d'avertissement **dans le fichier CSV** (l. 622), `Sentry.captureMessage` (l. 623), journal RGPD `changes.tronque` (l. 635) | ✅ |
| « un **no-show déclaré dans Calendly arrive dans le CRM sans geste** » | `enrich.ts:45` importe enfin `@/server/crm-sync` — **le trou nommé au journal est bouché** ; l. 235-248 émettent `canceled`/`no_show` vers l'outbox. Chemin nominal : sondage BullMQ toutes les 10 min (`queues.ts:999`) + fenêtre de rattrapage portée de **2 h à 48 h** (`refresh.ts:56`) | ✅ |
| « **trois tests** » | **27 `it()`** ajoutés (4 chatbot, 10 export, 13 Calendly). **Aucun ne pré-insère ce qu'il doit produire** | ✅ |

**Les trois gardes mesurent le bon objet**, ce qui est notable :
- le test du chatbot inspecte la **valeur du payload passé à `submission.create`**, vérifie
  le préfixe `enc:v1:`, **balaie tout le payload sérialisé** à la recherche de résidu en
  clair (c'est cela qui attraperait un champ oublié) et vérifie la réversibilité — il ne
  se contente **pas** de vérifier que `encryptPii` a été appelée ;
- le test d'export exerce la **vraie Server Action** avec un `findMany` qui **honore
  réellement `take`/`cursor`/`skip`**, et assère `toContain("sub-006999")` en plus du
  compte — le compte seul ne dirait pas qu'on n'a pas sauté une ligne au milieu.

**Deux réserves, nommées :** (a) un no-show coché **plus de 48 h** après le créneau n'est
jamais rattrapé — la ligne quitte la fenêtre ; (b) le test Calendly **mocke
`@/server/crm-sync`** : aucun test ne parcourt la chaîne jusqu'à `crm_sync_outbox`.

🔴 **Et un écart franc entre le journal et le code** : le journal §2 affirme que
`completed` / `no_show` sont « **hors de portée de l'API Calendly par construction** ».
**C'est faux** — `api.ts:290` lit `noShow: Boolean(invitee["no_show"])` et `enrich.ts:34-35`
écrit noir sur blanc « Contrairement à ce qu'on a longtemps écrit ici, l'API le sait ». Le
correctif est bon ; c'est le **diagnostic consigné** qui est à corriger (constat **A06-011**).

### Ligne 12 — éprouver le canal → **PARTIEL**

🟢 **L'hypothèse la plus grave a été RÉFUTÉE par la mesure.** Je soupçonnais la
réconciliation de comparer `crm_sync_outbox` au CRM — auquel cas un point de capture muet
aurait produit un écart de **zéro** et le critère « si un point de capture est muet, c'est
le premier bug » aurait été inatteignable par construction. **Ce n'est pas le cas** :
`reconcile.ts` interroge les **tables métier** (l. 171 `submission`, 192 `jobApplication`,
232 `calendlyEvent`, 245 `newsletterSubscriber`, 258 `customerReview`), construit les
`subject_ref` attendus (l. 293) et cherche ce qui **manque** dans l'outbox (l. 311-317).
Le sens de la comparaison est le bon, et les formats de `subject_ref` ont été vérifiés
identiques à ceux des 16 sites d'émission.

**Trois angles morts réels subsistent :**

1. 🔴 **La réconciliation s'arrête à l'outbox, pas au CRM.** Le `findMany` de `reconcile.ts:311`
   **ne filtre aucun statut** : une ligne `pending`, `failed` ou **`gave_up`** compte comme
   « émise ». Une soumission dont la livraison a **définitivement échoué** affiche donc
   `missing: 0` — parité verte, CRM vide. Or le critère verbatim compare « ce que la console
   voit » à « les événements **reçus par le CRM** ». Le versant livraison est mesuré
   séparément (`health.ts`, comptes par statut, `gave_up`) mais **les deux mesures ne sont
   jamais jointes**. C'est un cas de piège 19 — constat **A06-012**.
2. **Une famille émettrice n'est pas réconciliée du tout** : `podcast_request`
   (`podcast-request/actions.ts:111` émet `site:podcast_request:<id>`, la table existe,
   `CrmSyncFamily` ne la contient pas). **6 familles émettrices, 5 réconciliées.**
3. **B.10 → B.12 et les 4 pannes ne sont toujours pas joués** (accès `ssh` au serveur du
   site). Et la parité annoncée porte sur **3 événements en 7 jours** : un écart de 0 sur
   un échantillon de 3 ne discrimine rien.

**Épinglage** : les **14 finalités de formulaire** sont bien épinglées par un test
(`crm-sync.test.ts:162`, liste runtime pensée pour être épinglable, née d'un lead perdu sur
`simulateur_roi` → 422 → `gave_up`). En revanche les **10 types d'événements** (`CrmEventType`)
sont une **union TypeScript pure, sans tableau runtime** : rien ne peut en garder
l'exhaustivité ni la symétrie avec le CRM — **exactement le mécanisme de panne déjà
documenté pour les finalités**. Asymétrie signalée.

---

## 1 ter. Les trois numérotations concurrentes — table de correspondance

Le mandat d'audit se contredit (« 15 fragilités » l. 78 vs « F1 → F19 » ailleurs). La
mesure explique pourquoi : **il existe trois systèmes, et deux d'entre eux sont réellement
concurrents.**

| CDC v2.7 §A.1 (**15** fragilités, numérotées 1-15) | Plan §2 (**19** fragilités, F1-F19) | Ligne du plan §3 (**16** lignes) | Verdict (§1) |
|---|---|---|---|
| 1 — Interface sans aucun test | **F1** | 3 | PARTIEL |
| 2 — Collecte quasi sans test | **F2** | 4 | CLOS |
| 3 — 337 erreurs PHPStan suppressées | **F3** | 6 | CLOS |
| 4 — Console non exécutable en local (D-11) | **F4** | 1 | PARTIEL |
| 5 — Dette de dépendances (20 PR) | **F13** | 9 | CLOS |
| 6 — Performance jamais mesurée au volume | **F5** | 11 | PARTIEL |
| 7 — Deux arbitrages ouverts | **F6** | 10 | PARTIEL |
| 8 — Routes « CRM » partiellement factices | **F7** | 7 | CLOS |
| 9 — Isolation par espace durcie récemment | **F8** | 8 | PARTIEL |
| 10 — Canal prouvé, pas éprouvé | **F9** | 12 | PARTIEL |
| 11 — Base locale non reconstructible | **F10** | 2 | CLOS |
| 12 — Analyse d'impact obsolète | **F11** | 14 | OUVERT |
| 13 — Pare-feu du serveur non vérifié | **F12** | 14 | OUVERT |
| 14 — Trois défauts du site | **F14 + F15 + F16** | 13 | CLOS |
| 15 — Navigation du CRM à ranger | **F17** | 3 bis | PARTIEL |
| *(absent du CDC)* | **F18** — le CRM n'envoie aucun e-mail | 3 ter | OUVERT |
| *(absent du CDC)* | **F19** — amorce MailWizz jamais branchée | *(aucune — « à laisser dormir »)* | s.o. |

**Ce qu'il faut retenir :**

- **Le code et les messages de commit emploient la numérotation du PLAN**, de façon
  **cohérente** : `(F4)` pour la surcouche locale, `(F7)` pour les stubs 501, `(F17)` pour
  la navigation, `(F11, F12)` pour AIPD + pare-feu. Il n'y a pas de « troisième
  numérotation dans le code » : le code suit le plan.
- **La collision est entre le CDC et le plan**, et elle est **piégeuse parce qu'elle n'est
  pas monotone** : CDC n° 5 = **F13**, CDC n° 6 = **F5**, CDC n° 15 = **F17**. Un lecteur
  qui suit le CDC (§28 et le critère 17 du §29 renvoient à « §A.1 n° 6 ») et le retrouve
  dans le code sous `F5` ne peut pas s'en apercevoir sans faire ce tableau.
- Le plan a **ajouté deux fragilités** que le CDC ne connaît pas (F18 mailer, F19 MailWizz)
  et **éclaté** la n° 14 du CDC en trois (F14/F15/F16).

*(Vérification : F19 n'est pas un trou de documentation — les colonnes `mailwizzListUid` /
`mailwizzSubUid` de `prisma/schema.prisma:1909-1911` sont bien commentées et le `README.md`
du site en parle. Le critère « à documenter pour qu'un agent ne les répare pas » est tenu.)*

---

## 2. Décompte

| Verdict | Lignes | Nombre |
|---|---|---|
| **CLOS** | 2, 4, 5, 6, 7, 9, 13 | **7** |
| **PARTIEL** | 1, 3, 3 bis, 8, 10, 11, 12 | **7** |
| **OUVERT** | 3 ter, 14 | **2** |
| **BLOQUÉ-ACCÈS** | *(aucune ligne entièrement bloquée ; le volet B.10→B.12 de la ligne 12 l'est et est compté en PARTIEL)* | 0 |

**Le journal §4 annonce « 16 lignes traitées, 15 closes ». La mesure en trouve 7
closes au sens du critère de sortie de la ligne elle-même**, 7 partiellement closes
et 2 non closes.

Aucune ligne n'a été trouvée vide : **les 16 ont produit du code, des tests ou un
document réels, et 16/16 sont sur `main`**. L'écart n'est pas un écart de travail,
c'est un **écart de clôture** : le travail est presque toujours plus honnête que la
case ✅ qui le résume.

---

## 3. 🔴 Les conclusions de l'étape 0 qui mesuraient le mauvais objet

C'est le fil rouge de ce mandat. Voici ce que la relecture a trouvé, **en distinguant
trois natures très différentes** — la confusion des trois est elle-même un piège.

### 3.1 — Le cas fondateur du mandat est mal caractérisé (et c'est important)

Le mandat pose comme modèle : « `_REPORTS/2026-08-18_ETAT-PARE-FEU.md` concluait
“le pare-feu est en ordre” — il l'était, au niveau d'`ufw` ; le trou était en dessous. »

**Ce n'est pas ce que dit le document.** Lu intégralement, il :

- porte en tête « 🔴 **AVERTISSEMENT — CE DOCUMENT NE CONSTATE RIEN SUR LE SERVEUR** » ;
- **prédit la faille exacte** : §2.1 « en production, Postgres écoute sur 55432 et Redis
  sur 56379, sur toutes les interfaces » ; §2.2 « un `ufw deny` sur un port publié par
  Docker **ne bloque rien** » ; §2.3 « la base de production est joignable depuis Internet
  avec un mot de passe connu de tout lecteur du dépôt, sur un rôle superutilisateur » ;
- **donne la commande qui le prouve** (§4.4, `nc -zv <IP> 55432` depuis l'extérieur) ;
- **donne le correctif** (§4.6(b), `ports: !override` — celui appliqué le 19/08) ;
- **anticipe même la suite RGPD** (§4.4 pt 5, « évaluer l'obligation de notification CNIL
  sous 72 h ») ;
- laisse son **§5 « CONSTAT MESURÉ » VIDE**, et écrit « **tant que ce n'est pas fait, F12
  n'est PAS soldé** ».

**Le défaut n'est donc pas un défaut de niveau de mesure. C'est un défaut de clôture :
un document qui refusait de conclure a été coché ✅ dans le journal §4**, et sa procédure
§4 — cinq minutes de travail — n'a été jouée qu'après qu'une faille a été prouvée depuis
Internet, le lendemain. Corriger le modèle change ce qu'il faut chercher : non pas
« quelle garde regarde trop haut », mais **« quelle case ✅ a été posée sur un document
qui disait non »**. Constat **A06-001**.

### 3.2 — Les vraies conclusions qui mesuraient le mauvais objet

| # | Conclusion d'étape 0 | Elle est juste au niveau… | L'objet réel est… | Constat |
|---|---|---|---|---|
| 1 | **Ligne 11** : « toutes les requêtes < 250 ms, le critère §29 n° 1 est tenu **avec une marge d'un ordre de grandeur** » | …de **la base de données**, en `EXPLAIN ANALYZE`, cache chaud, **un seul utilisateur**, en soustrayant une « ligne de base » imputée au montage Windows | …**la réponse servie à un utilisateur en production**, qui passe par un `php -S` **mono-processus** (`Dockerfile.laravel:121`). Le rapport écrit l.19 « sur la production (Linux, **php-fpm + opcache**) » — la production n'a pas php-fpm. A-010 mesure la conséquence : compteurs du hub à **17,5 s cache froid**, requêtes sérialisées | **A06-003** |
| 2 | **Ligne 8** : « étanchéité **par table**, pour chaque table à `workspace_id` — CLOS » | …des **tables que le scan retient** (`relkind='r'`, hors partitions, hors défauts épinglés) : sur ce périmètre, la mesure est irréprochable et a deux témoins réels | …de **toute table portant `workspace_id`**. `audit_logs` — le **journal d'audit RGPD** — en est écartée parce que le scan filtre `relkind='r'` : **la cécité du scan a été promue en décision** (« absente par construction »). Et `email_verification_logs` **fuit, mesuré**, et est épinglée hors contrôle. Confirmé indépendamment par **B11-006** | **A06-004** |
| 3 | **Ligne 2** : « base locale reconstructible — CLOS », gardé par `ReconstructionBaseTest` | …du **catalogue Postgres** : aucune table d'extension sur le chemin du `DROP … CASCADE` global. C'est exactement la **cause** de la panne de juillet | …de **`migrate:fresh` joué deux fois de suite**, ce que le critère demande textuellement. La garde ne le joue pas, et **la CI part toujours d'un conteneur Postgres vierge** : le cas qui cassait — la **deuxième** reconstruction sur une base déjà migrée — n'est joué par **aucune** exécution automatique | **A06-005** |
| 4 | **Ligne 1** : « la console tourne en local, tous les écrans v2 ouverts — CLOS » | …du **geste du 18/08** : un vrai navigateur, un vrai TOTP, 33 captures versionnées, une garde TLS | …d'un **état durable**. `console-locale.spec.ts` n'est exécuté par **aucun workflow** (2 specs sur 16 tournent — cohérent avec H44-001) et le runbook fait démarrer la pile depuis `crmpro-wt-etape0`, worktree **résiduel**. La preuve du critère **n'est pas rejouable depuis `main`** | **A06-007** |
| 5 | **Ligne 6** : « zéro entrée de baseline sur tout module que le chantier touchera » | …des **7 chemins écrits dans `BASELINE_CHEMINS_INTERDITS`** — et là, c'est vrai et gardé | …des **modules que le chantier touchera**. `app/Services/Audiences/AudienceBuilderService.php` porte **9 entrées** de baseline et n'est pas dans la liste — alors que **la ligne 10 de l'étape 0 elle-même** a modifié la sémantique `neq`/`not_in` de ce service, et que c'est lui qui décide **à qui part un e-mail** | **A06-006** |
| 6 | **Ligne 12** : « parité de capture : **3 → 3 → 0 manquante** sur 7 jours » | …de **l'outbox du site** : ce qui est né dans une table métier a bien produit une ligne dans `crm_sync_outbox`. La comparaison est dans le bon sens, et c'est une bonne surprise (§1 bis) | …des **événements REÇUS PAR LE CRM**, ce que le critère écrit. `reconcile.ts:311` ne filtre **aucun statut** : `pending`, `failed` et **`gave_up`** comptent comme « émis ». Une livraison définitivement abandonnée donne `missing: 0` — **parité verte, CRM vide**. Le versant livraison est mesuré par `health.ts` et **jamais joint** à celui-ci | **A06-012** |

### 3.3 — Ce que j'ai passé au crible et qui **ne** relève **pas** du piège

Règle 3 du dossier : un « rien trouvé » ne vaut que si le contrôle sait trouver. Les
gardes suivantes ont été lues ligne à ligne **en cherchant le mauvais objet**, et n'en
portent pas :

- **`PasDeStub501SousCrmEtAnalyticsTest`** (ligne 7) — mesure la **route servie**
  (`Route::getRoutes()` + appel HTTP réel sur 5 verbes), pas le texte de `routes/api.php` ;
  porte un **témoin anti-vacuité** explicite ; et **constate** le choix assumé sur
  `/cold-email` / `/linkedin` pour qu'on ne le « nettoie » pas par erreur.
- **`.github/dependabot.yml`** (ligne 9) — le fichier de configuration **est** l'objet
  (c'est lui que Dependabot lit) ; et c'est le seul endroit de l'étape 0 où quelqu'un a
  explicitement cherché le faux-ami (`open-pull-requests-limit: 0` **écarté** parce que le
  dépôt a une expérience contraire à la doc GitHub).
- **`NeDoitPasRegresserTest` ACQUIS 2** (ligne 5) — exécute un **vrai** `pg_dump | gzip` puis
  `gunzip | psql --single-transaction` avec les options exactes de production, et vérifie
  les **données** et les **policies**, pas seulement l'absence d'erreur. Le `skip` est
  nommé et n'a **pas** eu lieu en CI sur `e8924b8` (`✓ ACQUIS 2 … 2.44s`).
- **`EtancheiteParTableTest` témoins** — deux témoins qui font réellement changer d'avis
  la sonde (RLS désactivée → 2 lignes visibles ; FORCE posé sur un propriétaire non
  superutilisateur → 0). C'est le **périmètre** qui pose problème (§3.2 n° 2), pas la sonde.
- **`PhpstanBaselineNeGrossitPasTest`** — `throw` si le format du fichier change (un
  parseur devenu muet **rougit** au lieu de rendre 0 entrée), et un test « le parseur lit
  vraiment des entrées ». C'est la **liste de chemins** qui est courte (§3.2 n° 5).

### 3.4 — Le motif de fond

**L'étape 0 a produit des artefacts d'une honnêteté inhabituelle** : le rapport pare-feu
refuse de conclure, le README du harnais écrit « 31 écrans sur 37 restent », l'AIPD se
déclare « non validée », `vitest.config.ts` documente que ses propres seuils sont
« DÉCORATIFS », `EtancheiteWorkspace` écrit noir sur blanc que `audit_logs` est écartée
par **cécité du scan**, et `EtancheiteParTableTest` prévient qu'il « prouve que la barrière
EST prête, pas qu'elle est ARMÉE ».

**Ce qui n'est pas honnête, c'est le tableau §4 du journal** : quinze ✅ posés sur des
lots dont plusieurs contiennent, à l'intérieur, l'aveu qu'ils ne satisfont pas leur
critère. Le risque pour l'étape 1 n'est donc pas que le travail soit mauvais — il ne
l'est pas — mais **qu'on lise le journal plutôt que les lots** : c'est déjà arrivé
(la ligne 14 a été cochée, la faille a été prouvée depuis Internet le lendemain).

---

## 4. Constats

### [A06-001] Le rapport d'état du pare-feu désignait la faille du 19/08 ; la ligne 14 a été déclarée close par-dessus
- Sévérité      : **S1**
- Domaine       : conformité / sécurité / méthode
- Référence     : main `e8924b8` ; document introduit par `9e81b8a` (PR #174, fusionnée 2026-08-18 18:44 UTC)
- Emplacement   : `_REPORTS/2026-08-18_ETAT-PARE-FEU.md` §2.1, §2.3, §4.4, §5, §7.1 · journal `_SESSIONS/2026-08-18_PREALABLES-CRM-ETAPE-0.md` §4 ligne 14
- Constat       : le document écrit « CE DOCUMENT NE CONSTATE RIEN SUR LE SERVEUR », laisse son §5 « CONSTAT MESURÉ » **vide**, prédit nommément « Postgres 55432 et Redis 56379 publiés sur 0.0.0.0 », « un `ufw deny` sur un port publié par Docker ne bloque rien », « la base de production est joignable depuis Internet avec un mot de passe connu de tout lecteur du dépôt, sur un rôle superutilisateur », donne la commande qui le prouve (§4.4) et le correctif (§4.6(b)), et conclut « tant que ce n'est pas fait, F12 n'est **PAS** soldé » — et le journal §4 coche cette ligne **✅**.
- Preuve        : `grep -n "CE DOCUMENT NE CONSTATE RIEN\|55432\|PAS soldé" _REPORTS/2026-08-18_ETAT-PARE-FEU.md` → l. 11, 174, 342, 366, 502 ; §5 (l. 458-470) contient les cinq champs « [coller] » vides. Sortie : `04_PREUVES/agent-06/ligne-14-pare-feu.txt`.
- Témoin négatif: le §5 du même document est le témoin : s'il avait été rempli, la ligne serait close. Il ne l'est pas — le contrôle **sait** dire non, et il l'a dit.
- Impact        : la faille annoncée au §2.1 est restée ouverte du 18/08 (rédaction) au 19/08 (preuve depuis Internet : lecture superutilisateur de `count(*) from companies` = 4 295 349, données personnelles). **Le coût de la fermer était de cinq minutes de §4.** Au-delà : le mandat d'audit lui-même a repris la caractérisation fausse (« le pare-feu était en ordre au niveau d'ufw »), ce qui oriente la recherche vers le mauvais motif — on cherche une garde myope, alors que le motif réel est **une case ✅ posée sur un refus de conclure**.
- Reproduction  : lire `_REPORTS/2026-08-18_ETAT-PARE-FEU.md` en entier, puis la ligne 14 du §4 du journal.
- Correctif     : (a) rouvrir la ligne 14 : elle exige `ufw status` **et** `fail2ban` **dans un runbook**, ce qui n'existe nulle part (0 occurrence dans `RUNBOOK-CONSOLE-LOCALE.md`) — écrire `infra/runbooks/06-verifier-pare-feu.md` (§6 du rapport en donne le contenu, ~15 min) ; (b) coller au §5 la sortie de la vérification du 19/08 ; (c) **méthode** : interdire de cocher une ligne dont le livrable porte lui-même « non soldé ». Coût total ≈ 1 h.
- Statut        : ouvert

### [A06-002] Ligne 3 ter déclarée « CLOSE en production » alors que trois de ses quatre sous-critères sont contredits par le dépôt
- Sévérité      : **S2**
- Domaine       : canal / méthode
- Référence     : main `e8924b8`
- Emplacement   : `docker-compose.staging.yml:130,156,177` · `backend/config/mail.php:4` · journal `_SESSIONS/2026-08-18_PREALABLES-CRM-ETAPE-0.md` §8.7
- Constat       : le critère de sortie exige « un e-mail de test **part de préproduction et arrive** », « `MAIL_MAILER` **n'est plus `log`** en préproduction ni en production » ; la **préproduction** pose `MAIL_MAILER: log` sur ses trois services, le journal §8.7 écrit « `MAIL_MAILER` reste `log` : rien n'envoie » pour la production, et la ligne y est titrée « **CLOSE en production (19/08)** ».
- Preuve        : `grep -rn "MAIL_MAILER" backend/config/mail.php docker-compose.staging.yml` → `config/mail.php:4: 'default' => env('MAIL_MAILER', 'log')` ; `docker-compose.staging.yml:130/156/177: MAIL_MAILER: log`. Sortie : `04_PREUVES/agent-06/01_mesures.txt` §L3ter.
- Témoin négatif: le même dépôt sait poser autre chose — `docker-compose.test.yml:28` pose `MAIL_MAILER: array`. Le contrôle distingue donc bien les valeurs ; il n'y a pas d'erreur de lecture.
- Impact        : la voie B du CDC §9.0 (confirmations, rappels, comptes rendus, relances) est **réputée préparée** alors que rien n'a jamais quitté le CRM. Le premier envoi réel de l'étape 1b se fera **sans qu'aucun chemin sortant n'ait jamais été éprouvé** — y compris le retour de rebond, dont le journal reconnaît qu'il « se fera au premier envoi ». Le volet entrant, lui, est réel et testé (8 tests `PASS` en CI) : ce constat ne le remet pas en cause.
- Reproduction  : `grep -rn MAIL_MAILER docker-compose.staging.yml` ; puis lire le §8.7 du journal.
- Correctif     : renommer la ligne en « 3 ter-a — volet entrant : CLOS » et rouvrir « 3 ter-b — volet sortant : non commencé ». Le volet sortant est ~0,5 j (identité d'envoi, `MAIL_MAILER=smtp` en préproduction, un envoi réel, un rebond réel), et il **est un préalable de l'étape 1b**, pas de l'étape 0.
- Statut        : ouvert

### [A06-003] La mesure de performance de référence conclut sur une production qui n'existe pas (php-fpm supposé, `php -S` réel)
- Sévérité      : **S2**
- Domaine       : performance
- Référence     : main `e8924b8`
- Emplacement   : `_REPORTS/2026-08-18_MESURE-PERFORMANCE-REFERENCE.md:16,19,63,70` · `Dockerfile.laravel:103,121`
- Constat       : le rapport écrit l.19 « **sur la production (Linux, php-fpm + opcache)**, la ligne de base est de l'ordre de quelques dizaines de millisecondes », et fonde sur cette phrase sa méthode (ne retenir que le **delta** HTTP et le temps SQL) puis son verdict l.63 (« tenu avec une marge d'un ordre de grandeur ») ; or la cible `prod` de `Dockerfile.laravel` porte `CMD ["php","-S","0.0.0.0:80","-t","public"]`.
- Preuve        : `grep -nE "^CMD|^ENTRYPOINT|FROM .* AS " Dockerfile.laravel` → `103: FROM php-base AS prod` / `120: ENTRYPOINT [".../entrypoint-prod"]` / `121: CMD ["php","-S","0.0.0.0:80","-t","public"]`. Sortie : `04_PREUVES/agent-06/01_mesures.txt` §L11.
- Témoin négatif: le dépôt **sait** détecter ce cas — `infra/scripts/verifier-serveur-http.sh` existe et sort en code 1 si un conteneur sert par `php -S` ; il a été écrit **après** (post-A-010). Le contrôle est donc capable ; il n'existait pas au moment de la mesure.
- Impact        : le rapport est **le témoin de tous les critères du §29** (c'est le critère de sortie lui-même qui le dit). Un témoin qui projette une production php-fpm rend « verts » des critères que A-010 mesure rouges : compteurs du hub à **17,5 s cache froid**, requêtes **sérialisées**, critère 17 (dix sessions simultanées) et principe directeur 8 **inatteignables par construction**. Le rapport écrit même l.70 que le critère 17 est « impossible sur un serveur mono-thread » — **sans voir que la production en est un**.
- Reproduction  : lire `Dockerfile.laravel:103-121`, puis `_REPORTS/2026-08-18_MESURE-PERFORMANCE-REFERENCE.md:19`.
- Correctif     : (a) corriger l.19 et la conclusion §4 du rapport — coût ~30 min, **sans re-mesurer** ; (b) rejouer la mesure HTTP en préproduction **après** que le serveur HTTP de production soit corrigé (php-fpm ou FrankenPHP), sinon les chiffres HTTP ne signifieront toujours rien ; (c) la mesure **SQL** du rapport reste valable telle quelle et n'est pas à refaire.
- Statut        : ouvert

### [A06-004] « Étanchéité par table, pour CHAQUE table à workspace_id » exclut le journal d'audit RGPD, par cécité de scan promue en décision
- Sévérité      : **S2**
- Domaine       : sécurité / conformité
- Référence     : main `e8924b8`
- Emplacement   : `backend/tests/Support/EtancheiteWorkspace.php:70-89` (`ABSENTES_PAR_CONSTRUCTION`), `:125-151` (`DEFAUTS_CONNUS`), `:192` (filtre `relkind !== 'r'`) · `backend/tests/Feature/EtancheiteParTableTest.php:327` · `backend/database/migrations/2026_08_14_000001_harden_workspace_isolation.php:61-66`
- Constat       : le critère exige « pour **chaque** table à `workspace_id` : sans contexte → 0 ligne » ; le périmètre réellement contrôlé est `tablesScopees()` **moins** `DEFAUTS_CONNUS`, ce qui écarte `audit_logs` (motif écrit : « le scan ne retient que `relkind='r'`, donc elle en est absente **par construction** »), `audit_logs_default`, `coverage_matrix_cells`, et `email_verification_logs` — dont la **fuite inter-workspace est mesurée** et épinglée.
- Preuve        : `grep -n "audit_logs\|coverage_matrix_cells\|email_verification_logs" backend/tests/Support/EtancheiteWorkspace.php` (l. 71-88, 126) ; `EtancheiteParTableTest.php:327` `if ($relation->relkind !== 'r' || $relation->relispartition) continue;` — la boucle FORCE porte le **même** filtre. Sortie : `04_PREUVES/agent-06/01_mesures.txt` §L8. Confirmation indépendante : **B11-006** (`audit_logs` n'a aucune RLS).
- Témoin négatif: le fichier de test **sait** rougir sur une table non contrôlée — il porte deux témoins réels (RLS retirée → 2 lignes visibles ; FORCE posé → 0). La sonde fonctionne ; c'est la liste qu'on lui donne qui est amputée.
- Impact        : `audit_logs` est **le journal d'audit** — la table qui prouverait, en cas d'incident, qui a lu quoi. Elle est hors RLS et hors contrôle, et la seule chose qui le dit est un commentaire dans un fichier de test. `email_verification_logs` contient des adresses e-mail et des verdicts de prestataire, et **fuit entre univers, mesuré**. La ligne 8 est cochée ✅ dans le journal §4 sans réserve.
- Reproduction  : lire `EtancheiteWorkspace::ABSENTES_PAR_CONSTRUCTION` puis `EtancheiteParTableTest::tablesControlees`.
- Correctif     : (a) une migration `DROP POLICY IF EXISTS email_verif_workspace_isolation ON email_verification_logs;` — le motif est déjà écrit, coût ~15 min + le test `DÉFAUT CONNU` rougira, ce qui est le comportement voulu ; (b) trancher explicitement `audit_logs` : soit RLS sur la table partitionnée (le piège `create_parent` v4/v5 est connu du dépôt), soit un **contrôle applicatif dédié** avec son propre test — mais pas « absente par construction ». Coût : 0,5 j.
- Statut        : ouvert

### [A06-005] La garde de la base reconstructible mesure la cause, jamais le geste ; et le cas qui cassait n'est joué par aucune CI
- Sévérité      : **S3**
- Domaine       : tests
- Référence     : main `e8924b8`
- Emplacement   : `backend/tests/Feature/Database/ReconstructionBaseTest.php:36-91` · `.github/workflows/ci.yml:307-390`
- Constat       : le critère exige « `migrate:fresh` réussit de zéro … **joué deux fois de suite** » ; la garde lance `Artisan::call('migrate')` puis interroge `pg_depend` pour vérifier qu'aucune table d'extension n'est sur le chemin du `DROP … CASCADE`. La CI, elle, crée un conteneur Postgres **neuf** à chaque exécution : la **deuxième** reconstruction sur une base déjà migrée — le cas exact qui cassait — n'est jouée par aucune exécution automatique.
- Preuve        : lecture de `ReconstructionBaseTest.php` (aucun appel à `migrate:fresh`) ; `ci.yml:359-366` `docker run -d --name axion-ci-postgres … POSTGRES_DB=axion_crm_test` à chaque run. Contre-mesure jouée à la main le 19/08 : `migrate:fresh` ×2, `EXIT1=0` et `EXIT2=0` (`04_PREUVES/agent-06/ligne-02-migrate-fresh.txt`).
- Témoin négatif: la garde **sait** rougir — son message d'échec nomme les tables bloquantes, et le test échouerait si `pg_partman` revenait dans `public`. Elle garde bien la **cause**. Ce qu'elle ne garde pas, c'est l'**effet**.
- Impact        : faible aujourd'hui (le critère a été re-mesuré vert le 19/08), mais la garde ne détectera **pas** une régression du geste qui ne passerait pas par cette cause précise (p. ex. une nouvelle extension, un `dont_drop` modifié, un `RefreshDatabase` remplacé).
- Reproduction  : `git grep -n "migrate:fresh" backend/tests/` → aucune occurrence dans `ReconstructionBaseTest`.
- Correctif     : ajouter dans la CI un pas qui joue `migrate:fresh` **deux fois** sur la base déjà migrée par la suite Pest (≈ 2 min de CI, ~15 min d'écriture). C'est le seul contrôle qui reproduit le cas historique.
- Statut        : ouvert

### [A06-006] La garde « zéro baseline sur les modules du chantier » omet le service que l'étape 0 a elle-même modifié
- Sévérité      : **S3**
- Domaine       : tests / backend
- Référence     : main `e8924b8`
- Emplacement   : `backend/tests/Unit/PhpstanBaselineNeGrossitPasTest.php:55-63` (`BASELINE_CHEMINS_INTERDITS`) · `backend/phpstan-baseline.neon` · commit `dd91665`
- Constat       : la liste interdite compte 7 chemins ; `app/Services/Audiences/AudienceBuilderService.php` porte **9 entrées** de baseline et n'y figure pas — alors que la **ligne 10 de l'étape 0** (`dd91665`) a modifié la sémantique `neq`/`not_in` qui gouverne ce service, et que le critère de la ligne 6 dit « tout module que le chantier touchera ».
- Preuve        : `grep -oP "^\t{3}path: \K.*" backend/phpstan-baseline.neon | grep -iE "audience"` → `app/Services/Audiences/AudienceBuilderService.php` ×9, `app/Models/AudienceMember.php` ×3, `app/Jobs/RefreshAudienceChunkJob.php` ×1, `app/Console/Commands/AudiencesFullRefreshCommand.php` ×1. Sortie : `04_PREUVES/agent-06/01_mesures.txt` §L6.
- Témoin négatif: la garde **sait** rougir sur un chemin listé — le test « aucune entrée de baseline ne vise le socle CRM » nomme les entrées fautives dans son message. Le mécanisme marche ; c'est la liste qui est courte.
- Impact        : `AudienceBuilderService` **décide à qui part un e-mail** (F6 du plan le dit). Quatorze erreurs de niveau 8 y sont gelées sur ce périmètre, et le chantier peut y écrire du code neuf sans que la garde ne s'en aperçoive. Le reste de la ligne 6 est solide (211/248, en-tête concordant, `reportUnmatchedIgnoredErrors: true`, niveau 8 vert).
- Reproduction  : comparer `BASELINE_CHEMINS_INTERDITS` aux chemins touchés par les commits d'étape 0.
- Correctif     : ajouter `app/Services/Audiences/` à `BASELINE_CHEMINS_INTERDITS` et corriger les 14 entrées correspondantes. Coût ≈ 0,5 j (typage de relations et `@property`). À faire **avant** que l'étape 2 touche les segments.
- Statut        : ouvert

### [A06-007] La seule preuve de la ligne 1 n'est exécutée par aucun workflow, et son runbook démarre depuis un worktree résiduel
- Sévérité      : **S2**
- Domaine       : tests / navigation
- Référence     : main `e8924b8`
- Emplacement   : `frontend/tests/e2e/console-locale.spec.ts` · `_REPORTS/RUNBOOK-CONSOLE-LOCALE.md:41,331,344,575` · `.github/workflows/a11y.yml:48,58`
- Constat       : le critère de la ligne 1 (« la console tourne en local, connexion, 2FA, tous les écrans v2 ouverts ») est prouvé par `console-locale.spec.ts` ; **aucun workflow ne lance ce fichier** — `a11y.yml` ne lance que `a11y.spec.ts` et `navigation.spec.ts`, et aucun autre workflow ne mentionne Playwright côté frontend. Le runbook, seul mode d'emploi du critère, fait démarrer la pile depuis `C:/Users/willi/Documents/Projets/crmpro-wt-etape0/`, worktree **résiduel** (`702253c`, cf. dossier §1).
- Preuve        : `grep -n "playwright test" .github/workflows/*.yml` → deux occurrences, `a11y.spec.ts` et `navigation.spec.ts`, sur **16** specs versionnées ; `grep -n "crmpro-wt-etape0" _REPORTS/RUNBOOK-CONSOLE-LOCALE.md` → l. 41, 331, 344, 575. Sortie : `04_PREUVES/agent-06/01_mesures.txt` §L1.
- Témoin négatif: le dépôt sait brancher une spec et la rendre bloquante — c'est exactement ce que la ligne 3 bis a fait pour `navigation.spec.ts`, en découvrant qu'il « n'était exécuté NULLE PART ». Le geste est connu, documenté et déjà joué une fois ; il n'a pas été refait pour `console-locale`.
- Impact        : la ligne 1 est le « préalable n° 1 **non négociable** » du plan (§5.7). Sa preuve est un geste unique du 18/08 dont **rien** ne signalera la péremption, et dont le mode d'emploi pointe un répertoire que le dossier d'audit qualifie de résiduel. Un agent d'étape 1 qui suit le runbook démarre depuis le mauvais arbre. Recoupe **H44-001** (14 specs sur 16 ne tournent nulle part) et le §6 bis du dossier.
- Reproduction  : `grep -rn "console-locale" .github/workflows/` → aucun résultat.
- Correctif     : (a) corriger les 4 chemins du runbook vers la racine du dépôt (`docker-compose.local.yml` **y est déjà versionné**) — 10 min ; (b) décider si `console-locale.spec.ts` doit tourner en CI : il exige une pile Docker complète, donc plutôt un workflow `workflow_dispatch` + un rappel dans le runbook qu'il est **la** preuve du critère. Coût ≈ 0,5 j si on le branche.
- Statut        : ouvert

### [A06-008] Le journal §4 annonce 15 lignes closes sur 16 ; sept le sont au sens de leur propre critère
- Sévérité      : **S2**
- Domaine       : méthode
- Référence     : main `e8924b8`
- Emplacement   : `C:\Users\willi\Documents\Projets\Axion-IA\_SESSIONS\2026-08-18_PREALABLES-CRM-ETAPE-0.md` §4 · `_PLANS/2026-08-18_PREALABLES-AVANT-CHANTIER-CRM-CIBLE.md` §0 bis l. 15
- Constat       : le journal §4 et le §0 bis du plan annoncent « **16 lignes sur 16 traitées, 15 closes** » ; confrontée au critère de sortie **écrit dans le plan pour chaque ligne**, la mesure trouve **7 CLOS**, **7 PARTIEL**, **2 OUVERT** (cf. §1, §1 bis et §2 de ce document).
- Preuve        : la grille du §1 ci-dessus, chaque cellule portant sa commande. Sorties : `04_PREUVES/agent-06/00_reference.txt`, `01_mesures.txt`, `ci_backend_e8924b8.log`.
- Témoin négatif: le décompte n'est pas un procès du travail : **16/16 lignes ont produit un livrable réel et 16/16 sont sur `main`** (mesuré, §0). Le contrôle sait donc reconnaître ce qui a été fait — il distingue « fait » de « clos au sens du critère ».
- Impact        : le plan dit « **Puis, et seulement puis, l'étape 1** ». L'étape 1a a démarré (worktree `crmpro-wt-etape1a`, PR de préproduction, compteurs du hub) sur la foi d'un « 15/16 ». Trois des lignes non closes sont précisément celles qui garantissent la mesurabilité de l'étape 1 : le harnais d'interface (6 écrans sur 37), le témoin de performance (fondé sur une production supposée) et l'étanchéité (journal d'audit hors contrôle).
- Reproduction  : lire le §3 du plan, colonne « Critère de sortie », ligne par ligne, contre le §4 du journal.
- Correctif     : mettre à jour le §4 du journal avec les trois verdicts (CLOS / PARTIEL / OUVERT) **et le sous-critère qui manque** pour chaque PARTIEL. Coût ≈ 1 h. Ne pas rouvrir le travail : rouvrir la **case**.
- Statut        : ouvert

### [A06-009] Deux fichiers de test affirment que `CRM_DB_APP_ROLE_ENABLED` vaut `false` en production ; B11-010 le mesure à `true`
- Sévérité      : **S3**
- Domaine       : sécurité / documentation
- Référence     : main `e8924b8`
- Emplacement   : `backend/tests/Feature/NeDoitPasRegresserTest.php:35-37` · `backend/tests/Feature/EtancheiteParTableTest.php:29-34`
- Constat       : les deux fichiers écrivent « tant que `CRM_DB_APP_ROLE_ENABLED` reste à false — **sa valeur par défaut, et sa valeur en production** — l'application se connecte avec le rôle `axion`, SUPERUSER et BYPASSRLS » ; le constat **B11-010** du présent audit mesure ce drapeau à **`true` en production** et `false` en local.
- Preuve        : `grep -rn "CRM_DB_APP_ROLE_ENABLED" backend/config/ .env.example` → `config/crm.php:36` défaut `false`, `.env.example:76` `false`. Le **défaut** est donc bien `false` ; c'est l'affirmation « **et sa valeur en production** » qui est en cause, et je **n'ai pas l'accès** pour la trancher moi-même (production en lecture seule, pas de `ssh`).
- Témoin négatif: aucun — je déclare ce point **non concluant de mon côté** et je m'appuie sur B11-010, conformément au §5 bis-1 du dossier.
- Impact        : les deux fichiers sont les commentaires les plus lus de la suite d'étanchéité ; ils orientent la lecture du niveau de protection réel dans les deux sens à la fois. Si B11-010 dit vrai, la nuance qu'ils posent (« la barrière est prête, pas armée ») est **périmée en production** et sous-estime ce que ces tests prouvent ; si c'est l'inverse, c'est B11-010 qu'il faut corriger. Dans les deux cas, **une seule mesure tranche**.
- Reproduction  : comparer les deux blocs de commentaires à B11-010.
- Correctif     : une mesure unique en production (`docker exec axion-crm-api php -r 'echo config("crm.db_app_role") ? "true":"false";'`, lecture seule, ~2 min, à jouer par qui a l'accès), puis corriger le perdant. Coût ≈ 15 min.
- Statut        : ouvert

### [A06-010] Le plan qui « fait foi » pour l'étape 0 n'est versionné dans aucun dépôt
- Sévérité      : **S2**
- Domaine       : conformité / méthode
- Référence     : main `e8924b8` (CRM) et `eb754332` (site)
- Emplacement   : `C:\Users\willi\Documents\Projets\Axion-IA\_PLANS\2026-08-18_PREALABLES-AVANT-CHANTIER-CRM-CIBLE.md` (28 200 octets, 2026-08-18 19:35) · `C:\Users\willi\Documents\Projets\Axion-IA\_SESSIONS\2026-08-18_PREALABLES-CRM-ETAPE-0.md`
- Constat       : `C:\Users\willi\Documents\Projets\Axion-IA` **n'est pas un dépôt git** (la racine du dépôt du site est `Axion-IA\axionia`, un niveau plus bas) ; le plan et son journal d'exécution vivent donc **hors de tout historique**, alors que le CDC v2.7 §27 et le §A.1 les désignent comme faisant foi pour l'étape 0.
- Preuve        : `git -C "C:/Users/willi/Documents/Projets/Axion-IA" rev-parse --show-toplevel` → **`fatal: not a git repository`** ; `ls -la` sur le plan → **28 200 octets, 18 août 19:35** ; `git -C .../axionia ls-files | grep -i PREALABLES` → **vide** ; `git ls-files | grep -i PREALABLES` (CRM) → seul `_REPORTS/2026-08-18_ARBITRAGES-PREALABLES-SECTION-4.md`, qui est le **document des décisions**, pas le plan. Les répertoires `_PLANS/` (10+ documents) et `_SESSIONS/` (10+ documents) sont dans le même cas.
- Témoin négatif: la commande **sait** trouver un dépôt — jouée sur `.../Axion-IA/axionia` et sur le dépôt CRM, elle rend une racine. Et `git ls-files` **sait** trouver un fichier de préalables : il remonte bien celui du CRM. Le vide mesuré n'est donc pas un vide de commande. *(C'est le piège 21 du dossier : un autre agent a lu le `fatal:` comme « le plan n'existe pas ». Il existe, il est simplement hors dépôt.)*
- Impact        : le document de référence de l'étape 0 — et celui de l'étape 1 à venir — n'a **ni historique, ni sauvegarde, ni protection de branche, ni revue**. Le plan lui-même a été « **mis à jour sur disque en cours de session** (v2.3 → v2.7) » (journal §0) : personne ne peut aujourd'hui dire quelle version de quel critère de sortie a été jouée par quel agent. C'est exactement ce que le présent audit doit trancher, et il ne le peut pas. Une mauvaise manipulation l'efface sans trace.
- Reproduction  : `git -C "C:/Users/willi/Documents/Projets/Axion-IA" status`.
- Correctif     : committer `_PLANS/` et `_SESSIONS/` dans l'un des deux dépôts (le CRM est le plus naturel : c'est lui que le plan pilote), ou en faire un troisième dépôt. Coût ≈ 30 min. **Le faire avant l'étape 1**, sinon le même trou se rouvre au même endroit.
- Statut        : ouvert

### [A06-011] Trois numérotations de fragilités coexistent, et la collision CDC ↔ plan n'est pas monotone
- Sévérité      : **S3**
- Domaine       : documentation
- Référence     : CDC v2.7 §A.1 · plan §2 · main `e8924b8`
- Emplacement   : `C:\Users\willi\Downloads\axion-ia-crm-cahier-des-charges-fonctionnel-v2.md` §A.1 (l. 40-58) · plan §2 · messages de commit `3feb733`, `da97826`, `ab2b9d1`, `9e81b8a`…
- Constat       : le CDC §A.1 numérote **15** fragilités (1-15) ; le plan §2 en numérote **19** (F1-F19) ; le §3 du plan en tire **16** lignes. Le code et les messages de commit suivent **la numérotation du plan**, de façon cohérente — il n'y a donc pas de troisième numérotation *dans le code*. La collision est entre le CDC et le plan, et elle **n'est pas monotone** : CDC n° 5 = **F13**, CDC n° 6 = **F5**, CDC n° 15 = **F17**.
- Preuve        : `sed -n '40,58p'` du CDC (15 lignes numérotées 1-15, la n° 6 = « Performance jamais mesurée au volume », la n° 15 = « Navigation du CRM à ranger ») ; `git log -1 --format=%s ab2b9d1` → « …mesure de performance de référence… **(F5)** » ; `git log -1 --format=%s da97826` → « …ranger la barre latérale… **(F17)** ». Table complète au §1 ter.
- Témoin négatif: la correspondance est **établissable** — je l'ai construite ligne à ligne au §1 ter, les 15 entrées du CDC trouvent toutes leur F. Ce n'est donc pas une divergence de fond, c'est une absence de table.
- Impact        : le CDC renvoie à ses propres numéros dans des endroits normatifs (§28 « les fragilités du §A.1 », §29 critère 17 « la mesure de référence (§A.1 n° 6) »). Un agent qui suit le CDC et cherche « n° 6 » dans le code trouve `F5`, ou ne trouve rien. Le mandat d'audit s'est lui-même contredit sur ce point (« 15 » à sa l. 78, « F1 → F19 » ailleurs). Deux fragilités (F18 mailer, F19 MailWizz) **n'existent pas dans le CDC** et ne sont donc couvertes par aucune exigence de sortie du CDC.
- Reproduction  : comparer le §A.1 du CDC au §2 du plan.
- Correctif     : coller la table du §1 ter dans le CDC §A.1 (une colonne « réf. plan ») **ou** renuméroter le plan sur le CDC. Coût ≈ 20 min. Et verser F18/F19 au §A.1 du CDC si elles doivent avoir une exigence de sortie opposable.
- Statut        : ouvert

### [A06-012] La parité de capture s'arrête à l'outbox du site : une livraison abandonnée compte comme reçue par le CRM
- Sévérité      : **S2**
- Domaine       : canal
- Référence     : site `main = eb754332`
- Emplacement   : `C:\Users\willi\Documents\Projets\Axion-IA\axionia\src\server\crm-sync\reconcile.ts:292-317` · `.../crm-sync/health.ts`
- Constat       : le critère de la ligne 12 exige de comparer ce que la console voit aux « **événements reçus par le CRM** » ; `compareFamily()` construit les `subject_ref` attendus depuis les tables métier (l. 292-293) puis interroge `prisma.crmSyncOutbox.findMany({ where: { subjectRef: { in: refs } } })` (l. 311-313) **sans aucun filtre de statut** : une ligne `pending`, `failed` ou **`gave_up`** est comptée comme émise, et `missing` reste à 0.
- Preuve        : lecture de `reconcile.ts:311-317` — le `where` ne porte que sur `subjectRef`, le `select` ne remonte que `subjectRef`. Le versant livraison existe bien (`health.ts` : comptes par statut, `sent24h`, `gave_up`) mais aucun code ne joint les deux.
- Témoin négatif: la réconciliation **sait** compter un manquant — le test `crm-sync-l5.test.ts` vérifie qu'un événement Calendly sans ligne d'outbox est remonté avec sa référence exacte. La sonde marche ; c'est son **critère d'arrêt** qui est trop en amont.
- Impact        : la parité annoncée « **3 → 3 → 0 manquante** sur 7 jours » (journal §4) est une parité **source → outbox**, pas **source → CRM**. Le §22 du CDC empile 20+ familles d'événements sur ce canal, et le critère 18 du CDC (parité toutes familles) est censé être mesuré **avant** d'y ajouter une famille. Aggravant : l'échantillon est de **3 événements**, sur lequel un écart de 0 ne discrimine rien. Second angle mort mesuré : `podcast_request` **émet** (`podcast-request/actions.ts:111`) et **n'est pas réconciliée** — 6 familles émettrices, 5 réconciliées.
- Reproduction  : lire `reconcile.ts:292-317`, puis chercher un `status` dans le `where`.
- Correctif     : (a) filtrer sur `status: 'sent'` — ou mieux, remonter **deux** compteurs (`non émis` et `émis mais non livré`), ce qui rend le rapport lisible sans perdre d'information ; (b) ajouter `podcast_request` à `CrmSyncFamily` ; (c) poser un test runtime sur `CrmEventType` comme il en existe un sur `CRM_FORM_TYPES` (`crm-sync.test.ts:162`) — aujourd'hui les 10 types d'événements sont une union TypeScript pure, non épinglable, alors que c'est le mécanisme de panne déjà payé une fois. Coût total ≈ 0,5 j.
- Statut        : ouvert

### [A06-013] Le journal d'étape 0 consigne un diagnostic Calendly faux, que le code de `main` contredit explicitement
- Sévérité      : **S3**
- Domaine       : documentation / canal
- Référence     : site `main = eb754332`
- Emplacement   : journal `_SESSIONS/2026-08-18_PREALABLES-CRM-ETAPE-0.md` §2, ligne F16 · `axionia/src/server/calendly/api.ts:290` · `axionia/src/server/calendly/enrich.ts:34-35,72`
- Constat       : le journal écrit « `completed`/`no_show` sont **hors de portée de l'API Calendly par construction** (`enrich.ts:20-21`) » ; le code de `main` lit `noShow: Boolean(invitee["no_show"])` (`api.ts:290`), le mappe (`enrich.ts:72`) et porte le commentaire « **Contrairement à ce qu'on a longtemps écrit ici, l'API le sait** » (`enrich.ts:34-35`).
- Preuve        : lecture des trois emplacements sur `eb754332` (mesure §1 bis).
- Témoin négatif: le journal a **correctement réfuté** quatre autres constats du plan (F1, F3, F15, F16 partiel) par la mesure ; sa méthode sait donc trouver le faux. Cette affirmation-là a survécu.
- Impact        : le correctif livré est **bon** — c'est le diagnostic archivé qui est faux, et c'est lui qu'un agent d'étape 1 relira. Il pourrait en conclure qu'un no-show ne peut pas remonter automatiquement, et reconstruire un chemin qui existe. Le vrai reste à dire est ailleurs, et n'est écrit nulle part : la fenêtre de rattrapage est de **48 h** (`refresh.ts:56`) — **un no-show coché plus tard n'arrive jamais**.
- Reproduction  : comparer le §2 du journal (ligne F16) à `enrich.ts:34-35`.
- Correctif     : corriger la ligne du journal et y consigner la limite réelle des 48 h. Coût ≈ 10 min. Décider ensuite si 48 h suffit (le webhook `invitee_no_show.created` existe mais est **inerte** : il exige un plan Calendly payant non souscrit).
- Statut        : ouvert

---

## 5. Ce que je n'ai PAS pu vérifier, et pourquoi

Cette liste est un livrable.

1. **L'état réel du serveur de production** (`ufw status`, `fail2ban-client status`,
   `ss -tlnp`) — pas d'accès `ssh`, et la production est en lecture seule pour cet audit.
   C'est exactement ce que le §4 de `ETAT-PARE-FEU.md` demande, et il n'a toujours pas
   été joué **sur le pare-feu** (la faille des ports, elle, a été fermée et vérifiée
   depuis l'extérieur le 19/08).
2. **La valeur de `CRM_DB_APP_ROLE_ENABLED` en production** (cf. A06-009) — je m'appuie
   sur B11-010 sans pouvoir le confirmer. Verdict **non concluant de mon côté**, conformément
   au §5 bis-1 du dossier.
3. **Le rejeu des suites de tests sur la pile locale** — interdit par le mandat (A-009) et
   de toute façon non concluant : une sortie d'une instance précédente
   (`04_PREUVES/agent-06/pest-etape0-v3.txt`) montre `NeDoitPasRegresserTest` et `RlsTest`
   **rouges en local** ; ces rouges sont ceux du **harnais partagé** (B11-005 : base
   `axion_crm_test` codée en dur et partagée entre agents) et non du code — la **CI sur
   `e8924b8`** les rend `PASS`. Je les archive pour la traçabilité et **je n'en conclus rien**.
4. **Le rejeu de `console-locale.spec.ts`** (ligne 1) — exige la pile Docker locale.
   Non joué. Je me suis limité à constater qu'il n'est branché à aucun workflow et à lire
   ses assertions et ses 33 captures versionnées.
5. **Le « vu rouge puis vert »** pour les 3 mécanismes de collecte préexistant côté Laravel
   (ligne 4) et pour le « casser un écran » (ligne 3) : aucune preuve archivée dans le dépôt.
   Je ne peux ni le confirmer ni l'infirmer — je constate l'absence d'archive.
6. **La conformité fine de la barre latérale au §23.3 du CDC** (ligne 3 bis) : je m'appuie
   sur l'écart déjà mesuré par A-006 plutôt que de le re-mesurer (règle 8 : on étend, on ne
   réinvente pas). J'ai vérifié indépendamment les points que A-006 affirme sur la barre
   **livrée** (6 sections, une entrée Contacts, aucun cadenas) : conformes.
7. **Les lignes 12 et 13** : mesurées sur le dépôt du site (`main = eb754332`) par une mesure
   déléguée dont je restitue les commandes et les numéros de ligne (§1 bis). Je n'ai pas relu
   ce dépôt de mes propres yeux ; les emplacements cités sont vérifiables tels quels.
8. **L'état des variables d'environnement de production du site** (`CRM_SYNC_ENABLED`,
   `CALENDLY_API_TOKEN`, `CALENDLY_WEBHOOK_SIGNING_KEY`, `PII_ENCRYPTION_KEY`) : le chemin
   du no-show (ligne 13) en dépend. Non mesurable depuis les dépôts.
9. **Les chiffres de parité relevés en production le 18/08** (« 3 → 3 → 0 ») : je constate
   l'**échantillon** (3 événements sur 7 jours) et le **sens** de la comparaison, pas les
   chiffres eux-mêmes — ils vivent dans la carte « Synchro CRM » de la console du site.
10. **Toute mesure d'interface à l'exécution** : le chef de chantier signale que l'atelier
    local servait une image vieille de 32 h et qu'il l'a reconstruite. **Aucun de mes
    verdicts ne repose sur l'interface servie** — la ligne 3 bis est jugée sur `Sidebar.tsx`,
    sur `git show --stat da97826` et sur `a11y.yml`, tous mesurés sur `main = e8924b8`. Une
    vérification à l'écran après reconstruction reste souhaitable et n'a pas été faite ici.
