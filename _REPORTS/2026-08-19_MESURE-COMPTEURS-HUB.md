# Mesure — compteurs du hub de contacts (étape 1a, pièce n°1)

> **Date** : 2026-08-19 · **Branche** : `feat/etape-1a` · **Objet** :
> `GET /api/v1/crm/contacts-hub/counts`
> **Suite de** : `_REPORTS/2026-08-18_MESURE-PERFORMANCE-REFERENCE.md` §4 n°1, qui
> signalait le défaut sans le chiffrer au volume réel.
> **Rejouable** : `backend/database/perf/seed_reference_50k.sql` (300 000 fiches),
> `backend/database/perf/seed_volume_production_4m.sql` (volume de production),
> `backend/database/perf/explain_reference.sql`.

---

## 1. Le défaut

`ContactsHubController::counts()` exécutait, **à chaque rendu de la navigation** :

```sql
SELECT relation_type, lifecycle_stage, count(*)
  FROM companies
 WHERE workspace_id = ? AND deleted_at IS NULL
 GROUP BY relation_type, lifecycle_stage
```

Aucun index ne la servait. La production porte **4,29 M de `companies` dans un
seul workspace business**, et le coût d'un balayage est linéaire en nombre de
lignes **et** en nombre de pages de tas — or le tas de `companies` est large
(≈ 50 colonnes, dont du `jsonb` et de la géométrie).

---

## 2. Ce qui a été mesuré, et comment

| Élément | Valeur |
|---|---|
| Base | `axion_crm_perf` (300 000 fiches, jeu de référence du cahier des charges §0.9) et `axion_crm_perf4m` (**2 800 000 fiches**, jetables, créées `TEMPLATE axion_crm`, locale **C** comme la prod) |
| Serveur | Postgres 16 de la pile locale (`axion-crm-postgres`), Docker Desktop / Windows |
| Méthode | `EXPLAIN (ANALYZE, BUFFERS)` de la requête **telle que le contrôleur la construit**, première passe (cache froid) puis passes suivantes (cache chaud) |
| Index posé | `idx_companies_ws_counts (workspace_id, relation_type, lifecycle_stage) WHERE deleted_at IS NULL`, migration `2026_08_19_000001`, `CONCURRENTLY` |

⚠️ **Le volume visé était 4,29 M ; il s'est arrêté à 2,8 M**, le remplissage
affamant la suite de tests qui tournait en parallèle (attente `WALWrite` sur le
même disque virtuel). 2,8 M, c'est **9,3 fois** le volume de référence : la pente
est mesurée sur près d'une décade, et l'écart restant jusqu'à 4,29 M est un
facteur **1,53**, pas un facteur 14. C'est dit ici plutôt que masqué.

---

## 3. Résultats

### 3.1 Volume de référence — 300 000 fiches

| | Plan | Tampons | Temps |
|---|---|---|---|
| **Avant** | `Parallel Seq Scan on companies` | `hit=378 read=9830` = **10 208** | 476 ms (froid) · 337 / 363 / 457 ms (chaud) |
| **Après** | `Parallel Index Only Scan using idx_companies_ws_counts` · `Heap Fetches: 0` | `hit=270` = **270** | 188 / 277 / 135 / 128 ms |
| | | **÷ 38** | **÷ 2,6** |

### 3.2 Volume de production — 2 800 000 fiches

| | Plan | Tampons | Temps |
|---|---|---|---|
| **Avant** | `Parallel Seq Scan on companies` | `hit=1199 read=78639` = **79 838** (≈ 624 Mo lus) | **17 504 ms (froid)** · 2 416 / 2 949 ms (chaud) |
| **Après** | `Parallel Index Only Scan` · `Heap Fetches: 0` | `hit=2481` = **2 481** | **721 / 648 / 798 / 972 ms** |
| | | **÷ 32** | **÷ 24 (froid)** · **÷ 3,4 (chaud)** |

### 3.3 Pourquoi : la taille

| Objet | Taille à 2,8 M |
|---|---|
| Tas de `companies` | **624 Mo** |
| `idx_companies_ws_counts` | **20 Mo** |

