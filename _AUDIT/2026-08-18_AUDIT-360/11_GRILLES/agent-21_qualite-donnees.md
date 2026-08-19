# Agent 21 — Qualité des données

- **Référence code** : `main = 8db8229` (relu par `git rev-parse --short HEAD`, pas recopié d'un document).
- **Référence données** : **PRODUCTION**, `root@46.62.248.239` → `docker exec axion-crm-postgres psql -U axion -d axion_crm`.
  PostgreSQL 16.9, `datcollate = C`, `datctype = C`.
- **Mode d'accès** : chaque session ouverte par `SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY;`.
  **Aucune écriture, aucun `INSERT`, `UPDATE`, `DELETE`, `CREATE`.** Seuls des `SELECT`, `EXPLAIN (ANALYZE)`
  de `SELECT`, et des méta-commandes `\d`.
- **Bases synthétiques `axion_crm_perf` / `axion_crm_perf4m` : NON UTILISÉES.** Aucun chiffre de ce rapport
  n'en provient. La base `axion_crm_a21` n'a pas été créée : aucune écriture n'a été nécessaire.
- **Preuves brutes** : `_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/agent-21/`.

## 0. Provenance de chaque chiffre — la colonne qui commande la lecture

> L'avertissement méthodologique du mandat est appliqué littéralement. **Tout chiffre ci-dessous est
> une mesure de PRODUCTION.** Il n'y a pas un seul chiffre synthétique dans ce rapport. Là où je n'ai
> pas pu mesurer, je le dis au §4 au lieu de substituer une mesure `perf`.

Volumes de contrôle relevés en production (`prod_doublons_companies.txt`, en-tête de session) :

| Table | Lignes | Attendu par le mandat | Écart |
|---|---:|---:|---|
| `companies` | 4 295 349 | 4 295 349 | conforme |
| `contacts` | 1 319 567 | 1 319 567 | conforme |
| `candidates` | 0 | 0 | conforme |
| `activities` | 649 | 649 | conforme |
| `tags` | 217 | — | — |
| `company_tag` | 7 501 969 | — | — |
| `workspaces` | 2 | — | — |
| `migrations` | **59** | 58 (dépôt) | prod porte 1 migration de plus que le compte dépôt du dossier commun |

`deleted_at IS NOT NULL` = **0** sur `companies` et sur `contacts` : aucune suppression douce n'a jamais
été faite. Toutes les mesures portent donc sur la totalité du stock.

---

## 1. Tableau de grille

| # | Objet du périmètre | Mesuré sur | Résultat | Verdict |
|---|---|---|---|---|
| 1a | Doublons `companies` — même SIREN | production | 0 groupe | conforme (contrainte `UNIQUE(workspace_id, siren)`) |
| 1b | Doublons `companies` — même nom normalisé + ville | production | 38 451 groupes / 102 974 fiches / **64 523 surnuméraires** (1,50 %) | défaut — C21-007 |
| 1c | Doublons `companies` — même téléphone | production | 31 885 groupes / 193 910 fiches / **162 025 surnuméraires** (3,77 %) | défaut — C21-007 |
| 1d | Doublons `contacts` — même e-mail | production | 49 492 groupes / 225 710 fiches / **176 218 surnuméraires** = **42,93 % des 410 481 contacts ayant un e-mail** | grave — C21-003 |
| 1e | Doublons `contacts` — même téléphone | production | 0 — *et pour cause : 0 contact porte un téléphone* | non concluant, cf. C21-006 |
| 1f | Doublons `contacts` — même nom + entreprise | production | 0 (bloqué par `UNIQUE(workspace_id, normalized_hash)`) | conforme |
| 1g | Doublons `contacts` — même personne, entreprises dupliquées | production | 23 386 groupes / **46 232 surnuméraires** | défaut — C21-003 |
| 2a | Casse — CI vs production | production + `ci.yml` | **les deux en `C`/`C`** — le piège 10 réécrit est vérifié, la divergence n'est PAS là | conforme |
| 2b | Casse — divergence SQL ↔ PHP, mécanisme | production (témoin) | **prouvée** : `lower('ÉLODIE')` → `Élodie` ; `'ÉLODIE'::citext = 'élodie'::citext` → **faux** | mécanisme réel |
| 2c | Casse — conséquence sur la déduplication e-mail | production | **0 doublon échappe aujourd'hui** (0 e-mail non-ASCII sur 410 481) | latent, non réalisé — C21-002 |
| 2d | Casse — « un index unique sur `lower(email)` protège-t-il ? » | production | **il n'en existe aucun** : 0 index UNIQUE sur `lower()` dans toute la base, 0 index UNIQUE sur `contacts.email` | grave — C21-003 |
| 2e | Casse — Dupont / DUPONT / dupont attrapés ? | production | **oui** : `normalize_name` replie casse + accents ; 528 « Dupont » + 453 « DUPONT » réels | conforme |
| 2f | Casse — Dupond attrapé ? | production | **non**, et c'est correct : `normalize_name('Dupont') ≠ normalize_name('Dupond')` | hors périmètre de la dédup exacte |
| 2g | Casse — recherche par nom accentué | production | `lower(last_name)` ≠ `lower(unaccent(last_name))` sur **7 383 fiches** ; l'index de recherche `idx_contacts_ws_lower_last_name` est en `lower()` nu | défaut — C21-002 |
| 3a | Encodage — caractère de remplacement U+FFFD | production | **0** sur `companies` (dénomination, adresse) et `contacts` | conforme |
| 3b | Encodage — double encodage UTF-8 | production | **0** sur `companies` (4 295 349) ; **1** sur `contacts.last_name` | finition — C21-009 |
| 3c | Encodage — entités HTML non décodées | production | **0** sur `companies` et `contacts` | conforme |
| 3d | Encodage — témoins des trois contrôles | production | **positif ET négatif validés pour les 3 motifs** | contrôle prouvé capable |
| 4a | Complétude `companies` — e-mail | production | 255 290 renseignés (**5,94 %**) — 255 290 plausibles (**100 % du renseigné**) | mesuré |
| 4b | Complétude `companies` — téléphone | production | 323 678 renseignés (**7,54 %**) — 323 678 plausibles (**100 %**) | mesuré |
| 4c | Complétude `companies` — adresse | production | 4 285 421 renseignées (**99,77 %**) — 3 788 293 plausibles (**88,38 %** du total) | mesuré |
| 4d | Complétude `companies` — SIREN | production | 4 294 903 renseignés (**99,99 %**) — **4 294 903 passent Luhn (100 %)** | conforme |
| 4e | Complétude `companies` — site web | production | 824 762 renseignés (**19,20 %**) — 824 762 plausibles (**100 %**) | mesuré |
| 4f | Complétude `contacts` — e-mail | production | 410 481 (**31,11 %**) — 410 481 plausibles (**100 %**) | mesuré |
| 4g | Complétude `contacts` — téléphone / titre / LinkedIn | production | **0 / 0 / 0** sur 1 319 567 | grave — C21-006 |
| 4h | Complétude `contacts` — `person_key` | production | **0** — confirme A05-001 | déjà ouvert (A05-001) |
| 4i | Complétude `contacts` — `legal_basis` | production | **0 renseignée sur 1 319 567** | grave — C21-006, renforce C19-007 |
| 5a | `QualityScore` — mode de calcul | production (`pg_get_functiondef`) | fonction PL/pgSQL `recompute_company_quality_score`, 8 critères + bonus contact +20, plafond 100 | documenté |
| 5b | `QualityScore` — distribution | production | 16 valeurs distinctes ; **3 470 574 à 0 (80,80 %)** ; max observé **85** | concentrée — C21-005 |
| 5c | `QualityScore` — crédibilité vs sa propre formule | production | **3 546 986 / 4 295 349 (82,58 %) divergent** — 3 484 663 sous-évalués, 62 323 sur-évalués | grave — C21-004 |
| 5d | `quality_badge` | production | `basique` 92,37 % / `partielle` 7,63 % / **`complete` 0 fiche** (seuil ≥ 90 jamais atteint) | défaut — C21-005 |
| 6a | Tags — fiches portant un tag `src:` | production | **4 295 346 / 4 295 349** ; le backfill `src:scraping-insee` porte **exactement 4 294 895** | **annonce du backfill VÉRIFIÉE** |
| 6b | Tags — fiches sans aucun tag | production | **3** | conforme |
| 6c | Tags — orphelins (0 fiche) | production | **59 sur 217 (27,2 %)**, dont 36 des 38 tags `src:` | finition — C21-009 |
| 6d | Tags — doublons de slug | production | 1 (`src:site-formulaire-autre`) — **mais dans DEUX espaces différents** : légitime, pas un défaut | réfuté |
| 6e | Tags — doublons de casse | production | 1, le même que 6d | réfuté |
| 7 | `companies_entites_sans_siren` | production + code | 446 fiches sans SIREN, **446 avec `foreign_id` (0 sans ancre), 446 avec `entity_nature`** ; toutes `country_code = RO` | **cohérent** |
| 8a | Type de contact — porté par la personne ? | production | **NON** : 0 colonne de type sur `contacts` ; `relation_type` existe sur `companies` et `candidates` | contradiction CONFIRMÉE |
| 8b | Type de contact — mono-type « le plus engageant » ? | code `SiteSyncClassifier.php:180` | **OUI**, `mergeRelationType()` retourne un scalaire | contradiction CONFIRMÉE |
| 8c | Type de contact — `conference` / `newsletter` comme types ? | production (CHECK) + code | **OUI**, présents dans `companies_relation_type_check` et dans `BUSINESS_RELATION_TYPES` | contradiction CONFIRMÉE |
| 8d | **Fiches réellement concernées par le reclassement** | production | voir §2 ci-dessous — **1 319 567 structurellement, 0 par requalification de valeur** | C21-008 |

---

## 2. Le reclassement de type — combien de fiches, réellement

C'est la question que le mandat pose, et la réponse honnête est en deux temps.

**Mesure (production, `prod_type_contact.txt`) :**

| Ce qu'on reclasse | Fiches concernées |
|---|---:|
| `companies` dont `relation_type` = `conference` ou `newsletter` (à repasser en motif) | **0** |
| `companies` portant ≥ 2 tags impliquant un type (le multi-types du §2.2) | **0** |
| `companies` portant ≥ 1 tag impliquant un type | **0** |
| `companies` à requalifier depuis une autre valeur que `prospect` | **0** — les **4 295 349** sont à `relation_type = prospect`, 100,0000 % |
| `contacts` ne pouvant structurellement PAS porter de type (aucune colonne) | **1 319 567** |
| `candidates` (l'autre porteur de `relation_type`) | **0** |

**Verdict.** Les trois contradictions CDC ↔ code sont **réelles dans le code** — je les ai contre-vérifiées
une par une, je ne les recopie pas. Mais leur **portée en données aujourd'hui est nulle pour deux d'entre
elles** : aucune fiche n'a jamais quitté `prospect`, donc aucune valeur `conference`/`newsletter` n'est à
requalifier et aucun multi-type n'est à démêler. Les contradictions (b) et (c) sont des **dettes latentes**,
pas des corrections de données à faire.

La contradiction (a) est d'une autre nature : elle est **structurelle et totale**. Le type est porté par
`companies`, jamais par la personne ; **1 319 567 personnes** ne peuvent porter aucun type, et le
`relation_type` de leur entreprise est le même pour toutes (`prospect`). Combiné à
`contacts.company_id NOT NULL` (I48-003), une personne ne peut ni exister sans société, ni avoir un type
propre. **C'est la fiche 360° qui est visée**, et c'est le même mur qu'A05-001.

**Chiffre à retenir : 1 319 567 fiches concernées par le reclassement, 0 par requalification de valeur.**

---

## 3. Constats

### [C21-001] La recherche d'e-mail des chemins RGPD et de déduplication ne peut utiliser aucun index : 1 070 ms de balayage séquentiel par requête

- Sévérité      : S1 grave
- Domaine       : performance / conformité
- Référence     : main 8db8229 ; données production 2026-08-19
- Emplacement   : `backend/app/Crm/Rgpd/SiteGdprService.php` (5 occurrences), `backend/app/Crm/Scraping/ScrapedRecordIngestService.php:437`, `backend/app/Crm/Ingest/ContactUpserter.php`, `backend/app/Crm/Ingest/SiteSyncIngestService.php` — 8 occurrences du motif `whereRaw('lower(email::text) = ?', [$email])`
- Constat       : `contacts.email` est de type `citext` et indexé par `idx_contacts_email btree(email)`, mais les 8 requêtes du produit interrogent `lower(email::text)`, expression qu'aucun index ne couvre.
- Preuve        : `04_PREUVES/agent-21/prod_explain_lookup_email.txt` — `EXPLAIN (ANALYZE, BUFFERS)` joué en production :
  - `WHERE lower(email::text) = 'contact@exemple.fr'` → **Parallel Seq Scan**, 1 319 567 lignes filtrées, 101 706 buffers lus, **Execution Time 1 070,604 ms**
  - **Témoin positif** : `WHERE email = 'contact@exemple.fr'` (citext, même table, même workspace) → **Index Scan using idx_contacts_email**, 6 buffers, **Execution Time 1,378 ms**
  - Rapport mesuré : **776×**
- Témoin négatif : le témoin positif ci-dessus est la démonstration que l'index existe, est sain, et *aurait* servi si la requête avait été écrite autrement. Le contrôle n'est donc pas aveugle : il distingue les deux formulations sur la même table au même instant.
- Impact        : cinq des huit occurrences sont sur `SiteGdprService::export()` (art. 15) et `::erase()` (art. 17) — une demande RGPD déclenche plusieurs balayages complets. Combiné à **A-010** (la production sert toute l'API par un `php -S` mono-processus, requêtes sérialisées), chaque seconde de balayage bloque **tous** les utilisateurs. Les trois autres occurrences sont sur le chemin d'ingestion : `upsertContact()` paie ce balayage **une fois par personne scrapée**, ce qui rend l'alimentation à l'échelle du stock (4,29 M d'entreprises) impraticable.
- Reproduction  : `ssh root@46.62.248.239` puis `docker exec axion-crm-postgres psql -U axion -d axion_crm -c "EXPLAIN (ANALYZE, BUFFERS, COSTS OFF) SELECT id FROM contacts WHERE workspace_id='1db106f5-c8a4-47b0-bf86-930f1ccc9f4a' AND lower(email::text) = 'contact@exemple.fr';"`
- Correctif     : la colonne étant déjà `citext`, `lower()` est **redondant** — remplacer les 8 `whereRaw('lower(email::text) = ?')` par `->where('email', $email)` restaure l'index sans migration ni index supplémentaire. Coût : 8 lignes, ~1 h avec tests. Variante conservatrice si l'on veut garder l'écriture actuelle : `CREATE INDEX CONCURRENTLY ON contacts (lower(email::text))` — plus cher (index de plus sur une table qui porte déjà 1 491 Mo d'index pour 624 Mo de tas, B10-014) et donc **non recommandé**.
- Statut        : ouvert

### [C21-002] Le repli de casse de PostgreSQL et celui de PHP divergent sur les accents ; le mécanisme est prouvé, son exposition actuelle est nulle

- Sévérité      : S2 défaut (latent — voir la mesure d'exposition)
- Domaine       : backend / conformité
- Référence     : main 8db8229 ; données production 2026-08-19
- Emplacement   : `backend/app/Crm/Rgpd/SiteGdprService.php:42` et `:108`, `backend/app/Crm/Scraping/ScrapedRecordIngestService.php:401` — `$email = mb_strtolower(trim($email));` puis comparaison à `lower(email::text)` côté SQL
- Constat       : sous `lc_ctype = C`, `lower()` de PostgreSQL ne replie pas les accents, alors que `mb_strtolower` de PHP les replie ; les deux côtés de la comparaison ne calculent donc pas la même clé.
- Preuve        : `04_PREUVES/agent-21/prod_casse_sql_vs_php.txt` et `prod_explain_lookup_email.txt`, joués en production :
  - `lower('ÉLODIE')` → `Élodie` (accent **non** replié) ; `pg_lower_replie_accent = f`
  - `'ÉLODIE'::citext = 'élodie'::citext` → **`f`** ; alors que `'DUPONT'::citext = 'dupont'::citext` → `t`
  - `lower('JOSÉ.ELODIE@x.fr')` → `josÉ.elodie@x.fr`, tandis que PHP produirait `josé.elodie@x.fr` → `php_matche_sql = f`
- Témoin négatif : le même contrôle rend `t` sur le cas purement ASCII (`'DUPONT'::citext = 'dupont'::citext`, et `ascii_matche = t`). Il sait donc dire « ça correspond » quand ça correspond — son `f` sur le cas accentué n'est pas un faux positif de méthode.
- **Exposition réelle mesurée** : sur les **410 481** e-mails de `contacts` en production, **0** contient un caractère non-ASCII, **0** contient une majuscule, et **0** diverge entre `lower()` et `lower(unaccent())`. Le nombre de doublons qui échappent aujourd'hui à la déduplication à cause de cet écart est donc **exactement 0** : les groupes de doublons comptés par `citext` (49 492 groupes, 176 218 surnuméraires) et par un repli à la PHP (49 492 / 176 218) sont **identiques**. **Je ne présente donc pas ce constat comme un défaut réalisé.** Il l'est en mécanisme, pas en données.
- Pourquoi l'exposition est nulle — et pourquoi elle est fragile : tous les chemins d'écriture connus appliquent `mb_strtolower` **avant** l'insertion, si bien que le stock est déjà replié et que `lower()` côté SQL est idempotent dessus. La garde tient donc **par convention applicative, non par contrainte**. Toute voie d'écriture qui omettrait `mb_strtolower` — import CSV, saisie manuelle, futur connecteur — réintroduirait l'écart, et il frapperait d'abord `SiteGdprService::erase()`, c'est-à-dire l'effacement RGPD.
- Effet **déjà réalisé**, en revanche, sur la recherche par nom : `lower(last_name) ≠ lower(unaccent(last_name))` sur **7 383** fiches, et l'index de recherche `idx_contacts_ws_lower_last_name` est bâti sur `lower(last_name)` nu. Ces 7 383 personnes sont introuvables par une saisie sans accent.
- **Ce qui n'est PAS en cause** : la déduplication par nom. `ContactUpserter` et `ScrapedRecordIngestService` ne réimplémentent pas la normalisation en PHP — ils la **font calculer par Postgres** (`SELECT encode(digest(normalize_name(...)))`, `ContactUpserter.php:177`, `ScrapedRecordIngestService.php:661`), avec la même fonction `normalize_name` que la colonne générée `normalized_hash`. Et `normalize_name` applique `unaccent()`. Sur ce chemin, **il n'y a aucune divergence SQL ↔ PHP**, par construction et de façon commentée. Vérifié : `normalize_name('DUPONT') = normalize_name('dupont') = normalize_name('Dupont') = 'dupont'`.
- Reproduction  : `docker exec axion-crm-postgres psql -U axion -d axion_crm -c "SELECT lower('ÉLODIE'), 'ÉLODIE'::citext='élodie'::citext;"`
- Correctif     : (1) poser la contrainte au lieu de la convention — normaliser à l'écriture par une contrainte ou un trigger, ou stocker une colonne générée `email_norm = lower(unaccent(email::text))` et y adosser l'unicité ; (2) pour la recherche par nom, reconstruire `idx_contacts_ws_lower_last_name` sur `normalize_name(last_name)` et faire porter la requête sur la même expression. Coût : ~3 h + une création d'index `CONCURRENTLY` sur 1,3 M de lignes.
- Statut        : ouvert

### [C21-003] Aucune contrainte d'unicité ne protège `contacts.email` : 176 218 doublons de personne en production, soit 42,93 % des contacts joignables

- Sévérité      : S1 grave
- Domaine       : backend / conformité
- Référence     : main 8db8229 ; données production 2026-08-19
- Emplacement   : schéma `public.contacts` — index présents : `contacts_workspace_id_normalized_hash_key` (nom + entreprise) et `contacts_workspace_external_ref_key` ; **aucun sur l'e-mail**
- Constat       : la seule unicité de personne repose sur `normalize_name(prénom_nom) + company_id`, si bien que la même adresse e-mail peut être portée par un nombre illimité de fiches dès que le nom ou l'entreprise diffère.
- Preuve        : `04_PREUVES/agent-21/prod_doublons_contacts.txt` et `prod_casse_detail.txt` :
  - 410 481 contacts portent un e-mail, pour seulement **234 263 e-mails distincts**
  - 49 492 groupes d'e-mail en doublon, 225 710 fiches impliquées, **176 218 surnuméraires** = **42,93 %** des contacts joignables
  - recherche exhaustive des index UNIQUE portant sur un e-mail dans **toute** la base : 15 résultats, **aucun sur `contacts`** (`users`, `password_reset_tokens`, `email_suppressions`, `email_validations`, `email_verification_logs`…)
  - recherche exhaustive des index UNIQUE bâtis sur `lower(...)` : **0 ligne**
  - 23 386 groupes / **46 232 surnuméraires** supplémentaires correspondent à la même personne rattachée à des fiches entreprise distinctes portant la même dénomination normalisée
- Témoin négatif : la même requête d'inventaire d'index remonte bien les 15 unicités e-mail existantes ailleurs dans la base, et `contacts_workspace_id_normalized_hash_key` est bien détecté sur `contacts` — le contrôle voit les contraintes quand il y en a. Corollaire mesuré : le dédoublonnage par nom, lui, **fonctionne** — 0 doublon sur (nom, prénom, `company_id`).
- Impact        : la réponse à la question du mandat — « un index unique sur `lower(email)` protège-t-il encore ? » — est qu'**il n'y en a jamais eu**. La protection n'est pas dégradée par la casse, elle est absente. Conséquences : comptages de personnes faux, une même personne sollicitée jusqu'à plusieurs fois par campagne, et surtout un effacement RGPD art. 17 qui, même corrigé de C21-001, doit balayer *toutes* les fiches d'une adresse — ce que `SiteGdprService::erase()` fait par `delete()` de masse, donc correctement, mais l'export art. 15 rend alors des fiches en double.
- Reproduction  : `docker exec axion-crm-postgres psql -U axion -d axion_crm -c "SELECT count(*), sum(c-1) FROM (SELECT workspace_id, email, count(*) c FROM contacts WHERE email IS NOT NULL GROUP BY 1,2 HAVING count(*)>1) x;"`
- Correctif     : l'unicité stricte est **inapplicable en l'état** — 176 218 lignes la violeraient. Le correctif est en deux temps : (1) une campagne de fusion pilotée par `person_key` (aujourd'hui vide, A05-001) ; (2) une fois le stock assaini, `CREATE UNIQUE INDEX CONCURRENTLY ... ON contacts (workspace_id, email) WHERE email IS NOT NULL AND deleted_at IS NULL`. Coût : la fusion est un chantier, pas un correctif — à chiffrer avec le lot `person_key`.
- Statut        : ouvert

### [C21-004] 82,58 % des `quality_score` stockés contredisent la formule qui est censée les produire

- Sévérité      : S1 grave
- Domaine       : backend
- Référence     : main 8db8229 ; données production 2026-08-19
- Emplacement   : fonction `public.recompute_company_quality_score(bigint)` ; triggers `companies_recompute_score` (AFTER **UPDATE OF** website, phone, linkedin_url, signals) et `contacts_recompute_score` (AFTER INSERT OR UPDATE OF email_status, email_score)
- Constat       : le trigger de `companies` n'écoute que des `UPDATE` de quatre colonnes et **jamais l'`INSERT`**, si bien qu'une fiche créée par import garde le `DEFAULT 0` de la colonne tant qu'aucune de ces quatre colonnes n'est modifiée.
- Preuve        : `04_PREUVES/agent-21/prod_quality_score.txt` et `prod_quality_score_recompute.txt`. J'ai rejoué **en `SELECT` pur** l'intégralité de la formule de la fonction (8 critères + le bonus +20 pour un contact joignable, plafond 100) sur les 4 295 349 lignes, sans aucun `UPDATE`, et comparé au stocké :
  - identiques : **748 363** (17,42 %)
  - **divergents : 3 546 986 (82,58 %)** — dont **3 484 663 sous-évalués** et 62 323 sur-évalués
  - le cas dominant : **3 083 493** fiches stockées à `0` alors que la formule rend `10`, et **377 571** stockées à `0` alors que la formule rend `15`
- Témoin négatif : la même requête classe 748 363 fiches en « identiques », et retrouve exactement les paliers attendus là où le trigger a bien tourné (35→35 sur 345 969 fiches, 45→45 sur 77 870, 25→25 sur 7 610). Le recalcul n'est donc pas systématiquement décalé : il sait rendre l'égalité quand elle existe.
- Impact        : `quality_score` est indexé (`idx_companies_workspace_score`) et sert au tri et au ciblage. Un opérateur qui trie par qualité décroissante voit un classement faux pour 4 fiches sur 5, et 3,08 M de fiches réellement notables à 10 sont enterrées à 0. La colonne générée `quality_badge` en dérive et hérite du défaut.
- Reproduction  : rejouer `04_PREUVES/agent-21/` → fichier `prod_quality_score_recompute.txt`, requête T23 (100 % `SELECT`).
- Correctif     : (1) ajouter `INSERT` au trigger `companies_recompute_score`, ou mieux, remplacer la colonne matérialisée par une colonne **générée** comme l'est déjà `quality_badge` — la formule n'utilise que des colonnes de la ligne, sauf le bonus +20 ; (2) un backfill par lots des 3 546 986 lignes. Coût : ~2 h de code, plus un backfill à cadencer (la table porte 1 491 Mo d'index, B10-014 — un `UPDATE` global les réécrit tous).
- Statut        : ouvert

### [C21-005] Le palier « complete » du badge qualité n'est atteint par aucune des 4 295 349 fiches, et 80,80 % du stock est à zéro

- Sévérité      : S2 défaut
- Domaine       : backend / UX
- Référence     : main 8db8229 ; données production 2026-08-19
- Emplacement   : colonne générée `companies.quality_badge` — `CASE WHEN quality_score >= 90 THEN 'complete' WHEN >= 50 THEN 'partielle' ELSE 'basique' END`
- Constat       : le score stocké ne dépasse jamais 85 et le score maximal que la formule peut produire sur le stock réel est lui aussi 85, si bien que le seuil de 90 n'est franchi par aucune fiche.
- Preuve        : `04_PREUVES/agent-21/prod_quality_score.txt` et `prod_quality_score_recompute.txt` :
  - distribution : **0 → 3 470 574 (80,7984 %)**, 35 → 345 969 (8,05 %), 80 → 196 368 (4,57 %), 45 → 77 870, 70 → 78 869, … 16 valeurs distinctes en tout
  - `min = 0`, `max = 85`, **fiches à ≥ 90 : 0**
  - `max(theorique)` recalculé sur les 4 295 349 lignes : **85**
  - badges : `basique` 3 967 446 (92,37 %) / `partielle` 327 903 (7,63 %) / **`complete` 0**
- Témoin négatif : la même requête de distribution rend bien les deux autres paliers avec des effectifs non nuls, et distingue 16 valeurs de score — elle n'est pas aveugle au haut du barème (elle voit les 24 730 fiches à 85).
- Impact        : un des trois états de l'indicateur qualité présenté à l'opérateur est **inatteignable en pratique**, et la moitié basse est écrasée sur une seule valeur par C21-004. L'indicateur ne discrimine pas ce qu'il est censé discriminer.
- Reproduction  : `docker exec axion-crm-postgres psql -U axion -d axion_crm -c "SELECT quality_badge, count(*) FROM companies GROUP BY 1;"`
- Correctif     : corriger d'abord C21-004 — sans quoi tout ré-étalonnage porterait sur des valeurs fausses — puis re-mesurer la distribution et fixer les seuils sur des quantiles observés plutôt que sur des constantes. Coût : ~1 h une fois C21-004 réglé.
- Statut        : ouvert

### [C21-006] 909 086 personnes (68,89 %) sont enregistrées sans aucun moyen de contact, et les 1 319 567 sont sans base légale renseignée

- Sévérité      : S1 grave
- Domaine       : conformité
- Référence     : main 8db8229 ; données production 2026-08-19
- Emplacement   : table `public.contacts` — colonnes `email`, `phone`, `linkedin_url`, `legal_basis`
- Constat       : deux tiers des fiches personne ne portent ni e-mail, ni téléphone, ni URL LinkedIn, et la colonne `legal_basis` prévue par la taxonomie n'est renseignée sur aucune ligne.
- Preuve        : `04_PREUVES/agent-21/prod_divers.txt` et `prod_completude.txt` :
  - `phone` renseigné : **0 / 1 319 567**. `title` : **0**. `linkedin_url` : **0**.
  - sans aucun canal (ni e-mail, ni téléphone, ni LinkedIn) : **909 086** = **68,89 %**
  - `email_status` : `unknown` sur 410 481, **NULL sur les 909 086 autres** — aucune n'est `valid`
  - `legal_basis` : **NULL sur 1 319 567 lignes (100 %)**, alors que la colonne existe et que le CHECK admet `legitimate_interest_b2b`
  - `person_key` : **0** (confirme A05-001) ; `lifecycle_stage` des 4 295 349 entreprises : **`nouveau` à 100 %** (confirme A05-004)
- Témoin négatif : la même requête compte correctement les 410 481 e-mails et les 857 917 prénoms renseignés — elle sait distinguer une colonne peuplée d'une colonne vide. Le zéro sur `phone` n'est pas un artefact de méthode.
- Impact        : 909 086 personnes physiques identifiées (nom, prénom, employeur) sont conservées **sans finalité atteignable** — on ne peut ni les contacter, ni les exclure, ni les retrouver, `person_key` étant vide. C'est la matière même de **C19-007** (intérêt légitime invoqué pour 1 319 567 personnes sans mise en balance écrite) : la mesure ci-dessus montre que pour **68,89 %** d'entre elles, le plateau « intérêt » de la balance est **vide par construction**. `legal_basis` à 100 % NULL prive en outre le registre de toute traçabilité par fiche.
- Reproduction  : `docker exec axion-crm-postgres psql -U axion -d axion_crm -c "SELECT count(*) FILTER (WHERE (email IS NULL OR email::text='') AND (phone IS NULL OR phone='') AND (linkedin_url IS NULL OR linkedin_url='')), count(*) FROM contacts;"`
- Correctif     : décision métier avant code. Soit purger les fiches sans canal (minimisation, art. 5.1.c), soit documenter la finalité de leur conservation dans l'AIPD 2.0. Puis backfiller `legal_basis = 'legitimate_interest_b2b'` sur le stock scrapé et rendre la colonne NOT NULL à l'écriture. Coût : la décision est un arbitrage dirigeant ; le backfill ~2 h.
- Statut        : ouvert

### [C21-007] Doublons d'entreprise : 64 523 fiches surnuméraires par nom + ville, 162 025 par téléphone

- Sévérité      : S2 défaut
- Domaine       : backend
- Référence     : main 8db8229 ; données production 2026-08-19
- Emplacement   : table `public.companies` — unicité posée sur `(workspace_id, siren)` uniquement
- Constat       : l'ancre d'identité est le SIREN, si bien que deux établissements ou deux immatriculations distinctes du même acteur cohabitent comme deux fiches sans qu'aucune contrainte ni aucun signalement ne le relève.
- Preuve        : `04_PREUVES/agent-21/prod_doublons_companies.txt` :
  - même SIREN : **0 groupe** — l'unicité déclarée tient parfaitement
  - même `denomination_normalized` + ville : **38 451 groupes, 102 974 fiches, 64 523 surnuméraires** (1,50 % du stock)
  - même téléphone : **31 885 groupes, 193 910 fiches, 162 025 surnuméraires** (3,77 % du stock ; 50,1 % des 323 678 fiches ayant un téléphone)
- Témoin négatif : la même requête, appliquée au SIREN, rend **0** — elle sait donc rendre zéro quand l'unicité est réellement garantie, et son 38 451 sur la dénomination n'est pas un défaut de regroupement.
- Impact        : la fiche 360° d'un acteur est éclatée. Effet mesuré en aval : **46 232** contacts surnuméraires proviennent de la même personne rattachée à des fiches entreprise en double (C21-003, mesure D9) — le doublon d'entreprise **fabrique** du doublon de personne, puisque `normalized_hash` inclut `company_id`.
- Nuance à ne pas surinterpréter : un téléphone partagé est légitime entre un siège et ses établissements, ou au sein d'un groupe. **Le chiffre de 162 025 est une borne haute**, pas un décompte de fiches à fusionner. Le chiffre robuste est celui du nom + ville : 64 523.
- Reproduction  : `docker exec axion-crm-postgres psql -U axion -d axion_crm -c "SELECT count(*), sum(c-1) FROM (SELECT workspace_id, denomination_normalized, lower(coalesce(city_name,city,'')) v, count(*) c FROM companies WHERE denomination_normalized IS NOT NULL AND denomination_normalized<>'' GROUP BY 1,2,3 HAVING count(*)>1) d;"`
- Correctif     : ne pas poser de contrainte — elle serait fausse. Exposer un écran de fusion assisté, alimenté par l'index `idx_companies_denom_btree` qui existe déjà et couvre exactement `(workspace_id, denomination_normalized)`. Coût : chantier d'écran, ~3 j.
- Statut        : ouvert

### [C21-008] Le type de relation n'est porté par aucune personne : 1 319 567 contacts sans colonne de type, et 4 295 349 entreprises toutes à `prospect`

- Sévérité      : S1 grave
- Domaine       : backend / conformité au CDC
- Référence     : main 8db8229 ; données production 2026-08-19
- Emplacement   : `backend/app/Crm/Taxonomy.php:35` (`BUSINESS_RELATION_TYPES`) et `:53` (`BUSINESS_RELATION_PRIORITY`) ; `backend/app/Crm/Ingest/SiteSyncClassifier.php:180` (`mergeRelationType`) ; schéma `public.contacts`
- Constat       : le type est une colonne de `companies` et de `candidates`, l'arbitrage `mergeRelationType()` retourne un type unique, et `conference` comme `newsletter` figurent parmi les types alors que le CDC en fait des motifs.
- Preuve        : trois vérifications indépendantes, **contre-vérifiées et non recopiées** :
  - (a) `information_schema.columns` sur `contacts`, colonnes `relation_type|type|contact_type|lifecycle_stage` : **0 résultat**. La même requête sur `candidates` rend **1** (`relation_type`). Le contrôle voit donc la colonne là où elle est. → `04_PREUVES/agent-21/prod_type_contact.txt`
  - (b) `SiteSyncClassifier.php:180-196` : `mergeRelationType(?string $current, string $incoming): string` — signature de retour **scalaire**, arbitrée par le rang dans `BUSINESS_RELATION_PRIORITY`. Une fiche ne peut porter qu'un type, « le plus engageant ». Le §2.2 du plan, remis au périmètre par le dirigeant le 19/08, demande le multi-types.
  - (c) `conference` et `newsletter` sont présents dans `BUSINESS_RELATION_TYPES` **et** dans le CHECK réellement posé en base : `companies_relation_type_check CHECK (relation_type = ANY (ARRAY['prospect','client','presse_media','partenaire','investisseur','conference','newsletter','fournisseur']))`.
  - **Portée en données** : `relation_type` = `prospect` sur **4 295 349 / 4 295 349 (100,0000 %)**. Fiches en `conference` ou `newsletter` : **0**. Fiches portant ≥ 2 tags impliquant un type : **0**. `candidates` : **0** ligne.
- Témoin négatif : la requête de distribution sur `relation_type` s'applique sans filtre et rendrait chaque valeur présente ; elle en rend une seule parce qu'il n'y en a qu'une. Contrôle croisé : la même forme de requête sur `country_code`/`entity_nature` rend bien 8 combinaisons distinctes — elle sait produire plusieurs lignes.
- Impact        : le reclassement de type porte sur **1 319 567 fiches personne** — non parce que leur valeur serait à corriger, mais parce qu'aucune ne peut porter de valeur. Combiné à `contacts.company_id NOT NULL` et `last_name NOT NULL` (I48-003), une personne ne peut exister ni sans société, ni sans nom, ni avec une qualification propre. Les contradictions (b) et (c) sont en revanche **latentes** : 0 fiche à requalifier aujourd'hui.
- Reproduction  : `docker exec axion-crm-postgres psql -U axion -d axion_crm -c "SELECT relation_type, count(*) FROM companies GROUP BY 1;"` puis `grep -n "BUSINESS_RELATION_PRIORITY" backend/app/Crm/Taxonomy.php backend/app/Crm/Ingest/SiteSyncClassifier.php`
- Correctif     : arbitrage dirigeant requis avant tout code — le multi-types du §2.2 et le mono-type de `BUSINESS_RELATION_PRIORITY` sont exclusifs. Le coût du multi-types (table de liaison `contact_relation_type`, reprise des écrans, reprise de `mergeRelationType`) est un lot, pas un correctif. Le coût de (c) est nul aujourd'hui : 0 ligne à migrer, seulement le CHECK et deux constantes à réduire.
- Statut        : ouvert

### [C21-009] Résidus : un nom doublement encodé, 59 tags orphelins sur 217

- Sévérité      : S3 finition
- Domaine       : backend
- Référence     : main 8db8229 ; données production 2026-08-19
- Emplacement   : `contacts.id = 265192` ; table `public.tags`
- Constat       : une fiche personne porte un nom mojibaké et 27 % des tags déclarés ne sont rattachés à aucune fiche.
- Preuve        : `04_PREUVES/agent-21/prod_encodage.txt` et `prod_tags.txt` / `prod_tags2.txt` :
  - `contacts.id=265192`, `last_name = 'BÃ¿HLER (BÃ¿HLER)'`, octets `42 c383 c2bf 48 4c 45 52 …` — séquence `C3 83 C2 BF` caractéristique d'un `Ü` encodé deux fois. **1 seule occurrence sur 1 319 567.**
  - `companies` : **0** double encodage sur 4 295 349 dénominations, 0 sur les adresses, 0 sur `city_name`, 0 sur `enseigne`
  - caractère de remplacement U+FFFD : **0** partout ; entités HTML non décodées : **0** partout
  - tags sans aucune fiche : **59 / 217 (27,2 %)**, dont 36 des 38 tags `src:`
- Témoin négatif : **les trois motifs ont été validés positivement et négativement avant usage** (`prod_encodage.txt`, bloc T11) : le motif de double encodage reconnaît `chr(195)||chr(169)` et rejette `'Elodie'` ; le motif U+FFFD reconnaît une chaîne qui en contient un et rejette `'role'` ; le motif d'entités reconnaît `'&amp;'` et rejette `'Durand & Fils'`. Les zéros ci-dessus sont donc des zéros mesurés, pas des zéros d'aveuglement.
- Impact        : marginal. La fiche 265192 est mal orthographiée ; les 59 tags orphelins encombrent les sélecteurs.
- Reproduction  : `docker exec axion-crm-postgres psql -U axion -d axion_crm -c "SELECT id, last_name FROM contacts WHERE id=265192;"`
- Correctif     : correction manuelle de la fiche ; les tags orphelins sont un référentiel pré-déclaré et **ne doivent pas être purgés** — ils attendent leurs sources. Coût : 10 min.
- Statut        : ouvert

---

## 3 bis. Vérifications qui ne débouchent sur aucun constat — et c'est le résultat

Ces points ont été mesurés et sont **conformes**. Les consigner évite qu'un agent suivant les redécouvre.

1. **CI ↔ production sur la locale** : les deux en `C`/`C`. Le piège 10 réécrit est confirmé des deux côtés. **Aucun correctif d'alignement de locale n'a d'objet.**
2. **Déduplication par nom** : `UNIQUE(workspace_id, normalized_hash)` tient — **0 doublon** sur (nom, prénom, entreprise). `normalize_name` replie casse **et** accents (`unaccent`), et `Dupont`/`DUPONT`/`dupont` sont bien confondus (528 + 453 occurrences réelles). `Dupond` ne l'est pas, ce qui est correct pour une dédup exacte.
3. **Pas de divergence SQL ↔ PHP sur le hash de dédup** : le PHP fait calculer `normalize_name` **par Postgres** au lieu de le réimplémenter (`ContactUpserter.php:177`, `ScrapedRecordIngestService.php:661`). C'est la bonne façon de faire, et elle est commentée comme telle.
4. **Unicité du SIREN** : 0 doublon. **Qualité du SIREN : 4 294 903 / 4 294 903 passent la clé de Luhn (100 %)** — témoin validé sur `552081317` (valide) et `552081318` (invalide).
5. **`companies_entites_sans_siren` est cohérente** : 446 fiches sans SIREN, **446 avec `foreign_id`, 0 sans ancre d'identité**, 446 avec `entity_nature` renseignée, toutes en `country_code = RO` (352 entreprises, 57 associations, 27 cabinets, 6 enseignement, 2 institutions, 1 média, 1 CCI). Le `CHECK companies_identity_anchor_check` est posé et **validé** en base. La migration fait exactement ce qu'elle annonce, et la conception (ADD COLUMN DEFAULT metadata-only, CHECK NOT VALID puis VALIDATE, index unique CONCURRENTLY dans la migration suivante) est adaptée aux 4,29 M de lignes.
6. **L'annonce du backfill `src:` est exacte** : `src:scraping-insee` porte **4 294 895** fiches, chiffre annoncé au caractère près. Au total **4 295 346 / 4 295 349** fiches portent un tag `src:` ; **3** n'en portent aucun.
7. **Le doublon de slug `src:site-formulaire-autre` n'est PAS un défaut** : les deux lignes (id 158 et 204) appartiennent à **deux espaces différents** (`Axion-IA` et `Vivier candidats`), ce que la contrainte `UNIQUE(workspace_id, slug)` autorise et doit autoriser. Je l'avais relevé comme suspect, la contre-mesure le réfute.
8. **Encodage : la base est propre.** 0 U+FFFD, 0 entité HTML, 1 seule séquence de double encodage sur 5,6 M de lignes examinées — avec les trois motifs validés positivement et négativement.

---

## 4. Ce que je n'ai PAS pu vérifier, et pourquoi

1. **La déduplication `candidates`** — la table contient **0 ligne** en production. Aucune mesure de doublon, de casse ou de complétude n'y est possible. Je ne substitue pas une mesure synthétique : le verdict est **non mesurable**, pas « conforme ».
2. **Le comportement réel de `SiteGdprService::erase()` face à un e-mail à majuscule accentuée** — le vérifier exigerait d'insérer une ligne témoin en production. **Interdit par le mandat.** J'ai donc mesuré le mécanisme (C21-002, témoins SQL purs) et l'exposition (0 e-mail non-ASCII sur 410 481), sans jamais présenter l'un pour l'autre. Reproduire ce cas relève d'une base jetable, hors de ma consigne d'écriture.
3. **Le nombre de fiches entreprise réellement à fusionner** — les 64 523 et 162 025 de C21-007 sont des **bornes hautes**. Trancher exigerait un référentiel externe (Sirene établissements) ou un arbitrage humain fiche à fiche.
4. **`companies` sans aucun tag, au premier essai** — la requête a échoué en production sur `could not resize shared memory segment … No space left on device` (mémoire partagée de l'exécution parallèle). **Rejouée avec `max_parallel_workers_per_gather = 0`** et aboutie : 3 fiches. Le chiffre est bon ; **l'incident de mémoire partagée sous parallélisme, lui, n'a pas été investigué** — il sort de mon périmètre mais mérite d'être signalé au domaine infrastructure.
5. **La complétude « plausible » de l'adresse** — mon critère (contient un chiffre ET longueur ≥ 8) est une heuristique, pas une validation postale. Les 88,38 % annoncés en 4c sont à lire comme « ne ressemble pas à une adresse tronquée », pas comme « adresse livrable ».
6. **L'effet de C21-001 sur la latence perçue en charge** — mesuré à **un seul utilisateur** (1 070 ms). Conformément au §5 bis point 0 du dossier commun, je ne déduis pas le comportement en charge ; je note seulement que A-010 sérialise les requêtes et que le raisonnement de composition est donc à faire par l'agent performance.
7. **La préproduction** (`staging`) — non mesurée, hors mandat. Les conteneurs `axion-crm-staging-*` tournent sur le même hôte.
