<?php

/**
 * NON DIFFUSIBLES HORS DE TOUTE PROSPECTION (lot N8, veto sécurité #313,
 * bloquant 2).
 *
 * Une fiche marquée « non diffusible » par la mise à jour INSEE
 * (`companies.insee_non_diffusible_le`) ne sort :
 *  - ni dans l'export CSV des entreprises (`CompaniesController::export`) ;
 *  - ni dans l'export CSV des PERSONNES, même avec
 *    `inclure_non_prospectables=oui` (`PersonnesController::export`,
 *    réserve S1 de la relecture de confirmation) ;
 *  - ni dans la liste des membres d'une audience déjà calculée
 *    (`AudiencesController::members`, réserve S2) ;
 *  - ni dans aucune audience (`AudienceBuilderService`, construction SQL et
 *    évaluation en mémoire), y compris une fiche PROTÉGÉE admise par une
 *    liste manuelle exigée.
 * Rien n'est effacé : la fiche reste en base, seulement filtrée.
 *
 * Fixtures FICTIVES (dépôt public) : dénominations « ZZ », adresses en
 * `example.invalid`, SIREN absents (fiches sans SIREN) ou à clé de Luhn
 * invalide.
 */

use App\Crm\FichesProtegees;
use App\Crm\Listes\ListesManuelles;
use App\Models\Company;
use App\Models\EmailAudience;
use App\Models\ListeManuelle;
use App\Models\User;
use App\Services\Audiences\AudienceBuilderService;
use App\Services\Audit\AuditHashChain;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** @return array{ws: string, ordinaire: int, opposee: int} */
function ndhpEspace(): array
{
    $ws = F::espace('zz-insee-nd');
    $commun = ['siren' => null, 'department_code' => '38', 'prospection_status' => 'ready_for_outreach'];

    return [
        'ws' => $ws,
        'ordinaire' => F::fiche($ws, 'ZZ ORDINAIRE DIFFUSIBLE', $commun + ['email_generic' => 'contact@zz-ordinaire.example.invalid']),
        'opposee' => F::fiche($ws, 'ZZ OPPOSEE NE PAS EXPORTER', $commun + [
            'email_generic' => 'contact@zz-opposee.example.invalid', 'insee_non_diffusible_le' => '2026-10-06',
        ]),
    ];
}

test('export CSV des entreprises : une fiche non diffusible n y figure pas, le témoin oui', function () {
    $e = ndhpEspace();
    $this->seed(PermissionsAndRolesSeeder::class);
    app(PermissionRegistrar::class)->setPermissionsTeamId($e['ws']);
    $u = User::create([
        'id' => (string) Str::uuid(), 'email' => 'owner-' . Str::random(6) . '@example.invalid', 'name' => 'ZZ owner',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $e['ws'],
        'first_login_completed_at' => now(),
    ]);
    $u->assignRole('owner');
    $this->actingAs($u);

    $csv = $this->get('/api/v1/companies/export')->assertOk()->streamedContent();

    expect($csv)->toContain('ZZ ORDINAIRE DIFFUSIBLE')
        ->and($csv)->not->toContain('ZZ OPPOSEE NE PAS EXPORTER')
        ->and($csv)->not->toContain('zz-opposee.example.invalid');
    // Rien n'est effacé.
    expect(DB::table('companies')->where('id', $e['opposee'])->whereNull('deleted_at')->exists())->toBeTrue();
});

test('audience : une fiche non diffusible n entre dans aucune audience (SQL)', function () {
    $e = ndhpEspace();
    $service = new AudienceBuilderService;

    $ids = $service->buildPublicQuery($e['ws'], [])->pluck('id')->map(fn ($id) => (int) $id)->all();

    expect($ids)->toContain($e['ordinaire'])->not->toContain($e['opposee'])
        ->and($service->preview($e['ws'], [])['companies'])->toBe(1);
});

test('audience : une fiche PROTÉGÉE non diffusible reste dehors, même cochée dans une liste manuelle exigée', function () {
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    $e = ndhpEspace();
    $protegee = F::fiche($e['ws'], 'ZZ PROTEGEE OPPOSEE', ['siren' => null, 'insee_non_diffusible_le' => '2026-10-06']);
    $temoin = F::fiche($e['ws'], 'ZZ PROTEGEE DIFFUSIBLE', ['siren' => null]);
    F::proteger($e['ws'], $protegee, FichesProtegees::TAG_FEDERATIONS);
    F::proteger($e['ws'], $temoin, FichesProtegees::TAG_FEDERATIONS);
    $liste = ListeManuelle::create(['workspace_id' => $e['ws'], 'nom' => 'ZZ Liste ND']);
    ListesManuelles::ajouter($liste, [$protegee, $temoin], [], null, ListesManuelles::ORIGINE_COCHE);

    $criteres = ['all' => [['field' => 'liste_manuelle', 'op' => 'in', 'value' => [$liste->id]]]];
    $ids = (new AudienceBuilderService)->buildPublicQuery($e['ws'], $criteres)->pluck('id')->map(fn ($id) => (int) $id)->all();

    expect($ids)->toContain($temoin)->not->toContain($protegee);
});

