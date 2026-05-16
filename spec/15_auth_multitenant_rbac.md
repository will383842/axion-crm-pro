# 15 — Auth + multi-tenant + RBAC

> **Stack :** Sanctum SPA cookie + TOTP 2FA obligatoire + Magic link + middleware workspace + RLS PostgreSQL + Spatie Permission + audit hash chain.

---

## §1 — Laravel Sanctum SPA cookie

### Configuration

```php
// config/sanctum.php (extraits)
'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', 'localhost,crm.axion-pro.com,api.axion-pro.com')),
'guard' => ['web'],
'expiration' => null,    // session cookies (pas tokens)
'middleware' => [
    'verify_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
],

// config/session.php
'driver' => 'redis',
'connection' => 'sessions',
'lifetime' => 720,    // 12h
'expire_on_close' => true,
'secure' => env('SESSION_SECURE_COOKIE', true),
'http_only' => true,
'same_site' => 'lax',
'domain' => '.axion-pro.com',
```

### Flow login SPA

```
Frontend                              Backend                     DB
────────                              ─────────                   ────
                                                              
GET /sanctum/csrf-cookie  ─────────▶  set XSRF-TOKEN cookie
                                                              
POST /login                ─────────▶  validate credentials
   email, password                    check 2FA enabled?
   X-XSRF-TOKEN header                if yes: return {requires_2fa: true}
                          ◀─────────  if no: set session cookie
                                      INSERT audit_logs(login.success)
                                                              
POST /two-factor/verify    ─────────▶  validate TOTP code
   code: '123456'                     INSERT audit_logs(two_factor.success)
                          ◀─────────  set session cookie

GET /api/v1/me             ─────────▶  return user + workspace context
                          ◀─────────  
```

### CSRF protection

Toute requête `POST/PUT/PATCH/DELETE` doit inclure header `X-XSRF-TOKEN` (extrait du cookie).

### LoginController

```php
class LoginController extends Controller
{
    public function store(LoginRequest $req): JsonResponse
    {
        if (! Auth::attempt($req->only('email','password'), $req->boolean('remember'))) {
            $this->markFailedLogin($req->email);
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }
        $user = Auth::user();
        if ($user->locked_until && $user->locked_until->isFuture()) {
            Auth::logout();
            throw ValidationException::withMessages(['email' => __('auth.locked')]);
        }
        $req->session()->regenerate();

        if ($user->totp_enabled_at) {
            session()->put('two_factor_required', $user->id);
            return response()->json(['requires_two_factor' => true]);
        }

        $user->update(['last_login_at' => now(), 'last_login_ip' => $req->ip(), 'failed_login_count' => 0]);
        AuditLog::record('user.login.success', $user, ['ip' => $req->ip()]);
        return response()->json(['user' => UserData::from($user)]);
    }

    private function markFailedLogin(string $email): void
    {
        $u = User::firstWhere('email', $email);
        if (! $u) return;
        $u->increment('failed_login_count');
        if ($u->failed_login_count >= 5) {
            $u->update(['locked_until' => now()->addMinutes(15)]);
            AuditLog::record('user.locked', $u, ['failed_count' => $u->failed_login_count]);
            event(new UserAccountLocked($u));
        }
    }
}
```

---

## §2 — TOTP 2FA obligatoire

### Setup TOTP (enable)

