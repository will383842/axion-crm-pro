<?php

/**
 * LA REQUÊTE DES DROITS DU RÔLE APPLICATIF, EXÉCUTÉE TELLE QUELLE.
 *
 * `restore-postgres.sh` et `dr-drill.sh` (étape 5) lisent le même fichier
 * `infra/scripts/droits-role-applicatif.sql`. Ce test le joue, sans le
 * réécrire, sur la base de test migrée :
 *
 *   - base saine → AUCUNE ligne. En particulier `adresses_partagees` et
 *     `fusions_empreintes`, que le rôle applicatif ne lit que PAR COLONNES,
 *     ne sont pas déclarées illisibles (c'était le défaut : `has_table_privilege`
 *     seul y valait faux, et la restauration sortait en code 6 à chaque fois) ;
 *   - témoins, dans une transaction du propriétaire ANNULÉE à la fin : un droit
 *     accordé sur une table de clés ou sur une colonne d'empreintes sort
 *     `fuite|…` ; un droit retiré sur une table ordinaire sort `illisible|…`.
 *
 * Aucune donnée : la requête n'interroge que le catalogue.
 */

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

function drapProprio(): Connection
{
    return DB::connection('pgsql_owner');
}

function drapRole(): string
{
    return (string) config('database.connections.pgsql_app.username');
}

/** @return list<string> lignes `genre|objet`, triées */
function drapLignes(): array
{
    $chemin = (realpath(base_path('..')) ?: base_path('..')) . '/infra/scripts/droits-role-applicatif.sql';
    $sql = file_get_contents($chemin);
    expect($sql)->toBeString("Fichier introuvable : {$chemin}");
    $pdo = drapProprio()->getPdo();
    // La SEULE substitution : la variable psql `:'role'` (littéral cité).
    $requete = str_replace(":'role'", $pdo->quote(drapRole()), (string) $sql);
    expect($requete)->not->toContain(':\'', 'Une variable psql non substituée resterait dans la requête.');

    $lignes = [];
    foreach (drapProprio()->select($requete) as $ligne) {
        $ligne = (array) $ligne;
        $lignes[] = $ligne['genre'] . '|' . $ligne['nom'];
    }

    return $lignes;
}

/** Joue $temoin comme propriétaire, mesure, puis ANNULE tout. @return list<string> */
function drapSous(string $temoin): array
{
    $owner = drapProprio();
    $owner->beginTransaction();
    try {
        $owner->statement($temoin);

        return drapLignes();
    } finally {
        $owner->rollBack();
    }
}

test('témoin : le rôle applicatif existe et n est pas le propriétaire', function () {
    expect(drapRole())->not->toBe((string) config('database.connections.pgsql_owner.username'));
    expect(drapProprio()->selectOne('SELECT 1 AS ok FROM pg_roles WHERE rolname = ? AND NOT rolsuper', [drapRole()]))->not->toBeNull();
});

test('base saine : aucune table illisible, aucune clé ni empreinte lisible', function () {
    expect(drapLignes())->toBe([]);
});

test('les tables lues PAR COLONNES ne sont pas déclarées illisibles', function () {
    $role = drapRole();
    foreach (['adresses_partagees', 'fusions_empreintes'] as $table) {
        $ligne = (array) drapProprio()->selectOne(
            "SELECT has_table_privilege(?, ?, 'SELECT') AS entiere, has_any_column_privilege(?, ?, 'SELECT') AS colonnes",
            [$role, 'public.' . $table, $role, 'public.' . $table],
        );
        // Le piège que la requête doit déjouer : lecture PAR COLONNES seulement.
        expect($ligne['entiere'])->toBeFalse("{$table} : le rôle applicatif la lit en entier, le témoin ne prouve rien");
        expect($ligne['colonnes'])->toBeTrue("{$table} : le rôle applicatif n'en lit aucune colonne");
    }
    expect(drapLignes())->not->toContain('illisible|adresses_partagees');
    expect(drapLignes())->not->toContain('illisible|fusions_empreintes');
});

test('fuite : une table de clés accordée au rôle applicatif est signalée', function (string $table) {
    expect(drapSous("GRANT SELECT ON public.{$table} TO " . drapRole()))->toBe(['fuite|' . $table]);
})->with(['contacts_retires_cle', 'doublons_cle']);

test('fuite : une colonne d empreintes accordée au rôle applicatif est signalée', function (string $table, string $colonne) {
    expect(drapSous("GRANT SELECT ({$colonne}) ON public.{$table} TO " . drapRole()))->toBe(["fuite|{$table}.{$colonne}"]);
})->with([
    ['adresses_partagees', 'email_empreinte'],
    ['fusions_empreintes', 'empreinte'],
]);

test('fuite : un GRANT en masse, celui que l ancien message proposait, est signalé', function () {
    expect(drapSous('GRANT SELECT ON ALL TABLES IN SCHEMA public TO ' . drapRole()))->toBe([
        'fuite|adresses_partagees.email_empreinte',
        'fuite|contacts_retires_cle',
        'fuite|doublons_cle',
        'fuite|fusions_empreintes.empreinte',
    ]);
});

test('illisible : une table ordinaire retirée au rôle applicatif est signalée', function () {
    expect(drapSous('REVOKE SELECT ON public.companies FROM ' . drapRole()))->toBe(['illisible|companies']);
});
