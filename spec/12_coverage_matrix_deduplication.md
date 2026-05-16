# 12 — Coverage Matrix + déduplication 6 niveaux

> **2 objectifs :**
> 1. Visualiser et piloter la couverture (où on a déjà scrapé, où il reste à faire).
> 2. Garantir qu'aucune donnée n'est scrapée ou payée deux fois (économie ~50-100 €/mois).

---

## §1 — Coverage Matrix (materialized view)

### Définition

Cf. `03_db_schema_phase1.md` § 10 : `coverage_matrix_cells` est une `MATERIALIZED VIEW` agrégeant `companies` par (department × naf_subclass × size_category).

### Refresh

```sql
SELECT cron.schedule('coverage_matrix_refresh', '5 * * * *', $$
    REFRESH MATERIALIZED VIEW CONCURRENTLY coverage_matrix_cells
$$);
```

Refresh hourly (`*:05`). `CONCURRENTLY` évite verrou.

### Algorithme "prochaine zone à attaquer"

Priorité combinée :

```
priority = match × valeur × low_coverage × freshness_decay × random_jitter
```

- **`match`** (0-1) : taille_category dans `axion_offer_targets.target_size_min..max` ? × NAF dans `axion_offer_targets.naf_sections_in/subclasses_in` ?
- **`valeur`** (0-1) : `axion_offer_targets.score_weight` normalisé
- **`low_coverage`** (0-1) : `1 - enriched_count / companies_count`
- **`freshness_decay`** (0-1) : `1 - exp(-days_since_last_enrichment / 30)`
- **`random_jitter`** (0.9-1.1) : éviter pattern parfaitement prévisible

```sql
-- app/Services/Coverage/NextZoneSelector.php
WITH zones_scored AS (
    SELECT
        cmc.department_code,
        cmc.naf_subclass_code,
        cmc.size_category,
        cmc.companies_count,
        cmc.enriched_count,
        cmc.last_enriched_at,
        (1.0 - LEAST(1.0, cmc.enriched_count::float / NULLIF(cmc.companies_count, 0))) AS low_coverage,
        EXP(-EXTRACT(EPOCH FROM (now() - COALESCE(cmc.last_enriched_at, '2020-01-01'))) / (86400 * 30.0)) AS freshness_decay,
        COALESCE((
            SELECT aot.score_weight
            FROM axion_offer_targets aot
            WHERE aot.workspace_id = :ws
              AND aot.is_active = true
              AND (aot.target_size_max IS NULL OR cmc.size_category = ANY(string_to_array(aot.target_size_min || ',' || aot.target_size_max, ',')))
            ORDER BY aot.score_weight DESC LIMIT 1
        ), 1.0) AS value_weight,
        (0.9 + random() * 0.2) AS jitter
    FROM coverage_matrix_cells cmc
    WHERE cmc.workspace_id = :ws
      AND (cmc.last_enriched_at IS NULL OR cmc.last_enriched_at < now() - INTERVAL '24 hours')
)
SELECT *, low_coverage * freshness_decay * value_weight * jitter AS priority
FROM zones_scored
ORDER BY priority DESC
LIMIT 50;
```

---

## §2 — Anti-doublon strict (6 niveaux)

### Niveau 1 — Entreprise par SIREN

**Index UNIQUE** : `(workspace_id, siren)` sur `companies`.

```php
class DeduplicationService
{
    public function findOrCreateCompany(string $workspaceId, string $siren, array $attrs): Company
    {
        return Company::firstOrCreate(
            ['workspace_id' => $workspaceId, 'siren' => $siren],
            array_merge($attrs, ['first_seen_at' => now()])
        );
    }
}
```

Pour entreprises sans SIREN (futur international) :
```php
$hashKey = hash('sha256', normalize_name($legalName) . '|' . normalize_name($city) . '|' . $countryCode);
$company = Company::firstOrCreate(
    ['workspace_id' => $ws, 'siren' => null, 'name_city_hash' => $hashKey],  // colonne à ajouter si international
    $attrs
);
```

