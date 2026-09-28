<?php

use App\Services\Audit\AuditHashChain;
use App\Services\Rgpd\GdprErasureService;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * L'EFFACEMENT CONSOLE, REJOUÉ SOUS LE RÔLE DE PRODUCTION (relecture S8,
 * 2026-09-29).
 *
 * `GdprErasureService` efface sans contexte d'espace : il dépend du rôle
 * propriétaire, BYPASSRLS (cf. `RolePorteurDeLaRlsTest`). Sous `axion_app`,
 * la RLS forcée lui cache toutes les lignes : il ne supprimerait rien, et
 * répondait pourtant « complet ». Deux exigences :
 *
 *  - les fiches d'organisation et les fiches personnes sont effacées ESPACE PAR
 *    ESPACE, dans leur contexte : ce travail-là est fait ;
 *  - le reste de l'effacement (la timeline, ici) n'a rien vu : l'effacement ne
 *    se dit PAS complet.
 *
 * Données semées et nettoyées par le propriétaire (auto-commit).
 */
function efrProprio(): Connection
{
    return DB::connection('pgsql_owner');
}

afterEach(function () {
    DB::connection('pgsql_app')->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    DB::connection('pgsql_app')->disconnect();
    efrProprio()->disconnect();
});

test('sous axion_app, l effacement console fait le travail par espace et ne se dit PAS complet', function () {
    // La chaîne d'audit est partagée entre sessions : pas d'écriture validée ici.
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);

    $owner = efrProprio();
    $espace = (string) Str::uuid();
    $marque = substr(str_replace('-', '', $espace), 0, 8);
    $email = 'zz.rls.' . $marque . '@zz-rls.example.invalid';
    $owner->table('workspaces')->insert([
        'id' => $espace, 'slug' => 'zz-efr-' . $marque, 'name' => 'ZZ effacement RLS', 'settings' => '{}',
        'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $fiche = (int) $owner->table('companies')->insertGetId([
        'workspace_id' => $espace, 'siren' => '97' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        'denomination' => 'ZZ fiche RLS', 'email_generic' => $email, 'signals' => '{}', 'metadata' => '{}',
        'quality_score' => 0, 'relation_type' => 'prospect', 'lifecycle_stage' => 'nouveau',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $contact = (int) $owner->table('contacts')->insertGetId([
        'workspace_id' => $espace, 'company_id' => $fiche, 'last_name' => 'ZZRLS', 'email' => $email,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    // Une ligne de TIMELINE qui porte l'adresse : la partie de l'effacement
    // qui tourne sans contexte ne la voit pas sous RLS.
    $activite = (int) $owner->table('activities')->insertGetId([
        'workspace_id' => $espace, 'type' => 'form_submission', 'kind' => 'form_submission', 'occurred_at' => now(),
        'external_ref' => 'zz-efr-' . $marque, 'title' => 'ZZ', 'payload' => json_encode(['email' => $email]),
        'created_at' => now(),
    ]);

    $precedente = DB::getDefaultConnection();
    try {
        DB::setDefaultConnection('pgsql_app');
        $resultat = app(GdprErasureService::class)->erase($email);
        DB::setDefaultConnection($precedente);

        expect($owner->table('companies')->where('id', $fiche)->value('email_generic'))->toBeNull()
            ->and($owner->table('contacts')->where('id', $contact)->exists())->toBeFalse()
            ->and($resultat['complete'])->toBeFalse()
            ->and($resultat['residus'])->toHaveKey('perimetre_non_verifie_sous_rls');
    } finally {
        DB::setDefaultConnection($precedente);
        $owner->table('activities')->where('id', $activite)->delete();
        $owner->table('contacts')->where('workspace_id', $espace)->delete();
        $owner->table('contacts_retires')->where('workspace_id', $espace)->delete();
        $owner->table('companies')->where('workspace_id', $espace)->delete();
        $owner->table('opt_out')->where('email_hash', hash('sha256', $email))->delete();
        $owner->table('workspaces')->where('id', $espace)->delete();
    }
});
