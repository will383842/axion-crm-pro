# 02 bis — P2 : CONSOLIDATION, DÉDOUBLONNAGE, ARBITRAGE, ORDONNANCEMENT

> Produit par le chef de chantier au terme de **P1** (23 agents rendus sur 46 ; les autres tournent).
> **Référence : `main = e8924b8`**, code identique à `c0c453d`.
> Le registre détaillé reste `02_CONSTATS.md` ; ce document **regroupe, dédoublonne, arbitre et ordonne**.
>
> ⚠️ **Aucun constat de ce document n'est vérifié.** La vérification est P4 (rotation +17), la
> réfutation P5 (rotation +29). Ce qui suit est **le meilleur état de la mesure**, pas un verdict.

---

## 1. Le dédoublonnage — 17 constats S0 rendus, **12 défauts distincts**

Cinq groupes de constats décrivent le **même** défaut vu par des agents différents. C'est un bon signe
(plusieurs mesures indépendantes convergent), mais il ne faut pas les compter deux fois.

| Groupe | Constats fusionnés | Le défaut, en une phrase |
|---|---|---|
| **G1 — le journal d'audit n'est pas un journal d'audit** | `B16-002` · `B16-003` · `B16-004` · `B11-006` · `B10-002` · `A09-008` · `A06-004` · `A08-004` | La table qui doit **prouver** ce qui s'est passé est **tronquable par la queue sans détection**, son **horodatage n'entre pas dans le hachage**, elle n'a **aucune RLS** (ni ses 14 partitions), le rôle applicatif y a `DELETE` **et** `UPDATE`, et `GET /audit-logs` la rend **tous espaces confondus à tout compte authentifié**. |
| **G2 — le cloisonnement s'arrête à la porte** | `B11-001` · `B11-002` · `B17-010` · `B12-001` · `B12-012` | **26 tâches planifiées sur 33** et **5 jobs sur 6** tournent **sans contexte d'espace** ; `GET /companies/{id}` **rend la fiche d'un autre espace** (200 mesuré) ; et `BasePolicy::sameWorkspace()` compare des **UUID castés `(int)`** → `0 === 0`, **toujours vrai**. |
| **G3 — l'effacement n'efface pas** | `B14-002` · `E31-001` · `B10-004` | `erasure` traverse, le site répond **« 200 applied »**, et **rien n'est effacé** — le mot n'apparaît dans **aucun `if`, aucun test**. Et côté CRM, l'export RGPD couvre **4 tables**, l'effacement **8**, **`candidates` dans ni l'un ni l'autre**. |
| **G4 — personne ne peut entrer** | `A-012` · `F40-002` · `A07-001` · `A06-002` | `MAIL_MAILER` n'est défini **nulle part** → ni lien magique ni réinitialisation ; le mot de passe initial n'a été annoncé qu'une fois ; **et l'enrôlement 2FA écrit trois colonnes qui n'existent pas** — donc même avec un mot de passe, **la première connexion ne peut pas aboutir**. |
| **G5 — la production ne peut pas porter le produit** | `A-010` · `A-009` · `A06-003` | `php -S`, **un seul processus**, requêtes **sérialisées** (escalier de 15 ms, témoin positif plat). **Principe directeur 8 et critère 17 inatteignables par construction.** php-fpm **est dans l'image** et n'est jamais lancé. |

**Les sept S0 restants, distincts :**

| Id | Le défaut |
|---|---|
| `B12-003` | **Aucune policy n'est jamais appelée** : un `viewer` a **supprimé définitivement** une entreprise (`Company` n'a pas `SoftDeletes`, la doc annonce l'inverse) |
| `B12-004` | `POST /internal/scraper-result` **accepte une signature forgée** — HMAC réimplémenté sans garde de secret vide |
| `C18-016` | `MockServicesProvider` **sans garde d'environnement, défaut = mock** ; `MockLLMClient` **écrit des classifications fabriquées en base de production** (reclassé S0, `D-012`) |
| `C19-007` | Base légale « intérêt légitime » **sans mise en balance écrite ni information art. 14**, pour **1 319 567 personnes** |
| `I48-001` | **Le CRM n'a aucune route pour créer une fiche personne** ; la modifier ou la supprimer rend **501** |
| `A07-003` | Le **runbook de rotation des secrets** prescrit `docker compose restart` : **un secret réputé tourné ne l'est pas** |
| `A08-008` | La sauvegarde restaure **les données mais pas les droits** (`--no-acl`) : une restauration de secours livre **une application incapable de lire** — et le script annonce « Restore complet » |

**→ 12 défauts S0 distincts.**

---

## 2. Les trois défauts **systémiques** — ceux qui expliquent les autres

Ce sont les plus importants du rapport, parce qu'ils ne se corrigent pas fichier par fichier.

