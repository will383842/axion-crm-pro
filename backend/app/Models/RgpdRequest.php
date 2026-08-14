<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Demande RGPD (art. 15-22) — journal d'exécution des droits.
 *
 * @property int $id
 * @property ?string $workspace_id
 * @property string $type
 * @property string $status
 * @property string $subject_email
 * @property ?Carbon $requested_at
 * @property ?Carbon $processed_at
 * @property ?string $processed_by
 * @property ?string $export_token
 * @property ?Carbon $export_expires_at
 * @property array<string, mixed> $metadata
 */
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
            'requested_at' => 'datetime',
            'processed_at' => 'datetime',
            'export_expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
