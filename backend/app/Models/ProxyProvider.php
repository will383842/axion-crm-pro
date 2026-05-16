<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProxyProvider extends Model
{
    use HasFactory;

    protected $table = 'proxy_providers_config';

    protected $fillable = [
        'workspace_id', 'slug', 'type', 'zone', 'enabled', 'weight',
        'endpoints_count', 'last_health_check_at', 'last_health_status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'enabled'              => 'boolean',
            'metadata'             => 'array',
            'last_health_check_at' => 'datetime',
        ];
    }
}
