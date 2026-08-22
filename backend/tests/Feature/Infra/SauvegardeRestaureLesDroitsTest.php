<?php

/**
 * GARDE DE LA SAUVEGARDE — constat A08-008 (S1).
 *
 * « La sauvegarde restaure les donnees mais pas les droits : une restauration
 *   de secours livre une application incapable de lire quoi que ce soit. »
 *
 * ── CE QUI ETAIT MESURE DANS LES SCRIPTS ────────────────────────────────────
 *
 * `infra/scripts/backup-postgres.sh:97-104` appelait :
 *
 *     pg_dump -U axion -Fp --no-owner --no-acl --clean --if-exists axion_crm
 *
 * `--no-acl` retire les GRANT. Et rien n'appelait `pg_dumpall --globals-only`,
 * qui est le SEUL moyen d'emporter les ROLES. Il manquait donc les deux moities
 * du meme mur.
 *
 * Ce n'est pas une deduction. Mesure du 2026-08-20 sur la base de test, avec les
 * options exactes du script :
 *
 *     $ pg_dump -U axion -Fp --no-owner --schema-only -t companies … \
 *         | grep -E 'GRANT'
 *     GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.companies TO axion_app;
 *     GRANT SELECT,USAGE ON SEQUENCE public.companies_id_seq TO axion_app;
 *
 *     $ pg_dump -U axion -Fp --no-owner --no-acl --schema-only -t companies … \
 *         | grep -cE 'GRANT'
 *     0
 *
 * Et `pg_dumpall --globals-only` emporte bien ce que le dump ne contient pas :
 *
 *     CREATE ROLE axion_app;
 *     ALTER ROLE axion_app WITH NOSUPERUSER … LOGIN … PASSWORD 'SCRAM-SHA-256$…';
 *
 * ── POURQUOI CE ROLE EST TOUT LE SUJET ──────────────────────────────────────
 *
 * `2026_08_14_000001_harden_workspace_isolation.php` cree `axion_app`, un role
 * NON-PROPRIETAIRE, NOSUPERUSER, NOBYPASSRLS, et lui accorde
 * `SELECT, INSERT, UPDATE, DELETE` sur les tables du schema `public`. C'est le
 * role par lequel l'application se connecte des que `CRM_DB_APP_ROLE_ENABLED`
 * vaut vrai — et c'est lui qui rend la RLS mordante.
 *
 * Sur un serveur RECONSTRUIT apres sinistre, ce role N'EXISTE PAS : il n'est pas
 * dans le dump. Meme s'il existait, aucun GRANT ne l'accompagnerait. La
 * restauration rendrait donc une base pleine et une application aveugle.
 *
 * ── ET POURQUOI PERSONNE NE L'AVAIT VU ──────────────────────────────────────
 *
 * `infra/scripts/dr-drill.sh` — l'exercice de restauration, celui qui EXISTE
 * pour attraper ce genre de chose — restaure puis compte :
 *
 *     docker exec axion-crm-postgres psql -U axion -d "$BASE_DRILL" -tAc "…"
 *
 * `-U axion`, c'est-a-dire le SUPERUTILISATEUR (mesure : `rolsuper=t`,
 * `rolbypassrls=t`). Un superutilisateur lit tout, quels que soient les GRANT et
 * quelle que soit la RLS. L'exercice ne pouvait STRUCTURELLEMENT pas s'apercevoir
 * du probleme : il verifiait la seule chose qui ne pouvait pas echouer.
 *
 * C'est le defaut le plus couteux de la famille : un controle qui rassure.
 *
 * ── CE QUE CETTE GARDE MESURE ───────────────────────────────────────────────
 *
 * 1. LE MECANISME, EN VRAI, AVEC LE ROLE APPLICATIF. Elle fabrique une base
 *    exactement comme `--no-owner --no-acl` la laisse (objets appartenant au
 *    restaurateur, zero GRANT), s'y connecte AVEC `axion_app` — pas avec
 *    `axion` — et exige « permission denied ». Puis elle pose le GRANT et exige
 *    que la lecture passe. Sans cette seconde moitie, la garde serait verte sur
 *    une base vide, un role absent ou une connexion cassee.
 *
 * 2. Que les trois scripts portent le correctif.
 *
 * ⚠️ Le conteneur de mesure n'a ni `pg_dump` ni `docker` : ces scripts ne
 * peuvent pas etre JOUES depuis ce banc, et `backup-postgres.sh` televerse de
 * surcroit sur une Storage Box reelle. Ce que la garde prouve en direct, c'est
 * le MECANISME (droits) ; ce qu'elle prouve par lecture, c'est que les scripts
 * portent le remede. Les deux moities sont dites, aucune n'est maquillee en
 * l'autre.
 */

