<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property string $id UUID
 * @property string $email
 * @property string $name
 * @property ?string $password_hash Hachage du mot de passe — la colonne ne s'appelle PAS `password`
 * @property ?string $current_workspace_id UUID
 * @property ?CarbonInterface $first_login_completed_at
 * @property ?CarbonInterface $onboarding_tour_completed_at
 * @property ?CarbonInterface $email_verified_at
 * @property ?CarbonInterface $last_login_at
 * @property ?string $last_login_ip
 * @property ?string $last_login_user_agent
 * @property int $failed_login_count NOT NULL, défaut 0
 * @property ?CarbonInterface $locked_until
 * @property ?string $totp_secret
 * @property ?CarbonInterface $totp_enabled_at
 * @property ?string $two_factor_secret
 * @property bool $two_factor_enabled
 * @property ?array<int, string> $two_factor_recovery_codes
 * @property-read ?Workspace $currentWorkspace
 * @property-read Collection<int, Workspace> $workspaces
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var string Garde Spatie/Permission — non typée dans le trait HasRoles. */
    protected $guard_name = 'web';
    // table par défaut 'users' OK

    protected $fillable = [
        'id', 'name', 'email', 'password_hash', 'current_workspace_id',
        'two_factor_enabled', 'first_login_completed_at', 'onboarding_tour_completed_at',
        'totp_enabled_at', 'totp_secret', 'two_factor_secret', 'two_factor_recovery_codes',
        'last_login_at', 'last_login_ip', 'last_login_user_agent',
        'failed_login_count', 'locked_until', 'email_verified_at',
    ];

    protected $hidden = ['password_hash', 'remember_token', 'totp_secret', 'two_factor_secret', 'two_factor_recovery_codes'];

    public function getAuthPassword(): ?string
    {
        return $this->password_hash;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'first_login_completed_at' => 'datetime',
            'onboarding_tour_completed_at' => 'datetime',
            'totp_enabled_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'two_factor_enabled' => 'boolean',
            'two_factor_recovery_codes' => 'encrypted:array',
            'totp_secret' => 'encrypted',
            'two_factor_secret' => 'encrypted',
        ];
    }

    /** @return BelongsToMany<Workspace, $this> */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'user_workspaces')
            ->withTimestamps(false)
            ->withPivot(['role_slug', 'invited_at', 'joined_at', 'revoked_at']);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function currentWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }
}
