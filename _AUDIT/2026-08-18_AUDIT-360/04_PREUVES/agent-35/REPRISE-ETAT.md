# OÙ NOUS EN SOMMES — fiche de reprise

> **Mise à jour après chaque correctif.** Si Claude Code se ferme, tout est ici : ce qui est
> fait, ce qui est en cours, et comment reprendre exactement au même point.
>
> **Dernière mise à jour : 2026-08-21, 07 h 30** — après la **vague 14**, qui a été
> coupée en plein vol à 05 h 27. Les versions précédentes de cette fiche s'arrêtaient à
> 01 h 25 et ignoraient donc six lots. **Voir le §13.**

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

> ⚠️ **Ce titre était prématuré, et il l'est resté vingt-quatre heures.** Le 20/08, les trois
> blocages du §10 étaient levés, mais **sept contrôles CI étaient rouges** et le job backend
> n'atteignait même pas Pest. #191 n'est `CLEAN` que depuis le **2026-08-21, 09 h 15** —
> voir §13.9. *« Fusionnable » se mesure sur la PR, pas sur la liste des constats fermés.*

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
| **S0** | 25 | ~~13~~ **16** | ~~2~~ **1** | — | ~~10~~ **8** |
| **S1** | 116 | ~~28~~ **33** | ~~6~~ **5** | 2 | ~~80~~ **78** |
| **S2** | 256 | — **1** | — | 1 | **255** |
| **S3** | 88 | — **1** | — | 1 | **87** |
| **TOTAL** | **485** | **51** | **6** | — | **428** |

> 📐 **Recompté par script le 2026-08-21 à 10 h**, après la vague 14 et la passe 3
> du §13 : `+3` S0 et `+5` S1 fermés. Le script de contrôle
> (`verifier-etats-file-de-travail.py`) rend désormais **0 écart** — il en listait
> **8** le matin même, dont **six déjà fermés que la file donnait ouverts**.
> *C'est le coût mesuré de l'écart : le 21/08, quatre agents sur six ont passé
> leur première heure à redécouvrir un travail fait.*

🔴 **46 lignes marquées « ouvert » sont pourtant nommées par un commit ou par une garde
existante.** Elles portent désormais ce commit dans leur cellule. Elles n'ont **pas** été
refermées : un état recopié d'un message de commit ne vaut pas mieux qu'une colonne tenue à la
main. **Jouer `verifier-etats-file-de-travail.py` avant d'ouvrir un lot** — trente secondes,
contre une heure perdue.

### 7 bis. RECTIFICATIF — il RESTE des S0 mécaniquement réparables ici

J'ai écrit le 20/08, et répété le 21/08, qu'« aucun S0 mécaniquement réparable ne reste dans ce
dépôt ». **C'est faux.** En relisant les emplacements ligne par ligne, il en restait **trois** dont
tout ou partie vit dans le dépôt CRM.

> ✅ **Les trois sont fermés depuis la vague 14 du 21/08 au matin** (§13). Le tableau ci-dessous
> garde l'énoncé d'origine et lui ajoute ce que la mesure a trouvé — parce que deux des trois
> étaient **mal énoncés**, et que corriger un constat sans montrer ce qu'il disait d'abord
> revient à effacer l'erreur au lieu de la rectifier (§6).

| Constat | Ce qui est réparable ICI |
|---|---|
| `B11-002 / B17-010` (S0, conception) | ~~5 des 6 jobs~~ → **SIX sur six** : mesuré le 21/08, `EnrichCompanyJob` portait bien le trait, mais ses **quatre** points de dispatch l'appelaient à **un seul argument** — son `?string $workspaceId = null` valait `null` à tous les coups. ✅ **fermé** (`4a6d574`, §13). |
| `B14-002 / E31-001` (S0, correctif) | ✅ **fermé côté CRM** (`8db4417`, §13) — et le constat était **SOUS-ESTIMÉ** : sur l'`erasure` qu'il visait, le CRM est innocent (la garde le reprouve) ; le défaut était intact sur **trois autres types sur cinq** — `access`, `rectification`, `opposition` répondaient « traitée » contre `{"noop":true}`. |
| `B10-004` (S0, PARTIEL) | ✅ **fermé** (`8db4417`, §13). `users` est **anonymisé** et non supprimé : mesure sur `pg_constraint`, 33 contraintes le visent, **7 bloquent** un DELETE et les 21 `SET NULL` incluent `audit_logs.user_id` sur la table mère **et ses douze partitions**. |

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

> ✅ **LES SIX ENTRÉES SONT FERMÉES depuis le 2026-08-21** — quatre l'étaient déjà et
> cette liste les donnait ouvertes. Chacune a été rouverte **dans le code**, pas dans un
> rapport : voir §13.11. *Un état recopié ne vaut pas un état relu* — c'est le même défaut
> que le §7 a mesuré sur les comptes tenus à la main.

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
| `P6-UI-001` — l'accueil affiche « aucune entreprise » sur 4,29 M | `/dashboard/stats` est une closure à zéros en dur. Le mandat exigeait d'ouvrir chaque écran à la main : **la console ne tourne pas**, personne ne l'a ouvert | ✅ **fermé** — re-mesuré dans le code le 21/08, §13.10 |
| `P6-UI-002` — la palette ⌘K ne peut rien trouver | `GET /search` déclarée deux fois, trois tableaux vides en dur. **Le test e2e mocke l'endpoint** et reste vert | ✅ **fermé** — re-mesuré dans le code le 21/08, §13.10 |
| `P6-INFRA-001` — porte dérobée `root` réarmable en un clic | P1 l'avait vue, mais **repliée en note** dans `F38-007` : jamais de sévérité, jamais de ligne dans `RESTE-WILL` | 🔴 **RESTE WILL** (§10) |
| `P6-INFRA-003` — un **douzième** chemin vers la faille du 19/08 | `docker-compose.observability.yml` prescrit la combinaison qui republie 55432, 56379 et neuf ports d'admin. La garde CI ne rend jamais cette combinaison | ⚠️ **gardé** (`ab4bfe1`) mais **ouvert à la source** — §13.10 |

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

---

