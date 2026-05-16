# 12 — COVERAGE MATRIX + DÉDUPLICATION

## Vue d'ensemble

Le **Coverage Matrix** est le tableau de bord business le plus important d'Axion CRM Pro. Il répond instantanément à des questions du type :
- "Combien d'entreprises ETI 250-2000 avec maturité IA 'en cours' en Île-de-France sont déjà enrichies ?"
- "Quelle est ma couverture sur le secteur NAF 'Activités informatiques' à Lyon ?"
- "Quelle est la prochaine zone × secteur où j'aurais le meilleur ROI à scraper ?"

Couplée à un système de **déduplication 6 niveaux**, elle garantit qu'on ne dépense jamais 2 fois pour scraper la même donnée et qu'on dirige toujours nos efforts là où le ROI est maximal.

---

## 1. Modèle multi-dimensionnel (10 dimensions)

Chaque entreprise scrapée est positionnée dans un espace 10-dimensionnel :

| # | Dimension | Type | Cardinalité approx |
|---|---|---|---|
| 1 | Type d'entité | enum | 5 (entreprise / école / association / collectivité / indépendant) |
| 2 | Taille (tier) | enum INSEE | 4 (TPE / PME / ETI / GE) |
| 3 | Forme juridique | FK `legal_forms` | ~80 (SARL, SAS, SA, SCI, EI, EURL, …) |
| 4 | Secteur NAF (5 niveaux) | FK `naf_*` | 21 sections / 88 div / 272 grp / 615 cl / 732 subcl |
| 5 | Secteur métier custom Axion-IA | tags | ~40 |
| 6 | Géographie (Région / Dpt / Ville) | FK `regions/departments/cities` | 13 / 101 / 2 157 |
| 7 | Maturité IA estimée | enum | 4 (decouverte / en_cours / avancee / inconnue) |
| 8 | Offre Axion-IA recommandée + score | enum | 6 (audit_flash, audit_cible, mission_pme, mission_eti, grand_programme, non_cible) |
| 9 | Priorité de contact | enum | 4 (hot / warm / cold / frozen) |
| 10 | Statut prospection | enum | 7 (decouvert / enrichi / qualifie / contacte / repondu / client / disqualifie) |

**Cardinalité totale combinatoire** : ~1,2 × 10^12. Impensable à pré-calculer. D'où l'usage de la **materialized view** rafraîchie hourly.

---

## 2. SQL Materialized View `coverage_matrix_cells`

Définition complète dans le fichier 03 (`03_db_schema_phase1.md`). Récapitulatif :

```sql
CREATE MATERIALIZED VIEW coverage_matrix_cells AS
SELECT
  c.workspace_id,
  r.id   AS region_id,
  d.id   AS department_id,
  ci.id  AS city_id,
  ns.id  AS naf_section_id,
  nd.id  AS naf_division_id,
  er.tier AS tier,
  c.ia_maturity,
  c.axion_offer,
  c.priority_score,
  c.prospection_status,
  COUNT(*)                                                 AS total_companies,
  COUNT(*) FILTER (WHERE c.last_enriched_at IS NOT NULL)   AS enriched_companies,
  COUNT(*) FILTER (WHERE c.enrichment_score >= 80)         AS richly_enriched_companies,
  MIN(c.last_enriched_at)                                  AS earliest_enriched_at,
  MAX(c.last_enriched_at)                                  AS latest_enriched_at
FROM companies c
LEFT JOIN cities       ci ON ci.id = c.city_id
LEFT JOIN departments  d  ON d.id  = ci.department_id
LEFT JOIN regions      r  ON r.id  = d.region_id
LEFT JOIN naf_subclasses nsc ON nsc.id = c.naf_subclass_id
LEFT JOIN naf_classes  nc ON nc.id = nsc.class_id
LEFT JOIN naf_groups   ng ON ng.id = nc.group_id
LEFT JOIN naf_divisions nd ON nd.id = ng.division_id
LEFT JOIN naf_sections ns ON ns.id = nd.section_id
LEFT JOIN effectif_ranges er ON er.id = c.effectif_range_id
WHERE c.deleted_at IS NULL
GROUP BY 1,2,3,4,5,6,7,8,9,10,11;
```

### Refresh strategy

```sql
-- Refresh CONCURRENTLY pour ne pas bloquer les lectures pendant le refresh
REFRESH MATERIALIZED VIEW CONCURRENTLY coverage_matrix_cells;
```

Job `RefreshCoverageMatrixJob` :
- Tous les jours à 03:30 (full refresh complet)
- Toutes les heures pour incrémental (~5 min de durée à 200 k companies)
- Sur demande : trigger manuel admin "Refresh matrix" (bouton sur page Coverage)
- Auto-trigger : après bulk import de companies dépassant seuil 1000 nouvelles lignes

