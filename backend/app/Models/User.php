<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $email
 * @property string $name
 * @property ?int $current_workspace_id
 * @property ?\Carbon\CarbonInterface $first_login_completed_at
 * @property ?string $two_factor_secret
 * @property bool $two_factor_enabled
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $guard_name = 'web';

    protected $fillable = [
        'name', 'email', 'password', 'current_workspace_id',
        'two_factor_enabled', 'first_login_completed_at',
    ];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected function casts(): array
    {
        return [
            'email_verified_at'        => 'datetime',
            'password'                 => 'hashed',
            'first_login_completed_at' => 'datetime',
            'two_factor_enabled'       => 'boolean',
            'two_factor_recovery_codes'=> 'encrypted:array',
            'two_factor_secret'        => 'encrypted',
        ];
    }

    public function workspaces()
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')
            ->withTimestamps()
            ->withPivot('role');
    }

    public function currentWorkspace()
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }
}
