<?php

/**
 * NR-23 — une audience factice donne le même nombre de fiches avant et après un lot.
 * NR-08 — les tâches planifiées qui lisent des fiches, lancées SOUS LE RÔLE DE
 * PRODUCTION (`pgsql_app`, RLS forcée) avec deux espaces factices, voient les
 * lignes de LEUR espace : ni zéro (silence), ni celles de l’autre espace.
 *
 * Périmètre de NR-08 (honnête) : les tâches qui peuvent tourner HORS RÉSEAU et
 * sans effet de bord sur une base jetable. Le relevé (plan 21 § 2) en compte 10
 * qui touchent les fiches ; celles qui appellent un service extérieur
 * (collecte, INSEE, presse…) ne sont pas jouables ici sans simulateur dédié : elles
 * sont listées dans `instantanes/nr08-taches-sous-rls.json` avec leur statut
 * (« jouee » ou « non jouee : motif »), pour que S7 les traite une à une.
 * Gardes existantes rattachées : `RafraichissementNocturneSousRlsTest`.
 *
 * Données semées et nettoyées par le PROPRIÉTAIRE (`pgsql_owner`), comme le test
 * existant : la connexion `pgsql_app` est celle de la production.
 */

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\NonRegression\EspacesFactices;
use Tests\Feature\NonRegression\Instantane;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function nr08Proprio(): Connection
{
    return DB::connection('pgsql_owner');
}