Index UNIQUE obligatoire pour permettre `REFRESH CONCURRENTLY` :
```sql
CREATE UNIQUE INDEX coverage_matrix_cells_uk ON coverage_matrix_cells (workspace_id, region_id, department_id, city_id, naf_section_id, naf_division_id, tier, ia_maturity, axion_offer, priority_score, prospection_status);
```

---

## 3. Service Laravel `CoverageMatrixService`

```php
namespace App\Modules\Coverage;

use Illuminate\Support\Collection;

final class CoverageMatrixService
{
    public function __construct(private CoverageMatrixRepository $repo) {}

    /**
     * Agrège la matrix selon les filtres demandés.
     * @param array $filters ex: ['region_id' => 11, 'tier' => 'PME', 'axion_offer' => 'mission_pme']
     * @param string $groupBy ex: 'region_id', 'department_id', 'naf_section_id'
     */
    public function aggregate(int $workspaceId, array $filters, string $groupBy): Collection
    {
        return $this->repo->aggregateBy($workspaceId, $filters, $groupBy);
    }

    public function summary(int $workspaceId, ?array $filters = null): CoverageSummary
    {
        $rows = $this->repo->aggregateBy($workspaceId, $filters ?? [], 'tier');
        return new CoverageSummary(
            totalCompanies: $rows->sum('total_companies'),
            enrichedCompanies: $rows->sum('enriched_companies'),
            coveragePct: $this->pct($rows->sum('enriched_companies'), $rows->sum('total_companies')),
            byTier: $rows->keyBy('tier')->map(fn ($r) => $r['enriched_companies'])->all(),
        );
    }

    private function pct(int $part, int $total): float
    {
        return $total === 0 ? 0.0 : round($part / $total * 100, 2);
    }
}
```

---

## 4. Algorithme de déduplication 6 niveaux

Doctrine : **avant chaque action coûteuse, vérifier qu'on n'a pas déjà fait l'équivalent**. Économies cibles : ne pas re-scraper LinkedIn 2× le même profil, ne pas valider SMTP 2× le même email en moins de 30j, ne pas re-payer un proxy résidentiel pour visiter la même page Google Maps.

### Niveau 1 — Déduplication par SIREN

```php
final class CompanyDeduplicator
{
    public function findExisting(string $siren, int $workspaceId): ?Company
    {
        return Company::query()
            ->where('workspace_id', $workspaceId)
            ->where('siren', $siren)
            ->first();
    }
}
```

`companies.siren` est UNIQUE → INSERT sur conflit = no-op + retour de l'existant. Garantie au niveau DB.

### Niveau 2 — Déduplication par contact (company_id + full_name normalisé)

Index UNIQUE :
```sql
CREATE UNIQUE INDEX contacts_company_fullname_idx
  ON contacts (company_id, LOWER(unaccent(COALESCE(full_name,''))))
  WHERE deleted_at IS NULL;
```

UPSERT atomique :
```php
Contact::updateOrCreate(
    ['workspace_id' => $ws, 'company_id' => $companyId, 'full_name_norm' => $norm],
    ['first_name' => $first, 'last_name' => $last, 'position_title' => $title, /* ... */]
);
```

### Niveau 3 — Déduplication des scraping runs

Champ `scraper_targets.fingerprint` = sha256(`target_type` + `target_payload` + `ttl_bucket`).

```php
final class ScrapeRunDeduplicator
{
    /** Empêche de relancer un scraping si même target dans TTL */
    public function shouldSkip(string $sourceKey, array $payload, int $workspaceId): bool
    {
        $ttlDays = ScrapingSource::where('source_key', $sourceKey)->value('ttl_days') ?? 30;
        $bucket = floor(time() / ($ttlDays * 86400));
        $fingerprint = hash('sha256', json_encode([
            'source' => $sourceKey,
            'payload' => $payload,
            'bucket' => $bucket,
        ]));
        return ScraperTarget::query()
            ->where('workspace_id', $workspaceId)
            ->where('source_key', $sourceKey)
            ->where('fingerprint', $fingerprint)
            ->whereIn('state', ['done', 'running', 'pending'])
            ->exists();
    }
}
```

### Niveau 4 — Déduplication coverage cells

La materialized view dédupliquée par essence (GROUP BY). Si une entreprise change de NAF entre 2 enrichissements, c'est l'état actuel qui prévaut.

### Niveau 5 — Fuzzy matching pg_trgm seuil 0.85+

Pour détecter les doublons "presque identiques" (ex: `SARL Dupont` vs `Dupont SARL`).

