<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Colonnes réelles de la table `tags` (migrations 2026_05_16_000003,
 * 2026_05_18_000007 et 2026_08_14_000004).
 *
 * @property int $id
 * @property string $workspace_id UUID
 * @property string $slug
 * @property string $name
 * @property ?string $color
 * @property ?string $description
 * @property string $category geo|sector|size|intent|custom|candidate (NOT NULL, défaut 'custom')
 * @property string $kind auto|manual|llm (NOT NULL, défaut 'manual')
 * @property bool $is_locked
 * @property ?string $namespace GENERATED : partie du slug avant le « : », NULL sinon — jamais écrite
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Collection<int, Company> $companies
 * @property-read Collection<int, Candidate> $candidates
 *
 * `$rules` (JSONB NOT NULL DEFAULT '{}', casté en `array`) est VOLONTAIREMENT
 * absent de cette liste. Le déclarer `array<string, mixed>` rend redondant le
 * `! is_array($rules)` de AutoTagApplier::apply() — PHPStan le signale comme
 * toujours vrai. Or ce garde-fou protège d'un JSONB scalaire (`json_decode`
 * d'un `"5"` rend un `int`, non vide et non tableau) : le retirer changerait le
 * comportement, ce qui sort du cadre de cette ligne. Une entrée de baseline
 * couvre donc `Tag::$rules` dans AutoTagApplier. Pour la solder : durcir le
 * contrat de la colonne (CHECK `jsonb_typeof(rules) = 'object'`) puis retirer
 * le garde-fou ET l'entrée de baseline dans le même geste.
 */
class Tag extends Model
{
    use BelongsToWorkspace;
    use HasFactory;

    // ATTENTION : `namespace` est une GENERATED COLUMN côté Postgres
    // (migration 2026_08_14_000004, `split_part(slug, ':', 1)`) — exclue de
    // fillable : toute écriture dessus lèverait une erreur Postgres.
    protected $fillable = [
        'workspace_id', 'slug', 'name', 'color', 'description', 'rules',
        // Sprint Pipeline 360° (2026-05-17)
        'category', 'kind',
        // Lot L1 — gouvernance du référentiel
        'is_locked',
    ];

    protected function casts(): array
    {
        return ['rules' => 'array', 'is_locked' => 'boolean'];
    }

    /** @return BelongsToMany<Company, $this> */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class);
    }

    /** @return BelongsToMany<Candidate, $this> */
    public function candidates(): BelongsToMany
    {
        return $this->belongsToMany(Candidate::class, 'candidate_tag');
    }
}
