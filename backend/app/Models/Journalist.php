<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * JOURNALISTE / contact rédaction rattaché à un {@see Media}.
 *
 * ⚠️ DONNÉE PERSONNELLE (RGPD). Base légale = intérêt légitime B2B relations
 * presse. Ingestion/scraping gaté par MEDIA_JOURNALISTS_ENABLED. `source_url`
 * pour la traçabilité (transparence CNIL), `opt_out` pour le droit d'opposition,
 * soft-delete pour le droit à l'effacement.
 *
 * ⚠️ CETTE LISTE DOIT SUIVRE LE SCHÉMA, pas le confort du moment. Elle était
 * arrêtée à `opt_out` alors que la table portait déjà `source`, `source_url`,
 * `phone` et `socials` : PHPStan niveau 8 rendait donc « Access to an undefined
 * property » sur du code parfaitement valide, et la CI a rougi au rejeu du lot
 * presse (2026-08-26). Un modèle dont les propriétés ne sont pas déclarées est
 * un modèle sur lequel l'analyse statique ne peut RIEN affirmer.
 *
 * Les types ci-dessous sont RELEVÉS du schéma réel — `information_schema` en
 * production pour les colonnes historiques, la migration
 * `2026_08_25_000001_journalists_base_presse` pour celles de la base presse —
 * jamais devinés depuis `$fillable`, qui ne dit rien de la nullabilité.
 *
 * @property int $id
 * @property string $workspace_id
 * @property ?int $media_id
 * @property ?int $company_id
 * @property ?string $first_name
 * @property ?string $last_name
 * @property ?string $role
 * @property ?string $beat
 * @property ?string $email
 * @property ?string $phone
 * @property ?array<string, mixed> $socials
 * @property string $source NOT NULL
 * @property ?string $source_url
 * @property bool $opt_out
 * @property ?string $linkedin_slug
 * @property ?string $media_raw
 * @property ?string $acces
 * @property string $lien_linkedin NOT NULL, défaut 'inconnu'
 * @property ?Carbon $lien_linkedin_le
 * @property ?Carbon $lien_linkedin_verifie_le
 * @property ?int $priorite
 * @property ?int $score
 * @property ?int $abonnes
 * @property ?Carbon $collecte_le
 * @property ?string $media_portee_raw
 * @property ?string $media_support_raw
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 * @property-read ?Media $media
 * @property-read ?Workspace $workspace
 * @property-read ?Company $company
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
