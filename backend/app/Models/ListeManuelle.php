<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Une LISTE MANUELLE : des fiches choisies à la main, sous un nom
 * (« Invités salon GOFAB »). Migration `2026_10_01_000040`.
 *
 * Elle ne porte aucune adresse : ses membres (`listes_manuelles_membres`)
 * désignent des organisations ou des personnes qui existent déjà dans le CRM.
 * Elle ne se supprime jamais : `delete()` la met à la corbeille
 * (`SoftDeletes`), `restore()` l'en sort.
 *
 * @property int $id
 * @property string $workspace_id UUID
 * @property string $nom
 * @property ?string $description
 * @property ?string $created_by UUID de l'utilisateur, NULL si compte supprimé
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 */
class ListeManuelle extends Model
{
    use SoftDeletes;

    protected $table = 'listes_manuelles';

    protected $fillable = ['workspace_id', 'nom', 'description', 'created_by'];
}
