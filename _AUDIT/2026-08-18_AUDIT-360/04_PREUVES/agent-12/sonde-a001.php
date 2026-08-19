<?php
/**
 * Agent 12 — A-001 : mesure de l'etendue exacte, sans le rendu de page de
 * debogage (config app.debug forcee a false pour reproduire la PRODUCTION).
 */
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

config(['app.debug' => false]);

use Illuminate\Http\Request;

function joue(string $titre, string $methode, string $uri, array $entetes = [], ?string $corps = null): void
{
    global $kernel;
    $server = ['REQUEST_METHOD' => $methode, 'REQUEST_URI' => $uri, 'HTTP_HOST' => 'api.localhost'];
    foreach ($entetes as $k => $v) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
    }
    if ($corps !== null) {
        $server['CONTENT_TYPE'] = 'application/json';
    }
    $url = parse_url($uri);
    $query = [];
    if (isset($url['query'])) {
        parse_str($url['query'], $query);
    }
    $request = Request::create($uri, $methode, $query, [], [], $server, $corps);
    $t0 = microtime(true);
    try {
        $response = $kernel->handle($request);
        $code = (string) $response->getStatusCode();
        $loc = $response->headers->get('Location');
        $contenu = (string) $response->getContent();
    } catch (\Throwable $e) {
        $code = 'EXCEPTION';
        $loc = null;
        $contenu = get_class($e) . ' : ' . $e->getMessage();
    }
    $ms = round((microtime(true) - $t0) * 1000);
    echo "### $titre\n    $methode $uri\n    -> HTTP $code" . ($loc ? " Location: $loc" : '') . "  ({$ms} ms)\n";
    echo '    ' . str_replace("\n", ' ', mb_substr(trim($contenu), 0, 260)) . "\n\n";
}

echo "===== A-001 : etendue, app.debug force a FALSE (comme en production) =====\n";
echo 'date : ' . gmdate('c') . "\n\n";

joue('PUBLIQUE  /up', 'GET', '/up');
joue('PUBLIQUE  / (web.php)', 'GET', '/');
joue('PUBLIQUE  POST /api/v1/auth/login (html)', 'POST', '/api/v1/auth/login', ['Accept' => 'text/html'], '{}');
joue('PUBLIQUE  POST /api/v1/auth/magic-link (html)', 'POST', '/api/v1/auth/magic-link', ['Accept' => 'text/html'], '{"email":"a@b.c"}');
joue('PUBLIQUE  GET /api/v1/rgpd/export/{jeton} (html)', 'GET', '/api/v1/rgpd/export/' . str_repeat('a', 48), ['Accept' => 'text/html']);
joue('PUBLIQUE  POST /api/internal/scraper-result (sans sig)', 'POST', '/api/internal/scraper-result', ['Accept' => 'text/html'], '{}');

joue('SANCTUM   GET /api/v1/auth/me  Accept: application/json', 'GET', '/api/v1/auth/me', ['Accept' => 'application/json']);
joue('SANCTUM   GET /api/v1/auth/me  Accept: text/html', 'GET', '/api/v1/auth/me', ['Accept' => 'text/html']);
joue('SANCTUM   GET /api/v1/companies  AUCUN Accept', 'GET', '/api/v1/companies');
joue('SANCTUM   GET /api/v1/crm/contacts-hub  Accept: text/html', 'GET', '/api/v1/crm/contacts-hub', ['Accept' => 'text/html']);
joue('SANCTUM   POST /api/v1/auth/logout  Accept: text/html', 'POST', '/api/v1/auth/logout', ['Accept' => 'text/html'], '{}');
joue('SANCTUM   GET /api/v1/cold-email  Accept: text/html', 'GET', '/api/v1/cold-email', ['Accept' => 'text/html']);

echo "===== FIN =====\n";
