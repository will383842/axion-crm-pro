# 15 — AUTH + MULTI-TENANT + RBAC

## Vue d'ensemble

Auth Axion CRM Pro = **Laravel Sanctum cookie SPA** + **TOTP 2FA obligatoire** + **magic link backup**. Multi-tenant = `workspace_id` injecté côté middleware + **RLS PostgreSQL** au niveau DB. RBAC = **Spatie Laravel Permission** avec 4 rôles : `owner`, `admin`, `operator`, `viewer`. Toute action sensible est tracée dans `audit_logs` (table append-only + hash chain).

---

## 1. Laravel Sanctum cookie SPA

### Configuration `config/sanctum.php`

```php
return [
    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', 'localhost,127.0.0.1,crm.axion-ia.com')),
    'guard'    => ['web'],
    'expiration' => 60 * 24 * 7,                  // 7 jours (re-login après)
    'middleware' => [
        'authenticate_session' => Authenticate::class,
        'encrypt_cookies'      => EncryptCookies::class,
        'validate_csrf_token'  => ValidateCsrfToken::class,
    ],
];
```

### Flux login complet

```
1. SPA appelle GET /api/auth/csrf  → set cookie XSRF-TOKEN (HttpOnly=false, lecture JS pour header X-XSRF-TOKEN)
2. SPA appelle POST /api/auth/login avec { email, password, totp_code? } + header X-XSRF-TOKEN
3. Laravel valide email + password + (si user.totp_enabled_at) valide TOTP
4. Laravel crée session (table `sessions`) et retourne cookie session_id HttpOnly Secure SameSite=Lax
5. SPA stocke pas le cookie en JS (HttpOnly), mais le navigateur l'envoie automatiquement
6. Routes API auth:sanctum vérifient session_id valide
7. POST /api/auth/logout → DELETE session + clear cookie
```

### Middleware `auth:sanctum`

Standard Laravel. Refuse si :
- Pas de cookie session_id
- Session expirée
- IP ne match pas `sessions.ip_address` (option strict, désactivable)

---

## 2. TOTP 2FA obligatoire (`pragmarx/google2fa-laravel`)

### Setup utilisateur

```php
namespace App\Modules\Auth\Services;

use PragmaRX\Google2FA\Google2FA;
use Illuminate\Support\Facades\Crypt;

final class TwoFactorService
{
    public function __construct(private Google2FA $g2fa) {}

    /** Génère secret + QR code pour 1er setup */
    public function setup(User $user): array
    {
        $secret = $this->g2fa->generateSecretKey(32);
        $user->totp_secret = Crypt::encryptString($secret);
        $user->save();
        $qrUrl = $this->g2fa->getQRCodeUrl('Axion CRM Pro', $user->email, $secret);

        // 8 codes de backup, hashés bcrypt
        $backupCodes = collect(range(1, 8))->map(fn () => strtoupper(bin2hex(random_bytes(4))));
        $user->backup_codes_hash = $backupCodes->map(fn ($c) => bcrypt($c))->all();
        $user->save();

        return [
            'qr_code_url' => $qrUrl,
            'secret' => $secret,                       // affiché 1 seule fois
            'backup_codes' => $backupCodes->all(),     // affichés 1 seule fois
        ];
    }

    /** Vérifie code TOTP lors login OU finalize setup */
    public function verify(User $user, string $code): bool
    {
        if (!$user->totp_secret) return false;
        $secret = Crypt::decryptString($user->totp_secret);
        if ($this->g2fa->verifyKey($secret, $code, window: 1)) {
            if (!$user->totp_enabled_at) {
                $user->totp_enabled_at = now();
                $user->save();
                $this->audit('user.2fa.enabled', $user);
            }
            return true;
        }
        // Tentative avec backup code
        foreach ($user->backup_codes_hash ?? [] as $i => $hash) {
            if (Hash::check($code, $hash)) {
                $codes = $user->backup_codes_hash;
                unset($codes[$i]);
                $user->backup_codes_hash = array_values($codes);
                $user->save();
                $this->audit('user.2fa.backup_code_used', $user);
                return true;
            }
        }
        $this->audit('user.2fa.failed_verify', $user);
        return false;
    }

    private function audit(string $action, User $user): void
    {
        AuditLog::logSafe([
            'workspace_id' => $user->currentWorkspaceId(),
            'actor_user_id' => $user->id,
            'action' => $action,
            'entity_type' => 'user',
            'entity_id' => $user->id,
            'payload' => ['email' => $user->email],
        ]);
    }
}
```

