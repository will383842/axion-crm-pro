<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RgpdRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id', 'type', 'status', 'subject_email',
        'requested_at', 'processed_at', 'export_token', 'export_expires_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'requested_at'       => 'datetime',
            'processed_at'       => 'datetime',
            'export_expires_at'  => 'datetime',
            'metadata'           => 'array',
        ];
    }
}
