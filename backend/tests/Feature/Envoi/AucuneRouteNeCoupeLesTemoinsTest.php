<?php

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class);

test('aucune route (API ou web) ne touche au mode témoins', function () {
    // Garde contre le passage à vide : sans route chargée, la boucle ne prouverait rien.
    expect(count(Route::getRoutes()->getRoutes()))->toBeGreaterThan(10);
    foreach (Route::getRoutes() as $route) {
        $texte = strtolower($route->uri() . ' ' . ($route->getActionName()));
        expect($texte)->not->toContain('temoins')->and($texte)->not->toContain('temoin-off')->and($texte)->not->toContain('tout-arreter');
    }
});

test('le code du contrat n’expose aucun réglage du mode témoins', function () {
    $contrat = file_get_contents(base_path('app/Envoi/Contrat/ServeurEnvoi.php'));
    expect($contrat)->not->toMatch('/function\s+(eteindre|couper|desactiver|definir)\w*[Tt]emoins/');
    // La seule écriture de « mode_temoins = false » vit dans le dépôt, appelée par la commande artisan.
    $appelants = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'))) as $f) {
        if ($f->isFile() && str_ends_with($f->getFilename(), '.php')
            && str_contains(file_get_contents($f->getPathname()), 'eteindreTemoins(')) {
            $appelants[] = str_replace(base_path() . '/', '', $f->getPathname());
        }
    }
    sort($appelants);
    expect($appelants)->toBe([
        'app/Console/Commands/EnvoiTemoinsOff.php',
        'app/Envoi/Depot/DepotSocle.php',
        'app/Envoi/Depot/DepotSocleDb.php',
    ]);
});

test('la config garde l’envoi réel éteint par défaut', function () {
    expect(config('envoi.reel_actif'))->toBeFalse();
});
