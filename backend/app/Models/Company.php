<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $workspace_id UUID — jamais un entier (cf. WorkspaceContext)
 * @property string $siren
 * @property ?string $denomination
 * @property ?string $naf
 * @property ?string $size_category
 * @property ?int $quality_score
 * @property array $signals
 * @property ?string $city
 * @property ?string $city_name
 * @property ?string $postcode
 * @property ?string $department_code
 * @property ?string $region_code
 * @property ?string $email_generic
 * @property ?string $best_email_confidence
 * @property ?string $website
 * @property ?float $lat
 * @property ?float $lon
 * @property ?string $address
 * @property ?string $enseigne
 * @property ?string $phone
 * @property ?Carbon $updated_at
 * @property ?Carbon $created_at
 *
 * Lot L1 — taxonomie CRM (migration 2026_08_14_000002) :
 * @property string $relation_type
 * @property string $lifecycle_stage
 * @property ?string $legal_basis
 * @property ?string $external_ref
 * @property-read Collection<int, Contact> $contacts
 * @property-read Collection<int, Tag> $tags
 * @property-read Collection<int, HealthPractitioner> $healthPractitioners
 */
class Company extends Model
{
    use BelongsToWorkspace;
    use HasFactory;

    // ATTENTION : `denomination_normalized` et `quality_badge` sont GENERATED COLUMNS
    // côté Postgres (migration 000003) — exclues de fillable → toute tentative
    // INSERT/UPDATE sur ces colonnes lèvera une erreur Postgres.
    protected $fillable = [
        'workspace_id', 'siren', 'siret', 'denomination',
        'naf', 'legal_form', 'effectif_range', 'effectif_min', 'effectif_max',
        'size_category', 'is_artisan',
        'address', 'postcode', 'city', 'insee', 'lat', 'lon', 'enseigne',
        'website', 'phone', 'linkedin_url',
        'quality_score', 'priority', 'discovery_source',
        'signals', 'metadata', 'enriched_at', 'last_seen_at',
        // Sprint Prospection Pipeline 360° (2026-05-17)
        'email_generic', 'prospection_status',
        'region_code', 'department_code', 'commune_code', 'city_name',
        'sector_main', 'archive_reason',
    ];

    protected function casts(): array
    {
        return [
            'signals' => 'array',
            'metadata' => 'array',
            'enriched_at' => 'datetime',
            'lat' => 'float',
            'lon' => 'float',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return HasMany<Contact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /** @return HasMany<ScraperRun, $this> */
    public function scraperRuns(): HasMany
    {
        return $this->hasMany(ScraperRun::class);
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * Verticale santé — praticiens (RPPS) rattachés par SIREN/company_id.
     *
     * @return HasMany<HealthPractitioner, $this>
     */
    public function healthPractitioners(): HasMany
    {
        return $this->hasMany(HealthPractitioner::class);
    }
}
