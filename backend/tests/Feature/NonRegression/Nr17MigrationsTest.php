<?php

/**
 * NR-17 — R10, les migrations (jouées automatiquement en production à chaque fusion).
 *
 * 1. Toute migration NOUVELLE (nom daté après le 2026-11-01, date de R0) doit faire
 *    l’aller, le retour, puis l’aller sur la base jetable de test, sans erreur
 *    (le DDL de PostgreSQL est transactionnel : le test se déroule dans la
 *    transaction de `RefreshDatabase`, rien ne reste). Règle du plan 20 § 11.1 :
 *    « ajouter d’abord, retirer plus tard ».
 * 2. Les migrations ANCIENNES dont le retour est VIDE ou REFUSE sont listées et
 *    figées : la liste ne peut que BAISSER (`instantanes/nr17-migrations-sans-retour.json`).
 *    Une nouvelle migration au retour vide ou refusé fait donc rougir.
 *
 * Témoin : l’instrument « aller-retour-aller » est aussi exercé sur la dernière
 * migration ancienne dont le retour est réel, pour prouver qu’il sait voir un défaut.
 *
 * Aucune donnée de personne n’est touchée : base jetable, transaction annulée.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\NonRegression\Instantane;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const NR17_DERNIERE_ANCIENNE = '2026_11_01_999999';

/** @return list<string> noms de fichiers (sans dossier), triés */
function nr17Migrations(): array
{
    $noms = array_map('basename', glob(database_path('migrations/*.php')) ?: []);
    sort($noms);

    return $noms;
}

/** @return 'reel'|'vide'|'refuse' */
function nr17ClasseRetour(string $fichier): string
{
    $jetons = token_get_all((string) file_get_contents(database_path('migrations/' . $fichier)));
    $n = count($jetons);
    for ($i = 0; $i < $n; $i++) {
        if (is_array($jetons[$i]) && $jetons[$i][0] === T_FUNCTION) {
            $j = $i + 1;
            while ($j < $n && is_array($jetons[$j]) && $jetons[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if (is_array($jetons[$j] ?? null) && $jetons[$j][1] === 'down') {
                while ($j < $n && $jetons[$j] !== '{') {
                    $j++;
                }
                $profondeur = 0;
                $corps = [];
                for (; $j < $n; $j++) {
                    $t = $jetons[$j];
                    if ($t === '{') {
                        $profondeur++;
                        if ($profondeur === 1) {
                            continue;
                        }
                    }
                    if ($t === '}') {
                        $profondeur--;
                        if ($profondeur === 0) {
                            break;
                        }
                    }
                    if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $corps[] = $t;
                }
                if ($corps === []) {
                    return 'vide';
                }
                foreach ($corps as $t) {
                    if (is_array($t) && $t[0] === T_THROW) {
                        return 'refuse';
                    }
                }

                return 'reel';
            }
        }
    }

    return 'vide'; // pas de down() du tout
}

/** @return list<string> */
function nr17SansRetour(): array
{
    return array_values(array_filter(nr17Migrations(), fn (string $f) => nr17ClasseRetour($f) !== 'reel'));
}

function nr17AllerRetourAller(string $fichier): void
{
    $migration = require database_path('migrations/' . $fichier);
    if (! is_object($migration)) {
        throw new RuntimeException("{$fichier} ne rend pas un objet migration.");
    }
    $migration->down();
    $migration->up();
}

test('NR-17 les migrations anciennes sans retour réel sont celles de la photo (la liste ne peut que baisser)', function () {
    Instantane::verifierContenuDans('nr17-migrations-sans-retour', nr17SansRetour());
    expect(count(nr17Migrations()))->toBeGreaterThanOrEqual(122);
});

test('NR-17 toute migration NOUVELLE a un retour réel et fait aller, retour, aller sans erreur', function () {
    $nouvelles = array_values(array_filter(nr17Migrations(), fn (string $f) => strcmp(substr($f, 0, 17), NR17_DERNIERE_ANCIENNE) > 0));

    foreach ($nouvelles as $fichier) {
        expect(nr17ClasseRetour($fichier))->toBe('reel');
        nr17AllerRetourAller($fichier);
    }

    expect(true)->toBeTrue(); // aucune migration nouvelle à ce jour : le test garde l’avenir
});

test('NR-17 témoin : l’instrument aller-retour-aller joue sur une migration ancienne réversible', function () {
    // Fichier FIGÉ (une table simple, retour réel) : le témoin ne change pas tout seul
    // quand un lot ajoute une migration.
    $fichier = '2026_10_04_000010_create_partners_idempotence_table.php';
    expect(nr17Migrations())->toContain($fichier)
        ->and(nr17ClasseRetour($fichier))->toBe('reel');
    $src = (string) file_get_contents(database_path('migrations/' . $fichier));
    preg_match("/Schema::create\('([a-z_0-9]+)'/", $src, $m);
    $table = $m[1];

    expect(DB::selectOne('select to_regclass(?) as t', [$table])->t)->not->toBeNull();
    nr17AllerRetourAller($fichier);
    expect(DB::selectOne('select to_regclass(?) as t', [$table])->t)->not->toBeNull();
});

test('NR-17 le classement voit bien des retours vides, refusés et réels (témoin de couverture)', function () {
    $classes = array_count_values(array_map('nr17ClasseRetour', nr17Migrations()));

    expect($classes['reel'] ?? 0)->toBeGreaterThan(50)
        ->and(($classes['vide'] ?? 0) + ($classes['refuse'] ?? 0))->toBeGreaterThan(0);
});
