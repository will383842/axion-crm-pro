<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id    UUID
 * @property string $name
 * @property string $slug
 * @property array $settings
 */
class Workspace extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['id', 'name', 'slug', 'settings', 'cost_cap_eur', 'is_active'];

    protected function casts(): array
    {
        return [
            'settings'     => 'array',
            'is_active'    => 'boolean',
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
