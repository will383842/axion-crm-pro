# OÙ NOUS EN SOMMES — fiche de reprise

> **Mise à jour après chaque correctif.** Si Claude Code se ferme, tout est ici : ce qui est
> fait, ce qui est en cours, et comment reprendre exactement au même point.
>
> **Dernière mise à jour : 2026-08-21.**

---

## 1. L'essentiel en dix lignes

- **Ce que je fais** : je corrige, un par un, les problèmes trouvés par l'audit 360°. Je ne
  cherche plus de nouveaux défauts — sauf ceux que mes propres mesures révèlent, et il y en a.
- **Méthode, invariable** : j'écris un test → je le fais tourner sur le code cassé, **il doit
  rougir pour la bonne raison** → je corrige → il passe au vert → je rejoue toutes les suites
  touchées → je commite → je pousse.
- **Où vit le travail** : worktree `C:/Users/willi/Documents/Projets/crmpro-wt-a35-auth`,
  branche **`fix/a35-authentification`**, PR **#191** (ouverte, **non fusionnée**).
- **⛔ Interdits** : le worktree `crmpro-wt-etape1a`, la copie principale
  `Axion-CRM-Pro/backend`, et **toute écriture en production**.

## 2. 🟢 CE QUI A CHANGÉ LE 2026-08-20 — la PR #191 est redevenue fusionnable

**Les trois blocages de `08_PASSE-2-ADVERSARIALE.md` §10 sont fermés, chacun vu rouge puis
vert.** Et la suite complète, **jouée pour la première fois en entier sur cette branche**, en
a révélé bien davantage.

| Blocage §10 | État | Preuve archivée |
|---|---|---|
| **`P5-35-007`** — l'admin de A lisait le journal d'audit de B | ✅ **fermé** | `p5-35-007-ROUGE-avant-correctif.txt` |
| **`P5-35-006`** — la branche cassait `NotificationsControllerTest` | ✅ **fermé** | rouge reproduit, + pendant négatif ajouté |
| **`P5-35-004`** — le `set -e` rendait le script d'accès muet | ✅ **fermé** | `p5-35-004-ROUGE-avant-correctif.txt` |

**Les quatre commits qui ne fermaient qu'à moitié :**

| Constat | Ce qui restait | État |
|---|---|---|
| `F37-001` / `B12-004` | le rejeu, les deux commentaires trompeurs, le secret hors `config/` | ✅ **fermés** (`P5-HMAC-001/002/003`) |
| `B12-001` / `F36-005` | « 20 autres routes » — **il y en avait 36** | ✅ **fermé**, + contrôle de complétude |
| `B16-004` | le cloisonnement par espace | ✅ **fermé** (= `P5-35-007`) |
| `B15-008` / `22d1fd0` | les sept commandes destructives | 🟡 **partiel** — voir §5 |

**Les trois affirmations de production** (`46f1717`, `debc860`, `22d1fd0`) sont rectifiées —
dans `_AUDIT/RECTIFICATIF-PR-191.md`, dans les commentaires de code concernés, et dans le
corps de la PR. **L'historique publié n'est pas réécrit**, et le §6 dit pourquoi.

## 3. 🔴 LE CHIFFRE QUI COMPTE : 13 rouges, pas 3

La suite backend n'avait **jamais** été jouée en entier sur cette branche. Les onze vagues
précédentes jouaient des périmètres ; la contre-vérification bornait son verdict à `46848d4`.

**Première exécution complète : 13 rouges, 860 verts.** Plus un quatorzième dans la suite
Vitest des workers, qu'aucune suite PHP ne pouvait voir.

