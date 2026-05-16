<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property ?string $logo_url
 * @property array $settings
 */
class Workspace extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'logo_url', 'settings'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->withTimestamps()
            ->withPivot('role');
    }

    public function companies()
    {
        return $this->hasMany(Company::class);
    }
}