## 13. VAGUE 14 — coupée à 05 h 27, reprise et close le 21/08 au matin

### 13.1 Ce que la coupure a laissé, et comment l'état a été reconstruit

Six lots tournaient. **Quatre** avaient rendu leur verdict ; **deux** — dont un S0 — ont été
relancés à 05 h 14 et **tués à 05 h 27**. Rien n'était commité : 22 fichiers modifiés et 3
nouveaux, **1 664 insertions**, vivant uniquement dans l'arbre de travail.

L'état n'a pas été relu dans cette fiche — qui datait de 01 h 25 et ignorait tout — mais
**reconstruit depuis git et depuis les mtimes**. La coupure est nette :

| horodatage | contenu |
|---|---|
| 04 h 35 → 05 h 10 | les quatre lots achevés |
| 05 h 25 → 05 h 26 | les quatre fichiers des deux lots tués |

🔴 **Et ce découpage par mtime était trop naïf.** `PortabiliteCompleteTest.php` (05 h 10,
lot *achevé*) teste `comptes_crm`, `sessions_ouvertes`, `invitations_recues` — c'est-à-dire
exactement ce que `GdprPortabilityService.php` (05 h 25, lot *partiel*) ajoute. **Un même
fichier portait deux lots.** Exclure les « partiels » du commit aurait rendu rouge un lot
prouvé. *L'heure de dernière écriture dit quand un fichier a bougé, pas à quel travail il
appartient.*

### 13.2 Les quatre fichiers suspects : joués, et ils avaient quatre défauts

Consigne : les jouer, prouver chaque assertion rouge, ou les jeter. **Joués : 33 verts,
4 rouges** — et les quatre rouges étaient dans les *gardes*, jamais dans le code produit.

| défaut | ce que c'était |
|---|---|
| `assertStringContainsString()` sans `$this->` (×3) | PHP levait « undefined function » : ces trois assertions **n'avaient jamais rien prouvé** |
| `PORTES MORTES` attendait 2 routes, il y en a 3 | **la garde a contredit son auteur** — voir 13.3 |

### 13.3 🔑 Le résultat que la vague n'attendait pas : une garde plus juste que son auteur

La garde « portes mortes » annonçait *« sept routes DELETE, deux mortes »* — **une liste écrite
à la main, jamais mesurée**. Première exécution : le routeur rend **NEUF** routes `DELETE`
câblées sur un contrôleur, et **TROIS** ne suppriment rien.

La troisième, `api/v1/saved-views/{saved_view}`, avait échappé au constat — et ce n'est pas un
hasard : `saved_views` est l'une des **six tables mortes** de
`_REPORTS/2026-08-19_INVENTAIRE-ETAPE-1A.md`. La porte est déclarée, elle répond, il n'y a rien
derrière. Les trois ne se ressemblent pas :

```
contacts/{contact}         vérifie l'espace (refuserHorsEspace) PUIS 501
users/{user}               vérifie l'espace (refuserHorsEspace) PUIS 501
saved-views/{saved_view}   501 sec, aucun contrôle
```

*C'est le balayage du **routeur** — et non la liste à la main — qui a permis à la garde de
rendre un résultat que personne n'avait vu.* Même leçon qu'au §7 sur les comptes tenus à la
main : **une énumération recopiée ne garde que ce que son auteur savait déjà.**

### 13.4 Le S0 `B14-002` était sous-estimé, et sur son propre terrain il était faux

Le constat visait l'**effacement**. Sur l'effacement, **le CRM est innocent** et la garde le
reprouve : une demande `erasure` supprime réellement la fiche, inscrit l'opposition dans les
deux univers, met le signal en file. Sa moitié vraie vit dans le dépôt du site
(`crm-sync/inbound.ts:243-261`).

**Mais le défaut était intact sur trois des cinq types que le point d'entrée accepte :**

```
access          200 done  RIEN ({"noop":true})   art. 15
rectification   200 done  RIEN ({"noop":true})   art. 16
opposition      200 done  RIEN ({"noop":true})   art. 21
```

`opposition` porte exactement la conséquence annoncée : la personne est inscrite « traitée » au
**registre** — la pièce que le CRM opposerait à un contrôle — et reste parfaitement joignable.

Le mécanisme était le `default => ['noop' => true]`, qui accueillait en silence tout type non
câblé. Il a disparu : le `match` est exhaustif sur le **catalogue** (`CHECK` relu dans
`pg_constraint`, jamais recopié), et un type inconnu **lève** au lieu de mentir.

L'article 16 n'a aucun traitement automatique possible ; il rend **422** tant que l'opérateur
n'a pas déclaré son geste — la note que la console POSTait déjà était jetée sans être lue.

### 13.5 Les gardes du S0, vues rouges — trois mutations, trois rouges

| mutation | garde qui rougit |
|---|---|
| `opposition` renvoyée au `noop` | *« une opposition traitée FERME réellement les portes »* **+** la garde de couverture du catalogue — **deux gardes indépendantes** |
| jeton d'export re-filtré sur `portability` seul | *« un droit d'accès traité produit une archive réellement téléchargeable »* |
| garde-fou de l'art. 16 neutralisé | *« une rectification n'est PAS inscrite traitée sans acte déclaré »* |

### 13.6 PR #191 : les sept contrôles rouges se ramenaient à QUATRE causes

| cause | contrôles bloqués | état |
|---|---:|---|
| `RouteErrorBoundary` lit `state.loadedAt`, **disparu de la librairie** | **4** — Frontend, Trivy (app), E2E, axe-core | ✅ `c95e673` |
| PHPStan : 38 erreurs | 1 — Backend Laravel | ✅ **38 → 0** |
| Gitleaks : 6 fausses fuites, aucune config | 1 | ✅ `f1c3344` |
| deux scripts d'infra en `100644` | 1 | ✅ `f115af2` |

🔴 **Le piège de banc qui explique pourquoi `loadedAt` n'avait pas été vu**, et il vaut pour
tout ce dépôt : `crmpro-wt-a35-auth/frontend/node_modules/@tanstack/react-router` est un **lien
symbolique** vers le `node_modules` du dépôt principal, figé à `1.170.4`.
`pnpm install --frozen-lockfile` s'exécute **sans rien changer**. Un `pnpm typecheck` lancé dans
ce worktree est donc **vert sur une version que la CI n'installera jamais** :

