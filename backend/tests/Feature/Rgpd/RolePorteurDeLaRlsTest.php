<?php

use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * F36-007 — AVEC QUEL RÔLE LA SUITE TOURNE, ET SI LA RLS Y EST ACTIVE.
 *
 * ⚠️ CE FICHIER NE RÉPARE RIEN, ET NE DOIT RIEN RÉPARER. Le constat F36-007 est
 * classé « conception » par l'audit : la décision de brancher l'application sur
 * le rôle non-privilégié appartient à l'exploitation, pas à un correctif de
 * code. Ce que ce fichier fait, et fait seulement, c'est ÉCRIRE L'ÉTAT NOIR SUR
 * BLANC et le FIGER — pour qu'on ne puisse plus lire « 55 policies RLS » dans un
 * schéma et en conclure que quoi que ce soit est protégé.
 *
 * ── L'ÉTAT, MESURÉ le 2026-08-20 sur `axion_crm_test_lot7` ──────────────────
 *
 *     SELECT rolname, rolsuper, rolbypassrls FROM pg_roles;
 *       axion      | t | t     ← le rôle de la connexion PAR DÉFAUT
 *       axion_app  | f | f     ← le rôle applicatif, jamais utilisé par défaut
 *
 *     SELECT count(*) FROM pg_policies WHERE schemaname='public';  →  55
 *
 * La connexion par défaut — celle par laquelle passent l'application ET
 * l'écrasante majorité de cette suite de tests — utilise `axion`, qui est
 * SUPERUSER **et** BYPASSRLS. Pour lui, Postgres n'évalue AUCUNE policy, même
 * en `FORCE ROW LEVEL SECURITY`. Les 55 policies posées sur 55 tables sont donc
 * INERTES sur ce chemin-là. Elles sont correctes ; elles ne sont pas armées.
 *
 * ── CE QUE CELA VEUT DIRE POUR LES AUTRES TESTS ─────────────────────────────
 *
 * Un test de cloisonnement qui lit par la connexion par défaut ne prouve RIEN
 * sur la RLS : il mesure au mieux le `WHERE` que le code applicatif a bien voulu
 * écrire. C'est pour cette raison que `RlsTest`, `EtancheiteParTableTest` et
 * `CloisonnementJournauxVerificationEmailTest` passent tous par `pgsql_app`.
 * Ce fichier est la démonstration à laquelle ils renvoient.
 *
 * ── COMMENT CE FIGEAGE SE PÉRIME ────────────────────────────────────────────
 *
 * Le jour où `CRM_DB_APP_ROLE_ENABLED` passe à true et où la connexion par
 * défaut change de rôle, les tests ci-dessous ROUGISSENT — avec, dans leur
 * message, ce qu'il faut faire. C'est voulu : une bonne nouvelle doit obliger à
 * revenir ici, sans quoi la description périmée survivrait à la réalité.
 */
function rlsRoleOwner(): Connection
{
    return DB::connection('pgsql_owner');
}

function rlsRoleApp(): Connection
{
    return DB::connection('pgsql_app');
}

afterEach(function () {
    rlsRoleApp()->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    rlsRoleApp()->disconnect();
    rlsRoleOwner()->disconnect();
});

// ─────────────────────────────────────────────────────────────────────────────
// 1. LE FAIT, ÉCRIT : quel rôle, et quels attributs
// ─────────────────────────────────────────────────────────────────────────────

test('la connexion PAR DEFAUT de la suite utilise un role SUPERUSER et BYPASSRLS', function () {
    // Le rôle réellement connecté, demandé à Postgres — pas celui que la
    // configuration prétend. `current_user` est ce qui décide de la RLS.
    $roleConnecte = (string) DB::scalar('SELECT current_user');
    $roleConfigure = (string) config('database.connections.pgsql.username');

    expect($roleConnecte)->toBe($roleConfigure);

    $attributs = DB::selectOne(
        'SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = ?',
        [$roleConnecte],
    );

    // TÉMOIN — la ligne existe. Un `selectOne` qui rend null ferait passer les
    // assertions suivantes sur des propriétés d'un objet inexistant.
    expect($attributs)->not->toBeNull();

    // ⚠️ Ces deux assertions CERTIFIENT UN DÉFAUT. Elles ne demandent pas qu'il
    // soit réparé, elles interdisent qu'il change en silence.
    expect((bool) $attributs->rolsuper)->toBeTrue(
        "F36-007 A CHANGE D'ETAT : le role de la connexion par defaut n'est plus SUPERUSER. "
        . 'Si c est voulu (CRM_DB_APP_ROLE_ENABLED passe a true), reecrire ce fichier : '
        . 'la RLS est desormais ARMEE sur le chemin par defaut, et tous les tests qui '
        . 'supposaient le contraire doivent etre relus.',
    );
    expect((bool) $attributs->rolbypassrls)->toBeTrue(
        "F36-007 A CHANGE D'ETAT : le role de la connexion par defaut ne contourne plus la RLS.",
    );
});

