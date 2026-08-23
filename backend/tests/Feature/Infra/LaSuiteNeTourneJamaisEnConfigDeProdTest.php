<?php

/**
 * GARDE — A08-003 (S1) : « LA SUITE NE S'EXECUTE JAMAIS DANS LA CONFIGURATION
 * DE LA PRODUCTION, ET DEUX TESTS AFFIRMENT LE CONTRAIRE DE CE QUI Y TOURNE. »
 *
 * ── CE QUE CE FICHIER NE FAIT PAS ───────────────────────────────────────────
 *
 * Il ne demande PAS que la suite tourne en configuration de production. Ce
 * serait absurde et dangereux : une suite qui tourne avec `APP_ENV=production`,
 * `QUEUE_CONNECTION=redis` et `MOCK_MODE=false` appelle l'INSEE pour de vrai et
 * `RefreshDatabase` y devient une arme. La divergence est NORMALE.
 *
 * Ce qui n'est pas normal, c'est de la laisser INVISIBLE, puis d'ecrire des
 * tests dont le NOM lit comme une phrase sur le produit alors qu'ils ne
 * mesurent que le fichier de configuration sous lequel ils tournent.
 *
 * ── LA MESURE, LE 2026-08-20 ────────────────────────────────────────────────
 *
 * Trois configurations font tourner cette suite, et trois seulement :
 *
 *   backend/phpunit.xml            → en local (`composer test`, `make test-backend`)
 *   backend/phpunit-ci.xml         → en CI (`ci.yml`, etape « Pest (BLOQUANT) »)
 *   .github/workflows/ci.yml       → l'environnement du job, par-dessus
 *
 * AUCUNE des trois ne pose `CRM_DB_APP_ROLE_ENABLED` ni
 * `CRM_STRICT_WORKSPACE_SCOPE`. La suite mesure donc, invariablement, la valeur
 * PAR DEFAUT declaree dans `config/crm.php` — c'est-a-dire `false`, c'est-a-dire
 * une valeur que PERSONNE n'a decidee pour cette execution.
 *
 * Or ce depot affirme, en quatre endroits dates du 2026-08-20, que la
 * production tourne avec le drapeau ARME :
 *
 *   app/Console/Commands/CoverageRefreshMatrix.php:14-26
 *     « A08-001 (S1) — echoue 71 fois sur 71 en production DEPUIS L'ARMEMENT DU
 *       ROLE APPLICATIF […] Depuis `CRM_DB_APP_ROLE_ENABLED=true`, la connexion
 *       par defaut porte `axion_app` »
 *   database/migrations/2026_08_20_140000_rafraichir_matrice_couverture_par_fonction_definer.php
 *   tests/Feature/Database/RafraichissementMatriceCouvertureTest.php:19
 *   routes/console.php:18
 *
 * ⚠️ ET LE DEPOT SE CONTREDIT LUI-MEME : `app/Console/Commands/PartmanMaintenir.php`
 * (ligne ~110), DU MEME JOUR, ecrit « Aujourd'hui `CRM_DB_APP_ROLE_ENABLED` vaut
 * false ». L'un des deux est faux. Ce fichier ne tranche pas — il n'en a pas les
 * moyens, la production n'etant pas observable depuis ce depot, et un correctif
 * d'audit qui DEVINE l'etat d'un drapeau de production est pire que le silence.
 * Il fige le seul fait etabli : la suite, elle, n'en sait rien.
 *
 * ── LA MESURE QUI VA PLUS LOIN QUE LE CONSTAT ───────────────────────────────
 *
 * A08-003 dit « la suite ne s'execute JAMAIS dans la configuration de la
 * production ». Elle NE PEUT PAS s'y executer. Mesure du 2026-08-20, obtenue en
 * injectant le drapeau dans l'execution, sans toucher un seul fichier :
 *
 *   $ docker exec -e CRM_DB_APP_ROLE_ENABLED=true a35r \
 *       ./vendor/bin/pest -c /tmp/a35r/phpunit-lot11.xml tests/Feature/RlsTest.php --filter=drapeau
 *     SQLSTATE[42501]: Insufficient privilege: 7
 *     ERROR:  must be owner of table activities
 *     (SQL: drop table "public"."activities", … cascade)
 *
 * `RefreshDatabase` emet un `DROP TABLE … CASCADE` sur la connexion PAR DEFAUT.
 * Sous le role applicatif non-proprietaire, PostgreSQL le refuse — et ce ne
 * sont pas trois tests qui tombent, c'est le trait lui-meme, donc l'essentiel
 * des 159 fichiers de la suite.
 *
 * Consequence a garder en tete avant de « rapprocher la suite de la
 * production » : ce n'est pas un reglage, c'est un chantier.
 *
 * ── LES DEUX TESTS VISES ────────────────────────────────────────────────────
 *
 * `tests/Feature/RlsTest.php` portait :
 *   « drapeaux par defaut : aucun durcissement actif »
 *   « drapeau db_app_role a OFF : la connexion par defaut reste le role historique »
 *
 * Leurs assertions sont EXACTES — sous phpunit.xml. Ce qui etait faux, c'est
 * qu'elles se lisaient comme un etat du produit. Elles ont ete renommees pour
 * dire de quoi elles parlent ; leurs assertions n'ont pas bouge d'un caractere,
 * parce qu'elles n'avaient rien de faux.
 *
 * ⚠️ IL Y EN AVAIT UN TROISIEME, et l'audit n'en nommait qu'un :
 * `tests/Feature/Rgpd/RolePorteurDeLaRlsTest.php` tient le meme discours sur
 * quatre tests, avec en tete « la connexion par defaut — celle par laquelle
 * passent L'APPLICATION et l'ecrasante majorite de cette suite ». Ce fichier est
 * hors du perimetre de cette reparation ; le constat est reporte, non repare.
 */

