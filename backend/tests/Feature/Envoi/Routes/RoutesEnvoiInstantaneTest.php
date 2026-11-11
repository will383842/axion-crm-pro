<?php

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class)->group('envoi');

/** R00 : déplacer les routes ne change ni méthode, ni URI, ni middlewares (hors `produit-envoi`, ajouté exprès). */
test('les routes /envoi/* sont identiques à l’instantané d’avant le déplacement (hors produit-envoi)', function () {
    $actuel = [];
    foreach (Route::getRoutes() as $r) {
        if (! str_starts_with($r->uri(), 'api/v1/envoi/')) {
            continue;
        }
        $mw = array_values(array_filter($r->gatherMiddleware(), fn ($m) => $m !== 'produit-envoi'));
        sort($mw);
        foreach (array_diff($r->methods(), ['HEAD']) as $m) {
            $actuel[] = ['methode' => $m, 'uri' => $r->uri(), 'middlewares' => $mw];
        }
    }
    usort($actuel, fn ($a, $b) => [$a['uri'], $a['methode']] <=> [$b['uri'], $b['methode']]);

    $attendu = json_decode((string) file_get_contents(__DIR__ . '/instantane-routes-envoi.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($attendu)->not->toBeEmpty()->and($actuel)->toBe($attendu);
});

test('toute route /envoi/* porte produit-envoi, y compris la santé', function () {
    $vues = 0;
    foreach (Route::getRoutes() as $r) {
        if (str_starts_with($r->uri(), 'api/v1/envoi/')) {
            $vues++;
            expect($r->gatherMiddleware())->toContain('produit-envoi');
        }
    }
    expect($vues)->toBeGreaterThan(0);
});

test('un fichier de routes par domaine existe, et routes/envoi.php les requiert tous', function () {
    $domaines = ['sante', 'feu-vert', 'chauffe', 'boites', 'sequences', 'infrastructure', 'resultats', 'conformite', 'campagnes', 'creation', 'reponses', 'reglages', 'reputation'];
    $racine = (string) file_get_contents(base_path('routes/envoi.php'));
    foreach ($domaines as $d) {
        expect(file_exists(base_path("routes/envoi/{$d}.php")))->toBeTrue($d);
        expect($racine)->toContain("'{$d}'");
    }
});
