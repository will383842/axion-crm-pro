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

echo "===== ROUTES INTERNES SIGNEES — AGENT 12 =====\n";
echo 'date : ' . gmdate('c') . "\n";
echo 'WORKER_INTERNAL_HMAC_SECRET  = ' . var_export(env('WORKER_INTERNAL_HMAC_SECRET'), true) . "\n";
echo 'crm.ingest.hmac_secret       = ' . var_export(config('crm.ingest.hmac_secret'), true) . "\n";
echo 'crm.ingest.enabled           = ' . var_export(config('crm.ingest.enabled'), true) . "\n";
echo 'crm.scrape_funnel.enabled    = ' . var_export(config('crm.scrape_funnel.enabled'), true) . "\n";
echo 'mail.webhooks.zeptomail_token= ' . var_export(config('mail.webhooks.zeptomail_token'), true) . "\n\n";

echo "----- TEMOIN NEGATIF : signature fausse ----------------------------\n";
joue('scraper-result — signature bidon', 'POST', '/api/internal/scraper-result', ['X-Worker-Signature' => '00deadbeef'], '{"run_id":1}');
joue('scraper-result — sans en-tete de signature', 'POST', '/api/internal/scraper-result', [], '{"run_id":1}');
joue('site-sync — signature bidon', 'POST', '/api/internal/site-sync', ['X-Site-Signature' => 'sha256=deadbeef', 'X-Site-Timestamp' => (string) time()], '{"type":"x"}');
joue('site-sync/gdpr — signature bidon', 'POST', '/api/internal/site-sync/gdpr', ['X-Site-Signature' => 'sha256=deadbeef', 'X-Site-Timestamp' => (string) time()], '{"action":"erase"}');
joue('email/zeptomail — sans jeton', 'POST', '/api/internal/email/zeptomail', [], '{"event_name":"hardbounce"}');
joue('email/zeptomail — mauvais jeton', 'POST', '/api/internal/email/zeptomail?t=mauvais', [], '{"event_name":"hardbounce"}');

echo "----- TEMOIN POSITIF : signature calculee avec le secret CONFIGURE --\n";
$corps = '{"run_id":424242,"source":"audit-agent-12","status":"ok"}';
$secret = (string) env('WORKER_INTERNAL_HMAC_SECRET', '');
$sig = hash_hmac('sha256', $corps, $secret);
echo "    corps     = $corps\n    secret    = " . var_export($secret, true) . "\n    signature = $sig\n\n";
joue('scraper-result — signature FORGEE avec ce secret', 'POST', '/api/internal/scraper-result', ['X-Worker-Signature' => $sig], $corps);
joue('scraper-result — REJEU identique #2', 'POST', '/api/internal/scraper-result', ['X-Worker-Signature' => $sig], $corps);
joue('scraper-result — REJEU identique #3', 'POST', '/api/internal/scraper-result', ['X-Worker-Signature' => $sig], $corps);
joue('scraper-result — CORPS ALTERE, meme signature', 'POST', '/api/internal/scraper-result', ['X-Worker-Signature' => $sig], '{"run_id":999999}');

echo "----- site-sync : signature forgee, rejeu, horodatage --------------\n";
$ts = (string) time();
$corpsSite = '{"type":"audit_agent12"}';
$secretSite = (string) config('crm.ingest.hmac_secret', '');
$sigSite = 'sha256=' . hash_hmac('sha256', $ts . '.' . $corpsSite, $secretSite);
echo "    charge signee = $ts.$corpsSite\n    signature     = $sigSite\n\n";
joue('site-sync — signature FORGEE', 'POST', '/api/internal/site-sync', ['X-Site-Signature' => $sigSite, 'X-Site-Timestamp' => $ts], $corpsSite);
joue('site-sync — REJEU identique', 'POST', '/api/internal/site-sync', ['X-Site-Signature' => $sigSite, 'X-Site-Timestamp' => $ts], $corpsSite);
$vieux = (string) (time() - 4000);
$sigVieux = 'sha256=' . hash_hmac('sha256', $vieux . '.' . $corpsSite, $secretSite);
joue('site-sync — horodatage a t-4000 s (hors fenetre 300 s)', 'POST', '/api/internal/site-sync', ['X-Site-Signature' => $sigVieux, 'X-Site-Timestamp' => $vieux], $corpsSite);
$sansTs = 'sha256=' . hash_hmac('sha256', '.' . $corpsSite, $secretSite);
joue('site-sync — SANS horodatage', 'POST', '/api/internal/site-sync', ['X-Site-Signature' => $sansTs], $corpsSite);

echo "----- routage : /search declare deux fois ? ------------------------\n";
$n = 0;
foreach (app('router')->getRoutes()->getRoutes() as $r) {
    if ($r->uri() === 'api/v1/search') {
        $n++;
        $uses = $r->getAction('uses');
        echo '    entree #' . $n . ' : ' . implode('|', $r->methods()) . ' -> '
            . (is_string($uses) ? $uses : 'Closure') . "\n";
    }
}
echo "    total d entrees 'api/v1/search' RETENUES par le routeur : $n\n";
echo "    (le fichier routes/api.php en declare DEUX : ligne 99 fermeture, ligne 207 GlobalSearchController)\n\n";

echo "----- stubs / routes mortes ---------------------------------------\n";
joue('/v1/crm/inexistant (404 attendu, plus 501)', 'GET', '/api/v1/crm/inexistant', ['Accept' => 'application/json']);
joue('/v1/analytics (404 attendu)', 'GET', '/api/v1/analytics', ['Accept' => 'application/json']);

echo "===== FIN =====\n";

