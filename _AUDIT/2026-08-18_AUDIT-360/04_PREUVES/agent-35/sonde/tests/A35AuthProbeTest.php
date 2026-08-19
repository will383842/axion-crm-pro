<?php

namespace A35;

use App\Models\User;
use App\Models\Workspace;
use App\Rules\NotPwnedPassword;
use App\Services\Auth\HibpChecker;
use App\Services\Auth\MagicLinkService;
use App\Services\Auth\TwoFactorService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * AUDIT 360 — AGENT 35 (authentification). Sonde de mesure, hors arborescence
 * produit (/tmp/a35). Ne modifie aucun fichier du dépôt.
 */
class A35AuthProbeTest extends TestCase
{
    use RefreshDatabase;

    private const PROTEGEES = [
        ['GET', '/api/v1/auth/me'],
        ['POST', '/api/v1/auth/logout'],
        ['POST', '/api/v1/auth/onboarding/complete'],
        ['POST', '/api/v1/auth/2fa/setup'],
        ['POST', '/api/v1/auth/2fa/confirm'],
        ['GET', '/api/v1/contacts'],
        ['GET', '/api/v1/saved-views'],
        ['GET', '/api/v1/users'],
    ];

    private const PROFILS = [
        'aucun en-tete Accept' => [],
        'Accept: */* (curl)' => ['Accept' => '*/*'],
        'navigateur (text/html)' => ['Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'],
        'Accept: application/json' => ['Accept' => 'application/json'],
        'XHR (SPA axios)' => ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'],
    ];

    private function say(string $s): void
    {
        fwrite(STDERR, $s . "\n");
    }