```
router-core 1.171.2  (lié en local)          RouterState.loadedAt EXISTE
router-core 1.171.22 (résolu par le lock)    loadedAt n'existe NULLE PART
```

*La seule référence est `pnpm-lock.yaml`.* Vérifié en dépaquetant `router-core@1.171.22` hors du
dépôt. **Troisième piège de banc de ce dossier, après `.github/` non monté et `backend/` monté
sur `/var/www/html`** (§8) — et le même en substance : *le banc n'est pas le lieu où le code
tournera.*

### 13.7 Ce que la remise à zéro de PHPStan a trouvé en chemin

La baseline passe de **248 à 209 erreurs figées (−39), zéro entrée nouvelle** — le contrat du
fichier (« elle ne peut que décroître ») est tenu, avec **une** croissance justifiée
(`SsrfGuard` 1 → 2 : deux méthodes sœurs lisent `env()` hors `config/`, choix documenté dont le
défaut est le comportement **sûr**).

Quatre trouvailles méritent d'être notées :

- **`getEloquentBuilder()` n'existe ni dans Laravel ni dans l'application** — il vient de Spatie.
  Le code est juste ; c'est le chaînage `->where(...)->getEloquentBuilder()` qui trompe
  l'analyse, `@mixin EloquentBuilder` faisant croire que `->where()` rend un Builder Eloquent.
  **Même écart enveloppe/Builder que celui qui a produit le 500 de `F36-008`.**
- **`Journalist` n'avait aucun type de retour sur ses trois relations** : Larastan refusait
  `->with('media')` alors que la relation existe. *Un modèle dont les relations ne sont pas
  typées est un modèle sur lequel l'analyse ne peut rien affirmer — c'est le silence, pas la
  garantie.*
- **`RetentionPurge` portait deux conditions qui ne pouvaient pas être fausses.** La garde
  au-dessus corrèle les deux variables : passé ce point, « portée non nulle » **implique**
  « colonne d'espace non nulle ». PHPStan l'avait vu ; le code re-testait quand même.
- **`Seeder::$command` a pour valeur par défaut `null`** (mesuré par réflexion) : les quinze
  `?->` d'`OwnerUserSeeder` sont **nécessaires**, et c'est le docblock du framework qui ment.
  Centralisés dans un helper — baseline **7 → 1**, et la raison écrite à côté du geste.

### 13.8 🔴 LE RESULTAT LE PLUS IMPORTANT DE LA VAGUE : **le banc ment, six fois**

Débloquer PHPStan a fait atteindre Pest au job CI, **pour la première fois sur
cette branche**. Verdict : **1454 verts, 10 rouges**. *Aucun des dix ne venait du
produit.* Tous venaient d'un écart entre le banc `a35r` et le runner.

| # | l'écart | ce qu'il faussait |
|---|---|---|
| 1 | **`RecursiveDirectoryIterator` ne rend qu'un quart d'un répertoire** | toute garde qui énumère |
| 2 | le conteneur monte le **`vendor` du dépôt principal** | `predis` v2 au lieu de v3.5.1 → FATAL en CI |
| 3 | `node_modules` **lié** au dépôt principal | `react-router@1.170.4` au lieu de `1.170.27` |
| 4 | la CI fabrique un **`.env`** depuis `.env.example`, pas le banc | la sonde Telescope mesurait ce `.env` |
| 5 | le banc tourne **en root**, le runner non | trois gardes mesuraient un refus root |
| 6 | `composer` **2.7.9** au banc, **dernière v2** en CI | une garde figeait la prose de l'outil |

#### Le premier est le plus grave, et il se mesure

```
app/Console/Commands, dans le conteneur a35r, 2026-08-21 :
  scandir() / glob() / find (shell) ....... 56 fichiers
  RecursiveDirectoryIterator .............. 14      <- stable sur trois passages
  ( app/ entier : 293 fichiers vus par find, 251 par l'itérateur )
```

Ce n'est **ni aléatoire, ni un problème de droits** : c'est le montage de Docker
Desktop pour Windows qui ne rend pas tout le répertoire à cet itérateur-là.

**Ce que ça a coûté.** Les plafonds de `B10-016-PORTEE PLAFOND` ont été écrits
d'après **un quart** du répertoire le plus concerné, puis vus verts :

| table | figé au banc | réel (CI) |
|---|---:|---:|
| companies | 16 | **22** |
| contacts | 23 | **26** |
| media | 1 | **5** |
| workspaces | 10 | **17** |
| **total** | **67** | **87** |

Et la garde `COLONNES MORTES` affirmait « **un seul** site de tout `app/` pose
`deleted_at` ». C'était faux. Il y en a **deux** :

```
app/Console/Commands/ImportMediaMerge.php:197
  DB::table('media')->whereIn('id', $paquet)->update(['deleted_at' => now(), ...])
```

C'est l'**archivage des médias** sortis du registre, et il tourne en automatisme.
`media` porte donc une corbeille **vivante** — la seule avec `users` — et les
cinq lectures aveugles de `media` recensées juste au-dessus lisent des lignes
archivées comme si elles ne l'étaient pas.

#### ⚠️ CE QUI RESTE À FAIRE, ET QUI N'EST PAS FAIT

**TREIZE gardes de la suite balaient un répertoire avec
`RecursiveDirectoryIterator`.** Une seule a été réparée (`scandir` récursif, qui
rend bien 56/56 et 293/293). Sur ce banc, **le « rien trouvé » des douze autres
ne vaut rien** :

```
ArretCollecteCoteNodeTest              IndexEmailIngestionServentLesRequetesTest
AucuneFonctionGlobaleEnDoubleTest      AucunMessageDansToContainTest
RedemarrageNeRelitPasEnvTest           RunbookRestaurationDrTest
SauvegardeEmporteLesExtensionsTest     ContexteEspaceDesJobsTest
CoucheAutorisationAtteignableTest      OppositionCouvreTousLesUniversTest
RolePorteurDeLaRlsTest                 SsrfCompletudeTest
```