### Niveau 2 — Contact par hash normalisé

**Index UNIQUE** : `(company_id, full_name_normalized)` sur `contacts`.

```php
public function findOrCreateContact(string $workspaceId, string $companyId, array $attrs): Contact
{
    return Contact::firstOrCreate([
        'workspace_id' => $workspaceId,
        'company_id'   => $companyId,
        'first_name'   => $attrs['first_name'] ?? null,
        'last_name'    => $attrs['last_name'],
    ], $attrs);
}
```

La colonne générée `full_name_normalized` garantit l'unicité même si nom écrit différemment (Marie Dupont, MARIE DUPONT, Marie  Dupont).

### Niveau 3 — Scraping jobs par TTL

```php
public function shouldScrape(string $workspaceId, string $entityId, string $source): bool
{
    $ttlDays = ScrapingSource::where('workspace_id', $workspaceId)
        ->where('source_slug', $source)
        ->value('ttl_revalidation_days') ?? 90;

    $lastRun = ScraperRun::where('workspace_id', $workspaceId)
        ->where('target_id', $entityId)
        ->where('source', $source)
        ->where('status', 'ok')
        ->where('completed_at', '>', now()->subDays($ttlDays))
        ->latest('completed_at')
        ->first();

    return is_null($lastRun);
}

// Usage avant dispatch
if (!$this->dedup->shouldScrape($ws, $company->id, 'google_maps')) {
    ScraperRun::create([..., 'status' => 'skipped_already_fresh', ...]);
    return;
}
```

TTLs par source (défauts dans `scraping_sources`):

| Source | TTL (j) |
|--------|---------|
| INSEE Sirene | 180 |
| annuaire-entreprises | 365 |
| BODACC | 30 |
| Google Maps | 90 |
| Pages Jaunes | 90 |
| Sites web | 30 |
| Google Search Wrapper | 60 |
| France Travail | 7 |
| MESRI/ONISEP | 365 |
| Crunchbase | 30 |
| BAN | 365 |
| Social light | 60 |
| Direction Finder | 90 |

### Niveau 4 — Coverage cells (cooldown zone)

24h cooldown obligatoire par cellule `(department × naf × size)`. Empêche de re-scraper la même zone trop rapidement.

Voir `10_rotations_universelles.md` § Zone rotator + `coverage_matrix_cells.last_enriched_at`.

### Niveau 5 — Validation email (TTL 30j)

Cf. `06_email_finder_validation.md` § 7. Avant probe SMTP, query `email_verifications`.

### Niveau 6 — Opt-out cross-workspace

Cf. `03_db_schema_phase1.md` § 5 (table `opt_out` SANS workspace_id, GLOBAL).

```php
public function isOptedOut(string $email, ?string $personNameNorm = null, ?string $domain = null): bool
{
    return OptOut::where(fn($q) => $q
        ->where('email', $email)
        ->orWhere('email_hash', hash('sha256', $email))
        ->orWhere('domain', $domain ?? substr(strrchr($email, '@'), 1))
        ->when($personNameNorm, fn($q2) => $q2->orWhere('person_name_norm', $personNameNorm))
    )->exists();
}
```

Consulté AVANT :
1. Tout INSERT `companies`
2. Tout INSERT `contacts`
3. Tout enrichissement
4. Toute validation SMTP
5. Tout envoi cold email (Phase 2)

---

## §3 — Fuzzy matching pg_trgm

### Stratégie

Pour entreprises sans SIREN identique, détection quasi-doublons via similarité trigram.

