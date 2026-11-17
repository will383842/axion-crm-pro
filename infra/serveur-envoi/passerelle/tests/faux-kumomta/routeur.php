<?php

declare(strict_types=1);

// Faux KumoMTA HTTP (tests seulement) : `php -S 127.0.0.1:<port> routeur.php`. Écrit chaque requête reçue
// (une ligne JSON) dans le fichier FAUX_KUMO_JOURNAL ; FAUX_KUMO_MODE : ok | 500 | 400 | lent.
$journal = (string) getenv('FAUX_KUMO_JOURNAL');
$mode = (string) getenv('FAUX_KUMO_MODE') ?: 'ok';
$chemin = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$corps = (string) file_get_contents('php://input');
if ($journal !== '') {
    file_put_contents($journal, json_encode([
        'methode' => $_SERVER['REQUEST_METHOD'], 'chemin' => $chemin, 'corps' => json_decode($corps, true) ?? $corps,
    ], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
}
header('Content-Type: application/json');
if ($mode === 'lent') {
    sleep(8);
}
if ($mode === '500') {
    http_response_code(500);
    echo '{}';

    return;
}
if ($mode === '400') {
    http_response_code(400);
    echo '{}';

    return;
}
if ($chemin === '/api/admin/suspend/v1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    echo json_encode(['id' => 'susp-'.bin2hex(random_bytes(4))]);

    return;
}
echo '{}';