Elles sont **vertes en CI** (arbre complet), donc non bloquantes — mais toute
mesure faite **depuis le banc** sur l'une d'elles est à refaire. `ContexteEspaceDesJobsTest`
porte deux ÉNUMÉRATIONS du lot `B11-002` : leur verdict local ne prouve rien, seul
celui de la CI compte.

> 🔑 **La règle qui en sort, et elle généralise le §8 :** *un banc n'est fidèle
> que là où on l'a mesuré.* Les dépendances (`vendor`, `node_modules`), les
> fichiers de configuration (`.env`, `.github/`), l'identité du processus (root),
> la version des outils (`composer`, `gitleaks`) et **jusqu'à la façon dont on
> liste un répertoire** diffèrent — et chacun de ces écarts a produit ici, au
> moins une fois, un vert qui ne valait rien. La référence est `composer.lock`,
> `pnpm-lock.yaml`, et la CI.


### 13.9 ✅ VERDICT — la PR #191 est **CLEAN**, mesurée le 2026-08-21 à 09 h 15

```
PR #191   mergeable = MERGEABLE   mergeStateStatus = CLEAN
Backend Laravel (install + PHPStan + Pint + Pest) .... SUCCESS
Suites e2e sur le build (BLOQUANT) ................... SUCCESS
axe-core Playwright / Lighthouse CI .................. SUCCESS
Frontend React/Vite .................................. SUCCESS
Secrets scan (Gitleaks) .............................. SUCCESS
Container scan (Trivy) x3 ............................ SUCCESS
Les scripts d'infra sont-ils exécutables ? ........... SUCCESS
… 20 SUCCESS, 2 SKIPPED, 1 NEUTRAL — AUCUN ROUGE

Pest :  1464 passed, 0 failed, 3 incomplete (9177 assertions)
```

**Point de départ : 7 contrôles rouges et une suite qui n'atteignait jamais
Pest.** Le job « Backend Laravel » sortait sur les 38 erreurs PHPStan — Pint et
Pest, tous deux BLOQUANTS, n'étaient donc **jamais joués**. Les débloquer a
révélé 10 rouges de plus, tous dus aux écarts banc/CI du §13.8.

⚠️ **Trois tests restent `incomplete`** (auto-ignorés par `markTestSkipped`, pas
des échecs). Ils ne bloquent pas, mais **personne ne les a identifiés** : à
nommer avant de déclarer la suite pleinement couverte.

⚠️ **Ce qui n'est PAS fait, et qui doit être dit** : sur les treize gardes qui
balaient un répertoire avec `RecursiveDirectoryIterator`, **trois** ont été
fiabilisées (`EffacementDouxPortee`, `ContexteEspaceDesJobs`, et le témoin
`C21-001`). Une tentative de conversion automatique des deux suivantes
(`IndexEmailIngestionServentLesRequetes`, `OppositionCouvreTousLesUnivers`) a
cassé leurs boucles — **annulée, les deux fichiers sont revenus à l'état poussé
et rejoués verts (16 tests)**. Les dix restantes visent des dossiers NON tronqués
(`tests/`, `app/Http/Controllers`, `infra/scripts` : mesurés complets) ; elles ne
sont donc pas fausses aujourd'hui, mais elles le deviendraient le jour où l'un de
ces dossiers dépasserait le seuil. **À convertir à la main, une par une.**

### 13.10 PASSE 3 — les trois S0 « ouverts » du §11, re-mesurés dans le CODE

Le §11 les donne ouverts. **Règle 1 : le code fait foi.** Mesure du 2026-08-21 :

| constat | ce que le §11 dit | ce que le code dit |
|---|---|---|
| `P6-UI-001` — l'accueil affiche « aucune entreprise » | 🔴 ouvert, `/dashboard/stats` est *« une closure à zéros en dur »* | ✅ **fermé** — la route pointe `DashboardController::stats`, qui compte réellement (`companies_total`, `contacts_qualified`, `scraper_runs_24h`, répartition qualité) et rend des zéros **seulement** hors contexte d'espace |
| `P6-UI-002` — la palette ⌘K ne peut rien trouver | 🔴 ouvert, *« `GET /search` déclarée deux fois, trois tableaux vides en dur »* | ✅ **fermé** — `/search` n'est déclarée **qu'une fois** (`api.php:238`) et `GlobalSearchController` interroge vraiment `companies`, `contacts`, `tags` |
| `P6-INFRA-003` — douzième chemin vers la faille du 19/08 | 🔴 ouvert | ⚠️ **réel, et il l'était encore** — voir ci-dessous |

#### `P6-INFRA-003` : documenté n'est pas gardé

Le fichier `docker-compose.observability.yml` porte un avertissement en tête, ne
recopie pas la forme fautive, et prescrit la bonne commande. **Mais rien ne
l'empêchait.** La garde CI `config-prod` ne fusionnait que `base + prod` — la
combinaison prescrite par ce fichier-là n'était **jamais rendue**.

*Le §3 bis point 5 du mandat exige qu'une garde rougisse **sur l'objet qui
casse**. Ici il n'y avait pas de garde du tout : un commentaire n'arrête
personne.* Étendue (`ab4bfe1`), et vue rouge sur mutation.

#### 🔴 Et l'extension a trouvé un défaut DANS LA GARDE EXISTANTE

Elle lisait `published:` **sans regarder `host_ip:`** :

```
base + prod + observabilité, sans host_ip :
  80 443 3000 3001 3100 3200 4317 4318 8080 9090 9093
avec host_ip :
  80 443
```

Les neuf ports d'observabilité sont liés à `127.0.0.1` — ils n'exposent rien.
Étendre la garde sans corriger l'extracteur l'aurait fait **rougir à tort** sur
une configuration saine, *et une garde qui crie faux finit désactivée.*

#### Ce que le témoin négatif établit, et qui reste ouvert

```
docker-compose.yml SEUL publie : 80 443 8080 55432 56379
```

