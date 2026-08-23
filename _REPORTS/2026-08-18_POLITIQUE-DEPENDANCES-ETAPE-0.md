# Politique de dépendances — gel jusqu'à la fin de l'étape 0

**Dépôt** : `will383842/axion-crm-pro`
**Date** : 2026-08-18
**Portée** : `.github/dependabot.yml`, `CONTRIBUTING.md`, ce document.
**Statut** : décision **tranchée**, appliquée dans la configuration.

---

## 1. Décision

> **Toutes les montées de dépendances — majeures comme mineures comme
> correctives — sont GELÉES jusqu'à la fin de l'étape 0 du chantier CRM.**
>
> **Les mises à jour de SÉCURITÉ ne sont PAS gelées.**

Le gel est porté par des règles `ignore` / `update-types: version-update:semver-*`
dans `.github/dependabot.yml`, pas par un plafond de PR. Tous les `groups:` ont
été retirés.

---

## 2. Motifs

### 2.1 Le précédent de production du 2026-08-18 (dépôt du site)

Le dépôt du site (`axionia`) a été mis en **panne totale de déploiement** le
2026-08-18 par **deux montées Dependabot fusionnées séparément**. Chacune
ajoutait la même clé (`baseline-browser-mapping@2.11.14`) au lockfile pnpm.
Chaque PR était **verte seule** ; c'est leur **fusion** qui a produit un
lockfile à clé dupliquée → `ERR_PNPM_BROKEN_LOCKFILE` dès le premier step
d'installation.

Deux enseignements, tous deux structurants ici :

- 🔑 **Les gates testent chaque PR séparément, jamais leur fusion.** Aucune
  quantité de vert par-PR ne prouve que deux PR fusionnent proprement. `git
  merge-tree` ne valide pas davantage un lockfile : il faut vérifier
  l'**unicité des clés** du fichier fusionné, pas les specifiers.
- 🔑 **Ce fut un incident de PRODUCTION, pas de CI.** La Gate qui est morte est
  morte *à l'installation*, donc **aucun test n'a été exécuté** — le rouge ne
  disait pas « le code est cassé », il disait « rien n'a pu tourner ».

### 2.2 Mesurer un socle qui bouge sous les pieds

L'étape 0 du chantier CRM consiste précisément à **poser un harnais de tests**.
Passer 20 montées une par une pendant ce temps rendrait tout rouge ambigu :
impossible de dire si l'échec vient du harnais qu'on écrit ou de la dépendance
qu'on vient de monter. On fige le socle, on le mesure, puis on le bouge.

### 2.3 Le plan de préalables accepte explicitement cette option

Critère de sortie de la ligne 9 : « **0 PR Dependabot ouverte, ou politique
écrite “figé jusqu'à fin de chantier”** ». C'est la **seconde branche** qui est
retenue, et ce document en est la formalisation.

---

## 3. Inventaire mesuré des PR ouvertes

Mesure faite le 2026-08-18 :
`gh -R will383842/axion-crm-pro pr list --limit 200 --state open --json number --jq 'length'` → **20**.

**Le plan annonçait 20 PR ; la mesure en confirme 20.** Les 20 PR ouvertes sont
**toutes** des PR Dependabot (auteur `app/dependabot`) : il n'y a **aucune autre
PR ouverte** sur le dépôt. Toutes ont été créées le **2026-08-17** — c'est un
lot unique et frais, pas le vieux backlog de mai (celui-ci avait été purgé le
2026-08-16). **Aucune** ne porte de label `security`.

### 3.1 Ventilation par écosystème × gravité semver

| Écosystème            | Majeures | Mineures | Correctifs | Total |
| --------------------- | -------: | -------: | ---------: | ----: |
| composer `/backend`   |    **4** |        0 |          0 | **4** |
| npm `/frontend`       |    **6** |    **2** |          0 | **8** |
| npm `/workers`        |    **1** |    **2** |          0 | **3** |
| github-actions `/`    |    **5** |        0 |          0 | **5** |
| docker `/`            |        0 |        0 |          0 | **0** |
| **Total**             |   **16** |    **4** |      **0** | **20** |

**16 majeures sur 20 (80 %).** Aucune montée corrective. C'est un lot à risque
élevé, pas un lot d'entretien.

### 3.2 Détail, PR par PR, et sort de chacune

Toutes sont **gelées** : la nouvelle configuration les couvre intégralement
(les trois niveaux semver sont ignorés sur `*`, pour les cinq écosystèmes).

