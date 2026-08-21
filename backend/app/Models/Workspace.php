<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id UUID
 * @property string $name
 * @property string $slug
 * @property array $settings
 */
class Workspace extends Model
{
    use HasFactory;

    /**
     * 🔴 B10-016 — l'espace de travail est le LOCATAIRE : le detruire est le
     * geste le plus destructeur du produit, et c'etait le moins protege.
     *
     * `workspaces.deleted_at` existe (migration 2026_05_16_000002, ligne 36) et
     * l'index `idx_workspaces_slug_active` (ligne 38) est explicitement partiel
     * sur `deleted_at IS NULL` : le schema a ete concu pour une corbeille.
     *
     * Sans le trait, un `->delete()` emettait un DELETE dur, et DEUX cles
     * etrangeres achevaient le travail sans repasser par Eloquent :
     *   - `user_workspaces.workspace_id ... ON DELETE CASCADE` (ligne 75) :
     *     toutes les appartenances, avec leurs roles, disparaissaient ;
     *   - `users.current_workspace_id ... ON DELETE SET NULL` (ligne 51) :
     *     chaque membre se retrouvait sans espace courant.
     * Autrement dit : un seul geste, irreversible, sur toute une organisation.
     *
     * ⚠️ Meme limite que sur `User` : `slug` porte un UNIQUE inconditionnel
     * (ligne 29). Un espace masque garde donc son slug — une migration serait
     * necessaire pour le liberer, et elle sort du perimetre de ce lot.
     */
    use SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'name', 'slug', 'settings', 'cost_cap_eur', 'is_active'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_active' => 'boolean',
            'cost_cap_eur' => 'decimal:2',
        ];
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_workspaces')
            ->withTimestamps(false)
            ->withPivot(['role_slug', 'invited_at', 'joined_at', 'revoked_at']);
    }

    public function companies()
    {
        return $this->hasMany(Company::class);
    }
}
