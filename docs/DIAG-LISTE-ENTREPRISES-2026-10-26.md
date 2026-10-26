# Diagnostic — page « Entreprises » figée sur les squelettes (26/10/2026)

Symptôme (prod, `/companies` et `/companies?search=OURY`) : lignes grises
indéfiniment, « 0 entreprises actives », cartes « calcul en cours ».

Méthode : **lecture du code seule** (aucun accès aux journaux ni à la base de
production depuis cette session). Les conclusions ci-dessous distinguent ce que
le code **prouve** de ce qui reste **à confirmer en prod**.

## 1. Ce que le code prouve sur le symptôme

| Ce qu'on voit | Ce que ça veut dire dans le code |
|---|---|
| Squelettes | `CompaniesListPage.tsx:653` : `isLoading` vrai = la requête `GET /companies` **n'a encore jamais répondu**. Une réponse, même vide ou « dégradée », afficherait « Aucune entreprise » ; une erreur afficherait `QueryErrorState`. |
| « 0 entreprises actives » | `(total ?? 0)` (l. 352) : `data` est `undefined`, ce n'est pas un total réel de 0. |
| « calcul en cours » | `indicateurs.ts:43-50` : `GET /companies/stats` n'a rien rendu (ou a échoué : **aucun état d'erreur** n'existe pour ces cartes, l'échec est avalé). |

Durée maximale du squelette : `api.ts:10` `timeout: 30_000` × 3 essais
(`nouvel-essai.ts`, 2 nouveaux essais) + attentes ≈ **1 min 35** avant que
l'écran d'erreur ne s'affiche. **Les deux requêtes dépassent donc 30 s côté
serveur** (ou ne répondent pas). Le front ne bloque rien de lui-même : aucune
modification front depuis le 10/10 hors `audiences/destinataires.ts` et la
montée de version axios 1.7 → 1.20 (#335, 24/10, comportement du `timeout`
inchangé).

## 2. Cause la plus probable : caches vides + calculs synchrones plus longs que le délai du front

1. **Chaque déploiement purge le cache** : `deploy-direct-ssh.yml:507`
   `php artisan cache:clear`. Le 26/10 a connu ~5 déploiements (#348, #350,
   #351, #353, #355). Après chacun, les totaux (`TotalListe`, clé
   `crm:liste:total:v1:…`) et les indicateurs (`crm:companies:stats:v2:…`)
   sont froids.
2. **`/companies/stats` à froid** (`CompaniesController::stats`, l. 262-267) :
   `Cache::flexible` calcule **dans la requête** (le verrou `lock:` ne protège
   que le rafraîchissement différé, pas le premier calcul), sous un délai SQL
   **élargi à 120 s** par instruction (`DelaiRequeteSql::etendu(120, …)`), avec
   5 requêtes dont 4 `GROUP BY` sur ≈ 4,4 M de fiches. Le front abandonne à
   **30 s** puis **relance deux fois** : jusqu'à 3 calculs identiques
   simultanés par onglet ouvert, chacun tenant un worker PHP et une connexion
   Postgres jusqu'à 2 min. Si un calcul dépasse 120 s, rien n'est jamais mis en
   cache et les cartes restent « calcul en cours » pour toujours.
