<?php

use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\EtancheiteWorkspace;
use Tests\Support\SemeurTablesScopees;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * ÉTANCHÉITÉ PAR TABLE — les trois propriétés, sur CHAQUE table à workspace_id.
 *
 * `RlsTest` prouve la barrière sur DEUX tables : `companies` et `tags`. Les 53
 * autres n'étaient couvertes que par deux contrôles STRUCTURELS (« FORCE est
 * posé », « aucune policy ne garde le repli permissif »). Or une policy peut
 * être présente, en FORCE, et laisser passer — c'est exactement ce qui se
 * produit sur `email_verification_logs` (cf. plus bas). La structure ne
 * remplace pas la mesure.
 *
 * ── CE QUE CE FICHIER MESURE, EXACTEMENT ────────────────────────────────────
 *
 * La BARRIÈRE SQL, et elle seule : toutes les lectures passent par la connexion
 * `pgsql_app`, c'est-à-dire le rôle NON-PROPRIÉTAIRE `axion_app`. C'est la
 * seule connexion sur laquelle la RLS mord.
 *
 * 🔴 Ce que ce fichier NE mesure PAS : la ceinture applicative. Tant que
 * `CRM_DB_APP_ROLE_ENABLED` reste à false — sa valeur par défaut, et sa valeur
 * en production —, l'application se connecte avec le rôle `axion`, qui est
 * SUPERUSER et BYPASSRLS : les policies, même strictes et même en FORCE, sont
 * intégralement contournées. Ces tests prouvent donc que la barrière EST prête,
 * pas qu'elle est ARMÉE. L'armer est un changement de variable d'environnement,
 * et c'est `RlsTest` (section « INERTIE ») qui garde le fait qu'elle ne l'est
 * pas encore.
 */
function etancheiteOwner(): Connection
{
    return DB::connection('pgsql_owner');
}

function etancheiteApp(): Connection
{
    return DB::connection('pgsql_app');
}

/**
 * Pose (ou retire, si `null`) le contexte workspace sur la connexion applicative.
 */
function etancheitePoserContexte(?string $workspaceId): void
{
    etancheiteApp()->select('SELECT set_config(?, ?, false)', [
        'app.current_workspace_id',
        $workspaceId ?? '',
    ]);
}

/**
 * Crée deux workspaces et sème UNE ligne dans chacune des tables scopées, pour
 * chacun des deux. Rend les identifiants et de quoi tout nettoyer.
 *
 * @return array{0: string, 1: string, 2: array{user_id: string, department_code: string, region_code: string}}
 */