### Politique

- 2FA **obligatoire** pour tous les users sauf premier login → setup forcé
- Reset 2FA possible uniquement par `owner` avec confirmation password owner
- Pas de "Remember device" (politique stricte : TOTP à chaque session)
- 8 backup codes générés, consommables 1 fois chacun

---

## 3. Magic link backup

Pour les cas où l'utilisateur perd accès à son 2FA ou son mot de passe :

### Endpoint `POST /api/auth/magic-link/request`

```php
final class MagicLinkController
{
    public function request(MagicLinkRequest $r, Mailer $mailer, AuditLog $audit): JsonResponse
    {
        $user = User::query()->where('email', $r->email)->first();
        if ($user) {                                       // ne révèle pas si email existe
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            Cache::put("magic-link:{$tokenHash}", $user->id, now()->addMinutes(15));
            $mailer->to($user->email)->send(new MagicLinkMail($token));
            $audit->log('user.magic_link.requested', user: $user);
        }
        return response()->json(['ok' => true]);
    }

    public function consume(string $token): JsonResponse
    {
        $hash = hash('sha256', $token);
        $userId = Cache::pull("magic-link:{$hash}");
        if (!$userId) return response()->json(['error' => 'invalid_or_expired'], 422);
        $user = User::findOrFail($userId);
        Auth::login($user);
        $audit->log('user.magic_link.consumed', user: $user);
        return response()->json(['user' => UserResource::make($user)]);
    }
}
```

- Token valide 15 minutes
- Single-use (Cache::pull supprime après lecture)
- Pas de revelation si email inconnu (réponse OK identique)
- Mail envoyé via SES/Postmark/Mailgun (config futur)
- Magic link **NE BYPASSE PAS** 2FA — l'user devra encore valider TOTP au login

---

## 4. Multi-tenant middleware

### `App\Http\Middleware\InjectWorkspace`

```php
final class InjectWorkspace
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user) return $next($request);

        $workspaceId = $request->session()->get('current_workspace_id');
        if (!$workspaceId) {
            $workspaceId = $user->workspaces()->first()?->id;
            if (!$workspaceId) {
                return response()->json(['error' => 'no_workspace_assigned'], 403);
            }
            $request->session()->put('current_workspace_id', $workspaceId);
        }

        // Inject pour RLS PostgreSQL
        DB::statement("SET LOCAL app.workspace_id = ?", [$workspaceId]);
        // Inject pour accès facile dans controllers
        $request->merge(['_workspace_id' => $workspaceId]);
        $user->currentWorkspaceId = $workspaceId;

        return $next($request);
    }
}
```

Enregistré dans `app/Http/Kernel.php` → groupe `auth:sanctum`.

### Endpoint `POST /api/auth/workspaces/switch`

```php
public function switch(Request $request, int $workspaceId): JsonResponse
{
    $user = $request->user();
    if (!$user->workspaces()->where('workspace_id', $workspaceId)->exists()) {
        abort(403, 'not_member_of_workspace');
    }
    $request->session()->put('current_workspace_id', $workspaceId);
    AuditLog::logSafe([
        'workspace_id' => $workspaceId,
        'actor_user_id' => $user->id,
        'action' => 'user.workspace.switched',
        'entity_type' => 'workspace',
        'entity_id' => $workspaceId,
    ]);
    return response()->json(['ok' => true]);
}
```

---

## 5. Row-Level Security (RLS) PostgreSQL

### Helper SQL global

Défini dans le bootstrap DB (`migrations/0000_bootstrap.sql`) — cf fichier 03 :

```sql
CREATE OR REPLACE FUNCTION app_workspace_id() RETURNS BIGINT AS $$
  SELECT NULLIF(current_setting('app.workspace_id', TRUE), '')::BIGINT;
$$ LANGUAGE SQL STABLE;
```

### Policies systématiques

Pour chaque table tenant-scoped (cf liste fichier 03) :

```sql
ALTER TABLE companies ENABLE ROW LEVEL SECURITY;
CREATE POLICY companies_tenant_isolation ON companies
  USING (workspace_id = app_workspace_id())
  WITH CHECK (workspace_id = app_workspace_id());
```

Cela signifie qu'**aucune requête** ne peut accéder à une ligne d'un autre workspace, même un `SELECT * FROM companies` brut. La policy `USING` filtre les lectures, `WITH CHECK` empêche les insertions cross-workspace.

