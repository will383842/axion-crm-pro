<?php

use App\Crm\Rgpd\SiteGdprService;
use App\Crm\Taxonomy;
use App\Services\Audit\AuditHashChain;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * 🔴 L'EFFACEMENT VENU DU SITE, REJOUÉ SOUS LE RÔLE DE PRODUCTION.
 *
 * Constaté en production le 2026-09-25 : `POST /internal/site-sync/gdpr`
 * (action `erase`) rendait 500 `gdpr_failed`, avec dans le journal :
 *
 *     new row violates row-level security policy for table "rgpd_requests"
 *
 * `journal()` était appelé APRÈS `WorkspaceContext::run()`, donc sans
 * `app.current_workspace_id`, sur une table en RLS FORCÉE. La suppression
 * business, déjà validée dans sa propre transaction, restait acquise : un
 * effacement PARTIEL — ni journal, ni opposition anti-réinsertion, ni univers
 * vivier, ni preuve d'audit. Mesuré en production : 0 journal
 * `site-sync-gdpr`, 0 opposition `gdpr_erasure_bisystem`, de tout temps.
 *
 * `SiteGdprTest` restait vert : la suite tourne sous `axion` (SUPERUSER,
 * BYPASSRLS), la RLS n'y mord pas. Ce fichier exécute l'effacement sur la
 * connexion `pgsql_app` — le rôle `axion_app`, celui de la production — avec
 * des données semées et nettoyées par le propriétaire.
 */
function rlsOwner(): Connection
{
    return DB::connection('pgsql_owner');
}

/**
 * Sème une personne dans les deux univers, par le propriétaire (écritures
 * VALIDÉES, donc visibles d'une autre connexion).
 *
 * @return array{business: string, vivier: string, companies: list<int>, email: string, key: string}
 */
