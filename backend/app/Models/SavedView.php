<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Une vue sauvegardee — un filtre nomme, propre a UNE personne dans UN espace.
 *
 * Ce modele est ne le 2026-08-23, en fermant les quatre routes 501 de
 * `SavedViewsController`. Il n'existait pas : le controleur passait par
 * `DB::table('saved_views')`, ce qui marche tres bien pour lire et ecrire —
 * mais la garde `G43-005` exige `refuserSiVersionPerimee()`, et le trait
 * `VerrouOptimiste` a besoin d'un `Model` pour calculer son jeton de version.
 *
 * ⚠️ `UNIQUE (user_id, entity, name)` vit dans la migration `2026_05_16_000006`.
 * Le controleur le verifie AVANT d'ecrire, pour rendre un 422 qui nomme le champ
 * plutot qu'un 500 venu de Postgres.
 */
class SavedView extends Model
{
    protected $table = 'saved_views';

    protected $fillable = [
        'workspace_id', 'user_id', 'entity', 'name', 'filters', 'is_default',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'is_default' => 'boolean',
        ];
    }
}
