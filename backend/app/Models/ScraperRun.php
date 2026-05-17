<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
            'started_at'       => 'datetime',
            'finished_at'      => 'datetime',
            'request_payload'  => 'array',
            'response_payload' => 'array',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function campaign()
    {
        return $this->belongsTo(ScrapingCampaign::class, 'campaign_id');
    }
}