function rlsSemer(): array
{
    $owner = rlsOwner();
    $email = 'zz.rls.' . Str::lower(Str::random(8)) . '@example.invalid';
    $key = hash('sha256', 'sel-site|' . $email);

    $business = (string) Str::uuid();
    $owner->table('workspaces')->insert([
        'id' => $business,
        'slug' => 'zz-gdpr-rls-' . substr($business, 0, 8),
        'name' => 'ZZ GDPR RLS',
        'settings' => '{}',
        'cost_cap_eur' => 100,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $vivier = (string) $owner->table('workspaces')->where('slug', Taxonomy::VIVIER_WORKSPACE_SLUG)->value('id');

    $company = (int) $owner->table('companies')->insertGetId([
        'workspace_id' => $business,
        'siren' => '9' . random_int(10000000, 99999999),
        'denomination' => 'ZZ GDPR RLS SAS',
        'signals' => '{}',
        'metadata' => '{}',
        'quality_score' => 0,
        'relation_type' => 'prospect',
        'lifecycle_stage' => 'nouveau',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $owner->table('contacts')->insert([
        'workspace_id' => $business,
        'company_id' => $company,
        'first_name' => 'Zoé',
        'last_name' => 'ZZ RLS',
        'email' => $email,
        'person_key' => $key,
        'sources' => '["site"]',
        'metadata' => '{}',
        'legal_basis' => 'precontractual',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $owner->table('candidates')->insert([
        'workspace_id' => $vivier,
        'person_key' => $key,
        'last_name' => 'ZZ RLS',
        'email' => $email,
        'relation_type' => 'candidat_commercial',
        'lifecycle_stage' => 'nouveau',
        'legal_basis' => 'consent',
        'consent_version' => 'careers-v2-2026-08-13',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    foreach ([[$business, 'form_submission'], [$vivier, 'application_submitted']] as [$ws, $kind]) {
        $owner->table('activities')->insert([
            'workspace_id' => $ws,
            'type' => $kind,
            'kind' => $kind,
            'occurred_at' => now(),
            'person_key' => $key,
            'external_ref' => 'site:event:' . Str::uuid(),
            'title' => 'test',
            'payload' => json_encode(['email' => $email]),
            'created_at' => now(),
        ]);
    }

    return ['business' => $business, 'vivier' => $vivier, 'companies' => [$company], 'email' => $email, 'key' => $key];
}

/** @param array{business: string, vivier: string, companies: list<int>, email: string, key: string} $s */
function rlsNettoyer(array $s): void
{
    $owner = rlsOwner();
    $owner->table('rgpd_requests')->where('subject_email', $s['email'])->delete();
    $owner->table('opt_out')->where('email_hash', hash('sha256', $s['email']))->delete();
    $owner->table('activities')->where('person_key', $s['key'])->delete();
    $owner->table('candidates')->where('person_key', $s['key'])->delete();
    $owner->table('contacts')->where('workspace_id', $s['business'])->delete();
    $owner->table('companies')->whereIn('id', $s['companies'])->delete();
    $owner->table('workspaces')->where('id', $s['business'])->delete();
}

/** Compte, par le propriétaire (qui voit tout), ce qui reste de la personne. */
function rlsReste(array $s): array
{
    $owner = rlsOwner();

    return [
        'contacts' => $owner->table('contacts')->where('workspace_id', $s['business'])->count(),
        'candidates' => $owner->table('candidates')->where('person_key', $s['key'])->count(),
        'activities' => $owner->table('activities')->where('person_key', $s['key'])->count(),
        'journaux' => $owner->table('rgpd_requests')->where('subject_email', $s['email'])->count(),
        'oppositions' => $owner->table('opt_out')->where('email_hash', hash('sha256', $s['email']))->count(),
    ];
}

/** Exécute l'effacement sous `axion_app`, comme en production. */
function rlsEffacer(array $s): array
{
    config(['crm.ingest.business_workspace' => rlsOwner()->table('workspaces')->where('id', $s['business'])->value('slug')]);
    $precedente = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_app');

    try {
        return app(SiteGdprService::class)->erase($s['key'], $s['email'], 'both');
    } finally {
        DB::connection('pgsql_app')->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
        DB::setDefaultConnection($precedente);
    }
}

// La chaîne d'audit est une chaîne de hachage partagée : une écriture VALIDÉE
// par ce test y resterait et fausserait les suites qui la vérifient. Chaque
// test la remplace et dit combien de fois elle doit être appelée.

afterEach(function () {
    DB::connection('pgsql_app')->disconnect();
    rlsOwner()->disconnect();
});

test('TÉMOIN — la connexion de ce test est bien le rôle soumis à la RLS', function () {
    // Sans ce témoin, un `pgsql_app` configuré avec le propriétaire rendrait
    // tout ce fichier vert sans rien mesurer — l'erreur même qu'il corrige.
    $role = DB::connection('pgsql_app')->selectOne(
        'SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user',
    );
    expect($role->rolsuper)->toBeFalse()
        ->and($role->rolbypassrls)->toBeFalse();
});

test('🔴 sous axion_app, l\'effacement aboutit : fiches, timeline, journal ET opposition, dans les deux univers', function () {
    // La preuve d'audit, jamais atteinte en production jusqu'ici.
    $this->mock(AuditHashChain::class)->shouldReceive('record')->once()->andReturn(1);
    $s = rlsSemer();

    try {
        expect(rlsReste($s))->toBe([
            'contacts' => 1, 'candidates' => 1, 'activities' => 2, 'journaux' => 0, 'oppositions' => 0,
        ]);

        $resultat = rlsEffacer($s);

        expect(rlsReste($s))->toBe([
            'contacts' => 0,
            'candidates' => 0,
            'activities' => 0,
            // Un journal par univers : c'est l'insertion qui levait en production.
            'journaux' => 2,
            // L'anti-réinsertion, jamais atteinte en production jusqu'ici.
            'oppositions' => 2,
        ])->and($resultat['opt_out_scopes'])->toBe(['business', 'vivier']);
    } finally {
        rlsNettoyer($s);
    }
});

test('🔴 si le journal échoue, RIEN n\'est effacé — plus jamais d\'effacement partiel', function () {
    $this->mock(AuditHashChain::class)->shouldNotReceive('record');
    $s = rlsSemer();
    // Le journal lève (contrainte violée à la volée) : la suppression de
    // l'univers business, qui partage désormais sa transaction, doit être
    // ANNULÉE avec lui.
    rlsOwner()->statement(
        "ALTER TABLE rgpd_requests ADD CONSTRAINT zz_rls_journal_refuse CHECK (subject_email <> '" . $s['email'] . "') NOT VALID",
    );

    try {
        expect(fn () => rlsEffacer($s))->toThrow(Exception::class);

        expect(rlsReste($s))->toBe([
            'contacts' => 1, 'candidates' => 1, 'activities' => 2, 'journaux' => 0, 'oppositions' => 0,
        ]);
    } finally {
        rlsOwner()->statement('ALTER TABLE rgpd_requests DROP CONSTRAINT IF EXISTS zz_rls_journal_refuse');
        rlsNettoyer($s);
    }
});
