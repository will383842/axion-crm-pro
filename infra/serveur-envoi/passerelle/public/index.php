<?php

declare(strict_types=1);

// Point d'entrée php-fpm. Aucun affichage d'erreur ; la base est hors du dossier public.
require __DIR__.'/../vendor/autoload.php';

use Passerelle\Configuration;
use Passerelle\KumoInactif;
use Passerelle\Passerelle;
use Passerelle\Requete;
use Passerelle\Stockage;

ini_set('display_errors', '0');
header_remove('X-Powered-By');

$entetes = [];
foreach (getallheaders() ?: [] as $k => $v) {
    $entetes[strtolower($k)][] = $v;
}
$chemin = $_SERVER['REQUEST_URI'] ?? '/';
$stockage = new Stockage(getenv('PASSERELLE_BASE') ?: '/var/lib/passerelle/passerelle.sqlite', static fn (): int => time());
$rep = (new Passerelle($stockage, new KumoInactif, Configuration::cles()))
    ->traiter(new Requete($_SERVER['REQUEST_METHOD'] ?? 'GET', $chemin, (string) file_get_contents('php://input'), $entetes));

http_response_code($rep->statut);
foreach ($rep->entetes as $k => $v) {
    header("$k: $v");
}
echo $rep->corps;