**`P6-INFRA-003` est ouvert À LA SOURCE.** Le fichier de base publie Postgres et
Redis sur `0.0.0.0` ; seul l'overlay `prod` les referme par `ports: !override []`.
La sécurité repose donc sur *ne jamais oublier un fichier* — **le défaut est
ouvert, la fermeture est l'exception**. Le job le rappelle en `::warning::` à
chaque exécution.

> 🔑 **Le geste de fond, non fait, et qui appartient à une décision d'infra :**
> retirer ces deux ports du fichier de BASE et les poser dans
> `docker-compose.local.yml`. Le défaut deviendrait *fermé*, et aucun oubli
> d'overlay ne pourrait plus rouvrir la faille. ⚠️ Sans effet immédiat en
> production — `deploy-direct-ssh.yml` ne recrée que `api app horizon scheduler`
> avec `--no-deps` (§3 bis point 4) — mais décisif pour toute recréation future.
> **À arbitrer par Will.**

### 13.11 PASSE 3 — la file d'attente du §9, re-mesurée point par point

Le §9 range six entrées « dans l'ordre du danger ». Chacune a été rouverte dans
le CODE, pas dans un rapport.

| # | entrée du §9 | verdict du 2026-08-21 |
|---|---|---|
| 1 | `F38-007` | ✅ déjà fermé le 20/08 (`23a0e5f`) |
| 2 | `C18-016 / F37-002` (S0) — six services **simulés en production** | ✅ **fermé** — `MockServicesProvider` prend le service RÉEL par défaut en `production`/`staging`, et **refuse** un simulacre en production *même explicitement demandé* |
| 3 | `B15-001` (S0) — une personne effacée revient au vivier | ✅ **fermé** — `addOptOut()` écrit les **deux** univers par défaut (`UNIVERS_OPPOSITION`), et `GdprErasureService` l'appelle ainsi |
| 4 | `B10-004` (S0) — export RGPD partiel | ✅ **fermé** par cette vague (`8db4417`) |
| 5 | `B15-008` — les sept commandes destructives | ✅ **fermé** par cette vague (`b4a5cd8`, `9f15664`) — voir ci-dessous |
| 6 | `B12-012` (S1) — `sameWorkspace()` compare deux UUID castés en entier | ✅ **fermé** — `BasePolicy:96` fait `hash_equals((string) …, (string) …)` |

**Quatre des six étaient déjà fermés et la file les donnait ouverts.** C'est le
même défaut que le §7 a mesuré sur les comptes tenus à la main : *un état recopié
ne vaut pas un état relu.*

#### `B15-008` : pourquoi il était resté « partiel » depuis le début

Le trait `RefuseUneSuppressionMassive` existait, et trois commandes le portaient.
Les quatre autres ne pouvaient pas le prendre : **sa dernière barrière exige
`--force` dès que l'entrée n'est pas interactive**, et ces quatre-là tournent
sous le planificateur. Le poser tel quel les aurait rendues muettes ; lui ajouter
`--force` dans `console.php` aurait retiré la garde en ayant l'air de la poser.

D'où `ecritureAutoriseeSansOperateur()` — le plafond, sans la confirmation.

| commande | quand | ce qu'elle détruisait sans borne |
|---|---|---|
| `media:clean-emails` | **chaque jour 05:05** | `media.email → NULL` sur tout ce que son détecteur juge « sur-partagé » |
| `retention:prune-scraper-runs` | chaque jour 04:20 | `scraper_runs` de plus de N jours |
| `rgpd:purge-vivier` | mensuel | `candidates` + leurs `activities` |
| `rgpd:purge-business-prospects` | mensuel | `contacts` de plus de 3 ans |

⚠️ Les deux purges RGPD portaient bien un `skip()` — mais c'est un **drapeau
d'activation** (`CRM_PURGE_ENABLED`), pas un plafond : il empêche la commande de
tourner, il ne borne rien le jour où elle tourne. *C'est exactement le genre de
garde qu'on prend pour une autre.*

🔑 **La décision, et elle n'est pas neutre : on BLOQUE, même quand l'effacement
est une obligation légale.** Refuser une purge de rétention retarde une échéance ;
laisser passer une purge erronée détruit ce qui ne revient pas. *L'irréversible
l'emporte.*

#### 🔴 Deux défauts que mes propres gardes m'ont trouvés

- **`B10-016-PORTEE PLAFOND` a rougi en CI sur mon commit.** Elle avait raison :
  mes comptages `DB::table('media')->…->count()` ignoraient `deleted_at`, alors
  que `media` porte une corbeille vivante — *établi la veille par cette même
  garde*. Je comptais des lignes archivées **aux deux termes du rapport**. Le
  plafond n'a pas été relevé : c'est mon code qui était faux.
- **PHPStan** a signalé que `retention:prune-scraper-runs` n'a pas de `--dry-run`,
  que le trait suppose. Vrai manque : elle supprime définitivement, chaque jour,
  sans aucun moyen de voir ce qu'elle ferait — quand les cinq autres l'ont.
  Ajoutée **et honorée**.

Le recensement du banc porte désormais une **liste vide** : les six commandes qui
détruisent ont toutes une barrière. *Elle doit rester vide — ce n'est pas une
dérogation, c'est le registre de ce qui reste à faire.*

### 13.12 Les dix-sept commits de la vague

| commit | contenu |
|---|---|
| `4a6d574` | `B11-002` — six jobs sur six, pas cinq |
| `bb2fbb6` | `A-007` — Telescope **refuse** la production |
| `f115af2` | deux scripts d'infra sans bit d'exécution |
| `8db4417` | `B14-002` + `B10-004` — trois droits RGPD sur cinq |
| `26ea176` | `B10-016` — la garde qui a contredit son auteur |
| `c95e673` | `state.loadedAt` disparu — quatre contrôles débloqués |
| `f1c3344` | Gitleaks — six fausses fuites, témoin négatif à l'appui |
| `7002de6` | PHPStan 38 → 0, quatre défauts trouvés en chemin |
| `32f6f46` | Pint — 110 écarts sur les 227 fichiers de la PR |
| `f10c77d` | les trois défauts que Pest a montrés une fois atteint |
| `b732d75` | les cinq gardes vertes au banc et rouges en CI |
| `46e41ff` | composer a REPARE H47-001 en amont ; une enumeration avait raison par chance |
| `ab4bfe1` | `P6-INFRA-003` — la garde des ports ne rendait qu'une combinaison sur trois |
| `b4a5cd8` | `B15-008` — un automatisme detruisait des adresses chaque nuit, sans plafond |
| `9f15664` | `B15-008` — les trois dernieres commandes destructives ; constat CLOS |

