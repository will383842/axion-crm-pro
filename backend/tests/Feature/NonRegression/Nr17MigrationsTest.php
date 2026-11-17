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
    // Filet modifié : pourquoi — envoi R17 : constats de réputation, ajout seul (trigger, REVOKE), down() inerte par conception (conservation indéfinie).
    '2026_12_02_000170_envoi_reputation_constats.php',
    // Filet modifié : pourquoi — envoi RR-0 : élargit deux listes fermées (canal `releve`, geste `annulation`), contraintes NOT VALID, ajout seul ; down() inerte par conception (un retour arrière reposerait l'ancienne liste et échouerait dès qu'une ligne `releve` ou `annulation` existe).
    '2026_12_11_000100_envoi_retours_reactions.php',
    // Filet modifié : pourquoi — G3-0 : double authentification par courriel, ajout seul ; down() inerte (un retour arrière désactiverait la double authentification par courriel et effacerait des données de sécurité).
    '2026_12_08_000200_double_auth_courriel.php',
    '2026_11_10_000060_envoi_evenements.php',
    '2026_11_10_000061_envoi_curseurs_imap.php',
    '2026_11_10_000062_envoi_rattachements.php',
    // Filet modifié : pourquoi — envoi R12 : texte chiffré des réponses et classements, ajout seul (trigger, REVOKE), down() inerte par conception (S8).
    '2026_12_02_000120_envoi_reponses.php',
    '2026_12_02_000121_envoi_reponses_classements.php',
    // Filet modifié : pourquoi — envoi R12 : canal d'opposition `reponse` (CHECK élargi NOT VALID, aucune donnée touchée) ; la retirer fausserait le registre ; down() inerte, up() rejouable.
    '2026_12_02_000122_envoi_oppositions_canal_reponse.php',
    // Filet modifié : pourquoi — envoi D1-L1 : accès IMAP chiffrés des boîtes, AJOUT SEUL (déclencheur UPDATE/DELETE/TRUNCATE, droits SELECT+INSERT) ; les retirer effacerait les accès posés ; down() inerte, up() rejouable (IF NOT EXISTS).
    '2026_12_05_000400_envoi_boites_acces_imap.php',
    // Filet modifié : pourquoi — VERDICTS : colonne motif nullable + CHECK NOT VALID ; la retirer effacerait le pourquoi des verdicts ; down() inerte, up() rejouable.
    '2026_12_05_000500_envoi_verdicts_adresse_motif.php',
    // Filet modifié : pourquoi — VERDICTS : REVOKE UPDATE/DELETE du rôle applicatif sur les verdicts (ajout seul) ; rendre ces droits rouvrirait la réécriture des verdicts ; down() inerte, up() rejouable.
    '2026_12_05_000510_envoi_verdicts_adresse_revoke_ud.php',
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
    // Envoi R14 : règles d'alerte et alertes réglées, AJOUT SEUL (déclencheur, droits SELECT+INSERT) ; les retirer effacerait l'historique ; down() inerte, up() rejouable (IF NOT EXISTS).
    '2026_12_02_000140_envoi_regles_alerte.php',
    '2026_12_02_000141_envoi_alertes_reglees.php',
    '2026_12_02_000142_envoi_identite_versions.php',
    '2026_12_02_000143_envoi_reglages.php',
    // Envoi R15 : rappels DMARC (ajout seul : déclencheur, REVOKE) et CHECK de statut des versions élargi à « prete » (aucune ligne touchée) ; down() inerte, up() rejouable.
    '2026_12_02_000150_envoi_domaines_rappels_dmarc.php',
    // Filet modifié : pourquoi — Envoi L8-F14a : colonne `statut` (défaut `acceptee`, sans UPDATE), index unique des conclusions et fonction
    // de libération (ajouts seuls) ; les retirer effacerait l'état des réservations d'injection ; down() inerte, up() rejouable.
    '2026_12_02_000180_envoi_injection_reservation_statut.php',
    // Envoi R06 : tentatives d'envoi par fournisseur, AJOUT SEUL (déclencheur UPDATE/DELETE/TRUNCATE, droits SELECT+INSERT) ; la retirer effacerait la source du frein « reports » ; down() inerte, up() rejouable.
    '2026_12_02_000060_envoi_tentatives.php',
    // Envoi R08 : modèle de données campagne (campagnes, journal, codes), AJOUT SEUL ou colonnes figées par déclencheur ; les retirer effacerait l'historique ; down() inerte, up() rejouable (IF NOT EXISTS).
    '2026_12_02_000080_envoi_campagnes.php',
    '2026_12_02_000081_envoi_campagnes_journal.php',
    '2026_12_02_000083_envoi_campagne_codes.php',
    // Envoi R08b : modèles de message versionnés, AJOUT SEUL (versions) et archivage par `archive_le` (jamais de suppression) ; down() inerte, up() rejouable (IF NOT EXISTS).
    '2026_12_02_000082_envoi_modeles.php',
    // Filet modifié : pourquoi — envoi D1 : fonction IMMUTABLE + index d'expression sur l'empreinte des contacts (ajout seul, IF NOT EXISTS) ; down() inerte (retirer l'index ne se fait pas dans ce plan), up() rejouable.
    '2026_12_02_000200_contacts_index_empreinte_email.php',
    // Envoi R13 : registre d'opposition manuel, AJOUT SEUL (déclencheur UPDATE/DELETE/TRUNCATE, droits SELECT+INSERT) ; le retirer effacerait des oppositions ; down() inerte, up() rejouable (IF NOT EXISTS).
    '2026_12_02_000130_envoi_oppositions_registre.php',
    // Filet modifié : pourquoi — envoi R07 : colonne `envoi_domaines.flux` (ADD COLUMN IF NOT EXISTS, NULL) + CHECK NOT VALID ; la retirer effacerait l'étiquetage des domaines ; down() inerte, up() rejouable.
    '2026_12_02_000070_envoi_domaines_flux.php',
    // Envoi R09 : colonne `envoi_prevu_le` (ADD COLUMN IF NOT EXISTS, NULL) ; la retirer effacerait les dates prévues ; down() inerte.
    '2026_12_02_000090_envoi_campagnes_date.php',
    // Envoi R16 : accès IMAP des témoins (hôte en liste fermée par CHECK, identifiant chiffré), AJOUT SEUL (déclencheur UPDATE/DELETE/TRUNCATE) ; la retirer effacerait les accès posés ; down() inerte, up() rejouable.
    '2026_12_02_000160_envoi_temoins_acces.php',
    // Envoi R16 : index UNIQUE PARTIEL (auteur releve_auto) sur les journées témoins, ajout d'index seul ; down() inerte, up() rejouable.
    '2026_12_02_000161_envoi_temoins_journees_releve_auto.php',
    // Filet modifié : pourquoi — envoi S1-A : butée de plafond par espace (table + fonction, protégées par les droits), `envoi_injection_liberer` remplacée ; les retirer rouvrirait le dépassement du plafond ; down() inerte, up() rejouable.
    '2026_12_03_000010_envoi_compteur_espace.php',
    // Filet modifié : pourquoi — envoi S1-A : conservation des tentatives (agrégat quotidien en ajout seul, fonction d'archivage, déclencheur d'ajout seul remplacé) ; les retirer effacerait l'historique agrégé ou rouvrirait le DELETE ; down() inerte, up() rejouable.
    '2026_12_03_000060_envoi_tentatives_retention.php',
    // Filet modifié : pourquoi — envoi S1-A : gestes de pause et de reprise des séquences, AJOUT SEUL (déclencheur UPDATE/DELETE/TRUNCATE, droits SELECT+INSERT) ; les retirer effacerait l'historique ; down() inerte, up() rejouable.
    '2026_12_03_000070_envoi_sequences_gestes.php',
    // Filet modifié : pourquoi — envoi S1-B : essais à blanc, AJOUT SEUL (déclencheur UPDATE/DELETE/TRUNCATE, droits SELECT+INSERT) ; les retirer effacerait l'historique ; down() inerte, up() rejouable.
    '2026_12_03_000030_envoi_essais_blanc.php',
    // Filet modifié : pourquoi — envoi S1-B : colonne `adaptateur`, déclencheur de garde sur `envoi_domaines` et fonctions journalisée/lecture ; les retirer rouvrirait le changement direct d'adaptateur (le serveur réel) ; down() inerte, up() rejouable.
    '2026_12_03_000040_envoi_domaines_adaptateur.php',
    // Filet modifié : pourquoi — envoi S1-B : décisions de feu vert, AJOUT SEUL ; les retirer effacerait la trace de qui a accordé l'envoi ; down() inerte, up() rejouable.
    '2026_12_03_000050_envoi_feu_vert_decisions.php',
    // Filet modifié : pourquoi — envoi S1-B : injections locales (AJOUT SEUL) et déclencheur `envoi_verifie_id_injection` élargi ; les retirer ferait refuser le registre des messages envoyés ; down() inerte, up() rejouable.
    '2026_12_03_000080_envoi_injections_locales.php',
    // Filet modifié : pourquoi — envoi S1-C : imports de listes de refus (AJOUT SEUL) et colonne `import_id` du registre ; les retirer effacerait l'historique des imports ou la trace de l'origine d'un refus ; down() inerte, up() rejouable.
    '2026_12_03_000020_envoi_oppositions_imports.php',
    // Filet modifié : pourquoi — envoi X1-L1 : colonne `envoi_sequences.campagne_id`, clé étrangère, index unique partiel et gardes (déclencheurs) du lien campagne -> séquence ; les retirer rouvrirait des séquences sans campagne et des campagnes reliées à n'importe quoi ; down() inerte, up() rejouable.
    '2026_12_04_000010_envoi_sequences_campagne.php',
    // Filet modifié : pourquoi — envoi X1-L1 : état de matérialisation des campagnes (SANS suppression : déclencheur DELETE/TRUNCATE, droits par colonne) ; la retirer effacerait l'avancement des campagnes ; down() inerte, up() rejouable.
    '2026_12_04_000020_envoi_campagnes_materialisations.php',
    // Filet modifié : pourquoi — envoi X1-L1 : clé étrangère campagne -> séquence et garde `envoi_campagnes_garde()` élargie ; remettre l'ancienne garde rouvrirait la pose d'un lien sans contrôle ; down() inerte (corps vide), up() rejouable.
    '2026_12_04_000030_envoi_campagnes_lien_sequence.php',
    // Filet modifié : pourquoi — envoi X1-L1 : index partiel des inscriptions actives (CONCURRENTLY hors transaction), ajout d'index seul ; down() inerte, up() rejouable.
    '2026_12_04_000040_envoi_inscriptions_contact_actif_index.php',
    // Filet modifié : pourquoi — envoi P2-A : colonnes `population` et `personne_id`, deux CHECK, contact/entreprise nullables et déclencheur de garde des inscriptions typées (ajout seul, inerte : aucune ligne « personnes » possible) ; les retirer rouvrirait des inscriptions incohérentes ; down() inerte, up() rejouable.
    '2026_12_05_000100_envoi_inscriptions_population.php',
    // Filet modifié : pourquoi — envoi P2-A : index unique partiel par personne (CONCURRENTLY hors transaction), ajout d'index seul ; down() inerte, up() rejouable.
    '2026_12_05_000110_envoi_inscriptions_personne_index.php',
    // Filet modifié : pourquoi — envoi P2-A : garde de la clé `envoi.population_personnes` (propriétaire seul, true/false) ; la retirer laisserait l'application allumer l'interrupteur ; down() inerte, up() rejouable.
    '2026_12_05_000120_envoi_reglages_interrupteur_population.php',
    // Filet modifié : pourquoi — envoi P2-B : index unique partiel « une inscription active par adresse et par espace » (CONCURRENTLY hors transaction), ajout d'index seul ; s'arrête sans rien corriger s'il existe des doublons ; down() inerte, up() rejouable.
    '2026_12_05_000180_envoi_inscriptions_adresse_active_unique.php',
    // Filet modifié : pourquoi — envoi C1-0 : colonne `id_injection_serveur` (ADD COLUMN IF NOT EXISTS, NULL) + CHECK ; la retirer effacerait les identifiants serveur ; down() inerte, up() rejouable.
    '2026_12_05_000200_envoi_messages_id_injection_serveur.php',
    // Filet modifié : pourquoi — envoi C1-0 : deux index partiels d'`envoi_messages` (CONCURRENTLY hors transaction), ajout d'index seul ; down() inerte, up() rejouable.
    '2026_12_05_000210_envoi_messages_index_injection.php',
    // Filet modifié : pourquoi — envoi C3-0 : boîte des retraits (empreintes, états finals figés, DELETE/TRUNCATE refusés) ; la retirer effacerait des demandes de retrait ; down() inerte, up() rejouable (IF NOT EXISTS).
    '2026_12_05_000300_envoi_retraits_demandes.php',
    // Filet modifié : pourquoi — envoi C3-0 : registre Kumo durable (3 tables, ni DELETE ni TRUNCATE, seq figé, 5xx en ajout seul) ; le retirer effacerait des injections ; down() inerte, up() rejouable.
    '2026_12_05_000310_envoi_registre_kumo.php',
    // Filet modifié : pourquoi — envoi C3-0 : fonction de lecture des couples (espace, domaine, adaptateur) non « faux » pour la pause globale (CREATE OR REPLACE) ; down() inerte (un appelant futur en dépendra), up() rejouable.
    '2026_12_05_000320_envoi_couples_reels_portes.php',
    // Filet modifié : pourquoi — X1-L2-bis : colonne de filtre ajoutée, down() inerte (aucune donnée de campagne ne se perd)
    '2026_12_10_000100_envoi_campagnes_filtre_origine.php',
    // Filet modifié : pourquoi — envoi L6-0 : index partiel d'expression d'`envoi_evenements` (CONCURRENTLY hors transaction), ajout d'index seul ; down() inerte, up() rejouable.
    '2026_12_09_000100_envoi_evenements_index_injection.php',
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
