# Load Test Runbook — Axion CRM Pro

Sprint Pipeline 360° Hardening (H5) — 2026-05-17
Réécrit le 2026-08-22 (constat **G43-007**).

## ⚠️ État réel de ce banc — à lire avant tout le reste

**Ce banc de charge n'a jamais tourné.** Mesure du 2026-08-22 :

- `load-tests/` ne contient que deux fichiers (`audience-refresh.yml`, ce runbook) ;
  aucun dossier `results/`, donc aucun résultat n'a jamais été enregistré ;
- `artillery` n'apparaît dans **aucun** `package.json` et dans **aucun** workflow
  GitHub : rien ne l'installe, rien ne le lance, aucune CI ne le mesure.

Les baselines ci-dessous sont donc des **cibles écrites**, pas des mesures. Ne
citez jamais ce document comme preuve que la charge est tenue.

**Et surtout — ce que ce runbook prescrivait jusqu'au 2026-08-22 :** se connecter
à la **production** avec l'adresse e-mail personnelle du dirigeant et son mot de
passe collé en clair dans un `curl`, puis y envoyer 6 000 requêtes en 6 minutes.
Un mode d'emploi dont l'exécution littérale charge la production avec le compte
le plus privilégié du produit est un incident en attente d'un lecteur
consciencieux. Ce qui suit vise un environnement **dédié**, avec un **compte de
service jetable**.

## Objectif

Vérifier que l'API tient la charge à 1M companies / mois cible (~33K / jour, ~1400 / h).
Pas seulement le throughput brut : valider les SLA p95/p99 sur les 3 endpoints
les plus appelés depuis le frontend (list companies, audience preview, tags list).

## Outil

