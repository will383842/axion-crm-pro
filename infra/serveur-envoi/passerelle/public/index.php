<?php

declare(strict_types=1);

// Point d'entrée php-fpm. Aucun affichage d'erreur ; la base est hors du dossier public.
require __DIR__.'/../vendor/autoload.php';

use Passerelle\Configuration;
use Passerelle\GardeTls;
use Passerelle\Kumo;
use Passerelle\KumoHttp;
use Passerelle\KumoInactif;
use Passerelle\Passerelle;
use Passerelle\Requete;
use Passerelle\Sante\MesureurSysteme;
use Passerelle\Stockage;

/**
 * Choix de l'adaptateur KumoMTA (C4-B). `KumoHttp` SEULEMENT si `PASSERELLE_KUMO_URL` est non vide ET `ENVOI_REEL_ACTIF`
 * vaut exactement « true » (lecture seule ; même variable que GardeTls). Tout le reste : `KumoInactif`.
 * Hôte non local : `InvalidArgumentException` (refus au démarrage, géré en 500 propre par le point d'entrée).
 *
 * @param array<string,mixed> $env variables d'environnement
 * @param \Closure():Stockage $stockage fabrique du stockage (ouvert seulement si KumoHttp est choisi)
 */
function choisirKumo(array $env, \Closure $stockage): Kumo
{
    $url = $env['PASSERELLE_KUMO_URL'] ?? null;
    if (! is_string($url) || $url === '' || ($env['ENVOI_REEL_ACTIF'] ?? null) !== 'true') {
        return new KumoInactif;
    }

    return new KumoHttp($url, $stockage());
}

if (defined('PASSERELLE_SANS_EXECUTION')) {
    return; // tests : charge seulement la fonction
}

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
$base = getenv('PASSERELLE_BASE') ?: '/var/lib/passerelle/passerelle.sqlite';
$stockage = static fn (): Stockage => new Stockage($base, static fn (): int => time());
$urlKumo = getenv('PASSERELLE_KUMO_URL');
$envKumo = ['PASSERELLE_KUMO_URL' => $urlKumo === false ? null : $urlKumo, 'ENVOI_REEL_ACTIF' => getenv('ENVOI_REEL_ACTIF') === false ? null : getenv('ENVOI_REEL_ACTIF')];
try {
    $kumo = choisirKumo($envKumo, $stockage);
} catch (\Throwable) {
    // Configuration KumoMTA refusée (hôte non local, base illisible…) : échec fermé, 500 propre, rien n'est servi.
    http_response_code(500);
    header('Content-Type: application/json');
    echo '{"erreur":"configuration_kumo"}';
    exit;
}
// `file_age_s` n'est mesuré que si l'adaptateur HTTP est actif (même condition que le choix de l'adaptateur).
$mesureur = new MesureurSysteme(dirname($base), getenv('PASSERELLE_CERTIFICAT') ?: null, kumoBase: $kumo instanceof KumoHttp ? (string) $urlKumo : null);
$rep = (new Passerelle($stockage, $kumo, Configuration::cles(), null, null, null, $mesureur))
    ->traiter(new Requete($_SERVER['REQUEST_METHOD'] ?? 'GET', $chemin, (string) file_get_contents('php://input'), $entetes));

http_response_code($rep->statut);
foreach ($rep->entetes as $k => $v) {
    header("$k: $v");
}
echo $rep->corps;
