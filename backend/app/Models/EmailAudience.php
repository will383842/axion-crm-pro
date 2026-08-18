<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

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
 *
 * Colonnes réelles (migration 2026_05_18_000008).
 *
 * @property int $id
 * @property string $workspace_id UUID
 * @property string $name
 * @property ?string $description
 * @property bool $is_active
 * @property bool $auto_refresh
 * @property int $member_count NOT NULL, défaut 0
 * @property ?Carbon $refreshed_at
 * @property ?string $created_by UUID de l'utilisateur, NULL si compte supprimé
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 * @property-read ?Workspace $workspace
 * @property-read ?User $creator
 * @property-read Collection<int, AudienceMember> $members
 *
 * `$criteria` (JSONB NOT NULL DEFAULT '{}', casté `array`) est VOLONTAIREMENT
 * absent, pour la même raison que `Tag::$rules` : le déclarer rend redondants
 * les `is_array($audience->criteria) ? … : []` de RefreshAudienceChunkJob et
 * d'AudienceBuilderService, que PHPStan signale alors comme toujours vrais. Ces
 * garde-fous protègent d'un JSONB scalaire ; les retirer changerait le
 * comportement. Deux entrées de baseline les couvrent.
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
            'criteria' => 'array',
            'is_active' => 'boolean',
            'auto_refresh' => 'boolean',
            'member_count' => 'integer',
            'refreshed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<AudienceMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(AudienceMember::class, 'audience_id');
    }
}