| Famille | Nombre | Cause |
|---|---:|---|
| `CompaniesControllerTest` — 403 | 7 | **la branche** (`d58d75c`) |
| `AuditHashChain*Test` — `verifyChain()` rend `false` | 4 | **la branche** (`46f1717`) |
| `workers/tests/result-sender.test.ts` | 1 | **la branche** (`a6aceb0`) |
| `NeDoitPasRegresserTest` | 1 | mon banc (`.github/` non monté) |
| `CoverageControllerTest` — 500 | 1 | **le dépôt** (`MOCK_INSEE` n'existait que dans la CI) |

> 🔑 **Le dépôt a deux toolchains, donc deux angles morts. « Jouer la suite » n'a de sens
> qu'au pluriel.**

Et `MOCK_INSEE` est un vrai défaut, pas un détail de banc : **le test était rouge pour
quiconque lançait la suite en local, et vert en CI**. Ramené dans `phpunit.xml`.

## 4. Ce qui est FAIT — commits poussés

Les quatorze premiers, plus **huit** du 2026-08-20 :

| Commit | Constat | Ce qui était cassé |
|---|---|---|
| `da994be` → `22d1fd0` | *(les 14 premiers)* | voir le journal, vagues 1 à 11 |
| `278ec7e` | `P5-35-007` / `B16-004` | l'admin d'un espace lisait le journal de tous les autres |
| `3ef9d00` | `P5-35-004` / `P5-35-005` | le script qui rend l'accès au CRM mourait en silence |
| `e9e0f95` | `A-010` | la production servait l'API par le serveur mono-processus de PHP |
| `a890fe6` | `P5-HMAC-001/002/003` | le dépôt désignait le canal troué comme le patron à copier |
| `8fd1d60` | `B12-001` / `F36-005` | 36 routes sur 38 rendaient la fiche d'un autre espace |
| `4f89357` | `P5-35-006` + 11 autres | douze tests que la branche cassait sans les mettre à jour |
| `3c2c0cf` | *(rectificatif)* | trois affirmations sur la production, invérifiables par ce lot |
| `a0a6310` | *(gardes)* | trois défauts de mes propres gardes, dont un faux vert |
| `23a0e5f` | **`F38-007`** (S0) | la faille du 19/08 se rouvrait **en un clic**, et par deux autres chemins |

## 5. 🟡 CE QUI RESTE OUVERT SUR CETTE BRANCHE

- **`B15-008`** — les sept commandes destructives : un trait de garde partagé est posé, les
  sept ne sont pas toutes couvertes. **Non fermé.**
- **`I48-001`** (S0) — `PUT`/`DELETE /contacts/{id}` rendent 501. **Rencontré en mesurant le
  cloisonnement, pas réparé** : classé `conception`, il appartient au lot B. On ne tranche
  pas une conception au détour d'un correctif. Un témoin de `CloisonnementTousLesSitesTest`
  est écrit sur ce 501 et **rougira le jour où il sera réparé** — c'est voulu.
- **`NeDoitPasRegresserTest`** n'a pas de témoin de montage, alors que la parade existe
  maintenant dans `tests/Feature/Infra/`. Vingt-et-unième cas de `A-011`, non porté.

## 6. La décision sur l'historique publié

**On ne réécrit pas les trois messages de commit.** Deux raisons :

1. **Le dossier d'audit ancre ses verdicts sur ces identifiants.** Le §10 écrit que le verdict
   du contradicteur *« porte jusqu'à `46848d4` »*, et quatorze sections citent des SHA.
   Réécrire invalide toutes ces références. *Un audit dont les preuves pointent vers des
   objets disparus ne prouve plus rien.*
2. **Effacer une affirmation fausse n'est pas la rectifier.** La correction doit être
   **visible**, pas substituée.

À la place : `_AUDIT/RECTIFICATIF-PR-191.md`, le commit `3c2c0cf`, et le corps de la PR — qui
s'édite sans rien réécrire.

## 7. Compte des constats — recompté MÉCANIQUEMENT le 2026-08-21

⚠️ **Les comptes précédents de cette fiche étaient tenus à la main, et ils étaient faux.**
Ils le sont restés plusieurs jours sans que personne le voie, jusqu'à ce que quatre agents sur
six, lancés le 21/08, rapportent la même phrase : *« le correctif était déjà posé et commité,
la file le donne encore ouvert »*. Voir `FILE-DE-TRAVAIL.md` §1.1 bis.

Le compte ci-dessous est lu **par script** dans la dernière cellule de chaque ligne du tableau
de `FILE-DE-TRAVAIL.md`. Ce sont des **LIGNES**, pas des étiquettes : une ligne comme
`B16-004 / B11-006 / F36-004 / B10-002` compte pour un. Les 25 lignes S0 portent les
34 étiquettes S0 du rapport final ; c'est la même chose comptée autrement.

| Sévérité | Lignes | Fermées | Partielles | `resteWill` / hors dépôt | Ouvertes |
|---|---:|---:|---:|---:|---:|
| **S0** | 25 | **13** | 2 | — | **10** |
| **S1** | 116 | **28** | 6 | 2 | **80** |
| **S2** | 256 | — | — | 1 | **255** |
| **S3** | 88 | — | — | 1 | **87** |

🔴 **46 lignes marquées « ouvert » sont pourtant nommées par un commit ou par une garde
existante.** Elles portent désormais ce commit dans leur cellule. Elles n'ont **pas** été
refermées : un état recopié d'un message de commit ne vaut pas mieux qu'une colonne tenue à la
main. **Jouer `verifier-etats-file-de-travail.py` avant d'ouvrir un lot** — trente secondes,
contre une heure perdue.

### 7 bis. RECTIFICATIF — il RESTE des S0 mécaniquement réparables ici

J'ai écrit le 20/08, et répété le 21/08, qu'« aucun S0 mécaniquement réparable ne reste dans ce
dépôt ». **C'est faux.** En relisant les emplacements ligne par ligne, il en reste **trois** dont
tout ou partie vit dans le dépôt CRM :

| Constat | Ce qui est réparable ICI |
|---|---|
| `B11-002 / B17-010` (S0, conception) | 5 des 6 jobs de file s'exécutent sans contexte d'espace, alors que `Queue::looping` l'efface. Entièrement CRM : `app/Jobs/*`. |
| `B14-002 / E31-001` (S0, correctif) | La moitié CRM : `Api/RgpdRequestsController.php`. L'autre moitié (`inbound.ts`) est dans le dépôt du site. |
| `B10-004` (S0, PARTIEL) | Le reste : `users`, `invitations`, `password_reset_tokens` ne sont couverts par AUCUNE procédure d'effacement. `app/Services/Rgpd/*`. |

`E33-006` et `C19-007`, eux, ne le sont pas : le premier vit entièrement dans le dépôt du site
(`chatbot/tools/escalader-question.ts`), le second est un acte juridique qui appartient à Will.

Détail complet : **`FILE-DE-TRAVAIL.md`**, dans ce même dossier.

⚠️ **Aucune de ces fermetures n'est en production.** La PR #191 est ouverte et non fusionnée.
Le produit qui tourne porte encore les 508.

## 8. Le banc de mesure — comment le remonter

Docker était éteint au matin du 20/08. Après redémarrage du démon, **le conteneur `a35r` et
la base `axion_crm_test_a35r` ont été retrouvés intacts**, avec `/tmp/a35r/`.

```
docker start axion-crm-postgres a35r
docker exec a35r sh -c 'cd /var/www/html && ./vendor/bin/pest -c /tmp/a35r/phpunit-a35.xml'
```

**Trois pièges du banc, tous payés le 20/08 :**

1. `axion-crm-redis` refuse de démarrer (port 56379 dans une plage réservée par Windows).
   Sans conséquence : `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`.
2. **Le conteneur ne monte pas `.github/`.** `NeDoitPasRegresserTest` accuse alors un fichier
   qui existe. Parade : `docker cp .github a35r:/var/www/.github` — **c'est une copie, donc à
   refaire si on édite un workflow**.
3. **`backend/` est monté sur `/var/www/html`, pas sur `/var/www/backend`.** Une garde qui
   écrit `base_path('..') . '/backend/…'` est rouge ici et verte en CI. Utiliser `base_path()`
   pour ce qui est dans l'application, `base_path('..')` seulement pour infra/Dockerfile/CI.

Côté workers : `pnpm install` puis `pnpm vitest run` — **61 tests verts sur 61**.

## 9. La file d'attente, dans l'ordre du danger

1. ~~`F38-007`~~ — ✅ **fermé le 20/08** (`23a0e5f`). Il y avait **quatre** invocations nues,
   dans **trois** fichiers, pas une : le workflow `workflow_dispatch`, le script qui configure
   la production (`depends_on` monte postgres et redis même sans les nommer), et deux
   instructions imprimées à l'opérateur.
2. `C18-016 / F37-002` (S0) — six services **simulés en production**, dont le modèle de
   langage qui écrit des classifications fabriquées en base.
3. `B15-001` (S0) — une personne effacée revient au vivier à la candidature suivante.
4. `B10-004` (S0) — export RGPD : 4 tables sur ~40 ; `candidates` dans ni l'export ni
   l'effacement.
5. `B15-008` — les sept commandes destructives, à finir (§5).
6. `B12-012` (S1) — `sameWorkspace()` compare deux UUID castés en entier : **toujours vrai**.
   *Et c'est le défaut de `channels.php` corrigé le 2026-08-16, jamais propagé — A-011.*

Deux S0 vivent dans le **dépôt du site** (`Axion-IA`) : `B14-002 / E31-001` et `E33-006`.
**PR distincte.**

## 10. RESTE WILL

- **Fusionner la PR #191** — les trois blocages sont levés et la suite est verte, mais
  **pas avant** que la flotte d'audit ait cessé de mesurer la production : un `push` sur
  `main` déclenche le déploiement Hetzner **et les migrations**.
- **Changer le mot de passe propriétaire** : il a transité par un canal non sûr.
- **Supprimer** `storage/app/private/seeders/owner-initial-password.txt` sur le serveur.
- **Trancher `MAIL_MAILER_AUTH`** : par quel relais les courriels d'authentification partent.
- 🔴 **La porte dérobée `root` de `diag-website-status.yml`** — trouvée par la passe P6, et
  **je ne l'ai pas retirée moi-même** : c'est un mécanisme d'accès délibéré à votre serveur, et
  le supprimer sans vous demander pourrait vous enfermer dehors.

  Sa **première action**, avant tout diagnostic, est d'ajouter une clé publique en dur au
  `authorized_keys` du compte **root** de production (`:45-54`). Deux conséquences, et la
  seconde est la vraie :

  1. Le workflow est en `workflow_dispatch`, sans `environment:` ni approbation : **un clic
     repose la clé**. Autrement dit, *la retirer du serveur ne tient pas.*
  2. La clé s'appelle `claude-code-axion-ia-20260510` — elle est rattachée à **un autre
     produit**. Qui détient la clé privée correspondante a `root` sur la production du CRM.

  ⚠️ *Une clé **publique** dans un dépôt public n'est pas une fuite d'identifiant en soi : seule
  la clé privée ouvre. Le défaut n'est pas la publication, c'est la **réinstallation
  automatique** et le **périmètre** de la clé.*

  **Le geste** : décider si cet accès doit exister. S'il doit exister, retirer le bloc du
  workflow et poser la clé à la main, une fois, avec une clé propre au CRM. S'il ne doit pas,
  retirer le bloc **et** la clé du serveur — dans cet ordre, sinon le prochain clic la repose.

- **Une requête en lecture seule sur la production**, pour trancher le chiffre de `B17-012` :
  ```sql
  SELECT count(*) FILTER (WHERE legal_form IS NULL), count(*) FROM companies;
  ```
  *C'est la question à poser, pas un fait établi.* Voir `_AUDIT/RECTIFICATIF-PR-191.md`.

## 11. P6 — le regard neuf, LANCÉE le 2026-08-20 (première vague)

**Quatre agents neufs**, sans accès à `02_CONSTATS.md`, au rapport final, à la passe
adversariale, aux grilles ni aux preuves. Ils ont reçu le code, le CDC et le mandat.
Rapports dans `09_PASSE-3-REGARD-NEUF/`, comparaison des trois passes en `05_`.

### ✅ Ce qu'elle confirme, et c'est la meilleure nouvelle du dossier

Sans avoir lu une ligne du registre, l'agent des automatismes retrouve `A08-006`, `B17-001`,
`B10-003`, `B11-001`, `B11-003`, `B15-008` — **avec le mécanisme exact**, que la passe 1
n'avait pas toujours. *Une passe indépendante qui converge par ses propres mesures valide la
passe 1 bien mieux qu'une relecture.*

Elle confirme aussi que `B15-008` devait rester « partiel » : `media:clean-emails` détruit des
adresses **tous les jours à 05:05**, et le trait `RefuseUneSuppressionMassive` **existe dans le
dépôt sans être branché**.

### 🔴 Cinq S0 que ni P1 ni P2 n'avaient vus — dont deux dans le travail du 20 août

| Constat | Pourquoi il a échappé aux deux premières passes | État |
|---|---|---|
| `P6-API-001/002` — les **listes** ne sont pas cloisonnées | P1 s'est arrêtée aux *fiches* ; P2 hérite du périmètre de P1 ; **ma** garde de complétude énumérait les méthodes recevant un modèle par liaison de route, et un `index()` n'en reçoit aucun | ✅ **fermé** (`cb81284`) |
| `P6-UI-019` — `pnpm lint`, gate **BLOQUANTE**, échouait | personne ne l'avait jouée. 16 erreurs, toutes introduites par cette branche : **#191 ne pouvait pas passer la CI** | ✅ **fermé** (`46ecb80`) |
| `P6-UI-001` — l'accueil affiche « aucune entreprise » sur 4,29 M | `/dashboard/stats` est une closure à zéros en dur. Le mandat exigeait d'ouvrir chaque écran à la main : **la console ne tourne pas**, personne ne l'a ouvert | 🔴 ouvert |
| `P6-UI-002` — la palette ⌘K ne peut rien trouver | `GET /search` déclarée deux fois, trois tableaux vides en dur. **Le test e2e mocke l'endpoint** et reste vert | 🔴 ouvert |
| `P6-INFRA-001` — porte dérobée `root` réarmable en un clic | P1 l'avait vue, mais **repliée en note** dans `F38-007` : jamais de sévérité, jamais de ligne dans `RESTE-WILL` | 🔴 **RESTE WILL** (§10) |
| `P6-INFRA-003` — un **douzième** chemin vers la faille du 19/08 | `docker-compose.observability.yml` prescrit la combinaison qui republie 55432, 56379 et neuf ports d'admin. La garde CI ne rend jamais cette combinaison | 🔴 ouvert |

### ⚠️ Un défaut de dispositif, et il est de moi

Trois des quatre agents ont noté que **la branche bougeait pendant leur mesure** : je
committais sur le même arbre de travail. Même faute que celle relevée au §10 de la passe
adversariale, sous une autre forme. **Pour la prochaine vague : un worktree en lecture seule
par agent, figé sur un SHA nommé.**

### Ce qui reste de P6

Quatre périmètres sur les onze du §4. **Restent** : les 68 services, les 34 workers, le côté
site (`Axion-IA`), les 23 fonctionnalités et la matrice de raccordement, les 13 parcours.
Le mandat exige de **boucler jusqu'à ce qu'une passe complète ne trouve plus rien de
sévérité ≥ S2** : cette première vague en a trouvé **cinq S0**.

---

## 12. Rappel — ce que P6 exige, et pourquoi elle ne s'improvise pas

La phase P6 (`_PROMPTS/PROMPT_AUDIT_360_CRM_PRO_2026-08-18.md` §8) demande des agents
**neufs**, sans accès aux rapports des passes 1 et 2 ni à `02_CONSTATS.md`, refaisant l'audit
de zéro sur le périmètre des §4 et §5 — puis la comparaison des trois passes, tout écart étant
**en soi un défaut de méthode**.

🔑 **C'est la raison pour laquelle elle se délègue.** L'agent qui a mené les réparations des 19
et 20 août connaît les 508 constats ; il ne peut pas les oublier, et tout ce qu'il
« retrouverait » serait suspect de l'avoir été de mémoire. *Un regard neuf ne se simule pas :
il se recrute.* La première vague, lancée le 20/08, l'a démontré en trouvant cinq S0 en
quelques heures — dont deux dans le travail de réparation du jour même.

La définition de fini du §12 reste à **3 points sur 16**.
