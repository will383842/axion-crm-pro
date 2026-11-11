<?php

use Tests\TestCase;

uses(TestCase::class)->group('envoi');

/** Le jour où `FeaturesController` publiera `produits`, il lira la MÊME source que la garde `produit-envoi`. */
test('FeaturesController ne publie produits qu’en lisant ProduitsEspace', function () {
    $code = (string) file_get_contents(base_path('app/Http/Controllers/Api/FeaturesController.php'));
    if (str_contains($code, "'produits'")) {
        expect($code)->toContain('ProduitsEspace');
    }
    expect(file_exists(base_path('app/Socle/Produits/ProduitsEspace.php')))->toBeTrue();
});
