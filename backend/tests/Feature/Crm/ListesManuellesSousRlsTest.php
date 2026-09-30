<?php

/**
 * LISTES MANUELLES ET DESTINATAIRES, REJOUÉS SOUS LE RÔLE DE PRODUCTION
 * (`axion_app`, RLS forcée) — 2026-09-30.
 *
 * Les autres tests tournent sous le propriétaire (SUPERUSER, BYPASSRLS). Ici,
 * cocher, le critère d'audience et le résolveur passent par `pgsql_app` dans
 * le contexte de l'espace A ; un espace B aux mêmes fiches ne doit RIEN voir
 * ni rien laisser faire. Ce test COMMIT (connexions hors transaction de
 * test) : il nettoie tout ce qu'il a semé. Fixtures FICTIVES.
 */

use App\Crm\Campagnes\ReglageDestinataires;
use App\Crm\Campagnes\ResolveurDestinataires;
use App\Crm\Emails\VerificationEmail;
use App\Crm\Listes\ListesManuelles;
use App\Models\ListeManuelle;
use App\Services\Audiences\AudienceBuilderService;
use App\Services\Audiences\CritereAudienceInvalide;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function lrlsProprio(): Connection
{
    return DB::connection('pgsql_owner');
}

/** @return array{id: string, org: int, personne: int, liste: int} */
function lrlsEspace(): array
{
    $owner = lrlsProprio();
    $id = (string) Str::uuid();
    $marque = substr(str_replace('-', '', $id), 0, 8);
    $owner->table('workspaces')->insert([
        'id' => $id, 'slug' => 'zz-listes-rls-' . $marque, 'name' => 'ZZ listes RLS', 'settings' => '{}',
        'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $email = 'accueil@zz-rls-' . $marque . '.example.invalid';
    $org = (int) $owner->table('companies')->insertGetId([
        'workspace_id' => $id, 'siren' => '95' . random_int(1000000, 9999999), 'denomination' => 'ZZ Rls ' . $marque,
        'email_generic' => $email, 'metadata' => '{}', 'quality_score' => 0,
        'signals' => json_encode(['email_generic_verification' => [
            'statut' => VerificationEmail::VALIDE, 'verifie_par' => VerificationEmail::SOURCE, 'empreinte' => VerificationEmail::empreinte($email),
        ]]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $personne = (int) $owner->table('contacts')->insertGetId([
        'workspace_id' => $id, 'company_id' => $org, 'first_name' => 'Zoe', 'last_name' => 'ZZRLS' . $marque, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $id]);
    $liste = (int) $owner->table('listes_manuelles')->insertGetId([
        'workspace_id' => $id, 'nom' => 'ZZ Invités', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);

    return ['id' => $id, 'org' => $org, 'personne' => $personne, 'liste' => $liste];
}

/** @param  array{id: string}  $e */
function lrlsNettoyer(array $e): void
{
    $owner = lrlsProprio();
    $owner->transaction(function () use ($owner, $e): void {
        // Nettoyage du TEST (données semées ici) — le produit, lui, ne
        // supprime jamais ni une liste ni une appartenance.
        foreach (['listes_manuelles_membres', 'listes_manuelles', 'contacts', 'companies'] as $table) {
            $owner->table($table)->where('workspace_id', $e['id'])->delete();
        }
        $owner->table('workspaces')->where('id', $e['id'])->delete();
    });
}

afterEach(function () {
    DB::connection('pgsql_app')->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    DB::connection('pgsql_app')->disconnect();
    lrlsProprio()->disconnect();
});

test('sous axion_app : cocher, cibler et résoudre dans l espace visé seulement', function () {
    $a = lrlsEspace();
    $b = lrlsEspace();
    $owner = lrlsProprio();
    $precedente = DB::getDefaultConnection();

    try {
        DB::setDefaultConnection('pgsql_app');
        $role = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        expect($role->rolsuper)->toBeFalse()->and($role->rolbypassrls)->toBeFalse();
        DB::select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $a['id']]);

        // La liste de B est INVISIBLE depuis A.
        expect(ListeManuelle::query()->whereKey($b['liste'])->exists())->toBeFalse()
            ->and(ListeManuelle::query()->whereKey($a['liste'])->exists())->toBeTrue();

        // Cocher dans A : l'organisation et la personne de A entrent ; celles
        // de B sont « introuvables » (invisibles), jamais ajoutées.
        $liste = ListeManuelle::query()->findOrFail($a['liste']);
        $bilan = ListesManuelles::ajouter($liste, [$a['org'], $b['org']], [$a['personne'], $b['personne']], null, 'coche');
        expect($bilan['ajoutes'])->toBe(2)->and($bilan['introuvables'])->toBe(2);

        // Même en forçant l'écriture, la base refuse une fiche de B dans A.
        $refus = null;
        try {
            DB::table('listes_manuelles_membres')->insert([
                'workspace_id' => $a['id'], 'liste_id' => $a['liste'], 'company_id' => $b['org'], 'origine' => 'coche',
            ]);
        } catch (Throwable $e) {
            $refus = $e->getMessage();
        }
        expect($refus)->toContain('n est pas de cet espace');

        // Et la RLS refuse une ligne rattachée à l'espace B depuis le contexte A.
        $refusRls = null;
        try {
            DB::table('listes_manuelles_membres')->insert([
                'workspace_id' => $b['id'], 'liste_id' => $b['liste'], 'company_id' => $b['org'], 'origine' => 'coche',
            ]);
        } catch (Throwable $e) {
            $refusRls = $e->getMessage();
        }
        expect($refusRls)->not->toBeNull();

        // Le critère et le résolveur, sous le rôle applicatif.
        $criteres = ['all' => [['field' => 'liste_manuelle', 'op' => 'in', 'value' => [$a['liste']]]]];
        $ids = app(AudienceBuilderService::class)->buildPublicQuery($a['id'], $criteres)->pluck('id')->all();
        expect(array_map('intval', $ids))->toBe([$a['org']]);

        $r = app(ResolveurDestinataires::class)->resoudre($a['id'], $criteres, new ReglageDestinataires(ReglageDestinataires::GENERIQUE));
        expect($r['destinataires'])->toBe(1)->and($r['organisations'])->toBe(1);

        // Depuis A, une liste de B citée est « inconnue » : refusée.
        expect(fn () => app(AudienceBuilderService::class)->buildPublicQuery($a['id'], ['all' => [['field' => 'liste_manuelle', 'op' => 'in', 'value' => [$b['liste']]]]]))
            ->toThrow(CritereAudienceInvalide::class);
    } finally {
        DB::setDefaultConnection($precedente);
        // B n'a RIEN reçu.
        $membresB = $owner->table('listes_manuelles_membres')->where('workspace_id', $b['id'])->count();
        lrlsNettoyer($a);
        lrlsNettoyer($b);
    }

    expect($membresB)->toBe(0);
});
