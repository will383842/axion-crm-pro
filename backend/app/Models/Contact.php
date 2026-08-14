<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $workspace_id UUID
 * @property ?int $company_id
 * @property ?string $first_name
 * @property string $last_name
 * @property ?string $email
 * @property ?string $phone
 * @property ?string $role
 * @property ?string $email_confidence
 * @property ?string $normalized_hash
 *
 * Lot L1 — taxonomie CRM (migration 2026_08_14_000002) :
 * @property ?string $person_key
 * @property ?string $external_ref
 * @property ?string $legal_basis
 * @property ?string $consent_version
 * @property ?Carbon $consent_at
 * @property ?string $consent_text_ref
 * @property-read ?Company $company
 */
class Contact extends Model
{
    use BelongsToWorkspace;
    use HasFactory;

    protected $fillable = [
        'workspace_id', 'company_id', 'first_name', 'last_name', 'normalized_hash',
        'title', 'role', 'email', 'email_status', 'email_score', 'email_confidence',
        'phone', 'linkedin_url', 'twitter_url',
        'sources', 'discovery_source', 'metadata',
    ];

    protected function casts(): array
    {
        return ['sources' => 'array', 'metadata' => 'array'];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
