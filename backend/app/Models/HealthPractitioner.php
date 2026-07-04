<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Professionnel de santé (Annuaire Santé / RPPS « PS LibreAccès »).
 *
 * ⚠️ Donnée nominative de SANTÉ (RGPD art. 9). Ingestion gatée par
 * SANTE_INGESTION_ENABLED. Rattaché à une {@see Company} par SIREN.
 *
 * @property int     $id
 * @property string  $workspace_id
 * @property ?int    $company_id
 * @property ?string $siren
 * @property ?string $rpps
 * @property ?string $specialite
 */
class HealthPractitioner extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'workspace_id', 'company_id', 'siren', 'rpps',
        'nom', 'prenom', 'specialite',
        'phone', 'email', 'address', 'postcode', 'city', 'source',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