/** @return array{a: string, b: string} */
function nr08Espaces(): array
{
    $ids = [];
    foreach (['a' => 'Espace A', 'b' => 'Espace B'] as $cle => $nom) {
        $ids[$cle] = (string) Str::uuid();
        nr08Proprio()->table('workspaces')->insert([
            'id' => $ids[$cle], 'slug' => 'nr08-' . $cle . '-' . Str::lower(Str::random(6)), 'name' => $nom,
            'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $ids;
}

function nr08Semer(string $espace, int $nombre, string $region = '84'): int
{
    $o = nr08Proprio();
    for ($i = 1; $i <= $nombre; $i++) {
        $o->table('companies')->insert([
            'workspace_id' => $espace, 'siren' => EspacesFactices::sirenInvalide(),
            'denomination' => 'NR08 ' . $i, 'region_code' => $region, 'postcode' => '69001',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return (int) $o->table('email_audiences')->insertGetId([
        'workspace_id' => $espace, 'name' => 'NR08 audience',
        'criteria' => json_encode(['all' => [['field' => 'region_code', 'op' => 'eq', 'value' => $region]]]),
        'is_active' => true, 'auto_refresh' => true, 'member_count' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function nr08Nettoyer(array $espaces): void
{
    $o = nr08Proprio();
    foreach ($espaces as $e) {
        $o->table('audience_members')->where('workspace_id', $e)->delete();
        $o->table('email_audiences')->where('workspace_id', $e)->delete();
        $o->table('business_events')->where('workspace_id', $e)->delete();
        $o->table('companies')->where('workspace_id', $e)->delete();
        $o->table('workspaces')->where('id', $e)->delete();
    }
}

/** Joue une commande planifiée SOUS LE RÔLE DE PRODUCTION. */
function nr08SousRoleProduction(callable $f): mixed
{
    $precedente = DB::getDefaultConnection();
    try {
        DB::setDefaultConnection('pgsql_app');

        return $f();
    } finally {
        DB::setDefaultConnection($precedente);
        DB::connection('pgsql_app')->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    }
}

afterEach(function () {
    DB::connection('pgsql_app')->disconnect();
    nr08Proprio()->disconnect();
});

test('NR-23 une audience factice compte le même nombre de fiches pour chaque espace (photo de référence)', function () {
    $e = nr08Espaces();
    try {
        $audA = nr08Semer($e['a'], 3);
        $audB = nr08Semer($e['b'], 2);
        // Une fiche d’une AUTRE région : ne doit compter nulle part.
        nr08Semer($e['b'], 1, '93');

        // Sur la connexion du PROPRIÉTAIRE : la connexion par défaut du test est dans
        // une transaction ouverte, dont les verrous bloqueraient le nettoyage.
        foreach ($e as $espace) {
            $precedente = DB::getDefaultConnection();
            try {
                DB::setDefaultConnection('pgsql_owner');
                expect(Artisan::call('audiences:full-refresh', ['--workspace' => $espace]))->toBe(0);
            } finally {
                DB::setDefaultConnection($precedente);
            }
        }
        $o = nr08Proprio();

        Instantane::verifier('nr23-audience', [
            'espace_a' => (int) $o->table('email_audiences')->where('id', $audA)->value('member_count'),
            'espace_b' => (int) $o->table('email_audiences')->where('id', $audB)->value('member_count'),
            'membres_espace_a' => $o->table('audience_members')->where('audience_id', $audA)->count(),
            'membres_espace_b' => $o->table('audience_members')->where('audience_id', $audB)->count(),
        ]);
    } finally {
        nr08Nettoyer($e);
    }
});

test('NR-08 sous le rôle de production, le rafraîchissement des audiences voit les fiches de CHAQUE espace, et seulement les siennes', function () {
    $e = nr08Espaces();
    try {
        $audA = nr08Semer($e['a'], 3);
        $audB = nr08Semer($e['b'], 2);

        $code = nr08SousRoleProduction(fn () => Artisan::call('audiences:full-refresh'));
        $o = nr08Proprio();

        expect($code)->toBe(0)
            ->and((int) $o->table('email_audiences')->where('id', $audA)->value('member_count'))->toBe(3)
            ->and((int) $o->table('email_audiences')->where('id', $audB)->value('member_count'))->toBe(2)
            ->and($o->table('email_audiences')->where('id', $audA)->value('refreshed_at'))->not->toBeNull();
    } finally {
        nr08Nettoyer($e);
    }
});

test('NR-08 témoin : le rôle de production ne contourne PAS la RLS (sans contexte d’espace, zéro ligne)', function () {
    $e = nr08Espaces();
    try {
        nr08Semer($e['a'], 2);

        $vues = nr08SousRoleProduction(fn () => DB::table('companies')->where('denomination', 'like', 'NR08 %')->count());

        expect($vues)->toBe(0)
            ->and(nr08Proprio()->table('companies')->where('denomination', 'like', 'NR08 %')->count())->toBe(2);
    } finally {
        nr08Nettoyer($e);
    }
});

test('NR-08 CONSTAT : la sonde « non diffusibles » (06:20) est AVEUGLE sous le rôle de production (à corriger par S7)', function () {
    // ⚠️ Défaut constaté par R0, NON corrigé ici (le filet constate). Sous `pgsql_app`
    // (RLS forcée), `runWithoutScope()` « ne lève pas la RLS » (WorkspaceContext) :
    // la sonde ne voit AUCUNE fiche et sort en succès (code 0) alors que deux
    // fiches « [ND] » existent. Aujourd’hui la production tourne avec
    // `CRM_DB_APP_ROLE_ENABLED=false`, donc la sonde voit : le risque devient réel
    // le jour où S7 arme le rôle. Quand S7 la corrigera, ce test rougira (code 1) :
    // c’est voulu — S7 le mettra à jour avec « Filet modifié : pourquoi ».
    $e = nr08Espaces();
    try {
        foreach ($e as $espace) {
            nr08Proprio()->table('companies')->insert([
                'workspace_id' => $espace, 'siren' => EspacesFactices::sirenInvalide(), 'denomination' => '[ND]',
                'discovery_source' => 'insee', 'signals' => '{}', 'metadata' => '{}', 'field_origins' => '{}',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Témoin : avec le rôle propriétaire, la même sonde CRIE (code 1).
        $precedente = DB::getDefaultConnection();
        try {
            DB::setDefaultConnection('pgsql_owner');
            $codeProprietaire = Artisan::call('crm:sonde-non-diffusibles');
        } finally {
            DB::setDefaultConnection($precedente);
        }
        $codeProduction = nr08SousRoleProduction(fn () => Artisan::call('crm:sonde-non-diffusibles'));

        expect($codeProprietaire)->toBe(1)
            ->and($codeProduction)->toBe(0);
    } finally {
        nr08Nettoyer($e);
    }
});

test('NR-08 sous le rôle de production, la relance des effets d’opposition termine ceux de CHAQUE espace, et seulement eux', function () {
    $e = nr08Espaces();
    $o = nr08Proprio();
    $hashes = [];
    try {
        foreach (['a', 'b'] as $cle) {
            $hashes[$cle] = hash('sha256', "nr08-{$cle}@exemple.invalid");
            $lien = $o->table('envoi_liens_desinscription')->insertGetId([
                'workspace_id' => $e[$cle], 'reference' => (string) Str::uuid(), 'empreinte_adresse' => $hashes[$cle], 'portee' => 'business',
            ]);
            $o->table('envoi_oppositions')->insert([
                'workspace_id' => $e[$cle], 'lien_id' => $lien, 'empreinte_adresse' => $hashes[$cle], 'canal' => 'one_click',
            ]);
        }

        $code = nr08SousRoleProduction(fn () => Artisan::call('envoi:relancer-effets-opposition'));

        expect($code)->toBe(0);
        foreach (['a', 'b'] as $cle) {
            expect($o->table('envoi_oppositions')->where('workspace_id', $e[$cle])->whereNotNull('effets_termines_le')->count())->toBe(1);
            // Un seul événement sortant, pour SON adresse (aucun mélange entre espaces).
            expect($o->table('crm_outbound_events')->where('email_hash', $hashes[$cle])->count())->toBe(1);
        }
    } finally {
        // Les triggers d'ajout seul sont suspendus le temps du nettoyage de CES lignes de test.
        $o->statement("SET session_replication_role = 'replica'");
        $o->table('crm_outbound_events')->whereIn('email_hash', array_values($hashes))->delete();
        $o->table('envoi_oppositions')->whereIn('workspace_id', array_values($e))->delete();
        $o->table('envoi_liens_desinscription')->whereIn('workspace_id', array_values($e))->delete();
        $o->statement("SET session_replication_role = 'origin'");
        nr08Nettoyer($e);
    }
});

test('NR-08 l’inventaire des tâches planifiées sous RLS est celui de la photo de référence', function () {
    Artisan::call('list', ['--format' => 'txt']);
    $jouees = [
        'audiences:full-refresh' => 'jouee',
        'envoi:relancer-effets-opposition' => 'jouee',
        'crm:sonde-non-diffusibles' => 'jouee : AVEUGLE sous RLS (code 0 malgre des fiches [ND]), a corriger par S7',
    ];
    $inventaire = [];
    foreach (app(Schedule::class)->events() as $evenement) {
        $nom = trim((string) preg_replace("/^.*?['\"]?artisan['\"]?\s+/", '', (string) $evenement->command));
        $base = explode(' ', $nom)[0];
        if ($base === '' || str_starts_with($base, 'crm:planificateur')) {
            continue;
        }
        $inventaire[$base] = $jouees[$base] ?? 'non jouee : a rejouer par S7 (service exterieur ou donnees lourdes)';
    }
    ksort($inventaire);

    Instantane::verifier('nr08-taches-sous-rls', $inventaire);
});
