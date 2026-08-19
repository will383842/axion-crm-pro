<?php
/**
 * Agent 12 — limitation de debit : TEMOIN POSITIF (une route limitee rougit)
 * puis constat sur les routes non limitees. Atelier local.
 */
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config(['app.debug' => false]);

use Illuminate\Http\Request;

function rafale(string $titre, string $methode, string $uri, int $n, array $entetes = [], ?string $corps = null): void
{
    global $kernel;
    $codes = [];
    for ($i = 0; $i < $n; $i++) {
        $server = ['REQUEST_METHOD' => $methode, 'REQUEST_URI' => $uri, 'HTTP_HOST' => 'api.localhost', 'REMOTE_ADDR' => '203.0.113.7'];
        foreach ($entetes as $k => $v) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        }
        if ($corps !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        $r = Request::create($uri, $methode, [], [], [], $server, $corps);
        $codes[] = $kernel->handle($r)->getStatusCode();
    }
    echo "### $titre\n    $methode $uri  x$n\n";
    echo '    codes : ' . implode(' ', $codes) . "\n";
    echo '    un 429 apparait ? ' . (in_array(429, $codes, true) ? 'OUI' : 'NON') . "\n\n";
}

echo "===== LIMITATION DE DEBIT — AGENT 12 =====\n";
echo 'date : ' . gmdate('c') . "\n";
echo "limiteurs declares (RouteServiceProvider) : api=60/min (JAMAIS ATTACHE), login=5/min, magic-link=3/min, internal=600/min, scraper-launch=10/min, scraper-list=60/min\n\n";

echo "----- TEMOIN POSITIF : route limitee (throttle:login = 5/min) ------\n";
rafale('POST /api/v1/auth/login', 'POST', '/api/v1/auth/login', 9, ['Accept' => 'application/json'], '{}');

echo "----- ROUTE INTERNE SANS AUCUN throttle ---------------------------\n";
rafale('POST /api/internal/scraper-result (aucun throttle declare)', 'POST', '/api/internal/scraper-result', 9, ['X-Worker-Signature' => 'faux'], '{}');

echo "----- ROUTE v1 PROTEGEE SANS throttle -----------------------------\n";
rafale('GET /api/v1/companies (aucun throttle declare)', 'GET', '/api/v1/companies', 9, ['Accept' => 'application/json']);

echo "===== FIN =====\n";
