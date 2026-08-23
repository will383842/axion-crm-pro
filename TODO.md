# TODO — Axion CRM Pro

> **Ce fichier n'est pas la source de vérité du dépôt.** Il n'en a aucune, et
> c'est un progrès : la source de vérité, c'est le code, les workflows, et les
> gardes qui rougissent.
> Ce qui suit est une **liste de travaux restants**, chacun rattaché à une
> mesure datée. Un point sans mesure n'a rien à faire ici.
>
> Dernière réécriture : **2026-08-20**.
> Relu par une garde : `backend/tests/Feature/Infra/DocsDeDeploiementDisentLeVraiTest.php`
> vérifie que chaque commande, chaque cible `make` et **chaque chemin** cités
> ci-dessous existent réellement.

---

## Pourquoi ce fichier a été entièrement réécrit

Constats **A09-009 / A09-002** (S1). Jusqu'au 2026-08-20, ce fichier :

1. se déclarait **« source de vérité de ce qu'il reste à faire avant Sprint 1 »**,
   daté du 2026-05-16 ;
2. décrivait un dépôt **d'avant la première ligne de code** : « Démarrer
   uniquement après POCs validés », « Compte Hetzner Cloud à créer », « Domaine
   acheté (`axion-pro.com` ou autre) », « Coolify v4 vs k3s » ;
3. listait comme travaux à faire des choses **déjà faites** — `poc/SYNTHESIS.md`
   était à créer alors que le fichier existe depuis le 2026-05-16 ;
4. et renvoyait à un calendrier « Semaine 18 : promotion prod » alors que **la
   production sert depuis le 17 mai** et a connu, entre-temps, une fuite de
   données mesurée.

Un document qui ment est pire qu'un document absent : on le suit. Celui-ci
aurait envoyé un lecteur acheter un domaine et choisir entre Coolify et k3s,
trois mois après que la question a été tranchée par les faits — le CRM se
déploie par SSH direct, Coolify déploie le **site** Axion-IA, pas ce produit.

---

## Ce qui existe, mesuré le 2026-08-20

| Objet | Compté | Où |
|---|---|---|
| Migrations Laravel | **65** | `backend/database/migrations` |
| Fichiers de tests (Pest) | **159** | `backend/tests` |
| Commandes Artisan du produit | **55** | `backend/app/Console/Commands` |
| Modèles Eloquent | **18** | `backend/app/Models` |
| Composants / pages React | **87** `.tsx` | `frontend/src` |
| Workflows GitHub Actions | **18** | `.github/workflows` |
| Runbooks d'exploitation | **5** | `infra/runbooks` |

La production tourne sur Hetzner (`/opt/axion-crm-pro`), déployée par
`.github/workflows/deploy-direct-ssh.yml`, derrière une CI **bloquante** à 6
jobs. Le pipeline est décrit commande par commande dans
`_AUDIT/DEPLOY-PIPELINE.md`.

---

## §1 — Ce qui bloque, et qui n'est PAS réparable par du code

Ces trois points sont des **gestes d'exploitant**. Les traiter comme des
correctifs serait, dans les trois cas, déclencher en production une action
irréversible. Ils sont listés ici parce qu'ils sont ouverts, pas parce qu'un
agent doit les fermer.

### 1.1 Les purges RGPD n'ont jamais tourné — B17-009 (S0, 2026-08-20)

`CRM_PURGE_ENABLED` n'apparaît que **deux fois** dans tout le dépôt : sa
déclaration dans `backend/config/crm.php` et une ligne de `.env.example`. Elle
vaut `false` aux deux, et **aucun** fichier Compose, aucun script d'`infra`,
aucun workflow ne la pose. Les deux seules purges correctement écrites du dépôt
— `php artisan rgpd:purge-vivier` et `php artisan rgpd:purge-business-prospects` —
n'ont donc **jamais** été exécutées, et l'échéance CNIL (CVthèque 2 ans,
prospection 3 ans) n'est tenue par aucun automatisme.

Ce qui a été réparé : le **silence**. Le saut se journalise désormais en
`warning` à chaque passage du planificateur. L'inaction laisse une trace datée.

Ce qui reste, et qui appartient à Will : poser `CRM_PURGE_ENABLED=true` sur le
serveur, **après** une vérification à la main en `--dry-run`.

### 1.2 Le sens CRM → site ne s'ouvre pas d'un drapeau — B14-013 (S1)

`CRM_OUTBOUND_ENABLED` seul ne suffit pas : `SITE_CRM_WEBHOOK_URL` n'a aucun
défaut utilisable et n'est posé dans aucun fichier du dépôt. Un exploitant qui
« ouvre le canal » ouvre en réalité l'ingestion seule, et `crm:flush-outbound`
tourne toutes les 5 minutes en refusant faute de destination. Les trois clés
vont ensemble ; le détail est écrit dans `backend/config/crm.php`.

