<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $workspace_id UUID — jamais un entier (cf. WorkspaceContext)
 * @property string $siren
 * @property ?string $denomination
 * @property ?string $naf
 * @property ?string $legal_form
 * @property ?string $effectif_range
 * @property ?string $insee
 * @property ?string $size_category
 * @property ?int $quality_score
 * @property ?string $priority haute|moyenne|basse|gelee (CHECK en base)
 * @property array<string, mixed> $signals
 * @property array<string, mixed> $metadata
 * @property ?Carbon $enriched_at
 * @property ?string $city
 * @property ?string $city_name
 * @property ?string $postcode
 * @property ?string $department_code
 * @property ?string $region_code
 * @property ?string $commune_code
 * @property ?string $sector_main
 * @property ?string $archive_reason
 * @property string $prospection_status NOT NULL, défaut 'pending' (migration 2026_05_18_000006)
 * @property ?string $email_generic
 * @property ?string $best_email_confidence
 * @property ?string $website
 * @property ?float $lat
 * @property ?float $lon
 * @property ?string $address
 * @property ?string $enseigne
 * @property ?string $phone
 * @property ?Carbon $updated_at
 * @property ?Carbon $created_at
 *
 * Lot L1 — taxonomie CRM (migration 2026_08_14_000002) :
 * @property string $relation_type
 * @property string $lifecycle_stage
 * @property ?string $legal_basis
 * @property ?string $external_ref
 * @property-read Collection<int, Contact> $contacts
 * @property-read Collection<int, Tag> $tags
 * @property-read Collection<int, HealthPractitioner> $healthPractitioners
 */
class Company extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    /**
     * 🔴 B10-016 — la table porte `deleted_at` depuis l'origine (migration
     * 2026_05_16_000003, ligne 57) et TOUT le code de lecture s'appuie dessus :
     * `CompaniesController::buildFilteredQuery()` (ligne 136, partagee par la
     * liste ET l'export), CompanyTagsBulkController:90, ArbitrageController:118,
     * CompteursHub:151, EligibiliteCampagne:76/99/250, GlobalSearchController,
     * plus CINQ index partiels `WHERE deleted_at IS NULL` (migrations
     * 2026_08_15_000002, 000003, 000006, 2026_08_17_000001, 2026_08_19_000001).
     *
     * Le modele, lui, ne declarait pas le trait : `$company->delete()`
     * (CompaniesController ligne 442) emettait un `DELETE FROM` sec, alors que
     * la documentation OpenAPI DOUZE LIGNES PLUS HAUT (ligne 427) promet
     * « Soft-delete une entreprise (deleted_at pose) ». Le fichier se
     * contredisait lui-meme, et le test cense l'attester ne verifiait que le
     * code HTTP 204 (`CompaniesControllerTest.php:115`, nomme « destroy
     * soft-deletes company » — il n'a jamais relu la ligne).
     *
     * Le cout mesure ne s'arrete pas a la fiche : `contacts.company_id`
     * REFERENCE `companies(id) ON DELETE CASCADE` (migration 000003, ligne 74).
     * Un DELETE dur emportait donc AUSSI tous les contacts, sans passer par
     * Eloquent — donc sans evenement ni journal. Garde jouee le 2026-08-20 :
     * trois contacts crees, `->delete()` sur l'entreprise, il en restait ZERO.
     *
     * ⚠️ Les purges RGPD ne sont pas touchees : GdprErasureService,
     * RetentionPurge, RgpdPurgeBusinessProspects, RgpdPurgeVivier et
     * ProspectionPurgeNonDiffusible passent tous par `DB::table(...)`, que les
     * traits Eloquent n'atteignent pas. Le droit a l'effacement reste dur.
     */
    use SoftDeletes;

    // ATTENTION : `denomination_normalized` et `quality_badge` sont GENERATED COLUMNS
    // côté Postgres (migration 000003) — exclues de fillable → toute tentative
    // INSERT/UPDATE sur ces colonnes lèvera une erreur Postgres.
    protected $fillable = [
        'workspace_id', 'siren', 'siret', 'denomination',
        'naf', 'legal_form', 'effectif_range', 'effectif_min', 'effectif_max',
        'size_category', 'is_artisan',
        'address', 'postcode', 'city', 'insee', 'lat', 'lon', 'enseigne',
        'website', 'phone', 'linkedin_url',
        'quality_score', 'priority', 'discovery_source',
        'signals', 'metadata', 'enriched_at', 'last_seen_at',
        // Sprint Prospection Pipeline 360° (2026-05-17)
        'email_generic', 'prospection_status',
        'region_code', 'department_code', 'commune_code', 'city_name',
        'sector_main', 'archive_reason',
        // Prospection internationale (2026-08-15) : une entité sans SIREN
        // s'ancre sur (country_code, foreign_id) — cf. migration 120001.
        'country_code', 'foreign_id', 'entity_nature',
    ];

    protected function casts(): array
    {
        return [
            'signals' => 'array',
            'metadata' => 'array',
            'enriched_at' => 'datetime',
            'lat' => 'float',
            'lon' => 'float',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return HasMany<Contact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /** @return HasMany<ScraperRun, $this> */
    public function scraperRuns(): HasMany
    {
        return $this->hasMany(ScraperRun::class);
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * Verticale santé — praticiens (RPPS) rattachés par SIREN/company_id.
     *
     * @return HasMany<HealthPractitioner, $this>
     */
    public function healthPractitioners(): HasMany
    {
        return $this->hasMany(HealthPractitioner::class);
    }
}
