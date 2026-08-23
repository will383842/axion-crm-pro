<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
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
 * @property string $locale NOT NULL, défaut 'fr' — colonne réelle depuis 2026_05_16_000002, jamais annotée
 * @property string $timezone NOT NULL, défaut 'Europe/Paris' — idem
 * @property ?CarbonInterface $deleted_at SoftDeletes — la colonne existe depuis l'origine
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
 * @property ?array<int, string> $totp_recovery_codes Codes de secours, hachés bcrypt
 * @property ?CarbonInterface $last_failed_login_at
 * @property-read ?Workspace $currentWorkspace
 * @property-read Collection<int, Workspace> $workspaces
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * 🔴 B10-016 — c'est ici que l'ecart coutait le plus cher.
     *
     * `users.deleted_at` existe depuis la migration 2026_05_16_000002 (ligne 65)
     * et LES QUATRE portes d'entree de l'authentification la filtrent deja :
     * AuthService:78 (mot de passe), MagicLinkService:22 et :96 (lien magique),
     * PasswordResetController:110 (reinitialisation). Ces quatre filtres ne
     * protegeaient RIEN : sans le trait, rien ne posait jamais `deleted_at`, et
     * l'etat « compte desactive » etait tout simplement INATTEIGNABLE. Le
     * produit n'avait aucun moyen de fermer un compte autrement qu'en
     * detruisant la ligne.
     *
     * Le trait rend cet etat atteignable, et `config/auth.php` le rend
     * OPPOSABLE : le fournisseur y est `eloquent` sur ce modele, donc
     * `EloquentUserProvider::retrieveById()` construit une requete du modele et
     * subit le scope global. Un compte ferme perd donc aussi ses sessions et
     * ses jetons Sanctum deja emis — ce que les quatre filtres ci-dessus, qui
     * ne couvrent que l'ouverture de session, ne faisaient pas.
     *
     * ⚠️ Limite connue, NON corrigee ici (ce serait une migration, hors
     * perimetre) : `users.email` porte un UNIQUE inconditionnel (ligne 45),
     * tandis que l'index `idx_users_email_active` (ligne 67) est partiel sur
     * `deleted_at IS NULL`. Le schema a donc ete pense pour qu'un compte ferme
     * LIBERE son adresse — mais le UNIQUE inconditionnel l'en empeche. Une
     * adresse reste donc squattee par le compte ferme.
     */
    use SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var string Garde Spatie/Permission — non typée dans le trait HasRoles. */
    protected $guard_name = 'web';
    // table par défaut 'users' OK

    /**
     * 🔴 Les colonnes de la 2FA s'appellent `totp_*`, PAS `two_factor_*`.
     * Ce tableau declarait `two_factor_secret`, `two_factor_enabled` et
     * `two_factor_recovery_codes` : aucune n'existe en base (migration 000002).
     * Comme Eloquent cree un attribut dynamique sans broncher, l'erreur
     * n'apparaissait qu'a l'enregistrement, en SQL, et l'enrolement 2FA etait
     * mort - donc la premiere connexion aussi (A07-001 / F35-002).
     * « La 2FA est active » se lit sur `totp_enabled_at` : pas de drapeau booleen
     * separe a tenir synchronise.
     */
    protected $fillable = [
        'id', 'name', 'email', 'password_hash', 'current_workspace_id',
        'locale', 'timezone',
        'first_login_completed_at', 'onboarding_tour_completed_at',
        'totp_enabled_at', 'totp_secret', 'totp_recovery_codes',
        'last_login_at', 'last_login_ip', 'last_login_user_agent',
        'failed_login_count', 'last_failed_login_at', 'locked_until', 'email_verified_at',
    ];

    protected $hidden = ['password_hash', 'remember_token', 'totp_secret', 'totp_recovery_codes'];

    public function getAuthPassword(): ?string
    {
        return $this->password_hash;
    }

    /**
     * La double authentification est-elle active sur ce compte ?
     *
     * Remplace l'ancien attribut `two_factor_enabled`, qui n'avait pas de colonne
     * et donnait un booleen toujours faux (F35-002).
     */
    public function twoFactorEnabled(): bool
    {
        return $this->totp_enabled_at !== null;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'first_login_completed_at' => 'datetime',
            'onboarding_tour_completed_at' => 'datetime',
            'totp_enabled_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_failed_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'totp_recovery_codes' => 'encrypted:array',
            'totp_secret' => 'encrypted',
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