test('audience : l évaluation EN MÉMOIRE (waterfall step12) ne rattache pas une fiche non diffusible', function () {
    $e = ndhpEspace();
    $audience = EmailAudience::create([
        'workspace_id' => $e['ws'], 'name' => 'ZZ toute la base', 'criteria' => [], 'is_active' => true, 'auto_refresh' => true,
    ]);
    $service = new AudienceBuilderService;

    expect($service->evaluateForCompany(Company::query()->findOrFail($e['ordinaire'])))->toContain($audience->id)
        ->and($service->evaluateForCompany(Company::query()->findOrFail($e['opposee'])))->not->toContain($audience->id);
});

function ndhpProprietaire(string $ws): User
{
    $u = User::create([
        'id' => (string) Str::uuid(), 'email' => 'owner-' . Str::random(6) . '@example.invalid', 'name' => 'ZZ owner',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $ws,
        'first_login_completed_at' => now(),
    ]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($ws);
    $u->assignRole('owner');
    DB::table('user_workspaces')->insertOrIgnore([
        'user_id' => $u->id, 'workspace_id' => $ws, 'role_slug' => 'owner', 'invited_at' => now(), 'joined_at' => now(),
    ]);

    return $u;
}

test('🔴 export CSV des PERSONNES : la personne d une fiche non diffusible ne sort JAMAIS, même avec inclure_non_prospectables=oui', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $e = ndhpEspace();
    config(['crm.console_v2' => true, 'crm.ingest.business_workspace' => F::slug($e['ws'])]);
    $this->actingAs(ndhpProprietaire($e['ws']));

    $base = ['workspace_id' => $e['ws'], 'email_nature' => 'pro', 'premiere_source' => 'newsletter', 'legal_basis' => 'consent',
        'premiere_source_at' => now(), 'derniere_interaction_at' => now(), 'created_at' => now(), 'updated_at' => now()];
    $personnes = [];
    foreach ([
        ['zoe@zz-ordinaire.example.invalid', $e['ordinaire']],
        ['yann@zz-opposee.example.invalid', $e['opposee']],
    ] as [$email, $fiche]) {
        $pid = (int) DB::table('personnes')->insertGetId($base + [
            'person_key' => hash('sha256', 'ndhp|' . $email), 'email' => $email, 'email_hash' => hash('sha256', $email),
            'company_id' => $fiche, 'rattachee_at' => now(),
        ]);
        DB::table('abonnements')->insert(['workspace_id' => $e['ws'], 'personne_id' => $pid, 'canal' => 'lettre', 'statut' => 'abonne',
            'consent_version' => 'v1', 'consent_at' => now(), 'dernier_evenement_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $personnes[] = $pid;
    }

    foreach (['', '?inclure_non_prospectables=oui'] as $option) {
        $csv = $this->get('/api/v1/crm/personnes/export' . $option)->assertOk()->streamedContent();
        expect($csv)->toContain('zoe@zz-ordinaire.example.invalid')
            ->and($csv)->not->toContain('yann@zz-opposee.example.invalid')
            ->and($csv)->not->toContain('zz-opposee.example.invalid');
    }
    // Rien n'est effacé : la personne, son abonnement et la fiche restent.
    expect(DB::table('personnes')->whereIn('id', $personnes)->count())->toBe(2)
        ->and(DB::table('abonnements')->whereIn('personne_id', $personnes)->count())->toBe(2)
        ->and(DB::table('companies')->where('id', $e['opposee'])->whereNull('deleted_at')->exists())->toBeTrue();
});

test('membres d une audience : un membre inscrit AVANT le marquage non diffusible ne s affiche plus', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $e = ndhpEspace();
    $this->actingAs(ndhpProprietaire($e['ws']));
    $audience = EmailAudience::create([
        'workspace_id' => $e['ws'], 'name' => 'ZZ calculée avant le marquage', 'criteria' => [], 'is_active' => true, 'auto_refresh' => false,
    ]);
    foreach ([$e['ordinaire'], $e['opposee']] as $fiche) {
        DB::table('audience_members')->insert(['workspace_id' => $e['ws'], 'audience_id' => $audience->id, 'company_id' => $fiche]);
    }

    $ids = collect($this->getJson("/api/v1/audiences/{$audience->id}/members")->assertOk()->json('data'))
        ->pluck('company_id')->map(fn ($id) => (int) $id)->all();

    expect($ids)->toBe([$e['ordinaire']]);
    // Rien n'est effacé : le membre attend le prochain rafraîchissement.
    expect(DB::table('audience_members')->where('audience_id', $audience->id)->count())->toBe(2);
});