```sql
-- Jobs nightly app:detect-duplicate-flags
INSERT INTO duplicate_flags (workspace_id, entity_type, entity_a_id, entity_b_id, fuzzy_score, match_fields)
SELECT
    a.workspace_id,
    'company',
    a.id,
    b.id,
    similarity(a.legal_name_normalized, b.legal_name_normalized) AS score,
    ARRAY['legal_name','city']
FROM companies a
JOIN companies b ON
    a.workspace_id = b.workspace_id
    AND a.id < b.id
    AND a.city_insee = b.city_insee
    AND a.legal_name_normalized % b.legal_name_normalized   -- trigram operator
WHERE
    similarity(a.legal_name_normalized, b.legal_name_normalized) >= 0.85
    AND NOT EXISTS (
        SELECT 1 FROM duplicate_flags df
        WHERE df.workspace_id = a.workspace_id
          AND df.entity_type = 'company'
          AND ((df.entity_a_id = a.id AND df.entity_b_id = b.id) OR (df.entity_a_id = b.id AND df.entity_b_id = a.id))
    )
ON CONFLICT DO NOTHING;
```

### Seuils

| Score | Action |
|-------|--------|
| ≥ 0.95 | Auto-merge candidat (avec validation humaine) |
| 0.85-0.95 | Flag pour review humaine (`duplicate_flags.status = 'pending'`) |
| < 0.85 | Ignoré |

### Workflow review humaine

UI admin "Doublons potentiels" → liste paires + bouton "Merger" / "Rejeter".

Merge :
```php
public function merge(Company $primary, Company $duplicate): void
{
    DB::transaction(function () use ($primary, $duplicate) {
        // Transfert relations
        Contact::where('company_id', $duplicate->id)->update(['company_id' => $primary->id]);
        CompanyEmail::where('company_id', $duplicate->id)->update(['company_id' => $primary->id]);
        CompanyPhone::where('company_id', $duplicate->id)->update(['company_id' => $primary->id]);
        CompanySocialHandle::where('company_id', $duplicate->id)->update(['company_id' => $primary->id]);
        CompanyBusinessSignal::where('company_id', $duplicate->id)->update(['company_id' => $primary->id]);
        // Audit
        AuditLog::record('company.merge', $primary->id, [
            'merged_from' => $duplicate->id,
            'merged_into' => $primary->id,
        ]);
        // Soft-delete duplicate
        $duplicate->delete();
        // Update flag
        DuplicateFlag::where('entity_a_id', $primary->id)->orWhere('entity_b_id', $primary->id)
            ->update(['status' => 'confirmed_merge', 'reviewed_at' => now()]);
    });
}
```

---

## §4 — Économie réelle attendue

Estimation : 200 k entreprises/mois, ~30% seraient re-scrapées sans dedup.

| Sans dedup | Avec dedup |
|------------|------------|
| 60k requêtes proxy gaspillées (×3 sources moyenne) | ~0 |
| ~15 GB bandwidth proxy gaspillé | ~0 |
| 50-100 € proxy/mois | ~0 |
| 10-20 € LLM tokens regaspillés (re-classification) | ~0 |
| **Total gaspillage évité** | **~70-120 €/mois** |

---

## §5 — Tests acceptance

```php
test('niveau 1 : SIREN dédupliqué')
    ->expect(fn() => Company::factory()->create(['siren' => '123456789']))
    ->and(fn() => Company::factory()->create(['siren' => '123456789']))   // même workspace
    ->toThrow(\Illuminate\Database\QueryException::class)               // contrainte unique
;

test('niveau 3 : skip si TTL non expiré')
    ->expect(function () {
        $company = Company::factory()->create();
        ScraperRun::factory()->create([
            'target_id' => $company->id, 'source' => 'google_maps',
            'status' => 'ok', 'completed_at' => now()->subDays(30),
        ]);
        return app(DedupService::class)->shouldScrape($company->workspace_id, $company->id, 'google_maps');
    })->toBeFalse();   // TTL 90j → 30j < TTL → skip

test('niveau 6 : opt-out cross-workspace')
    ->expect(function () {
        OptOut::create(['email' => 'user@example.com', 'reason' => 'user_request', 'source' => 'unsubscribe_link']);
        return app(DedupService::class)->isOptedOut('user@example.com');
    })->toBeTrue();
```

---

## Lecture suivante

→ `13_ui_admin_phase1.md` (17 pages Phase 1 + 5 pages Phase 2 scaffold).
