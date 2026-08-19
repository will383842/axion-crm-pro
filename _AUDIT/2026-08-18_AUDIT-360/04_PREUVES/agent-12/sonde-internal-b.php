<?php
/**
 * Agent 12 — routes internes signees : temoin NEGATIF (mauvaise signature),
 * temoin POSITIF (signature calculee avec le secret reellement configure),
 * rejeu, corps altere, fenetre d'horodatage, limitation de debit.
 * Atelier local uniquement.
 */
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config(['app.debug' => false]);

use Illuminate\Http\Request;

function joue(string $titre, string $methode, string $uri, array $entetes = [], ?string $corps = null): string
{
    global $kernel;
    $server = ['REQUEST_METHOD' => $methode, 'REQUEST_URI' => $uri, 'HTTP_HOST' => 'api.localhost'];
    foreach ($entetes as $k => $v) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
    }
    if ($corps !== null) {
        $server['CONTENT_TYPE'] = 'application/json';
    }
    $request = Request::create($uri, $methode, [], [], [], $server, $corps);
    try {
        $response = $kernel->handle($request);
        $code = (string) $response->getStatusCode();
        $contenu = (string) $response->getContent();
    } catch (\Throwable $e) {
        $code = 'EXCEPTION';
        $contenu = get_class($e) . ' : ' . $e->getMessage();
    }
    echo "### $titre\n    $methode $uri\n    -> HTTP $code\n    " . str_replace("\n", ' ', mb_substr(trim($contenu), 0, 300)) . "\n\n";

    return $code;
}

echo "===== LIMITATION DE DEBIT — AGENT 12 =====
";
echo "----- limitation de debit : 70 appels sur scraper-result -----------\n";
echo "    (les 3 autres routes internes portent throttle:internal ; celle-ci n'en porte AUCUN)\n";
$codes = [];
for ($i = 0; $i < 70; $i++) {
    $server = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/internal/scraper-result', 'HTTP_HOST' => 'api.localhost', 'HTTP_X_WORKER_SIGNATURE' => 'faux', 'CONTENT_TYPE' => 'application/json'];
    $r = Request::create('/api/internal/scraper-result', 'POST', [], [], [], $server, '{}');
    $codes[] = $kernel->handle($r)->getStatusCode();
}
$distincts = array_count_values($codes);
echo '    codes obtenus sur 70 appels : ' . json_encode($distincts) . "\n";
echo '    un 429 est-il apparu ? ' . (isset($distincts[429]) ? 'OUI' : 'NON') . "\n\n";

echo "----- comparaison : 70 appels sur site-sync (throttle:internal) ----\n";
$codes2 = [];
for ($i = 0; $i < 70; $i++) {
    $server = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/internal/site-sync', 'HTTP_HOST' => 'api.localhost', 'HTTP_X_SITE_SIGNATURE' => 'faux', 'HTTP_X_SITE_TIMESTAMP' => (string) time(), 'CONTENT_TYPE' => 'application/json'];
    $r = Request::create('/api/internal/site-sync', 'POST', [], [], [], $server, '{}');
    $codes2[] = $kernel->handle($r)->getStatusCode();
}
echo '    codes obtenus sur 70 appels : ' . json_encode(array_count_values($codes2)) . "\n";
echo '    (plafond configure du limiteur `internal` : voir AppServiceProvider)' . "\n\n";

echo "===== FIN =====
";
