<?php

declare(strict_types=1);

// Point d'entrée php-fpm. Aucun affichage d'erreur ; la base est hors du dossier public.
require __DIR__.'/../vendor/autoload.php';

use Passerelle\Configuration;
use Passerelle\GardeTls;
use Passerelle\KumoInactif;
use Passerelle\Passerelle;
use Passerelle\Requete;
use Passerelle\Stockage;

ini_set('display_errors', '0');
header_remove('X-Powered-By');

// TLS mutuel : avec l'envoi réel actif, pas de certificat client vérifié = 401, avant toute lecture de la requête.
if (! GardeTls::autorise($_SERVER, getenv('ENVOI_REEL_ACTIF') === false ? null : (string) getenv('ENVOI_REEL_ACTIF'))) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo '{"erreur":"non_autorise"}';
    exit;
}

$entetes = [];
foreach (getallheaders() ?: [] as $k => $v) {
    $entetes[strtolower($k)][] = $v;
}
$chemin = $_SERVER['REQUEST_URI'] ?? '/';
// Base ouverte à la demande, DANS le try de Passerelle::traiter() : une base illisible donne un 500 propre.
$stockage = static fn (): Stockage => new Stockage(getenv('PASSERELLE_BASE') ?: '/var/lib/passerelle/passerelle.sqlite', static fn (): int => time());
$rep = (new Passerelle($stockage, new KumoInactif, Configuration::cles()))
    ->traiter(new Requete($_SERVER['REQUEST_METHOD'] ?? 'GET', $chemin, (string) file_get_contents('php://input'), $entetes));

http_response_code($rep->statut);
foreach ($rep->entetes as $k => $v) {
    header("$k: $v");
}
echo $rep->corps;
