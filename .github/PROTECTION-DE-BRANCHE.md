# Protection de la branche `main` — ce que l'exploitant doit poser à la main

> **Constat F38-002 (S1)** — *la protection de branche n'exige que 4 contextes sur 36 jobs,
> n'inclut aucune des trois gardes nées d'un incident, et `enforce_admins: false` la rend
> inapplicable au seul contributeur.*

**Ce fichier n'est pas de la documentation : c'est la seule forme sous laquelle ce défaut peut
vivre dans le dépôt.** La protection de branche est un réglage de l'API GitHub. Aucun fichier
versionné ne la décrit, aucun test ne peut la corriger, et un `ruleset` versionné n'existe pas
ici (`rulesets` mesuré vide le 2026-08-19). Une session d'agent n'a **pas** le droit de la
modifier : c'est un geste d'exploitant, sur un compte, avec un jeton.

Ce que le dépôt *peut* garantir, et garantit : que les noms de contexte écrits ci-dessous
correspondent à des jobs qui existent réellement. C'est l'objet de la garde
`backend/tests/Feature/Infra/GardeDesPortsBrancheeSurLaProductionTest.php`, test
« F38-002 — le mode d emploi de la protection de branche … aucun contexte fantôme ».

---

## 1. L'état mesuré le 2026-08-19

`GET /repos/will383842/axion-crm-pro/branches/main/protection` rendait :

| Réglage | Valeur mesurée |
|---|---|
| `required_status_checks.contexts` | **4** : `Backend Laravel (…)`, `Frontend React/Vite`, `Workers Node + Playwright`, `Secrets scan (Gitleaks)` |
| `enforce_admins` | `false` |
| `rulesets` | vide |
| `environments[*].protection_rules` | `[]` sur les deux environnements |

**Ce n'est pas théorique.** La PR #186 a été fusionnée le 2026-08-19 à `09:15:13`. Son
`Container scan (Trivy) (worker, Dockerfile.worker)` était en **échec**, et son run — démarré à
`09:13:28`, durée 3 min 50 s — n'a rendu son verdict que vers `09:17`. **La PR a fusionné avant
même que la garde ne parle.**

Conséquence : les gardes nées des deux incidents les plus graves du produit — `config-prod`
(exposition de Postgres et Redis, 4 295 349 fiches) et la surveillance des sauvegardes (91
sauvegardes ratées) — peuvent rougir **sans empêcher la fusion**. Elles ne bloquent que le
*déploiement*. Un code fautif atterrit donc sur `main`, y reste, et **bloque toute mise en
production suivante, y compris un correctif d'urgence sans rapport**.

---

## 2. ⚠️ L'ordre des gestes, et pourquoi il n'est pas négociable

**Un contexte requis qui n'arrive jamais bloque `main` pour toujours.** GitHub attend
indéfiniment. Le seul moyen d'en sortir est de désactiver la protection — c'est-à-dire de faire
exactement l'inverse de ce que ce constat demande. Deux façons de tomber dedans :

1. **Un nom qui ne correspond à aucun job.** Le contexte est le `name:` du job, **pas** son
   identifiant : `config-prod` est l'identifiant, le contexte est
   `La config de production ne publie que 80 et 443`. *La garde du dépôt couvre ce cas.*
2. **Un job qui ne se déclenche pas sur les PR**, ou qu'un filtre `paths:` peut sauter.
   *Vérifié le 2026-08-20 : `ci.yml`, `security.yml`, `a11y.yml` et `e2e.yml` se déclenchent
   tous sur `pull_request: branches: [main]` **sans aucun filtre `paths:`**. Les quatre
   rapportent donc sur chaque PR.*

Et un troisième piège, que la garde ne peut pas couvrir :

3. **Exiger un contexte actuellement ROUGE fige le dépôt.** C'est pour cette raison que les
   scans Trivy sont explicitement hors de la liste ci-dessous.

**Donc, dans cet ordre :**

```
1. Ouvrir la dernière PR fusionnée (ou une PR blanche) et RELEVER la couleur de chaque
   contexte candidat.        gh pr checks <numéro>
2. N'ajouter que les VERTS.
3. Réparer les rouges, puis les ajouter.
```

---

## 3. Les contextes à exiger

Liste lue par la garde du dépôt. Chaque nom doit correspondre au `name:` d'un job réel.

