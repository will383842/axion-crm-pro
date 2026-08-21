<?php

/**
 * GARDE DU PREAMBULE D'EXTENSIONS — constat F39-005 (S1).
 *
 * « Le correctif qui a rendu la sauvegarde restaurable repose sur une liste de
 *   noms ECRITS EN DUR, sans aucun controle. »
 *
 * ── CE QUE LE CONSTAT DIT JUSTE, ET CE QU'IL DIT FAUX ───────────────────────
 *
 * Le constat annonce SEPT noms. Il y en avait NEUF (`backup-postgres.sh`,
 * heredoc `EXTENSIONS_SQL`, ligne 88 a 96 avant ce lot). Le chiffre est faux, le
 * fond ne l'est pas — mais il n'est pas faux dans le sens qu'on croit, et c'est
 * la mesure qui le dit.
 *
 * ── MESURE 1 : LE PREAMBULE N'A JAMAIS ETE CE QUI RENDAIT L'ARCHIVE
 *              RESTAURABLE ────────────────────────────────────────────────────
 *
 * Le commentaire qui justifiait ce heredoc depuis le Sprint 19.4 affirmait :
 *
 *     « Le dump pg_dump n'inclut pas les CREATE EXTENSION dans plain text
 *       sans --extension flag. »
 *
 * C'est FAUX. Mesure du 2026-08-21, options exactes du script, sur une base
 * portant les extensions de l'application :
 *
 *     $ pg_dump -U axion -Fp --no-owner --clean --if-exists axion_crm_test_lot10 \
 *         | grep -E '^(CREATE|DROP) (EXTENSION|SCHEMA)'
 *     DROP EXTENSION IF EXISTS vector;
 *     …
 *     CREATE SCHEMA partman;
 *     CREATE EXTENSION IF NOT EXISTS btree_gin WITH SCHEMA public;
 *     …
 *     CREATE EXTENSION IF NOT EXISTS pg_partman WITH SCHEMA partman;
 *
 * Et la restauration d'une archive SANS aucun preambule, recette de
 * `restore-postgres.sh`, base neuve : 0 erreur, 116 tables, LES DIX EXTENSIONS,
 * `pg_partman` compris, dans son schema `partman`.
 *
 * ── MESURE 2 : LA LISTE ETAIT INCOMPLETE — motif A-011 ──────────────────────
 *
 * Les neuf noms, joues SEULS sur une base neuve (2026-08-21) :
 *
 *     btree_gin, btree_gist, citext, pg_trgm, pgcrypto, postgis, unaccent,
 *     uuid-ossp, vector          → 9 extensions
 *     SELECT count(*) FROM pg_namespace WHERE nspname='partman'  → 0
 *
 * Il manquait `pg_partman` ET le schema `partman` qui le porte. C'est
 * exactement A08-008 dans une autre matiere : on oublie un objet, et personne
 * ne le sait avant la restauration, c'est-a-dire au pire moment.
 *
 * ── DONC : LE VRAI DEFAUT N'EST PAS LA LISTE, C'EST L'ABSENCE DE CONTROLE ───
 *
 * La liste etait a la fois FAUSSE et INERTE. Une liste inerte et fausse ne fait
 * de mal que le jour ou elle cesse d'etre inerte — par exemple si quelqu'un
 * restreint un jour le `pg_dump` (`--extension=…`, `--schema=…`). Rien, nulle
 * part, n'aurait alors dit que l'archive avait perdu une extension :
 *
 *   · `backup-postgres.sh` verifiait la TAILLE, puis (depuis A08-008) les roles
 *     et les GRANT — jamais les extensions ;
 *   · `restore-postgres.sh` comptait les TABLES, puis les droits — une base
 *     privee d'`unaccent` porte le meme nombre de tables et donne les memes
 *     droits ;
 *   · `dr-drill.sh` comparait cinq comptages de lignes — idem.
 *
 * Et ce n'est pas une hypothese : la panne du 2026-08-16 est litteralement
 * celle-la, `function unaccent(text) does not exist`, decouverte AU MILIEU d'un
 * exercice de reprise.
 *
 * ── CE QUE CETTE GARDE MESURE ───────────────────────────────────────────────
 *
 * 1. Elle LIT dans `backup-postgres.sh` la requete qui remplace la liste, entre
 *    deux marqueurs, et la REJOUE contre le catalogue de la base de test. Ce
 *    n'est pas une paraphrase de la requete : c'est la requete du script.
 * 2. Elle exige qu'elle couvre `pg_extension` EN ENTIER, schemas compris.
 * 3. Temoin negatif : le meme comparateur, nourri des NEUF NOMS d'avant, doit
 *    rendre rouge et nommer `pg_partman`.
 * 4. Temoin de couverture : nourri de ZERO ligne, il doit rendre rouge — un
 *    balayage qui ne voit rien ne doit pas passer pour un balayage satisfait.
 * 5. Les trois scripts de la chaine portent desormais un controle d'extensions.
 *
 * ⚠️ Le conteneur de mesure n'a ni `pg_dump`, ni `psql`, ni `docker` (verifie :
 * `command -v` ne rend rien pour les trois). La garde ne peut donc pas JOUER les
 * scripts. Ce qu'elle prouve en direct, c'est la REQUETE — sur le vrai
 * catalogue, par PDO. Ce qu'elle prouve par lecture, c'est que les scripts
 * portent le controle. Les deux moities sont dites, aucune n'est maquillee en
 * l'autre.
 */

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

