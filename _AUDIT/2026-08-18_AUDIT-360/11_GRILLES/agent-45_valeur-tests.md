# AGENT 45 — La VALEUR des tests

> Ce que l'agent 44 mesure : ce qui s'exécute. Ce que je mesure : ce que ça vaut.
> Sorties brutes : `_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/agent-45/`.
>
> **Méthode, sans exception** : pour chaque garde retenue, je casse délibérément le code
> qu'elle prétend garder, je joue **la suite ENTIÈRE** (780 tests) pour compter le rayon
> d'explosion, j'archive la sortie, je restaure, et je prouve la restauration.

---

## 0. Référence réellement mesurée, et atelier

Le dossier commun donne `main = c0c453d`. **Relu moi-même** (règle §1) :

```
$ git rev-parse HEAD
e8924b81ad64c0b236acd99ac5cbac4cd68eada7
$ git log --oneline -3
e8924b8 fix(rgpd+acces): rectification du registre + acces CRM rendu (#189)
9d273cd fix(rgpd+acces): le registre affirmait une chose FAUSSE en sa faveur — rectifié le jour même
1145473 docs(rgpd): registre des violations, notification non retenue (#188)
```

**Tous mes constats sont référencés à `main = e8924b8`**, mesuré, 6 commits devant le SHA du dossier.

### L'atelier que j'ai monté, et pourquoi

Deux mesures m'ont forcé à ne PAS travailler dans le conteneur commun :

1. **Ma première ligne de base a été rouge — et c'était un voisin, pas un défaut.**
   `tests/Feature/Crm/ActivitesEtMotifsTest.php` joué dans `axion-crm-api` : **6 échecs sur 15**,
   dont `SQLSTATE[42P01] relation "crm_motifs" does not exist`, un test à **326,86 s**.
   `ps aux` dans le conteneur : **12 processus `pest` / `artisan test` / `migrate:fresh`
   concurrents**, appartenant à d'autres agents. `pg_stat_activity` : une base `axion_crm_agent14`,
   un `COPY` de 16 min sur `axion_crm_dr_a08`. Rejoué en isolation : **0 échec**.
   → **le rouge était de la contention**, pas un défaut du produit (preuve `00_baseline_activites.txt`).
2. **Le montage bind Windows coûte un facteur ~115.** `tests/Unit/SmokeTest.php` (2 tests triviaux) :
   **234,81 s** avec le code monté depuis `C:\` (la recette documentée `infra/scripts/worktree/pest-worktree.sh`),
   **2,14 s** avec le même code **copié dans le conteneur**. Même image, même Postgres.

J'ai donc monté : un **worktree jetable détaché** sur `e8924b8`, une base **`axion_crm_a45`**
(+ `axion_crm_a45b` pour la démonstration de purge), et un conteneur **`a45`** dont l'arbre est
**copié** (pas monté), avec un instantané intact en `/var/www/html.orig` qui sert de référence de
restauration. `axion_crm`, `axion_crm_test` et le worktree `crmpro-wt-etape1a` n'ont **jamais** été
touchés.

### Ligne de base, mesurée trois fois

| Où | Tests | Résultat | Durée |
|---|---|---|---|
| CI GitHub, run `32240894728` sur `9d273cd` | 780 | **780 passés** | **30,18 s** |
| Mon conteneur `a45` (code copié, base `axion_crm_a45`) | 780 | 778 passés, **1 échec**, 1 ignoré | **434,15 s** |
| Conteneur commun `axion-crm-api`, un seul fichier | 15 | **6 échecs** (contention) | 960,59 s |

L'échec local est **`CoverageControllerTest > POST /coverage/launch accepte body valide`** : il
n'existe pas en CI. Cause mesurée : `.env` local porte `MOCK_INSEE=false`, la CI pose
`MOCK_INSEE: 'true'` — et **ni `phpunit.xml` ni `phpunit-ci.xml` n'épinglent ces drapeaux**
(→ **H45-005**). Le test ignoré est `ACQUIS 2` (pg_dump absent du conteneur) ; **il tourne bien en
CI**, vérifié dans le journal du run : `✓ ACQUIS 2 — un dump produit avec la recette de production
se restaure… 2.44s`.

---

## 1. Grille de sabotage — une ligne par sabotage joué

Rayon d'explosion = nombre de tests rouges **hors** l'échec de ligne de base
(`CoverageControllerTest`, présent partout).
« Objet sur lequel la garde rougit » = colonne demandée par le chef de chantier (piège 19).

<!-- TABLEAU-SABOTAGES -->

---

## 2. Les quatre pathologies, recherchées nommément

<!-- PATHOLOGIES -->

---

## 3. Constats

<!-- CONSTATS -->

---

## 4. Ce que je n'ai PAS pu vérifier, et pourquoi

<!-- NON-VERIFIE -->
