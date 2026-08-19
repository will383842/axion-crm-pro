# AGENT 16 — Journal d'audit, chaîne de hachage, traçabilité et registre AI Act

> Périmètre : `AuditHashChain`, `AuditLogger`, `AuditHashChainLogger`, la commande
> `audit:verify-chain` planifiée à 03:00, la migration `audit_logs_prev_hash_default`,
> le modèle `AuditLog`, la table `audit_logs`, les routes `GET /audit-logs`,
> `GET /audit-logs/verify-chain`, `GET|POST /ai-act/register`, le contrôleur
> `Api/AiActRegisterController`, et les §20 / §21 du cahier des charges.

## 0. Référence et conditions de mesure — à lire avant tout

- Le dossier commun nomme `main = c0c453d`. **`git log` relu au début de mon travail :
  `main = 1145473`.** Quatre commits documentaires sont arrivés depuis
  (`17ba4f1`, `b53338c`, `bb60473`, `1145473`, tous `docs(cnil)` / `docs(rgpd)`).
  Aucun ne touche mon périmètre : `git log c0c453d..HEAD -- backend/` est vide de
  code. **Toutes mes mesures valent donc pour `c0c453d` comme pour `1145473`**, et
  je nomme `1145473` dans chaque constat puisque c'est ce que j'ai réellement lu.
- Base jetable : **`axion_crm_a16`**, créée par
  `CREATE DATABASE axion_crm_a16 TEMPLATE axion_crm_test`. Aucune écriture en
  production, aucune écriture dans `crmpro-wt-etape1a`, aucun fichier du produit
  modifié. Les deux scripts de mesure ont été déposés dans `/tmp` du conteneur,
  jamais dans le dépôt.
- ⚠️ **Un agent parallèle a relancé un `migrate:fresh` sur `axion_crm` pendant mon
  travail** (vers 12:05–12:30 UTC). Le contenu réel du journal que j'avais relevé à
  12:00 n'est plus rejouable ; il est transcrit intégralement dans
  `04_PREUVES/agent-16/04_contenu-reel-du-journal-avant-reconstruction.txt`, avec
  l'avertissement. **Tous les constats structurels ci-dessous sont, eux, prouvés sur
  `axion_crm_a16` et rejouables** (fichiers 01 et 02).
- Piège 21 respecté : `docker exec` sans `-T`. Aucune de mes commandes n'a échoué
  sur `unknown shorthand flag`.
- L'atelier est **très lent en E/S** : un `php artisan --version` prend **2 min 39 s**
  (mesuré). J'ai donc groupé toutes les vérifications de chaîne dans **un seul
  amorçage** de Laravel, en appelant `Artisan::call('audit:verify-chain')` — la vraie
  classe de commande, le vrai code de sortie.

---

## 1. Tableau de grille — un objet par ligne, un point de mission par colonne

Légende : ✅ mesuré conforme · 🔴 mesuré défaillant · ⚪ sans objet · ❔ non vérifié (raison donnée au §5)

| Objet | 1. Complétude | 2. Immuabilité | 3. Alerte 03:00 | 4. `prev_hash` par défaut | 5. Rétention | 6. Registre AI Act | 7. Cloisonnement | 8. §20 / §21 |
|---|---|---|---|---|---|---|---|---|
| `app/Services/Audit/AuditHashChain.php` | 🔴 `canonical()` ne couvre que 7 des 11 colonnes utiles : `user_agent`, `created_at`, `prev_hash`, `id` en sont dehors | 🔴 détecte l'altération d'une colonne canonicalisée (**prouvé**), **ne détecte ni la modification de `created_at`/`user_agent` ni la suppression de la dernière ligne** (**prouvé**) | ⚪ le service ne notifie pas | ✅ le service fournit toujours `prev_hash` explicitement (`GENESIS_PREV_HASH`, 64 zéros) — le défaut SQL est bien inatteignable par ce chemin | 🔴 `verifyChain()` repart toujours de GENESIS : toute purge de tête rend le contrôle rouge à jamais (**prouvé**) | ⚪ | ⚪ | 🔴 §20 « journal horodaté » : l'horodatage n'est pas protégé |
| `app/Support/AuditLogger.php` (table `business_events`) | 🔴 **5 points d'appel dans tout le produit**, aucun sensible (`audience.refreshed`, `company.archived`, `email.verified`, `company.tags_synced`) ; `business_events` = **0 ligne** | ⚪ aucune chaîne, table modifiable librement | ⚪ | ⚪ | ❔ aucune purge ne la vise | ⚪ | ✅ colonne `workspace_id` obligatoire, `log()` refuse sans elle | 🔴 ne couvre aucun geste du §20 |
| `app/Http/Middleware/AuditHashChainLogger.php` | 🔴 ne journalise que `POST/PUT/PATCH/DELETE` → **les 50 routes `GET` de `routes/api.php` sont muettes**, dont les 4 exports et la lecture du journal | 🔴 alimente une chaîne tronquable | ⚪ | ✅ n'écrit jamais sans `prev_hash` | ⚪ | ⚪ | 🔴 `workspace_id` renseigné pour 8 lignes sur 80 (mesuré avant reconstruction) | 🔴 §20 exige « consultation, export, téléchargement » au journal : aucun des trois n'est un verbe mutatif |
| `audit:verify-chain` + `Schedule::…->dailyAt('03:00')` | ✅ balaie toute la table (`orderBy('id')`, sans `--max`) | ✅ rend bien 1 sur chaîne rompue, 0 sur chaîne intacte (**témoins positif ET négatif joués**) | 🔴 `output = '/dev/null'`, `afterCallbacks = 0`, `beforeCallbacks = 0` : ni `onFailure`, ni `emailOutputOnFailure`, ni `pingOnFailure`. **Personne n'est prévenu.** | 🔴 une ligne insérée sans `prev_hash` fait rougir la commande comme une falsification (faux positif, **joué**) | 🔴 rougira définitivement à la première partition détachée | ⚪ | ⚪ | 🔴 §20 : un contrôle d'intégrité dont la sortie va dans `/dev/null` n'est pas un journal exploitable |
| Migration `2026_08_16_000001_audit_logs_prev_hash_default` | ⚪ | ✅ elle a bien aligné le défaut SQL (`repeat('0',64)`) sur le code — vérifié en base | ⚪ | ✅ **son intention est atteinte** : le défaut n'est plus `'GENESIS'`. 🔴 **mais** le commentaire de `AuditHashChain.php:28` affirme encore le contraire | ⚪ | ⚪ | ⚪ | ✅ elle ne réécrit aucune ligne existante — geste correct sur un journal |
| Modèle `App\Models\AuditLog` | ⚪ | ⚪ | ⚪ | ⚪ | ⚪ | ⚪ | 🔴 **`getGlobalScopes()` = `[]`** (mesuré). Aucun filtre d'espace. | 🔴 aucun `actor`, `target`, `severity` : les colonnes que l'écran affiche n'existent pas |
| Table `audit_logs` (+ 14 partitions) | 🔴 11 chemins distincts pour 80 lignes, **toutes de type `POST`** | 🔴 `UPDATE`/`DELETE` libres : aucun trigger, aucun `REVOKE`, aucune contrainte d'append-only | ⚪ | ✅ `column_default = repeat('0'::text, 64)`, `NOT NULL` | 🔴 `partman.part_config` : `retention = 24 months`, `retention_keep_table = t`, 14 partitions mensuelles | ⚪ | 🔴 **`relrowsecurity = f`, 0 politique RLS sur les 15 relations `audit_logs*`** — alors que `ai_act_register` porte une politique *forcée* | 🔴 |
| `GET /audit-logs` | ⚪ | ⚪ | ⚪ | ⚪ | ⚪ | ⚪ | 🔴 **fuite inter-espaces prouvée** : 0 `authorize()`, 0 `workspace`, 0 `Gate` dans le contrôleur ; `AuditLogPolicy::viewAny` (rôle `owner`) n'est jamais appelée | 🔴 §20 : le journal est lisible par tout compte authentifié, de tout espace |
| `GET /audit-logs/verify-chain` | ⚪ | ✅ appelle le même `verifyChain()` | 🔴 rend `200 {"valid": false}` — un appelant qui ne lit pas le corps voit un succès ; sur exception, rend `200 {"valid": false, "degraded": true}`, indiscernable d'une vraie falsification | ⚪ | ⚪ | ⚪ | 🔴 aucune autorisation non plus | 🔴 |
| `GET /ai-act/register` | 🔴 **rend `['data' => []]` en dur** — la table n'est jamais interrogée | ⚪ | ⚪ | ⚪ | ⚪ | 🔴 **table `ai_act_register` = 0 ligne** | ⚪ la route ne lit rien, donc rien à cloisonner | 🔴 §21.4 « le produit doit tenir le registre correspondant — **il existe déjà dans CRM Pro** » : la phrase est fausse |
| `POST /ai-act/register` | 🔴 `notImplemented('11')` → 501 | ⚪ | ⚪ | ⚪ | ⚪ | 🔴 aucune saisie possible : le registre ne peut pas être alimenté, ni à la main ni automatiquement | ⚪ | 🔴 |
| `Api/AiActRegisterController` | 🔴 12 lignes de code, aucune requête SQL, aucun `use` de modèle | ⚪ | ⚪ | ⚪ | ⚪ | 🔴 `AiActRegisterSeeder` écrirait 1 ligne (`risk_class = 'limited'`), mais il n'est appelé que depuis `DatabaseSeeder`, jamais joué : **0 ligne en base** | ⚪ | 🔴 §21.4 classe la notation de candidats en **haut risque** ; la seule entrée jamais écrite se déclare `limited` |