[Artillery](https://www.artillery.io/) — déjà standard pour load testing API REST.

```bash
npm install -g artillery
# ou ponctuel sans install
npx artillery run load-tests/audience-refresh.yml
```

## Où ce banc a le droit de taper

| Cible | Autorisé ? |
|---|---|
| `http://localhost` (docker compose local) | ✅ |
| Environnement de charge dédié, base jetable | ✅ |
| Staging partagé | ⚠️ prévenir avant, jamais en journée |
| **`app.axion-crm-pro.com` (production)** | ❌ **JAMAIS** |

La production porte les données réelles des clients et une seule base Postgres.
Une phase « sustained » à 20 req/s pendant 5 min n'y est pas un test : c'est un
incident de disponibilité qu'on s'inflige, et les lignes créées par le scénario
restent dans les données de production.

## Baselines attendues (cible : environnement de charge dimensionné comme la prod)

| Scenario | p50 | p95 | p99 | Note |
|---|---|---|---|---|
| List companies dept+size (100 results) | ≤ 200ms | ≤ 800ms | ≤ 2000ms | Index `(workspace_id, department_code, size_category)` essentiel |
| Preview audience criteria DSL | ≤ 100ms | ≤ 300ms | ≤ 700ms | COUNT(*) via DSL — coûts variables selon criteria |
| List tags grouped | ≤ 50ms | ≤ 150ms | ≤ 400ms | Petite table, full scan acceptable |
| **Error rate global** | < 0.5% | < 1% | — | Tolérance courte (Redis pump-up, FCM RST) |

Phases du scenario YAML :
- **Warmup** : 60s à 5 req/s (constitue cache PHP-FPM + Postgres planner)
- **Sustained** : 300s à 20 req/s = 6000 req → ~80% du throughput théorique CPX42

## Workflow recommandé

### 1. Préparation (1×, à l'init)

**a. Un compte de service jetable, créé SUR l'environnement de charge.** Jamais
un compte humain, jamais un compte qui existe aussi en production :

```bash
# sur l'environnement de charge — ni en local, ni en prod
# créer p. ex. load-test@axion-ia.invalid, rôle minimal, workspace de test
```

**b. Le mot de passe ne s'écrit ni dans ce fichier, ni dans un `curl` qui
restera dans l'historique du shell.** On le passe par l'environnement :

```bash
read -rs LOAD_TEST_PASSWORD && export LOAD_TEST_PASSWORD   # saisie masquée
export LOAD_TEST_EMAIL='load-test@axion-ia.invalid'
export LOAD_TEST_TARGET='https://<votre-env-de-charge>'    # OBLIGATOIRE
```

**c. Refus explicite de la production.** À coller tel quel avant chaque
campagne — c'est la seule chose de ce document qui empêche vraiment l'accident.
Le domaine y est nommé sans schéma (`app.axion-crm-pro.com`, pas d'`https://`)
pour qu'aucun copier-coller de ce fichier ne produise une URL de production
directement exécutable :

```bash
if [ -z "${LOAD_TEST_TARGET:-}" ]; then
  echo "REFUS : LOAD_TEST_TARGET n'est pas défini. Ce banc ne devine pas sa cible." >&2
  exit 1
fi
case "$LOAD_TEST_TARGET" in
  *app.axion-crm-pro.com*)
    echo "REFUS : LOAD_TEST_TARGET vise la PRODUCTION. Utiliser un environnement dédié." >&2
    exit 1 ;;
esac
```

**d. Le jeton, obtenu sur la cible ainsi validée :**

```bash
API_TOKEN=$(curl -sS -X POST "$LOAD_TEST_TARGET/api/v1/auth/login" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"$LOAD_TEST_EMAIL\",\"password\":\"$LOAD_TEST_PASSWORD\"}" \
  | jq -r .data.token)
export API_TOKEN
```

### 2. Run (à chaque sprint avant merge)

```bash
npx artillery run load-tests/audience-refresh.yml
```

Output Artillery affichera p50/p95/p99 + error rate.

### 3. Analyse

Si **p95 > 800ms** sur list companies → vérifier index PG :
```sql
SELECT * FROM pg_indexes WHERE tablename = 'companies' ORDER BY indexname;
EXPLAIN ANALYZE
  SELECT * FROM companies
  WHERE workspace_id = '...' AND department_code = '75' AND size_category = 'pme'
  LIMIT 100;
```

Si **error rate > 1%** → check `tail -f storage/logs/laravel.log` + Sentry pendant le run.

## Antipattern à éviter

❌ Ne PAS lancer Artillery contre la production — ni le jour, ni la nuit, ni
   « juste la phase de warmup ». Le tableau « Où ce banc a le droit de taper »
   n'a pas d'exception horaire : c'est un environnement dédié, ou rien.

❌ Ne PAS utiliser un compte humain (a fortiori celui du dirigeant) pour obtenir
   le jeton : ce jeton porte tous les droits, et l'adresse finit dans les
   journaux de charge.

❌ Ne PAS scaler au-delà de 50 req/s sans en parler à Will :
   - CPX42 = 8 vCPU partagés (steal possible chez Hetzner)
   - Une charge soutenue à 50 req/s = saturation FPM par défaut (10 workers)

✅ Pour test "burst" courts (10s à 100 req/s) : duplicate ce yaml en `audience-burst.yml`
   avec phase `maxVusers: 100, duration: 10` — à valider avec Will avant run.

## Quoi enregistrer après chaque run

Dans `load-tests/results/` (à créer si absent, gitignored sauf le runbook) :
- output Artillery brut (`artillery report` HTML)
- date + commit SHA + scénario
- **l'URL de la cible** — sans elle, un résultat ne veut rien dire
- p95 / p99 / error rate observés
- baseline KO / OK

Cette doc + le yml sont les seuls fichiers commités — les résultats sont locaux.

## Si ce banc n'est toujours pas joué au prochain audit

Deux issues, et c'est une décision de Will, pas un correctif : soit on l'outille
pour de bon (installation d'Artillery + environnement de charge + un job qui
enregistre ses résultats), soit on supprime `load-tests/`. Le garder tel quel
entretient la croyance qu'un banc de charge existe.

La garde `backend/tests/Feature/Infra/BancDeChargeNeViseJamaisLaProductionTest.php`
tient l'entre-deux : elle n'oblige personne à jouer le banc, mais elle interdit
que ce mode d'emploi vise de nouveau la production ou nomme un compte humain.