```php
class TwoFactorController extends Controller
{
    public function enable(Request $req): JsonResponse
    {
        $user = $req->user();
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();
        $qrCodeUrl = $google2fa->getQRCodeUrl(
            company: 'Axion CRM Pro',
            holder:  $user->email,
            secret:  $secret,
        );
        // Stocker en pending, validation requise avec 1er code
        cache()->put("totp_setup:{$user->id}", $secret, 600);
        return response()->json(['secret' => $secret, 'qr_code_url' => $qrCodeUrl]);
    }

    public function confirm(Request $req): JsonResponse
    {
        $user = $req->user();
        $secret = cache()->pull("totp_setup:{$user->id}");
        if (!$secret) abort(400, 'setup_expired');
        $google2fa = new Google2FA();
        if (!$google2fa->verifyKey($secret, $req->input('code'))) {
            throw ValidationException::withMessages(['code' => 'invalid']);
        }
        $recoveryCodes = $this->generateRecoveryCodes();
        $user->update([
            'totp_secret' => Crypt::encryptString($secret),
            'totp_enabled_at' => now(),
            'totp_recovery_codes' => array_map(fn($c) => Hash::make($c), $recoveryCodes),
        ]);
        AuditLog::record('user.two_factor.enabled', $user);
        return response()->json(['recovery_codes' => $recoveryCodes]);
    }

    public function verify(Request $req): JsonResponse
    {
        $userId = session()->pull('two_factor_required');
        if (!$userId) abort(401);
        $user = User::findOrFail($userId);
        $code = $req->input('code');
        $google2fa = new Google2FA();
        $secret = Crypt::decryptString($user->totp_secret);

        if ($google2fa->verifyKey($secret, $code, 1)) {
            Auth::loginUsingId($user->id);
            $user->update(['last_login_at' => now(), 'failed_login_count' => 0]);
            AuditLog::record('user.two_factor.success', $user);
            return response()->json(['user' => UserData::from($user)]);
        }
        // try recovery codes
        if ($this->verifyRecoveryCode($user, $code)) {
            Auth::loginUsingId($user->id);
            AuditLog::record('user.two_factor.recovery_code_used', $user);
            return response()->json(['user' => UserData::from($user)]);
        }
        throw ValidationException::withMessages(['code' => 'invalid']);
    }

    private function generateRecoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))->map(fn() => strtoupper(Str::random(10)))->toArray();
    }
}
```

### Storage

- `users.totp_secret` : AES-256-GCM encrypted (Laravel `Crypt`)
- `users.totp_recovery_codes` : array de bcrypt hashes
- `users.totp_enabled_at` : timestamp activation

### UI

Cf. `13_ui_admin_phase1.md` § 1 (page two-factor).

---

## §3 — Magic link

### Request flow

```php
class MagicLinkController extends Controller
{
    public function request(Request $req): JsonResponse
    {
        $req->validate(['email' => 'required|email']);
        $user = User::firstWhere('email', $req->email);
        if (!$user) return response()->json(['sent' => true]);  // ne révèle pas existence

        $token = Str::random(64);
        cache()->put("magic_link:" . hash('sha256', $token), $user->id, 900);   // 15 min
        Mail::to($user)->send(new MagicLinkEmail($token));
        AuditLog::record('user.magic_link.requested', $user, ['ip' => $req->ip()]);
        return response()->json(['sent' => true]);
    }

    public function consume(string $token): JsonResponse|RedirectResponse
    {
        $userId = cache()->pull("magic_link:" . hash('sha256', $token));
        if (!$userId) return redirect('/login?error=link_expired');
        $user = User::find($userId);
        if (!$user) return redirect('/login?error=link_invalid');

        if ($user->totp_enabled_at) {
            session()->put('two_factor_required', $user->id);
            return redirect('/two-factor');
        }
        Auth::loginUsingId($user->id);
        AuditLog::record('user.magic_link.success', $user);
        return redirect('/dashboard');
    }
}
```

---

## §4 — Middleware workspace + RLS

### `SetCurrentWorkspace`

```php
// app/Http/Middleware/SetCurrentWorkspace.php
class SetCurrentWorkspace
{
    public function handle(Request $req, Closure $next): Response
    {
        $user = $req->user();
        if (!$user) return $next($req);

        $wsId = $user->current_workspace_id ?? $this->resolveDefaultWorkspace($user);

        if (! UserWorkspace::where('user_id', $user->id)
            ->where('workspace_id', $wsId)
            ->whereNull('revoked_at')
            ->exists()) {
            abort(403, 'workspace_access_denied');
        }

        // RLS : applique au niveau session Postgres pour tout le request
        DB::statement("SET LOCAL app.current_workspace_id = ?", [$wsId]);

        // Helper accessible partout via $request->workspace_id ou auth()->user()->current_workspace_id
        $req->merge(['workspace_id' => $wsId]);
        config(['app.current_workspace_id' => $wsId]);

        return $next($req);
    }
}
```

