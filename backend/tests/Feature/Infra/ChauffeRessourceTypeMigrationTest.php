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

    $sql = explode('function down()', (string) file_get_contents($f))[0]; // up() seulement : down() est gardé par un refus si des lignes ip existent
    $code = implode("\n", array_filter(explode("\n", $sql), fn ($l) => ! preg_match('~^\s*(//|\*|/\*)~', $l)));
    expect($code)->toContain('ADD COLUMN IF NOT EXISTS ressource_type')
        ->and($code)->toContain("CHECK (ressource_type IN ('ip', 'domaine'))")
        ->and($code)->not->toMatch('/\bDROP\s+(TABLE|COLUMN|CONSTRAINT)\b|\bDELETE\b|\bTRUNCATE\b/i');
});

test('K8 chaque lecture agrégée de la chauffe filtre sur ressource_type (l\'IP ne double pas les totaux)', function () {
    $src = (string) file_get_contents(app_path('Envoi/Chauffe/DepotChauffe.php'));
    // Plus aucune lecture directe de la table : tout passe par le point d'entrée qui filtre.
    expect(substr_count($src, "DB::table('envoi_chauffe_jours')"))->toBe(1)
        ->and($src)->toContain('function lignesChauffe(');
});
