# Mesure de performance de référence — étape 0, ligne 11 (F5)

> **Date** : 2026-08-18 · **Branche** : `chore/etape-0-prealables` · **Volume** : celui du cahier des charges (§0.9) — 50 000 fiches actives, 5 ans d'historique — plus 250 000 fiches froides pour que le filtre de température travaille.
> **Statut** : première mesure, à **conserver comme témoin** ; les critères du §29 du cahier des charges (n° 1, 17) se comparent à elle.
> **Rejouable** : `backend/database/perf/seed_reference_50k.sql` (jeu de données), `mesure_reference.sh` (HTTP), `analyse_mesure.py`, et le bloc `EXPLAIN ANALYZE` §3 ci-dessous.

---

## 1. Ce qui a été mesuré, et comment

| Élément | Valeur |
|---|---|
| Base | `axion_crm_perf` (jetable, créée `TEMPLATE axion_crm`, locale **C** comme la prod), Postgres 16 de la pile locale (`axion-crm-postgres`) |
| Données | **300 000 `companies`** (50 000 actives : types et étapes variés, source humaine `src:site-*` / `src:calendly` / `src:newsletter` / `src:avis-client` ; 250 000 froides : `nouveau` + `src:scraping-*`) · **50 000 `contacts`** (1 par fiche active, `person_key` sha256) · **500 000 `activities`** (10 par contact, `occurred_at` réparti sur 5 ans) · 300 000 `company_tag`. Générées en SQL, graine fixe (`setseed(0.42)`), ~2 min |
| Code | image `axion-crm-pro-api`, backend du dépôt principal (`main`) — le hub n'a pas changé à l'étape 0 |
| HTTP | serveur PHP de dev (`php -S`, **mono-thread**) sur montage Windows, 10 endpoints × 15 itérations **séquentielles**, jeton Sanctum d'un utilisateur `owner` du workspace business |
| SQL | `EXPLAIN (ANALYZE, BUFFERS)` des requêtes telles que `ContactsHubController` les construit, 2 passes (la 2ᵉ, cache chaud, est reportée) |

**Ce que la mesure HTTP vaut, et ne vaut pas.** Sur ce poste, la requête la plus vide (`/config/features`) coûte déjà **5,9 s (p50) / 8,3 s (p95)** : c'est le temps de démarrage de Laravel à travers le montage Windows, pas le produit. Les chiffres HTTP ne servent donc qu'en **delta** par rapport à cette ligne de base, et le **temps SQL** est la mesure portable. Sur la production (Linux, php-fpm + opcache), la ligne de base est de l'ordre de quelques dizaines de millisecondes.

---

## 2. HTTP — 15 itérations par endpoint (secondes)

| endpoint | codes | p50 | p95 | p50 − base | p95 − base | taille |
|---|---|---|---|---|---|---|
| `GET /config/features` (ligne de base) | 200 | 5,90 | 8,31 | — | — | 64 o |
| `GET /crm/contacts-hub?per_page=50` (actifs) | 200 | 7,56 | 9,37 | +1,66 | +3,46 | 26 Ko |
| `…&relation_type=client` | 200 | 6,16 | 7,97 | +0,25 | +2,07 | 26 Ko |
| `…&temperature=froids` | 200 | 6,03 | 6,40 | +0,12 | +0,49 | 17 Ko |
| `…&temperature=tous` | 200 | 5,67 | 7,32 | −0,24 | +1,42 | 26 Ko |
| `…&q=Cabinet Mar` (recherche préfixe) | 200 | 5,88 | 6,85 | −0,03 | +0,94 | 26 Ko |
| `GET /crm/contacts-hub/counts` | 200 | 4,89 | 5,46 | −1,01 | −0,45 | 301 o |
| `GET /crm/persons/{person_key}/timeline` | 200 | 4,67 | 5,86 | −1,23 | −0,04 | 2,6 Ko |
| `GET /search?q=Cabinet Martin` | 200 | 4,73 | 5,84 | −1,18 | −0,06 | 40 o |
| `GET /companies/export?relation_type=client` (9 092 lignes CSV) | 200 | **37,41** | **41,56** | **+31,5** | **+35,7** | 16 Mo |

Lecture : hors export, **tout tient dans le bruit de la ligne de base** (deltas entre −1,2 s et +3,5 s sur un p95 de base à 8,3 s) — la charge utile du produit est de l'ordre de la seconde au pire, sur un poste où la seule initialisation en coûte six. L'export CSV est le seul endpoint dont le coût propre est visible : **≈ 3,4 ms par ligne** de génération PHP (16 Mo), voir §4.

---

## 3. SQL — `EXPLAIN ANALYZE`, cache chaud (millisecondes)

