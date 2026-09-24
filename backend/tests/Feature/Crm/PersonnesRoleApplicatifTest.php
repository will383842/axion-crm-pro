<?php

/**
 * LOT L4-C — `personnes` et `abonnements` sous le RÔLE APPLICATIF.
 *
 * ⚠️ Pourquoi ce fichier existe à part. En local et en CI, l'application se
 * connecte avec `axion` (SUPERUSER + BYPASSRLS) : la RLS n'y mord pas, et un
 * vert obtenu là ne dit RIEN de la production, où `CRM_DB_APP_ROLE_ENABLED`
 * est à true et la connexion passe par `axion_app` (mémoire
 * « RLS inerte en local, active en prod »). Ici, tout se joue sur
 * `pgsql_app` :
 *
 *   1. SANS contexte d'espace, `personnes` et `abonnements` rendent ZÉRO ligne
 *      (politique stricte, jamais la permissive de `email_audiences`) ;
 *   2. l'ingestion jouée HORS requête HTTP — comme un job de file, après le
 *      vrai crochet `Looping` qui efface le contexte entre deux jobs — écrit
 *      bien `personnes`, `abonnements` et `activities` : le service pose son
 *      propre contexte, il n'hérite de rien.
 *
 * Pas de `RefreshDatabase` : `pgsql_app` est une AUTRE session Postgres, qui ne
 * voit pas une transaction non validée. Le jeu d'essai est écrit en
 * auto-commit par le propriétaire, et `l4cRoleNettoyer()` est appelé dans un
 * `finally`.
 */

use App\Crm\Ingest\SiteSyncEvent;
use App\Crm\Ingest\SiteSyncIngestService;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

function l4cRoleEspace(): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql_owner')->table('workspaces')->insert([
        'id' => $id,
        'slug' => 'zz-l4c-role-' . substr($id, 0, 8),
        'name' => 'ZZ L4-C rôle applicatif',
        'settings' => '{}',
        'cost_cap_eur' => 0,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function l4cRoleNettoyer(string $workspaceId, string $emailHash): void
{
    $o = DB::connection('pgsql_owner');
    $o->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    foreach (['activities', 'abonnements', 'personnes'] as $table) {
        $o->table($table)->where('workspace_id', $workspaceId)->delete();
    }
    $o->table('opt_out')->where('email_hash', $emailHash)->delete();
    $o->table('workspaces')->where('id', $workspaceId)->delete();
    $o->disconnect();
    DB::connection('pgsql_app')->disconnect();
}

/**
 * @template T
 *
 * @param  callable(): T  $fn
 * @return T
 */
function l4cSousRoleApplicatif(callable $fn)
{
    config(['database.default' => 'pgsql_app']);
    DB::purge('pgsql_app');

    try {
        return $fn();
    } finally {
        config(['database.default' => 'pgsql']);
        DB::purge('pgsql_app');
    }
}

test('L4-C 11 — sans contexte d’espace, personnes et abonnements rendent ZÉRO ligne au rôle applicatif', function () {
    $ws = l4cRoleEspace();
    $hash = hash('sha256', 'zz.rls@example.invalid');

    try {
        $o = DB::connection('pgsql_owner');
        $personneId = (int) $o->table('personnes')->insertGetId([
            'workspace_id' => $ws,
            'person_key' => hash('sha256', 'l4c-rls'),
            'email' => 'zz.rls@example.invalid',
            'email_hash' => $hash,
            'premiere_source' => 'newsletter',
            'premiere_source_at' => now(),
            'legal_basis' => 'consent',
        ]);
        $o->table('abonnements')->insert([
            'workspace_id' => $ws,
            'personne_id' => $personneId,
            'canal' => 'lettre',
            'statut' => 'abonne',
            'dernier_evenement_at' => now(),
        ]);

        $app = DB::connection('pgsql_app');

        // Le rôle est bien le rôle durci : sinon ce test ne prouverait rien.
        $role = $app->selectOne('SELECT current_user AS u, (SELECT rolbypassrls FROM pg_roles WHERE rolname = current_user) AS b');
        expect($role->b)->toBeFalse();

        $app->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
        expect($app->table('personnes')->where('workspace_id', $ws)->count())->toBe(0)
            ->and($app->table('abonnements')->where('workspace_id', $ws)->count())->toBe(0);

        // TÉMOIN : avec le bon contexte, la ligne est bien là.
        $app->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $ws]);
        expect($app->table('personnes')->where('workspace_id', $ws)->count())->toBe(1)
            ->and($app->table('abonnements')->where('workspace_id', $ws)->count())->toBe(1);
        $app->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    } finally {
        l4cRoleNettoyer($ws, $hash);
    }
});

test('L4-C — ingestion jouée HORS requête HTTP (comme un job de file) sous le rôle applicatif : personnes, abonnements et activities sont écrits', function () {
    $ws = l4cRoleEspace();
    $slug = (string) DB::connection('pgsql_owner')->table('workspaces')->where('id', $ws)->value('slug');
    $email = 'zz.job@example.invalid';
    $hash = hash('sha256', $email);

    config([
        'crm.ingest.personnes_enabled' => true,
        'crm.ingest.business_workspace' => $slug,
    ]);

    try {
        l4cSousRoleApplicatif(function () use ($email): void {
            // Le VRAI crochet de la file : il efface le contexte d'espace entre
            // deux jobs. Le service doit poser le sien.
            event(new Looping('sync', 'default'));

            $service = app(SiteSyncIngestService::class);

            $service->ingest(SiteSyncEvent::fromArray([
                'schema_version' => 1,
                'event_id' => (string) Str::uuid(),
                'event_type' => 'newsletter_optin',
                'occurred_at' => '2026-09-01T10:00:00Z',
                'subject_ref' => 'site:newsletter_subscriber:l4c-job',
                'person' => ['person_key' => hash('sha256', 'l4c-job'), 'email' => $email],
                'consent' => ['version' => 'newsletter-v1', 'at' => '2026-09-01T10:00:00Z', 'text_ref' => 'newsletter-double-optin'],
            ]));

            $service->ingest(SiteSyncEvent::fromArray([
                'schema_version' => 1,
                'event_id' => (string) Str::uuid(),
                'event_type' => 'newsletter_optout',
                'occurred_at' => '2026-09-02T10:00:00Z',
                'subject_ref' => 'site:newsletter_subscriber:l4c-job',
                'person' => ['person_key' => hash('sha256', 'l4c-job'), 'email' => $email],
            ]));
        });

        // Relu par le PROPRIÉTAIRE : on mesure ce que le rôle applicatif a
        // réellement écrit, pas ce qu'il croit avoir écrit.
        $o = DB::connection('pgsql_owner');
        expect($o->table('personnes')->where('workspace_id', $ws)->count())->toBe(1)
            ->and($o->table('abonnements')->where('workspace_id', $ws)->value('statut'))->toBe('desabonne')
            ->and($o->table('activities')->where('workspace_id', $ws)->where('subject_type', 'personne')->count())->toBe(2)
            ->and($o->table('opt_out')->where('email_hash', $hash)->pluck('scope')->all())->toBe(['lettre']);
    } finally {
        l4cRoleNettoyer($ws, $hash);
    }
});