### Role `axion_crm_app` (Laravel) — RLS activée

```sql
GRANT axion_crm_app TO postgres;     -- transitivité pour app
ALTER ROLE axion_crm_app SET row_security TO 'on';
```

### Bypass admin (réservé super_admin Will)

Pour debugging / opérations cross-workspace exceptionnelles :

```sql
ALTER ROLE axion_crm_app BYPASSRLS;       -- ❌ NE PAS FAIRE
-- À la place :
CREATE ROLE axion_crm_super NOLOGIN BYPASSRLS;
GRANT axion_crm_super TO postgres;
```

Le super role n'est utilisé que pour scripts spécifiques (RGPD cross-workspace, rapports globaux).

---

## 6. Spatie Laravel Permission — RBAC 4 rôles

### Modèle User

```php
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles;

    public function workspaces() {
        return $this->belongsToMany(Workspace::class, 'user_workspaces');
    }

    public function currentWorkspaceId(): ?int
    {
        return $this->currentWorkspaceId ?? null;       // set par middleware
    }
}
```

### Seeder rôles

```php
namespace Database\Seeders;

use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

final class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Permissions (granulaires, 50+)
        $perms = [
            // Companies
            'companies.view', 'companies.create', 'companies.update', 'companies.delete',
            'companies.bulk-export', 'companies.bulk-disqualify', 'companies.override-priority',
            'companies.relaunch-enrichment',
            // Contacts
            'contacts.view', 'contacts.create', 'contacts.update', 'contacts.delete',
            // Scraping
            'scraper.runs.view', 'scraper.sources.view', 'scraper.sources.configure',
            'scraper.targets.launch',
            // Coverage
            'coverage.view', 'coverage.refresh-matrix',
            // LLM
            'llm.use-cases.view', 'llm.use-cases.update', 'llm.templates.update',
            'llm.usage.view', 'llm.test',
            // Proxies
            'proxies.view', 'proxies.update', 'proxies.add-provider',
            // Rotations
            'rotations.view', 'rotations.refresh',
            // GDPR
            'gdpr.view', 'gdpr.process', 'gdpr.opt-out.manage',
            // Users
            'users.view', 'users.invite', 'users.update', 'users.disable',
            // Workspace
            'workspace.view', 'workspace.update', 'workspace.delete',
            // Audit
            'audit-logs.view', 'audit-logs.verify-integrity',
            // Monitoring
            'monitoring.view', 'monitoring.acknowledge-alerts',
        ];
        foreach ($perms as $p) Permission::firstOrCreate(['name' => $p]);

        // Rôles
        Role::firstOrCreate(['name' => 'owner'])    ->givePermissionTo(Permission::all());
        Role::firstOrCreate(['name' => 'admin'])    ->givePermissionTo(array_filter($perms, fn ($p) => !str_starts_with($p, 'workspace.delete')));
        Role::firstOrCreate(['name' => 'operator']) ->givePermissionTo([
            'companies.view', 'companies.update', 'companies.override-priority',
            'companies.relaunch-enrichment', 'companies.bulk-export',
            'contacts.view', 'contacts.update',
            'scraper.runs.view', 'scraper.sources.view', 'scraper.targets.launch',
            'coverage.view', 'rotations.view', 'monitoring.view',
            'llm.usage.view', 'audit-logs.view',
        ]);
        Role::firstOrCreate(['name' => 'viewer'])   ->givePermissionTo([
            'companies.view', 'contacts.view',
            'scraper.runs.view', 'coverage.view',
            'monitoring.view', 'llm.usage.view',
        ]);
    }
}
```

### Usage dans controllers

```php
final class CompaniesController
{
    public function override(string $uuid, OverrideScoresRequest $r): JsonResponse
    {
        $this->authorize('override-priority', Company::class);    // policy Spatie
        // ...
    }
}
```

Ou via middleware route :

```php
Route::middleware(['auth:sanctum', 'permission:companies.override-priority'])
    ->post('/companies/{uuid}/override-priority', [CompaniesController::class, 'override']);
```

---

## 7. Audit log append-only avec hash chain

### Service `AuditLog::logSafe`