use Tests\TestCase;

uses(TestCase::class);

/**
 * La racine du depot — `infra/` vit AU-DESSUS de l'application.
 */
function racineDepotSauvegarde(): string
{
    return realpath(base_path('..')) ?: base_path('..');
}

/** Nom de la base jetable de la garde. Explicite : personne ne doit la confondre. */
const BASE_JETABLE_DROITS = 'axion_crm_test_lot1_a35_droits';

/**
 * Ouvre une connexion PDO directe.
 *
 * On n'emprunte pas le gestionnaire de connexions de Laravel : il faut ouvrir
 * une session AVEC LE ROLE APPLICATIF sur une base qui n'est pas celle des
 * tests, et pouvoir observer l'echec d'autorisation tel que Postgres le rend.
 */
function connexionPostgres(string $base, string $utilisateur, string $motDePasse): PDO
{
    $hote = (string) config('database.connections.pgsql_owner.host');
    $port = (string) config('database.connections.pgsql_owner.port');

    return new PDO(
        "pgsql:host={$hote};port={$port};dbname={$base}",
        $utilisateur,
        $motDePasse,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

test('A08-008 — TEMOIN : le banc a bien un role applicatif distinct du proprietaire', function () {
    $proprietaire = (string) config('database.connections.pgsql_owner.username');
    $roleApp = (string) config('database.connections.pgsql_app.username');
    $mdpApp = (string) config('database.connections.pgsql_app.password');

    expect($roleApp)->not->toBe(
        $proprietaire,
        "Le role applicatif et le role proprietaire sont le MEME (« {$roleApp} »). La garde "
        . 'ci-dessous se connecterait avec le proprietaire, qui lit tout par construction : '
        . 'elle serait verte quoi qu il arrive. C est exactement le defaut de `dr-drill.sh`.',
    );

    // Sans mot de passe, impossible d'ouvrir une session avec ce role : la
    // garde ne pourrait pas mesurer, et un `markTestSkipped` serait un vert
    // deguise. On ECHOUE en le disant.
    expect($mdpApp)->not->toBe(
        '',
        "`DB_APP_PASSWORD` est vide sur ce banc : impossible d'ouvrir une session avec le "
        . 'role applicatif. La garde ne mesurerait RIEN. Ajouter '
        . '`<env name="DB_APP_PASSWORD" value="…"/>` a la configuration phpunit du lot.',
    );

    // Le role doit exister dans le cluster, et NE PAS etre superutilisateur —
    // un superutilisateur contournerait GRANT et RLS, et rendrait la mesure
    // vide de sens.
    $admin = connexionPostgres(
        'postgres',
        $proprietaire,
        (string) config('database.connections.pgsql_owner.password'),
    );

    $ligne = $admin->query(
        'SELECT rolsuper, rolbypassrls, rolcanlogin FROM pg_roles WHERE rolname = '
        . $admin->quote($roleApp),
    )->fetch(PDO::FETCH_ASSOC);

    expect($ligne)->not->toBeFalse(
        "Le role applicatif « {$roleApp} » n'existe pas dans ce cluster. Il est cree par la "
        . 'migration `2026_08_14_000001_harden_workspace_isolation` : la base du lot n est pas '
        . 'migree, et la garde ne mesurerait rien.',
    );
    expect($ligne['rolsuper'])->toBeFalse("Le role applicatif « {$roleApp} » est SUPERUTILISATEUR : il lirait tout, GRANT ou pas.");
    expect($ligne['rolbypassrls'])->toBeFalse("Le role applicatif « {$roleApp} » porte BYPASSRLS.");
    expect($ligne['rolcanlogin'])->toBeTrue("Le role applicatif « {$roleApp} » ne peut pas ouvrir de session.");
});

test('A08-008 — TEMOIN DU MECANISME : sans GRANT le role applicatif ne lit RIEN, avec GRANT il lit', function () {
    $proprietaire = (string) config('database.connections.pgsql_owner.username');
    $mdpProprietaire = (string) config('database.connections.pgsql_owner.password');
    $roleApp = (string) config('database.connections.pgsql_app.username');
    $mdpApp = (string) config('database.connections.pgsql_app.password');

    $admin = connexionPostgres('postgres', $proprietaire, $mdpProprietaire);

    // Base jetable, refaite a neuf : on ne mesure jamais sur un reste.
    $admin->exec('DROP DATABASE IF EXISTS ' . BASE_JETABLE_DROITS . ' WITH (FORCE)');
    $admin->exec('CREATE DATABASE ' . BASE_JETABLE_DROITS);

    try {
        // ── Ce que `--no-owner --no-acl` produit, reproduit fidelement ──
        // Une table creee par le restaurateur, sans le moindre GRANT. C'est
        // EXACTEMENT l'etat d'une base restauree par le script d'origine.
        $proprio = connexionPostgres(BASE_JETABLE_DROITS, $proprietaire, $mdpProprietaire);
        $proprio->exec('CREATE TABLE public.fiches_restaurees (id integer PRIMARY KEY, nom text)');
        $proprio->exec("INSERT INTO public.fiches_restaurees VALUES (1, 'une fiche restauree')");

        // Le proprietaire, lui, lit sans probleme — c'est ce que `dr-drill.sh`
        // mesurait, et c'est pourquoi il ne voyait rien.
        expect((int) $proprio->query('SELECT count(*) FROM public.fiches_restaurees')->fetchColumn())
            ->toBe(1);

        // ── LA MESURE QUI COMPTE : avec le ROLE APPLICATIF ──
        $app = connexionPostgres(BASE_JETABLE_DROITS, $roleApp, $mdpApp);

        $refus = null;
        try {
            $app->query('SELECT count(*) FROM public.fiches_restaurees')->fetchColumn();
        } catch (PDOException $e) {
            $refus = $e->getMessage();
        }

        expect($refus)->not->toBeNull(
            "Le role applicatif « {$roleApp} » a pu lire une table SANS AUCUN GRANT. "
            . 'Ou bien un GRANT traine au niveau du schema, ou bien ce role a plus de '
            . 'privileges que prevu : la garde ne prouverait plus rien sur la restauration.',
        );
        // ⚠️ Sous-chaine SANS LETTRE ACCENTUEE, et en anglais : c'est Postgres
        // qui parle ici, pas nous.
        $this->assertStringContainsString(
            'permission denied',
            (string) $refus,
            "Le refus attendu n'est pas un refus d'autorisation : « {$refus} ».",
        );

        // ── ET LE TEMOIN INVERSE : la garde SAIT passer au vert ──
        // Sans lui, un test qui echouerait pour n'importe quelle raison (base
        // absente, connexion morte) serait pris pour une preuve.
        $proprio->exec('GRANT USAGE ON SCHEMA public TO ' . $roleApp);
        $proprio->exec('GRANT SELECT ON public.fiches_restaurees TO ' . $roleApp);

        $app2 = connexionPostgres(BASE_JETABLE_DROITS, $roleApp, $mdpApp);
        expect((int) $app2->query('SELECT count(*) FROM public.fiches_restaurees')->fetchColumn())
            ->toBe(
                1,
                'Le GRANT vient d etre pose et le role applicatif ne lit toujours pas : la '
                . 'mesure ci-dessus ne prouvait donc pas ce qu elle pretend.',
            );

        unset($app, $app2, $proprio);
    } finally {
        // On rend le cluster propre meme si la garde a rougi.
        $admin->exec('DROP DATABASE IF EXISTS ' . BASE_JETABLE_DROITS . ' WITH (FORCE)');
    }
});

/**
 * Les trois scripts de la chaine de sauvegarde.
 *
 * @return list<string>
 */
function scriptsDeSauvegarde(): array
{
    return [
        'infra/scripts/backup-postgres.sh',
        'infra/scripts/restore-postgres.sh',
        'infra/scripts/dr-drill.sh',
    ];
}

function contenuScriptSauvegarde(string $relatif): string
{
    return (string) file_get_contents(racineDepotSauvegarde() . '/' . $relatif);
}

/**
 * 🔴 LE SCRIPT PRIVE DE SES COMMENTAIRES — ajoute le 2026-08-21.
 *
 * DEUX DES GARDES DE CE FICHIER NE POUVAIENT PAS ECHOUER, et c'est une mesure,
 * pas une inquietude. Le 2026-08-21, on a remis les trois moities du defaut
 * A08-008 dans les scripts pour exiger le rouge :
 *
 *   · `--no-acl` reintroduit sur `pg_dump`          → ROUGE. Correct.
 *   · `pg_dumpall --globals-only` retire du flux    → VERT. Faux vert.
 *   · `has_table_privilege` retire de `dr-drill.sh` → VERT. Faux vert.
 *
 * La cause est la meme dans les deux cas : `dumpPorteLesRoles()` et
 * `verifieLesDroitsDuRoleApplicatif()` cherchaient leur motif dans le FICHIER
 * ENTIER, commentaires compris — et ces scripts sont abondamment commentes, par
 * ce meme lot, avec les mots exacts qu'ils cherchent :
 *
 *     backup-postgres.sh:208  #     $ pg_dumpall -U axion --globals-only
 *     dr-drill.sh:276         # `has_table_privilege` repond exactement a la …
 *
 * Autrement dit : on pouvait supprimer le MECANISME et garder la PHRASE qui en
 * parle, et les gardes applaudissaient. C'est exactement ce que le docbloc de ce
 * fichier reproche a `dr-drill.sh` — « un controle qui rassure ». Il etait ici
 * aussi, et il y est reste un jour.
 */
function codeDuScriptSansCommentaires(string $contenu): string
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

/**
 * 🔴 LES INVOCATIONS DU SCRIPT, UNE PAR LIGNE — ajoute le 2026-08-21.
 *
 * Retirer les commentaires n'a pas suffi, et la mesure l'a dit tout de suite.
 * Deuxieme tour du meme jour, `--globals-only` retire du flux :
 * `dumpPorteLesRoles()` restait VERTE. Deux raisons, toutes deux instructives :
 *
 *   1. son motif exigeait `pg_dumpall` ET `--globals-only` SUR LA MEME LIGNE.
 *      Dans le script, l'invocation est ecrite sur trois lignes avec des
 *      continuations `\`. Elle n'a donc JAMAIS reconnu le vrai appel — elle
 *      reconnaissait la trace ecrite ailleurs ;
 *   2. ce qu'elle reconnaissait, apres le retrait des commentaires, c'etait
 *      `backup-postgres.sh:345` :
 *
 *          log "   À vérifier : que `pg_dumpall --globals-only` tourne, …"
 *
 *      un MESSAGE D'ERREUR. Le script pouvait ne plus rien faire et continuer a
 *      recommander de le faire ; la garde applaudissait.
 *
 * On rend donc les invocations REELLES : continuations recollees, et les lignes
 * qui ne font que PARLER (`log`, `echo`, `printf`) ecartees.
 */
function invocationsDuScript(string $contenu): string
{
    // Les continuations `\` recollees : une commande = une ligne.
    $recolle = preg_replace('/\\\\\R\s*/', ' ', codeDuScriptSansCommentaires($contenu));

    $lignes = [];
    foreach (explode("\n", (string) $recolle) as $ligne) {
        $nue = ltrim($ligne);
        // Une ligne qui PARLE n'est pas une ligne qui FAIT.
        if (preg_match('/^(log|echo|printf)\b/', $nue) === 1) {
            continue;
        }
        $lignes[] = $ligne;
    }

    return implode("\n", $lignes);
}

/** Une invocation de `pg_dump` retire-t-elle les ACL ? (`--no-acl` ou son alias `-x`) */
function dumpRetireLesAcl(string $contenu): bool
{
    foreach (explode("\n", $contenu) as $ligne) {
        $nue = trim($ligne);
        if ($nue === '' || str_starts_with($nue, '#')) {
            continue;
        }
        if (preg_match('/(^|\s)(--no-acl|-x)(\s|$|\\\\)/', $nue) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Le script emporte-t-il les roles du cluster ?
 *
 * ⚠️ SUR LE CODE, PAS SUR LES COMMENTAIRES : cf. `codeDuScriptSansCommentaires()`.
 * Cette garde etait verte, le 2026-08-21, sur un `backup-postgres.sh` dont le
 * `pg_dumpall --globals-only` avait ete retire — le motif survivait dans un
 * commentaire qui citait la mesure.
 */
function dumpPorteLesRoles(string $contenu): bool
{
    return preg_match(
        '/pg_dumpall[^\n]*--globals-only|--globals-only[^\n]*pg_dumpall/',
        invocationsDuScript($contenu),
    ) === 1;
}

/**
 * Le script verifie-t-il les droits AVEC LE ROLE APPLICATIF ?
 *
 * `has_table_privilege` est le seul artefact qui ne peut pas s'y trouver par
 * hasard : c'est la fonction Postgres qui repond exactement a la question
 * « ce role peut-il lire cette table ». Un comptage `SELECT count(*)` joue en
 * superutilisateur — ce que faisait `dr-drill.sh` — ne la contient pas.
 *
 * ⚠️ SUR LE CODE, PAS SUR LES COMMENTAIRES : cf. `codeDuScriptSansCommentaires()`.
 * Cette garde etait verte, le 2026-08-21, sur un `dr-drill.sh` dont le
 * `has_table_privilege` avait ete remplace par `false` dans la requete — le mot
 * survivait dans le commentaire qui explique pourquoi on l'emploie.
 */
function verifieLesDroitsDuRoleApplicatif(string $contenu): bool
{
    return str_contains(invocationsDuScript($contenu), 'has_table_privilege');
}

test('A08-008 — TEMOIN : le banc voit les trois scripts de la chaine', function () {
    $manquants = [];
    foreach (scriptsDeSauvegarde() as $relatif) {
        if (! is_file(racineDepotSauvegarde() . '/' . $relatif)) {
            $manquants[] = $relatif;
        }
    }

    expect($manquants)->toBe(
        [],
        'Le banc ne voit pas ces scripts : ' . implode(', ', $manquants)
        . '. Les gardes qui suivent seraient vertes sur ZERO fichier. Racine vue : '
        . racineDepotSauvegarde(),
    );
});

test('A08-008 — TEMOIN NEGATIF : les balayages savent reperer le defaut ET le correctif', function () {
    // `--no-acl` sous ses deux formes, y compris coupee par une continuation de
    // ligne — c'est ainsi que le script d'origine l'ecrivait.
    expect(dumpRetireLesAcl("pg_dump \\\n  --no-owner \\\n  --no-acl \\\n  axion_crm"))->toBeTrue();
    expect(dumpRetireLesAcl('pg_dump -U axion -x axion_crm'))->toBeTrue();
    // Et il ne crie pas sur la forme correcte, ni sur un commentaire qui en parle.
    expect(dumpRetireLesAcl("pg_dump \\\n  --no-owner \\\n  axion_crm"))->toBeFalse();
    expect(dumpRetireLesAcl('# on ne passe plus --no-acl, cf. A08-008'))->toBeFalse();

    expect(dumpPorteLesRoles('pg_dumpall -U axion --globals-only'))->toBeTrue();
    expect(dumpPorteLesRoles('pg_dump -U axion axion_crm'))->toBeFalse();

    expect(verifieLesDroitsDuRoleApplicatif("has_table_privilege('axion_app', t, 'SELECT')"))->toBeTrue();
    expect(verifieLesDroitsDuRoleApplicatif('psql -U axion -tAc "SELECT count(*) FROM companies"'))->toBeFalse();

    // 🔴 LE TROU MESURE LE 2026-08-21 : LA PHRASE NE VAUT PAS LE MECANISME.
    //
    // Les deux balayages ci-dessus lisaient le fichier ENTIER. On pouvait donc
    // retirer le mecanisme et garder le commentaire qui en parle — et ils
    // restaient verts. Les deux cas exacts qu'on a mesures :
    expect(dumpPorteLesRoles(
        "#     \$ pg_dumpall -U axion --globals-only\n"
        . '    docker exec "$DB_CONTAINER" pg_dump -U axion --no-owner axion_crm',
    ))->toBeFalse();

    // Deuxieme trou du meme jour : un MESSAGE qui recommande le geste n'est pas
    // le geste. `backup-postgres.sh:345` le prononce dans un `log` d'erreur.
    expect(dumpPorteLesRoles(
        "    log \"   À vérifier : que \`pg_dumpall --globals-only\` tourne\"\n"
        . '    docker exec "$C" pg_dump -U axion axion_crm',
    ))->toBeFalse();

    // Et troisieme : l'invocation REELLE est ecrite sur trois lignes avec des
    // continuations. Le motif d'origine, mono-ligne, ne l'a jamais reconnue.
    expect(dumpPorteLesRoles(
        "    docker exec \"\$DB_CONTAINER\" pg_dumpall \\\n        -U \"\$DB_USER\" \\\n        --globals-only",
    ))->toBeTrue();
    expect(verifieLesDroitsDuRoleApplicatif(
        "# `has_table_privilege` repond exactement a la question posee par le constat\n"
        . 'ILLISIBLES=$(psql -tAc "SELECT count(*) FROM pg_class WHERE false")',
    ))->toBeFalse();

    // Et ils ne se trompent pas dans l'autre sens : le mecanisme PRESENT,
    // commentaire ou pas, reste reconnu.
    expect(dumpPorteLesRoles(
        "# un commentaire quelconque\n    docker exec \"\$C\" pg_dumpall -U axion --globals-only",
    ))->toBeTrue();
    expect(verifieLesDroitsDuRoleApplicatif(
        "# un commentaire quelconque\n    AND NOT has_table_privilege('axion_app', c.oid, 'SELECT')",
    ))->toBeTrue();
});

test('A08-008 — la sauvegarde emporte les ROLES du cluster', function () {
    expect(dumpPorteLesRoles(contenuScriptSauvegarde('infra/scripts/backup-postgres.sh')))->toBeTrue(
        "`backup-postgres.sh` n'appelle pas `pg_dumpall --globals-only`.\n\n"
        . 'Un `pg_dump` ne contient AUCUN role : ni `axion`, ni `axion_app`. Sur un serveur '
        . "reconstruit apres sinistre, le role applicatif n'existerait donc pas et "
        . "l'application ne pourrait meme pas OUVRIR de session.\n\n"
        . "Mesure du 2026-08-20, `pg_dumpall -U axion --globals-only` :\n"
        . "    CREATE ROLE axion_app;\n"
        . "    ALTER ROLE axion_app WITH NOSUPERUSER … LOGIN … PASSWORD 'SCRAM-SHA-256\$…';\n\n"
        . 'Correctif : emettre cette section dans l archive, avant la charge utile.',
    );
});

test('A08-008 — la sauvegarde emporte les GRANT', function () {
    expect(dumpRetireLesAcl(contenuScriptSauvegarde('infra/scripts/backup-postgres.sh')))->toBeFalse(
        "`backup-postgres.sh` passe encore `--no-acl` (ou `-x`) a `pg_dump`.\n\n"
        . "Mesure du 2026-08-20, avec et sans l option, sur la table `companies` :\n"
        . "    sans --no-acl : GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.companies TO axion_app;\n"
        . "    avec --no-acl : 0 ligne GRANT\n\n"
        . 'Le role applicatif est NON-PROPRIETAIRE (migration harden_workspace_isolation) : '
        . 'sans GRANT il ne lit rien. Le temoin du mecanisme, plus haut, le montre en direct '
        . "— « permission denied » sur une table restauree sans ACL.\n\n"
        . 'Correctif : retirer `--no-acl`. `--no-owner` peut rester : la propriete revient au '
        . 'restaurateur, ce qui est deja le cas en production.',
    );
});

test('A08-008 — la restauration REPOSE les roles et VERIFIE avec le role applicatif', function () {
    $restore = contenuScriptSauvegarde('infra/scripts/restore-postgres.sh');

    expect(dumpPorteLesRoles($restore) || str_contains($restore, 'GLOBALS'))->toBeTrue(
        "`restore-postgres.sh` n'applique pas la section des roles de l archive.\n\n"
        . 'Sans elle, les `GRANT … TO axion_app` de la charge utile echouent sur '
        . '« role "axion_app" does not exist », et avec `--single-transaction -v '
        . 'ON_ERROR_STOP=1` c est TOUTE la restauration qui est annulee.',
    );

    expect(verifieLesDroitsDuRoleApplicatif($restore))->toBeTrue(
        "`restore-postgres.sh` ne verifie pas les droits du role applicatif.\n\n"
        . 'Sa verification finale compte les TABLES (`information_schema.tables`) : une base '
        . "restauree sans un seul GRANT passe ce controle haut la main, puis l'application "
        . "n'y lit rien. Un controle qui ne peut pas echouer sur le defaut qu'on repare est "
        . "pire qu'aucun controle.\n\n"
        . "Correctif : interroger `has_table_privilege('<role applicatif>', …, 'SELECT')` sur "
        . 'les tables du schema public, et sortir en erreur s il en reste une seule illisible.',
    );
});

test('A08-008 — les trois scripts partagent LES MEMES marqueurs de section', function () {
    // La section des roles est isolee dans l'archive par deux marqueurs
    // textuels. `backup-postgres.sh` les ECRIT, `restore-postgres.sh` et
    // `dr-drill.sh` les CHERCHENT. C'est un contrat entre trois fichiers, et
    // c'est exactement la forme que prend le patron A-011 dans ce depot :
    // quelqu'un renomme d'un cote, oublie les deux autres, et la restauration
    // se casse EN SILENCE — on ne s'en apercoit qu'un jour de sinistre.
    //
    // Le mode de defaillance n'est meme pas une erreur : `sed` sur un marqueur
    // introuvable ne supprime rien et ne dit rien. La charge utile partirait
    // alors avec les `CREATE ROLE` dedans, et `ON_ERROR_STOP=1` annulerait
    // TOUTE la restauration.
    $marqueurs = [];
    foreach (scriptsDeSauvegarde() as $relatif) {
        preg_match_all(
            '/-- >>> AXION-GLOBALS-(DEBUT|FIN)/',
            contenuScriptSauvegarde($relatif),
            $trouves,
        );
        $marqueurs[$relatif] = array_values(array_unique($trouves[0]));
        sort($marqueurs[$relatif]);
    }

    $attendu = ['-- >>> AXION-GLOBALS-DEBUT', '-- >>> AXION-GLOBALS-FIN'];

    foreach ($marqueurs as $relatif => $trouves) {
        expect($trouves)->toBe(
            $attendu,
            "« {$relatif} » ne porte pas les deux marqueurs de la section des roles.\n\n"
            . 'Trouve : ' . (implode(', ', $trouves) ?: '(aucun)') . "\n"
            . 'Attendu : ' . implode(', ', $attendu) . "\n\n"
            . 'Ces marqueurs sont un CONTRAT entre les trois scripts. Un `sed` sur un '
            . 'marqueur introuvable ne supprime rien ET NE DIT RIEN : la section des roles '
            . 'partirait dans la charge utile, ou `CREATE ROLE axion;` echouerait sur un '
            . 'cluster existant — et `ON_ERROR_STOP=1` annulerait toute la restauration.',
        );
    }
});

test('A08-008 — l exercice de restauration ne se contente plus de compter en superutilisateur', function () {
    $drill = contenuScriptSauvegarde('infra/scripts/dr-drill.sh');

    expect(verifieLesDroitsDuRoleApplicatif($drill))->toBeTrue(
        "`dr-drill.sh` verifie toujours la restauration UNIQUEMENT en superutilisateur.\n\n"
        . 'Ses etapes 2 et 4 jouent `psql -U axion` — mesure : `rolsuper=t`, `rolbypassrls=t`. '
        . 'Un superutilisateur lit tout, quels que soient les GRANT et quelle que soit la RLS. '
        . "Cet exercice ne pouvait donc STRUCTURELLEMENT pas s'apercevoir du constat A08-008 : "
        . "il verifiait la seule chose qui ne pouvait pas echouer, et il rassurait.\n\n"
        . 'Correctif : ajouter une etape qui interroge '
        . "`has_table_privilege('<role applicatif>', …, 'SELECT')` sur la base restauree.",
    );
});

/**
 * 🔴 F39-010 (S2) — « le script de restauration a pour cible par defaut la base
 * de production ».
 *
 * Mesure du 2026-08-22, avant correctif :
 *   `infra/scripts/restore-postgres.sh:56` `TARGET_DB="${2:-axion_crm}"`
 *   `docker-compose.yml:97`                `POSTGRES_DB: axion_crm`
 * Les deux nomment la MEME base. Et le script l'ecrase :
 *   `:132` `| psql … -d "$TARGET_DB" --single-transaction -v ON_ERROR_STOP=1`,
 * apres l'avoir creee au besoin (`:88`). Aucune confirmation, aucun controle
 * d'environnement : la forme meme donnee dans l'usage (`:5`),
 * `bash restore-postgres.sh dump.sql.gz`, visait la production.
 *
 * CE QUE CETTE GARDE INSPECTE, et rien d'autre : le TEXTE du script (l'absence
 * de defaut, la presence du second verrou) et la ligne du runbook qui l'appelle.
 * Elle ne joue pas la restauration — ce banc n'a ni archive ni conteneur.
 */
test('F39-010 — la base cible n a AUCUN defaut, et viser la production se declare', function () {
    $restore = contenuScriptSauvegarde('infra/scripts/restore-postgres.sh');

    // TEMOIN — c'est bien le script qui ecrase une base : sans lui, un fichier
    // vide ou renomme passerait la garde au vert.
    expect(str_contains($restore, 'TARGET_DB'))->toBeTrue(
        "`restore-postgres.sh` ne porte plus de variable TARGET_DB : cette garde n'inspecte "
        . 'plus rien. Verifier ce qu est devenu le script avant de conclure quoi que ce soit.',
    );

    // `${2:-}` (defaut VIDE) est la forme voulue ; `${2:-quoi-que-ce-soit}` ne
    // l'est pas, quelle que soit la base nommee.
    $defautArme = (bool) preg_match('/TARGET_DB="?\$\{2:-\s*[^}\s"]/', $restore);
    expect($defautArme)->toBeFalse(
        "F39-010 rouvert : `restore-postgres.sh` redonne un DEFAUT a la base cible.\n\n"
        . "Le script ECRASE la base qu'il vise, apres l'avoir creee au besoin. Un defaut "
        . "signifie qu'un appel a un seul argument — la forme la plus naturelle, et celle "
        . "que l'usage montrait — choisit la cible a la place de l'operateur. Le 2026-08-22 "
        . "cette cible etait `axion_crm`, la production (docker-compose.yml:97).\n\n"
        . 'Geste : `TARGET_DB="${2:-}"` et refuser l execution si le second argument manque.',
    );

    expect(str_contains($restore, 'JE_RESTAURE_LA_PRODUCTION'))->toBeTrue(
        "F39-010, second verrou disparu : `restore-postgres.sh` n exige plus de declaration "
        . "explicite pour ecraser la base de production.\n\n"
        . "Retirer le defaut protege de la distraction ; ca ne protege pas du copier-coller "
        . "d une ligne de runbook. Ecraser la production est legitime (reprise apres "
        . "sinistre) mais jamais ordinaire : ca doit s ENONCER.\n\n"
        . 'Geste : refuser quand `$TARGET_DB` vaut la base de production et que '
        . '`JE_RESTAURE_LA_PRODUCTION` ne vaut pas `oui`.',
    );

    // L'usage ne doit plus presenter la cible comme facultative : c'est cette
    // ligne-la que l'operateur copie.
    expect(str_contains($restore, '[target_db]'))->toBeFalse(
        "L usage de `restore-postgres.sh` annonce toujours la base cible entre crochets, "
        . "donc facultative, alors qu elle est desormais obligatoire. C est la ligne que "
        . "l operateur copie : elle doit dire vrai.\n\n"
        . 'Geste : ecrire `<target_db>` a la place de `[target_db]` en tete du script.',
    );
});

test('F39-010 — le runbook de reprise appelle le script avec la declaration exigee', function () {
    // Un verrou pose dans le script sans mettre a jour le runbook qui l'appelle
    // ne protege rien : il transforme la reprise apres sinistre en enigme, a
    // l'heure ou personne n'a le temps d'en resoudre une.
    $runbook = (string) file_get_contents(racineDepotSauvegarde() . '/infra/runbooks/04-restore-dr.md');

    expect(str_contains($runbook, 'restore-postgres.sh'))->toBeTrue(
        "Le runbook 04 n appelle plus `restore-postgres.sh` : cette garde n inspecte plus rien.",
    );

    expect(str_contains($runbook, 'JE_RESTAURE_LA_PRODUCTION=oui'))->toBeTrue(
        "F39-010 : le runbook `infra/runbooks/04-restore-dr.md` appelle `restore-postgres.sh` "
        . "sur la base de production SANS la declaration que le script exige depuis le "
        . "2026-08-22.\n\n"
        . "La commande du §4 sortira donc en code 1, en pleine reprise apres sinistre, avec "
        . "un message que personne n attend a ce moment-la.\n\n"
        . 'Geste : prefixer la commande du §4 par `JE_RESTAURE_LA_PRODUCTION=oui`, et dire '
        . 'pourquoi elle est la.',
    );
});