⚠️ **Un défaut de dispositif, et il est de moi.** Le premier découpage a fait
passer le `chmod` des scripts d'infra dans le commit RGPD, dont le message n'en
disait rien. Redécoupé en trois commits ; l'identité du contenu a été **prouvée**
(`git diff filet HEAD` vide) avant de retirer le filet.

---

## §14 — Une garde verte par chance, et ce qu'elle cachait

`CompteursHubTest > les compteurs sont servis par un index couvrant` a bloqué la
PR #192 pendant toute la vague 15. Elle exigeait
`Index Only Scan using idx_companies_ws_counts` sur `companies`, et elle rougissait
en CI sur des commits qui ne touchaient **ni la table, ni ses index, ni ce fichier**.
Jouée seule au banc, elle restait verte — quatre fois de suite.

### 14.1 Deux tentatives, deux aggravations

| tentative | résultat en CI |
|---|---|
| `ANALYZE companies` avant l'`EXPLAIN` | **pire** : `Sort` + `GroupAggregate`, plus aucun index |
| 600 lignes de volume + `ANALYZE` | **pire encore** : `Bitmap Heap Scan` |

Je corrigeais un symptôme sans avoir mesuré la cause. *Deux correctifs posés sur
une hypothèse, et deux fois le contraire de l'effet voulu.*

### 14.2 La mesure qui a tranché

Cinq plans, la même requête à chaque fois, mesurés en `psql` sur le banc :

| état de la table | plan obtenu |
|---|---|
| propre, 2 lignes, `ANALYZE` | `Index Only Scan` ✅ |
| **ballonnée**, 2 lignes, `ANALYZE` | `Sort` + `Bitmap` ❌ |
| ballonnée, lignes **validées** + `VACUUM ANALYZE` | `Sort` + `Bitmap` ❌ |
| réplique **vierge**, 600 lignes fraîches | `Bitmap Heap Scan` ❌ |
| réplique vierge, `seqscan` **et** `bitmapscan` coupés | `Index Only Scan` ✅ |

Le deuxième cas reproduit l'échec CI **à l'identique** (`Sort` + `GroupAggregate`,
`rows=2`). Deux causes, et aucune n'est à la portée d'un test :

1. `RefreshDatabase` annule les **données** entre deux tests, pas l'**état
   physique** — les tuples morts et le nombre de pages que les ~1 478 tests
   voisins laissent dans `companies` restent, et c'est sur eux que le
   planificateur raisonne ;
2. un `Index Only Scan` exige que la carte de visibilité déclare les pages
   « toutes visibles ». Seul `VACUUM` la met à jour, il ne tourne pas dans une
   transaction, et il ne peut de toute façon rien déclarer des lignes d'une
   transaction non validée.

**Donc la garde ne prouvait pas ce qu'elle annonçait.** Son verdict était une
fonction de l'ordre dans lequel Pest tirait les tests. *Une garde dont le
résultat dépend de ses voisines finit désarmée comme un test capricieux — et
c'est la fonctionnalité qu'elle couvrait qui part avec elle.*

### 14.3 Ce qui la remplace

Deux temps, tous deux déterministes :

- **le contrat**, lu dans `pg_index` : l'index existe, il est `indisvalid`, il
  porte les trois colonnes et le partiel `WHERE deleted_at IS NULL`. `indisvalid`
  n'est pas décoratif — un `CREATE INDEX CONCURRENTLY` interrompu laisse un index
  qui **existe** et que PostgreSQL n'utilisera jamais ;
- **le comportement**, sur une réplique `LIKE companies INCLUDING INDEXES` créée
  et remplie par le test lui-même. Elle hérite du schéma **réel** — la preuve
  reste accrochée à la vraie table — mais aucun test voisin ne peut la ballonner.
  Trois passages, coûts identiques au centième.

Plus un témoin négatif (l'index retiré de la réplique, l'`Index Only Scan` doit
disparaître) et un dernier contrôle sur la vraie table : le seul énoncé qui reste
vrai quel que soit son état physique — elle ne retombe pas en `Seq Scan`.

### 14.4 Deux défauts trouvés en réécrivant, dont un que je portais

- **`SET` au lieu de `SET LOCAL`** — la connexion est partagée entre les tests
  d'un même processus. Un `SET enable_seqscan = off` que le test n'atteint pas à
  cause d'une assertion rouge restait posé pour **tous les tests suivants** et
  faussait leurs plans. L'ancienne version portait ce défaut ; il explique
  peut-être une part des intermittences observées ailleurs.
- **`toContain($aiguille, $message)`** — j'allais le réécrire ainsi. `toContain`
  est **variadique** dans Pest : le message y devient une seconde aiguille
  cherchée dans le texte. Le piège avait déjà été payé dans cette campagne
  (garde `AucunMessageDansToContain`). Remplacé par
  `str_contains(...)->toBeTrue($message)`.

### 14.5 Deux mutations qui n'ont rien prouvé, et pourquoi

J'ai d'abord ballonné la table puis supprimé l'index **dans `axion_crm`**, et la
garde est restée verte les deux fois.

⚠️ **CORRECTION DU 2026-08-21, et elle porte sur ce que j'ai écrit ici même.**
J'ai d'abord attribué ces deux verts à `migrate:fresh`, qui aurait effacé mes
mutations avant le test. C'est faux. La vraie raison est plus simple et plus
gênante : `phpunit.xml` **force** `DB_DATABASE=axion_crm_test`. Les tests
n'ouvrent **jamais** `axion_crm`. Je mutais une base que personne ne lisait.

*Le dispositif de mesure faisait partie de ce qu'il fallait mesurer* — c'est le
piège du §13.8, et je viens de le repayer deux fois : une fois en mutant la
mauvaise base, une fois en expliquant l'échec de ces mutations par une cause
plausible que je n'avais pas vérifiée.