| PR   | Écosystème     | Paquet                          | De → vers          | Semver    | Sort  |
| ---- | -------------- | ------------------------------- | ------------------ | --------- | ----- |
| #164 | npm /frontend  | `react-i18next`                 | 15.7.4 → 17.0.11   | **MAJEURE** (×2) | gelée |
| #163 | npm /frontend  | `@sentry/cli` (dev)             | 2.58.5 → 3.6.2     | **MAJEURE** | gelée |
| #162 | npm /frontend  | `react-joyride`                 | 2.9.3 → 3.2.0      | **MAJEURE** | gelée |
| #161 | npm /frontend  | `eslint-plugin-react-hooks` (dev) | 5.2.0 → 7.1.1    | **MAJEURE** (×2) | gelée |
| #160 | github-actions | `docker/setup-buildx-action`    | 3 → 4              | **MAJEURE** | gelée |
| #159 | npm /frontend  | `date-fns`                      | 4.2.0 → 4.4.0      | mineure   | gelée |
| #158 | github-actions | `pnpm/action-setup`             | 4 → 6              | **MAJEURE** (×2) | gelée |
| #157 | github-actions | `github/codeql-action`          | 3 → 4              | **MAJEURE** | gelée |
| #156 | npm /frontend  | `@tanstack/react-table`         | 8.21.3 → 9.1.2     | **MAJEURE** | gelée |
| #155 | npm /frontend  | `lucide-react`                  | 0.460.0 → 1.31.0   | **MAJEURE** (0.x→1.x) | gelée |
| #154 | composer       | `pragmarx/google2fa-laravel`    | 2.3.1 → 3.0.1      | **MAJEURE** | gelée |
| #153 | npm /workers   | `typescript` (dev)              | 5.9.3 → 6.0.3      | **MAJEURE** | gelée |
| #152 | composer       | `spatie/laravel-permission`     | 6.25.0 → 8.3.0     | **MAJEURE** (×2) | gelée |
| #151 | npm /workers   | `tsx` (dev)                     | 4.22.1 → 4.23.12   | mineure   | gelée |
| #150 | npm /frontend  | `@axe-core/playwright` (dev)    | 4.11.3 → 4.13.0    | mineure   | gelée |
| #149 | composer       | `laravel/tinker`                | 2.11.1 → 3.0.2     | **MAJEURE** | gelée |
| #148 | npm /workers   | `axios`                         | 1.16.1 → 1.19.0    | mineure   | gelée |
| #147 | composer       | `spatie/laravel-query-builder`  | 6.4.4 → 7.3.3      | **MAJEURE** | gelée |
| #146 | github-actions | `docker/build-push-action`      | 6 → 7              | **MAJEURE** | gelée |
| #145 | github-actions | `actions/checkout`              | 4 → 7              | **MAJEURE** (×3) | gelée |

⚠️ Six PR franchissent **plus d'une** majeure d'un coup (#164, #161, #158, #152,
#145 et, en pratique, #155 depuis une 0.x). Ce ne sont pas des montées : ce sont
des chantiers de migration. Elles ne doivent en aucun cas être traitées « à la
chaîne » au dégel.

⚠️ #152 et #147 sont exactement les deux paquets qui avaient été **bundlés
ensemble** par l'ancien groupe `laravel-ecosystem` (PR #95, historique du
2026-08-16) : `laravel-permission` gouverne toutes les habilitations, et
`laravel-query-builder` 6 → 7 rend `allowedFilters()` / `allowedSorts()` /
`allowedIncludes()` variadiques (une douzaine de contrôleurs à réécrire).

---

## 4. Configuration : avant → après

### 4.1 Avant (verbatim, corps du fichier)

```yaml
version: 2
updates:
  - package-ecosystem: composer
    directory: /backend
    schedule: { interval: weekly, day: monday, time: '06:00' }
    open-pull-requests-limit: 8
    groups:
      laravel-ecosystem:
        patterns: ['laravel/*', 'spatie/*', 'pragmarx/*']
        update-types: ['minor', 'patch']
      security-patches:
        applies-to: security-updates
        patterns: ['*']

  - package-ecosystem: npm
    directory: /frontend
    schedule: { interval: weekly, day: monday, time: '06:00' }
    open-pull-requests-limit: 8
    groups:
      tanstack:
        patterns: ['@tanstack/*']
        update-types: ['minor', 'patch']
      react:
        patterns: ['react', 'react-dom', '@types/react*']
        update-types: ['minor', 'patch']

  - package-ecosystem: npm
    directory: /workers
    schedule: { interval: weekly, day: monday, time: '06:00' }
    open-pull-requests-limit: 5

  - package-ecosystem: github-actions
    directory: /
    schedule: { interval: weekly, day: monday, time: '06:00' }

  - package-ecosystem: docker
    directory: /
    schedule: { interval: weekly, day: monday, time: '06:00' }
    ignore:
      - dependency-name: 'postgis/postgis'
        update-types: ['version-update:semver-major']
```