```sql
-- Trouver les doublons potentiels d'une entreprise
SELECT id, legal_name,
       similarity(LOWER(unaccent(legal_name)), LOWER(unaccent($1))) AS sim
FROM companies
WHERE workspace_id = $2
  AND id != $3
  AND city_id = $4
  AND similarity(LOWER(unaccent(legal_name)), LOWER(unaccent($1))) >= 0.85
ORDER BY sim DESC
LIMIT 5;
```

Index requis :
```sql
CREATE INDEX companies_legal_name_trgm_idx ON companies USING GIN (legal_name gin_trgm_ops);
```

Service :
```php
final class FuzzyDuplicateDetector
{
    public function findCandidates(Company $c): array
    {
        return DB::select("
            SELECT id, legal_name, similarity(LOWER(unaccent(legal_name)), LOWER(unaccent(?))) AS sim
            FROM companies
            WHERE workspace_id = ?
              AND id != ?
              AND city_id = ?
              AND similarity(LOWER(unaccent(legal_name)), LOWER(unaccent(?))) >= 0.85
            ORDER BY sim DESC
            LIMIT 5
        ", [$c->legal_name, $c->workspace_id, $c->id, $c->city_id, $c->legal_name]);
    }

    public function flag(Company $current, array $candidates): void
    {
        foreach ($candidates as $cand) {
            DuplicateFlag::firstOrCreate([
                'workspace_id' => $current->workspace_id,
                'entity_type' => 'company',
                'entity_id' => $current->id,
                'duplicate_of_entity_id' => $cand->id,
            ], [
                'similarity_score' => $cand->sim,
                'rationale' => "Fuzzy match legal_name @ {$cand->sim}",
            ]);
        }
    }
}
```

L'opérateur résout les flags depuis la page "Doublons potentiels" de l'admin.

### Niveau 6 — Opt-out cross-workspace

Avant tout scraping ou enrichissement, vérifier que le sujet n'est pas dans `opt_out` (qui est GLOBAL, non RLS) :

```php
final class OptOutGuard
{
    public function isOptedOut(string $email, ?string $domain, ?string $phone): bool
    {
        return OptOut::query()
            ->where(function ($q) use ($email, $domain, $phone) {
                if ($email)  $q->orWhere('email', $email);
                if ($domain) $q->orWhere('domain', $domain);
                if ($phone)  $q->orWhere('phone_e164', $phone);
            })
            ->where(function ($q) { $q->whereNull('expires_at')->orWhere('expires_at', '>', now()); })
            ->exists();
    }
}
```

Appelé au niveau de :
- Email Finder (avant cascade SMTP — skip si email opt-out)
- Cold Email Phase 2 (avant envoi — skip)
- LinkedIn Outreach Phase 2 (avant message)

---

## 5. Algorithme "prochaine zone à attaquer"

Pour aider Will à prioriser, on calcule un score pour chaque cellule (zone × secteur) :

```php
final class NextZoneRecommender
{
    /**
     * priority_score = (target_match × 0.4) + (business_value × 0.3) + (low_coverage × 0.2) + (freshness_decay × 0.1)
     */
    public function recommendTop(int $workspaceId, int $limit = 10): array
    {
        $cells = DB::select("
            WITH cells AS (
              SELECT
                region_id, department_id, naf_division_id, tier, axion_offer,
                SUM(total_companies)    AS total,
                SUM(enriched_companies) AS enriched,
                MAX(latest_enriched_at) AS last_enriched
              FROM coverage_matrix_cells
              WHERE workspace_id = ?
                AND axion_offer IS NOT NULL AND axion_offer <> 'non_cible'
              GROUP BY region_id, department_id, naf_division_id, tier, axion_offer
            )
            SELECT
              region_id, department_id, naf_division_id, tier, axion_offer, total, enriched,
              -- target_match : entreprises qui matchent une offre Axion-IA
              CASE
                WHEN axion_offer IN ('mission_eti','grand_programme') THEN 1.0
                WHEN axion_offer IN ('mission_pme','audit_cible') THEN 0.85
                ELSE 0.6
              END AS target_match,
              -- business_value : valeur business cellule (taille × offre)
              CASE tier
                WHEN 'ETI' THEN 1.0
                WHEN 'GE'  THEN 0.95
                WHEN 'PME' THEN 0.7
                WHEN 'TPE' THEN 0.4
                ELSE 0.3
              END AS business_value,
              -- low_coverage : score plus haut si la zone est sous-explorée
              GREATEST(0, 1 - (enriched::float / NULLIF(total,0))) AS low_coverage,
              -- freshness_decay : zones non rafraîchies depuis longtemps
              CASE
                WHEN last_enriched IS NULL THEN 1.0
                WHEN last_enriched < NOW() - INTERVAL '90 days' THEN 0.8
                WHEN last_enriched < NOW() - INTERVAL '30 days' THEN 0.4
                ELSE 0.1
              END AS freshness_decay
            FROM cells
            WHERE total > 5
        ", [$workspaceId]);

        return collect($cells)->map(function ($cell) {
            $score = ($cell->target_match * 0.4)
                   + ($cell->business_value * 0.3)
                   + ($cell->low_coverage * 0.2)
                   + ($cell->freshness_decay * 0.1);
            return [...((array) $cell), 'priority_score' => round($score, 4)];
        })->sortByDesc('priority_score')->take($limit)->values()->all();
    }
}
```

