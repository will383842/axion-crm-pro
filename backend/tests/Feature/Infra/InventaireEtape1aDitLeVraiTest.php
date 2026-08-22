<?php

/**
 * GARDE : L'INVENTAIRE DE L'ETAPE 1a NE DECLARE PAS ABSENT CE QUI EXISTE
 * — constat A09-003 (S2).
 *
 * CE QUI ETAIT ECRIT, ET QUI ETAIT FAUX LE JOUR MEME.
 *
 * `_REPORTS/2026-08-19_INVENTAIRE-ETAPE-1A.md`, section 2 « Ce qui N'EXISTE
 * PAS DU TOUT — a construire », portait deux lignes :
 *
 *     | Activites (§2.3) — les cinq activites d'AXION IA | ❌ aucune table,
 *       aucune colonne, aucune constante |
 *     | Motifs d'echange (§2.3) — les 11 motifs non commerciaux | ❌ idem |
 *
 * Les deux etaient fausses AU MOMENT MEME OU ELLES ONT ETE ECRITES : la
 * migration `2026_08_19_000002_crm_activites_et_motifs.php` (commit 504737f du
 * 2026-08-19) cree `crm_activites` et `crm_motifs` et les SEME elle-meme, et
 * `App\Crm\ActivitesEtMotifs` porte les cinq activites et les onze motifs. Le
 * dernier commit touchant l'inventaire, a832d88, date du meme 2026-08-19.
 *
 * POURQUOI CELA MERITE UNE GARDE, ALORS QUE C'EST « DU DOCUMENT ».
 *
 * Parce que ce document-la est une ENTREE DE DECISION. Le §28.5 impose de le
 * lire avant d'ecrire une ligne, pour savoir ce qu'on etend et ce qu'on cree.
 * Qui le suit reconstruit une taxonomie qui a deja sa migration, son semis et
 * sa garde — et se retrouve avec deux sources de verite sur les memes onze
 * motifs, c'est-a-dire exactement la faute que `Taxonomy` a ete concue pour
 * empecher.
 *
 * CE QUE CETTE GARDE MESURE, ET CE QU'ELLE NE MESURE PAS.
 *
 * Elle n'interroge AUCUNE base : `migrate:fresh` est partage par toute la
 * campagne et un test qui compterait des lignes en base serait au mieux
 * fragile, au pire destructeur. Elle mesure ce qui est verifiable dans le
 * depot, et qui EST le constat :
 *
 *   1. l'etat REEL du code — migration presente et creant les deux tables,
 *      constantes presentes avec le bon compte (5 et 11) ;
 *   2. que le document ne declare plus ces deux exigences absentes ;
 *   3. que la rectification est DATEE et cite ce qu'elle a mesure ;
 *   4. que la formulation d'origine du 19/08 n'a pas ete effacee au passage —
 *      rectifier n'est pas reecrire l'histoire.
 *
 * Le point 1 n'est pas decoratif : sans lui, la garde certifierait qu'un
 * document dit vrai sans jamais regarder ce dont il parle. Le jour ou la
 * migration serait retiree, c'est le document rectifie qui mentirait, et c'est
 * ce test-la qui doit rougir.
 */

use App\Crm\ActivitesEtMotifs;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

uses(TestCase::class);

function racineDepotA09003(): string
{
    return realpath(base_path('..')) ?: base_path('..');
}

const INVENTAIRE_A09003 = '_REPORTS/2026-08-19_INVENTAIRE-ETAPE-1A.md';
/**
 * ⚠️ CHEMIN RELATIF A `base_path()`, PAS A LA RACINE DU DEPOT.
 *
 * Ecrit d'abord `backend/database/migrations/...` depuis la racine. Sur le banc
 * local, `backend/` est MONTE a `/var/www/html` : `racine/backend` n'existe pas,
 * et la garde rougissait sur un fichier pourtant present. En CI l'arbre est
 * complet et les deux formes marchent — le defaut ne se voyait donc QUE sur le
 * banc, c'est-a-dire la ou on le regarde le plus souvent.
 *
 * `base_path()` designe le meme repertoire dans les deux mondes.
 */
const MIGRATION_A09003 = 'database/migrations/2026_08_19_000002_crm_activites_et_motifs.php';

