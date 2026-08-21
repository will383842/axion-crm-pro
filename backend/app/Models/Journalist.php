<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * JOURNALISTE / contact rédaction rattaché à un {@see Media}.
 *
 * ⚠️ DONNÉE PERSONNELLE (RGPD). Base légale = intérêt légitime B2B relations
 * presse. Ingestion/scraping gaté par MEDIA_JOURNALISTS_ENABLED. `source_url`
 * pour la traçabilité (transparence CNIL), `opt_out` pour le droit d'opposition,
 * soft-delete pour le droit à l'effacement.
 *
 * @property int $id
 * @property string $workspace_id
 * @property ?int $media_id
 * @property ?string $first_name
 * @property ?string $last_name
 * @property ?string $role
 * @property ?string $beat
 * @property ?string $email
 * @property bool $opt_out
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

    /**
     * ⚠️ LES TROIS RELATIONS CI-DESSOUS PORTENT UN TYPE DE RETOUR DEPUIS LE
     * 2026-08-21, ET CE N'EST PAS COSMETIQUE.
     *
     * Sans lui, Larastan ne RECONNAIT PAS ces methodes comme des relations :
     * il refusait `->with('media')` en `JournalistsController::export()`
     * (« Relation 'media' is not found in App\Models\Journalist model ») alors
     * que la relation existe et que l'export fonctionne. Un modele dont les
     * relations ne sont pas typees est un modele sur lequel l'analyse statique
     * ne peut RIEN affirmer — ni qu'une relation existe, ni qu'elle n'existe
     * pas. C'est le silence, pas la garantie.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