function etancheiteSemerDeuxUnivers(): array
{
    $owner = etancheiteOwner();

    $wsA = (string) Str::uuid();
    $wsB = (string) Str::uuid();

    foreach ([[$wsA, 'A'], [$wsB, 'B']] as [$id, $lettre]) {
        $owner->table('workspaces')->insert([
            'id' => $id,
            // ⚠️ Le préfixe « vivier » n'est pas cosmétique : `candidates` porte un
            // TRIGGER (`candidates_enforce_vivier_workspace`) qui refuse toute
            // fiche dont le workspace n'a pas un slug commençant par « vivier ».
            // Sans ce préfixe, le semis meurt sur `candidates` — et une table
            // non semée est une table non prouvée.
            'slug' => 'vivier-zz-etancheite-' . strtolower($lettre) . '-' . substr($id, 0, 8),
            'name' => 'ZZ Étanchéité ' . $lettre,
            'settings' => '{}',
            'cost_cap_eur' => 100,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $referentiels = SemeurTablesScopees::assurerReferentiels($owner);
    SemeurTablesScopees::semer($owner, $wsA, $referentiels);
    SemeurTablesScopees::semer($owner, $wsB, $referentiels);

    return [$wsA, $wsB, $referentiels];
}

afterEach(function () {
    etancheitePoserContexte(null);
    etancheiteApp()->disconnect();
    etancheiteOwner()->disconnect();
});

// ─────────────────────────────────────────────────────────────────────────────
// 1. L'INVENTAIRE — calculé, jamais recopié, et ses exclusions sont ÉCRITES
// ─────────────────────────────────────────────────────────────────────────────

test('l’inventaire des tables scopées est CALCULÉ, et ses exclusions sont motivées par écrit', function () {
    $brut = EtancheiteWorkspace::inventaireBrut(etancheiteOwner());
    $noms = array_map(static fn (object $r): string => $r->name, $brut);

    // Une liste vide ferait passer tous les contrôles suivants par vacuité.
    expect($brut)->not->toBeEmpty();

    $tables = EtancheiteWorkspace::tablesScopees(etancheiteOwner());

    // Les deux exclusions MOTIVÉES existent bien dans le schéma : une exclusion
    // qui porte sur une table disparue est un commentaire, pas une décision.
    foreach (array_keys(EtancheiteWorkspace::EXCLUSIONS_MOTIVEES) as $exclue) {
        expect($noms)->toContain($exclue)
            ->and($tables)->not->toContain($exclue);
        expect(EtancheiteWorkspace::EXCLUSIONS_MOTIVEES[$exclue])->not->toBe('');
    }

    // Les relations « absentes par construction » sont dans l'état annoncé.
    // Le jour où `audit_logs` cesse d'être partitionnée, ou
    // `coverage_matrix_cells` d'être une vue matérialisée, la garde rougit ici,
    // avant que quiconque ait à deviner pourquoi un autre test s'est mis à
    // exiger d'elles ce que la migration ne leur donne pas.
    $parNom = [];
    foreach ($brut as $relation) {
        $parNom[$relation->name] = $relation;
    }

    foreach (EtancheiteWorkspace::ABSENTES_PAR_CONSTRUCTION as $nom => $attendu) {
        expect(array_key_exists($nom, $parNom))->toBeTrue(
            "La relation « {$nom} » ne porte plus workspace_id — mettre à jour ABSENTES_PAR_CONSTRUCTION.",
        );
        expect($parNom[$nom]->relkind)->toBe(
            $attendu['relkind'],
            "« {$nom} » n'est plus de type relkind='{$attendu['relkind']}'. Motif enregistré : " . $attendu['motif'],
        );
        expect($tables)->not->toContain($nom);
    }

    // Les tables réellement contrôlées : le compte exact est affiché par le
    // message d'échec si une migration future le fait bouger.
    expect(count($tables))->toBeGreaterThanOrEqual(50);
    expect($tables)->toContain('companies', 'contacts', 'tags', 'candidates', 'health_practitioners');
});

test('les exclusions de la MIGRATION et celles des TESTS sont la même liste (une seule source de vérité)', function () {
    $migration = EtancheiteWorkspace::exclusionsDeLaMigration();

    // La migration exclut quatre noms. Les tests n'en excluent explicitement que
    // deux — les deux autres (`audit_logs`, `audit_logs_default`) sont écartées
    // par le SCAN lui-même, pas par leur nom. C'est cette asymétrie, jamais
    // écrite nulle part, qui faisait diverger `RlsTest` de la migration.
    $attendu = array_merge(
        array_keys(EtancheiteWorkspace::EXCLUSIONS_MOTIVEES),
        array_keys(EtancheiteWorkspace::ABSENTES_PAR_CONSTRUCTION),
    );

    $manquantes = array_values(array_diff($migration, $attendu));

    expect($manquantes)->toBe(
        [],
        'La migration de durcissement exclut des tables dont les tests ne savent rien : '
        . implode(', ', $manquantes)
        . '. Les déclarer dans EtancheiteWorkspace (EXCLUSIONS_MOTIVEES si c’est une décision, '
        . 'ABSENTES_PAR_CONSTRUCTION si le scan ne les voit pas) AVEC un motif écrit.',
    );

    // Symétrie : une table que les tests s'autorisent à ne pas contrôler doit
    // être connue de la migration OU absente du scan par construction.
    foreach (array_keys(EtancheiteWorkspace::EXCLUSIONS_MOTIVEES) as $exclue) {
        expect(in_array($exclue, $migration, true))->toBeTrue(
            "Les tests excluent « {$exclue} » mais la migration la durcit : l'une des deux a tort.",
        );
    }

    // Même exigence pour les tables à LIGNES GLOBALES : c'est la seule liste qui
    // autorise `workspace_id IS NULL` dans une policy. Si la migration en ajoute
    // une et que les tests l'ignorent, le contrôle « sans contexte → 0 ligne »
    // rougirait sans raison ; si les tests en ajoutent une que la migration ne
    // connaît pas, on s'autoriserait un trou.
    $globalesMigration = EtancheiteWorkspace::lignesGlobalesDeLaMigration();
    $globalesTests = array_keys(EtancheiteWorkspace::LIGNES_GLOBALES);
    sort($globalesTests);

    expect($globalesMigration)->toBe(
        $globalesTests,
        'GLOBAL_ROW_TABLES (migration) et LIGNES_GLOBALES (tests) ont divergé : '
        . implode(', ', $globalesMigration) . ' vs ' . implode(', ', $globalesTests),
    );
});

test('le semeur couvre TOUTES les tables scopées — aucune table nouvelle ne peut passer sans être semée', function () {
    $scannees = EtancheiteWorkspace::tablesScopees(etancheiteOwner());
    $semees = SemeurTablesScopees::ORDRE;

    sort($scannees);
    $semeesTriees = $semees;
    sort($semeesTriees);

    $nonSemees = array_values(array_diff($scannees, $semeesTriees));
    $semeesInconnues = array_values(array_diff($semeesTriees, $scannees));

    expect($nonSemees)->toBe(
        [],
        'Ces tables portent workspace_id mais ne sont semées par personne : ' . implode(', ', $nonSemees)
        . '. Sans ligne dedans, « aucune ligne visible sans contexte » est vrai par VACUITÉ : '
        . 'le contrôle passerait sans rien prouver. Ajouter la charge utile dans '
        . 'SemeurTablesScopees::semer() et le nom dans ::ORDRE.',
    );

    expect($semeesInconnues)->toBe(
        [],
        'Le semeur écrit dans des tables qui ne sont plus scopées : ' . implode(', ', $semeesInconnues),
    );
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. LES TROIS PROPRIÉTÉS, TABLE PAR TABLE
//
// Une seule exécution du semis alimente les deux contrôles : semer 55 tables
// deux fois coûte ~1 s, le faire par table en coûterait 55.
// ─────────────────────────────────────────────────────────────────────────────

test('SANS contexte workspace, AUCUNE table scopée ne rend la moindre ligne', function () {
    [$wsA, $wsB, $referentiels] = etancheiteSemerDeuxUnivers();

    try {
        $tables = EtancheiteWorkspace::tablesControlees(etancheiteOwner());

        // Témoin de charge : le propriétaire, lui, VOIT les lignes semées. Sans
        // ce contrôle, une base vide (semis silencieusement raté) rendrait le
        // test vert sans rien prouver.
        etancheiteOwner()->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $wsA]);
        $vides = [];
        foreach ($tables as $table) {
            if (etancheiteOwner()->table($table)->count() === 0) {
                $vides[] = $table;
            }
        }
        expect($vides)->toBe([], 'Tables restées VIDES après le semis (le contrôle serait vrai par vacuité) : ' . implode(', ', $vides));

        etancheitePoserContexte(null);

        $fuites = [];
        foreach ($tables as $table) {
            // Pour les tables à lignes globales (workspace_id NULL légitime), le
            // critère porte sur les lignes RATTACHÉES à un workspace : les
            // lignes globales, elles, restent visibles par construction — c'est
            // écrit dans EtancheiteWorkspace::LIGNES_GLOBALES, pas dissimulé ici.
            $requete = etancheiteApp()->table($table);
            if (array_key_exists($table, EtancheiteWorkspace::LIGNES_GLOBALES)) {
                $requete->whereNotNull('workspace_id');
            }

            $vues = $requete->count();
            if ($vues !== 0) {
                $fuites[] = "{$table} ({$vues} ligne(s) visibles)";
            }
        }

        expect($fuites)->toBe(
            [],
            "Sans contexte workspace, ces tables rendent des lignes au rôle applicatif : \n  - "
            . implode("\n  - ", $fuites)
            . "\nLa policy y garde un repli permissif, ou la RLS n'y est pas active.",
        );
    } finally {
        SemeurTablesScopees::nettoyer(etancheiteOwner(), [$wsA, $wsB], $referentiels);
    }
});

test('AVEC le contexte du workspace A, chaque table scopée ne rend QUE les lignes de A', function () {
    [$wsA, $wsB, $referentiels] = etancheiteSemerDeuxUnivers();

    try {
        $tables = EtancheiteWorkspace::tablesControlees(etancheiteOwner());
        etancheitePoserContexte($wsA);

        $muettes = [];
        $fuites = [];

        foreach ($tables as $table) {
            $requete = etancheiteApp()->table($table);
            if (array_key_exists($table, EtancheiteWorkspace::LIGNES_GLOBALES)) {
                // Les lignes globales sont visibles depuis tous les workspaces :
                // c'est leur raison d'être. On juge donc les lignes rattachées.
                $requete->whereNotNull('workspace_id');
            }

            /** @var list<string> $vus */
            $vus = $requete->pluck('workspace_id')->all();

            if ($vus === []) {
                // Aussi grave que la fuite inverse : une table qui ne rend RIEN
                // avec le bon contexte casse l'application sans le dire.
                $muettes[] = $table;

                continue;
            }

            $distincts = array_values(array_unique(array_map('strval', $vus)));
            if ($distincts !== [$wsA]) {
                $fuites[] = $table . ' → ' . implode(', ', $distincts);
            }
        }

        expect($muettes)->toBe([], 'Tables INVISIBLES malgré le bon contexte : ' . implode(', ', $muettes));
        expect($fuites)->toBe(
            [],
            "Tables rendant des lignes d'un AUTRE workspace que celui du contexte : \n  - "
            . implode("\n  - ", $fuites),
        );
    } finally {
        SemeurTablesScopees::nettoyer(etancheiteOwner(), [$wsA, $wsB], $referentiels);
    }
});

test('toutes les tables scopées portent ENABLE + FORCE ROW LEVEL SECURITY', function () {
    $manquantes = [];

    foreach (EtancheiteWorkspace::inventaireBrut(etancheiteOwner()) as $relation) {
        if ($relation->relkind !== 'r' || $relation->relispartition) {
            continue;
        }
        if (array_key_exists($relation->name, EtancheiteWorkspace::EXCLUSIONS_MOTIVEES)) {
            continue;
        }
        if (! $relation->relrowsecurity || ! $relation->relforcerowsecurity) {
            $manquantes[] = $relation->name
                . ' (enable=' . var_export((bool) $relation->relrowsecurity, true)
                . ', force=' . var_export((bool) $relation->relforcerowsecurity, true) . ')';
        }
    }

    expect($manquantes)->toBe([], 'Tables scopées sans ENABLE + FORCE ROW LEVEL SECURITY : ' . implode(', ', $manquantes));
});

test('aucune policy ne rouvre un repli permissif — y compris sous la forme COALESCE', function () {
    // ⚠️ Le détecteur d'origine (RlsTest) cherchait `qual LIKE '%IS NULL%'`.
    // Il ne voyait donc PAS le repli écrit
    // `COALESCE(NULLIF(current_setting(...), ''), workspace_id::text)`, qui a
    // rigoureusement le même effet : sans contexte, le prédicat est toujours
    // vrai. Un détecteur écrit sur la FORME d'un repli en rate les autres formes.
    $fautives = etancheiteOwner()->select(
        "SELECT tablename, policyname, qual
           FROM pg_policies
          WHERE schemaname = current_schema()
            AND qual LIKE '%current_setting%'
            AND (qual LIKE '%IS NULL%' OR qual LIKE '%COALESCE%')
            AND tablename <> 'llm_use_cases'",
    );

    // `llm_use_cases` est la seule table à lignes globales (workspace_id NULL
    // légitime) : sa policy garde la branche `workspace_id IS NULL`, jamais la
    // branche `current_setting(...) IS NULL`.
    $noms = array_map(static fn (object $r): string => $r->tablename . '.' . $r->policyname, $fautives);

    $connues = [];
    $inconnues = [];
    foreach ($fautives as $policy) {
        $etiquette = $policy->tablename . '.' . $policy->policyname;
        if (array_key_exists($policy->tablename, EtancheiteWorkspace::DEFAUTS_CONNUS)) {
            $connues[] = $etiquette;
        } else {
            $inconnues[] = $etiquette;
        }
    }

    expect($inconnues)->toBe(
        [],
        "Policies conservant un repli « pas de contexte ⇒ je vois tout » : \n  - " . implode("\n  - ", $inconnues),
    );

    // TÉMOIN — le détecteur SAIT voir la forme COALESCE : il trouve bel et bien
    // la policy fautive de `email_verification_logs`. Sans ce contrôle, un
    // détecteur devenu aveugle (motif mal écrit, colonne renommée) rendrait la
    // liste vide et le test vert, exactement comme l'ancien.
    expect(in_array('email_verification_logs.email_verif_workspace_isolation', $connues, true))->toBeTrue(
        'Le détecteur ne trouve plus la policy permissive connue de email_verification_logs. '
        . 'Soit elle a été CORRIGÉE — auquel cas retirer la table de EtancheiteWorkspace::DEFAUTS_CONNUS '
        . 'et supprimer le test « DÉFAUT CONNU » —, soit le détecteur est devenu AVEUGLE, '
        . "et c'est exactement le défaut qu'il remplace.",
    );
    expect($noms)->toHaveCount(count($connues) + count($inconnues));
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. DÉFAUT CONNU, MESURÉ, ÉPINGLÉ
//
// Cette section n'est pas une exemption : c'est une dette datée dont la
// correction FAIT ROUGIR le test. On ne peut pas la corriger sans venir ici.
// ─────────────────────────────────────────────────────────────────────────────

test('DÉFAUT CONNU — email_verification_logs fuit SANS contexte (à corriger par une migration)', function () {
    expect(EtancheiteWorkspace::DEFAUTS_CONNUS)->toHaveKey('email_verification_logs');

    [$wsA, $wsB, $referentiels] = etancheiteSemerDeuxUnivers();

    try {
        etancheitePoserContexte(null);
        $vues = etancheiteApp()->table('email_verification_logs')->count();

        expect($vues)->toBeGreaterThan(
            0,
            "LE DÉFAUT EST CORRIGÉ — et c'est une bonne nouvelle. Retirer maintenant "
            . "« email_verification_logs » de EtancheiteWorkspace::DEFAUTS_CONNUS pour qu'elle "
            . 'rentre dans le contrôle général, puis supprimer ce test.',
        );

        // La cause, nommée : la policy permissive héritée de 2026_05_19_000001 a
        // survécu au durcissement parce que son nom est RACCOURCI
        // (`email_verif_…` et non `email_verification_logs_…`), et que la
        // migration ne fait un DROP que sur le nom canonique.
        $survivante = etancheiteOwner()->selectOne(
            "SELECT policyname, qual FROM pg_policies
              WHERE schemaname = current_schema()
                AND tablename = 'email_verification_logs'
                AND policyname = 'email_verif_workspace_isolation'",
        );

        expect($survivante)->not->toBeNull()
            ->and($survivante->qual)->toContain('COALESCE');
    } finally {
        SemeurTablesScopees::nettoyer(etancheiteOwner(), [$wsA, $wsB], $referentiels);
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. TÉMOINS — une garde ne vaut que si elle SAIT rougir
// ─────────────────────────────────────────────────────────────────────────────

test('TÉMOIN — la sonde « 0 ligne sans contexte » détecte réellement une barrière absente', function () {
    // Sans ce témoin, un `count()` qui rendrait toujours 0 (droits manquants,
    // table vide, connexion muette) ferait passer les 55 contrôles ci-dessus
    // sans rien prouver. On fabrique donc une table sur laquelle la barrière est
    // d'abord posée, puis RETIRÉE, et on vérifie que la sonde change d'avis.
    $owner = etancheiteOwner();
    $ws = (string) Str::uuid();
    $role = (string) config('database.connections.pgsql_app.username');

    $owner->statement('DROP TABLE IF EXISTS zz_temoin_sonde');
    $owner->statement('CREATE TABLE zz_temoin_sonde (workspace_id uuid NOT NULL)');

    try {
        $owner->statement("GRANT SELECT ON zz_temoin_sonde TO {$role}");
        $owner->table('zz_temoin_sonde')->insert([['workspace_id' => $ws], ['workspace_id' => (string) Str::uuid()]]);

        $owner->statement('ALTER TABLE zz_temoin_sonde ENABLE ROW LEVEL SECURITY');
        $owner->statement(
            "CREATE POLICY zz_temoin_sonde_isolation ON zz_temoin_sonde FOR ALL
             USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))",
        );

        etancheitePoserContexte(null);
        expect(etancheiteApp()->table('zz_temoin_sonde')->count())->toBe(0, 'Barrière posée : la sonde doit voir 0.');

        etancheitePoserContexte($ws);
        expect(etancheiteApp()->table('zz_temoin_sonde')->count())->toBe(1, 'Contexte posé : la sonde doit voir la ligne du workspace.');

        // On RETIRE la barrière : la sonde DOIT changer d'avis.
        $owner->statement('ALTER TABLE zz_temoin_sonde DISABLE ROW LEVEL SECURITY');
        etancheitePoserContexte(null);
        expect(etancheiteApp()->table('zz_temoin_sonde')->count())->toBe(
            2,
            'La sonde ne détecte PAS une RLS désactivée : les 55 contrôles ci-dessus ne prouvent rien.',
        );
    } finally {
        $owner->statement('DROP TABLE IF EXISTS zz_temoin_sonde');
    }
});

test('TÉMOIN — FORCE ROW LEVEL SECURITY change bien le comportement du PROPRIÉTAIRE', function () {
    // Ce que FORCE achète, et rien d'autre : sans lui, le PROPRIÉTAIRE d'une
    // table n'est pas soumis à ses propres policies. C'est invisible aujourd'hui
    // parce que le propriétaire (`axion`) est SUPERUSER, donc BYPASSRLS de toute
    // façon ; FORCE protège le jour où il cessera de l'être. Ce témoin le
    // démontre sur un rôle propriétaire jetable NON superutilisateur.
    $owner = etancheiteOwner();
    $ws = (string) Str::uuid();

    $owner->statement('DROP TABLE IF EXISTS zz_temoin_force');
    $owner->statement('DROP ROLE IF EXISTS zz_temoin_proprio');

    try {
        $owner->statement('CREATE ROLE zz_temoin_proprio NOLOGIN NOSUPERUSER NOBYPASSRLS');
        $owner->statement('GRANT CREATE, USAGE ON SCHEMA public TO zz_temoin_proprio');
        $owner->statement('SET ROLE zz_temoin_proprio');
        $owner->statement('CREATE TABLE zz_temoin_force (workspace_id uuid NOT NULL)');
        $owner->table('zz_temoin_force')->insert([['workspace_id' => $ws], ['workspace_id' => (string) Str::uuid()]]);
        $owner->statement('ALTER TABLE zz_temoin_force ENABLE ROW LEVEL SECURITY');
        $owner->statement(
            "CREATE POLICY zz_temoin_force_isolation ON zz_temoin_force FOR ALL
             USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))",
        );
        $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);

        // ENABLE seul : le propriétaire voit TOUT, malgré la policy stricte.
        expect($owner->table('zz_temoin_force')->count())->toBe(
            2,
            'Sans FORCE, le propriétaire devrait échapper à sa propre policy.',
        );

        $owner->statement('ALTER TABLE zz_temoin_force FORCE ROW LEVEL SECURITY');
        expect($owner->table('zz_temoin_force')->count())->toBe(
            0,
            'Avec FORCE, le propriétaire doit être soumis à sa policy — sans contexte, il ne voit rien.',
        );
    } finally {
        $owner->statement('RESET ROLE');
        $owner->statement('DROP TABLE IF EXISTS zz_temoin_force');
        $owner->statement('REVOKE CREATE, USAGE ON SCHEMA public FROM zz_temoin_proprio');
        $owner->statement('DROP ROLE IF EXISTS zz_temoin_proprio');
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// 5. LE CAS NOMMÉ : health_practitioners
// ─────────────────────────────────────────────────────────────────────────────

test('health_practitioners : le commentaire de 2026_07_04 annonce une policy PERMISSIVE, le durcissement l’a bien rattrapée', function () {
    // La migration `2026_07_04_000001_create_health_practitioners` porte encore
    // le commentaire « RLS — même politique permissive-si-non-défini que les
    // autres tables scoped » et créait une policy à triple repli. La
    // reconnaissance CONCLUAIT qu'elle était rattrapée par le durcissement L0.
    // Voici la mesure, pas la déduction — et elle porte sur des données de
    // SANTÉ (art. 9 RGPD : nom, prénom, spécialité, RPPS, adresse).
    $relation = etancheiteOwner()->selectOne(
        "SELECT relrowsecurity, relforcerowsecurity FROM pg_class
          WHERE oid = 'health_practitioners'::regclass",
    );

    expect($relation->relrowsecurity)->toBeTruthy()
        ->and($relation->relforcerowsecurity)->toBeTruthy();

    $policies = etancheiteOwner()->select(
        "SELECT policyname, qual, with_check FROM pg_policies
          WHERE schemaname = current_schema() AND tablename = 'health_practitioners'",
    );

    // UNE seule policy, stricte, sans repli — le triple repli d'origine a bien
    // été remplacé (et non simplement doublé, comme sur email_verification_logs).
    expect($policies)->toHaveCount(1)
        ->and($policies[0]->policyname)->toBe('health_practitioners_workspace_isolation')
        ->and($policies[0]->qual)->not->toContain('COALESCE')
        ->and($policies[0]->qual)->not->toContain('IS NULL')
        ->and($policies[0]->with_check)->not->toBeNull();

    // Et la mesure de bout en bout, sur une ligne réelle.
    [$wsA, $wsB, $referentiels] = etancheiteSemerDeuxUnivers();

    try {
        etancheitePoserContexte(null);
        expect(etancheiteApp()->table('health_practitioners')->count())->toBe(0);

        etancheitePoserContexte($wsA);
        $vus = etancheiteApp()->table('health_practitioners')->pluck('workspace_id')->all();
        expect(array_values(array_unique(array_map('strval', $vus))))->toBe([$wsA]);
    } finally {
        SemeurTablesScopees::nettoyer(etancheiteOwner(), [$wsA, $wsB], $referentiels);
    }
});