function lireFichierA09003(string $relatif): string
{
    // Les documents (`_REPORTS/...`) vivent a la racine du depot ; le code
    // (`database/...`) vit sous `base_path()`. Sur le banc, les deux ne sont
    // PAS dans le meme arbre : `backend/` y est monte a part.
    $chemin = str_starts_with($relatif, 'database/') || str_starts_with($relatif, 'app/')
        ? base_path($relatif)
        : racineDepotA09003() . '/' . $relatif;

    Assert::assertFileExists(
        $chemin,
        "{$relatif} est introuvable. En local, la copie du depot dans le conteneur de banc se "
        . 'fait effacer par les autres agents : re-copier avant de croire un resultat.',
    );

    $contenu = (string) file_get_contents($chemin);

    Assert::assertNotSame('', trim($contenu), "{$relatif} est vide : la garde ne mesurerait rien.");

    return $contenu;
}

/**
 * Le document declare-t-il encore ces deux exigences comme inexistantes ?
 *
 * On cherche les DEUX formulations d'origine dans leur role d'ETAT (« ❌ … »),
 * et non le simple fait que la phrase apparaisse quelque part : la
 * rectification, elle, cite volontairement ces mots pour dire ce qui etait
 * ecrit. Un detecteur qui ne ferait pas cette difference rougirait sur le
 * document CORRIGE — un faux rouge qui pousserait a effacer la trace.
 */
function declareAbsentA09003(string $contenu, string $exigence): bool
{
    foreach (explode("\n", $contenu) as $ligne) {
        // La rectification est un bloc de citation Markdown : ses lignes
        // commencent par « > ». On ne lit que le tableau lui-meme.
        if (str_starts_with(ltrim($ligne), '>')) {
            continue;
        }
        if (! str_contains($ligne, $exigence)) {
            continue;
        }
        if (str_contains($ligne, '❌')) {
            return true;
        }
    }

    return false;
}

test('A09-003 — TEMOIN : le detecteur retrouve le defaut dans le texte d avant, et pas dans celui d apres', function (): void {
    // Sans ce temoin, `declareAbsentA09003()` pourrait rendre faux sur
    // n'importe quoi et les tests suivants passeraient au vert sans rien
    // inspecter. C'est le pire des verts, et ce depot l'a deja paye.
    $avant = "| **Activités (§2.3)** — les cinq activités d'AXION IA | ❌ aucune table, aucune colonne, aucune constante |\n"
        . "| **Motifs d'échange (§2.3)** — les 11 motifs non commerciaux | ❌ idem |\n";

    expect(declareAbsentA09003($avant, 'Activités (§2.3)'))
        ->toBeTrue('Le detecteur doit RETROUVER la ligne fausse du 19/08.');
    expect(declareAbsentA09003($avant, "Motifs d'échange (§2.3)"))
        ->toBeTrue('Le detecteur doit RETROUVER la seconde ligne fausse du 19/08.');

    // Et il ne doit PAS rougir parce que la rectification cite ce texte pour
    // dire ce qui etait ecrit : la citation vit dans un bloc « > ».
    $apres = "| **Activités (§2.3)** — les cinq activités d'AXION IA | ✅ construit |\n"
        . "> | Activités (§2.3) | ❌ aucune table, aucune colonne, aucune constante — ecrit le 19/08 |\n";

    expect(declareAbsentA09003($apres, 'Activités (§2.3)'))
        ->toBeFalse('Citer l ancienne formulation DANS la rectification ne doit pas faire rougir.');
});

test('A09-003 — l etat reel : les deux tables et les deux constantes existent bien', function (): void {
    // Le fondement de tout le reste. Si ce cas tombe, ce n'est pas le document
    // qui ment : c'est le code qui a recule, et la rectification devient a son
    // tour un mensonge qu'il faut retirer.
    $migration = lireFichierA09003(MIGRATION_A09003);

    foreach (['CREATE TABLE IF NOT EXISTS crm_activites', 'CREATE TABLE IF NOT EXISTS crm_motifs'] as $creation) {
        $this->assertTrue(
            str_contains($migration, $creation),
            '🔴 ' . MIGRATION_A09003 . " ne cree plus la table attendue (« {$creation} »).\n\n"
            . 'L inventaire de l etape 1a a ete RECTIFIE le 2026-08-22 sur la foi de cette '
            . "migration (constat A09-003) : sans elle, la rectification est fausse a son tour.\n"
            . 'GESTE : retablir la migration, OU retirer la rectification du document et y '
            . 'reinscrire l etat mesure du jour.',
        );
    }

    $this->assertCount(
        5,
        ActivitesEtMotifs::ACTIVITES,
        "🔴 App\\Crm\\ActivitesEtMotifs::ACTIVITES ne porte plus CINQ activites (§2.3).\n"
        . "L inventaire rectifie annonce « 5 entrées ». Mesure du 2026-08-22 : 5.\n"
        . 'GESTE : si le catalogue change pour de bon, corriger la migration, la constante ET '
        . 'le compte inscrit dans ' . INVENTAIRE_A09003 . ' — jamais l un sans les autres.',
    );

    $this->assertCount(
        11,
        ActivitesEtMotifs::MOTIFS,
        "🔴 App\\Crm\\ActivitesEtMotifs::MOTIFS ne porte plus ONZE motifs (§2.3).\n"
        . "L inventaire rectifie annonce « 11 entrées ». Mesure du 2026-08-22 : 11.\n"
        . 'GESTE : corriger la migration, la constante ET le compte inscrit dans '
        . INVENTAIRE_A09003 . '.',
    );
});

