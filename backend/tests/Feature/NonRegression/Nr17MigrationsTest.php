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

/**
 * Filet modifié : pourquoi — envoi P0 L0 (PR #433).
 * Migrations NOUVELLES dont le down() est INERTE PAR CONCEPTION : elles posent une
 * protection « ajout seul » (triggers, REVOKE) sur le journal d’envoi et ne créent que
 * des ajouts (colonne, index, table de compteur). Un vrai retour devrait retirer la
 * protection ou effacer des lignes du journal, ce que REGLES-SOCLE S8 interdit.
 * La liste est EXPLICITE et FERMÉE : toute autre migration nouvelle garde l’exigence
 * d’un retour réel + aller-retour-aller. Pour ces fichiers on exige en plus que
 * down() soit vraiment vide (aucun DROP/DELETE/TRUNCATE ne s’y glisse) et que up()
 * soit rejouable deux fois (idempotent) à la place de l’aller-retour-aller.
 */
const NR17_RETOUR_INERTE_VOULU = [
    '2026_11_10_000011_envoi_journal_commandes.php',
    '2026_11_10_000013_envoi_garde_drapeaux_compteurs.php',
    '2026_11_11_000100_envoi_serveurs.php',
    '2026_11_11_000104_envoi_journal_commande_id.php',
    // K12 : table d'historique des contrôles DNS (ajout seul) ; down() inerte (simple commentaire).
    '2026_11_11_000110_envoi_controles_dns.php',
    // Envoi K15 : relevés de santé et constats, ajout seul (trigger), down() inerte.
    '2026_11_13_000150_envoi_sante.php',
    '2026_11_10_000060_envoi_evenements.php',
    '2026_11_10_000061_envoi_curseurs_imap.php',
    '2026_11_10_000062_envoi_rattachements.php',
    // Filet modifié : pourquoi — envoi R12 : texte chiffré des réponses et classements, ajout seul (trigger, REVOKE), down() inerte par conception (S8).
    '2026_12_02_000120_envoi_reponses.php',
    '2026_12_02_000121_envoi_reponses_classements.php',
    '2026_11_10_000063_envoi_echecs_releve.php',
    '2026_11_10_000064_envoi_messages_envoyes.php',
    // Envoi K13 : contrôles de listes noires, ajout seul (trigger), down() inerte.
    '2026_11_11_000120_envoi_controles_listes.php',
    // Filet modifié : pourquoi — K8 : colonne `ressource_type` ajoutée (ajout seul) ; la retirer effacerait la distinction ip/domaine
    // des compteurs. Le down() de 000070 (table recréée à vide) rend en outre un retour réel non rejouable dans ce test.
    '2026_11_13_000145_envoi_chauffe_ressource_type.php',
    // Filet modifié : pourquoi — K8 durcissement : le garde fige aussi ressource_type + CHECK type/clé ; down() inerte (relâcher le garde réouvrirait la falsification).
    '2026_11_14_000150_envoi_chauffe_garde_ressource_type.php',
    // Envoi K18 : versions de configuration (empreinte + statut), ajout seul (trigger), down() inerte.
    '2026_11_30_000100_envoi_config_versions.php',
    // Envoi K1 (suite K2) : trois colonnes NULLABLES ajoutées à envoi_serveurs (époque de la passerelle, blocage du tirage) ;
    // les retirer effacerait la mémoire de désynchronisation : down() inerte, up() rejouable (IF NOT EXISTS).
    '2026_11_30_000310_envoi_serveurs_epoque.php',
    // Envoi P0 L5 (suivi) : état de relance des effets d'opposition, en base (ajout seul) ; down() inerte (corps vide).
    '2026_11_30_000200_envoi_relances_effets.php',
    // Filet modifié : pourquoi — envoi L8-F04 : preuves du feu vert, ajout seul (trigger UPDATE/DELETE/TRUNCATE) ; down() inerte, up() rejouable.
    '2026_12_01_000020_envoi_feu_vert_preuves.php',
    // Envoi L8-F03 : colonne NULLABLE `fournisseur` ajoutée à envoi_temoins (ajout seul) ; la retirer effacerait le fournisseur des témoins ; down() inerte.
    '2026_12_01_000010_envoi_temoins_fournisseur.php',
    // Envoi L8-F15 : journées témoins, AJOUT SEUL (déclencheur UPDATE/DELETE/TRUNCATE, droits SELECT+INSERT) ; la retirer effacerait l'historique des 3 jours ; down() inerte, up() rejouable.
    '2026_12_01_000030_envoi_temoins_journees.php',
    // Envoi L8-F07a : table des reculs de chauffe, AJOUT SEUL (déclencheur UPDATE/DELETE/TRUNCATE, droits SELECT+INSERT) ; la retirer effacerait l'historique des reculs ; down() inerte, up() rejouable (IF NOT EXISTS).
    '2026_12_01_000040_envoi_chauffe_reculs.php',
];

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
    return array_values(array_filter(
        nr17Migrations(),
        fn (string $f) => nr17ClasseRetour($f) !== 'reel' && ! in_array($f, NR17_RETOUR_INERTE_VOULU, true),
    ));
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
        if (in_array($fichier, NR17_RETOUR_INERTE_VOULU, true)) {
            // Retour inerte voulu : down() vide (rien n’est retiré ni effacé), up() rejouable.
            expect(nr17ClasseRetour($fichier))->toBe('vide');
            $migration = require database_path('migrations/' . $fichier);
            $migration->down();
            $migration->up();
            $migration->up();

            continue;
        }
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

test('NR-17 la liste des retours inertes voulus est fermée : fichiers existants, nouveaux, jamais réels', function () {
    foreach (NR17_RETOUR_INERTE_VOULU as $fichier) {
        expect(nr17Migrations())->toContain($fichier)
            ->and(strcmp(substr($fichier, 0, 17), NR17_DERNIERE_ANCIENNE))->toBeGreaterThan(0)
            ->and(nr17ClasseRetour($fichier))->toBe('vide');
    }
});

test('NR-17 le classement voit bien des retours vides, refusés et réels (témoin de couverture)', function () {
    $classes = array_count_values(array_map('nr17ClasseRetour', nr17Migrations()));

    expect($classes['reel'] ?? 0)->toBeGreaterThan(50)
        ->and(($classes['vide'] ?? 0) + ($classes['refuse'] ?? 0))->toBeGreaterThan(0);
});