<!-- CONTEXTES-REQUIS:DEBUT -->
- `Backend Laravel (install + PHPStan + Pint + Pest)`
- `Frontend React/Vite`
- `Workers Node + Playwright`
- `Secrets scan (Gitleaks)`
- `La config de production ne publie que 80 et 443`
- `Le Caddyfile est-il valide ?`
- `Les scripts d'infra sont-ils exécutables ?`
- `axe-core Playwright`
<!-- CONTEXTES-REQUIS:FIN -->

Les quatre premiers sont **déjà** requis : ils figurent ici pour que la liste soit l'état
**cible complet**, et non un delta qu'on appliquerait par-dessus un état qu'on n'a pas relu.
`required_status_checks.contexts` est **remplacé**, pas fusionné — envoyer les quatre nouveaux
seuls **retirerait** les quatre anciens.

### La commande

```bash
gh api -X PUT repos/will383842/axion-crm-pro/branches/main/protection \
  --input - <<'JSON'
{
  "required_status_checks": {
    "strict": true,
    "contexts": [
      "Backend Laravel (install + PHPStan + Pint + Pest)",
      "Frontend React/Vite",
      "Workers Node + Playwright",
      "Secrets scan (Gitleaks)",
      "La config de production ne publie que 80 et 443",
      "Le Caddyfile est-il valide ?",
      "Les scripts d'infra sont-ils exécutables ?",
      "axe-core Playwright"
    ]
  },
  "enforce_admins": false,
  "required_pull_request_reviews": null,
  "restrictions": null
}
JSON
```

Puis **relire**, parce qu'un `PUT` accepté n'est pas un `PUT` appliqué :

```bash
gh api repos/will383842/axion-crm-pro/branches/main/protection \
  --jq '.required_status_checks.contexts'
```

Le compte attendu est **8**. Si la relecture en rend 4, le `PUT` a été refusé silencieusement
(jeton sans le droit `administration:write`) : c'est le cas à ne pas confondre avec un succès.

---

## 4. Ce qui reste à trancher — décisions de Will, pas d'un agent

### `enforce_admins`

Le passer à `true` rendrait la protection applicable au seul contributeur du dépôt. **Cela lui
retirerait aussi la possibilité de forcer un correctif d'urgence.** Sur un produit dont la mise
en production dépend d'un unique pipeline, c'est un arbitrage réel : à trancher, pas à imposer.

### Trois contextes candidats, volontairement hors de la liste du §3

| Contexte | Pourquoi il n'y est pas |
|---|---|
| `Suites e2e sur le build (BLOQUANT)` | **Son propre fichier écrit : « Ce job est BLOQUANT, et il doit le rester ».** C'est faux au niveau de la protection de branche : il peut rougir sans empêcher une fusion. Il se déclenche bien sur `pull_request` sans filtre. **Il devrait être requis** — sa couleur actuelle n'a simplement pas été mesurée par cette session. |
| `SAST (Semgrep)` | Même raison : non mesuré. |
| `Container scan (Trivy)` (3 jobs de matrice) | ⚠️ **Ne pas ajouter tant que le déploiement de préproduction est rouge.** Deux gardes bloquantes rouges simultanément figeraient le dépôt. Et un job de matrice rapporte `Container scan (Trivy) (api, Dockerfile.laravel)` — le nom de base seul ne correspond à aucun contexte. |

### Les environnements

`production-direct-ssh` et `staging-direct-ssh` ont tous deux `protection_rules: []`. Une règle
de relecture obligatoire sur `production-direct-ssh` mettrait un humain entre un `push` sur
`main` et la production. **Non demandé par F38-002 ; noté ici parce que la mesure l'a vu.**

---

## 5. Comment savoir que ce fichier est encore vrai

Il ne décrit pas un état, il décrit une **cible**. Le seul point qu'une machine vérifie est que
les noms du §3 ne sont pas des fantômes. Le reste — les 4 contextes réellement posés,
`enforce_admins`, les `protection_rules` — n'est lisible que par l'API. **Aucun test du dépôt ne
peut rougir si quelqu'un retire les huit contextes demain.** C'est la nature du défaut, et c'est
pourquoi il se relit à la main :

```bash
gh api repos/will383842/axion-crm-pro/branches/main/protection --jq \
  '{contextes: .required_status_checks.contexts, admins: .enforce_admins.enabled}'
```