### S-1 · Un problème de **clôture**, pas de mesure (`A-013`)
Les artefacts de ce dépôt sont d'une **honnêteté inhabituelle** ; ce sont les **couches de résumé**
qui mentent. Le rapport pare-feu **prédisait la faille du 19/08 en toutes lettres** et disait
« F12 n'est PAS soldé » — **la ligne a été cochée ✅ par-dessus**. Le README du harnais écrit
« 31 écrans sur 37 restent ». `vitest.config.ts` documente ses seuils comme « **DÉCORATIFS** ».
`A08-008` était **écrit d'avance**, avec la date à laquelle il se déclencherait.
**Dans chaque cas, l'information exacte existait, écrite, au bon endroit.**

### S-2 · Des gardes qui **mesurent le mauvais objet** (`A-011`) — **neuf cas**
`config-prod` valide le fichier, pas le conteneur · `CrmOutboundTest` teste un 503 **que le site
n'émet jamais** (15 verts, 52 assertions) · les gardes SSRF bloquent l'IPv6 **par accident de
parsing** — *corriger le parsing ouvrirait la faille* · la garde horaire ne mesure **que sa propre
fixture** · `proxy-providers/test` renvoie **`healthy: true` en dur** · l'instrument de parité compte
un `gave_up` **comme émis** · la garde e2e d'`ErrorBoundary` surveille **un composant jamais monté** ·
les 3 suites du canal sont **vertes 59/59 avec `erasure` cassé** · et la mesure de performance de
l'étape 0 conclut sur une production « php-fpm » **qui est en réalité `php -S`**.

### S-3 · **L'atelier ne reproduit pas la production** — cinq divergences mesurées
`CRM_DB_APP_ROLE_ENABLED` (`false` local / **`true` prod**) · `DB_TIMEZONE` (absent local / posé prod) ·
`AUDIT_HASH_CHAIN_SECRET` (**vide local** / 64 car. prod) · le **bundle frontend** (32 h de retard,
corrigé) · et le **schéma lui-même** (`audit_logs` partitionnée en CI, **ordinaire en prod**).
**Conséquence** : la suite de 780 tests **ne tourne jamais dans la configuration de production**
(`A08-003`) — **et deux tests verts affirment le contraire**.

---

## 3. L'arbitrage du chef de chantier — ce que je tranche, et pourquoi

| # | Question | Décision | Raison |
|---|---|---|---|
| 1 | `C18-016` : S1 ou S0 ? | **S0** (`D-012`) | Le §4.6 du mandat le dit ; la fuite ne demande **aucune action** (variable absente = mock) ; et l'effet **écrit en base de production** sans marqueur |
| 2 | `A-001` : S1 ou S2 ? | **S2** (`D-004` révisée) | La variable est l'en-tête `Accept`, **que le SPA pose**. La console n'est pas touchée. |
| 3 | `A-004` : S2 ou S3 ? | **S3** | C'est la limite de **validations échouées** qui est consommée, pas le quota d'émission |
| 4 | `A07-004` « le plan n'a jamais existé » | **Réfuté**, reformulé en `A06-010` | Le fichier **existe** (28 200 o) ; il n'est **pas versionné**, et il a **changé en cours de session** |
| 5 | L'hypothèse « une opposition RGPD part en 422 » | **Morte** | Les lignes de journal venaient de **la suite de tests**. Mais la **variante** `form_type=recrutement` est **confirmée** (`E31-002`) |
| 6 | Piège 10 du mandat (« CI en `en_US.utf8` ») | **Faux, cause corrigée** | CI et prod sont **toutes deux en `C`**. La divergence réelle est **SQL ↔ PHP** — « aligner les locales » ne réglerait rien |
| 7 | `E32-002` : bug ou arbitrage ? | **Arbitrage, remonté au dirigeant** | Le CRM déclare **par conception** que sa timeline est un **index, jamais une copie**. Cela **contredit le principe 10**. Ce n'est pas à corriger en P3, c'est à trancher |
| 8 | `I48-008` : le produit **dépasse** son périmètre | **Remonté au dirigeant (§28.6)** | `/cold-email`, `/linkedin` et le constructeur d'audiences relèvent du lot **L7**, que le §26 exclut |

---

## 4. L'ordonnancement de P3 — **ce qui débloque le reste d'abord**

Le mandat impose : « ce qui débloque le reste d'abord », puis S0 → S1 → S2 → S3.
Voici l'ordre, et **la raison de chaque rang** — un ordre sans raison n'est qu'une liste.