Ce qui reste vrai : la mutation valide a porté sur la **migration**, jouée par
`artisan test` — donc sur `axion_crm_test` — et la garde est bien tombée. Et les
mesures de plan faites en `psql` sur `axion_crm` gardent leur valeur : elles
portaient sur des tables **temporaires** que je créais moi-même à partir du même
schéma, et elles mesurent le comportement du planificateur, pas l'état d'une base
particulière.

La mutation valide a porté sur la **migration** : privée de sa création d'index,
la garde tombe sur « L index couvrant `idx_companies_ws_counts` a DISPARU ».
Migration restaurée, quatre passages verts en ordre aléatoire.

---

## §15 — La famille entière des gardes de plan, et pourquoi personne ne l'avait vue

Le §14 a fermé une garde verte par chance. En jouant enfin la **suite complète**
plutôt que des fichiers isolés, deux autres sont tombées — de la même famille, et
pour la même raison.

### 15.1 Le banc ne pouvait pas jouer la suite entière

C'est la découverte qui explique tout le reste.

| tentative | résultat |
|---|---|
| `php artisan test` | **mort** à 730 tests — `Allowed memory size of 134217728 bytes exhausted` |
| `php -d memory_limit=1G artisan test` | **mort** à 816 tests — sortie 255, sans résumé ni message |

*Personne ne voyait ces gardes rougir parce que personne n'allait jusqu'au bout.*
Jouée fichier par fichier, chacune est verte : la table est alors propre. Jouées
en suite, les tests voisins y laissent des tuples morts et des pages, et le
planificateur change d'avis — légitimement. La CI, elle, va au bout, ce qui
explique qu'elle ait rougi deux fois là où mon banc restait vert.

La suite est désormais jouée **par tranches**, ce qui règle la mémoire et donne
enfin un verdict complet.

### 15.2 La règle qui se dégage

> Une garde de plan est saine **si et seulement si** son jeu d'essai rend l'index
> visé réellement sélectif, **et** qu'elle établit ses propres statistiques.
> Elle est verte par chance dès que le choix se joue à égalité de coût.

Mesure à l'appui, sur le même jeu de 30 fiches :