use Tests\TestCase;

uses(TestCase::class);

/**
 * La racine du depot. `base_path('..')` : les workflows vivent au-dessus de
 * l'application.
 */
function suiteProdRacine(): string
{
    return realpath(base_path('..')) ?: base_path('..');
}

/**
 * Les trois — et seulement trois — configurations qui font tourner cette suite.
 *
 * Enumerees a la main et non ramassees au `glob` : une quatrieme configuration
 * qui apparaitrait demain DOIT etre ajoutee ici a la main, par quelqu'un qui a
 * relu ce qu'elle pose. Un `glob` l'aurait avalee en silence, et c'est
 * exactement le mecanisme par lequel une exclusion de tests a survecu des mois.
 *
 * @return array<string, string> etiquette => chemin absolu
 */
function suiteProdConfigurations(): array
{
    return [
        'phpunit.xml (local)' => base_path('phpunit.xml'),
        'phpunit-ci.xml (CI)' => base_path('phpunit-ci.xml'),
        'ci.yml (env du job)' => suiteProdRacine() . '/.github/workflows/ci.yml',
    ];
}

/**
 * Cette configuration pose-t-elle cette cle, sous l'une ou l'autre des deux
 * syntaxes en usage ici ?
 *
 *   PHPUnit : <env name="CLE" value="…"/>
 *   Actions : CLE: '…'   (sous `env:`)
 */
function suiteProdPose(string $contenu, string $cle): bool
{
    $enPhpunit = preg_match('/<env\s+name="' . preg_quote($cle, '/') . '"/', $contenu) === 1;
    $enActions = preg_match('/^\s*' . preg_quote($cle, '/') . '\s*:/m', $contenu) === 1;

    return $enPhpunit || $enActions;
}

/** Les drapeaux de durcissement, ceux dont l'etat en production est en cause. */
function suiteProdDrapeauxDeDurcissement(): array
{
    return ['CRM_DB_APP_ROLE_ENABLED', 'CRM_STRICT_WORKSPACE_SCOPE'];
}

