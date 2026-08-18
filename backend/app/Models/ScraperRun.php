<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Colonnes réelles de `scraper_runs` (migration 2026_05_16_000003).
 *
 * @property int $id
 * @property string $workspace_id UUID
 * @property ?int $company_id
 * @property ?int $campaign_id
 * @property string $source
 * @property string $status pending|running|success|failed|partial|cancelled (CHECK en base)
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property ?int $latency_ms
 * @property ?string $error
 * @property ?string $payload_path
 * @property ?array<string, mixed> $request_payload
 * @property ?array<string, mixed> $response_payload
 * @property ?string $dedup_key
 * @property ?Carbon $created_at
 * @property-read ?Company $company
 * @property-read ?ScrapingCampaign $campaign
 */
class ScraperRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id', 'company_id', 'campaign_id', 'source', 'status',
        'started_at', 'finished_at', 'latency_ms', 'error',
        'payload_path', 'request_payload', 'response_payload',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'request_payload' => 'array',
            'response_payload' => 'array',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<ScrapingCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(ScrapingCampaign::class, 'campaign_id');
    }
}