**31 fois plus petit.** C'est tout le correctif d'index : la requête ne lit plus
que trois colonnes étroites au lieu de traverser cinquante colonnes de tas. Le
`Heap Fetches: 0` dit que le tas n'est plus touché **du tout**.

### 3.4 Coût de pose de l'index

`CREATE INDEX CONCURRENTLY` sur 2 800 000 lignes : **13 s**. Extrapolé à 4,29 M :
de l'ordre de **20 s**, sans verrou d'écriture sur `companies`.

---

## 4. Ce que la mesure a tranché — et ce qu'elle a réfuté

1. **Le rapport du 18/08 avait raison sur le fait, et était optimiste sur
   l'ampleur.** Il annonçait « de l'ordre de 3 s » sur 4,29 M. Mesuré : **2,4 à
   2,9 s dès 2,8 M, cache chaud — et 17,5 s cache froid**. Le cas froid n'avait
   pas été envisagé : c'est pourtant le cas normal quand le tas de 624 Mo (1 Go
   à 4,29 M) ne tient pas dans le cache d'une base qui sert aussi tout le reste.
2. **L'index seul NE SUFFIT PAS.** Il ramène le calcul à ≈ 700-1 000 ms à 2,8 M,
   donc ≈ 1 à 1,5 s à 4,29 M. C'est acceptable **une fois**, ce ne l'est pas à
   chaque rendu de navigation. **Le cache est donc le correctif principal**, et
   ce n'était pas ce que laissait croire la formulation « cache court **ou**
   index couvrant » du rapport précédent.
3. **Le cache seul ne suffit pas non plus** : il faut bien le remplir une
   première fois, et sans index ce premier remplissage coûte 17,5 s.

**Conclusion : les deux, et dans cet ordre de priorité — cache d'abord, index
comme plancher.** C'est ce qui est livré (`App\Crm\Console\CompteursHub`).

---

## 5. Le réglage retenu pour le cache, et pourquoi ces valeurs

`Cache::flexible($cle, [300, 3600], …, lock: ['seconds' => 30])`, par workspace.

| Réglage | Valeur | Motif |
|---|---|---|
| Fenêtre **fraîche** | 300 s | Valeur du rapport du 18/08 : « les rafraîchir toutes les cinq minutes suffit ». Le cahier des charges veut des compteurs qui appellent une action, pas un total à la seconde près. |
| Fenêtre **périmée mais servie** | 3 600 s | Entre 5 min et 1 h, la valeur est rendue **immédiatement** et le recalcul part **après la réponse** : personne n'attend jamais. Au-delà d'une heure sans que personne n'ouvre l'écran, le premier arrivant paie **≈ 1 à 1,5 s** — mesuré, et jugé acceptable pour un chiffre décoratif. C'est ce qui a fait **écarter** l'élargissement à 24 h : il aurait échangé cette seconde contre le risque d'afficher, une fois, des chiffres vieux d'un jour. |
| **Verrou** du rafraîchissement | 30 s | `flexible` prend par défaut un verrou **sans expiration** : un processus tué entre la prise et le relâchement gèlerait les compteurs de ce workspace **pour toujours**, en silence. |

**Pas de table de compteurs entretenue par déclencheur**, bien que ce soit le
meilleur temps de lecture : la collecte insère par centaines de milliers, et un
déclencheur ferait converger toutes ces écritures sur quelques dizaines de
lignes — contention de verrou sur le chemin le plus chaud du produit, pour un
chiffre rafraîchi toutes les cinq minutes.

---

## 6. Ce qui reste à mesurer

- La reprise à **4,29 M** exactement, quand la machine n'a rien d'autre à faire
  (le facteur restant est 1,53).
- La charge à **dix utilisateurs simultanés** (§29 n°17) : impossible sur cette
  pile locale, à jouer en **préproduction** (`staging.axion-crm-pro.com`).
- Le même agrégat existe sur `candidates` (`CandidatesController::counts`), sans
  index couvrant. La table du vivier n'a **pas** d'ordre de grandeur comparable —
  constat posé, à re-mesurer si le vivier grossit, **pas ignoré en silence**.
