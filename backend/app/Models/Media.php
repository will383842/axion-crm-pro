<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * MÉDIA (chaîne TV, émission, journal quotidien/hebdo/mensuel, radio, agence de
 * presse, portail web, blog, production audiovisuelle).
 *
 * Rattachable à une {@see Company} éditrice (par SIREN) quand elle existe dans la
 * base des 4,3M, et à un média parent ({@see Media}) pour émission → chaîne.
 * Même moteur d'enrichissement que les entreprises (colonne `website_status`
 * pending/found/not_found/exhausted + `enrich_status`).
 *
 * @property int $id
 * @property string $workspace_id
 * @property ?int $company_id
 * @property ?int $parent_media_id
 * @property ?string $siren
 * @property string $name
 * @property string $media_type
 * @property ?string $periodicity
 * @property ?string $editorial_theme
 * @property ?string $diffusion_zone
 * @property ?string $publisher
 * @property ?string $department_code
 * @property ?string $region_code
 * @property ?string $city
 * @property ?string $postcode
 * @property ?string $website
 * @property ?string $website_status pending|found|not_found|exhausted
 * @property ?string $website_method
 * @property ?Carbon $website_checked_at
 * @property ?string $email E-mail générique de rédaction (colonne CITEXT)
 * @property ?string $phone
 * @property ?string $cppap_number
 * @property ?string $arcom_id
 * @property string $enrich_status NOT NULL, défaut 'pending'
 * @property ?Carbon $enriched_at
 * @property string $source NOT NULL, défaut 'naf-extract'
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 * @property-read ?Workspace $workspace
 * @property-read ?Company $company
 * @property-read ?Media $parent
 * @property-read Collection<int, Media> $children
 * @property-read Collection<int, Journalist> $journalists
 *
 * `$socials` (JSONB NULL, casté `array`) est laissé hors de cette liste : le
 * code qui le lit garde un `is_array()` défensif, que sa déclaration rendrait
 * « toujours vrai » aux yeux de PHPStan. Même arbitrage que `Tag::$rules`.
 */
class Media extends Model
{
    use SoftDeletes;

    protected $table = 'media';

    protected $fillable = [
        'workspace_id', 'company_id', 'parent_media_id', 'siren',
        'name', 'media_type', 'media_family', 'periodicity', 'editorial_theme', 'diffusion_zone', 'publisher',
        'department_code', 'region_code', 'city', 'postcode',
        'website', 'website_status', 'email', 'email_confidence', 'phone', 'socials',
        'cppap_number', 'arcom_id', 'enrich_status', 'enriched_at', 'source',
    ];

    protected $casts = [
        'socials' => 'array',
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

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Média parent (ex. la chaîne d'une émission).
     *
     * @return BelongsTo<Media, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'parent_media_id');
    }

    /**
     * Médias enfants (ex. les émissions d'une chaîne).
     *
     * @return HasMany<Media, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Media::class, 'parent_media_id');
    }

    /** @return HasMany<Journalist, $this> */
    public function journalists(): HasMany
    {
        return $this->hasMany(Journalist::class);
    }
}
