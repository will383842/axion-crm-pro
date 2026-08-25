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

    /**
     * ⚠️ `dedup_key` n'y figure PAS et ne doit jamais y figurer : c'est une
     * colonne GENERATED, calculée par Postgres. L'ajouter ferait tenter un
     * INSERT dessus, que la base rejette — et le message d'erreur ne dirait pas
     * pourquoi.
     */
    protected $fillable = [
        'workspace_id', 'media_id', 'company_id',
        'first_name', 'last_name', 'role', 'beat',
        'email', 'phone', 'socials', 'source', 'source_url', 'opt_out',
        // Base presse (2026-08-25)
        'linkedin_slug', 'media_raw', 'acces',
        'lien_linkedin', 'lien_linkedin_le', 'lien_linkedin_verifie_le',
        'priorite', 'score', 'abonnes', 'collecte_le',
        'media_portee_raw', 'media_support_raw',
    ];

    protected $casts = [
        'socials' => 'array',
        'opt_out' => 'boolean',
        // Des DATE, pas des DATETIME : une mise en relation LinkedIn n'a pas
        // d'heure, et en fabriquer une ferait dériver la valeur d'un jour selon
        // le fuseau de lecture.
        'lien_linkedin_le' => 'date',
        'lien_linkedin_verifie_le' => 'date',
        'collecte_le' => 'date',
        'priorite' => 'integer',
        'score' => 'integer',
        'abonnes' => 'integer',
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