---

## 2. Point 1 — Complétude : ce que le journal couvre, et ce qu'il ne couvre pas

`routes/api.php` déclare **111 routes** que mon motif a comptées (50 `GET`, 43 `POST`,
11 `PUT`, 7 `DELETE`) ; l'inventaire de l'agent 2 en annonce 112 — l'écart d'une
déclaration tient à mon motif, je ne le corrige pas ici. Le middleware
`AuditHashChainLogger` journalise **les 61 routes mutatives** et **aucune des 50
routes `GET`**.

### Tableau des écritures sensibles

| # | Geste sensible | Existe dans le produit ? | Journalisé dans `audit_logs` ? | Avec quel détail |
|---|---|---|---|---|
| 1 | Création d'utilisateur | 🔴 **non** — `UsersController::store` rend 501 | ⚪ sans objet | — |
| 2 | Modification d'utilisateur | 🔴 **non** — `update` rend 501 | ⚪ sans objet | — |
| 3 | Suppression d'utilisateur | 🔴 **non** — `destroy` rend 501 | ⚪ sans objet | — |
| 4 | Changement de rôle / de permission | 🔴 **non** — aucune occurrence de `role`, `permission`, `assignRole` dans `UsersController` (68 l.), aucune route | ⚪ sans objet | — |
| 5 | Bascule d'espace de travail | 🔴 **non** — aucune route | ⚪ sans objet | — |
| 6 | Modification des réglages d'espace | 🔴 **non** — `WorkspaceController::update` rend 501 | ⚪ sans objet | — |
| 7 | Connexion réussie | ✅ | ✅ **oui** | `POST` + chemin + 200 + IP + `user_id` |
| 8 | Échec de connexion | ✅ | ✅ **oui** | `POST` + 422/419 + IP, **sans acteur ni adresse tentée** |
| 9 | Déconnexion | ✅ | ✅ oui | méthode + chemin |
| 10 | 2FA (mise en place, confirmation, vérification) | ✅ | ✅ oui | méthode + chemin + statut |
| 11 | Lien magique demandé / consommé | ✅ | ✅ oui | méthode + chemin |
| 12 | Mot de passe oublié / réinitialisé | ✅ | ✅ oui | méthode + chemin |
| 13 | Effacement RGPD depuis la console | ✅ | ✅ **oui, deux fois** (HTTP + `GDPR_ERASURE`) | ligne dédiée, mais `workspace_id = null`, `user_id = null`, identité **seulement en empreinte** |
| 14 | Effacement RGPD venu du site | ✅ | ✅ oui (`GDPR_ERASURE_BISYSTEM`) | idem |
| 15 | Purge du vivier (planifiée) | ✅ | ✅ oui (`GDPR_PURGE_VIVIER`) | espace + empreinte des compteurs |
| 16 | Purge des prospects business | ✅ | ✅ oui (`GDPR_PURGE_BUSINESS`) | espace + empreinte |
| 17 | Purge de rétention (`retention:purge`) | ✅ | 🔴 **non** | — |
| 18 | Purge des `scraper_runs` (`retention:prune-scraper-runs`) | ✅ | 🔴 **non** | — |
| 19 | Anonymisation des IP (`rgpd:anonymize-ips`) | ✅ | 🔴 **non** | — |
| 20 | Purge des emails médias (`media:clean-emails`) | ✅ | 🔴 **non** | — |
| 21 | Purges prospection non-commercial / non-diffusible | ✅ | 🔴 **non** | — |
| 22 | **Export CSV des entreprises** (`GET /companies/export`) | ✅ | 🔴 **non** | — |
| 23 | **Export CSV des médias** (`GET /media/export`) | ✅ | 🔴 **non** | — |
| 24 | **Export CSV des journalistes** (`GET /journalists/export`) | ✅ | 🔴 **non** | — |
| 25 | **Export de portabilité RGPD** (`GET /rgpd/export/{token}`) | ✅ | 🔴 **non** | — |
| 26 | **Consultation d'une fiche personne** | ✅ | 🔴 **non** — §20 l'exige nommément | — |
| 27 | **Consultation du journal d'audit lui-même** | ✅ | 🔴 **non** (et le middleware s'auto-exclut explicitement) | — |
| 28 | Action de masse (`POST /bulk`, tags en masse, enrichissement en masse) | ✅ | ✅ oui | méthode + chemin, **jamais le périmètre touché** |
| 29 | Suppressions unitaires (entreprise, contact, journaliste, tag, audience, campagne) | ✅ | ✅ oui | l'identifiant est dans le chemin |
| 30 | Opposition d'un journaliste | ✅ | ✅ oui | méthode + chemin |
| 31 | Changement d'un drapeau fonctionnel | ✅ (variables d'environnement + redémarrage) | 🔴 **non** — aucune route d'écriture, `GET /config/features` seul ; un changement de drapeau ne laisse aucune trace | — |
| 32 | Écoute / téléchargement d'un enregistrement (§21.1) | 🔴 **non** — fonctionnalité absente | ⚪ sans objet | — |

### Le compte

- **Gestes sensibles qui existent dans le produit : 25.**
- **Journalisés : 13.**
- **Non journalisés : 12** — dont **les 4 exports de données nominatives**, la
  consultation d'une fiche, la lecture du journal, le changement de drapeau, et
  **5 des 7 commandes destructives**.
- **Gestes exigés par le §20/§21 qui n'existent pas du tout : 7** (tout le cycle de
  vie des comptes, des rôles et des permissions, plus l'écoute d'enregistrement).

Et le détail journalisé est toujours le même quintuplet : **méthode, chemin, code
HTTP, IP, empreinte du corps**. Jamais l'objet touché, jamais l'avant/après, jamais
le motif. Le journal sait qu'un `DELETE` a eu lieu sur `api/v1/tags/17` ; il ne sait
pas ce que le tag 17 contenait, ni qui l'a demandé quand l'acteur est absent.

---

## 3. Point 2 — Immuabilité : la chaîne a été mise à l'épreuve

Preuve complète : `04_PREUVES/agent-16/01_chaine-alteration-temoin-positif-et-negatif.txt`
(script rejouable : `01_script-alteration.php`). Base jetable `axion_crm_a16`.