| statistiques | plan obtenu |
|---|---|
| table ballonnée (stats d'un voisin) | le **bon** index ✅ |
| table neuve (aucune statistique) | le **mauvais**, avec un `Filter` ❌ |

Le `ANALYZE` n'est donc pas un confort : sans lui, une garde hérite du verdict de
sa voisine. Et il ne suffit pas non plus — il faut d'abord que l'index ait une
raison de gagner.

### 15.3 `IndexesEmployesParLeProduitTest` — le seul sans ni l'un ni l'autre

Le recensement de la famille est sans ambiguïté :

| fichier | `ANALYZE` | volume |
|---|---|---|
| `IndexEmailRgpdServentLesRequetes` | 10 | 13 |
| `IndexEmailIngestionServentLesRequetes` | 5 | 11 |
| `VolumeDeProductionHubConsole` | 5 | 9 |
| `IndexServentLesRequetes` | 1 | 7 |
| **`IndexesEmployesParLeProduit`** | **0** | **1** |

Le seul fichier sans aucun des deux est exactement celui qui a rougi. Ses gardes
se jouaient sur **une** fiche — qui ne remplissait **aucun** des prédicats
qu'elles interrogent. Or quatre des index visés sont **partiels** : sur cette
fiche, ils sont vides, aucun n'est meilleur, et le planificateur départage
arbitrairement.

Le jeu d'essai posé : 200 fiches, réparties pour que chaque index partiel soit
franchement le plus sélectif. Le cas le plus délicat a demandé trois mesures :
`idx_companies_revalidate` (partiel) concurrence `companies_website_status_index`
(plein), et tant que les deux portaient le même nombre de lignes, **le plein
gagnait**. Il a fallu des fiches *déjà revalidées* — celles que la production
accumule — pour que le partiel soit vraiment plus petit.

Vérifié table neuve **et** table ballonnée (8 000 tuples morts), deux passages :
les six gardes nomment leur index dans les deux cas.

### 15.4 Un témoin dont la prémisse était fausse

`C21-001/twins — TEMOIN : sans volume, Postgres balaie, et il a raison`.

Mesure, **même ligne unique, même requête** :

| état de `candidates` | plan |
|---|---|
| propre | `Seq Scan` (coût 1,01) |
| ballonnée | `Index Scan using idx_candidates_email` (coût 8,27) |

Un balayage qui doit lire 200 pages perd contre un index qui en lit deux, et le
planificateur a raison. *Le témoin ne mesurait pas l'instrument, il mesurait ses
voisins.*

Réécrit pour que sa prémisse soit vraie par construction : il interroge désormais
`candidates.last_name`, qui ne porte **aucun** index (catalogue vérifié : sept
index, aucun sur cette colonne). Le balayage est alors le seul plan possible,
quel que soit l'état de la table — vérifié jusque `enable_seqscan` découragé.

Son pouvoir discriminant n'est pas perdu : le témoin suivant montre qu'**au même
volume**, la forme `lower(email::text)` reste un balayage là où la forme correcte
prend l'index. Deux plans opposés dans les mêmes conditions — c'est cela qui
prouve la mesure, pas une comparaison entre deux états de table incomparables.

### 15.5 Ce que cette vague dit du reste de la campagne

Trois gardes de plan sur les sept fichiers de la famille ne prouvaient pas ce
qu'elles annonçaient, et toutes trois avaient été écrites **pendant cette
campagne**. Le défaut n'est pas dans le produit : il est dans ma façon de
mesurer. *Une garde qu'on n'a jamais vue rougir dans les conditions réelles de la
CI n'est pas une garde, c'est une conjecture verte.*

---

## §16 — La suite jouée en entier, enfin ; et une opposition RGPD qui survivait

### 16.1 Le verdict complet, par tranches

| tranche | contenu | verdict |
|---|---|---|
| 1 | `Crm` `Infra` `Database` `Console` `Commands` | 520 passés, 1 incomplete |
| 2 | `Rgpd` `Auth` `Controllers` `Api` `Audiences` … | 423 passés, 2 incomplete |
| 3 | `Unit` + les 25 fichiers racine de `Feature` | 539 passés, 1 skipped |

**1 482 tests, zéro échec.** C'est le premier verdict complet de la campagne — le
banc n'avait jamais pu aller au bout d'un seul tenant.

### 16.2 Deux échecs que j'ai fabriqués moi-même

La tranche 3 a d'abord annoncé deux échecs, `relation "audit_logs" does not
exist`. Ils ne se reproduisaient ni fichier par fichier, ni à deux, ni **en
rejouant la graine exacte** (`1787341146` → 539 verts).

La cause était dans mon fichier de sortie : il contenait **deux résumés**. J'avais
lancé la tranche 3 deux fois — une première avec un `&` que j'avais cru mort, une
seconde correctement. Les deux processus jouaient `migrate:fresh` sur la **même
base**, en même temps : l'un détruisait les tables pendant que l'autre lisait.

*C'est la règle que j'ai moi-même consignée au §13 de cette campagne, et que je
viens d'enfreindre.* Une suite jouée pendant qu'un autre processus refait la base
ne prouve rien — pas plus qu'une suite jouée pendant que des agents éditent
l'arbre.

### 16.3 Les trois `incomplete`, enfin nommés

Ils traînaient sans identité depuis plusieurs vagues. Ce ne sont pas des oublis :

| fichier | ce qu'il déclare |
|---|---|
| `Infra/VolumeDeProductionHubConsoleTest:422` | `G41-002` — le OU multi-champs de `applySearch` empêche l'index trigrammes ; arbitrage à Will |
| `Rgpd/OppositionVoieJumelleAnnuaireTest:169` | `C19-010` — la voie jumelle laisse entrer l'identité d'un dirigeant opposé |
| `Rgpd/PurgeNonDiffusibleVariantesTest:160` | `C19-010` — le rattrapage ne voit qu'une forme de marquage |

Le troisième est **fermé** par cette vague ; les deux autres restent, et disent
pourquoi.

### 16.4 `C19-010` — une opposition qui survivait à sa propre purge

Une personne physique ayant demandé à l'INSEE de **ne pas être publiée** entrait
sous « [ND] [ND] » et survivait à `prospection:purge-non-diffusible`, dont la
condition était une égalité stricte sur `'[ND]'`.

La vague précédente avait **mesuré** le correctif et l'avait laissé en attente :
le fichier était hors de son périmètre écrit. Il est dans celui-ci.

**Un second défaut trouvé en chemin, et il est plus grave que le premier** : la
condition était écrite **deux fois** — une pour compter, une pour supprimer. Qui
n'en corrigeait qu'une faisait mentir le plafond de `RefuseUneSuppressionMassive`
— la garde aurait autorisé une suppression sur la foi d'un décompte plus étroit
qu'elle. Il n'y a plus qu'une définition.

⚠️ **Ce que je n'ai pas fermé, et pourquoi.** La FORME 3 est une fiche *sans
dénomination*. Elle est indiscernable d'un entrepreneur individuel parfaitement
légitime, qui arrive sans dénomination par la même voie. La purger serait
exactement le piège `B15-004` — « `legal_form IS NULL` » — qui a failli effacer la
base entière. *Une purge trop large n'est pas une purge prudente : c'est une
perte de données irréversible.* Son rattrapage demande de rejouer l'INSEE :
travail de commande, **arbitrage à Will**.

### 16.5 Ce qui reste ouvert et n'appartient qu'à Will

Trois arbitrages, tous documentés dans le registre et dans les gardes :

1. **`C19-010` / FORME 3** — rejouer l'INSEE sur les fiches sans dénomination.
2. **`C19-010` / voie jumelle** — `recherche-entreprises.api.gouv.fr` laisse
   entrer l'identité d'un dirigeant opposé, date de naissance comprise ;
   `statut_diffusion` n'apparaît **nulle part** dans le dépôt.
3. **`G41-002`** — le OU multi-champs de `applySearch` empêche l'index
   trigrammes ; le corriger change une sémantique de recherche.

### 16.6 🔴 CONSTAT NOUVEAU — un déploiement en échec ne prévient personne

En vérifiant le journal GitHub Actions du déploiement `377febf`, j'ai trouvé que
**j'avais écrit un récit faux** — dans une garde, dans le commentaire du workflow
de production, et dans mes comptes rendus à Will.

J'affirmais que le déploiement avait « réussi de bout en bout » pendant que l'API
rendait 502. Le journal dit le contraire :

```
GET https://api.axion-crm-pro.com/up
curl: (22) The requested URL returned error: 502
##[error]Health check failed
```

L'étape `Smoke test prod` a détecté la panne **dès la 21ᵉ seconde** et fait
échouer le job.

**Ce que cela change.** Le correctif Caddy est identique et la garde le défend
toujours. Mais le défaut d'alerte n'est pas celui que je décrivais :

| ce que je croyais | ce qui est |
|---|---|
| rien ne détectait la panne | la détection existe, et a marché en 21 s |
| le déploiement se déclarait vert | le job était **rouge** |
| — | **personne n'a lu le rouge**, treize minutes durant |

*Une alarme que personne ne reçoit n'est pas une alarme.* Un déploiement en échec
n'envoie aucune notification : le rouge attend sur GitHub qu'on vienne le
regarder.

Ce qui a égaré le diagnostic sur le moment, ce sont les conteneurs : tous
`healthy`, Caddy compris, pendant que Caddy parlait à une adresse morte. **Un
`healthcheck` qui interroge le conteneur lui-même ne dit rien de ce qu'il
ATTEINT.**

⚠️ **Arbitrage à Will** : où doit partir l'alerte d'un déploiement rouge ?
Le canal n'est pas une question technique, c'est un choix (courriel, Slack,
issue GitHub automatique…), et il n'appartient pas au dépôt d'en décider seul.

— et c'est la **troisième fois** de cette vague que je publie une affirmation
sans l'avoir vérifiée : la mauvaise base, la cause inventée de deux mutations, et
ce récit-ci. Les trois ont été corrigées par une mesure, jamais par un
raisonnement.
