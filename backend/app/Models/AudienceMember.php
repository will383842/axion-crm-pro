<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Membre d'une audience email (index pré-calculé).
 * Une (audience, company, contact) = une ligne.
 * Un member peut être sans contact_id si la company n'a pas de contact email valide
 * (= audience "company-level only", utile pour campagnes futures via email_generic).
 */
class AudienceMember extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'audience_members';

    protected $fillable = [
        'audience_id', 'company_id', 'contact_id', 'workspace_id', 'added_at',
    ];

    protected function casts(): array
    {
        return ['added_at' => 'datetime'];
    }

    public function audience()
    {
        return $this->belongsTo(EmailAudience::class, 'audience_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }
}