**Témoin positif** (règle 3 : le contrôle est d'abord prouvé capable de trouver) —
3 maillons écrits par le vrai service, puis :

```
========== 4. ALTERATION SQL DIRECTE : status_code 204 -> 200 sur la ligne du milieu ==========
cible id=2  status_code avant = 204
status_code apres = 200

========== 5. TEMOIN NEGATIF — la chaine doit ROUGIR ==========
[alteree-status] audit:verify-chain -> code de sortie = 1
[alteree-status] sortie :
Audit hash chain INVALIDE — possible falsification détectée.

========== 6. REMISE EN ETAT ==========
[restauree] audit:verify-chain -> code de sortie = 0
[restauree] sortie :
Audit hash chain OK — aucune anomalie détectée.
```

**Oui, la chaîne détecte une altération d'une colonne canonicalisée, et repasse verte
une fois la ligne remise en état.** C'est le seul point de ce rapport où la promesse
tient.

**Trois altérations qu'elle NE détecte PAS** — même base, même commande, même session :

```
========== 7. ALTERATION DU user_agent (colonne STOCKEE mais HORS canonical()) ==========
user_agent avant = agent-16
user_agent apres = FALSIFIE-PAR-AGENT-16
[alteree-user-agent] audit:verify-chain -> code de sortie = 0
Audit hash chain OK — aucune anomalie détectée.

========== 8. ALTERATION DU created_at (colonne STOCKEE mais HORS canonical()) ==========
created_at avant = 2026-08-19 12:03:06+00
created_at apres = 2019-01-01 00:00:00+00
[alteree-created-at] audit:verify-chain -> code de sortie = 0
Audit hash chain OK — aucune anomalie détectée.

========== 9. SUPPRESSION DE LA DERNIERE LIGNE (troncature de queue) ==========
supprime id=3 ; reste 2 lignes
[queue-tronquee] audit:verify-chain -> code de sortie = 0
Audit hash chain OK — aucune anomalie détectée.
```

Trois gestes de falsification, trois fois « OK — aucune anomalie détectée ».

Et la chaîne n'est **protégée par aucun secret** :

```
===== A. SECRET DE CHAINE — valeur reelle rendue par env() =====
type      = string
longueur  = 0
var_export= ''
=> le secret concatene au SHA-256 est LA CHAINE VIDE (aucun secret)
ligne .env : AUDIT_HASH_CHAIN_SECRET=
```

`AuditHashChain::__construct` fait `env('AUDIT_HASH_CHAIN_SECRET', 'dev-only-secret-change-me')`.
La clé **est présente** dans `.env` avec une valeur vide : `env()` rend `''`, pas le
défaut. Le hachage est donc `sha256(prev_hash || json_canonique || '')` — une fonction
publique, sans clé. Qui peut écrire dans la base peut **recalculer toute la chaîne**.

---

## 4. Point 3 — Que fait `audit:verify-chain` si la chaîne est rompue, et qui l'apprend ?

Mesuré par introspection de l'objet `Illuminate\Console\Scheduling\Event` réel, dans
le même amorçage (fichier de preuve 01, section 12) :

```
========== 12. QUOI QUE FASSE verify-chain, OU VA LA SORTIE ? ==========
Schedule audit:verify-chain — hooks declares :
  afterCallbacks = 0 element(s)
  beforeCallbacks = 0 element(s)
  output = '/dev/null'
  shouldAppendOutput = false
  onOneServer = false
  expression = 0 3 * * *
```

Ce que cela produit à 03:00, chaîne rompue :

1. La commande rend **1** et écrit `Audit hash chain INVALIDE — possible falsification
   détectée.` sur sa sortie standard — **redirigée vers `/dev/null`**. Le message
   métier est perdu. Vérifié dans les journaux réels du planificateur :
   `⇂ '/usr/local/bin/php' 'artisan' anomaly:detect > '/dev/null' 2>&1`.
2. Laravel *fait* quelque chose du code non nul — j'ai lu le code du cadriciel plutôt
   que de le supposer (`vendor/laravel/framework/…/ScheduleRunCommand.php`) :
   `if ($event->exitCode != 0 && ! $event->runInBackground) { throw new Exception(…); }`
   puis `catch { … $this->handler->report($e); }`. Il reste donc **une ligne `FAIL`
   dans la sortie du conteneur `axion-crm-scheduler`** et **un appel à `report()`**.
3. Mais `report()` ne va nulle part : `config/sentry.php` lit **`SENTRY_LARAVEL_DSN`**,
   variable **absente de `.env.example`** ; ce qui y figure est `GLITCHTIP_DSN=`,
   **vide**, et **lu par personne**. `app/Notifications/` n'existe pas. Le
   commentaire de la commande dit lui-même l'intention non tenue :
   `// En prod : envoi Slack/Telegram + ouverture incident.`
4. Il reste `docker logs axion-crm-scheduler`. Rien dans le dépôt n'agrège, n'archive
   ni n'alerte sur cette sortie.

**Réponse : personne n'est prévenu.** Le code de sortie est bien non nul, mais il ne
franchit pas la frontière du conteneur autrement que dans un flux que rien ne lit.

---

## 5. Point 4 — `prev_hash` par défaut : joué

État réel de la colonne (mesuré, base `axion_crm_a16`) :
`column_default = repeat('0'::text, 64)`, `is_nullable = NO`.

La migration du 2026-08-16 a donc **atteint son but** : le défaut n'est plus
`'GENESIS'`. Et il vaut exactement `AuditHashChain::GENESIS_PREV_HASH`.

Joué (fichier de preuve 01, section 10) :

```
========== 10. INSERT SANS prev_hash — le DEFAUT SQL repeat(0,64) est-il detecte ? ==========
ligne injectee id=4 prev_hash=0000000000000000000000000000000000000000000000000000000000000000 current_hash=deadbeef
[insert-sans-prev-hash] audit:verify-chain -> code de sortie = 1
Audit hash chain INVALIDE — possible falsification détectée.
```

**Elle ne passe pas inaperçue : elle casse la chaîne.** Et elle la casse en criant
« falsification » alors qu'aucune n'a eu lieu — exactement le faux positif que le
commentaire de la migration annonçait. La ligne devient un mur : tout ce qui suit est
déclaré rompu.

Deux nuances mesurées, à ne pas taire :
- 🔴 Le commentaire de `AuditHashChain.php:28-31` affirme encore que « la colonne
  `audit_logs.prev_hash` porte **encore** un DEFAULT SQL `'GENESIS'` ». **C'est faux
  depuis la migration du 2026-08-16.** Un lecteur du code fait le mauvais diagnostic.
- Le défaut n'est pas, en lui-même, le trou : un faussaire n'a pas besoin de lui,
  puisque le secret est vide (§3) et qu'il peut calculer le vrai `current_hash`.

---

## 6. Point 5 — Rétention : la purge n'efface pas la preuve, elle en détruit la vérifiabilité

Mesuré sur la base jetable pleinement migrée :

```
   parent_table    | retention | retention_keep_table | partition_interval | premake
 public.audit_logs | 24 months | t                    | 1 mon              |       6
 partitions : 14
```

`RetentionPurge` (`retention:purge`, planifiée à 04:00) **ne touche pas** `audit_logs`
— son en-tête le dit (« pg_partman gère le detach ») et son code le confirme : ses
trois requêtes visent `email_validations`, `notifications`, `scraper_runs`.
`partman.run_maintenance` n'est planifié nulle part (déjà relevé par
`_REPORTS/AIPD_2026-08-18.md`). **Aujourd'hui, rien ne purge le journal.**

Le danger est ailleurs, et il n'est écrit nulle part : **`infra/runbooks/02-disk-full.md`
§3 demande explicitement à un opérateur de jouer la commande de détachement** :

```bash
docker exec -it axion-crm-postgres psql -U axion -d axion_crm -c "
  SELECT partman.run_maintenance('public.audit_logs', p_jobmon := false);
"
```

Avec `retention = 24 months`, cette commande détache la partition la plus ancienne.
Or `verifyChain()` repart **toujours** de `GENESIS_PREV_HASH` sur la plus ancienne
ligne survivante. Joué :

```
========== 11. SUPPRESSION DE LA PREMIERE LIGNE (ce que ferait une purge de retention) ==========
supprime la premiere ligne id=1 ; reste 1 lignes
[tete-purgee] audit:verify-chain -> code de sortie = 1
Audit hash chain INVALIDE — possible falsification détectée.
```

Un opérateur qui suit le runbook un soir de disque plein rend le contrôle d'intégrité
**définitivement rouge**, sans qu'aucune falsification n'ait eu lieu — et sans que
personne ne le voie (§4).

---

## 7. Point 6 — Le registre AI Act : compté