**Trois `groups:` étaient présents** — `laravel-ecosystem`, `tanstack`, `react`
— plus un groupe `security-patches` en `applies-to: security-updates`.
Regrouper des montées entre elles est **exactement** le geste qui a produit
l'incident du site : un lot groupé, ce sont plusieurs écritures de lockfile dans
une seule PR, avec un seul signal CI et un seul revert pour l'ensemble.

### 4.2 Après (corps du fichier, hors en-tête de commentaires)

```yaml
version: 2
updates:
  - package-ecosystem: composer
    directory: /backend
    schedule: { interval: weekly, day: monday, time: '06:00' }
    open-pull-requests-limit: 8
    ignore:
      - dependency-name: '*'
        update-types:
          - 'version-update:semver-major'
          - 'version-update:semver-minor'
          - 'version-update:semver-patch'

  - package-ecosystem: npm
    directory: /frontend
    schedule: { interval: weekly, day: monday, time: '06:00' }
    open-pull-requests-limit: 8
    ignore:
      - dependency-name: '*'
        update-types:
          - 'version-update:semver-major'
          - 'version-update:semver-minor'
          - 'version-update:semver-patch'

  - package-ecosystem: npm
    directory: /workers
    schedule: { interval: weekly, day: monday, time: '06:00' }
    open-pull-requests-limit: 5
    ignore:
      - dependency-name: '*'
        update-types:
          - 'version-update:semver-major'
          - 'version-update:semver-minor'
          - 'version-update:semver-patch'

  - package-ecosystem: github-actions
    directory: /
    schedule: { interval: weekly, day: monday, time: '06:00' }
    ignore:
      - dependency-name: '*'
        update-types:
          - 'version-update:semver-major'
          - 'version-update:semver-minor'
          - 'version-update:semver-patch'

  - package-ecosystem: docker
    directory: /
    schedule: { interval: weekly, day: monday, time: '06:00' }
    ignore:
      - dependency-name: '*'
        update-types:
          - 'version-update:semver-major'
          - 'version-update:semver-minor'
          - 'version-update:semver-patch'
      # RÈGLE PERMANENTE — à NE PAS retirer au dégel (pg_upgrade, PR #14).
      - dependency-name: 'postgis/postgis'
        update-types: ['version-update:semver-major']
```

Différences : **zéro `groups:`**, un bloc `ignore` global par écosystème, les
plafonds `open-pull-requests-limit` **inchangés et non nuls**, la règle
permanente `postgis` conservée.

---

## 5. Préservation des mises à jour de sécurité — ce qui a été VÉRIFIÉ

C'est la seule subtilité de cette tâche : se tromper laisserait le dépôt sans
correctif de faille. Deux mécanismes permettaient de suspendre les montées.

### 5.1 Option (a) — `open-pull-requests-limit: 0` : ÉCARTÉE

La documentation GitHub affirme que ce réglage n'affecte **pas** les mises à
jour de sécurité :

