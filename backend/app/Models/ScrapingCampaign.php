<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sprint 19.7 — Campagnes de scraping.
 *
 * Orchestre N scraper_runs avec budgets + planning + auto-pause anti-blacklist.
 *
 * @property int    $id
 * @property string $workspace_id  UUID
 * @property string $created_by    UUID
 * @property string $name
 * @property ?string $description
 * @property string $status        draft|scheduled|running|paused|completed|failed|cancelled
 * @property array  $sources       liste des sources whitelistées
 * @property array  $zones         [{ type:'department'|'region'|'city', code:'75' }, ...]
 * @property int    $max_companies
 * @property int    $max_duration_minutes
 * @property int    $max_requests_per_minute
 * @property ?array $per_source_limits
 * @property ?\Illuminate\Support\Carbon $scheduled_at
 * @property ?\Illuminate\Support\Carbon $expires_at
 * @property int    $companies_created
 * @property int    $requests_made
 * @property int    $runs_completed
 * @property int    $runs_total
 * @property int    $duration_seconds_used
 * @property ?\Illuminate\Support\Carbon $started_at
 * @property ?\Illuminate\Support\Carbon $paused_at
 * @property ?\Illuminate\Support\Carbon $finished_at
 * @property ?string $paused_reason
 */
class ScrapingCampaign extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'scraping_campaigns';

    protected $fillable = [
        'workspace_id', 'created_by', 'name', 'description', 'status',
        'sources', 'zones',
        'max_companies', 'max_duration_minutes', 'max_requests_per_minute',
        'per_source_limits',
        'scheduled_at', 'expires_at',
        'companies_created', 'requests_made', 'runs_completed', 'runs_total',
        'duration_seconds_used',
        'started_at', 'paused_at', 'finished_at', 'paused_reason',
    ];

    protected function casts(): array
    {
        return [
            'sources'                 => 'array',
            'zones'                   => 'array',
            'per_source_limits'       => 'array',
            'scheduled_at'            => 'datetime',
            'expires_at'              => 'datetime',
            'started_at'              => 'datetime',
            'paused_at'               => 'datetime',
            'finished_at'             => 'datetime',
            'max_companies'           => 'int',
            'max_duration_minutes'    => 'int',
            'max_requests_per_minute' => 'int',
            'companies_created'       => 'int',
            'requests_made'           => 'int',
            'runs_completed'          => 'int',
            'runs_total'              => 'int',
            'duration_seconds_used'   => 'int',
        ];
    }

    // ------------------------------------------------------------------
    // Whitelist sources (cohérent avec ScrapingCampaignRequest)
    // ------------------------------------------------------------------
    public const ALLOWED_SOURCES = [
        'insee', 'google_maps', 'pages_jaunes', 'france_travail',
        'annuaire', 'bodacc', 'ban',
    ];

    public const ALLOWED_ZONE_TYPES = ['department', 'region', 'city'];

    public const ALLOWED_PAUSED_REASONS = [
        'quota_companies', 'quota_duration', 'manual', 'rate_limit',
    ];

    // ------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------
    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function runs()
    {
        return $this->hasMany(ScraperRun::class, 'campaign_id');
    }

    // ------------------------------------------------------------------
    // Accessors / computed metrics
    // ------------------------------------------------------------------

    /**
     * Pourcentage [0,100] basé sur le budget atteint en premier
     * (max(companies_created/max_companies, elapsed/max_duration)).
     */
    public function getProgressPercentAttribute(): int
    {
        $companiesRatio = $this->max_companies > 0
            ? min(1.0, $this->companies_created / $this->max_companies)
            : 0.0;
        $durationRatio = $this->max_duration_minutes > 0
            ? min(1.0, $this->elapsed_minutes / $this->max_duration_minutes)
            : 0.0;
        return (int) round(max($companiesRatio, $durationRatio) * 100);
    }

    /**
     * Minutes écoulées depuis started_at (live si running, figé sinon).
     */
    public function getElapsedMinutesAttribute(): int
    {
        if (! $this->started_at) {
            return 0;
        }
        $end = $this->finished_at ?? now();
        return max(0, (int) $this->started_at->diffInMinutes($end, false));
    }

    public function getRemainingMinutesAttribute(): int
    {
        return max(0, $this->max_duration_minutes - $this->elapsed_minutes);
    }

    public function getCompaniesRemainingAttribute(): int
    {
        return max(0, $this->max_companies - $this->companies_created);
    }

    // ------------------------------------------------------------------
    // Business rules — transitions de statut
    // ------------------------------------------------------------------

    public function canStart(): bool
    {
        return in_array($this->status, ['draft', 'scheduled'], true);
    }

    public function canPause(): bool
    {
        return $this->status === 'running';
    }

    public function canResume(): bool
    {
        return $this->status === 'paused';
    }

    public function canCancel(): bool
    {
        return in_array($this->status, ['draft', 'scheduled', 'running', 'paused'], true);
    }

    /**
     * Retourne le motif d'auto-pause si un quota est dépassé, sinon null.
     * Utilisé par MonitorCampaignProgressJob et par les workers de scraping.
     */
    public function shouldAutoPause(): ?string
    {
        if ($this->status !== 'running') {
            return null;
        }
        if ($this->companies_created >= $this->max_companies) {
            return 'quota_companies';
        }
        if ($this->elapsed_minutes >= $this->max_duration_minutes) {
            return 'quota_duration';
        }
        return null;
    }
}