| Objet | Mesure |
|---|---|
| `ai_act_register` sur `axion_crm` (avant reconstruction) | **0 ligne** |
| `ai_act_register` sur `axion_crm` (après reconstruction) | **0 ligne** |
| `ai_act_register` sur `axion_crm_a16` | **0 ligne** |
| `llm_usage` (le journal d'usage des modèles) | **0 ligne** |
| `business_events` | **0 ligne** |
| `llm_use_cases` | 1 ligne |
| Ce que rend `GET /ai-act/register` | `['data' => []]` **en dur** — la table n'est jamais interrogée |
| Ce que rend `POST /ai-act/register` | **501** `notImplemented('11')` |

Le contrôleur fait 12 lignes de corps utile, sans un seul `use` de modèle et sans une
seule requête. **Le registre n'est ni alimenté automatiquement par l'usage des modèles,
ni saisissable à la main : il n'est pas alimentable du tout.**

Un `AiActRegisterSeeder` existe et écrirait **1 ligne** (« LLM Router — Classification
Axion-IA », `risk_class = 'limited'`). Il n'est appelé que depuis `DatabaseSeeder`, et
il n'a jamais été joué : la table est vide sur les trois bases mesurées. *Note pour le
piège 22 : ce seeder fait bien un `updateOrInsert` sur un référentiel, mais il n'est
appelé par aucune migration — le piège ne se déclenche pas ici.*

**Comparé à ce que l'AI Act exige d'un registre** (art. 11-12, annexe IV — description
du système, finalité, fournisseur, données d'entraînement/entrée, supervision humaine,
et **journalisation automatique de l'usage** pendant toute la durée de vie) :

| Exigence | État mesuré |
|---|---|
| Liste des systèmes déployés | 🔴 0 ligne |
| Finalité de chaque système | ⚪ colonne `purpose` existe, jamais remplie |
| Fournisseur et modèle | ⚪ colonnes existent, jamais remplies |
| Classification du risque | 🔴 la seule entrée jamais rédigée se déclare `limited` alors que le §21.4 du cahier des charges classe la notation de candidats en **haut risque** |
| Journal d'usage automatique (art. 12) | 🔴 `llm_usage` = **0 ligne** ; aucun lien code entre `LLMRouterService` et `ai_act_register` |
| Responsable, statut, date de dernière revue | 🔴 **les colonnes n'existent pas en base** alors que l'écran les affiche |
| Analyse d'impact (DPIA) rattachée | ⚪ colonne `dpia_url` existe, jamais remplie |

Le §21.4 affirme : « le produit doit tenir le registre correspondant — **il existe déjà
dans CRM Pro** ». **Ce qui existe est une table vide et une route qui rend une liste
vide en dur.**

---

## 8. Point 7 — Cloisonnement : joué, un espace voit l'autre

Preuve : `04_PREUVES/agent-16/02_cloisonnement-et-secret.txt`.

Deux espaces créés sur la base jetable, une ligne d'audit écrite par le vrai service
pour chacun, puis le contexte d'espace posé exactement comme le fait
`SetCurrentWorkspace` — et la requête exacte du contrôleur rejouée :

```
espace A = 3c0f557c-46ed-40ba-8559-5a90f13ce6f0
espace B = 70a7c565-d7b6-465f-95c3-151e16aacd06
contexte pose : app.current_workspace_id = 70a7c565-d7b6-465f-95c3-151e16aacd06

-- ce que la requete du controleur (AuditLog::query()->orderByDesc('id')->paginate(50)) rend a B :
  id=6 workspace=70a7c565-… (espace B) path=api/v1/companies
  id=5 workspace=3c0f557c-… <<< ESPACE A, VU PAR B path=api/v1/companies/SECRET-DE-A

scopes globaux du modele AuditLog : []
politiques RLS sur audit_logs*    : 0
role applicatif BYPASSRLS ?       : {"u":"axion","rolbypassrls":true,"rolsuper":true}

-- le controleur appelle-t-il authorize() ?
occurrences de 'authorize'  : 0
occurrences de 'workspace'  : 0
occurrences de 'Gate'       : 0
```

**Quatre gardes possibles, quatre absentes** :
1. Pas de `where('workspace_id', …)` dans le contrôleur.
2. Pas de portée globale sur le modèle.
3. Pas de politique RLS sur la table ni sur ses 14 partitions — alors que
   `ai_act_register`, elle, en porte une *forcée*.
4. `AuditLogPolicy::viewAny()` existe et exige le rôle `owner` — **elle n'est jamais
   appelée**. Elle est du code mort.

Et même si (3) existait, elle serait sans effet : l'application se connecte avec le rôle
`axion`, **SUPERUSER et BYPASSRLS**.

---

## 9. Point 8 — Confrontation au §20 et au §21 du cahier des charges

### §20 « Utilisateurs, rôles et sécurité »

| Exigence du §20 | État mesuré |
|---|---|
| Comptes individuels, invitation par e-mail | 🔴 `UsersController::store` = 501 |
| Rôles paramétrables (5 profils) | 🔴 aucune route, aucun code de rôle dans `UsersController` |
| Permissions fines (voir, créer, modifier, supprimer, **exporter**, enregistrements, ressentis, franchir la frontière entre espaces) | ⚪ Spatie est installé et `data.export` est posé sur les exports ; le reste n'est pas paramétrable |
| Attribution, transfert, délégation | 🔴 absent |
| Double authentification | ✅ routes 2FA présentes et journalisées |
| Sessions listées et révocables | 🔴 aucune route |
| **« Journal d'audit horodaté : consultation, modification, export, suppression, écoute, téléchargement »** | 🔴 **2 des 6 verbes sont couverts** (modification, suppression). **Consultation : non. Export : non. Écoute et téléchargement : la fonctionnalité n'existe pas.** Et l'**horodatage n'est pas protégé** par la chaîne (§3). |
| Sauvegardes et restauration éprouvée | ❔ hors périmètre |

### §21 « Données personnelles et conformité »

| Exigence | État mesuré |
|---|---|
| §21.1 « toute écoute et tout téléchargement consignés » | 🔴 fonctionnalité absente ; et les téléchargements qui *existent* (les 4 exports, tous en `GET`) ne sont pas consignés |
| §21.2 « suppression définitive d'une personne, **avec compte rendu de ce qui a été effacé** » | 🟠 l'effacement écrit bien une ligne de chaîne — mais elle ne porte que `payload_hash = sha256(email\|téléphone)`, `workspace_id = null`, `user_id = null`. **Le journal ne dit ni qui a été effacé, ni qui l'a demandé.** Un compte rendu n'est pas une empreinte. |
| §21.2 « durées de conservation par catégorie, purge automatique avec alerte préalable » | 🔴 aucune alerte préalable ; `retention:purge` ne journalise rien |
| §21.3 « le produit enregistre qui a décidé quoi et sur quel fondement » | 🔴 le journal enregistre une méthode et un chemin |
| §21.4 « le produit doit tenir le registre correspondant — il existe déjà dans CRM Pro » | 🔴 **0 ligne, route en dur, écriture 501** |
| §21.4 « chaque note automatique renvoie à la version de la grille et à la transcription » | 🔴 `llm_usage` = 0 ligne, aucun lien vers le registre |
| §21.4 « sous-traitants inscrits au registre **avant** leur mise en service » | 🔴 le registre est vide ; `AiActRegisterSeeder` nomme Anthropic et Mistral, il n'a jamais été joué |

---

## 10. Constats

### [B16-001] La chaîne d'audit est hachée sans secret : `AUDIT_HASH_CHAIN_SECRET` est la chaîne vide
- Sévérité      : S0 bloquant
- Domaine       : sécurité / conformité
- Référence     : main 1145473 (identique à c0c453d sur ce fichier)
- Emplacement   : `backend/app/Services/Audit/AuditHashChain.php:33` ; `.env` du conteneur `axion-crm-api` ; `.env.example:218`
- Constat       : `env('AUDIT_HASH_CHAIN_SECRET', 'dev-only-secret-change-me')` rend `''` (chaîne vide, longueur 0) parce que la clé est présente mais vide dans `.env` — le défaut n'est donc jamais employé, et le condensat vaut `sha256(prev_hash || json_canonique || '')`.
- Preuve        : `04_PREUVES/agent-16/02_cloisonnement-et-secret.txt`, section A : `type = string / longueur = 0 / var_export = '' / ligne .env : AUDIT_HASH_CHAIN_SECRET=`
- Témoin négatif: le même relevé imprime la ligne brute du `.env` et la longueur ; si la variable avait porté une valeur, la longueur eût été non nulle et `var_export` l'eût affichée. Le contrôle est donc capable de distinguer les deux cas.
- Impact        : l'algorithme est intégralement dans le dépôt. Sans secret, quiconque peut écrire dans `audit_logs` (accès base, sauvegarde restaurée, injection SQL, administrateur d'infrastructure) peut **recalculer une chaîne entièrement cohérente** après avoir réécrit ou supprimé n'importe quelle ligne. La chaîne ne résiste alors qu'à la corruption accidentelle, pas à son unique adversaire. C'est la propriété que le produit met en avant (§21.4 « mitigations : audit_logs hash chain ») qui tombe.
- Reproduction  : `docker exec axion-crm-api sh -c 'grep AUDIT_HASH_CHAIN_SECRET .env'` puis le script `02_script-cloisonnement.php` section A.
- Correctif     : générer un secret aléatoire (32 octets) et le poser ; **et** faire échouer l'amorçage si `AUDIT_HASH_CHAIN_SECRET` est vide hors environnement de test, plutôt que de retomber sur un défaut littéral. Mieux : `hash_hmac('sha256', …, $secret)` au lieu d'une concaténation. Coût : 1 h + une décision d'exploitation (poser le secret casse la vérifiabilité de l'historique — cf. runbook 05). ⚠️ La rotation des secrets a été **refusée par Will** (dossier §6) : il s'agit ici de *poser* un secret qui n'existe pas, pas d'en changer un.
- Statut        : ouvert
- Portée mesurée: **atelier local uniquement.** `.env.example` livre la variable vide ; `infra/scripts/setup-hetzner-cpx22.sh:103` en génère une aléatoire au premier déploiement. **Je n'ai pas pu lire le `.env` de production** (interdiction d'écriture et pas d'accès en lecture depuis ce poste). Si la production porte elle aussi une valeur vide, ce constat est S0 en production ; sinon il reste S0 sur l'atelier et la préproduction.