test('A09-003 — l inventaire ne declare plus absentes les activites et les motifs', function (): void {
    $contenu = lireFichierA09003(INVENTAIRE_A09003);

    foreach (['Activités (§2.3)', "Motifs d'échange (§2.3)"] as $exigence) {
        $this->assertFalse(
            declareAbsentA09003($contenu, $exigence),
            '🔴 ' . INVENTAIRE_A09003 . " range encore « {$exigence} » parmi ce qui « N EXISTE PAS "
            . "DU TOUT ».\n\n"
            . 'Mesure du 2026-08-22 : la table est creee ET semee par '
            . MIGRATION_A09003 . ", la constante vit dans App\\Crm\\ActivitesEtMotifs.\n"
            . 'Ce document est lu AVANT d ecrire une ligne (§28.5) : qui le suit reconstruit une '
            . 'taxonomie qui existe, et se retrouve avec deux sources de verite sur les memes '
            . "onze motifs.\n\n"
            . 'GESTE : remplacer l etat « ❌ » de cette ligne par l etat mesure, et laisser la '
            . 'formulation du 19/08 visible dans l encart de rectification — on empile une mesure '
            . 'datee sur une autre, on n en remplace jamais une.',
        );
    }
});

test('A09-003 — la rectification est datee et cite ce qu elle a mesure', function (): void {
    $contenu = lireFichierA09003(INVENTAIRE_A09003);

    // Une note qui dirait « c est corrige » sans nommer la table, la constante
    // ni la migration serait une seconde affirmation non mesuree — le defaut
    // d origine repose a l envers.
    foreach ([
        'RECTIFICATION 2026-08-22',
        'crm_activites',
        'crm_motifs',
        'ActivitesEtMotifs',
        '2026_08_19_000002',
    ] as $exigence) {
        $this->assertTrue(
            str_contains($contenu, $exigence),
            '🔴 La rectification de ' . INVENTAIRE_A09003 . " ne cite pas « {$exigence} ».\n\n"
            . 'Le §28.3 interdit d affirmer sans mesure : « c est corrige » n est pas une mesure. '
            . 'La rectification doit nommer la table, la constante et la migration, pour que le '
            . "lecteur suivant puisse la VERIFIER au lieu de la croire.\n"
            . 'GESTE : completer l encart « RECTIFICATION 2026-08-22 » avec la reference manquante.',
        );
    }
});

test('A09-003 — la rectification n a pas efface la formulation d origine', function (): void {
    $contenu = lireFichierA09003(INVENTAIRE_A09003);

    // La ligne du 19/08 est la PIECE du constat : elle dit ce qui a ete cru, et
    // donc pourquoi l inventaire doit desormais se relire a la fin du lot qu il
    // ouvre. L effacer rendrait la rectification incomprehensible — on lirait
    // une correction sans savoir ce qui est corrige.
    $this->assertTrue(
        str_contains($contenu, 'aucune table, aucune colonne, aucune constante'),
        '🔴 ' . INVENTAIRE_A09003 . " ne porte plus la formulation fausse du 2026-08-19.\n\n"
        . "Rectifier n est pas reecrire : la phrase d origine reste, datee, sous la rectification.\n"
        . 'GESTE : restaurer la citation « ❌ aucune table, aucune colonne, aucune constante » '
        . 'dans l encart de rectification, en la datant du 19/08.',
    );
});
