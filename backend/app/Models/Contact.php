<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
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
 * Colonnes de qualification e-mail et de provenance — présentes en base et
 * dans `$fillable` depuis l'origine, mais jamais documentées ici : elles
 * n'étaient lues nulle part tant que la liste des contacts était un bouchon
 * vide. PHPStan les a signalées dès qu'on a branché l'écran.
 * @property ?string $email_status
 * @property ?int $email_score
 * @property ?string $linkedin_url
 * @property ?string $twitter_url
 * @property ?string $title
 * @property ?string $discovery_source
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
    /**
     * 🔴 B10-016 — meme ecart que sur `Company`. La colonne existe (migration
     * 2026_05_16_000003, ligne 95) et six sites de lecture la filtrent :
     * ContactsHubController:115, ContactsController:50,
     * PersonTimelineController:188 et :233, BulkController:171 et :322 — plus
     * les trois index partiels de `2026_08_15_000003_contacts_liste_console_index`,
     * tous batis sur `WHERE deleted_at IS NULL`.
     *
     * Il s'agit ici de DONNEES PERSONNELLES : un contact detruit par megarde
     * n'est pas rattrapable, et la timeline qui le referencait pointe dans le
     * vide. Le masquage rend le geste reversible.
     *
     * ⚠️ Aucune route ne supprime encore de contact — `ContactsController::destroy`
     * rend `notImplemented('5')` (ligne 178). Le trait est donc pose AVANT que
     * le premier appelant n'existe : c'est le bon ordre, et c'est justement ce
     * qui a manque a `Company`, dont la route de suppression a ete ecrite en
     * supposant un soft-delete qui n'etait pas branche.
     */
    use SoftDeletes;

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
