<?php

/*
 * Garde commune des scripts `ouvrier_*.php` : ils écrivent pour de bon en base (auto-commit) et ne doivent JAMAIS tourner
 * ailleurs que dans la suite de tests. Inclus en TOUT PREMIER, avant le démarrage de l'application, la lecture des arguments
 * et toute écriture. L'environnement est lu dans le processus (le test le pose explicitement) ; absent ou autre que « testing » :
 * arrêt avec le code 3 et un message sur STDERR, rien sur STDOUT.
 */
$environnementOuvrier = getenv('APP_ENV');
if ($environnementOuvrier !== 'testing') {
    fwrite(STDERR, 'Ouvrier de test refusé : exige APP_ENV=testing (environnement lu : ' . ($environnementOuvrier === false ? 'absent' : $environnementOuvrier) . ").\n");
    exit(3);
}
unset($environnementOuvrier);