/** La racine du depot — `infra/` vit AU-DESSUS de l'application. */
function racineDepotExtensions(): string
{
    return realpath(base_path('..')) ?: base_path('..');
}

function lireScriptExtensions(string $relatif): string
{
    return (string) file_get_contents(racineDepotExtensions() . '/' . $relatif);
}

/**
 * Les NEUF noms qui etaient ecrits a la main dans `backup-postgres.sh` jusqu'au
 * 2026-08-21. Ils sont figes ICI, et nulle part ailleurs : ils ne servent qu'a
 * une chose, prouver que le comparateur sait rendre rouge sur l'etat reel qui a
 * tenu trois mois en production.
 */
function lignesEcritesALaMainAvantF39005(): array
{
    return [
        'CREATE EXTENSION IF NOT EXISTS "pg_trgm";',
        'CREATE EXTENSION IF NOT EXISTS "unaccent";',
        'CREATE EXTENSION IF NOT EXISTS "btree_gin";',
        'CREATE EXTENSION IF NOT EXISTS "btree_gist";',
        'CREATE EXTENSION IF NOT EXISTS "pgcrypto";',
        'CREATE EXTENSION IF NOT EXISTS "citext";',
        'CREATE EXTENSION IF NOT EXISTS "uuid-ossp";',
        'CREATE EXTENSION IF NOT EXISTS "postgis";',
        'CREATE EXTENSION IF NOT EXISTS "vector";',
    ];
}

/**
 * Le nom d'extension pose par une ligne `CREATE EXTENSION`, ou null.
 *
 * `quote_ident` ne met des guillemets que si le nom l'exige (`"uuid-ossp"` oui,
 * `pg_trgm` non) : les deux formes doivent etre reconnues.
 */
function nomExtensionDeLaLigne(string $ligne): ?string
{
    if (preg_match('/^\s*CREATE EXTENSION IF NOT EXISTS\s+"?([^"\s;]+)"?/i', $ligne, $m) === 1) {
        return $m[1];
    }

    return null;
}

/** Le nom de schema pose par une ligne `CREATE SCHEMA`, ou null. */
function nomSchemaDeLaLigne(string $ligne): ?string
{
    if (preg_match('/^\s*CREATE SCHEMA IF NOT EXISTS\s+"?([^"\s;]+)"?/i', $ligne, $m) === 1) {
        return $m[1];
    }

    return null;
}