// ─────────────────────────────────────────────────────────────────────────────
// TEMOINS
// ─────────────────────────────────────────────────────────────────────────────

test('A08-003 — TEMOIN : les trois configurations sont lisibles et non vides', function () {
    $illisibles = [];

    foreach (suiteProdConfigurations() as $etiquette => $chemin) {
        if (! is_file($chemin) || strlen((string) file_get_contents($chemin)) < 500) {
            $illisibles[] = $etiquette . ' (' . $chemin . ')';
        }
    }

    // Sans ce temoin, une configuration absente rendrait « elle ne pose pas la
    // cle » vrai par vacuite — le vert le plus creux qui soit.
    expect($illisibles)->toBe(
        [],
        'Le banc ne lit pas : ' . implode(', ', $illisibles) . ".\n"
        . '⚠️ Le conteneur de mesure ne monte pas `.github/` : '
        . '`docker cp .github/. a35r:/var/www/.github/`.',
    );
});

test('A08-003 — TEMOIN NEGATIF : le lecteur de configuration sait voir une cle POSEE', function () {
    // Les deux syntaxes reconnues, fabriquees.
    $phpunit = '<php><env name="CRM_DB_APP_ROLE_ENABLED" value="true"/></php>';
    $actions = "        env:\n          CRM_DB_APP_ROLE_ENABLED: 'true'\n";

    expect(suiteProdPose($phpunit, 'CRM_DB_APP_ROLE_ENABLED'))->toBeTrue(
        'Le lecteur ne reconnait pas la forme PHPUnit : le controle ci-dessous serait vert sur tout.',
    );
    expect(suiteProdPose($actions, 'CRM_DB_APP_ROLE_ENABLED'))->toBeTrue(
        'Le lecteur ne reconnait pas la forme GitHub Actions.',
    );

    // Et il ne doit pas voir une cle qui n'y est pas, ni se laisser prendre par
    // une simple MENTION dans un commentaire — c'est precisement ce que
    // `deploy-direct-ssh.yml` contient : « tant que le drapeau
    // CRM_DB_APP_ROLE_ENABLED est a false ». Une mention n'est pas une valeur.
    expect(suiteProdPose($phpunit, 'CRM_STRICT_WORKSPACE_SCOPE'))->toBeFalse();
    expect(suiteProdPose('# CRM_DB_APP_ROLE_ENABLED est a false', 'CRM_DB_APP_ROLE_ENABLED'))->toBeFalse(
        'Un commentaire qui NOMME la cle est compte comme une valeur posee : le controle mesurerait la prose.',
    );
});

// ─────────────────────────────────────────────────────────────────────────────
// LE CONSTAT, FIGE
// ─────────────────────────────────────────────────────────────────────────────

test('A08-003 — aucune configuration de test ne pose les drapeaux de durcissement', function () {
    $posees = [];

    foreach (suiteProdConfigurations() as $etiquette => $chemin) {
        $contenu = (string) file_get_contents($chemin);
        foreach (suiteProdDrapeauxDeDurcissement() as $cle) {
            if (suiteProdPose($contenu, $cle)) {
                $posees[] = $etiquette . ' → ' . $cle;
            }
        }
    }

    expect($posees)->toBe(
        [],
        "BONNE NOUVELLE, PEUT-ETRE — mais elle PERIME plusieurs tests, et il faut y retourner.\n\n"
        . "Une configuration de test pose desormais un drapeau de durcissement :\n  - "
        . implode("\n  - ", $posees) . "\n\n"
        . "Ces fichiers decrivent l'etat INVERSE et doivent etre relus AVANT d'assouplir "
        . "cette garde :\n"
        . "  - tests/Feature/RlsTest.php        (2 tests sur l'inertie des drapeaux)\n"
        . "  - tests/Feature/Rgpd/RolePorteurDeLaRlsTest.php (4 tests, meme sujet)\n"
        . "  - tests/Feature/EtancheiteParTableTest.php\n"
        . '  - tests/Feature/NeDoitPasRegresserTest.php',
    );
});

