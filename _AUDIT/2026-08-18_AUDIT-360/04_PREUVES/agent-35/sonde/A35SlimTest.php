<?php

namespace A35Slim;

use App\Models\User;
use App\Models\Workspace;
use App\Rules\NotPwnedPassword;
use App\Services\Auth\HibpChecker;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AUDIT 360 — AGENT 35. Sonde COURTE : ~30 requetes, base deja migree,
 * DatabaseTransactions (pas de migrate:fresh). Hors arborescence produit.
 */
class A35SlimTest extends TestCase
{
    use DatabaseTransactions;

    private function say(string $s): void
    {
        fwrite(STDERR, $s . "\n");
        @file_put_contents('/tmp/a35/slim.txt', $s . "\n", FILE_APPEND);
    }

    private function frapper(string $m, string $uri, array $h)
    {
        $garde = $this->defaultHeaders;
        $this->defaultHeaders = [];
        $r = $m === 'GET' ? $this->get($uri, $h) : $this->post($uri, [], $h);
        $this->defaultHeaders = $garde;

        return $r;
    }

    private function reprendreSession($r): void
    {
        foreach ($r->baseResponse->headers->getCookies() as $c) {
            if ((string) $c->getValue() === '') {
                unset($this->defaultCookies[$c->getName()]);
                continue;
            }
            $this->defaultCookies[$c->getName()] = $c->getValue();
        }
        $this->app['auth']->forgetGuards();
    }