/** Le schema vise par une ligne `CREATE EXTENSION … WITH SCHEMA x`, ou null. */
function schemaVisePar(string $ligne): ?string
{
    if (preg_match('/WITH SCHEMA\s+"?([^"\s;]+)"?/i', $ligne, $m) === 1) {
        return $m[1];
    }

    return null;
}

/**
 * LE COMPARATEUR. Il repond a une seule question : quelles extensions du
 * catalogue ce preambule laisse-t-il dehors ?
 *
 * Il ne connait aucun nom d'extension. Les deux cotes lui sont donnes : c'est ce
 * qui le rend capable de voir ce qu'on a oublie, ce qu'aucune liste ecrite a la
 * main ne peut faire.
 *
 * @param  list<string>  $lignes  le preambule, ligne a ligne
 * @param  list<string>  $catalogue  les noms rendus par `pg_extension`
 * @return list<string> les noms du catalogue absents du preambule
 */
function extensionsLaisseesDehors(array $lignes, array $catalogue): array
{
    $posees = [];
    foreach ($lignes as $ligne) {
        $nom = nomExtensionDeLaLigne($ligne);
        if ($nom !== null) {
            $posees[] = $nom;
        }
    }

    return array_values(array_diff($catalogue, $posees));
}

/**
 * Et l'autre moitie : un `WITH SCHEMA x` sur un schema qui n'a pas ete cree
 * AVANT echoue — `CREATE EXTENSION … WITH SCHEMA partman` sur une base neuve
 * rend « schema "partman" does not exist ». On rend les schemas vises sans
 * `CREATE SCHEMA` prealable.
 *
 * @param  list<string>  $lignes
 * @return list<string>
 */
function schemasVisesSansCreation(array $lignes): array
{
    $crees = ['public', 'pg_catalog'];
    $manquants = [];

    foreach ($lignes as $ligne) {
        $schema = nomSchemaDeLaLigne($ligne);
        if ($schema !== null) {
            $crees[] = $schema;

            continue;
        }

        $vise = nomExtensionDeLaLigne($ligne) !== null ? schemaVisePar($ligne) : null;
        if ($vise !== null && ! in_array($vise, $crees, true)) {
            $manquants[] = $vise;
        }
    }

    return array_values(array_unique($manquants));
}

/**
 * Extrait du script la requete delimitee par les deux marqueurs, et n'en garde
 * que le SQL (le heredoc, sans l'affectation shell ni les marqueurs).
 */
function requeteExtensionsDuScript(string $contenu): ?string
{
    $motif = '/>>> AXION-EXTENSIONS-SQL-DEBUT.*?<<\'EOSQL\'\R(.*?)\REOSQL/s';

    if (preg_match($motif, $contenu, $m) !== 1) {
        return null;
    }

    $sql = trim($m[1]);

    return $sql === '' ? null : $sql;
}

/**
 * Le script PRIVE DE SES COMMENTAIRES.
 *
 * Sans cela, une garde qui cherche `pg_extension` dans un script shell est
 * satisfaite par la PHRASE qui en parle. Mesure du 2026-08-21 : en remplacant
 * dans `dr-drill.sh` le seul `FROM pg_extension …` par `FROM pg_class …` — le
 * controle detruit, donc —, `grep -c pg_extension` rendait encore 1, et la
 * premiere version de cette garde restait VERTE. C'est exactement le defaut
 * qu'elle poursuit : un controle qui ne peut pas echouer sur le defaut qu'il
 * surveille rassure au lieu de garder.
 */
// ⚠️ NOM PREFIXE, ET LA RAISON VAUT POUR TOUT CE DOSSIER : les fichiers de test
// de Pest partagent UN SEUL espace de noms de fonctions pour toute la campagne.
// Le 2026-08-21, ce fichier et
// `tests/Feature/Infra/IndexEmailRgpdServentLesRequetesTest.php` ont declare
// chacun un `codeSansCommentaires()` — l'un retire les `#` du shell, l'autre les
// commentaires PHP. La suite complete est morte sur
// « Fatal error: Cannot redeclare codeSansCommentaires() », et AUCUN des deux
// fichiers n'etait fautif seul : ils ne se rencontrent que joues ensemble.
// D'ou le nom qui dit ce qu'il fait, et sur quoi.
function shellSansDieses(string $contenu): string
{
    $lignes = [];
    foreach (explode("\n", $contenu) as $ligne) {
        if (str_starts_with(ltrim($ligne), '#')) {
            continue;
        }
        $lignes[] = $ligne;
    }

    return implode("\n", $lignes);
}

