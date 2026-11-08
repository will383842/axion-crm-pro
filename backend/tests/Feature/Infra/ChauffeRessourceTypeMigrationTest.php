<?php

use Tests\TestCase;

uses(TestCase::class);

/**
 * K8 — la colonne `ressource_type` de `envoi_chauffe_jours` arrive par une migration en AJOUT SEUL,
 * postérieure à toutes les autres ; et les compteurs d'IP n'entrent jamais dans les totaux de la chauffe par domaine.
 */
function migrationK8(): string
{
    $f = glob(database_path('migrations/*_envoi_chauffe_ressource_type.php')) ?: [];
    expect($f)->toHaveCount(1);

    return $f[0];
}

test('K8 la migration ressource_type est postérieure aux existantes et ajoute la colonne sans rien supprimer', function () {
    $f = migrationK8();
    // Postérieure à toutes les migrations qui existaient quand K8 est née (d'autres lots ont pu s'ajouter depuis : pas « la dernière »).
    foreach (glob(database_path('migrations/*.php')) ?: [] as $autre) {
        if (str_starts_with(basename($autre), '2026_11_12_') || basename($autre) < '2026_11_12_') {
            expect(basename($autre) < basename($f))->toBeTrue();
        }
    }
    expect(basename($f))->toBeGreaterThan('2026_11_12_000130_envoi_seuils_valeur_finie.php');

    $sql = explode('function down()', (string) file_get_contents($f))[0]; // up() seulement : down() est inerte (ajout seul), il ne retire rien
    $code = implode("\n", array_filter(explode("\n", $sql), fn ($l) => ! preg_match('~^\s*(//|\*|/\*)~', $l)));
    expect($code)->toContain('ADD COLUMN IF NOT EXISTS ressource_type')
        ->and($code)->toContain("CHECK (ressource_type IN ('ip', 'domaine'))")
        ->and($code)->not->toMatch('/\bDROP\s+(TABLE|COLUMN|CONSTRAINT)\b|\bDELETE\b|\bTRUNCATE\b/i');
});

test('K8 chaque lecture de la chauffe filtre sur ressource_type (l\'IP ne double pas les totaux), alias compris', function () {
    $src = (string) file_get_contents(app_path('Envoi/Chauffe/DepotChauffe.php'));
    // Toute lecture « table('envoi_chauffe_jours…') », avec ou sans alias (« as c »), via DB:: ou une connexion.
    preg_match_all("~table\\(\\s*['\"]envoi_chauffe_jours[^'\"]*['\"]~", $src, $m, PREG_OFFSET_CAPTURE);
    expect($m[0])->toHaveCount(2); // le point d'entrée lignesChauffe() + la jointure envoyesDomaine()
    foreach ($m[0] as [, $pos]) {
        expect(substr($src, $pos, 600))->toContain('ressource_type');
    }
    expect($src)->toContain('function lignesChauffe(string $ressourceType)')   // paramètre obligatoire
        ->and(preg_match('~FROM\\s+envoi_chauffe_jours~i', $src))->toBe(0);   // pas de SQL brut qui contournerait le filtre
    // Aucun autre fichier de l'appli ne lit la table.
    foreach (glob(app_path('Envoi/**/*.php'), GLOB_BRACE) ?: [] as $f) {
        if (! str_ends_with($f, 'Chauffe/DepotChauffe.php') && ! str_ends_with($f, 'LecteurReports.php')) {
            expect((string) file_get_contents($f))->not->toMatch("~table\\(\\s*['\"]envoi_chauffe_jours~");
        }
    }
});

test('K8 la migration du garde est en ajout seul, fige ressource_type et porte un horodatage unique', function () {
    $f = glob(database_path('migrations/*_envoi_chauffe_garde_ressource_type.php')) ?: [];
    expect($f)->toHaveCount(1);
    $src = (string) file_get_contents($f[0]);
    expect($src)->toContain('CREATE OR REPLACE FUNCTION envoi_chauffe_jours_garde()')
        ->and($src)->toContain('NEW.ressource_type IS DISTINCT FROM OLD.ressource_type')
        ->and(explode('function down()', $src)[0])->not->toMatch('/\bDROP\s+(TABLE|COLUMN|CONSTRAINT|INDEX)\b|\bDELETE\b|\bTRUNCATE\b/i');
    // Horodatage (jour + numéro) jamais partagé avec une autre migration.
    $prefixes = array_map(fn ($p) => substr(basename($p), 0, 21), glob(database_path('migrations/*.php')) ?: []);
    foreach ([basename($f[0]), basename(migrationK8())] as $nom) {
        expect(array_count_values($prefixes)[substr($nom, 0, 21)])->toBe(1);
    }
});