test('A08-003 — quelle que soit la source, la suite tourne DESARMEE', function () {
    // 🔴 PREMIERE VERSION DE CE TEST, ET POURQUOI ELLE A ETE JETEE.
    //
    // Il exigeait `getenv('CRM_DB_APP_ROLE_ENABLED') === false`, c'est-a-dire
    // « la variable est ABSENTE, personne n'a decide ». Mesure du 2026-08-20 au
    // banc `a35r` : elle rend la CHAINE `'false'`. Elle n'est pas dans
    // l'environnement du conteneur (`env` ne la montre pas) — elle vient de
    // `backend/.env`, que Laravel charge en `createUnsafeImmutable`, ce qui
    // appelle `putenv()`.
    //
    // Le test mesurait donc la presence d'un fichier `.env` local, pas le
    // produit : vert sur une machine sans `.env`, rouge sur une machine qui en a
    // un, a code strictement identique. C'est la regle 7 du mandat, prise en
    // flagrant delit.
    //
    // Ce qui est PORTABLE et qui dit la meme chose : l'etat EFFECTIF sous lequel
    // la suite tourne. Il est desarme, et c'est cela qu'il faut figer.
    expect(config('crm.db_app_role'))->toBeFalsy(
        'La suite tourne desormais avec le role applicatif ARME. Ses assertions changent de sens : '
        . 'relire tests/Feature/RlsTest.php et tests/Feature/Rgpd/RolePorteurDeLaRlsTest.php, qui '
        . "figent l'etat inverse et le disent dans leurs messages.",
    );
    expect(config('crm.strict_workspace_scope'))->toBeFalsy(
        'La suite tourne desormais en portee stricte : les tests qui verifient le comportement '
        . '« comme avant » ne mesurent plus ce qu ils annoncent.',
    );
});

test('A08-003 — la suite ne tourne JAMAIS dans l environnement de la production', function () {
    // ⚠️ On affirme la NEGATION, pas `=== 'testing'`.
    //
    // Mesure du 2026-08-20 : sous `/tmp/a35r/phpunit-lot11.xml`, l'environnement
    // effectif est `local` et non `testing` — cette configuration-la pose
    // `<env name="APP_ENV" value="testing"/>` SANS `force="true"`, et le
    // `APP_ENV=local` du conteneur l'emporte. C'est exactement le piege
    // documente en tete de `phpunit.xml` pour `DB_DATABASE`, qui avait failli
    // faire tomber `DROP TABLE … CASCADE` sur la base de developpement.
    //
    // Exiger `testing` ferait donc rougir ce fichier selon le banc qui le joue.
    // Ce que le constat A08-003 affirme, et tout ce qu'il affirme, c'est que la
    // suite ne tourne pas en PRODUCTION. On mesure cela, et rien d'autre.
    expect(app()->environment('production'))->toBeFalse(
        'La suite tourne en environnement de PRODUCTION. Aucun test de ce depot n a ete ecrit '
        . 'pour cela : RefreshDatabase y viderait la base servie.',
    );

    // Le fait cote production, lu dans le SEUL fichier de ce depot qui le pose :
    // l'overlay branche par `deploy-direct-ssh.yml` via COMPOSE_FILE.
    $overlay = (string) @file_get_contents(suiteProdRacine() . '/docker-compose.prod.yml');

    // TEMOIN de montage : sans l'overlay, la phrase ci-dessous ne serait
    // appuyee sur rien.
    expect(strlen($overlay))->toBeGreaterThan(
        1000,
        'Le banc ne lit pas `docker-compose.prod.yml` : la comparaison avec la production ne repose sur rien.',
    );

    $this->assertStringContainsString('APP_ENV: production', $overlay);

    // Ce test n'exige rien de plus, et c'est le point : la divergence est un
    // FAIT, pas un defaut. Ce qui etait un defaut, c'est qu'aucun test ne la
    // disait, pendant que deux autres se lisaient comme des phrases sur la
    // production.
});