### [B16-002] Supprimer la dernière ligne du journal ne rompt pas la chaîne : le journal n'est pas « append-only »
- Sévérité      : S0 bloquant
- Domaine       : sécurité / conformité
- Référence     : main 1145473
- Emplacement   : `backend/app/Services/Audit/AuditHashChain.php:71-95` (`verifyChain`)
- Constat       : `verifyChain()` parcourt les lignes existantes de la plus ancienne à la plus récente et n'a aucune connaissance du nombre de maillons attendus ; une chaîne tronquée par la queue reste une chaîne valide.
- Preuve        : `04_PREUVES/agent-16/01_chaine-alteration-temoin-positif-et-negatif.txt`, section 9 : `supprime id=3 ; reste 2 lignes` → `[queue-tronquee] audit:verify-chain -> code de sortie = 0 / Audit hash chain OK — aucune anomalie détectée.`
- Témoin négatif: la même commande, dans la même session, rend `1` en section 5 (altération de `status_code`) et en section 11 (suppression de la première ligne). Le contrôle sait rougir ; il ne rougit pas sur ce geste-là.
- Impact        : c'est le geste de falsification le plus naturel. Un acteur qui vient de commettre l'écriture illicite supprime **sa propre ligne, la dernière**, et le contrôle nocturne reste vert. La propriété annoncée par le produit — « journal append-only avec chaîne cryptographique » (en-tête du middleware, sous-titre de l'écran « Journaux d'audit ») — est fausse. Rien en base ne l'empêche non plus : aucun trigger, aucun `REVOKE DELETE`, et le rôle applicatif est SUPERUSER.
- Reproduction  : `DELETE FROM audit_logs WHERE id = (SELECT max(id) FROM audit_logs);` puis `php artisan audit:verify-chain` → code 0.
- Correctif     : ancrer le nombre de maillons hors de la table (compteur signé, ou publication périodique du dernier condensat ailleurs — journal externe, fichier append-only, second système) ; **et** retirer les droits `UPDATE`/`DELETE` sur `audit_logs` au rôle applicatif, avec un rôle d'écriture dédié `INSERT` seul. Coût : 1 j.
- Statut        : ouvert

### [B16-003] L'horodatage du journal n'entre pas dans le hachage : `created_at` est modifiable sans rompre la chaîne
- Sévérité      : S0 bloquant
- Domaine       : conformité / sécurité
- Référence     : main 1145473
- Emplacement   : `backend/app/Services/Audit/AuditHashChain.php:105-119` (`canonical()`)
- Constat       : `canonical()` construit sept champs — `workspace_id`, `user_id`, `method`, `path`, `status`, `ip`, `payload_hash` — et n'inclut ni `created_at`, ni `user_agent`, ni `id`, ni `prev_hash`.
- Preuve        : `01_chaine-alteration-…txt`, section 8 : `created_at avant = 2026-08-19 12:03:06+00 / created_at apres = 2019-01-01 00:00:00+00` → `code de sortie = 0 / Audit hash chain OK`.
- Témoin négatif: sections 5 et 11 de la même sortie, où la commande rend `1`.
- Impact        : le §20 exige un « journal d'audit **horodaté** ». La date est précisément ce qu'un faussaire déplace — pour antidater un consentement, pour sortir un geste d'une fenêtre d'enquête, pour faire croire qu'un effacement RGPD a eu lieu dans le délai d'un mois. Elle est hors de la protection. Conséquence secondaire : la table étant **partitionnée par `created_at`**, changer la date déplace aussi la ligne de partition — donc de fichier — sans qu'aucun contrôle ne s'en aperçoive.
- Reproduction  : `UPDATE audit_logs SET created_at = '2019-01-01' WHERE id = <n>;` puis `php artisan audit:verify-chain` → code 0.
- Correctif     : ajouter `created_at` (au format ISO-8601 UTC normalisé) et `user_agent` dans `canonical()`. ⚠️ **ce changement invalide toute la chaîne existante** : à faire en même temps que la pose du secret (B16-001), avec un point de reprise documenté. Coût : 2 h de code, plus la décision d'exploitation.
- Statut        : ouvert

### [B16-004] `GET /audit-logs` rend le journal de tous les espaces à tout compte authentifié
- Sévérité      : S0 bloquant
- Domaine       : sécurité / conformité
- Référence     : main 1145473
- Emplacement   : `backend/app/Http/Controllers/Api/AuditLogsController.php:28` ; `backend/app/Models/AuditLog.php` ; `backend/app/Policies/AuditLogPolicy.php:9`
- Constat       : `AuditLog::query()->orderByDesc('id')->paginate(50)` s'exécute sans filtre d'espace, sans portée globale, sans politique RLS et sans appel à `authorize()`.
- Preuve        : `04_PREUVES/agent-16/02_cloisonnement-et-secret.txt`, section B : la requête posée avec `app.current_workspace_id` = espace B rend la ligne `id=5 workspace=3c0f557c-… <<< ESPACE A, VU PAR B path=api/v1/companies/SECRET-DE-A`. Complété par `scopes globaux du modele AuditLog : []`, `politiques RLS sur audit_logs* : 0`, `occurrences de 'authorize' : 0`, et `role applicatif : {"u":"axion","rolbypassrls":true,"rolsuper":true}`.
- Témoin négatif: le même relevé distingue explicitement les deux espaces et étiquette la ligne étrangère ; si le cloisonnement avait fonctionné, la ligne `id=5` eût été absente et seule la ligne `(espace B)` eût été rendue. Contre-épreuve structurelle : `ai_act_register`, mesurée dans la même base, porte bien une politique `ai_act_register_workspace_isolation` *forcée* — la mécanique existe, elle n'a pas été posée ici.
- Impact        : le journal contient les chemins d'API (donc des identifiants de fiches), les IP, les agents utilisateurs et les empreintes de corps de requête de **tous les espaces**. Un `viewer` d'un espace lit l'activité complète d'un autre client. `AuditLogPolicy::viewAny` réserve pourtant explicitement cette lecture au rôle `owner` : la garde a été écrite, elle n'est jamais appelée — c'est du code mort qui donne l'illusion de la protection.
- Reproduction  : script `02_script-cloisonnement.php`, section B, sur base jetable.
- Correctif     : `$this->authorize('viewAny', AuditLog::class)` en tête de `index()` **et** `->where('workspace_id', app('workspace.id'))` **et** une politique RLS sur `audit_logs` + partitions, **et** cesser de connecter l'application avec un rôle SUPERUSER/BYPASSRLS (sans quoi la RLS restera décorative). Coût : 2 h pour les deux premiers, 1 j pour le rôle base.
- Statut        : ouvert

