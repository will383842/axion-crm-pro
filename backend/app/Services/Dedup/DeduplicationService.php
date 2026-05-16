<?php

namespace App\Services\Dedup;

use App\Models\Company;
use App\Models\Contact;
use App\Models\ScraperRun;
use Illuminate\Support\Facades\DB;

/**
 * Anti-doublon strict 6 niveaux — cf. spec/12_coverage_matrix_deduplication.md.
 *
 * 1. Entreprise par SIREN (unique composite workspace_id, siren) — DB constraint
 * 2. Contact par hash normalisé prenom+nom+company_id — DB constraint (normalized_hash GENERATED)
 * 3. Scraping jobs par TTL configurable par source (`scraper_runs.dedup_key`)
 * 4. Coverage cells cooldown 24h (`coverage_zones.cooldown_until`)
 * 5. Validation email TTL 30j (`email_validations.expires_at`)
 * 6. Opt-out cross-workspace (`opt_out` global)
 *
 * Performance cible : p95 < 50 ms sur 10M scraper_runs (POC #5 validé 35.94 ms).
 */
class DeduplicationService
{
    /** TTL revalidation par source (en jours). */
    public const SOURCE_TTL_DAYS = [
        'insee'                 => 90,
        'annuaire-entreprises'  => 30,
        'bodacc'                => 30,
        'google-maps'           => 60,
        'pages-jaunes'          => 90,
        'website'               => 60,
        'google-search'         => 30,
        'direction-finder'      => 90,
        'france-travail'        => 7,
        'mesri'                 => 365,
        'crunchbase'            => 90,
        'infogreffe'            => 180,
        'societe-com'           => 180,
        'social-light'          => 90,
    ];

    /** Niveau 1 : entreprise existe-t-elle déjà dans le workspace ? */
    public function findCompanyBySiren(string $workspaceId, string $siren): ?Company
    {
        return Company::query()
            ->where('workspace_id', $workspaceId)
            ->where('siren', $siren)
            ->first();
    }

    /** Niveau 2 : contact existe-t-il déjà ? (hash dedup GENERATED côté DB) */
    public function findContactByNormalizedHash(string $workspaceId, int $companyId, ?string $firstName, string $lastName): ?Contact
    {
        // normalized_hash GENERATED côté Postgres = encode(digest(normalize_name(first || '_' || last) || '_' || company_id, 'sha256'), 'hex')
        $hash = $this->computeContactHash($firstName, $lastName, $companyId);
        return Contact::query()
            ->where('workspace_id', $workspaceId)
            ->where('normalized_hash', $hash)
            ->first();
    }

    public function computeContactHash(?string $firstName, string $lastName, int $companyId): string
    {
        // Doit matcher exactement la GENERATED COLUMN côté Postgres (migration 000003).
        // On utilise une approximation côté PHP — la source de vérité reste la DB.
        $normFirst = $this->normalize($firstName ?? '');
        $normLast  = $this->normalize($lastName);
        return hash('sha256', "{$normFirst}_{$normLast}_{$companyId}");
    }

    /** Niveau 3 : un scraping de cette source pour ce target est-il encore "frais" ? */
    public function isScrapeFresh(string $workspaceId, string $source, string $dedupKey): bool
    {
        $ttlDays = self::SOURCE_TTL_DAYS[$source] ?? 30;
        $cutoff = now()->subDays($ttlDays);

        return ScraperRun::query()
            ->where('workspace_id', $workspaceId)
            ->where('source', $source)
            ->where('dedup_key', $dedupKey)
            ->where('status', 'success')
            ->where('finished_at', '>', $cutoff)
            ->exists();
    }

    /** Build dedup_key canonique pour un job (source-specific). */
    public function buildDedupKey(string $source, array $context): string
    {
        $canonical = match ($source) {
            'insee', 'annuaire-entreprises', 'bodacc', 'societe-com', 'infogreffe', 'france-travail', 'crunchbase'
                => $source . ':siren:' . ($context['siren'] ?? ''),
            'google-maps'
                => $source . ':query:' . md5(($context['query'] ?? '') . '|' . ($context['lat'] ?? '') . ',' . ($context['lon'] ?? '')),
            'pages-jaunes', 'website'
                => $source . ':url:' . md5((string) ($context['url'] ?? '')),
            'google-search', 'direction-finder'
                => $source . ':query:' . md5((string) ($context['query'] ?? '')),
            default
                => $source . ':' . md5(json_encode($context)),
        };

        return substr($canonical, 0, 255);
    }