3. **`/companies` à froid** (l. 154-171) : deux `count(*)` (toutes + fermées,
   `EntreprisesFermees::total`) puis la page, **chacun** borné à 15 s
   (`statement_timeout_web_ms`). Trois instructions à 10-15 s chacune
   dépassent les 30 s du front **sans jamais déclencher d'erreur serveur**.
   Le comptage « Index Only Scan, Heap Fetches 0 » mesuré le 20/08 suppose une
   carte de visibilité à jour : les écritures massives du 26/10 sur
   `companies` (import FINESS #348, `crm:pharmacies:classer-taille` #353,
   enrichissement #350) l'ont très probablement dégradée tant qu'aucun
   `VACUUM` n'est passé → le comptage relit le tas (≈ 9 Go).
4. **Avec `?search=OURY`** : `filter[denomination]` →
   `RechercheDenomination` (`denomination_normalized ILIKE …`). Sous la RLS
   forcée et le rôle `axion_app`, `ILIKE` n'étant pas *leakproof*, l'index
   trigrammes **n'est pas utilisable** : Parallel Seq Scan sur 4,3 M de lignes
   (prouvé par `2026_10_02_000050_recherche_choix_entreprise_sous_rls.php`
   pour le sélecteur ; le correctif `SECURITY DEFINER` n'a **jamais été
   porté** au filtre de la liste). Le comptage de la recherche n'est pas en
   cache non plus → la recherche ne peut pas « débloquer » la page.

## 3. Pistes écartées

- **Migration `2026_10_25_000010_synchro_dossier_client`** : purement
  additive (`ADD COLUMN` à défaut constant, `lock_timeout 30s`), déjà jouée le
  25/10 ; l'index `000020` est `CONCURRENTLY`. Elle n'ajoute rien aux requêtes
  de la liste (`COLONNES_LISTE` inchangée). Un verrou résiduel serait borné à
  15 s par le `statement_timeout` → réponse « dégradée », pas squelette.
- **Ingestion `CRM_INGEST_DOSSIER_ENABLED`** (`DossierClientIngest`) :
  quelques dizaines de fiches, écritures ligne à ligne ; ne prend aucun verrou
  bloquant les lectures. Ne touche ni `TotalListe` ni le cache des stats.
- **Filtres « Plus de filtres »** : vides par défaut ; ne s'appliquent pas au
  symptôme sans filtre.
- **Pharmacies (#348-#355)** : non modifiées ; elles ne contribuent que par le
  volume d'écritures (point 2.3).
- **Exception avalée côté serveur** : `index()` rend
  `{data: [], degraded: true}` en 200 sur exception — cela produirait
  « Aucune entreprise », pas des squelettes. (Défaut séparé : le front ignore
  `degraded` sur cet écran et afficherait « Aucune entreprise » au lieu d'une
  panne — à corriger aussi, cf. `NotificationsBell.tsx` qui le gère déjà.)

## 4. À confirmer en prod (lecture seule, < 5 min)

```sql
-- requêtes longues en cours sur companies
SELECT pid, now()-query_start AS duree, state, wait_event_type, left(query,120)
  FROM pg_stat_activity WHERE query ILIKE '%companies%' AND state <> 'idle' ORDER BY 2 DESC;
-- carte de visibilité / dernier vacuum
SELECT n_live_tup, n_dead_tup, last_autovacuum, last_autoanalyze
  FROM pg_stat_user_tables WHERE relname = 'companies';
SELECT relallvisible::float / NULLIF(relpages,0) AS part_visible FROM pg_class WHERE relname='companies';
```
Et dans `storage/logs` : `companies.index failed`, durées de
`GET /api/v1/companies` et `/companies/stats` dans les journaux nginx.

## 5. Correctif proposé (non codé : cause non certifiée sans mesure prod)

Par ordre d'effet / de risque :

1. **Immédiat, sans code** : `VACUUM (ANALYZE) companies;` hors heures
   (non bloquant, restaure les Index Only Scan) si `part_visible` est bas.
2. **Stats jamais synchrones** : dans `CompaniesController::stats`, sur cache
   vide rendre aussitôt `$vide` et lancer le calcul après la réponse
   (`defer()` ou un job unique protégé par `Cache::lock`), au lieu de bloquer
   jusqu'à 120 s. Test : cache vide → réponse immédiate `total: null`, un seul
   calcul même avec 3 appels.
3. **Rechauffer après déploiement** : après `cache:clear`
   (`deploy-direct-ssh.yml:507`), appeler une commande qui recalcule les
   stats et le total par défaut de chaque espace — ou ne plus purger ces clés.
4. **Front** : état d'erreur / « indisponible » pour les cartes (au lieu de
   « calcul en cours » éternel) et prise en compte de `degraded` dans la liste.
5. **Recherche sous RLS** : porter le patron `entreprises_choix_ids`
   (`SECURITY DEFINER`, ids d'un seul espace, relus sous RLS) au filtre
   `filter[denomination]` de la liste ; sans cela toute recherche par nom
   balaie 4,3 M de lignes.