### [B16-005] L'empreinte de corps de requête d'une connexion contient le mot de passe, et elle est servie à tout compte authentifié
- Sévérité      : S1 grave
- Domaine       : sécurité
- Référence     : main 1145473
- Emplacement   : `backend/app/Http/Middleware/AuditHashChainLogger.php:47` ; `backend/app/Http/Controllers/Api/AuditLogsController.php:28`
- Constat       : le middleware stocke `payload_hash = hash('sha256', json_encode($request->all()))` pour toute requête mutative, `POST /auth/login` comprise, dont le corps contient `password` en clair ; `GET /audit-logs` rend les modèles complets, colonne `payload_hash` incluse.
- Preuve        : `02_cloisonnement-et-secret.txt`, section C — pour le corps `{"email":"will@axion-ia.fr","password":"MotDePasse123!"}`, l'empreinte est `037253c925adb62395bee7a692be00059fe05b10618a2210cce31752397443e9`, reproductible à l'identique (`re-calcul identique = OUI`). Le relevé du journal réel (`04_contenu-reel…txt`) montre 23 lignes `api/v1/auth/login` portant chacune une telle empreinte.
- Témoin négatif: le contrôle recalcule l'empreinte à partir d'un corps *connu* et la compare ; il vérifie donc positivement que la fonction est déterministe et non salée. Un sel ou un HMAC à clé aurait rendu la comparaison fausse.
- Impact        : SHA-256 nu, sans sel, sur un JSON dont la seule inconnue est le mot de passe pour une adresse connue. Qui lit `audit_logs` — c'est-à-dire, par B16-004, **tout compte authentifié de tout espace** — peut monter une attaque par dictionnaire hors ligne sur les mots de passe de tous les utilisateurs qui se sont connectés. La longueur minimale de 12 caractères réduit le risque sans le supprimer.
- Reproduction  : script `02_script-cloisonnement.php`, section C.
- Correctif     : retirer les champs sensibles avant de hacher (`$request->except($request->route()?->parameter … )` ou une liste noire `password`, `password_confirmation`, `token`, `code`), ou remplacer par un HMAC à clé. Coût : 30 min.
- Statut        : ouvert

### [B16-006] Le contrôle d'intégrité planifié à 03:00 n'avertit personne
- Sévérité      : S1 grave
- Domaine       : conformité / sécurité
- Référence     : main 1145473
- Emplacement   : `backend/routes/console.php:14` ; `backend/app/Console/Commands/AuditVerifyChain.php:25`
- Constat       : l'événement planifié porte `output = '/dev/null'`, `afterCallbacks = 0`, `beforeCallbacks = 0` — ni `onFailure`, ni `emailOutputOnFailure`, ni `pingOnFailure`, ni `onOneServer`.
- Preuve        : `01_chaine-alteration-…txt`, section 12 (introspection de l'objet `Event` réel). Complété par `docker logs axion-crm-scheduler`, qui montre le motif `⇂ '/usr/local/bin/php' 'artisan' … > '/dev/null' 2>&1` sur chaque tâche.
- Témoin négatif: la même introspection lit six propriétés de l'événement et en imprime les valeurs ; `expression = 0 3 * * *` prouve que l'objet inspecté est bien celui d'`audit:verify-chain` et non un autre — le contrôle vise le bon objet (piège 19).
- Impact        : la commande rend bien `1` (prouvé) et Laravel lève bien une exception (`ScheduleRunCommand::runEvent` : `if ($event->exitCode != 0 …) throw`), mais son message métier part dans `/dev/null` et `report()` n'a aucune destination : `config/sentry.php` lit `SENTRY_LARAVEL_DSN`, absent de `.env.example`, tandis que `.env` ne porte qu'un `GLITCHTIP_DSN=` vide ; `app/Notifications/` n'existe pas. Il ne reste qu'une ligne `FAIL` dans la sortie d'un conteneur que rien n'agrège. Le commentaire du code l'admet : `// En prod : envoi Slack/Telegram + ouverture incident.` **Un contrôle nocturne dont personne ne lit la sortie ne protège rien.**
- Reproduction  : script `01_script-alteration.php`, section 12.
- Correctif     : `->onFailure(fn () => …)` qui écrit un `Log::critical` **et** ouvre un canal réellement lu (courriel à Will, ou une ligne dans un tableau de bord de la console) ; **et** retirer la redirection `/dev/null` pour cette tâche (`->appendOutputTo(storage_path('logs/audit-chain.log'))`). Coût : 2 h. Prérequis : décider quel canal Will lit réellement.
- Statut        : ouvert

### [B16-007] Le registre AI Act est vide, et la route qui le sert ne le lit pas
- Sévérité      : S1 grave
- Domaine       : conformité
- Référence     : main 1145473
- Emplacement   : `backend/app/Http/Controllers/Api/AiActRegisterController.php:15` et `:22`
- Constat       : `index()` rend `$this->ok(['data' => []])` en dur — le contrôleur ne contient aucune requête, aucun `use` de modèle — et `store()` rend `notImplemented('11')` (501) ; la table `ai_act_register` compte **0 ligne** sur les trois bases mesurées.
- Preuve        : `04_PREUVES/agent-16/04_contenu-reel-du-journal-avant-reconstruction.txt` (comptes `ai_act_register = 0`, `llm_usage = 0`) et `01_chaine-alteration-…txt` section 14 (`ai_act_register : 0 ligne(s)`, `llm_usage : 0 ligne(s)`), plus la lecture du contrôleur (12 lignes).
- Témoin négatif: la même requête de comptage rend `1` pour `llm_use_cases` dans la même sortie — le compteur sait donc compter autre chose que zéro. Et l'espace `axion-ia`, que le seeder exige, existe bien (`SELECT slug FROM workspaces` → `vivier-candidats`, `axion-ia`) : le registre n'est pas vide faute d'espace, il est vide faute d'écriture.
- Impact        : le §21.4 du cahier des charges affirme que le registre AI Act « existe déjà dans CRM Pro » et doit couvrir la notation de candidats — un usage **à haut risque** au sens du règlement UE 2024/1689. Ce qui existe est une table vide et une route qui ment. Aucune obligation de l'art. 11-12 (documentation du système, du fournisseur, de la finalité, et **journalisation automatique de l'usage**) n'est tenue : `llm_usage`, qui serait ce journal, compte lui aussi 0 ligne. Le seul contenu jamais rédigé (`AiActRegisterSeeder`, jamais joué) classe le système en `limited`. **C'est le même défaut que A-002 (`GET /saved-views` rend 200 liste vide au lieu de 501), sur une route de conformité** : un 200 vide se lit « rien à déclarer », un 501 se lit « pas encore construit ». Ici la différence est juridique.
- Reproduction  : `docker exec axion-crm-postgres psql -U axion -d axion_crm -c "SELECT count(*) FROM ai_act_register;"` ; lecture du contrôleur.
- Correctif     : à court terme, rendre **501** sur `index()` comme sur `store()` — un écran vide qui ment sur la conformité est pire qu'un écran qui dit « pas construit ». À moyen terme : brancher `index()` sur la table avec filtre d'espace, implémenter `store()`, ajouter les colonnes `responsible`, `status`, `last_review_at` que l'écran attend déjà, et rédiger l'entrée « haut risque » pour la notation de candidats. Coût : 30 min pour le 501, 2 j pour le registre réel.
- Statut        : ouvert

