<?php

namespace App\Models;

use App\Crm\Taxonomy;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Univers VIVIER — fiche candidat.
 *
 * Entité DÉDIÉE, jamais un `Contact` : `contacts.company_id` est NOT NULL et la
 * dédup y repose sur `sha256(nom || company_id)`. Un candidat n'a pas
 * d'entreprise.
 *
 * Étanchéité : workspace dédié + RLS forcée (L0) + CHECK `relation_type` +
 * trigger de workspace. Une fiche ne « glisse » jamais d'un univers à l'autre :
 * le seul chemin est une action explicite qui CRÉE une fiche dans l'autre
 * univers, avec sa propre base légale, et journalise l'opération des deux côtés.
 *
 * @property int $id
 * @property string $workspace_id
 * @property ?string $external_ref
 * @property ?string $person_key
 * @property string $last_name
 * @property string $relation_type
 * @property string $lifecycle_stage
 */
class Candidate extends Model
{
    use BelongsToWorkspace;
    use SoftDeletes;

    protected $fillable = [
        'workspace_id', 'external_ref', 'person_key',
        'first_name', 'last_name', 'email', 'phone',
        'relation_type', 'lifecycle_stage',
        'source', 'offer_slug',
        'legal_basis', 'consent_version', 'consent_at', 'consent_text_ref',
        'consent_vivier_at', 'derniere_interaction_at', 'vivier_info_sent_at',
        'attributes', 'experiences', 'cv_ref',
        'opt_out', 'opt_out_at', 'anonymized_at',
    ];

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'experiences' => 'array',
            'opt_out' => 'boolean',
            'consent_at' => 'datetime',
            'consent_vivier_at' => 'datetime',
            'derniere_interaction_at' => 'datetime',
            'vivier_info_sent_at' => 'datetime',
            'opt_out_at' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'candidate_tag')
            ->withPivot(['workspace_id', 'assigned_at', 'assigned_by']);
    }

    /**
     * Familles de métiers ouvertes à ce modèle (liste FERMÉE, cf. Taxonomy).
     *
     * @return list<string>
     */
    public static function relationTypes(): array
    {
        return Taxonomy::CANDIDATE_RELATION_TYPES;
    }

    /**
     * La conservation en vivier repose sur un consentement v2 explicite.
     * Aucune fiche portant une version v1 ne doit entrer au vivier : les textes
     * v1 ne couvrent QUE l'étude de la candidature en cours.
     */
    public function hasVivierConsent(): bool
    {
        return $this->consent_vivier_at !== null
            && in_array((string) $this->consent_version, Taxonomy::CANDIDATE_CONSENT_VERSIONS_V2, true);
    }
}