// ─────────────────────────────────────────────────────────────────────────────
// A06-009 — AUCUN FICHIER DE TEST N'AFFIRME L'ÉTAT DU DRAPEAU SUR LE SERVEUR
// ─────────────────────────────────────────────────────────────────────────────
//
// 🔴 CE QUI ÉTAIT ÉCRIT, ET POURQUOI C'EST GRAVE.
//
// `tests/Feature/EtancheiteParTableTest.php` portait, mot pour mot :
// « `CRM_DB_APP_ROLE_ENABLED` reste à false — sa valeur par défaut, et sa
// valeur en production ». Or CE DÉPÔT NE PEUT PAS LE SAVOIR : la production n'y
// est pas observable, et il se contredit lui-même sur ce point exact
// (`CoverageRefreshMatrix.php` dit `=true`, `PartmanMaintenir.php` dit false, le
// même jour). Une phrase de test qui tranche à leur place fabrique une
// certitude sur le SEUL fait qui décide si les policies mordent pour de vrai —
// et le lecteur suivant s'y fiera pour ne pas re-mesurer.
//
// Ce contrôle ne juge pas quelle valeur est la bonne. Il interdit seulement
// qu'un fichier de test AFFIRME la valeur du drapeau côté serveur. Le
// conditionnel reste permis, et c'est voulu : `NeDoitPasRegresserTest.php` écrit
// « tant que … vaut false, … » et n'affirme rien — cette formulation-là décrit
// une hypothèse de lecture, pas un état du monde.

/**
 * Les fichiers de test qui parlent du drapeau. ÉNUMÉRÉS À LA MAIN, jamais
 * ramassés au `glob` : un fichier qui apparaîtrait demain doit être ajouté ici
 * par quelqu'un qui l'a relu. Un `glob` l'avalerait en silence — et une garde
 * qui grossit toute seule finit par certifier ce qu'elle n'a jamais inspecté.
 *
 * @return list<string>
 */
function a06FichiersQuiParlentDuDrapeau(): array
{
    return [
        'tests/Feature/RlsTest.php',
        'tests/Feature/EtancheiteParTableTest.php',
        'tests/Feature/NeDoitPasRegresserTest.php',
        'tests/Feature/Rgpd/RolePorteurDeLaRlsTest.php',
    ];
}

/**
 * Les tournures qui AFFIRMENT une valeur côté serveur. Volontairement étroites :
 * « 71 fois sur 71 en production » (RlsTest) et « un trou en production »
 * (EtancheiteParTableTest) sont des faits rapportés d'ailleurs, pas des
 * affirmations sur le drapeau — les rendre rouges ferait de cette garde un
 * censeur de prose au lieu d'un contrôle.
 *
 * @return list<string>
 */
function a06TournuresQuiAffirment(): array
{
    return [
        'valeur en production',
        'vaut false en production',
        'est a false en production',
        'reste a false en production',
    ];
}

/**
 * Aplatit un fichier PHP en une phrase continue et sans accents.
 *
 * Deux raisons, toutes deux payées ici : les commentaires de ce dépôt coupent
 * les phrases sur plusieurs lignes derrière des « * », de sorte qu'une simple
 * recherche de sous-chaîne ne verrait JAMAIS « sa valeur en production » ; et
 * l'accentuation varie (« à false » / « a false ») d'un fichier à l'autre.
 */
function a06Aplatir(string $contenu): string
{
    $sansCommentaire = preg_replace('/\s*\n\s*(\*|\/\/)\s*/u', ' ', $contenu) ?? $contenu;
    $uneLigne = preg_replace('/\s+/u', ' ', $sansCommentaire) ?? $sansCommentaire;

    $sansAccent = strtr(mb_strtolower($uneLigne), [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
        'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u',
        'û' => 'u', 'ü' => 'u', 'ç' => 'c',
    ]);

    return $sansAccent;
}

