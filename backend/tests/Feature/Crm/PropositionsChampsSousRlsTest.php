<?php

/**
 * FILE DE PROPOSITIONS (N13) REJOUÉE SOUS LE RÔLE DE PRODUCTION (`axion_app`,
 * RLS forcée).
 *
 * Les autres tests tournent sous le propriétaire (SUPERUSER, BYPASSRLS) : un
 * oubli de contexte d'espace y resterait vert. Ici, proposer, accepter et
 * refuser passent par `pgsql_app` ; un SECOND espace ne voit RIEN et ne peut
 * rien décider ; le rôle applicatif ne peut rien supprimer.
 *
 * Pas de `RefreshDatabase` sur ces lignes : `pgsql_app` est une AUTRE
 * session. Le jeu d'essai est validé (auto-commit) et nettoyé par le
 * propriétaire dans un `finally`. Fixtures FICTIVES (dépôt public).
 */

use App\Crm\Propositions\PropositionIntrouvable;
use App\Crm\Propositions\Propositions;
use App\Models\User;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

function pcrProprio(): Connection
{
    return DB::connection('pgsql_owner');
}

/** @return array{id: string, fiche: int} */
function pcrEspace(): array
{
    $o = pcrProprio();
    $id = (string) Str::uuid();
    $o->table('workspaces')->insert([
        'id' => $id, 'slug' => 'zz-pc-rls-' . substr(str_replace('-', '', $id), 0, 8), 'name' => 'ZZ propositions RLS',
        'settings' => '{}', 'cost_cap_eur' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $o->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $id]);
    $fiche = (int) $o->table('companies')->insertGetId([
        'workspace_id' => $id, 'siren' => '94' . random_int(1000000, 9999999), 'denomination' => 'ZZ Rls Propositions',
        'city' => 'Lyon', 'discovery_source' => 'insee', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return ['id' => $id, 'fiche' => $fiche];
}

function pcrNettoyer(string ...$espaces): void
{
    $o = pcrProprio();
    $o->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    $o->transaction(function () use ($o, $espaces): void {
        foreach ($espaces as $ws) {
            $o->table('propositions_champs')->where('workspace_id', $ws)->delete();
            $o->table('companies')->where('workspace_id', $ws)->delete();
            $o->table('workspaces')->where('id', $ws)->delete();
        }
    });
    $o->disconnect();
    DB::connection('pgsql_app')->disconnect();
}

test('sous axion_app : proposer, refuser, accepter dans l espace visé seulement ; rien ne se supprime', function () {
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    $a = pcrEspace();
    $b = pcrEspace();
    $o = pcrProprio();
    $owner = new User;
    $owner->id = (string) Str::uuid();

    $precedente = DB::getDefaultConnection();
    try {
        DB::setDefaultConnection('pgsql_app');
        $role = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        expect($role->rolsuper)->toBeFalse()->and($role->rolbypassrls)->toBeFalse();

        $service = app(Propositions::class);
        expect($service->proposer($a['id'], 'entreprise', $a['fiche'], 'city', 'Bron', 'apporteur'))->toBe(Propositions::PROPOSEE)
            ->and($service->proposer($a['id'], 'entreprise', $a['fiche'], 'phone', '0199000009', 'societe'))->toBe(Propositions::REMPLI);

        $ids = WorkspaceContext::run($a['id'], fn () => DB::table('propositions_champs')->pluck('id')->all());
        expect($ids)->toHaveCount(1);
        $id = (int) $ids[0];

        // L'espace B ne voit rien, ne décide rien, ne peut pas viser la fiche de A.
        expect(WorkspaceContext::run($b['id'], fn () => DB::table('propositions_champs')->count()))->toBe(0)
            ->and(fn () => $service->accepter($b['id'], $id, $owner))->toThrow(PropositionIntrouvable::class)
            ->and(fn () => $service->proposer($b['id'], 'entreprise', $a['fiche'], 'city', 'Bron', 'apporteur'))->toThrow(InvalidArgumentException::class);
        // Sans contexte : rien de visible.
        expect(DB::table('propositions_champs')->count())->toBe(0);

        // Le rôle applicatif n'a pas le droit de supprimer une proposition.
        expect(fn () => WorkspaceContext::run($a['id'], fn () => DB::table('propositions_champs')->where('id', $id)->delete()))
            ->toThrow(QueryException::class);

        $service->accepter($a['id'], $id, $owner);
        DB::setDefaultConnection($precedente);

        // Relu par le PROPRIÉTAIRE : ce que le rôle applicatif a réellement écrit.
        $fiche = $o->table('companies')->where('id', $a['fiche'])->first();
        $p = $o->table('propositions_champs')->where('id', $id)->first();
        expect($fiche->city)->toBe('Bron')
            ->and($fiche->phone)->toBe('0199000009')
            ->and(json_decode((string) $fiche->field_origins, true))->toEqual(['city' => 'apporteur', 'phone' => 'societe'])
            ->and($p->statut)->toBe('acceptee')
            ->and($p->decidee_par)->toBe($owner->id)
            ->and($o->table('companies')->where('id', $b['fiche'])->value('city'))->toBe('Lyon');
    } finally {
        DB::setDefaultConnection($precedente);
        pcrNettoyer($a['id'], $b['id']);
    }
});