/** Les noms d'extension du catalogue de la base de test, `plpgsql` exclu. */
function catalogueDesExtensions(): array
{
    return array_map(
        static fn (object $l): string => (string) $l->extname,
        DB::select("SELECT extname FROM pg_extension WHERE extname <> 'plpgsql' ORDER BY extname"),
    );
}

// ═══════════════════════════════════════════════════════════════════════════
// TEMOINS
// ═══════════════════════════════════════════════════════════════════════════

test('F39-005 — TEMOIN : le banc voit le script ET un catalogue qui a de quoi rater quelque chose', function () {
    $chemin = racineDepotExtensions() . '/infra/scripts/backup-postgres.sh';
    expect(is_file($chemin))->toBeTrue(
        'Le banc ne voit pas `infra/scripts/backup-postgres.sh`. Toutes les gardes qui '
        . 'suivent seraient vertes sur ZERO fichier. Racine vue : ' . racineDepotExtensions(),
    );

    $catalogue = catalogueDesExtensions();

    // Vacuite : sur une base sans extension, « le preambule couvre le catalogue »
    // est vrai et ne prouve rien.
    expect(count($catalogue))->toBeGreaterThanOrEqual(
        10,
        'La base de test ne porte que ' . count($catalogue) . ' extension(s) : '
        . implode(', ', $catalogue) . ".\n"
        . "L'application en exige dix (cf. `infra/postgres/init/01-extensions.sql`). "
        . 'Les comparaisons qui suivent seraient vraies par vacuite.',
    );

    // Et il faut au moins une extension HORS `public`, sinon la moitie « schema »
    // de la garde ne mesurerait rien du tout.
    $horsPublic = DB::select("
        SELECT e.extname
        FROM pg_extension e
        JOIN pg_namespace n ON n.oid = e.extnamespace
        WHERE n.nspname NOT IN ('public', 'pg_catalog')
    ");

    expect(count($horsPublic))->toBeGreaterThan(
        0,
        "Aucune extension de la base de test ne vit hors de `public`.\n\n"
        . "C'est `pg_partman`, dans le schema `partman`, qui est le sujet meme de ce "
        . "constat : c'est LUI que la liste ecrite a la main avait oublie, et c'est son "
        . 'schema qui ne se cree pas tout seul. Sans lui ici, la garde ne mesure que la '
        . 'moitie facile.',
    );
});

test('F39-005 — TEMOIN NEGATIF : le comparateur SAIT rendre rouge sur la liste qui a tenu trois mois', function () {
    $catalogue = catalogueDesExtensions();

    $dehors = extensionsLaisseesDehors(lignesEcritesALaMainAvantF39005(), $catalogue);

    // Ce que la liste ecrite a la main laissait dehors — et c'est la mesure du
    // 2026-08-21, pas une deduction.
    $this->assertContains(
        'pg_partman',
        $dehors,
        "Le comparateur ne voit PAS que la liste ecrite a la main oubliait `pg_partman`.\n\n"
        . "Sans ce temoin, la garde qui suit serait verte quoi qu'il arrive : un instrument "
        . "qu'on n'a jamais vu rendre rouge ne vaut rien.\n"
        . 'Laissees dehors, selon le comparateur : ' . (implode(', ', $dehors) ?: '(aucune)'),
    );

    // Et il ne crie pas sur un preambule complet.
    $complet = array_map(
        static fn (string $nom): string => "CREATE EXTENSION IF NOT EXISTS \"{$nom}\";",
        $catalogue,
    );
    expect(extensionsLaisseesDehors($complet, $catalogue))->toBe([]);

    // La moitie « schema » discrimine elle aussi.
    expect(schemasVisesSansCreation([
        'CREATE EXTENSION IF NOT EXISTS pg_partman WITH SCHEMA partman;',
    ]))->toBe(['partman']);
    expect(schemasVisesSansCreation([
        'CREATE SCHEMA IF NOT EXISTS partman;',
        'CREATE EXTENSION IF NOT EXISTS pg_partman WITH SCHEMA partman;',
    ]))->toBe([]);
    // Ordre inverse : le schema cree APRES ne sert a rien, la garde doit le voir.
    expect(schemasVisesSansCreation([
        'CREATE EXTENSION IF NOT EXISTS pg_partman WITH SCHEMA partman;',
        'CREATE SCHEMA IF NOT EXISTS partman;',
    ]))->toBe(['partman']);

    // Et le retrait des commentaires : c'est LUI qui empeche la garde des trois
    // scripts d'etre satisfaite par une phrase. Mesure du 2026-08-21 :
    // `dr-drill.sh` prive de son `FROM pg_extension …` contenait encore le mot
    // dans un commentaire, et la premiere version de la garde restait verte.
    $avecCommentaire = "# On relève donc `pg_extension` EN PRODUCTION\nSELECT 1 FROM pg_class;";
    $this->assertStringNotContainsString('pg_extension', shellSansDieses($avecCommentaire));
    $this->assertStringContainsString('pg_class', shellSansDieses($avecCommentaire));

    $avecCode = "# un commentaire quelconque\nSELECT extname FROM pg_extension;";
    $this->assertStringContainsString('pg_extension', shellSansDieses($avecCode));
});

test('F39-005 — TEMOIN DE COUVERTURE : un preambule VIDE est rouge, pas satisfait', function () {
    $catalogue = catalogueDesExtensions();

    // Le mode de defaillance le plus sournois d'un balayage : il ne voit rien,
    // et « rien de manquant parmi rien » passe pour une bonne nouvelle.
    $dehors = extensionsLaisseesDehors([], $catalogue);

    expect($dehors)->toBe(
        $catalogue,
        "Un preambule VIDE ne rend pas TOUT le catalogue comme manquant.\n\n"
        . "C'est le cas qui compte : si la requete du script echoue ou rend zero ligne, "
        . "l'archive part sans preambule et la garde doit hurler, pas se taire.",
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// LE FOND
// ═══════════════════════════════════════════════════════════════════════════

test('F39-005 — la requete du script, REJOUEE, couvre le catalogue en entier', function () {
    $contenu = lireScriptExtensions('infra/scripts/backup-postgres.sh');
    $sql = requeteExtensionsDuScript($contenu);

    expect($sql)->not->toBeNull(
        '`backup-postgres.sh` ne porte plus les marqueurs `>>> AXION-EXTENSIONS-SQL-DEBUT` / '
        . "`>>> AXION-EXTENSIONS-SQL-FIN` autour d'un heredoc `<<'EOSQL'`.\n\n"
        . "Ces marqueurs sont un CONTRAT : c'est par eux que cette garde LIT la requete du "
        . 'script pour la rejouer. Sans eux, elle ne mesure plus rien — et une garde qui ne '
        . "mesure plus rien reste verte. On la rend donc rouge ici, exprès.\n\n"
        . 'Si la requete a demenage, deplace les marqueurs avec elle.',
    );

    $lignes = array_map(
        static fn (object $l): string => (string) $l->ligne,
        DB::select($sql),
    );

    expect(count($lignes))->toBeGreaterThan(
        0,
        "La requete du script rend ZERO ligne sur la base de test.\n\nSQL joue :\n" . $sql,
    );

    $catalogue = catalogueDesExtensions();
    $dehors = extensionsLaisseesDehors($lignes, $catalogue);

    expect($dehors)->toBe(
        [],
        "Le preambule d'extensions ne couvre pas tout le catalogue de la base.\n\n"
        . 'Laissees dehors : ' . implode(', ', $dehors) . "\n\n"
        . "C'est le constat F39-005 tel qu'il se manifeste : jusqu'au 2026-08-21, le "
        . 'preambule etait NEUF noms recopies a la main et laissait `pg_partman` dehors. '
        . 'Une extension absente ne fait pas echouer la restauration : elle la fait echouer '
        . "a la PREMIERE requete qui s'en sert — panne du 2026-08-16, "
        . "« function unaccent(text) does not exist ».\n\n"
        . "Correctif : deriver le preambule de `pg_extension`, jamais le recopier.\n\n"
        . "Preambule rendu :\n  " . implode("\n  ", $lignes),
    );

    $schemasOrphelins = schemasVisesSansCreation($lignes);
    expect($schemasOrphelins)->toBe(
        [],
        "Le preambule pose une extension dans un schema qu'il ne cree pas : "
        . implode(', ', $schemasOrphelins) . ".\n\n"
        . 'Sur une base neuve, `CREATE EXTENSION … WITH SCHEMA partman` rend '
        . '« schema "partman" does not exist » — et avec `--single-transaction '
        . "-v ON_ERROR_STOP=1`, c'est TOUTE la restauration qui est annulee.\n\n"
        . "Preambule rendu :\n  " . implode("\n  ", $lignes),
    );
});

test('F39-005 — le preambule n est plus RECOPIE : aucun nom d extension en dur dans le script', function () {
    $contenu = lireScriptExtensions('infra/scripts/backup-postgres.sh');

    // On ne regarde QUE le code : les commentaires de ce script citent la mesure
    // du 2026-08-21, donc les anciennes lignes, et c'est voulu — c'est la trace.
    $enDur = [];
    foreach (explode("\n", $contenu) as $numero => $ligne) {
        $nue = ltrim($ligne);
        if ($nue === '' || str_starts_with($nue, '#')) {
            continue;
        }
        // La forme DERIVEE se termine par une concatenation SQL
        // (`… || quote_ident(e.extname) || …`) et jamais par un nom suivi de `;`.
        if (preg_match('/CREATE EXTENSION IF NOT EXISTS\s+"?[A-Za-z_][A-Za-z0-9_-]*"?\s*;/i', $nue) === 1) {
            $enDur[] = ($numero + 1) . ' : ' . trim($nue);
        }
    }

    expect($enDur)->toBe(
        [],
        "`backup-postgres.sh` porte encore des noms d'extension ECRITS EN DUR :\n  "
        . implode("\n  ", $enDur) . "\n\n"
        . "C'est la forme meme du constat F39-005. Une liste recopiee ne signale jamais ce "
        . "qu'on a oublie d'y ecrire : le seul moment ou elle repond, c'est la restauration. "
        . 'Celle-ci a laisse `pg_partman` dehors pendant trois mois sans que rien ne le '
        . "dise.\n\n"
        . 'Correctif : demander au catalogue (`pg_extension`), comme pour C21-004.',
    );
});

test('F39-005 — les trois scripts de la chaine CONTROLENT desormais les extensions', function () {
    // Le defaut n'etait pas la liste, c'etait qu'aucun des trois scripts ne
    // regardait les extensions : `backup` verifiait la taille puis les droits,
    // `restore` comptait les tables puis les droits, `dr-drill` comparait cinq
    // comptages de lignes. Une extension manquante traverse les trois.
    //
    // `pg_extension` est l'artefact qui ne peut pas s'y trouver par hasard :
    // c'est le catalogue, et c'est la seule facon de mesurer le vrai etat.
    $scripts = [
        'infra/scripts/backup-postgres.sh' => "il verifie que l'ARCHIVE porte toutes les extensions de la base source",
        'infra/scripts/restore-postgres.sh' => "il verifie que la base RESTAUREE porte toutes celles que l'archive declare",
        'infra/scripts/dr-drill.sh' => 'il compare les extensions de la production a celles de la base restauree',
    ];

    foreach ($scripts as $relatif => $attendu) {
        $contenu = lireScriptExtensions($relatif);

        // 🔴 SUR LE CODE, PAS SUR LES COMMENTAIRES. Cf. `shellSansDieses()` :
        // la premiere version de cette garde lisait le fichier entier et restait
        // verte alors que le controle avait ete retire, parce qu'un commentaire
        // prononcait encore le mot.
        $this->assertStringContainsString(
            'pg_extension',
            shellSansDieses($contenu),
            "« {$relatif} » n'interroge jamais `pg_extension` DANS SON CODE.\n\n"
            . '(Les commentaires sont ecartes exprès : un script qui PARLE du catalogue sans '
            . "l'interroger ne controle rien.)\n\n"
            . "Attendu : {$attendu}.\n\n"
            . 'Aucun de ses controles actuels ne peut voir une extension manquante — une base '
            . "privee d'`unaccent` porte le meme nombre de tables, donne les memes droits et "
            . 'rend les memes comptages. Elle echoue plus tard, en production, sur une '
            . "requete. C'est la panne du 2026-08-16.",
        );

        $this->assertStringContainsString(
            'F39-005',
            $contenu,
            "« {$relatif} » interroge `pg_extension` mais ne dit pas POURQUOI.\n\n"
            . 'Le prochain qui lira ce script doit trouver le constat, sinon il retirera le '
            . 'controle en le prenant pour du bruit.',
        );
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// CE QUI RESTE OUVERT — compte fige, pas referme
// ═══════════════════════════════════════════════════════════════════════════

test('F39-005 — SITE JUMEAU NON REPARE : la liste recopiee subsiste dans la suite de tests, et on la COMPTE', function () {
    // `backend/tests/Feature/NeDoitPasRegresserTest.php` refait le dump « avec
    // LES MEMES OPTIONS que la production » a partir d'un tableau des memes neuf
    // noms, et passe encore `--no-acl` — que la production a RETIRE le
    // 2026-08-20 (constat A08-008). Ce fichier etait, au moment de ce lot, EN
    // COURS D'EDITION par un autre agent : on ne le touche pas, on fige le
    // chiffre pour que personne ne croie le sujet clos.
    //
    // La garde est en SOUS-ENSEMBLE, pas en egalite : si quelqu'un repare ce
    // fichier, elle reste verte ; si un NOUVEAU site recopie la liste, elle
    // rougit. Une garde qui punit la reparation ne serait pas jouee longtemps.
    $connus = ['NeDoitPasRegresserTest.php'];

    $racineTests = base_path('tests');
    $trouves = [];

    $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racineTests));
    foreach ($iterateur as $fichier) {
        if (! $fichier->isFile() || $fichier->getExtension() !== 'php') {
            continue;
        }
        if ($fichier->getFilename() === 'SauvegardeEmporteLesExtensionsTest.php') {
            continue; // ce fichier-ci fige la liste d'avant, exprès, comme temoin
        }

        $contenu = (string) file_get_contents($fichier->getPathname());

        // Le marqueur d'une liste recopiee : plusieurs noms d'extension de
        // l'application cites cote a cote dans une meme construction PHP.
        if (preg_match("/'pg_trgm'.{0,200}'btree_gin'/s", $contenu) === 1) {
            $trouves[] = $fichier->getFilename();
        }
    }

    sort($trouves);
    $inattendus = array_values(array_diff($trouves, $connus));

    expect($inattendus)->toBe(
        [],
        "Une liste d'extensions RECOPIEE apparait dans un fichier de test qui n'etait pas "
        . "recense :\n  " . implode("\n  ", $inattendus) . "\n\n"
        . "C'est le constat F39-005 qui repousse ailleurs. Deriver de `pg_extension` plutot "
        . "que recopier — la requete de `infra/scripts/backup-postgres.sh` est le patron.\n\n"
        . 'Sites recenses au 2026-08-21 : ' . implode(', ', $connus),
    );
});