test('A06-009 — TEMOIN : le lecteur voit la phrase MEME coupee sur deux lignes et accentuee', function () {
    // La forme exacte qui a échappé pendant des semaines : coupée par un « * »
    // de bloc de commentaire, et accentuée.
    $fabrique = " * `CRM_DB_APP_ROLE_ENABLED` reste à false — sa valeur par défaut, et sa valeur\n"
        . " * en production —, l'application se connecte avec le rôle `axion`.\n";

    expect(str_contains(a06Aplatir($fabrique), 'valeur en production'))->toBeTrue(
        'Le lecteur ne recolle pas une phrase coupée par un bloc de commentaire : le contrôle '
        . 'ci-dessous serait vert sur le défaut même qu il est censé voir (piège documenté : '
        . '`RecursiveDirectoryIterator` et les recherches naïves ont déjà produit des verts creux ici).',
    );

    // Et il ne doit PAS rougir sur un fait rapporté qui contient les mêmes mots.
    $rapporte = " * « coverage:refresh-matrix échoue 71 fois sur 71 en production depuis l'armement ».\n";
    $touches = array_filter(
        a06TournuresQuiAffirment(),
        static fn (string $t): bool => str_contains(a06Aplatir($rapporte), $t),
    );
    expect($touches)->toBe(
        [],
        'Le contrôle rougit sur un fait rapporté (« 71 fois sur 71 en production ») : il censure la '
        . 'prose au lieu de traquer une affirmation sur le drapeau.',
    );
});

test('A06-009 — aucun fichier de test n affirme la valeur du drapeau cote serveur', function () {
    $fautifs = [];

    foreach (a06FichiersQuiParlentDuDrapeau() as $relatif) {
        $chemin = base_path($relatif);

        // TEMOIN DE MONTAGE : un fichier absent rendrait « il n'affirme rien »
        // vrai par vacuité — exactement le vert creux que cette campagne traque.
        expect(is_file($chemin))->toBeTrue(
            "Le fichier `{$relatif}` n existe plus (déplacé ? renommé ?). Tant qu il figure dans "
            . '`a06FichiersQuiParlentDuDrapeau()` sans être lisible, cette garde ne mesure RIEN. '
            . 'Geste : corriger le chemin dans la liste, ou l en retirer en connaissance de cause.',
        );

        $aplati = a06Aplatir((string) file_get_contents($chemin));

        // On ne relève que les fichiers qui NOMMENT le drapeau : ailleurs, ces
        // tournures parlent d'autre chose.
        if (! str_contains($aplati, 'crm_db_app_role_enabled')) {
            continue;
        }

        foreach (a06TournuresQuiAffirment() as $tournure) {
            if (str_contains($aplati, $tournure)) {
                $fautifs[] = $relatif . ' → « ' . $tournure . ' »';
            }
        }
    }

    expect($fautifs)->toBe(
        [],
        "Un fichier de test AFFIRME la valeur de `CRM_DB_APP_ROLE_ENABLED` sur le serveur :\n  - "
        . implode("\n  - ", $fautifs) . "\n\n"
        . 'Ce dépôt n a pas les moyens de le savoir — la production n y est pas observable, et il se '
        . 'contredit lui-même (`CoverageRefreshMatrix.php` dit `=true`, `PartmanMaintenir.php` dit '
        . "false, le même jour). Constat A06-009.\n"
        . 'Geste : dire ce qui EST mesurable — « sa valeur par défaut, et celle sous laquelle CETTE '
        . 'SUITE tourne » — et renvoyer à ce fichier pour la divergence. Le conditionnel (« tant que '
        . '… vaut false ») reste permis : il décrit une hypothèse, pas un état du monde.',
    );
});