| Rang | Lot | Pourquoi ici, et pas plus bas |
|---|---|---|
| **0** | **Écrire la règle de clôture** (`A-013`) et la règle « une garde rougit **sur l'objet qui casse** » (`A-011`) dans `CONTRIBUTING.md` | **Avant tout correctif.** Sinon P3 produira des gardes du même genre que celles qu'il corrige. Coût : 1 h. C'est le seul lot qui protège tous les autres. |
| **1** | **`A-010`** — sortir la production de `php -S` | Tout le reste se mesure dessus. **Mesurer la performance avant ce lot mesure la file d'attente, pas le produit.** php-fpm est déjà dans l'image ⚠️ `pm.max_children=5` à régler. Repli à 15 min : `PHP_CLI_SERVER_WORKERS=8`. |
| **2** | **G4** — rendre le produit accessible (`MAIL_MAILER` explicite, colonnes 2FA) | **Sans cela, on ne peut pas ouvrir les 37 écrans**, donc ni finir le §11 du mandat, ni éprouver le principe 7. L'accès a été rendu par script, mais `A07-001` **bloque encore la première connexion**. |
| **3** | **G1** — le journal d'audit | C'est **l'instrument de preuve** de tout le reste, y compris de cet audit. Tant qu'il est tronquable et lisible tous espaces, **aucune vérification ultérieure n'a de valeur probante**. |
| **4** | **G2** — le cloisonnement au-delà de la porte | ⚠️ **Ordre interne impératif** : corriger `B12-012` (`sameWorkspace()` toujours vrai) **avant** `B12-003` (appeler les policies) — sinon on **arme** une comparaison fausse. |
| **5** | **G3** + `B10-004` — l'effacement et l'export RGPD | Obligation légale, et **le seul S0 dont un tiers subit le préjudice** sans le savoir. |
| **6** | `C18-016` (mock en production) · `B12-004` (signature forgée) · `A07-003` (runbook de rotation) | Trois portes ouvertes, indépendantes, peu coûteuses. |
| **7** | `A08-008` (droits non restaurés) · `A08-006` (anonymisation IP qui n'a jamais marché) · `A08-001` (71 échecs muets) | Exploitation : **une sauvegarde inutilisable ne se découvre que le jour où on en a besoin**. |
| **8** | `C19-007` — mise en balance et information art. 14 | **Rédaction par l'autopilote, validation par le dirigeant** (réserve n° 5 du §1). |
| **9** | `I48-001` + `I48-003` — créer une fiche personne | **Bloque l'étape 1a** : « une fiche se crée en 3 secondes » est **inatteignable par l'API**. |
| **10** | La navigation (`10_NAVIGATION-CIBLE.md`) | Lot à part entière, avec redirections et visite guidée refaite **une seule fois**. |
| **11** | S2 et S3, par domaine | Après. |

**Ce qui ne va PAS en P3** — et il faut le dire aussi :
`E32-002` (index vs copie) et `I48-008` (dépassement de périmètre) sont des **arbitrages**, pas des
défauts : ils vont dans `06_RESTE-WILL.md`. La **rotation des secrets** est **refusée** (`D-005`) et
n'est pas reproposée — mais `F40-007` (le mécanisme qui la rendrait **impossible**) reste un défaut à
corriger, indépendamment de la décision de la faire ou non.

---

## 5. Ce qui est SAIN, et qu'aucun correctif ne doit casser

Un audit qui ne liste que des défauts ne dit pas ce qu'il faut protéger. Mesuré, et à ne pas toucher :

- **Le canal entrant** — signature HMAC vérifiée **avant** le drapeau, **1 témoin positif et 4 négatifs joués**, idempotence adossée à un **index UNIQUE réel**, fenêtre ±300 s, cloisonnement en **503 sans écriture**. *C'est le patron à copier pour les 17 événements manquants du sens inverse.*
- **La CI backend** — **780 tests, 6 503 assertions**, bloquante et requise, PHPStan **niveau 8 `[OK]`**, et **zéro exclusion silencieuse** dans les configurations (0 `<exclude>`, 0 `@group`, 0 `->skip()`, 0 `.only`).
- **`SocleCrmTest`** — la seule garde qui compare les `CHECK` **réellement en base** aux constantes du code. *Le patron à réutiliser ; la pièce 2 s'en est servie et a paré le piège 22.*
- **`EtancheiteParTableTest`** — 11/11 verts, **sème 2 lignes dans 2 espaces sur 57/57 tables** pour qu'aucun contrôle ne soit vrai par vacuité, deux témoins négatifs.
- **203/203 colonnes temporelles en `timestamptz`**, zéro naïve ; 68 `CHECK`, 139 clés étrangères, 36 `UNIQUE` réellement en base ; **58/58 migrations déclarent un `down()`** ; aucun index invalide ni doublon.
- **La sauvegarde se restaure** : 725 Mo, **20 726 338 lignes revenues au nombre exact**, écart nul, témoin négatif joué. *(La question des droits reste ouverte — `A08-008`.)*
- **La barre latérale** — refondue à l'étape 0, **à compléter vers le §23.3, jamais à rouvrir**.
- **La pièce 1 de l'étape 1a** — bonne, mesurée avant/après aux deux volumes, **5 gardes vues rouges dans 5 modes de défaillance distincts**, dont une qui **nomme l'index attendu** parce que « pas de `Seq Scan` » serait passé vert sans lui. *Et elle retirait un **point de blocage global** de l'application sans que personne, y compris son auteur, ne l'ait vu.*
- **Le volume de référence** — 300 000 et 2 800 000 fiches, **versionnés et rejouables**.
- **Aucun secret de proxy en base** — vérifié **avec témoin négatif** (la même requête trouve bien `users.password_hash` et `totp_secret`).