test('le role applicatif existe et lui, ne contourne PAS la RLS — la barriere est prete, pas armee', function () {
    // TÉMOIN INVERSE de tout ce fichier : si `axion_app` était lui aussi
    // superutilisateur, « la RLS est inerte » ne dirait rien de la RLS mais
    // seulement de la base d'essai, et les suites qui passent par `pgsql_app`
    // ne prouveraient rien non plus.
    $roleApp = (string) config('database.connections.pgsql_app.username');

    $attributs = rlsRoleOwner()->selectOne(
        'SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = ?',
        [$roleApp],
    );

    expect($attributs)->not->toBeNull(
        'Le role applicatif n existe pas dans cette base : aucun test de RLS de ce depot ne prouve quoi que ce soit.',
    );
    expect((bool) $attributs->rolsuper)->toBeFalse()
        ->and((bool) $attributs->rolbypassrls)->toBeFalse();

    // Et il n'est PAS celui de la connexion par défaut : c'est tout le problème.
    expect($roleApp)->not->toBe((string) config('database.connections.pgsql.username'));
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. LA CONSÉQUENCE, MESURÉE : les policies existent et ne mordent pas
// ─────────────────────────────────────────────────────────────────────────────

test('les policies RLS sont nombreuses, en FORCE — et INERTES sur le chemin par defaut', function () {
    $policies = (int) DB::scalar(
        "SELECT count(*) FROM pg_policies WHERE schemaname = current_schema()",
    );
    $tablesEnForce = (int) DB::scalar(
        "SELECT count(*) FROM pg_class c
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema()
            AND c.relkind = 'r'
            AND c.relrowsecurity
            AND c.relforcerowsecurity",
    );

    // MESURÉ le 2026-08-20 : 55 policies, 55 tables en ENABLE + FORCE.
    // Le seuil est volontairement bas : ce n'est pas un compteur qu'on maintient,
    // c'est un garde-fou contre un schéma vide qui rendrait la démonstration
    // suivante vraie par vacuité.
    expect($policies)->toBeGreaterThan(40)
        ->and($tablesEnForce)->toBeGreaterThan(40);

    // La démonstration : deux espaces, une ligne chacun, AUCUN contexte posé.
    $wsA = (string) Str::uuid();
    $wsB = (string) Str::uuid();
    $now = now();

    rlsRoleOwner()->table('workspaces')->insert([
        ['id' => $wsA, 'slug' => 'zz-f36-a-' . substr($wsA, 0, 8), 'name' => 'ZZ F36 A', 'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ['id' => $wsB, 'slug' => 'zz-f36-b-' . substr($wsB, 0, 8), 'name' => 'ZZ F36 B', 'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
    ]);

    try {
        rlsRoleOwner()->table('companies')->insert([
            ['workspace_id' => $wsA, 'siren' => str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT), 'denomination' => 'ZZ F36 A', 'signals' => '{}', 'metadata' => '{}', 'quality_score' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['workspace_id' => $wsB, 'siren' => str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT), 'denomination' => 'ZZ F36 B', 'signals' => '{}', 'metadata' => '{}', 'quality_score' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // (a) LE RÔLE APPLICATIF : la policy mord. Sans contexte, il ne voit rien.
        //     C'est le TÉMOIN qui rend la ligne (b) interprétable : la policy sur
        //     `companies` est correcte et fonctionne. Ce qui suit n'est donc pas
        //     un défaut de policy, c'est un défaut de RÔLE.
        rlsRoleApp()->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
        $vuParLApplicatif = rlsRoleApp()->table('companies')->whereIn('workspace_id', [$wsA, $wsB])->count();
        expect($vuParLApplicatif)->toBe(0, 'La policy de companies ne mord meme pas sur le role applicatif : le probleme est ailleurs.');

        // (b) LA CONNEXION PAR DÉFAUT : elle voit les DEUX espaces, sans contexte,
        //     avec exactement la même policy en face d'elle. C'est F36-007, mesuré.
        $vuParDefaut = DB::table('companies')->whereIn('workspace_id', [$wsA, $wsB])->count();
        expect($vuParDefaut)->toBe(
            2,
            "F36-007 A CHANGE D'ETAT : la connexion par defaut ne voit plus les deux espaces. "
            . 'Soit la RLS a ete armee (bonne nouvelle : reecrire ce fichier), soit le semis '
            . "a echoue et cette suite ne mesure plus rien.",
        );
    } finally {
        rlsRoleOwner()->table('companies')->whereIn('workspace_id', [$wsA, $wsB])->delete();
        rlsRoleOwner()->table('tags')->whereIn('workspace_id', [$wsA, $wsB])->delete();
        rlsRoleOwner()->table('workspaces')->whereIn('id', [$wsA, $wsB])->delete();
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. LE DRAPEAU : c'est lui, et lui seul, qui arme la barrière
// ─────────────────────────────────────────────────────────────────────────────

test('le drapeau qui armerait la RLS est a OFF, et c est la seule chose qui separe les deux etats', function () {
    // `RlsTest` garde déjà l'inertie des drapeaux ; on le redit ici parce que
    // c'est LA phrase qui manque à quiconque lit « 55 policies » et se rassure :
    // l'armement n'est pas un correctif de code, c'est une variable d'environnement.
    expect(config('crm.db_app_role'))->toBeFalsy(
        'CRM_DB_APP_ROLE_ENABLED est passe a true : la RLS mord desormais sur le chemin par defaut. Reecrire ce fichier.',
    );

    // Et la connexion par défaut est bien restée celle du propriétaire historique.
    expect(config('database.connections.pgsql.username'))
        ->toBe(config('database.connections.pgsql_owner.username'));
});