    private function faireUtilisateur(string $email, string $mdp = 'CorrectPassword12345!', ?string $totp = null): User
    {
        $ws = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'a35s-' . Str::random(8), 'name' => 'A35S']);

        return User::create([
            'id' => (string) Str::uuid(),
            'email' => $email,
            'name' => 'A35S',
            'password_hash' => password_hash($mdp, PASSWORD_BCRYPT, ['cost' => 4]),
            'current_workspace_id' => $ws->id,
            'first_login_completed_at' => now(),
            'totp_secret' => $totp,
            'totp_enabled_at' => $totp ? now() : null,
        ]);
    }

    public function test_tout(): void
    {
        @unlink('/tmp/a35/slim.txt');
        $this->say('===== SONDE COURTE AGENT 35 — main e8924b8 — ' . date('c') . ' =====');
        $this->say('APP_ENV=' . config('app.env') . ' APP_DEBUG=' . var_export(config('app.debug'), true)
            . ' session.driver=' . config('session.driver') . ' base=' . DB::connection()->getDatabaseName());

        // ---------------------------------------------------------------- [1]
        $this->say("\n----- [1] TEMOIN : la sonde envoie-t-elle vraiment ses en-tetes ? -----");
        Route::get('/a35-temoin', fn (\Illuminate\Http\Request $r) => response()->json([
            'accept' => $r->headers->get('Accept'),
            'expectsJson' => $r->expectsJson(),
        ]));
        $profils = [
            'navigateur  ' => ['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8'],
            'curl */*    ' => ['Accept' => '*/*'],
            'aucun Accept' => [],
            'client JSON ' => ['Accept' => 'application/json'],
            'SPA axios   ' => ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'],
        ];
        foreach ($profils as $nom => $h) {
            $j = $this->frapper('GET', '/a35-temoin', $h)->json();
            $this->say(sprintf('  %s  Accept recu=%-46s expectsJson=%s', $nom,
                var_export($j['accept'] ?? null, true), var_export($j['expectsJson'] ?? null, true)));
        }

        // ---------------------------------------------------------------- [2]
        $this->say("\n----- [2] A-001 : etendue reelle -----");
        $this->say("route('login') existe ? " . (Route::has('login') ? 'OUI' : 'NON'));
        foreach ([['GET', '/api/v1/auth/me'], ['GET', '/api/v1/contacts']] as [$m, $uri]) {
            $l = str_pad("$m $uri", 26);
            foreach ($profils as $h) {
                $l .= str_pad((string) $this->frapper($m, $uri, $h)->getStatusCode(), 6);
            }
            $this->say('  ' . $l . '   (ordre des colonnes = ordre du bloc [1])');
        }
        $r = $this->frapper('GET', '/api/v1/auth/me', ['Accept' => 'text/html']);
        $this->say('  exception (profil navigateur) = ' . ($r->exception ? get_class($r->exception) . ' :: ' . $r->exception->getMessage() : '(aucune)'));
        $rj = $this->frapper('GET', '/api/v1/auth/me', ['Accept' => 'application/json']);
        $this->say('  corps (profil client JSON) = ' . substr((string) $rj->getContent(), 0, 100));

        // ---------------------------------------------------------------- [3]
        $this->say("\n----- [3] Correctif : les deux moities -----");
        /** @var \Illuminate\Foundation\Exceptions\Handler $h */
        $handler = $this->app->make(ExceptionHandler::class);
        $defaut = fn ($req, $e) => $req->expectsJson();
        $corr = fn ($req, $e) => $req->is('api/*') || $req->expectsJson();
        $html = ['Accept' => 'text/html'];

        $e0 = $this->frapper('GET', '/api/v1/auth/me', $html)->getStatusCode();
        $handler->shouldRenderJsonWhen($corr);
        $e1 = $this->frapper('GET', '/api/v1/auth/me', $html)->getStatusCode();
        $handler->shouldRenderJsonWhen($defaut);
        \Illuminate\Auth\Middleware\Authenticate::redirectUsing(fn () => null);
        $e2 = $this->frapper('GET', '/api/v1/auth/me', $html)->getStatusCode();
        $handler->shouldRenderJsonWhen($corr);
        $e3 = $this->frapper('GET', '/api/v1/auth/me', $html);
        $this->say(sprintf('  (0) tel quel=%d | (1) shouldRenderJsonWhen SEUL=%d | (2) redirectGuestsTo(null) SEUL=%d | (3) LES DEUX=%d',
            $e0, $e1, $e2, $e3->getStatusCode()));
        $this->say('  corps de (3) = ' . substr((string) $e3->getContent(), 0, 100));
        \Illuminate\Auth\Middleware\Authenticate::redirectUsing(fn () => route('login'));
        $handler->shouldRenderJsonWhen($defaut);

        // ---------------------------------------------------------------- [4]
        $this->say("\n----- [4] Colonnes 2FA -----");
        $cols = array_map(fn ($c) => $c->column_name, DB::select("SELECT column_name FROM information_schema.columns WHERE table_name='users'"));
        foreach (['totp_secret', 'totp_recovery_codes', 'two_factor_secret', 'two_factor_enabled', 'two_factor_recovery_codes'] as $c) {
            $this->say(sprintf('  %-28s %s', $c, in_array($c, $cols, true) ? 'PRESENTE' : '>>> ABSENTE <<<'));
        }
        $this->say('  (l appel qui casse est rejoue en [14], en toute fin : une erreur SQL');
        $this->say('   avorte la transaction de test et rendrait aveugle tout ce qui suit.)');

        // ---------------------------------------------------------------- [5]
        $this->say("\n----- [5] Session, deconnexion, jeton d API -----");
        $this->say('  session.lifetime=' . config('session.lifetime') . ' min | expire_on_close=' . var_export(config('session.expire_on_close'), true)
            . ' | encrypt=' . var_export(config('session.encrypt'), true) . ' | sanctum.expiration=' . var_export(config('sanctum.expiration'), true));
        $u = $this->faireUtilisateur('slim@ex.test');
        $login = $this->withServerVariables(['REMOTE_ADDR' => '10.20.0.1'])
            ->post('/api/v1/auth/login', ['email' => $u->email, 'password' => 'CorrectPassword12345!'],
                ['Origin' => 'https://localhost', 'Accept' => 'application/json']);
        $this->say('  login => ' . $login->getStatusCode() . ' requires_2fa=' . var_export($login->json('requires_2fa'), true));
        foreach ($login->baseResponse->headers->getCookies() as $c) {
            $this->say(sprintf('    cookie %-22s httpOnly=%s secure=%s sameSite=%s expire=%s',
                $c->getName(), var_export($c->isHttpOnly(), true), var_export($c->isSecure(), true),
                (string) $c->getSameSite(), $c->getExpiresTime() ? date('c', $c->getExpiresTime()) : 'session'));
        }
        $this->reprendreSession($login);
        $this->say('  /auth/me avec le cookie rejoue (gardes videes) => ' . $this->get('/api/v1/auth/me', ['Accept' => 'application/json'])->getStatusCode());
        $lo = $this->post('/api/v1/auth/logout', [], ['Accept' => 'application/json']);
        $this->say('  logout => ' . $lo->getStatusCode() . ' (corps de ' . strlen((string) $lo->getContent()) . ' octets)');
        $this->reprendreSession($lo);
        $this->say('  /auth/me apres logout => ' . $this->get('/api/v1/auth/me', ['Accept' => 'application/json'])->getStatusCode());

        $this->defaultCookies = [];
        $this->app['auth']->forgetGuards();
        $tok = $u->fresh()->createToken('slim');
        $this->say('  jeton cree, expires_at = ' . var_export($tok->accessToken->expires_at, true));
        $b = ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $tok->plainTextToken];
        $this->say('  /auth/me avec jeton VALIDE  => ' . $this->get('/api/v1/auth/me', $b)->getStatusCode());
        $tok->accessToken->delete();
        $this->app['auth']->forgetGuards();
        $this->say('  /auth/me avec jeton REVOQUE => ' . $this->get('/api/v1/auth/me', $b)->getStatusCode());

        // ---------------------------------------------------------------- [6]
        $this->say("\n----- [6] Contournement de la 2FA -----");
        $g = new \PragmaRX\Google2FA\Google2FA();
        $u2 = $this->faireUtilisateur('slimbypass@ex.test', 'CorrectPassword12345!', $g->generateSecretKey());
        $this->defaultCookies = [];
        $this->app['auth']->forgetGuards();
        $l2 = $this->withServerVariables(['REMOTE_ADDR' => '10.20.0.2'])
            ->post('/api/v1/auth/login', ['email' => $u2->email, 'password' => 'CorrectPassword12345!'],
                ['Origin' => 'https://localhost', 'Accept' => 'application/json']);
        $this->say('  login => ' . $l2->getStatusCode() . ' requires_2fa=' . var_export($l2->json('requires_2fa'), true));
        $this->reprendreSession($l2);
        foreach (['/api/v1/auth/me', '/api/v1/contacts', '/api/v1/audit-logs'] as $uri) {
            $this->say(sprintf('  SANS /2fa/verify : GET %-24s => %d', $uri, $this->get($uri, ['Accept' => 'application/json'])->getStatusCode()));
        }
        $this->say('  fenetre TOTP (verifyKey window=1, horloge serveur decalee) :');
        $code = $g->getCurrentOtp($u2->totp_secret);
        $base = $g->getTimestamp();
        $acc = [];
        foreach ([-3, -2, -1, 0, 1, 2, 3] as $p) {
            $acc[] = sprintf('%+d pas=%s', $p, $g->verifyKey($u2->totp_secret, $code, 1, $base + $p) ? 'OUI' : 'non');
        }
        $this->say('    ' . implode('  ', $acc));

        // ---------------------------------------------------------------- [7]
        $this->say("\n----- [7] Lien magique -----");
        $this->defaultCookies = [];
        $this->app['auth']->forgetGuards();
        $u3 = $this->faireUtilisateur('slimmagic@ex.test');
        $t = Str::random(64);
        DB::table('magic_links')->insert(['id' => (string) Str::uuid(), 'user_id' => $u3->id, 'email' => $u3->email,
            'token_hash' => hash('sha256', $t), 'expires_at' => now()->addMinutes(15), 'created_at' => now()]);
        $this->say('  1er usage  => ' . $this->post('/api/v1/auth/magic-link/verify', ['token' => $t], ['Origin' => 'https://localhost', 'Accept' => 'application/json'])->getStatusCode());
        $this->say('  2e usage (REJEU) => ' . $this->post('/api/v1/auth/magic-link/verify', ['token' => $t], ['Origin' => 'https://localhost', 'Accept' => 'application/json'])->getStatusCode());
        $tx = Str::random(64);
        DB::table('magic_links')->insert(['id' => (string) Str::uuid(), 'user_id' => $u3->id, 'email' => $u3->email,
            'token_hash' => hash('sha256', $tx), 'expires_at' => now()->subMinute(), 'created_at' => now()->subMinutes(20)]);
        $this->say('  jeton EXPIRE => ' . $this->post('/api/v1/auth/magic-link/verify', ['token' => $tx], ['Origin' => 'https://localhost', 'Accept' => 'application/json'])->getStatusCode());
        app(\App\Services\Auth\MagicLinkService::class)->issue('slim-inconnu@ex.test', '10.0.0.1');
        $this->say('  lignes magic_links pour un e-mail INCONNU = ' . DB::table('magic_links')->where('email', 'slim-inconnu@ex.test')->count()
            . ' (user_id = ' . var_export(DB::table('magic_links')->where('email', 'slim-inconnu@ex.test')->value('user_id'), true) . ')');
        $to = Str::random(64);
        DB::table('magic_links')->insert(['id' => (string) Str::uuid(), 'user_id' => null, 'email' => 'slim-futur@ex.test',
            'token_hash' => hash('sha256', $to), 'expires_at' => now()->addMinutes(15), 'created_at' => now()]);
        $this->faireUtilisateur('slim-futur@ex.test');
        $this->defaultCookies = [];
        $this->app['auth']->forgetGuards();
        $this->say('  jeton emis AVANT la creation du compte, compte cree ensuite => '
            . $this->post('/api/v1/auth/magic-link/verify', ['token' => $to], ['Origin' => 'https://localhost', 'Accept' => 'application/json'])->getStatusCode());

        // ---------------------------------------------------------------- [8]
        $this->say("\n----- [8] Reinitialisation du mot de passe -----");
        $this->defaultCookies = [];
        $this->app['auth']->forgetGuards();
        $u4 = $this->faireUtilisateur('slimreset@ex.test');
        $en = ['Origin' => 'https://localhost', 'Accept' => 'application/json'];
        $t1 = Str::random(64);
        DB::table('password_reset_tokens')->updateOrInsert(['email' => $u4->email], ['token' => hash('sha256', $t1), 'created_at' => now()]);
        $c1 = ['email' => $u4->email, 'token' => $t1, 'password' => 'NouveauMotDePasse123!', 'password_confirmation' => 'NouveauMotDePasse123!'];
        $this->say('  1er reset => ' . $this->post('/api/v1/auth/password/reset', $c1, $en)->getStatusCode());
        $this->say('  2e reset, MEME jeton => ' . $this->post('/api/v1/auth/password/reset', $c1, $en)->getStatusCode() . '  (401 attendu)');
        $t2 = Str::random(64);
        DB::table('password_reset_tokens')->updateOrInsert(['email' => $u4->email], ['token' => hash('sha256', $t2), 'created_at' => now()->subDays(30)]);
        $c2 = ['email' => $u4->email, 'token' => $t2, 'password' => 'TrenteJoursApres12!', 'password_confirmation' => 'TrenteJoursApres12!'];
        $this->say('  reset avec un jeton de 30 JOURS => ' . $this->post('/api/v1/auth/password/reset', $c2, $en)->getStatusCode() . '  (401 expired_token attendu)');
        $u4 = $u4->fresh();
        $tk = $u4->createToken('avant-reset')->plainTextToken;
        $t3 = Str::random(64);
        DB::table('password_reset_tokens')->updateOrInsert(['email' => $u4->email], ['token' => hash('sha256', $t3), 'created_at' => now()]);
        $this->post('/api/v1/auth/password/reset', ['email' => $u4->email, 'token' => $t3, 'password' => 'ApresRotation12345!', 'password_confirmation' => 'ApresRotation12345!'], $en);
        $this->app['auth']->forgetGuards();
        $this->say('  jetons d API restants apres reset = ' . DB::table('personal_access_tokens')->where('tokenable_id', $u4->id)->count()
            . ' ; /auth/me avec le jeton d AVANT => '
            . $this->get('/api/v1/auth/me', ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $tk])->getStatusCode()
            . '  (200 = non revoque)');

        // ---------------------------------------------------------------- [9]
        $this->say("\n----- [9] HibpChecker / NotPwnedPassword -----");
        $coupe = new HibpChecker(new Client(['handler' => HandlerStack::create(new MockHandler([
            new ConnectException('cURL error 6: Could not resolve host', new PsrRequest('GET', HibpChecker::API_BASE_URL)),
        ]))]));
        Cache::flush();
        $this->say('  RESEAU COUPE : getBreachCount("password") = ' . $coupe->getBreachCount('password'));
        $this->app->instance(HibpChecker::class, new HibpChecker(new Client(['handler' => HandlerStack::create(new MockHandler([
            new ConnectException('cURL error 6', new PsrRequest('GET', HibpChecker::API_BASE_URL)),
        ]))])));
        Cache::flush();
        $v = Validator::make(['password' => 'password'], ['password' => [new NotPwnedPassword()]]);
        $this->say('  RESEAU COUPE : NotPwnedPassword refuse "password" ? ' . var_export($v->fails(), true)
            . ($v->fails() ? '  (refuse)' : '  >>> ACCEPTE : fail-open <<<'));
        $sha = strtoupper(sha1('password'));
        $ok = new HibpChecker(new Client(['handler' => HandlerStack::create(new MockHandler([
            new PsrResponse(200, [], substr($sha, 5) . ":9999999\r\nAAAA:0\r\n"),
        ]))]));
        Cache::flush();
        $this->say('  TEMOIN NEGATIF (reseau OK) : getBreachCount("password") = ' . $ok->getBreachCount('password'));
        $this->app->instance(HibpChecker::class, $ok);
        Cache::flush();
        $v2 = Validator::make(['password' => 'password'], ['password' => [new NotPwnedPassword()]]);
        $this->say('  TEMOIN NEGATIF (reseau OK) : NotPwnedPassword refuse "password" ? ' . var_export($v2->fails(), true));

        // ---------------------------------------------------------------- [10]
        $this->say("\n----- [10] EnforceFirstLoginSetup -----");
        $this->app->forgetInstance(HibpChecker::class);
        $this->defaultCookies = [];
        $this->app['auth']->forgetGuards();
        $ws = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'a35fl-' . Str::random(8), 'name' => 'FL']);
        $uf = User::create(['id' => (string) Str::uuid(), 'email' => 'slimfl@ex.test', 'name' => 'FL',
            'password_hash' => password_hash('CorrectPassword12345!', PASSWORD_BCRYPT, ['cost' => 4]),
            'current_workspace_id' => $ws->id, 'first_login_completed_at' => null]);
        $lf = $this->withServerVariables(['REMOTE_ADDR' => '10.20.0.3'])
            ->post('/api/v1/auth/login', ['email' => $uf->email, 'password' => 'CorrectPassword12345!'], $en);
        $this->say('  login (first_login_completed_at = null) => ' . $lf->getStatusCode());
        $this->reprendreSession($lf);
        foreach ([['GET', '/api/v1/auth/me'], ['GET', '/api/v1/contacts'], ['GET', '/api/v1/contacts/'], ['GET', '/api/v1/exports/contacts.csv'], ['GET', '/broadcasting/auth']] as [$m, $uri]) {
            $rr = $m === 'GET' ? $this->get($uri, ['Accept' => 'application/json']) : $this->post($uri, [], ['Accept' => 'application/json']);
            $this->say(sprintf('  %-5s %-24s => %d  %s', $m, $uri, $rr->getStatusCode(), substr(str_replace("\n", ' ', (string) $rr->getContent()), 0, 60)));
        }

        // ---------------------------------------------------------------- [11]
        $this->say("\n----- [11] Verrouillage : 20 tentatives -----");
        $this->defaultCookies = [];
        $this->app['auth']->forgetGuards();
        $ua = $this->faireUtilisateur('slimlock@ex.test');
        $s = [];
        for ($i = 1; $i <= 20; $i++) {
            $s[] = $this->withServerVariables(['REMOTE_ADDR' => '10.30.0.1'])
                ->post('/api/v1/auth/login', ['email' => $ua->email, 'password' => "MauvaisMotDePasse{$i}!"], $en)->getStatusCode();
        }
        $this->say('  (a) 20 tentatives, MEME IP : ' . implode(' ', $s));
        $f = $ua->fresh();
        $this->say('      failed_login_count=' . $f->failed_login_count . ' locked_until=' . var_export((string) $f->locked_until, true));
        $ub = $this->faireUtilisateur('slimlock2@ex.test');
        $s2 = [];
        for ($i = 1; $i <= 20; $i++) {
            $s2[] = $this->withServerVariables(['REMOTE_ADDR' => '10.31.0.' . $i])
                ->post('/api/v1/auth/login', ['email' => $ub->email, 'password' => "MauvaisMotDePasse{$i}!"], $en)->getStatusCode();
        }
        $this->say('  (b) 20 tentatives, 20 IP DIFFERENTES : ' . implode(' ', $s2));
        $f2 = $ub->fresh();
        $this->say('      failed_login_count=' . $f2->failed_login_count . ' locked_until=' . var_export((string) $f2->locked_until, true));
        $bon = $this->withServerVariables(['REMOTE_ADDR' => '10.32.0.1'])
            ->post('/api/v1/auth/login', ['email' => $ub->email, 'password' => 'CorrectPassword12345!'], $en);
        $this->say('  (c) BON mot de passe sur compte verrouille => ' . $bon->getStatusCode() . ' ' . substr((string) $bon->getContent(), 0, 120));
        $av = $ub->fresh()->failed_login_count;
        $this->withServerVariables(['REMOTE_ADDR' => '10.33.0.1'])
            ->post('/api/v1/auth/login', ['email' => $ub->email, 'password' => 'EncoreUnAutre1234!'], $en);
        $this->say('  (d) compteur avant=' . $av . ' apres une tentative de plus=' . $ub->fresh()->failed_login_count
            . '  -> le hachage est encore verifie sur un compte verrouille');

        // ---------------------------------------------------------------- [12]
        $this->say("\n----- [12] Enumeration : corps ET temps (bcrypt cout 12) -----");
        $ue = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'a35e-' . Str::random(8), 'name' => 'E']);
        User::create(['id' => (string) Str::uuid(), 'email' => 'slim-existe@ex.test', 'name' => 'E',
            'password_hash' => password_hash('CorrectPassword12345!', PASSWORD_BCRYPT, ['cost' => 12]),
            'current_workspace_id' => $ue->id, 'first_login_completed_at' => now()]);
        $mesure = function (string $uri, array $corps, int $n = 5) use ($en) {
            $t = [];
            $d = null;
            for ($i = 0; $i < $n; $i++) {
                $a = microtime(true);
                $d = $this->withServerVariables(['REMOTE_ADDR' => '10.4' . random_int(0, 9) . '.' . random_int(0, 255) . '.' . random_int(1, 254)])
                    ->post($uri, $corps, $en);
                $t[] = (microtime(true) - $a) * 1000;
            }
            sort($t);
            return [$t[intdiv(count($t), 2)], $t[0], end($t), $d->getStatusCode(), substr((string) $d->getContent(), 0, 130)];
        };
        foreach ([
            '/api/v1/auth/login' => [['email' => 'slim-existe@ex.test', 'password' => 'MauvaisMotDePasse1!'], ['email' => 'slim-inconnu-jamais@ex.test', 'password' => 'MauvaisMotDePasse1!']],
            '/api/v1/auth/magic-link' => [['email' => 'slim-existe@ex.test'], ['email' => 'slim-inconnu-jamais@ex.test']],
            '/api/v1/auth/password/forgot' => [['email' => 'slim-existe@ex.test'], ['email' => 'slim-inconnu-jamais@ex.test']],
        ] as $uri => $paire) {
            $this->say("  -- $uri --");
            foreach (['COMPTE EXISTANT' => $paire[0], 'COMPTE INCONNU ' => $paire[1]] as $et => $corps) {
                [$med, $min, $max, $st, $body] = $mesure($uri, $corps);
                $this->say(sprintf('     %s statut=%d  mediane=%.1f ms (min %.1f / max %.1f)', $et, $st, $med, $min, $max));
                $this->say('        corps = ' . $body);
            }
        }

        // ---------------------------------------------------------------- [13]
        $this->say("\n----- [13] Mot de passe court : connexion impossible -----");
        $uc = $this->faireUtilisateur('slimcourt@ex.test', 'court');
        $rc = $this->withServerVariables(['REMOTE_ADDR' => '10.50.0.1'])
            ->post('/api/v1/auth/login', ['email' => $uc->email, 'password' => 'court'], $en);
        $this->say('  bon mot de passe de 5 caracteres => ' . $rc->getStatusCode() . ' ' . substr((string) $rc->getContent(), 0, 150));

        // -------------------------------------------------------------- [14]
        // EN DERNIER, ET POUR CAUSE : chacun de ces trois gestes leve une erreur
        // SQL « undefined column », qui AVORTE la transaction PostgreSQL du test.
        // Tout ce qui suivrait serait mesure dans une transaction morte.
        $this->say("\n----- [14] La panne des colonnes 2FA, rejouee (en dernier : elle avorte la transaction) -----");
        $this->defaultCookies = [];
        $this->app['auth']->forgetGuards();
        $u9 = $this->faireUtilisateur('slim2fa@ex.test');
        $l9 = $this->withServerVariables(['REMOTE_ADDR' => '10.60.0.1'])
            ->post('/api/v1/auth/login', ['email' => $u9->email, 'password' => 'CorrectPassword12345!'], $en);
        $this->say('  login => ' . $l9->getStatusCode());
        $this->reprendreSession($l9);
        $r9 = $this->post('/api/v1/auth/2fa/setup', [], ['Accept' => 'application/json']);
        $this->say('  POST /api/v1/auth/2fa/setup (authentifie) => ' . $r9->getStatusCode()
            . ' exception=' . ($r9->exception ? get_class($r9->exception) : '(aucune)'));
        if ($r9->exception) {
            $this->say('    ' . substr(str_replace("\n", ' ', $r9->exception->getMessage()), 0, 200));
        }

        $this->say("\n===== FIN DE LA SONDE COURTE =====");
        $this->assertTrue(true);
    }
}
