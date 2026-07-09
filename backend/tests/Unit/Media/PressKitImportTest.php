<?php

use App\Services\Email\MxEmailValidator;

/**
 * Kit presse (media:import-press-kit) — tests sans base de données.
 *
 * On vérifie (1) que les données JSON committées sont bien parsées et complètes
 * (193 émissions, médias de diffusion structurés) et (2) que la doctrine
 * « 0 email douteux » tient : le validateur MX rejette un email bidon et garde
 * un email rédaction générique (role) légitime en presse.
 */

/** @return array<int,array<string,mixed>> */
function loadPressKitJson(string $file): array
{
    $path = database_path("data/press-kit/{$file}");
    expect(is_file($path))->toBeTrue("Fichier manquant : {$path}");

    return json_decode((string) file_get_contents($path), true);
}

it('parse 193 émissions TV bien formées', function () {
    $emissions = loadPressKitJson('emissions.json');

    expect($emissions)->toHaveCount(193);

    foreach ($emissions as $em) {
        expect($em)->toHaveKeys(['chaine', 'chaine_email', 'emission', 'creneau', 'presentateur', 'angle', 'email']);
        expect(trim((string) $em['chaine']))->not->toBe('');
        expect(trim((string) $em['emission']))->not->toBe('');
    }

    // Plusieurs chaînes distinctes rattachées (BFM Business, TF1, France 2, …).
    $chaines = array_unique(array_column($emissions, 'chaine'));
    expect(count($chaines))->toBeGreaterThanOrEqual(15);
});

it('parse les médias de diffusion national + départementaux', function () {
    $medias = loadPressKitJson('medias.json');

    expect(count($medias))->toBeGreaterThan(400);

    $withEmail = array_filter($medias, fn ($m) => ! empty($m['email']));
    expect(count($withEmail))->toBeGreaterThan(400);

    $national = array_filter($medias, fn ($m) => $m['department_code'] === null);
    $depart = array_filter($medias, fn ($m) => $m['department_code'] !== null);
    expect(count($national))->toBeGreaterThan(0);
    expect(count($depart))->toBeGreaterThan(0);

    foreach ($medias as $m) {
        expect($m)->toHaveKeys(['name', 'media_type_hint', 'territoire', 'department_code', 'email']);
    }
});

it('rejette un email bidon et garde un email rédaction (role)', function () {
    $v = new MxEmailValidator;

    // Bidon → rejeté (syntaxe invalide, aucun réseau requis).
    expect($v->quickStatus('pas-un-email'))->toBe('invalid');
    expect($v->quickStatus('foo@yopmail.com'))->toBe('disposable');

    // Emails rédaction génériques → « role », gardés par la doctrine presse
    // (press@ / contact@ sont des préfixes role court-circuités avant le MX).
    expect($v->quickStatus('press@ledauphine.example'))->toBe('role');
    expect($v->quickStatus('contact@bfmtv.example'))->toBe('role');
});
