<?php

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class)->group('envoi');

/** Complète AucuneRouteNeCoupeLesTemoinsTest (inchangé) : le singulier et les mots d’armement sont interdits aussi. */
test('aucune URI ni action de route ne contient temoin, armer ou reprendre', function () {
    expect(count(Route::getRoutes()->getRoutes()))->toBeGreaterThan(10);
    foreach (Route::getRoutes() as $route) {
        $texte = strtolower($route->uri() . ' ' . $route->getActionName());
        foreach (['temoin', 'armer', 'reprendre'] as $mot) {
            expect($texte)->not->toContain($mot);
        }
    }
});