### RLS policies

Cf. `03_db_schema_phase1.md` § 12 (toutes les tables activées + policies workspace_isolation).

### Pourquoi double sécurité ?

- **Filtre applicatif** (where workspace_id = ?) : performance + comportement (ex: include relations)
- **RLS DB** : sécurité défense en profondeur. Si bug applicatif oublie le filtre → Postgres bloque toujours.

### Bypass RLS pour jobs système

User Postgres dédié `axion_worker` avec `BYPASSRLS`. Utilisé uniquement par :
- Migrations / refresh materialized views
- Job `app:detect-duplicate-flags` (cross-workspace si besoin futur)
- Lecture `opt_out` global (déjà non-RLS)

---

## §5 — Spatie Permission (RBAC)

### Rôles seed

```php
// database/seeders/RolesSeeder.php
public function run(): void
{
    foreach (Workspace::all() as $ws) {
        Role::firstOrCreate(['workspace_id' => $ws->id, 'slug' => 'owner', 'name' => 'Owner'])
            ->syncPermissions(Permission::pluck('id'));   // toutes les permissions

        Role::firstOrCreate(['workspace_id' => $ws->id, 'slug' => 'admin', 'name' => 'Admin'])
            ->syncPermissions(Permission::whereNotIn('slug', ['workspaces.manage'])->pluck('id'));

        Role::firstOrCreate(['workspace_id' => $ws->id, 'slug' => 'operator', 'name' => 'Operator'])
            ->syncPermissions(Permission::whereIn('slug', [
                'companies.view','companies.update','contacts.view','contacts.update',
                'scraping.run','data.export'
            ])->pluck('id'));

        Role::firstOrCreate(['workspace_id' => $ws->id, 'slug' => 'viewer', 'name' => 'Viewer'])
            ->syncPermissions(Permission::whereIn('slug', [
                'companies.view','contacts.view'
            ])->pluck('id'));
    }
}
```

### Authorization

```php
// Controller
public function destroy(Company $c, Request $r): Response
{
    Gate::authorize('companies.delete');
    $c->delete();
    AuditLog::record('company.delete', $c, [], $r->user());
    return response()->noContent();
}

// Route definition
Route::delete('/companies/{c}', [CompanyController::class, 'destroy'])
    ->middleware('can:companies.delete');
```

### Policies (Eloquent)

```php
// app/Policies/CompanyPolicy.php
class CompanyPolicy
{
    public function view(User $user, Company $c): bool
    {
        return $c->workspace_id === $user->current_workspace_id
            && $user->can('companies.view');
    }
    public function update(User $user, Company $c): bool
    {
        return $c->workspace_id === $user->current_workspace_id
            && $user->can('companies.update');
    }
    // ...
}
```

---

## §6 — Audit log hash chain

### Schéma

Cf. `03_db_schema_phase1.md` § 1 : `audit_logs` (partitionnée par mois) avec `previous_hash` + `record_hash`.

### Insertion (hash chain)

