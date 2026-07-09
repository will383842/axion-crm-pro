<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $siren
 * @property ?string $denomination
 * @property ?string $naf
 * @property ?string $size_category
 * @property ?int $quality_score
 * @property array $signals
 */
class Company extends Model
{
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
            'signals'      => 'array',
            'metadata'     => 'array',
            'enriched_at'  => 'datetime',
            'lat'          => 'float',
            'lon'          => 'float',
        ];
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function contacts()
    {
        return $this->hasMany(Contact::class);
    }

    public function scraperRuns()
    {
        return $this->hasMany(ScraperRun::class);
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }

    /** Verticale santé — praticiens (RPPS) rattachés par SIREN/company_id. */
    public function healthPractitioners()
    {
        return $this->hasMany(HealthPractitioner::class);
    }
}