### [B16-008] Les quatre exports de données nominatives ne laissent aucune trace au journal
- Sévérité      : S1 grave
- Domaine       : conformité
- Référence     : main 1145473
- Emplacement   : `backend/app/Http/Middleware/AuditHashChainLogger.php:23` ; `backend/routes/api.php:79,118,139,144`
- Constat       : le middleware retourne immédiatement si la méthode n'est pas dans `['POST','PUT','PATCH','DELETE']` ; les quatre routes d'export (`/companies/export`, `/media/export`, `/journalists/export`, `/rgpd/export/{token}`) sont déclarées en `GET`.
- Preuve        : lecture du middleware (l. 21-25) ; `grep -oE "Route::(get|post|put|patch|delete)" routes/api.php | sort | uniq -c` → `50 Route::get`, `43 Route::post`, `11 Route::put`, `7 Route::delete` ; `grep -nE "Route::get" routes/api.php | grep -i export` → les 4 lignes. Le relevé du journal réel confirme : les 11 chemins présents sur 80 lignes sont tous des routes `POST` (`04_contenu-reel…txt`).
- Témoin négatif: le même relevé montre que des routes mutatives *sont* journalisées (`api/v1/audiences/preview`, `api/v1/auth/login`, `api/internal/site-sync`) — le middleware fonctionne ; il ne voit simplement jamais les `GET`.
- Impact        : le §20 exige que l'**export** figure au journal d'audit, et le §21.1 que tout **téléchargement** soit consigné. Un export emporte, selon le commentaire du dépôt lui-même, « 4,29 M de fiches nominatives hors du système » — et il ne laisse aucune trace. En cas de fuite, il est impossible de dire qui a exporté quoi, ni quand. C'est la trace la plus importante du produit, et c'est celle qui manque. La lecture du journal d'audit lui-même n'est pas tracée non plus (et le middleware s'en exclut explicitement, l. 24).
- Reproduction  : appeler `GET /api/v1/companies/export` authentifié, puis `SELECT count(*) FROM audit_logs WHERE path LIKE '%export%'` → 0.
- Correctif     : journaliser explicitement les routes de lecture sensibles — soit en élargissant le middleware à une liste blanche de chemins `GET`, soit par un appel direct à `AuditHashChain::record()` dans chaque méthode `export()` avec le nombre de lignes sorties et les filtres appliqués (ce que le §20 appelle un compte rendu). Coût : 4 h.
- Statut        : ouvert

### [B16-009] Suivre le runbook « disque plein » rend le contrôle d'intégrité définitivement rouge
- Sévérité      : S1 grave
- Domaine       : conformité
- Référence     : main 1145473
- Emplacement   : `infra/runbooks/02-disk-full.md:21-24` ; `backend/app/Services/Audit/AuditHashChain.php:71-77`
- Constat       : `partman.part_config` porte `retention = 24 months` sur `public.audit_logs` ; le runbook demande à un opérateur de jouer `partman.run_maintenance('public.audit_logs')` ; `verifyChain()` repart toujours de `GENESIS_PREV_HASH` sur la plus ancienne ligne survivante.
- Preuve        : `04_PREUVES/agent-16/03_etat-base-audit-et-ai-act.txt` (`retention | 24 months`, `retention_keep_table | t`, `partitions | 14`) ; et le geste joué, `01_chaine-alteration-…txt` section 11 : `supprime la premiere ligne id=1 ; reste 1 lignes` → `code de sortie = 1 / Audit hash chain INVALIDE`.
- Témoin négatif: la section 6 de la même sortie montre la commande à `0` sur une chaîne complète — la rougeur de la section 11 vient bien de la troncature de tête, pas d'un état résiduel.
- Impact        : au premier incident de disque plein après 24 mois d'exploitation, l'opérateur applique le runbook, la partition la plus ancienne est détachée, et `audit:verify-chain` rend `1` toutes les nuits **pour toujours**, sans qu'aucune falsification n'ait eu lieu. Combiné à B16-006 (personne ne lit la sortie), la conséquence n'est pas une fausse alerte bruyante : c'est un contrôle mort dont personne ne saura jamais qu'il est mort. Aujourd'hui le risque est armé mais non déclenché : `run_maintenance` n'est planifié nulle part (déjà relevé par `_REPORTS/AIPD_2026-08-18.md`).
- Reproduction  : `DELETE FROM audit_logs WHERE id = (SELECT min(id) FROM audit_logs);` puis `php artisan audit:verify-chain` → code 1.
- Correctif     : faire de `verifyChain()` une vérification **relative** — stocker le condensat et l'identifiant de la dernière ligne archivée dans une table d'ancrage (`audit_chain_anchors`), et repartir de cet ancrage plutôt que de GENESIS. Et écrire l'ancrage **avant** tout détachement de partition, dans le runbook comme dans la commande. Coût : 1 j. À défaut, retirer le pas §3 du runbook 02.
- Statut        : ouvert

### [B16-010] `user_agent` n'entre pas dans le hachage
- Sévérité      : S2 défaut
- Domaine       : sécurité
- Référence     : main 1145473
- Emplacement   : `backend/app/Services/Audit/AuditHashChain.php:105-119`
- Constat       : `record()` stocke `user_agent` en colonne, `canonical()` ne l'inclut pas.
- Preuve        : `01_chaine-alteration-…txt` section 7 : `user_agent apres = FALSIFIE-PAR-AGENT-16` → `code de sortie = 0 / Audit hash chain OK`.
- Témoin négatif: sections 5 et 11 de la même sortie, où la commande rend `1`.
- Impact        : une colonne du journal peut être réécrite à volonté. Moins grave que `created_at` (B16-003) parce que l'agent utilisateur porte peu de valeur probante, mais c'est le même trou et il se corrige dans le même geste.
- Reproduction  : `UPDATE audit_logs SET user_agent = 'x' WHERE id = <n>;` puis `audit:verify-chain` → 0.
- Correctif     : inclus dans le correctif de B16-003. Coût : marginal.
- Statut        : ouvert

### [B16-011] L'écran « Journaux d'audit » affiche cinq colonnes qui n'existent ni en base ni dans l'API
- Sévérité      : S2 défaut
- Domaine       : interface / UX
- Référence     : main 1145473
- Emplacement   : `frontend/src/features/rgpd/AuditLogsPage.tsx:25-33` et `:157,:225,:229` ; `backend/app/Models/AuditLog.php:13-16`
- Constat       : l'interface `AuditLog` du frontal déclare `actor`, `target`, `severity`, `payload`, `previous_hash` ; la table porte `user_id` et `prev_hash`, et ni acteur nommé, ni cible, ni sévérité, ni charge utile.
- Preuve        : `\d audit_logs` (12 colonnes : `id, workspace_id, user_id, event_type, path, status_code, ip, user_agent, payload_hash, prev_hash, current_hash, created_at`) contre la lecture du composant ; `AuditLogsController::index()` rend `$page->items()`, c'est-à-dire les modèles bruts, sans transformateur.
- Témoin négatif: le composant possède bien un repli `?? '—'` sur chacune de ces colonnes — il ne casse pas, il affiche silencieusement du vide. C'est précisément pourquoi le défaut n'a pas été vu : l'écran a l'air de fonctionner.
- Impact        : les colonnes « Acteur », « Cible » et « empreinte précédente » affichent `—` pour **toutes** les lignes, et le filtre de sévérité ne trie que par code HTTP (`severityFromStatus`). L'écran que le §19 promet comme « journal d'audit » de la console d'administration ne dit jamais qui a fait quoi. Confusion de navigation : l'utilisateur cherche un acteur, la colonne existe, elle est toujours vide — il conclut que le journal est cassé, ou pire, qu'il n'y a rien à voir.
- Reproduction  : ouvrir `/console/…/journaux-audit` sur l'atelier avec des lignes en base ; les colonnes Acteur et Cible sont vides.
- Correctif     : soit retirer les colonnes fantômes de l'écran, soit ajouter un transformateur côté API qui joint `users` pour un nom d'acteur et dérive une cible du chemin. Le second est ce que le §20 exige. Coût : 30 min pour retirer, 1 j pour joindre.
- Statut        : ouvert

### [B16-012] Les commandes destructives planifiées n'écrivent rien au journal, sauf deux
- Sévérité      : S2 défaut
- Domaine       : conformité
- Référence     : main 1145473
- Emplacement   : `backend/app/Console/Commands/{RetentionPurge,PruneScraperRuns,AnonymizeOldIps,MediaCleanEmails,ProspectionPurgeNonCommercial,ProspectionPurgeNonDiffusible}.php`
- Constat       : sur les 9 commandes du dossier `Console/Commands` qui contiennent un `DELETE FROM`, un `->delete()`, un `forceDelete` ou un `truncate`, **deux seulement** appellent `AuditHashChain::record()` (`RgpdPurgeVivier`, `RgpdPurgeBusinessProspects`).
- Preuve        : `grep -ln "DELETE FROM\|->delete()\|forceDelete\|truncate" app/Console/Commands/*.php` → 9 fichiers ; `grep -rn "AuditHashChain" app/Console/Commands/` → 3 fichiers, dont `PentestSelfCheck` qui ne fait que vérifier. `business_events` = 0 ligne, `AuditLogger::log` n'a que 5 points d'appel, aucun destructif.
- Témoin négatif: les deux commandes qui *le font* écrivent bien (`GDPR_PURGE_VIVIER`, `GDPR_PURGE_BUSINESS`, avec `workspace_id` renseigné) — le motif d'appel existe et fonctionne ; il n'a simplement pas été étendu.
- Impact        : le §21.2 exige des « durées de conservation par catégorie, purge automatique avec alerte préalable ». Quatre purges tournent chaque nuit ou chaque semaine sur des données nominatives (IP, emails médias, prospects non diffusibles, payloads de collecte) et ne laissent aucune trace exploitable. En cas de contrôle, il est impossible de démontrer qu'une durée de conservation a été appliquée.
- Reproduction  : les deux `grep` ci-dessus.
- Correctif     : étendre le motif des deux commandes RGPD aux cinq autres — une ligne de chaîne par exécution avec le compte des lignes touchées. Coût : 3 h.
- Statut        : ouvert

### [B16-013] Une ligne insérée sans `prev_hash` fait crier « falsification » sans qu'il y en ait eu
- Sévérité      : S3 finition
- Domaine       : backend
- Référence     : main 1145473
- Emplacement   : `backend/database/migrations/2026_08_16_000001_audit_logs_prev_hash_default.php` ; table `audit_logs`, colonne `prev_hash`
- Constat       : le défaut SQL vaut `repeat('0'::text, 64)`, exactement `GENESIS_PREV_HASH` ; une ligne insérée sans `prev_hash` hérite donc du maillon zéro, ce que `verifyChain()` rejette dès la deuxième ligne.
- Preuve        : `01_chaine-alteration-…txt` section 10 : `ligne injectee id=4 prev_hash=0000…0000 current_hash=deadbeef` → `code de sortie = 1 / Audit hash chain INVALIDE`.
- Témoin négatif: la section 6 précédente montre la même commande à `0` sur la même base — la rougeur vient bien de l'insertion.
- Impact        : la question posée était « une ligne insérée sans `prev_hash` casse-t-elle la chaîne, ou passe-t-elle inaperçue ? ». **Elle casse la chaîne, et elle la casse en criant à la falsification.** C'est un faux positif — l'en-tête de la migration l'annonçait mot pour mot. Aucun mécanisme du produit n'insère ainsi ; le risque est un import, une reprise manuelle ou un script de secours. Défaut de finition, mais qui, cumulé à B16-006, transformerait le contrôle nocturne en bruit permanent.
- Correctif     : rendre `prev_hash` sans défaut (`DROP DEFAULT`), pour qu'un `INSERT` incomplet échoue franchement au lieu de produire une ligne silencieusement invalide. Coût : 15 min + une migration.
- Statut        : ouvert

### [B16-014] Deux commentaires du produit décrivent un état qui n'existe plus, et un runbook renvoie à une commande absente
- Sévérité      : S3 finition
- Domaine       : backend
- Référence     : main 1145473
- Emplacement   : `backend/app/Services/Audit/AuditHashChain.php:28-31` ; `infra/runbooks/05-rotate-secrets.md:31`
- Constat       : (a) le commentaire affirme « la colonne `audit_logs.prev_hash` porte **encore** un DEFAULT SQL `'GENESIS'` », alors que la migration du 2026-08-16 l'a passé à `repeat('0',64)` ; (b) le runbook de rotation demande « Marquer le breakpoint via `audit:checkpoint` artisan command », commande qui n'existe pas.
- Preuve        : `SELECT column_default FROM information_schema.columns WHERE table_name='audit_logs' AND column_name='prev_hash'` → `repeat('0'::text, 64)` ; `grep -r "audit:checkpoint" backend/` → aucun résultat.
- Témoin négatif: le même `grep` trouve bien `audit:verify-chain` dans `AuditVerifyChain.php:10` — il sait donc trouver une signature de commande quand elle existe.
- Impact        : un lecteur du code croit que le défaut `'GENESIS'` est encore armé et cherche un bogue là où il n'y en a plus ; un opérateur qui suit le runbook de rotation s'arrête au pas 4 sur une commande inexistante, au pire moment — après avoir déjà changé le secret et cassé la vérifiabilité de l'historique.
- Correctif     : corriger les deux textes ; ou écrire la commande `audit:checkpoint` que le runbook suppose (elle serait de toute façon nécessaire au correctif de B16-009). Coût : 20 min pour les textes.
- Statut        : ouvert

---

## 11. Ce que je n'ai PAS pu vérifier, et pourquoi

1. **La valeur de `AUDIT_HASH_CHAIN_SECRET` en production et en préproduction.** Le
   dossier interdit toute écriture en production et je n'ai pas d'accès en lecture au
   `.env` des serveurs depuis ce poste. `infra/scripts/setup-hetzner-cpx22.sh:103`
   génère une valeur aléatoire au premier déploiement — mais rien ne prouve que ce
   script a été joué sur l'hôte actuel, ni qu'une reprise ultérieure ne l'a pas
   écrasée par `.env.example`. **B16-001 est donc mesuré sur l'atelier seulement.**
   C'est la vérification la plus importante à faire faire à quelqu'un qui a l'accès :
   `docker exec axion-crm-api sh -c 'echo -n "$AUDIT_HASH_CHAIN_SECRET" | wc -c'`.
2. **Le contenu réel de `audit_logs` en production.** Mes chiffres de complétude
   (80 lignes, 11 chemins, 90 % sans espace) viennent de l'atelier. La production a
   sûrement un profil différent — mais le middleware y est le même, donc la *forme*
   de ce qui est capté est identique.
3. **Les gestes réels par HTTP** — un export suivi d'une lecture du journal, et une
   tentative de connexion en échec suivie d'une lecture du journal. Je les ai
   entrepris : `Invoke-WebRequest http://localhost:58080/api/v1/auth/login` avec un
   couple invalide, puis `SELECT … FROM audit_logs ORDER BY id DESC`. **L'API de
   l'atelier n'a pas répondu** : `http://localhost:58080/up` expire au bout de 20 s
   (mesuré à 12:55 UTC), la reconstruction concurrente de `axion_crm` par un agent
   parallèle ayant saturé le conteneur `axion-crm-api` (un simple
   `php artisan --version` y prend **2 min 39 s**). J'ai donc prouvé B16-008 par le
   code du middleware et par le contenu du journal (11 chemins, tous mutatifs), pas
   par le clic. À rejouer quand l'atelier sera libre.
4. **Le comportement de `verify-chain` sur un gros volume.** Je l'ai joué sur 1 à 4
   lignes. `verifyChain()` sans `--max` parcourt **toute** la table avec un `cursor()`
   et recalcule un SHA-256 par ligne : sur plusieurs millions de lignes, à 03:00, le
   temps d'exécution est inconnu. Le contrôleur HTTP, lui, appelle `verifyChain()`
   **sans borne** — un `GET /audit-logs/verify-chain` sur une grosse base pourrait
   expirer. Non mesuré, faute de jeu de volume sur `audit_logs`.
5. **Si `report()` remonte quelque part en production.** `GLITCHTIP_DSN` est vide en
   local et `SENTRY_LARAVEL_DSN` n'est pas déclaré dans `.env.example` ; la
   production pourrait porter le second. Vérifiable seulement avec l'accès serveur.
6. **La partition `audit_logs` en production.** Sur `axion_crm` (atelier), `part_config`
   était **vide** et il n'existait qu'une partition `DEFAULT` ; sur `axion_crm_a16`
   (issue de `axion_crm_test`), pg_partman est bien configuré avec 14 partitions et
   `retention = 24 months`. **Les deux bases de l'atelier divergent.** Laquelle
   ressemble à la production, je ne peux pas le dire. B16-009 vaut pour toute base où
   `part_config` porte la ligne — ce qui est le résultat d'une migration du dépôt,
   donc le cas nominal.
7. **La sortie `FAIL` réelle du planificateur sur chaîne rompue.** Je l'ai établie en
   lisant le code du cadriciel (`ScheduleRunCommand::runEvent`), pas en attendant
   03:00 avec une chaîne cassée. J'ai en revanche mesuré directement les trois
   propriétés qui décident du sort de la sortie (`output`, `afterCallbacks`,
   `beforeCallbacks`).
8. **Les §20/§21 côté « écoute et téléchargement d'enregistrements ».** La
   fonctionnalité n'existe pas dans le produit ; je ne peux donc pas mesurer si elle
   serait consignée. C'est un écart de périmètre, pas un défaut de journal.
