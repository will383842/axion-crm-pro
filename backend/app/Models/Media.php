<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * MÉDIA (chaîne TV, émission, journal quotidien/hebdo/mensuel, radio, agence de
 * presse, portail web, blog, production audiovisuelle).
 *
 * Rattachable à une {@see Company} éditrice (par SIREN) quand elle existe dans la
 * base des 4,3M, et à un média parent ({@see Media}) pour émission → chaîne.
 * Même moteur d'enrichissement que les entreprises (colonne `website_status`
 * pending/found/not_found/exhausted + `enrich_status`).
 *
 * @property int     $id
 * @property string  $workspace_id
 * @property ?int    $company_id
 * @property ?int    $parent_media_id
 * @property string  $name
 * @property string  $media_type
 * @property ?string $periodicity
 */
class Media extends Model
{
    use SoftDeletes;

    protected $table = 'media';

    protected $fillable = [
        'workspace_id', 'company_id', 'parent_media_id', 'siren',
        'name', 'media_type', 'periodicity', 'editorial_theme', 'diffusion_zone', 'publisher',
        'department_code', 'region_code', 'city', 'postcode',
        'website', 'website_status', 'email', 'phone', 'socials',
        'cppap_number', 'arcom_id', 'enrich_status', 'enriched_at', 'source',
    ];

    protected $casts = [
        'socials'     => 'array',
        'enriched_at' => 'datetime',
    ];

    /**
     * Alias `denomination` → `name` : permet de réutiliser le DomainFinderService
     * (moteur de devinette de domaine des entreprises) tel quel sur un média.
     */
    public function getDenominationAttribute(): ?string
    {
        return $this->name;
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /** Média parent (ex. la chaîne d'une émission). */
    public function parent()
    {
        return $this->belongsTo(Media::class, 'parent_media_id');
    }

    /** Médias enfants (ex. les émissions d'une chaîne). */
    public function children()
    {
        return $this->hasMany(Media::class, 'parent_media_id');
    }

    public function journalists()
    {
        return $this->hasMany(Journalist::class);
    }
}
