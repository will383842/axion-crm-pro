<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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

    public function companies()
    {
        return $this->belongsToMany(Company::class);
    }

    /** @return BelongsToMany<Candidate, $this> */
    public function candidates(): BelongsToMany
    {
        return $this->belongsToMany(Candidate::class, 'candidate_tag');
    }
}