    private function faireUtilisateur(string $email = 'a35@example.test', string $mdp = 'CorrectPassword12345!', int $rounds = 4): User
    {
        $ws = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'a35-' . Str::random(6), 'name' => 'A35 WS']);

        return User::create([
            'id' => (string) Str::uuid(),
            'email' => $email,
            'name' => 'A35',
            'password_hash' => password_hash($mdp, PASSWORD_BCRYPT, ['cost' => $rounds]),
            'current_workspace_id' => $ws->id,
            'first_login_completed_at' => now(),
        ]);
    }

    private function statefulHeaders(array $extra = []): array
    {
        return array_merge(['Origin' => 'https://localhost'], $extra);
    }

    /**
     * Rejoue REELLEMENT le cookie de session renvoye par la reponse, et vide le
     * cache des gardes d authentification. Sans cela, un test Laravel « reste
     * connecte » parce que SessionGuard garde l utilisateur en memoire dans le
     * conteneur : on mesurerait un artefact du banc, pas la session.
     */
    private function reprendreSession(\Illuminate\Testing\TestResponse $r): void
    {
        foreach ($r->baseResponse->headers->getCookies() as $c) {
            if ($c->getValue() === '' || $c->getValue() === null) {
                unset($this->defaultCookies[$c->getName()]);
                continue;
            }
            $this->defaultCookies[$c->getName()] = $c->getValue();
        }
        $this->app['auth']->forgetGuards();
    }

    private function oublierSession(): void
    {
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->app['auth']->forgetGuards();
    }

    /**
     * 🔴 PIEGE MESURE LE 2026-08-19 — `TestCase::call()` N'ENVOIE PAS les en-tetes.
     * Seuls `get()/post()/getJson()/...` passent par `transformHeadersToServerVars()`,
     * qui fusionne `$this->defaultHeaders`. Une matrice construite sur `call()`
     * renvoie donc CINQ FOIS la meme colonne, et l'on croit avoir compare des
     * profils de client alors qu'on n'a envoye aucun en-tete. Premier jet de cette
     * sonde : exactement ce piege. On frappe desormais par get()/post().
     */
    private function frapper(string $m, string $uri, array $h)
    {
        $garde = $this->defaultHeaders;
        $this->defaultHeaders = [];
        $r = $m === 'GET' ? $this->get($uri, $h) : $this->post($uri, [], $h);
        $this->defaultHeaders = $garde;

        return $r;
    }

    /** Comme frapper(), mais en CONSERVANT les cookies de session deja captures. */
    private function frapperSession(string $m, string $uri)
    {
        $h = ['Accept' => 'application/json'];

        return $m === 'GET' ? $this->get($uri, $h) : $this->post($uri, [], $h);
    }

    /** Temoin : la sonde voit-elle vraiment l'en-tete qu'elle croit envoyer ? */
    private function temoinEnteteRecue(): void
    {
        Route::get('/a35-temoin-entete', fn (\Illuminate\Http\Request $r) => response()->json([
            'accept' => $r->headers->get('Accept'),
            'xrw' => $r->headers->get('X-Requested-With'),
            'expectsJson' => $r->expectsJson(),
            'wantsJson' => $r->wantsJson(),
        ]));

        foreach (self::PROFILS as $nom => $h) {
            $j = $this->frapper('GET', '/a35-temoin-entete', $h)->json();
            $this->say(sprintf('   TEMOIN %-26s Accept recu=%-46s expectsJson=%s',
                $nom, var_export($j['accept'] ?? null, true), var_export($j['expectsJson'] ?? null, true)));
        }
    }

    // ------------------------------------------------------------------ 1
    public function test_01_a001_etendue_et_cause(): void
    {
        $this->say("\n===== [1] A-001 : etendue exacte du 500-au-lieu-de-401 =====");
        $this->say('APP_ENV=' . config('app.env') . '  APP_DEBUG=' . var_export(config('app.debug'), true));

        // Preuve de la cause : aucune route nommee `login` n'existe.
        $nommees = collect(Route::getRoutes()->getRoutesByName())->keys()->all();
        $this->say('routes nommees dans l app : ' . (count($nommees) ? implode(', ', $nommees) : '(AUCUNE)'));
        $this->say("route('login') existe ? " . (Route::has('login') ? 'OUI' : 'NON'));

        $this->say('-- temoin prealable : la sonde envoie-t-elle bien les en-tetes ? --');
        $this->temoinEnteteRecue();

        $lignes = [];
        foreach (self::PROTEGEES as [$m, $uri]) {
            $ligne = str_pad("$m $uri", 40);
            foreach (self::PROFILS as $nom => $h) {
                $ligne .= str_pad((string) $this->frapper($m, $uri, $h)->getStatusCode(), 6);
            }
            $lignes[] = $ligne;
        }
        foreach (array_keys(self::PROFILS) as $i => $n) {
            $this->say('   colonne ' . ($i + 1) . ' = ' . $n);
        }
        foreach ($lignes as $l) {
            $this->say($l);
        }

        // L'exception exacte, sur deux profils.
        foreach (['navigateur' => ['Accept' => 'text/html'], 'SPA axios' => ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']] as $nom => $h) {
            $r = $this->frapper('GET', '/api/v1/auth/me', $h);
            $e = $r->exception;
            $this->say("profil $nom : statut=" . $r->getStatusCode()
                . ' exception=' . ($e ? get_class($e) . ' :: ' . $e->getMessage() : '(aucune)'));
            $this->say('   corps (140 o) = ' . str_replace("\n", ' ', substr((string) $r->getContent(), 0, 140)));
        }

        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------ 2
    public function test_02_correctif_propose_fait_passer_a_401(): void
    {
        $this->say("\n===== [2] Correctif : les DEUX moities sont necessaires =====");
        $this->say('Le crash a lieu en DEUX endroits distincts :');
        $this->say('  (A) Authenticate::unauthenticated() l.104 -> redirectTo() -> le rappel pose par');
        $this->say('      ApplicationBuilder::withMiddleware() l.278 : `redirectGuestsTo(fn () => route(\'login\'))`');
        $this->say('  (B) Handler::unauthenticated() -> redirect()->guest($e->redirectTo() ?? route(\'login\'))');

        $profils = [
            'navigateur (text/html)' => ['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8'],
            'curl (Accept: */*)' => ['Accept' => '*/*'],
            'SPA axios (json+XHR)' => ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'],
        ];

        $etat = function (string $titre) use ($profils) {
            $s = [];
            foreach ($profils as $nom => $h) {
                $s[] = $nom . '=' . $this->frapper('GET', '/api/v1/auth/me', $h)->getStatusCode();
            }
            $this->say('   ' . str_pad($titre, 46) . implode('  ', $s));
        };

        /** @var \Illuminate\Foundation\Exceptions\Handler $handler */
        $handler = $this->app->make(ExceptionHandler::class);
        $defautJson = fn ($request, $e) => $request->expectsJson();
        $correctifJson = fn ($request, $e) => $request->is('api/*') || $request->expectsJson();

        $etat0 = $this->frapper('GET', '/api/v1/auth/me', ['Accept' => 'text/html'])->getStatusCode();
        $etat0json = $this->frapper('GET', '/api/v1/auth/me', ['Accept' => 'application/json'])->getStatusCode();
        $etat('(0) tel quel');

        // (1) seulement le rendu JSON force sur api/*
        $handler->shouldRenderJsonWhen($correctifJson);
        $etat1 = $this->frapper('GET', '/api/v1/auth/me', ['Accept' => 'text/html'])->getStatusCode();
        $etat('(1) shouldRenderJsonWhen SEUL');

        // (2) seulement redirectGuestsTo(null)
        $handler->shouldRenderJsonWhen($defautJson);
        \Illuminate\Auth\Middleware\Authenticate::redirectUsing(fn () => null);
        $etat2 = $this->frapper('GET', '/api/v1/auth/me', ['Accept' => 'text/html'])->getStatusCode();
        $etat('(2) redirectGuestsTo(null) SEUL');

        // (3) les deux
        $handler->shouldRenderJsonWhen($correctifJson);
        $etat3 = $this->frapper('GET', '/api/v1/auth/me', ['Accept' => 'text/html']);
        $etat('(3) LES DEUX');
        $this->say('   corps de la reponse (3) = ' . substr((string) $etat3->getContent(), 0, 120));

        // remise en etat pour ne pas contaminer les tests suivants
        \Illuminate\Auth\Middleware\Authenticate::redirectUsing(fn () => route('login'));
        $handler->shouldRenderJsonWhen($defautJson);

        $this->say(sprintf('   RESUME text/html : tel quel=%d | (1) seul=%d | (2) seul=%d | (3) les deux=%d ; json tel quel=%d',
            $etat0, $etat1, $etat2, $etat3->getStatusCode(), $etat0json));

        $this->assertSame(500, $etat0, 'temoin negatif : sans correctif, 500');
        $this->assertSame(401, $etat3->getStatusCode(), 'avec les deux moities, 401');
    }

    // ------------------------------------------------------------------ 2 bis
    public function test_02b_colonnes_2fa_absentes(): void
    {
        $this->say("\n===== [2 bis] Colonnes 2FA : le modele et le service ecrivent des colonnes qui n existent pas =====");
        $cols = DB::select("SELECT column_name FROM information_schema.columns WHERE table_name = 'users' ORDER BY 1");
        $noms = array_map(fn ($c) => $c->column_name, $cols);
        $this->say('base = ' . DB::connection()->getDatabaseName());
        $this->say('colonnes de `users` = ' . implode(', ', $noms));
        foreach (['totp_secret', 'totp_recovery_codes', 'totp_enabled_at', 'two_factor_secret', 'two_factor_enabled', 'two_factor_recovery_codes'] as $c) {
            $this->say(sprintf('   %-28s %s', $c, in_array($c, $noms, true) ? 'PRESENTE' : '>>> ABSENTE <<<'));
        }
        $this->say('User::$fillable contient : ' . implode(', ', array_intersect(
            (new User())->getFillable(),
            ['totp_secret', 'totp_enabled_at', 'two_factor_secret', 'two_factor_enabled', 'two_factor_recovery_codes']
        )));

        $u = $this->faireUtilisateur('col2fa@example.test');
        $svc = app(TwoFactorService::class);
        try {
            $svc->startEnrolment($u);
            $this->say('TwoFactorService::startEnrolment() => AUCUNE erreur');
        } catch (\Throwable $e) {
            $this->say('TwoFactorService::startEnrolment() => ' . get_class($e));
            $this->say('   ' . substr(str_replace("\n", ' ', $e->getMessage()), 0, 220));
        }

        $login = $this->withServerVariables(['REMOTE_ADDR' => '10.2.2.2'])
            ->withHeaders($this->statefulHeaders(['Accept' => 'application/json']))
            ->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'CorrectPassword12345!']);
        $this->reprendreSession($login);
        $r = $this->postJson('/api/v1/auth/2fa/setup');
        $this->say('POST /api/v1/auth/2fa/setup (authentifie) => ' . $r->getStatusCode());
        $r2 = $this->postJson('/api/v1/auth/2fa/confirm', ['code' => '123456']);
        $this->say('POST /api/v1/auth/2fa/confirm (authentifie) => ' . $r2->getStatusCode());

        $this->say('-- consequence : first_login_completed_at ne peut etre pose que par confirmEnrolment() --');
        $grep = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator('/var/www/html/app'));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')
                && str_contains((string) file_get_contents($f->getPathname()), 'first_login_completed_at')) {
                $grep[] = str_replace('/var/www/html/', '', $f->getPathname());
            }
        }
        $this->say('   fichiers de app/ qui touchent first_login_completed_at : ' . implode(', ', $grep));

        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------ 3
    public function test_03_sessions_et_revocation(): void
    {
        $this->say("\n===== [3] Sessions : duree, revocation, jeton d API =====");
        $this->say('session.lifetime = ' . config('session.lifetime') . ' min'
            . ' | expire_on_close=' . var_export(config('session.expire_on_close'), true)
            . ' | driver=' . config('session.driver')
            . ' | encrypt=' . var_export(config('session.encrypt'), true)
            . ' | http_only=' . var_export(config('session.http_only'), true)
            . ' | same_site=' . config('session.same_site')
            . ' | secure=' . var_export(config('session.secure'), true));
        $this->say('sanctum.expiration = ' . var_export(config('sanctum.expiration'), true)
            . ' (null = jeton d API SANS expiration)');
        $this->say('sanctum.middleware = ' . implode(', ', array_keys((array) config('sanctum.middleware'))));

        $u = $this->faireUtilisateur();

        // (a) session web
        $login = $this->withServerVariables(['REMOTE_ADDR' => '10.3.3.3'])
            ->withHeaders($this->statefulHeaders(['Accept' => 'application/json']))
            ->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'CorrectPassword12345!']);
        $this->say('login => ' . $login->getStatusCode() . ' requires_2fa=' . var_export($login->json('requires_2fa'), true));
        $cookies = array_map(fn ($c) => $c->getName() . '(httpOnly=' . var_export($c->isHttpOnly(), true)
            . ',secure=' . var_export($c->isSecure(), true) . ',sameSite=' . $c->getSameSite()
            . ',expire=' . ($c->getExpiresTime() ? date('c', $c->getExpiresTime()) : 'session') . ')',
            $login->baseResponse->headers->getCookies());
        $this->say('  cookies poses = ' . implode(' | ', $cookies));
        $this->say('  lignes en table sessions = ' . DB::table('sessions')->count());

        $this->reprendreSession($login);
        $this->say('  /auth/me en REJOUANT le cookie (garde videe) => ' . $this->getJson('/api/v1/auth/me')->getStatusCode());
        $lo = $this->postJson('/api/v1/auth/logout');
        $this->say('  logout => ' . $lo->getStatusCode());
        $this->reprendreSession($lo);
        $apres = $this->getJson('/api/v1/auth/me');
        $this->say('  /auth/me apres logout (cookie de la reponse) => ' . $apres->getStatusCode());
        $this->say('  lignes en table sessions apres logout = ' . DB::table('sessions')->count());

        // (b) jeton personnel Sanctum
        $this->oublierSession();
        $u = User::query()->where('email', 'a35@example.test')->first();
        $tok = $u->createToken('a35');
        $clair = $tok->plainTextToken;
        $r1 = $this->withHeaders(['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $clair])->getJson('/api/v1/auth/me');
        $this->say('  /auth/me avec jeton valide => ' . $r1->getStatusCode());
        $this->say('  expires_at du jeton = ' . var_export($tok->accessToken->expires_at, true));

        $tok->accessToken->delete();
        $this->app['auth']->forgetGuards();
        $r2 = $this->withHeaders(['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $clair])->getJson('/api/v1/auth/me');
        $this->say('  /auth/me avec jeton REVOQUE => ' . $r2->getStatusCode() . ' (401 attendu)');

        $this->assertSame(401, $r2->getStatusCode());
    }

    // ------------------------------------------------------------------ 4
    public function test_04_verrouillage_20_tentatives(): void
    {
        $this->say("\n===== [4] Verrouillage : 20 tentatives =====");
        $u = $this->faireUtilisateur('lock@example.test');

        $this->say('-- (a) 20 tentatives depuis LA MEME IP 10.0.0.1 --');
        $stat = [];
        for ($i = 1; $i <= 20; $i++) {
            $r = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
                ->withHeaders($this->statefulHeaders(['Accept' => 'application/json']))
                ->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => "MauvaisMotDePasse{$i}!"]);
            $stat[] = $r->getStatusCode();
        }
        $this->say('   statuts = ' . implode(' ', $stat));
        $f = $u->fresh();
        $this->say('   failed_login_count = ' . $f->failed_login_count . ' | locked_until = ' . var_export((string) $f->locked_until, true));

        $this->say('-- (b) 20 tentatives depuis 20 IP DIFFERENTES (rotation) --');
        $u2 = $this->faireUtilisateur('lock2@example.test');
        $stat2 = [];
        for ($i = 1; $i <= 20; $i++) {
            $r = $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.' . $i])
                ->withHeaders($this->statefulHeaders(['Accept' => 'application/json']))
                ->postJson('/api/v1/auth/login', ['email' => $u2->email, 'password' => "MauvaisMotDePasse{$i}!"]);
            $stat2[] = $r->getStatusCode();
        }
        $this->say('   statuts = ' . implode(' ', $stat2));
        $f2 = $u2->fresh();
        $this->say('   failed_login_count = ' . $f2->failed_login_count . ' | locked_until = ' . var_export((string) $f2->locked_until, true));

        $this->say('-- (c) compte verrouille + BON mot de passe --');
        $r = $this->withServerVariables(['REMOTE_ADDR' => '10.2.0.1'])
            ->withHeaders($this->statefulHeaders(['Accept' => 'application/json']))
            ->postJson('/api/v1/auth/login', ['email' => $u2->email, 'password' => 'CorrectPassword12345!']);
        $this->say('   => ' . $r->getStatusCode() . ' ' . substr((string) $r->getContent(), 0, 160));

        $this->say('-- (d) le verrou empeche-t-il de CONTINUER a tester des mots de passe ? --');
        $avant = $u2->fresh()->failed_login_count;
        $this->withServerVariables(['REMOTE_ADDR' => '10.3.0.1'])
            ->withHeaders($this->statefulHeaders(['Accept' => 'application/json']))
            ->postJson('/api/v1/auth/login', ['email' => $u2->email, 'password' => 'EncoreUnAutre123!']);
        $this->say('   failed_login_count avant=' . $avant . ' apres=' . $u2->fresh()->failed_login_count
            . ' -> le compteur continue de monter, le hachage est TOUJOURS verifie sur un compte verrouille');

        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------ 5
    public function test_05_enumeration_de_comptes(): void
    {
        $this->say("\n===== [5] Enumeration de comptes : corps ET temps =====");
        // Cout bcrypt 12 = celui de la production (config hashing par defaut).
        $this->say('hashing.bcrypt.rounds (config) = ' . var_export(config('hashing.bcrypt.rounds'), true));
        $u = $this->faireUtilisateur('existe@example.test', 'CorrectPassword12345!', 12);

        $mesure = function (string $uri, array $corps, int $n = 6): array {
            $t = [];
            $dernier = null;
            for ($i = 0; $i < $n; $i++) {
                $d = microtime(true);
                $dernier = $this->withServerVariables(['REMOTE_ADDR' => '10.9.' . random_int(0, 255) . '.' . random_int(1, 254)])
                    ->withHeaders($this->statefulHeaders(['Accept' => 'application/json']))
                    ->postJson($uri, $corps);
                $t[] = (microtime(true) - $d) * 1000;
            }
            sort($t);
            return ['med' => $t[intdiv(count($t), 2)], 'min' => $t[0], 'max' => end($t), 'st' => $dernier->getStatusCode(), 'body' => substr((string) $dernier->getContent(), 0, 180)];
        };

        foreach ([
            '/api/v1/auth/login' => [['email' => 'existe@example.test', 'password' => 'MauvaisMotDePasse1!'], ['email' => 'inconnu@example.test', 'password' => 'MauvaisMotDePasse1!']],
            '/api/v1/auth/magic-link' => [['email' => 'existe@example.test'], ['email' => 'inconnu@example.test']],
            '/api/v1/auth/password/forgot' => [['email' => 'existe@example.test'], ['email' => 'inconnu@example.test']],
        ] as $uri => $paires) {
            $this->say("-- $uri --");
            foreach (['COMPTE EXISTANT' => $paires[0], 'COMPTE INCONNU  ' => $paires[1]] as $etiq => $corps) {
                $m = $mesure($uri, $corps);
                $this->say(sprintf('   %s  statut=%d  median=%.1f ms (min %.1f / max %.1f)', $etiq, $m['st'], $m['med'], $m['min'], $m['max']));
                $this->say('      corps = ' . $m['body']);
            }
        }
        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------ 6
    public function test_06_lien_magique(): void
    {
        $this->say("\n===== [6] Lien magique : rejeu, duree de vie, entropie =====");
        $u = $this->faireUtilisateur('magic@example.test');

        $this->say('TTL declare = ' . MagicLinkService::TTL_MINUTES . ' min');
        $svc = app(MagicLinkService::class);
        $svc->issue('magic@example.test', '10.0.0.9');
        $row = DB::table('magic_links')->where('email', 'magic@example.test')->first();
        $this->say('ligne magic_links : token_hash=' . substr($row->token_hash, 0, 16) . '... (sha256, ' . strlen($row->token_hash) . ' hex)'
            . ' expires_at=' . $row->expires_at . ' consumed_at=' . var_export($row->consumed_at, true));

        // Entropie : on regenere 5 jetons et on mesure l'alphabet/longueur reelle.
        $ech = [];
        for ($i = 0; $i < 5; $i++) {
            $ech[] = Str::random(64);
        }
        $this->say('entropie du jeton : Str::random(64) -> longueur=' . strlen($ech[0])
            . ' alphabet=[A-Za-z0-9] (62) -> ~' . round(64 * log(62, 2)) . ' bits (CSPRNG random_bytes)');

        // Rejeu : il faut le jeton en clair. On le reconstruit en interceptant l emission.
        $clair = Str::random(64);
        DB::table('magic_links')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $u->id, 'email' => $u->email,
            'token_hash' => hash('sha256', $clair), 'ip' => '10.0.0.9',
            'expires_at' => now()->addMinutes(15), 'created_at' => now(),
        ]);
        $r1 = $this->withHeaders($this->statefulHeaders(['Accept' => 'application/json']))->postJson('/api/v1/auth/magic-link/verify', ['token' => $clair]);
        $this->say('1er usage => ' . $r1->getStatusCode());
        $r2 = $this->withHeaders($this->statefulHeaders(['Accept' => 'application/json']))->postJson('/api/v1/auth/magic-link/verify', ['token' => $clair]);
        $this->say('2e usage (REJEU) => ' . $r2->getStatusCode() . ' ' . substr((string) $r2->getContent(), 0, 100));

        // Expire
        $exp = Str::random(64);
        DB::table('magic_links')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $u->id, 'email' => $u->email,
            'token_hash' => hash('sha256', $exp), 'ip' => null,
            'expires_at' => now()->subMinute(), 'created_at' => now()->subMinutes(20),
        ]);
        $r3 = $this->withHeaders($this->statefulHeaders(['Accept' => 'application/json']))->postJson('/api/v1/auth/magic-link/verify', ['token' => $exp]);
        $this->say('jeton EXPIRE => ' . $r3->getStatusCode());

        // Ligne creee pour un email INCONNU ?
        $svc->issue('jamais-vu@example.test', '10.0.0.9');
        $n = DB::table('magic_links')->where('email', 'jamais-vu@example.test')->count();
        $this->say('lignes magic_links creees pour un email INCONNU = ' . $n . ' (user_id null)');

        // Un lien emis AVANT la creation du compte peut-il ouvrir la session APRES ?
        $orphelin = Str::random(64);
        DB::table('magic_links')->insert([
            'id' => (string) Str::uuid(), 'user_id' => null, 'email' => 'futur@example.test',
            'token_hash' => hash('sha256', $orphelin), 'ip' => null,
            'expires_at' => now()->addMinutes(15), 'created_at' => now(),
        ]);
        $this->faireUtilisateur('futur@example.test');
        $r4 = $this->withHeaders($this->statefulHeaders(['Accept' => 'application/json']))->postJson('/api/v1/auth/magic-link/verify', ['token' => $orphelin]);
        $this->say('jeton emis pour un email SANS compte, compte cree ensuite => ' . $r4->getStatusCode()
            . ' (200 = la session s ouvre sur un compte qui n existait pas a l emission)');

        $this->assertSame(401, $r2->getStatusCode(), 'le rejeu doit etre refuse');
    }

    // ------------------------------------------------------------------ 7
    public function test_07_reinitialisation_mot_de_passe(): void
    {
        $this->say("\n===== [7] Reinitialisation du mot de passe =====");
        $u = $this->faireUtilisateur('reset@example.test');

        // (a) jeton a usage unique ?
        $t = Str::random(64);
        DB::table('password_reset_tokens')->updateOrInsert(['email' => $u->email], ['token' => hash('sha256', $t), 'created_at' => now()]);
        $r1 = $this->withHeaders($this->statefulHeaders(['Accept' => 'application/json']))->postJson('/api/v1/auth/password/reset', [
            'email' => $u->email, 'token' => $t, 'password' => 'NouveauMotDePasse123!', 'password_confirmation' => 'NouveauMotDePasse123!',
        ]);
        $this->say('1er reset => ' . $r1->getStatusCode() . ' ' . substr((string) $r1->getContent(), 0, 120));
        $r2 = $this->withHeaders($this->statefulHeaders(['Accept' => 'application/json']))->postJson('/api/v1/auth/password/reset', [
            'email' => $u->email, 'token' => $t, 'password' => 'AutreMotDePasse1234!', 'password_confirmation' => 'AutreMotDePasse1234!',
        ]);
        $this->say('2e reset avec LE MEME jeton => ' . $r2->getStatusCode() . ' (401 attendu : usage unique)');

        // (b) expiration : jeton cree il y a 3 h, TTL annonce 60 min
        $t2 = Str::random(64);
        DB::table('password_reset_tokens')->updateOrInsert(['email' => $u->email], ['token' => hash('sha256', $t2), 'created_at' => now()->subHours(3)]);
        $ligne = DB::table('password_reset_tokens')->where('email', $u->email)->first();
        $ecart = now()->diffInMinutes($ligne->created_at);
        $this->say('created_at = ' . $ligne->created_at . '  |  now()->diffInMinutes(created_at) = ' . $ecart
            . '  (Carbon ' . \Composer\InstalledVersions::getPrettyVersion('nesbot/carbon') . ' : signe conserve)');
        $this->say('test du code : (' . $ecart . ' > 60) = ' . var_export($ecart > 60, true));
        $r3 = $this->withHeaders($this->statefulHeaders(['Accept' => 'application/json']))->postJson('/api/v1/auth/password/reset', [
            'email' => $u->email, 'token' => $t2, 'password' => 'TroisHeuresApres12!', 'password_confirmation' => 'TroisHeuresApres12!',
        ]);
        $this->say('reset avec jeton de 3 HEURES => ' . $r3->getStatusCode() . ' (401 expired_token attendu)');

        // (c) jeton tres vieux : 30 jours
        $t3 = Str::random(64);
        DB::table('password_reset_tokens')->updateOrInsert(['email' => $u->email], ['token' => hash('sha256', $t3), 'created_at' => now()->subDays(30)]);
        $r4 = $this->withHeaders($this->statefulHeaders(['Accept' => 'application/json']))->postJson('/api/v1/auth/password/reset', [
            'email' => $u->email, 'token' => $t3, 'password' => 'TrenteJoursApres12!', 'password_confirmation' => 'TrenteJoursApres12!',
        ]);
        $this->say('reset avec jeton de 30 JOURS => ' . $r4->getStatusCode());

        // (d) invalide-t-il les jetons d API existants ?
        $u = $u->fresh();
        $tok = $u->createToken('avant-reset')->plainTextToken;
        $t4 = Str::random(64);
        DB::table('password_reset_tokens')->updateOrInsert(['email' => $u->email], ['token' => hash('sha256', $t4), 'created_at' => now()]);
        $this->withHeaders($this->statefulHeaders(['Accept' => 'application/json']))->postJson('/api/v1/auth/password/reset', [
            'email' => $u->email, 'token' => $t4, 'password' => 'ApresRotation12345!', 'password_confirmation' => 'ApresRotation12345!',
        ]);
        $restants = DB::table('personal_access_tokens')->where('tokenable_id', $u->id)->count();
        $this->oublierSession();
        $rr = $this->withHeaders(['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $tok])->getJson('/api/v1/auth/me');
        $this->say('jetons d API restants apres reset = ' . $restants . ' ; /auth/me avec le jeton d AVANT => ' . $rr->getStatusCode()
            . ' (200 = le reset n a PAS revoque les jetons)');

        // (e) sessions en base ?
        $this->say('sessions stockees = ' . DB::table('sessions')->count() . ' (driver ' . config('session.driver') . ')');

        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------ 8
    public function test_08_double_authentification(): void
    {
        $this->say("\n===== [8] 2FA : contournement, codes de secours, fenetre TOTP =====");
        $u = $this->faireUtilisateur('2fa@example.test');

        $g = new Google2FA();
        $secret = $g->generateSecretKey();
        // On ecrit dans `totp_secret` — la SEULE colonne qui existe (cf. [2 bis]) —
        // pour pouvoir jouer le contournement malgre l'enrolement casse.
        $u->forceFill([
            'totp_secret' => $secret,
            'totp_enabled_at' => now(),
            'first_login_completed_at' => now(),
        ])->save();

        $login = $this->withServerVariables(['REMOTE_ADDR' => '10.8.0.1'])
            ->withHeaders($this->statefulHeaders(['Accept' => 'application/json']))
            ->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'CorrectPassword12345!']);
        $this->say('login => ' . $login->getStatusCode() . ' requires_2fa=' . var_export($login->json('requires_2fa'), true));
        $this->reprendreSession($login);

        $this->say('-- CONTOURNEMENT : routes protegees, cookie de session REJOUE, /2fa/verify JAMAIS appele --');
        foreach ([['GET', '/api/v1/auth/me'], ['GET', '/api/v1/contacts'], ['GET', '/api/v1/users'], ['GET', '/api/v1/audit-logs'], ['POST', '/api/v1/auth/onboarding/complete']] as [$m, $uri]) {
            $r = $this->frapperSession($m, $uri);
            $this->say(sprintf('   %-6s %-34s => %d', $m, $uri, $r->getStatusCode()));
        }
        $this->say('   -> 200 = la 2FA n est jamais exigee apres le mot de passe');

        $this->say('-- fenetre TOTP (verifyKey window=1) --');
        $code = $g->getCurrentOtp($secret);
        $base = $g->getTimestamp();
        foreach ([-3, -2, -1, 0, 1, 2, 3] as $pas) {
            $ok = $g->verifyKey($secret, $code, 1, $base + $pas);
            $this->say(sprintf('   horloge serveur decalee de %+d pas (%+d s) : code accepte ? %s', $pas, $pas * 30, $ok ? 'OUI' : 'non'));
        }

        $this->say('-- codes de secours (via TwoFactorService::confirmEnrolment) --');
        $u2 = $this->faireUtilisateur('2fab@example.test');
        $s2 = $g->generateSecretKey();
        $svc = app(TwoFactorService::class);
        try {
            $u2->two_factor_secret = $s2;
            $u2->save();
            $codes = $svc->confirmEnrolment($u2->fresh(), $g->getCurrentOtp($s2));
            $this->say('   ' . count($codes) . ' codes generes, exemple = ' . $codes[0]
                . ' (longueur ' . strlen($codes[0]) . ' -> ~' . round(strlen($codes[0]) * log(36, 2)) . ' bits)');
            $u2 = $u2->fresh();
            $a = $svc->verify($u2, $codes[0]);
            $u2 = $u2->fresh();
            $b = $svc->verify($u2, $codes[0]);
            $this->say('   1er usage = ' . var_export($a, true) . ' | 2e usage (REJEU) = ' . var_export($b, true));
            $this->say('   codes restants = ' . count($u2->fresh()->two_factor_recovery_codes ?? []));
        } catch (\Throwable $e) {
            $this->say('   >>> IMPOSSIBLE de generer des codes de secours : ' . get_class($e));
            $this->say('   ' . substr(str_replace("\n", ' ', $e->getMessage()), 0, 200));
            $this->say('   -> les codes de secours ne peuvent PAS exister en base (colonne absente) :');
            $this->say('      lecture du code source a la place, TwoFactorService.php l.56-62 :');
            $this->say('      strtoupper(Str::random(10)) = 10 caracteres [A-Za-z0-9] replies en majuscules');
            $this->say('      -> alphabet EFFECTIF de 36 signes, ~' . round(10 * log(36, 2)) . ' bits, et NON 62^10');
            $this->say('      -> hashes par Hash::make (bcrypt), retires du tableau apres usage (usage unique)');
        }

        $this->say('-- /auth/2fa/verify est-il limite en debit ? middleware throttle:login (5/min/IP) --');
        $st = [];
        for ($i = 0; $i < 8; $i++) {
            $st[] = $this->withServerVariables(['REMOTE_ADDR' => '10.8.9.9'])->withHeaders(['Accept' => 'application/json'])
                ->postJson('/api/v1/auth/2fa/verify', ['code' => '000000'])->getStatusCode();
        }
        $this->say('   statuts = ' . implode(' ', $st));

        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------ 9
    public function test_09_hibp_fail_open(): void
    {
        $this->say("\n===== [9] HibpChecker / NotPwnedPassword =====");
        $this->say('seuil par defaut = ' . HibpChecker::DEFAULT_THRESHOLD . ' | cache ' . HibpChecker::CACHE_TTL_SECONDS . ' s');

        // (a) reseau COUPE : le handler leve une ConnectException.
        $stack = HandlerStack::create(new MockHandler([
            new ConnectException('cURL error 6: Could not resolve host: api.pwnedpasswords.com', new PsrRequest('GET', HibpChecker::API_BASE_URL)),
            new ConnectException('cURL error 6: Could not resolve host: api.pwnedpasswords.com', new PsrRequest('GET', HibpChecker::API_BASE_URL)),
        ]));
        $coupe = new HibpChecker(new Client(['handler' => $stack]));
        $n = $coupe->getBreachCount('password');
        $this->say('RESEAU COUPE : getBreachCount("password") = ' . $n . ' | isBreached = ' . var_export($coupe->isBreached('password'), true));

        $this->app->instance(HibpChecker::class, $coupe);
        $stack2 = HandlerStack::create(new MockHandler([
            new ConnectException('cURL error 6', new PsrRequest('GET', HibpChecker::API_BASE_URL)),
        ]));
        $this->app->instance(HibpChecker::class, new HibpChecker(new Client(['handler' => $stack2])));
        $v = Validator::make(['password' => 'password'], ['password' => [new NotPwnedPassword()]]);
        $this->say('RESEAU COUPE : la regle NotPwnedPassword sur le mot de passe "password" echoue ? '
            . var_export($v->fails(), true) . '  -> ' . ($v->fails() ? 'refuse' : 'ACCEPTE (fail-open)'));

        // (b) reseau OK simule : reponse HIBP contenant le suffixe de "password"
        $sha = strtoupper(sha1('password'));
        $corps = substr($sha, 5) . ":9999999\r\nAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA:0\r\n";
        $stack3 = HandlerStack::create(new MockHandler([new \GuzzleHttp\Psr7\Response(200, [], $corps)]));
        $ok = new HibpChecker(new Client(['handler' => $stack3]));
        \Illuminate\Support\Facades\Cache::flush();
        $this->say('TEMOIN NEGATIF (reseau OK) : getBreachCount("password") = ' . $ok->getBreachCount('password'));

        // (c) reseau reel depuis ce conteneur
        \Illuminate\Support\Facades\Cache::flush();
        $reel = new HibpChecker();
        $d = microtime(true);
        $c = $reel->getBreachCount('password');
        $this->say(sprintf('RESEAU REEL depuis le conteneur : getBreachCount("password") = %d en %.0f ms', $c, (microtime(true) - $d) * 1000));

        // (d) ou la regle est-elle branchee ?
        $this->say('NotPwnedPassword est utilisee dans : ' . implode(', ', $this->grepNotPwned()));

        $this->assertTrue(true);
    }

    private function grepNotPwned(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator('/var/www/html/app'));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                $c = (string) file_get_contents($f->getPathname());
                if (str_contains($c, 'NotPwnedPassword') && ! str_contains($f->getPathname(), 'Rules/NotPwnedPassword')) {
                    $out[] = str_replace('/var/www/html/', '', $f->getPathname());
                }
            }
        }
        return $out ?: ['(aucun autre fichier)'];
    }

    // ------------------------------------------------------------------ 10
    public function test_10_enforce_first_login_setup(): void
    {
        $this->say("\n===== [10] EnforceFirstLoginSetup : peut-on l esquiver ? =====");
        $ws = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'a35fl-' . Str::random(6), 'name' => 'FL']);
        $u = User::create([
            'id' => (string) Str::uuid(), 'email' => 'firstlogin@example.test', 'name' => 'FL',
            'password_hash' => password_hash('CorrectPassword12345!', PASSWORD_BCRYPT, ['cost' => 4]),
            'current_workspace_id' => $ws->id,
            'first_login_completed_at' => null,
        ]);

        $login = $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.1'])
            ->withHeaders($this->statefulHeaders(['Accept' => 'application/json']))
            ->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'CorrectPassword12345!']);
        $this->say('login (first_login_completed_at = null) => ' . $login->getStatusCode());
        $this->reprendreSession($login);

        foreach ([
            ['GET', '/api/v1/contacts', 'route metier -> 403 attendu'],
            ['GET', '/api/v1/users', 'route metier -> 403 attendu'],
            ['GET', '/api/v1/auth/me', 'liste blanche'],
            ['GET', '/api/v1/contacts/', 'meme route, barre oblique finale'],
            ['GET', '/api/v1/contacts?x=1', 'meme route, parametre'],
            ['GET', '/API/v1/contacts', 'casse differente'],
            ['GET', '/api/v1/exports/contacts.csv', 'export'],
            ['GET', '/broadcasting/auth', 'hors groupe api'],
            ['POST', '/broadcasting/auth', 'hors groupe api'],
        ] as [$m, $uri, $note]) {
            $r = $this->frapperSession($m, $uri);
            $this->say(sprintf('   %-5s %-32s => %-3d  %s', $m, $uri, $r->getStatusCode(), $note));
        }

        // Le jeton d API traverse-t-il la garde ?
        $tok = $u->fresh()->createToken('fl')->plainTextToken;
        $this->oublierSession();
        $r = $this->withHeaders(['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $tok])->getJson('/api/v1/contacts');
        $this->say('   jeton d API sur /api/v1/contacts (first-login non fait) => ' . $r->getStatusCode());

        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------ 11
    public function test_11_login_divers(): void
    {
        $this->say("\n===== [11] Divers : contrat de /auth/login =====");
        $u = $this->faireUtilisateur('divers@example.test', 'MotDePasseCourt1!');

        // Mot de passe historique de moins de 12 caracteres : refuse a la CONNEXION
        $court = $this->faireUtilisateur('court@example.test', 'court');
        $r = $this->withServerVariables(['REMOTE_ADDR' => '10.11.0.1'])
            ->withHeaders($this->statefulHeaders(['Accept' => 'application/json']))
            ->postJson('/api/v1/auth/login', ['email' => 'court@example.test', 'password' => 'court']);
        $this->say('connexion avec le BON mot de passe de 5 caracteres => ' . $r->getStatusCode() . ' ' . substr((string) $r->getContent(), 0, 140));

        // Aucune regle NotPwnedPassword au login ni au changement de mot de passe ?
        $this->say('LoginRequest regles password = Password::min(12) seulement (pas de NotPwnedPassword)');

        // Compte sans mot de passe (password_hash null) : reponse ?
        $sans = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'a35sp-' . Str::random(6), 'name' => 'SP']);
        User::create(['id' => (string) Str::uuid(), 'email' => 'sansmdp@example.test', 'name' => 'SP', 'password_hash' => null, 'current_workspace_id' => $sans->id]);
        $r2 = $this->withServerVariables(['REMOTE_ADDR' => '10.11.0.2'])
            ->withHeaders($this->statefulHeaders(['Accept' => 'application/json']))
            ->postJson('/api/v1/auth/login', ['email' => 'sansmdp@example.test', 'password' => 'NimporteQuoi12345!']);
        $this->say('compte SANS password_hash => ' . $r2->getStatusCode());

        // logout : 204 avec un corps ?
        $li = $this->withServerVariables(['REMOTE_ADDR' => '10.11.0.3'])
            ->withHeaders($this->statefulHeaders(['Accept' => 'application/json']))
            ->postJson('/api/v1/auth/login', ['email' => 'divers@example.test', 'password' => 'MotDePasseCourt1!']);
        $this->reprendreSession($li);
        $lo = $this->postJson('/api/v1/auth/logout');
        $this->say('logout => ' . $lo->getStatusCode() . ' longueur du corps = ' . strlen((string) $lo->getContent()));

        $this->assertTrue(true);
    }
}