    /** Niveau 4 : zone (department × naf × size) est-elle en cooldown ? */
    public function isZoneInCooldown(string $workspaceId, string $department, ?string $naf, ?string $size): bool
    {
        return DB::table('coverage_zones')
            ->where('workspace_id', $workspaceId)
            ->where('department', $department)
            ->when($naf !== null, fn ($q) => $q->where('naf', $naf))
            ->when($size !== null, fn ($q) => $q->where('size_category', $size))
            ->where('cooldown_until', '>', now())
            ->exists();
    }

    public function markZoneAttempted(string $workspaceId, string $department, ?string $naf, ?string $size, int $cooldownHours = 24): void
    {
        DB::table('coverage_zones')->updateOrInsert(
            ['workspace_id' => $workspaceId, 'department' => $department, 'naf' => $naf, 'size_category' => $size],
            [
                'attempted_at'    => now(),
                'cooldown_until'  => now()->addHours($cooldownHours),
                'metadata'        => '{}',
                'created_at'      => now(),
            ],
        );
    }

    /** Niveau 5 : email validé récemment ? (cache 30j) */
    public function getEmailValidationCache(string $email): ?object
    {
        return DB::table('email_validations')
            ->where('email', strtolower(trim($email)))
            ->where('expires_at', '>', now())
            ->first();
    }

    public function setEmailValidationCache(string $email, string $status, int $score, ?string $mxHost, bool $catchall, bool $disposable, bool $role): void
    {
        DB::table('email_validations')->updateOrInsert(
            ['email' => strtolower(trim($email))],
            [
                'status'        => $status,
                'score'         => $score,
                'mx_host'       => $mxHost,
                'is_catchall'   => $catchall,
                'is_disposable' => $disposable,
                'is_role'       => $role,
                'checked_at'    => now(),
                'expires_at'    => now()->addDays(30),
            ],
        );
    }

    /** Niveau 6 : opt-out cross-workspace (RGPD). */
    public function isOptedOut(?string $email = null, ?string $phone = null): bool
    {
        if (! $email && ! $phone) {
            return false;
        }
        return DB::table('opt_out')
            ->when($email !== null, fn ($q) => $q->orWhere('email', strtolower(trim($email))))
            ->when($phone !== null, fn ($q) => $q->orWhere('phone', preg_replace('/[\s.-]/', '', $phone)))
            ->exists();
    }

    public function addOptOut(?string $email, ?string $phone, string $source, ?string $reason = null): void
    {
        DB::table('opt_out')->insert([
            'email'      => $email ? strtolower(trim($email)) : null,
            'phone'      => $phone ? preg_replace('/[\s.-]/', '', $phone) : null,
            'source'     => $source,
            'reason'     => $reason,
            'created_at' => now(),
        ]);
    }

    /**
     * Décide si un job de scraping doit être créé / skippé pour respecter les 6 niveaux.
     * @return array{should_run: bool, reason: ?string}
     */
    public function shouldRunScrape(string $workspaceId, string $source, array $context): array
    {
        $dedupKey = $this->buildDedupKey($source, $context);

        if ($this->isScrapeFresh($workspaceId, $source, $dedupKey)) {
            return ['should_run' => false, 'reason' => "fresh_within_ttl_{$source}"];
        }
        if (isset($context['email']) && $this->isOptedOut($context['email'])) {
            return ['should_run' => false, 'reason' => 'subject_opted_out'];
        }
        if (isset($context['department'])
            && $this->isZoneInCooldown(
                $workspaceId,
                (string) $context['department'],
                $context['naf'] ?? null,
                $context['size_category'] ?? null,
            )) {
            return ['should_run' => false, 'reason' => 'zone_in_cooldown'];
        }
        return ['should_run' => true, 'reason' => null];
    }

    private function normalize(string $input): string
    {
        $input = mb_strtolower($input);
        $input = preg_replace('/\s+/u', ' ', $input);
        // Approximation unaccent côté PHP (la vraie référence reste Postgres unaccent extension).
        $input = preg_replace('/\\b(de|du|la|le|les|d|l)\\b\\s+/iu', '', $input);
        return trim((string) $input);
    }
}
