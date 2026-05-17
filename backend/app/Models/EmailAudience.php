<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Audience email (segmentation réutilisable).
 *
 * `criteria` est un DSL JSON :
 *   {
 *     "all": [ {"field":"...", "op":"...", "value": ... }, ... ],
 *     "any": [...],
 *     "not": [...]
 *   }
 *
 * Le moteur d'évaluation = AudienceBuilderService.
 */
class EmailAudience extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'email_audiences';

    protected $fillable = [
        'workspace_id', 'name', 'description', 'criteria',
        'is_active', 'auto_refresh', 'member_count', 'refreshed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'criteria'     => 'array',
            'is_active'    => 'boolean',
            'auto_refresh' => 'boolean',
            'member_count' => 'integer',
            'refreshed_at' => 'datetime',
        ];
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members()
    {
        return $this->hasMany(AudienceMember::class, 'audience_id');
    }
}
