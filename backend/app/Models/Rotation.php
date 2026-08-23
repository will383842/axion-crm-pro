<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Une entree de rotation — la dimension et le nom IDENTIFIENT la ligne pour le
 * tirage ; le poids, le repos et l'activation en reglent le comportement.
 *
 * Ne le 2026-08-23 pour la meme raison que `SavedView` : la garde `G43-005`
 * exige `refuserSiVersionPerimee()` sur tout `update()` vivant, et le trait
 * `VerrouOptimiste` calcule son jeton depuis les attributs d'un `Model`.
 *
 * ⚠️ `dimension` porte un CHECK (proxy, user_agent, target, search_engine, llm)
 * dans la migration `2026_05_16_000004`. Le controleur ne la laisse pas changer :
 * la modifier reviendrait a deplacer la ligne dans un autre tirage.
 */
class Rotation extends Model
{
    protected $table = 'rotations';

    protected $fillable = [
        'workspace_id', 'dimension', 'slug', 'weight',
        'cooldown_seconds', 'enabled', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'metadata' => 'array',
            'last_used_at' => 'datetime',
        ];
    }
}
