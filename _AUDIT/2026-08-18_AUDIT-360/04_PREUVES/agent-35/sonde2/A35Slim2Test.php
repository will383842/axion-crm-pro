<?php

namespace A35Slim2;

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
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler as VraiHandler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * AUDIT 360 — AGENT 35. Sonde COURTE n°2 : ce que la n°1 n'a pas pu rendre.
 * Les blocs [1] et [2] (temoin d'en-tetes + matrice A-001) sont DEJA mesures,
 * cf. 04_PREUVES/agent-35/sonde-courte-1.txt — on ne les rejoue pas.
 */
class A35Slim2Test extends TestCase
{
    use DatabaseTransactions;

    private array $en = ['Origin' => 'https://localhost', 'Accept' => 'application/json'];

    private function say(string $s): void
    {
        @file_put_contents('/tmp/a35/slim2.txt', $s . "\n", FILE_APPEND);
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

    private function oublier(): void
    {
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->app['auth']->forgetGuards();
    }

    private function faireUtilisateur(string $email, string $mdp = 'CorrectPassword12345!', ?string $totp = null, bool $premierLoginFait = true): User
    {
        $ws = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 's2-' . Str::random(8), 'name' => 'S2']);

        return User::create([
            'id' => (string) Str::uuid(),
            'email' => $email,
            'name' => 'S2',
            'password_hash' => password_hash($mdp, PASSWORD_BCRYPT, ['cost' => 4]),
            'current_workspace_id' => $ws->id,
            'first_login_completed_at' => $premierLoginFait ? now() : null,
            'totp_secret' => $totp,
            'totp_enabled_at' => $totp ? now() : null,
        ]);
    }

    public function test_tout(): void
    {
        @unlink('/tmp/a35/slim2.txt');
        $this->say('===== SONDE COURTE n2 — AGENT 35 — main e8924b8 — ' . date('c') . ' =====');
        $this->say('APP_ENV=' . config('app.env') . ' | session.driver=' . config('session.driver')
            . ' | log=' . config('logging.default') . ' | base=' . DB::connection()->getDatabaseName());
        $this->say('gestionnaire d exceptions par defaut du banc = ' . get_class($this->app->make(ExceptionHandler::class)));

        // ================================================================ [3]
        $this->say("\n----- [3] Correctif A-001 : les DEUX moities sont necessaires -----");
        $html = ['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8'];
        $json = ['Accept' => 'application/json'];

        // Le banc de test remplace le gestionnaire par celui de Collision, qui n'a
        // pas shouldRenderJsonWhen(). On repose donc le VRAI gestionnaire de Laravel
        // — celui que `bootstrap/app.php` configure en production — a chaque etat.
        $poser = function (bool $rendreJsonSurApi, bool $pasDeRedirection) {
            $h = new VraiHandler($this->app);
            if ($rendreJsonSurApi) {
                $h->shouldRenderJsonWhen(fn ($r, $e) => $r->is('api/*') || $r->expectsJson());
            }
            $this->app->instance(ExceptionHandler::class, $h);
            Authenticate::redirectUsing($pasDeRedirection ? fn () => null : fn () => route('login'));
        };
        $etat = function (string $titre) use ($html, $json) {
            $a = $this->get('/api/v1/auth/me', $html);
            $b = $this->get('/api/v1/auth/me', $json);
            $this->say(sprintf('  %-42s navigateur=%d  json=%d   corps navigateur = %s',
                $titre, $a->getStatusCode(), $b->getStatusCode(),
                substr(str_replace("\n", ' ', (string) $a->getContent()), 0, 60)));

            return [$a->getStatusCode(), $b->getStatusCode()];
        };

        $poser(false, false);
        [$e0] = $etat('(0) tel quel, comme sur main e8924b8');
        $poser(true, false);
        [$e1] = $etat('(1) shouldRenderJsonWhen SEUL');
        $poser(false, true);
        [$e2] = $etat('(2) redirectGuestsTo(null) SEUL');
        $poser(true, true);
        [$e3] = $etat('(3) LES DEUX — le correctif propose');
        $this->say(sprintf('  RESUME (profil navigateur) : (0)=%d  (1)=%d  (2)=%d  (3)=%d', $e0, $e1, $e2, $e3));
        $this->say('  -> (3) doit valoir 401 ; toute autre combinaison laisse le 500 en place.');

        // remise en etat du banc
        Authenticate::redirectUsing(fn () => route('login'));

        // ================================================================ [9]
        $this->say("\n----- [9] HibpChecker : fail-open -----");
        $coupe = new HibpChecker(new Client(['handler' => HandlerStack::create(new MockHandler([
            new ConnectException('cURL error 6: Could not resolve host: api.pwnedpasswords.com', new PsrRequest('GET', HibpChecker::API_BASE_URL)),
        ]))]));
        Cache::flush();
        $this->say('  RESEAU COUPE  : getBreachCount("password") = ' . $coupe->getBreachCount('password'));
        $this->app->instance(HibpChecker::class, new HibpChecker(new Client(['handler' => HandlerStack::create(new MockHandler([
            new ConnectException('cURL error 6', new PsrRequest('GET', HibpChecker::API_BASE_URL)),
        ]))])));
        Cache::flush();
        $v = Validator::make(['password' => 'password'], ['password' => [new NotPwnedPassword()]]);
        $this->say('  RESEAU COUPE  : NotPwnedPassword refuse "password" ? ' . var_export($v->fails(), true)
            . ($v->fails() ? '' : '   >>> ACCEPTE : FAIL-OPEN <<<'));
        $sha = strtoupper(sha1('password'));
        $ok = new HibpChecker(new Client(['handler' => HandlerStack::create(new MockHandler([
            new PsrResponse(200, [], substr($sha, 5) . ":9999999\r\nAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA:0\r\n"),
        ]))]));
        Cache::flush();
        $this->say('  TEMOIN (reseau OK) : getBreachCount("password") = ' . $ok->getBreachCount('password'));
        $this->app->instance(HibpChecker::class, $ok);
        Cache::flush();
        $v2 = Validator::make(['password' => 'password'], ['password' => [new NotPwnedPassword()]]);
        $this->say('  TEMOIN (reseau OK) : NotPwnedPassword refuse "password" ? ' . var_export($v2->fails(), true)
            . '  -> la regle SAIT refuser ; elle ne le fait pas quand le reseau tombe.');
        $this->app->forgetInstance(HibpChecker::class);

        // ================================================================ [8]
        $this->say("\n----- [8] Reinitialisation du mot de passe -----");
        $u4 = $this->faireUtilisateur('s2reset@ex.test');
        $t1 = Str::random(64);
        DB::table('password_reset_tokens')->updateOrInsert(['email' => $u4->email], ['token' => hash('sha256', $t1), 'created_at' => now()]);
        $c1 = ['email' => $u4->email, 'token' => $t1, 'password' => 'NouveauMotDePasse123!', 'password_confirmation' => 'NouveauMotDePasse123!'];
        $r1 = $this->post('/api/v1/auth/password/reset', $c1, $this->en);
        $this->say('  1er reset (jeton frais)      => ' . $r1->getStatusCode() . ' ' . substr((string) $r1->getContent(), 0, 60));
        $r2 = $this->post('/api/v1/auth/password/reset', $c1, $this->en);
        $this->say('  2e reset, MEME jeton         => ' . $r2->getStatusCode() . ' ' . substr((string) $r2->getContent(), 0, 60) . '   (401 attendu : usage unique)');
        $t2 = Str::random(64);
        DB::table('password_reset_tokens')->updateOrInsert(['email' => $u4->email], ['token' => hash('sha256', $t2), 'created_at' => now()->subDays(30)]);
        $r3 = $this->post('/api/v1/auth/password/reset', ['email' => $u4->email, 'token' => $t2, 'password' => 'TrenteJoursApres12!', 'password_confirmation' => 'TrenteJoursApres12!'], $this->en);
        $this->say('  reset avec un jeton de 30 JOURS => ' . $r3->getStatusCode() . ' ' . substr((string) $r3->getContent(), 0, 60) . '   (401 expired_token attendu)');
        $u4 = $u4->fresh();
        $tk = $u4->createToken('avant-reset')->plainTextToken;
        $t3 = Str::random(64);
        DB::table('password_reset_tokens')->updateOrInsert(['email' => $u4->email], ['token' => hash('sha256', $t3), 'created_at' => now()]);
        $this->post('/api/v1/auth/password/reset', ['email' => $u4->email, 'token' => $t3, 'password' => 'ApresRotation12345!', 'password_confirmation' => 'ApresRotation12345!'], $this->en);
        $this->app['auth']->forgetGuards();
        $rr = $this->get('/api/v1/auth/me', ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $tk]);
        $this->say('  jetons d API restants apres reset = ' . DB::table('personal_access_tokens')->where('tokenable_id', $u4->id)->count()
            . ' ; /auth/me avec le jeton d AVANT => ' . $rr->getStatusCode() . '   (200 = NON revoque)');

        // ================================================================ [7]
        $this->say("\n----- [7] Lien magique -----");
        $this->oublier();
        $u3 = $this->faireUtilisateur('s2magic@ex.test');
        $t = Str::random(64);
        DB::table('magic_links')->insert(['id' => (string) Str::uuid(), 'user_id' => $u3->id, 'email' => $u3->email,
            'token_hash' => hash('sha256', $t), 'expires_at' => now()->addMinutes(15), 'created_at' => now()]);
        $this->say('  1er usage        => ' . $this->post('/api/v1/auth/magic-link/verify', ['token' => $t], $this->en)->getStatusCode());
        $this->oublier();
        $this->say('  2e usage (REJEU) => ' . $this->post('/api/v1/auth/magic-link/verify', ['token' => $t], $this->en)->getStatusCode() . '   (401 attendu)');
        $tx = Str::random(64);
        DB::table('magic_links')->insert(['id' => (string) Str::uuid(), 'user_id' => $u3->id, 'email' => $u3->email,
            'token_hash' => hash('sha256', $tx), 'expires_at' => now()->subMinute(), 'created_at' => now()->subMinutes(20)]);
        $this->say('  jeton EXPIRE     => ' . $this->post('/api/v1/auth/magic-link/verify', ['token' => $tx], $this->en)->getStatusCode() . '   (401 attendu)');
        app(\App\Services\Auth\MagicLinkService::class)->issue('s2-inconnu@ex.test', '10.0.0.1');
        $this->say('  lignes magic_links pour un e-mail INCONNU = ' . DB::table('magic_links')->where('email', 's2-inconnu@ex.test')->count()
            . ' (user_id = ' . var_export(DB::table('magic_links')->where('email', 's2-inconnu@ex.test')->value('user_id'), true) . ')');
        $to = Str::random(64);
        DB::table('magic_links')->insert(['id' => (string) Str::uuid(), 'user_id' => null, 'email' => 's2-futur@ex.test',
            'token_hash' => hash('sha256', $to), 'expires_at' => now()->addMinutes(15), 'created_at' => now()]);
        $this->faireUtilisateur('s2-futur@ex.test');
        $this->oublier();
        $rf = $this->post('/api/v1/auth/magic-link/verify', ['token' => $to], $this->en);
        $this->say('  jeton emis AVANT que le compte existe, compte cree ensuite => ' . $rf->getStatusCode()
            . '   (200 = la session s ouvre sur un compte absent a l emission)');

        // ================================================================ [6]
        $this->say("\n----- [6] Contournement de la 2FA -----");
        $this->oublier();
        $g = new \PragmaRX\Google2FA\Google2FA();
        $u2 = $this->faireUtilisateur('s2bypass@ex.test', 'CorrectPassword12345!', $g->generateSecretKey());
        $l2 = $this->withServerVariables(['REMOTE_ADDR' => '10.70.0.2'])
            ->post('/api/v1/auth/login', ['email' => $u2->email, 'password' => 'CorrectPassword12345!'], $this->en);
        $this->say('  login => ' . $l2->getStatusCode() . ' requires_2fa=' . var_export($l2->json('requires_2fa'), true));
        $this->reprendreSession($l2);
        foreach (['/api/v1/auth/me', '/api/v1/contacts', '/api/v1/audit-logs'] as $uri) {
            $this->say(sprintf('  SANS avoir appele /2fa/verify : GET %-22s => %d', $uri,
                $this->get($uri, ['Accept' => 'application/json'])->getStatusCode()));
        }
        $code = $g->getCurrentOtp($u2->totp_secret);
        $base = $g->getTimestamp();
        $acc = [];
        foreach ([-3, -2, -1, 0, 1, 2, 3] as $p) {
            $acc[] = sprintf('%+d(%+ds)=%s', $p, $p * 30, $g->verifyKey($u2->totp_secret, $code, 1, $base + $p) ? 'OUI' : 'non');
        }
        $this->say('  fenetre TOTP (window=1) : ' . implode('  ', $acc));

        // ================================================================ [10]
        $this->say("\n----- [10] EnforceFirstLoginSetup -----");
        $this->oublier();
        $uf = $this->faireUtilisateur('s2fl@ex.test', 'CorrectPassword12345!', null, false);
        $lf = $this->withServerVariables(['REMOTE_ADDR' => '10.70.0.3'])
            ->post('/api/v1/auth/login', ['email' => $uf->email, 'password' => 'CorrectPassword12345!'], $this->en);
        $this->say('  login (first_login_completed_at = null) => ' . $lf->getStatusCode());
        $this->reprendreSession($lf);
        foreach ([['GET', '/api/v1/auth/me'], ['GET', '/api/v1/contacts'], ['GET', '/api/v1/contacts/'], ['GET', '/api/v1/dashboard/stats'], ['GET', '/broadcasting/auth']] as [$m, $uri]) {
            $rr = $m === 'GET' ? $this->get($uri, ['Accept' => 'application/json']) : $this->post($uri, [], ['Accept' => 'application/json']);
            $this->say(sprintf('  %-5s %-26s => %-3d %s', $m, $uri, $rr->getStatusCode(),
                substr(str_replace("\n", ' ', (string) $rr->getContent()), 0, 70)));
        }

        // ================================================================ [13]
        $this->say("\n----- [13] Mot de passe court : connexion impossible -----");
        $this->oublier();
        $uc = $this->faireUtilisateur('s2court@ex.test', 'court');
        $rc = $this->withServerVariables(['REMOTE_ADDR' => '10.70.0.4'])
            ->post('/api/v1/auth/login', ['email' => $uc->email, 'password' => 'court'], $this->en);
        $this->say('  BON mot de passe de 5 caracteres => ' . $rc->getStatusCode() . ' ' . substr((string) $rc->getContent(), 0, 150));

        // ================================================================ [5]
        $this->say("\n----- [5] Session, deconnexion, revocation d un jeton d API -----");
        $this->oublier();
        $u = $this->faireUtilisateur('s2sess@ex.test');
        $login = $this->withServerVariables(['REMOTE_ADDR' => '10.70.0.5'])
            ->post('/api/v1/auth/login', ['email' => $u->email, 'password' => 'CorrectPassword12345!'], $this->en);
        $this->say('  login => ' . $login->getStatusCode());
        foreach ($login->baseResponse->headers->getCookies() as $c) {
            $this->say(sprintf('    cookie %-20s httpOnly=%s secure=%s sameSite=%s expire=%s',
                $c->getName(), var_export($c->isHttpOnly(), true), var_export($c->isSecure(), true),
                (string) $c->getSameSite(), $c->getExpiresTime() ? date('c', $c->getExpiresTime()) : 'session'));
        }
        $this->reprendreSession($login);
        $this->say('  /auth/me, cookie rejoue, gardes videes => ' . $this->get('/api/v1/auth/me', ['Accept' => 'application/json'])->getStatusCode());
        $lo = $this->post('/api/v1/auth/logout', [], ['Accept' => 'application/json']);
        $this->say('  logout => ' . $lo->getStatusCode() . ' (corps de ' . strlen((string) $lo->getContent()) . ' octets)');
        $this->reprendreSession($lo);
        $this->say('  /auth/me apres logout => ' . $this->get('/api/v1/auth/me', ['Accept' => 'application/json'])->getStatusCode());
        $this->oublier();
        $tok = $u->fresh()->createToken('s2');
        $this->say('  jeton cree : expires_at = ' . var_export($tok->accessToken->expires_at, true) . ' (sanctum.expiration = ' . var_export(config('sanctum.expiration'), true) . ')');
        $bear = ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $tok->plainTextToken];
        $this->say('  /auth/me, jeton VALIDE  => ' . $this->get('/api/v1/auth/me', $bear)->getStatusCode());
        $tok->accessToken->delete();
        $this->app['auth']->forgetGuards();
        $this->say('  /auth/me, jeton REVOQUE => ' . $this->get('/api/v1/auth/me', $bear)->getStatusCode() . '   (401 attendu)');

        // ================================================================ [14]
        // EN DERNIER : chacun de ces gestes avorte la transaction PostgreSQL.
        $this->say("\n----- [14] La panne des colonnes 2FA, rejouee par la route (avorte la transaction) -----");
        $this->oublier();
        $u9 = $this->faireUtilisateur('s22fa@ex.test');
        $l9 = $this->withServerVariables(['REMOTE_ADDR' => '10.70.0.9'])
            ->post('/api/v1/auth/login', ['email' => $u9->email, 'password' => 'CorrectPassword12345!'], $this->en);
        $this->say('  login => ' . $l9->getStatusCode());
        $this->reprendreSession($l9);
        $r9 = $this->post('/api/v1/auth/2fa/setup', [], ['Accept' => 'application/json']);
        $this->say('  POST /api/v1/auth/2fa/setup (authentifie) => ' . $r9->getStatusCode()
            . ' | exception = ' . ($r9->exception ? get_class($r9->exception) : '(aucune)'));
        if ($r9->exception) {
            $this->say('    ' . substr(str_replace("\n", ' ', $r9->exception->getMessage()), 0, 190));
        }

        $this->say("\n===== FIN DE LA SONDE COURTE n2 =====");
        $this->assertTrue(true);
    }
}
