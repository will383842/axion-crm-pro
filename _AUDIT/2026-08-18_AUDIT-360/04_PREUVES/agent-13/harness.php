<?php
/**
 * Harnais d'audit AGENT 13 — canal entrant site → CRM.
 * Ne modifie AUCUN fichier du produit. Vit dans /tmp du conteneur.
 * Base de travail : axion_crm_audit13 (jetable, créée pour l'audit).
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/../var/www/html/vendor/autoload.php';

$app = require_once __DIR__ . '/../var/www/html/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

// --- Réglages de l'atelier -------------------------------------------------
config([
    'database.connections.pgsql.database' => 'axion_crm_audit13',
    'crm.ingest.enabled' => getenv('H_ENABLED') === '0' ? false : true,
    'crm.ingest.candidates_enabled' => getenv('H_CAND') === '0' ? false : true,
    'crm.ingest.hmac_secret' => 'audit13-secret-local',
    'crm.ingest.business_workspace' => 'axion-ia',
    'crm.ingest.max_clock_skew_seconds' => (int) (getenv('H_SKEW') !== false ? getenv('H_SKEW') : 300),
]);
Illuminate\Support\Facades\DB::purge('pgsql');

$SECRET = 'audit13-secret-local';

function post(string $uri, array $body, ?string $sigOverride = null, ?string $tsOverride = null, bool $noSig = false): array
{
    global $kernel, $SECRET;
    $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ts = $tsOverride ?? (string) time();
    $sig = $sigOverride ?? hash_hmac('sha256', $ts . '.' . $json, $SECRET);

    $headers = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SITE_TIMESTAMP' => $ts,
    ];
    if (! $noSig) {
        $headers['HTTP_X_SITE_SIGNATURE'] = $sig;
    }

    $request = Illuminate\Http\Request::create($uri, 'POST', [], [], [], $headers, $json);
    $response = $kernel->handle($request);

    return [
        'status' => $response->getStatusCode(),
        'body' => $response->getContent(),
        'sent_json' => $json,
        'ts' => $ts,
    ];
}

function show(string $label, array $r): void
{
    echo "\n=== {$label} ===\n";
    echo "HTTP {$r['status']}\n";
    echo substr($r['body'], 0, 1200) . "\n";
}

function q(string $sql): array
{
    return Illuminate\Support\Facades\DB::select($sql);
}

function dump(string $label, string $sql): void
{
    echo "\n--- {$label} ---\n";
    foreach (q($sql) as $row) {
        echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

// Charge le scénario demandé
$scenario = $argv[1] ?? 'help';
require __DIR__ . "/scenario_{$scenario}.php";