### 1.3 L'état du rôle applicatif en production est contredit dans le dépôt

Deux fichiers datés du même jour disent l'inverse l'un de l'autre sur
`CRM_DB_APP_ROLE_ENABLED` : les traces du constat A08-001 l'expliquent par
« depuis l'armement du rôle applicatif » (donc `true`), quand
`backend/app/Console/Commands/PartmanMaintenir.php` écrit « aujourd'hui …
vaut false ». **Tant que ce n'est pas tranché, aucune phrase du dépôt sur ce que
fait la RLS en production n'est établie.** Voir la note finale de
`_AUDIT/DEPLOY-PIPELINE.md`.

---

## §2 — Documents encore périmés (dette de vérité)

Chacun de ces fichiers décrit un dépôt qui n'existe plus. Aucun n'est couvert
par une garde à ce jour.

| Fichier | Ce qu'il prétend | Ce qui est |
|---|---|---|
| `_REPORTS/PROGRESS.md` | S2 « en cours », S3 à S12 « pending » | Le produit sert en production depuis le 17 mai |
| `_AUDIT/TODO-AXION-CRM-PRO.md` | Second fichier TODO, arrêté au 2026-05-18 | Deux listes de tâches concurrentes, aucune à jour |
| `_PROMPTS/PROMPT_AUTOPILOT_SPRINT_1_TO_12.md` | Plan de sprints à dérouler | Les sprints sont derrière nous |
| `poc/SYNTHESIS.md` | « 1 / 5 POCs validés », décision d'avant-projet | Les quatre POCs restants n'ont jamais été rejoués, et le produit s'est construit sans eux |

⚠️ `backend/config/crm.php` renvoie en tête à un ordre de mission situé sous
\_PLANS/ — **répertoire qui n'existe pas dans ce dépôt**. Le renvoi est mort.
(Il n'est volontairement pas écrit ici comme un chemin : la garde qui relit ce
fichier exige que tout chemin cité existe.)

---

## §3 — Conformité

Produits, datés, et à jour :

⚠️ **Ces deux pièces ont quitté ce dépôt le 2026-08-23**, quand il est repassé
en **public**. Elles vivent dans **`will383842/axion-crm-pro-audit`** (privé) —
voir `_REPORTS/LISEZ-MOI-PIECES-DEPLACEES.md`. *Les chemins ne sont donc plus
cités ici : la garde `A09` exige que tout chemin cité existe, et elle a raison.*

- **AIPD** — remplace la DPIA de mai, qui décrivait des mesures « en place »
  qui ne l'étaient pas.
- **Registre des violations** — au sens de l'article 33 §5 du RGPD.

Restent ouverts :

- [ ] DPA sous-traitants : Anthropic, Mistral AI, Cloudflare, Webshare, IPRoyal,
      Backblaze — aucun signé à ce jour.
- [ ] Pentest externe. `make pentest` joue un auto-contrôle OWASP interne, ce
      qui n'en tient pas lieu.
- [ ] Exercice de reprise réel. `make dr-drill` existe ; le RTO mesuré n'est
      consigné nulle part. La procédure est dans `infra/runbooks/04-restore-dr.md`.

---

## §4 — Gestes de sécurité en attente

- [ ] Rotation du mot de passe `WJullin1974/*` : à considérer comme brûlé sur
      **tous** les services. Reste ouvert depuis mai.
- [ ] Suites de la fuite du 2026-08-19 (Postgres 55432 et Redis 56379 ouverts
      sur internet, 4 295 349 fiches lisibles) : le code est refermé et gardé
      (`infra/scripts/verifier-ports-publies.sh` est désormais appelé par le
      déploiement, sans tolérance). Le volet **notification CNIL** est un
      brouillon, pas un envoi.

---

## §5 — Comment vérifier soi-même

```bash
make test-backend      # suite Pest complète, dans le conteneur api
make db-rebuild-check  # exige que migrate:fresh passe DEUX fois de suite
make audit             # audit:verify-chain (chaîne de hachage)
```

Pourquoi `make db-rebuild-check` et pas `make fresh` : la base n'était pas
reconstructible, et la panne était **invisible au premier passage** — elle
n'apparaissait qu'à la deuxième reconstruction. Le détail, avec les sorties
rouges verbatim, est dans `_REPORTS/2026-08-18_RECONSTRUCTION-BASE.md`.

---

## §6 — Liens

- Dépôt : https://github.com/will383842/axion-crm-pro
- Sommaire de la spécification : `spec/00_INDEX.md`
- Pipeline de déploiement : `_AUDIT/DEPLOY-PIPELINE.md`
- Runbooks d'exploitation : `infra/runbooks/04-restore-dr.md` et ses voisins