| requête (telle que le contrôleur la construit) | plan | exécution | verdict |
|---|---|---|---|
| Hub, page 1, **actifs** (`lifecycle ≠ nouveau OR EXISTS src humaine`, tri `updated_at desc, id desc`, limit 51) | Index Scan `idx_companies_ws_updated_id` | **139 ms** | ✅ < 500 ms |
| Hub, `relation_type = client` | Index Scan idem | **106 ms** | ✅ |
| Hub, **froids** (250 000 lignes candidates) | Index Scan idem, 43 750 lignes filtrées | **44 ms** | ✅ |
| Hub, recherche préfixe `denomination ILIKE 'Cabinet Mar%'` | Index Scan idem | **72 ms** | ✅ |
| Hub, compteurs (`group by relation_type, lifecycle_stage`) | **Parallel Seq Scan** sur 300 000 | **210 ms** | ✅ ici — ⚠️ voir §4 |
| Timeline d'une personne (`activities` par `person_key`, tri `occurred_at desc`, limit 100) | index `person_key` | **11 ms** | ✅ |
| Export clients (`relation_type = client`, 9 092 lignes) | Bitmap Index Scan `idx_companies_workspace_relation_type` | **15 ms** | ✅ (SQL) |
| Recherche globale (`denomination ILIKE 'Cabinet Martin%'`, limit 20) | Index Scan | **0,3 ms** | ✅ |

Planification : 0,4 à 14 ms. Aucun `Seq Scan` sur le chemin des listes paginées ; le tri par curseur s'appuie sur `idx_companies_ws_updated_id` (posé le 17/08, `2026_08_17_000001_companies_hub_tous_index`).

> Le « eager contacts » du hub a été approché par une sous-requête sur `siren` (135 ms, seq scan) qui **n'est pas** la requête réelle : Laravel charge les contacts par liste de 50 `company_id` (index PK) — négligeable. Reporté pour honnêteté, non compté.

---

## 4. Verdict et deux points à surveiller

**Au volume de référence du cahier des charges (50 000 fiches actives, 5 ans), toutes les requêtes des listes, de la recherche et de la timeline sont sous 250 ms côté base, la plupart sous 100 ms** — le critère « retrouvable en moins de 5 s » (§29 n° 1) et l'exigence de fluidité (§0.9) sont tenus avec une marge d'un ordre de grandeur, à condition que l'application n'ajoute pas de coût propre déraisonnable (ce que la mesure HTTP, en delta, confirme).

Deux points, à traiter **avant** que la production ne les rende visibles :

1. **Les compteurs de la barre (`/contacts-hub/counts`) sont un `Seq Scan` complet de `companies` par workspace.** 210 ms sur 300 000 lignes ; **la production en porte 4,29 M** dans le même workspace → de l'ordre de **3 s à chaque rendu de navigation**, et ça grandit avec la collecte. À corriger à l'étape 1a (compteurs mis en cache quelques minutes, ou table de compteurs entretenue par déclencheur, ou index couvrant `(workspace_id, relation_type, lifecycle_stage) WHERE deleted_at IS NULL` pour un index-only scan). Le cahier des charges exige des compteurs qui « appellent une action », pas un total décoratif — les rafraîchir toutes les cinq minutes suffit.
2. **L'export CSV coûte ≈ 3,4 ms par ligne côté PHP** (9 092 lignes → 31 s au-dessus de la ligne de base sur ce poste ; peut-être 5 à 10 fois moins sur Linux, mais linéaire). Sur un export de 50 000 fiches, c'est une minute ou plus. À garder en flux (déjà le cas), à paginer côté base par curseur, et à passer en tâche de fond avec notification au-delà d'un seuil (le cahier des charges §17.2 pose déjà ce patron pour l'analyse).

Ce qui n'a **pas** été mesuré ici, et reste à faire quand les écrans existeront : la recherche plein texte dans les transcriptions (§18.1), l'export d'historique complet d'une personne (§3.1), et la charge à **dix utilisateurs simultanés** (§29 n° 17) — impossible sur un serveur mono-thread ; à jouer en préproduction (`staging.axion-crm-pro.com`) avec le même jeu de données.

---

## 5. Rejouer la mesure

```bash
# 1. base jetable, même locale que la prod
docker exec axion-crm-postgres psql -U axion -d postgres -c "CREATE DATABASE axion_crm_perf TEMPLATE axion_crm"
docker exec -i axion-crm-postgres psql -U axion -d axion_crm_perf -v ON_ERROR_STOP=1 < backend/database/perf/seed_reference_50k.sql
# 2. API jetable sur cette base (même env que axion-crm-api, DB_DATABASE=axion_crm_perf), port 58081
# 3. utilisateur owner + jeton : cf. RUNBOOK-CONSOLE-LOCALE §5.1, puis $u->createToken('perf')
# 4. mesure
PERF_TOKEN=... N=15 OUT=/tmp/bench.csv bash backend/database/perf/mesure_reference.sh
PYTHONIOENCODING=utf-8 python backend/database/perf/analyse_mesure.py /tmp/bench.csv 0
# 5. EXPLAIN ANALYZE : bloc §3 (`backend/database/perf/explain_reference.sql`)
```
