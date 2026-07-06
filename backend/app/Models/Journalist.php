<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * JOURNALISTE / contact rédaction rattaché à un {@see Media}.
 *
 * ⚠️ DONNÉE PERSONNELLE (RGPD). Base légale = intérêt légitime B2B relations
 * presse. Ingestion/scraping gaté par MEDIA_JOURNALISTS_ENABLED. `source_url`
 * pour la traçabilité (transparence CNIL), `opt_out` pour le droit d'opposition,
 * soft-delete pour le droit à l'effacement.
 *
 * @property int     $id
 * @property string  $workspace_id
 * @property ?int    $media_id
 * @property ?string $first_name
 * @property ?string $last_name
 * @property ?string $role
 * @property ?string $beat
 * @property bool    $opt_out
 */
class Journalist extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'workspace_id', 'media_id', 'company_id',
        'first_name', 'last_name', 'role', 'beat',
        'email', 'phone', 'socials', 'source', 'source_url', 'opt_out',
    ];

    protected $casts = [
        'socials' => 'array',
        'opt_out' => 'boolean',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function media()
    {
        return $this->belongsTo(Media::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