> « Security update pull requests are not subject to this limit and do not count
> toward it. »
> — [Dependabot options reference](https://docs.github.com/en/code-security/dependabot/working-with-dependabot/dependabot-options-reference)

et, pour l'usage exact visé :

> « If you only require security updates and want to exclude version updates,
> you can set `open-pull-requests-limit` to `0` … this option has no impact on
> security updates, which have a separate, internal limit of ten open pull
> requests. »

**Malgré cela, cette option est écartée.** Motif : ce dépôt possède une
**expérience contraire de première main**, consignée dans l'en-tête de
`.github/dependabot.yml` depuis le 2026-08-16 — un `open-pull-requests-limit`
**saturé** avait coupé le canal de sécurité **en silence** (5/5 frontend, 3/3
workers ; Dependabot ne proposait plus rien, correctifs de sécurité compris).
Le mécanisme du plafond a donc déjà produit ce dégât précis ici. On ne lui
confie pas une seconde fois la garde du canal de sécurité, quelle que soit la
doc.

### 5.2 Option (b) — `ignore` + `update-types: version-update:semver-*` : RETENUE

La réserve est **explicite et sans ambiguïté** :

> « Note: this feature only applies to **version updates**. If you have security
> updates enabled, you will **still get pull requests** updating you to the
> minimum patched version. »
> — [GitHub Changelog, 2021-05-21 — « Dependabot version updates can now ignore major/minor/patch releases »](https://github.blog/changelog/2021-05-21-dependabot-version-updates-can-now-ignore-major-minor-patch-releases/)

Confirmé par la référence des options, qui cadre `update-types` comme
`version-update:semver-patch|minor|major` — c'est-à-dire des **version updates**
uniquement.

⚠️ **La réserve ne vaut QUE pour les conditions exprimées en `update-types`.**
Une condition `ignore` exprimée en **`versions:`** (plage de versions)
s'applique **aussi** aux mises à jour de sécurité. La configuration posée
n'utilise **aucune** condition `versions:` — vérifié par script (colonne
`ignore_par_versions=False` sur les 5 écosystèmes). Ne jamais convertir ces
règles en plages de versions.

### 5.3 🔴 Prérequis NON REMPLI — les alertes Dependabot sont DÉSACTIVÉES

Mesuré le 2026-08-18, deux relevés indépendants et concordants :

```
$ gh api repos/will383842/axion-crm-pro/vulnerability-alerts -i
HTTP/2.0 404 Not Found                       # 404 = alertes DÉSACTIVÉES

$ gh api repos/will383842/axion-crm-pro -q '.security_and_analysis'
{"dependabot_security_updates":{"status":"disabled"}, ...}

$ gh api repos/will383842/axion-crm-pro/dependabot/alerts
403 — "Dependabot alerts are disabled for this repository."
```

**Conséquence** : à ce jour, il n'existe **aucun canal de mise à jour de
sécurité** sur ce dépôt. La précaution des §5.1/5.2 est correcte, mais **inerte**
tant que les alertes ne sont pas activées. Autrement dit : le gel ne retire rien
au dépôt, parce que le dépôt n'avait déjà rien — et c'est un défaut à corriger,
pas un soulagement. Le dépôt est **public** ; `secret_scanning` et
`secret_scanning_push_protection` sont, eux, actifs.

→ Action Will au §8.

#### 🔴 RECTIFICATION 2026-08-19 — le §5.3 ci-dessus est périmé (constat H47-003)

La mesure du 2026-08-18 n'est pas retirée : elle était juste ce jour-là, et c'est
elle qui explique la rédaction du gel. Elle ne décrit simplement plus le dépôt.

Nouvelle mesure, le **2026-08-19** — trois relevés concordants, conservés dans
`04_PREUVES/agent-47/etat-dependabot-depot.txt` de l'audit 360 :

```
$ gh api repos/will383842/axion-crm-pro/vulnerability-alerts -i
HTTP/2.0 204 No Content                      # 204 = alertes ACTIVES (était 404)

$ gh api repos/will383842/axion-crm-pro -q '.security_and_analysis'
{"dependabot_security_updates":{"status":"enabled"}, ...}   # était "disabled"

$ gh api repos/will383842/axion-crm-pro/automated-security-fixes
{"enabled":true,"paused":false}
```

**Témoin négatif** : la même réponse rend `"disabled"` pour
`secret_scanning_non_provider_patterns` et `secret_scanning_validity_checks`.
L'API ne répond donc pas `enabled` par défaut — le vert ci-dessus a une valeur.
**Corroboration indépendante** : les 57 alertes du dépôt portent toutes
`created_at` au 2026-08-19.

**Ce que cela change** :

- la précaution des §5.1/5.2 n'est **plus inerte** — il existe désormais un canal
  de mise à jour de sécurité, et le gel doit le préserver pour de bon ;
- le gel étant total sur les montées de version, ce canal est désormais la
  **seule voie de correction restante** ;
- le critère d'entrée du dégel « les alertes Dependabot sont ACTIVES » est
  **déjà rempli** ; il n'y a plus rien à demander à Will sur ce point.

⚠️ **Ce qui reste non mesuré, et n'est donc pas affirmé** : que ce canal
*produise* des PR. Au 2026-08-20, ~24 h après la création des 57 alertes,
`gh pr list --search "author:app/dependabot"` en rendait **zéro**, plafonds non
saturés. Un jour d'observation ne suffit pas à déclarer le canal rompu — à
**re-mesurer** après l'exécution planifiée du lundi 06:00 UTC. Si le compte est
toujours nul alors qu'une correction existe dans un intervalle déjà déclaré,
c'est un constat **S1** : le gel aurait coupé le canal de sécurité en silence.

---

## 6. Ce que Dependabot fera de lui-même — et ce qu'il ne fera pas

**Certain** (documenté) :

- ✅ Dependabot **cessera de créer** de nouvelles PR de montée de version, sur
  les cinq écosystèmes, dès que la configuration atterrit sur `main`.
- ✅ Il **cessera de rebaser / recréer** les PR gelées : une PR de montée fermée
  après l'atterrissage de cette configuration **ne reviendra pas**.
- ✅ Les PR de **sécurité** resteront possibles (une fois les alertes activées),
  hors groupe, une par faille.

**Non établi — je ne l'affirme pas** :

- ❓ Je n'ai **pas** trouvé d'énoncé officiel disant qu'une règle `ignore` posée
  dans `dependabot.yml` **ferme automatiquement** les PR déjà ouvertes qu'elle
  couvre. La fermeture automatique est documentée pour la **commande en
  commentaire** `@dependabot ignore this dependency`, pas pour une règle de
  fichier. **Il faut donc partir du principe que les 20 PR resteront ouvertes**
  jusqu'à ce qu'on les ferme.

**Piège à éviter** :

- ⛔ **Ne pas** utiliser `@dependabot ignore this dependency` /
  `@dependabot ignore this major version` pour vider la file. Ces commandes
  créent une règle d'exclusion **persistante et stockée hors du dépôt** :

  > « Closes the pull request and prevents Dependabot from creating any more
  > pull requests for this dependency (unless you reopen the pull request or
  > upgrade to the suggested version yourself). »
  > — [Dependabot pull request comment commands](https://docs.github.com/en/code-security/reference/supply-chain-security/dependabot-pull-request-comment-commands)

  Elle **survivrait au dégel** et serait invisible dans `dependabot.yml` : on se
  retrouverait avec un paquet définitivement muet sans qu'aucun fichier ne le
  dise. 🔑 *Une valeur qu'aucun fichier ne peut confirmer finit par surprendre
  quelqu'un d'honnête.* Fermer les PR **à la main**, simplement.

**Ordre des opérations, il compte** :

1. **D'abord** fusionner cette configuration sur `main`.
2. **Ensuite seulement** fermer les 20 PR.

Dans l'ordre inverse, Dependabot les rouvrirait au prochain passage (lundi
06:00) puisque rien ne les ignorerait encore.

---

## 7. Procédure de dégel

**Critère d'entrée — les trois, cumulativement** :

- l'étape 0 est déclarée terminée : harnais de tests posé et **vert sur `main`
  deux exécutions consécutives**, hors PR de dépendances ;
- les **alertes Dependabot sont actives** sur le dépôt (§5.3) ;
- `pnpm install --frozen-lockfile` (frontend **et** workers) et
  `composer validate --strict` (backend) passent sur `main`.

**Étapes — une par une, jamais de retrait en bloc** :

1. Retirer le bloc `ignore` de **un seul** écosystème. Commencer par
   **`github-actions`** : aucun lockfile, donc aucun risque de clé dupliquée.
   C'est l'écosystème d'échauffement.
2. Traiter les PR **une par une** : fusionner, **attendre que `main` soit vert**,
   puis seulement passer à la suivante. ⛔ Jamais deux PR de dépendances
   fusionnées dans la même fenêtre sans un `main` vert entre les deux.
3. Écosystèmes suivants, par risque croissant : `docker` (pas de lockfile) →
   `npm /workers` → `npm /frontend` → `composer /backend`.
4. 🔴 Pour tout écosystème à lockfile, avant **chaque** fusion : vérifier
   l'**unicité des clés** du lockfile **résultant de la fusion**. `git
   merge-tree` ne valide pas un lockfile ; comparer les specifiers ne suffit
   pas. C'est le contrôle qui manquait le 2026-08-18.
5. Les **16 majeures** listées au §3.2 restent **hors dégel automatique** :
   chacune est un chantier avec sa propre PR, son plan de test et son revert.
   Les six qui franchissent plusieurs majeures d'un coup sont à re-scoper en
   paliers.
6. **Ne pas réintroduire de `groups:`.** Si un besoin de regroupement
   réapparaît, il faut d'abord un contrôle de lockfile fusionné qui **rougit**
   pour de vrai — sinon le groupe rétablit exactement la panne de production.
7. Le gel est un **outil réutilisable** : remettre les blocs `ignore` au chantier
   suivant est normal, ce n'est pas un aveu d'échec.

---

## 8. Ce que Will doit faire à la main

| # | Action | Pourquoi |
| - | ------ | -------- |
| 1 | **Fusionner d'abord** la PR qui porte cette configuration. | Sans elle, toute fermeture de PR est annulée au prochain passage Dependabot (lundi 06:00). |
| 2 | 🔴 **Activer les alertes Dependabot** : Settings → Advanced Security → *Dependabot alerts* **et** *Dependabot security updates*. | Mesuré désactivées (§5.3). Sans ça, le dépôt — **public** — n'a aucun canal de correctif de faille, et la protection écrite ici reste inerte. |
| 3 | **Fermer les 20 PR** (#145 → #164), **à la main**, sans commande `@dependabot ignore`. | Aucun agent n'a le droit de fermer une PR ; et Dependabot ne les fermera peut-être pas seul (§6). Les laisser ouvertes est toléré mais rebouche la file. |
| 4 | Décider si la file est laissée ouverte **ou** vidée. | Les laisser ouvertes n'empêche **pas** les PR de sécurité (elles ne comptent pas dans `open-pull-requests-limit`) mais brouille la lecture de l'état du dépôt pendant toute l'étape 0. Recommandation : **vider**. |
| 5 | Au dégel, dérouler le §7 **dans l'ordre**, sans sauter le contrôle d'unicité des clés de lockfile. | C'est le contrôle absent le 2026-08-18. |

---

## 9. Vérifications réellement effectuées sur ce livrable

| Vérification | Méthode | Résultat |
| ------------ | ------- | -------- |
| Nombre de PR ouvertes | `gh pr list --limit 200 --state open` + `--jq length` | **20**, toutes Dependabot, toutes du 2026-08-17 |
| Aucune PR de sécurité dans le lot | labels de chaque PR (`dependencies` + langage seulement) | confirmé, aucun label `security` |
| YAML syntaxiquement valide | `yaml.safe_load` (PyYAML) | parse sans erreur |
| Conforme au schéma Dependabot | `jsonschema` 4.26 (Draft7) contre `https://json.schemastore.org/dependabot-2.0.json` (téléchargé le 2026-08-18, 51 250 octets) | **0 erreur** |
| Le validateur n'est pas aveugle (**témoin négatif**) | deux configs volontairement fausses injectées : `version-update:semver-typo`, `package-ecosystem: npmm` | **le validateur ROUGIT sur les deux** → le vert ci-dessus a une valeur |
| Zéro `groups:` restant | inspection programmatique de l'arbre YAML | **aucun** écosystème n'en porte |
| Zéro condition `ignore` par plage `versions:` | inspection programmatique | **aucune** sur les 5 écosystèmes |
| Sécurité préservée par `ignore`/`update-types` | doc GitHub + changelog 2021-05-21, cités verbatim au §5.2 | établi |
| État des alertes Dependabot du dépôt | `gh api .../vulnerability-alerts` (404) + `.security_and_analysis` (`disabled`) | **désactivées** au 2026-08-18 — §5.3. 🔴 **PÉRIMÉ** : re-mesuré **actives** le 2026-08-19, cf. la RECTIFICATION du §5.3 (constat H47-003) |

**Ce qui n'a PAS été vérifié**, et n'est donc pas affirmé :

- Que Dependabot **fermera** de lui-même les 20 PR déjà ouvertes une fois la
  règle `ignore` en place (§6). Aucune source officielle trouvée dans un sens ni
  dans l'autre.
- Le comportement de la règle semver face à une montée **non comparable en
  semver** (par ex. un pin par digest côté `docker`) : `update-types` s'appuie
  sur une comparaison semver, une telle montée pourrait théoriquement y échapper.
  Aucune n'est ouverte aujourd'hui ; si une apparaît, la fermer à la main.
- La configuration n'a **pas** été exécutée par Dependabot : elle est validée
  contre le schéma, pas contre le service.