Le résultat est exposé dans l'admin dashboard sous "Recommandations — Prochaines zones à attaquer" avec un bouton "Créer target_zone".

---

## 6. Index PostgreSQL critiques

```sql
-- Pour le coverage matrix
CREATE INDEX coverage_matrix_cells_ws_idx ON coverage_matrix_cells (workspace_id);
CREATE INDEX coverage_matrix_cells_region_idx ON coverage_matrix_cells (workspace_id, region_id);
CREATE INDEX coverage_matrix_cells_dept_idx ON coverage_matrix_cells (workspace_id, department_id);
CREATE INDEX coverage_matrix_cells_naf_idx ON coverage_matrix_cells (workspace_id, naf_section_id);
CREATE INDEX coverage_matrix_cells_offer_idx ON coverage_matrix_cells (workspace_id, axion_offer);
CREATE INDEX coverage_matrix_cells_priority_idx ON coverage_matrix_cells (workspace_id, priority_score);

-- Pour la déduplication fuzzy
CREATE INDEX companies_legal_name_trgm_idx ON companies USING GIN (legal_name gin_trgm_ops);
CREATE INDEX contacts_name_trgm_idx        ON contacts  USING GIN (full_name gin_trgm_ops);

-- Pour duplicate_flags non résolus
CREATE INDEX duplicate_flags_unresolved_idx ON duplicate_flags (workspace_id, entity_type) WHERE resolved = FALSE;
```

---

## 7. Performance attendue

| Opération | Volume cible | Latence cible |
|---|---|---|
| Aggregate matrix par région (13 cellules) | 200 k entreprises | < 30 ms |
| Aggregate matrix par département (101) | 200 k entreprises | < 80 ms |
| Aggregate matrix par ville top 100 | 200 k entreprises | < 200 ms |
| Recommander top 10 zones à attaquer | 200 k entreprises | < 500 ms |
| Fuzzy match doublons sur 1 nouvelle entreprise | 200 k entreprises | < 50 ms |
| Refresh CONCURRENTLY full | 200 k entreprises | < 5 min |
| Refresh CONCURRENTLY full | 1 M entreprises | < 25 min |

---

## 8. Vue admin "Doublons potentiels"

Page dédiée listant les `duplicate_flags WHERE resolved = false`, avec :
- Table : entité A | entité B | similarité | détecté le
- Bouton "Fusionner" → modal de fusion (sélectionne champs gagnants par colonne)
- Bouton "Marquer non doublon" → set `resolved = TRUE` sans fusion
- Filtre par type d'entité (company / contact)

---

## 9. Tests d'acceptance (S3 + S9)

- [ ] Materialized view se refresh sans erreur sur 200 k companies en < 5 min
- [ ] Dedup niveau 1 (SIREN) garantit zéro doublon strict
- [ ] Dedup niveau 5 (fuzzy 0.85+) détecte au moins 90 % des doublons connus dans dataset test
- [ ] Algo "prochaine zone à attaquer" recommande Top 10 cohérent (validation manuelle)
- [ ] Opt-out cross-workspace bloque tout scraping/enrichissement du sujet en < 50 ms
- [ ] Vue admin "Doublons potentiels" affiche les flags + fusion fonctionne

---

## 10. Anti-patterns interdits

- ❌ `REFRESH MATERIALIZED VIEW` (sans CONCURRENTLY) en heures ouvrées (bloque les lectures)
- ❌ Re-créer la matrix sur SELECT live à chaque appel API (latence × 50)
- ❌ Ignorer le seuil `similarity >= 0.85` (génère des faux positifs en cascade)
- ❌ Stocker des entreprises sans `siren` ni équivalent unique (perte dédup niveau 1)
- ❌ Oublier opt-out sur le module Phase 2 cold email (= incident RGPD)

---

## Prochaine étape

→ Lire `13_ui_admin_phase1.md` pour les 22 pages de la console admin.
