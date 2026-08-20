# OÙ NOUS EN SOMMES — fiche de reprise

> **Mise à jour après chaque correctif.** Si Claude Code se ferme, tout est ici : ce qui est
> fait, ce qui est en cours, et comment reprendre exactement au même point.
>
> **Dernière mise à jour : 2026-08-20.**

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

## 7. Compte des constats — révisé le 2026-08-20

| | |
|---|---|
| Constats **uniques** après dédoublonnage | **508** |
| dont S0 | 34 · **13 fermés** · 21 restants |
| dont S1 | 121 · 9 fermés · 112 restants |
| dont S2 | 265 · 12 fermés · 253 restants |
| dont S3 | 88 · 1 fermé · 87 restants |
| **Total fermé / restant** | **35 / 473** |

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
- **Une requête en lecture seule sur la production**, pour trancher le chiffre de `B17-012` :
  ```sql
  SELECT count(*) FILTER (WHERE legal_form IS NULL), count(*) FROM companies;
  ```
  *C'est la question à poser, pas un fait établi.* Voir `_AUDIT/RECTIFICATIF-PR-191.md`.

## 11. P6 — le regard neuf, toujours jamais lancée

La phase P6 du mandat (`_PROMPTS/PROMPT_AUDIT_360_CRM_PRO_2026-08-18.md` §8) n'a **jamais été
exécutée**. Elle demande des agents **neufs**, sans accès aux rapports des passes 1 et 2 ni à
`02_CONSTATS.md`, refaisant l'audit de zéro sur le périmètre des §4 et §5 — puis la
comparaison des trois passes, tout écart étant **en soi un défaut de méthode**.

La définition de fini du §12 est à **3 points sur 16**.