```php
class AuditLog extends Model
{
    public $timestamps = false;

    public static function record(string $action, $resource = null, array $changes = [], ?User $user = null, array $meta = []): self
    {
        return DB::transaction(function () use ($action, $resource, $changes, $user, $meta) {
            $previous = self::orderByDesc('id')->lockForUpdate()->first();
            $previousHash = $previous?->record_hash ?? 'GENESIS';

            $payload = [
                'workspace_id'  => config('app.current_workspace_id'),
                'user_id'       => $user?->id ?? auth()->id(),
                'action'        => $action,
                'resource_type' => $resource ? get_class($resource) : null,
                'resource_id'   => $resource ? (string) $resource->getKey() : null,
                'changes'       => $changes,
                'ip_address'    => request()->ip(),
                'user_agent'    => request()->userAgent(),
                'metadata'      => $meta,
                'previous_hash' => $previousHash,
                'created_at'    => now(),
            ];

            $payload['record_hash'] = hash('sha256', implode('|', [
                $previousHash,
                $payload['action'],
                $payload['resource_type'] ?? '',
                $payload['resource_id'] ?? '',
                json_encode($payload['changes']),
                $payload['user_id'] ?? '',
                $payload['created_at']->toIso8601String(),
            ]));

            return self::create($payload);
        });
    }

    public static function verifyChain(): array
    {
        $errors = [];
        $previousHash = 'GENESIS';
        self::orderBy('created_at')->orderBy('id')->chunk(1000, function ($chunk) use (&$errors, &$previousHash) {
            foreach ($chunk as $row) {
                $expected = hash('sha256', implode('|', [
                    $previousHash,
                    $row->action,
                    $row->resource_type ?? '',
                    $row->resource_id ?? '',
                    json_encode($row->changes),
                    $row->user_id ?? '',
                    $row->created_at->toIso8601String(),
                ]));
                if ($expected !== $row->record_hash) {
                    $errors[] = ['id' => $row->id, 'expected' => $expected, 'got' => $row->record_hash];
                }
                if ($row->previous_hash !== $previousHash) {
                    $errors[] = ['id' => $row->id, 'prev_mismatch' => true];
                }
                $previousHash = $row->record_hash;
            }
        });
        return $errors;
    }
}
```

### Actions auditées Phase 1 (extraits)

- `user.login.success`, `user.login.failed`, `user.locked`, `user.two_factor.enabled`
- `company.create`, `company.update`, `company.delete`, `company.merge`
- `contact.create`, `contact.update`, `contact.delete`
- `scraping.run.start`, `scraping.run.complete`
- `gdpr.request.received`, `gdpr.request.executed_erasure`
- `llm.use_case.updated`, `llm.prompt_template.version_created`
- `workspace.created`, `workspace.updated`
- `proxy.provider.enabled`, `proxy.provider.disabled`

---

## §7 — Session security

- Cookies HttpOnly, Secure, SameSite=lax
- Session timeout 12h (configurable)
- Renouvellement session sur logout/login (regenerate ID)
- Invalidation toutes sessions sur change password
- Stockage Redis (DB 3)

---

## §8 — Brute force protection

- 5 failed logins → compte locked 15 min (`users.locked_until`)
- IP-based : 50 failed logins /h → fail2ban ban 1h (Edge layer)
- Rate limit POST `/login` : 5 req/min/IP

---

## §9 — Password policy

- Minimum 12 caractères
- Au moins 1 minuscule, 1 majuscule, 1 chiffre
- Vérification leaked password via Have I Been Pwned API (`https://api.pwnedpasswords.com/range/{prefix}`)
- Hashing bcrypt rounds 12

---

## §10 — Logout

```php
public function destroy(Request $req): Response
{
    AuditLog::record('user.logout', $req->user());
    Auth::guard('web')->logout();
    $req->session()->invalidate();
    $req->session()->regenerateToken();
    return response()->noContent();
}
```

---

## §11 — Tests acceptance

```php
test('user cannot access other workspace data')
    ->actingAs(User::factory()->forWorkspace($workspaceA)->create())
    ->expect(fn() => get('/api/v1/companies/' . $companyB->id))
    ->toHaveStatus(403);

test('totp required after login')
    ->expect(fn() => postJson('/login', ['email'=>'will@', 'password'=>'pass']))
    ->toContain(['requires_two_factor' => true]);

test('audit hash chain valid after 100 inserts')
    ->expect(fn() => AuditLog::verifyChain())
    ->toBeEmpty();
```

---

## Lecture suivante

→ `16_monitoring_observabilite.md` (40+ métriques Prometheus + 10 dashboards Grafana + alertes).