```php
namespace App\Modules\AuditLog\Services;

final class AuditLogger
{
    public function log(array $data): void
    {
        DB::transaction(function () use ($data) {
            $prev = DB::table('audit_logs')->orderByDesc('id')->lockForUpdate()->first();
            $prevHash = $prev?->row_hash ?? str_repeat('0', 64);
            $payload = json_encode($data['payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $ts = now()->toIso8601String();
            $rowHash = hash('sha256', $prevHash . $data['action'] . $ts . $payload);

            DB::table('audit_logs')->insert([
                'workspace_id' => $data['workspace_id'] ?? null,
                'actor_user_id' => $data['actor_user_id'] ?? null,
                'actor_ip' => request()?->ip(),
                'actor_agent' => request()?->userAgent(),
                'action' => $data['action'],
                'entity_type' => $data['entity_type'] ?? null,
                'entity_id' => $data['entity_id'] ?? null,
                'payload' => $payload,
                'prev_hash' => $prevHash,
                'row_hash' => $rowHash,
                'occurred_at' => now(),
            ]);
        });
    }
}
```

### Vérification d'intégrité (`POST /api/audit-logs/verify-integrity`)

```php
final class HashChainVerifier
{
    public function verify(?int $fromId = null): array
    {
        $broken = [];
        $prevHash = str_repeat('0', 64);
        $lastVerified = 0;
        DB::table('audit_logs')->orderBy('id')->chunkById(1000, function ($rows) use (&$prevHash, &$broken, &$lastVerified) {
            foreach ($rows as $row) {
                $ts = $row->occurred_at;
                $payload = $row->payload;
                $expected = hash('sha256', $prevHash . $row->action . $ts . $payload);
                if ($expected !== $row->row_hash) {
                    $broken[] = ['id' => $row->id, 'expected' => $expected, 'stored' => $row->row_hash];
                }
                $prevHash = $row->row_hash;
                $lastVerified = $row->id;
            }
        });
        return ['ok' => empty($broken), 'broken' => $broken, 'last_verified_id' => $lastVerified];
    }
}
```

Triggers PostgreSQL bloquent les UPDATE et DELETE (cf fichier 03) → impossible de réécrire un audit log sans casser la chaîne.

---

## 8. UI invitations / gestion users / 2FA setup (page 15)

Wireframe (cf fichier 13 page 15) :

- Table users (liste + filtre rôle + status)
- Bouton "Inviter utilisateur" → modal (email + rôle) → POST `/api/users/invitations`
- Email de bienvenue avec lien `/accept-invitation?token=...`
- Page `/accept-invitation` (publique) : formulaire création password + first/last name + setup 2FA forcé en step 2
- Bouton "Reset 2FA" sur user → modale exige password owner → invalide `totp_secret` + force user à re-setup au prochain login
- Bouton "Désactiver" → status `disabled`, sessions actives invalidées

---

## 9. Sécurité headers HTTP

Middleware `App\Http\Middleware\SecurityHeaders` injecte sur toutes les réponses :

```php
$response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
$response->headers->set('X-Frame-Options', 'DENY');
$response->headers->set('X-Content-Type-Options', 'nosniff');
$response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
$response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
$response->headers->set('Content-Security-Policy', "default-src 'self'; ...");
```

CSP strict avec nonce dynamique pour les scripts inline du SPA (cf fichier 17 OWASP).

---

## 10. Critères de done (S1)

- [ ] Login + 2FA fonctionne en e2e (Playwright test)
- [ ] Magic link envoie email + consomme correctement (single-use 15min)
- [ ] Reset 2FA exige password owner
- [ ] RLS PostgreSQL : test fuzzing avec 2 workspaces fictifs → 0 leak cross-tenant
- [ ] 4 rôles seedés avec permissions cohérentes
- [ ] Audit log hash chain vérifié OK sur 10k entries simulées
- [ ] Trigger PG bloque tentative UPDATE/DELETE sur `audit_logs`
- [ ] CSP appliquée : aucune exception dans console browser sur les 17 pages

---

## 11. Anti-patterns interdits

- ❌ JWT bearer token (utiliser cookie Sanctum)
- ❌ `Auth::loginUsingId()` sans audit
- ❌ Stocker secret TOTP en clair (chiffrer via `Crypt::encryptString`)
- ❌ Désactiver RLS "temporairement" en prod
- ❌ Bypass middleware `InjectWorkspace` (= leak cross-tenant garanti)
- ❌ Permettre UPDATE direct `audit_logs` (les triggers le bloquent — ne pas les retirer)
- ❌ Magic link sans token hashé (stocker uniquement le hash en cache)

---

## Prochaine étape

→ Lire `16_monitoring_observabilite.md` pour les 40+ métriques Prometheus + 10 dashboards.
