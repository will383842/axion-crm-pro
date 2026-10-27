<?php

/**
 * ENTREPRISES FERMÉES SELON L'INSEE : MASQUÉES PARTOUT PAR DÉFAUT, EXPORTS
 * COMPRIS (décision du propriétaire, 27/10/2026 — `App\Support\EntreprisesFermees`).
 *
 * Pour chaque surface : une fiche OUVERTE et une FERMÉE ; par défaut seule
 * l'ouverte apparaît, `fermees=inclure` montre les deux. Rien n'est supprimé.
 * Fixtures FICTIVES (dépôt public).
 */

use App\Models\Company;
use App\Models\EmailAudience;
use App\Models\User;
use App\Services\Audiences\AudienceBuilderService;
use App\Services\Audit\AuditHashChain;
use App\Support\EntreprisesFermees;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    $this->ws = F::espace('zz-mpf');
    $u = User::create([
        'id' => (string) Str::uuid(), 'email' => 'owner-' . Str::random(6) . '@example.invalid', 'name' => 'ZZ owner',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $this->ws,
        'first_login_completed_at' => now(),
    ]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->ws);
    $u->assignRole('owner');
    DB::table('user_workspaces')->insertOrIgnore([
        'user_id' => $u->id, 'workspace_id' => $this->ws, 'role_slug' => 'owner', 'invited_at' => now(), 'joined_at' => now(),
    ]);
    $this->actingAs($u);

    $this->ouverte = F::fiche($this->ws, 'ZZ MPF OUVERTE', ['email_generic' => 'contact@zz-mpf-ouverte.example.invalid']);
    $this->fermee = F::fiche($this->ws, 'ZZ MPF FERMEE', ['email_generic' => 'contact@zz-mpf-fermee.example.invalid', 'insee_ferme_le' => '2026-09-15']);
    $this->cOuvert = F::contact($this->ws, $this->ouverte, 'Zoe', 'ZZMPFOUVERT', ['email' => 'zoe@zz-mpf-ouverte.example.invalid']);
    $this->cFerme = F::contact($this->ws, $this->fermee, 'Yann', 'ZZMPFFERME', ['email' => 'yann@zz-mpf-fermee.example.invalid']);
});

test('export CSV des entreprises : la fermée est masquée par défaut, fermees=inclure la remet', function () {
    $parDefaut = $this->get('/api/v1/companies/export')->assertOk()->streamedContent();
    $inclure = $this->get('/api/v1/companies/export?fermees=inclure')->assertOk()->streamedContent();
    $seules = $this->get('/api/v1/companies/export?fermees=seules')->assertOk()->streamedContent();

    expect($parDefaut)->toContain('ZZ MPF OUVERTE')->not->toContain('ZZ MPF FERMEE')
        ->and($inclure)->toContain('ZZ MPF OUVERTE')->toContain('ZZ MPF FERMEE')
        ->and($seules)->toContain('ZZ MPF FERMEE')->not->toContain('ZZ MPF OUVERTE');
});

/** Une personne de la lettre par fiche (ouverte, fermée) ; l'espace métier est celui du test. */
function personnesDesDeuxFiches(object $t): void
{
    config(['crm.console_v2' => true, 'crm.ingest.business_workspace' => F::slug($t->ws)]);
    $base = ['workspace_id' => $t->ws, 'email_nature' => 'pro', 'premiere_source' => 'newsletter', 'legal_basis' => 'consent',
        'premiere_source_at' => now(), 'derniere_interaction_at' => now(), 'created_at' => now(), 'updated_at' => now()];
    foreach ([['zoe@zz-mpf-ouverte.example.invalid', $t->ouverte], ['yann@zz-mpf-fermee.example.invalid', $t->fermee]] as [$email, $fiche]) {
        $pid = (int) DB::table('personnes')->insertGetId($base + [
            'person_key' => hash('sha256', 'mpf|' . $email), 'email' => $email, 'email_hash' => hash('sha256', $email),
            'company_id' => $fiche, 'rattachee_at' => now(),
        ]);
        DB::table('abonnements')->insert(['workspace_id' => $t->ws, 'personne_id' => $pid, 'canal' => 'lettre', 'statut' => 'abonne',
            'consent_version' => 'v1', 'consent_at' => now(), 'dernier_evenement_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}

test('export CSV des personnes : la personne d une fiche fermée est masquée par défaut, inclure la remet, seules ne garde qu elle', function () {
    personnesDesDeuxFiches($this);

    $parDefaut = $this->get('/api/v1/crm/personnes/export')->assertOk()->streamedContent();
    $inclure = $this->get('/api/v1/crm/personnes/export?fermees=inclure')->assertOk()->streamedContent();
    $seules = $this->get('/api/v1/crm/personnes/export?fermees=seules')->assertOk()->streamedContent();

    expect($parDefaut)->toContain('zoe@zz-mpf-ouverte')->not->toContain('yann@zz-mpf-fermee')
        ->and($inclure)->toContain('zoe@zz-mpf-ouverte')->toContain('yann@zz-mpf-fermee')
        ->and($seules)->toContain('yann@zz-mpf-fermee')->not->toContain('zoe@zz-mpf-ouverte');
});

test('personnes : la liste et les pastilles masquent la fermée par défaut (différence), fermees=inclure|seules les remettent', function () {
    personnesDesDeuxFiches($this);
    $emails = fn (string $q): array => array_column($this->getJson('/api/v1/crm/personnes' . $q)->assertOk()->json('data'), 'email');

    expect($emails(''))->toBe(['zoe@zz-mpf-ouverte.example.invalid'])
        ->and($emails('?fermees=inclure'))->toEqualCanonicalizing(['zoe@zz-mpf-ouverte.example.invalid', 'yann@zz-mpf-fermee.example.invalid'])
        ->and($emails('?fermees=seules'))->toBe(['yann@zz-mpf-fermee.example.invalid']);

    $pastilles = fn (string $q): array => $this->getJson('/api/v1/crm/personnes/counts' . $q)->assertOk()->json();
    $defaut = $pastilles('');
    $inclure = $pastilles('?fermees=inclure');
    $seules = $pastilles('?fermees=seules');
    expect($defaut['total'])->toBe(1)
        ->and($inclure['total'])->toBe(2)
        ->and($seules['total'])->toBe(1)
        ->and($defaut['by_statut_lettre']['abonne'])->toBe(1)
        ->and($inclure['by_statut_lettre']['abonne'])->toBe(2)
        ->and($defaut['by_source'])->toBe(['newsletter' => 1]);
});

test('audience : ni la construction (aperçu, compteurs) ni les membres déjà inscrits ne montrent la fermée', function () {
    $ids = (new AudienceBuilderService)->buildPublicQuery($this->ws, [])->pluck('id')->map(fn ($id) => (int) $id)->all();
    expect($ids)->toContain($this->ouverte)->not->toContain($this->fermee);

    $audience = EmailAudience::create(['workspace_id' => $this->ws, 'name' => 'ZZ MPF', 'criteria' => [], 'is_active' => true, 'auto_refresh' => true]);
    foreach ([$this->ouverte, $this->fermee] as $fiche) {
        DB::table('audience_members')->insert(['audience_id' => $audience->id, 'company_id' => $fiche, 'workspace_id' => $this->ws, 'added_at' => now()]);
    }

    $membres = array_map('intval', array_column($this->getJson("/api/v1/audiences/{$audience->id}/members")->assertOk()->json('data'), 'company_id'));
    expect($membres)->toBe([$this->ouverte]);
});

test('liste manuelle : les effectifs et les membres ne comptent pas la fermée (rien n est retiré en base)', function () {
    $id = (int) $this->postJson('/api/v1/listes-manuelles', ['nom' => 'ZZ MPF liste'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/listes-manuelles/{$id}/membres", ['company_ids' => [$this->ouverte, $this->fermee]])->assertOk();

    $this->getJson("/api/v1/listes-manuelles/{$id}")->assertOk()->assertJsonPath('data.organisations', 1);
    $this->getJson("/api/v1/listes-manuelles/{$id}/membres")->assertOk()->assertJsonPath('meta.total', 1);
    expect(DB::table('listes_manuelles_membres')->where('liste_id', $id)->whereNull('retire_le')->count())->toBe(2);
});

test('contacts : le contact d une fiche fermée est masqué par défaut, fermees=inclure le remet', function () {
    $noms = fn (string $q): array => array_column($this->getJson('/api/v1/contacts?per_page=50' . $q)->assertOk()->json('data'), 'last_name');

    expect($noms(''))->toBe(['ZZMPFOUVERT'])
        ->and($noms('&fermees=inclure'))->toEqualCanonicalizing(['ZZMPFOUVERT', 'ZZMPFFERME'])
        ->and($noms('&fermees=seules'))->toBe(['ZZMPFFERME']);
});

test('recherche globale : le contact d une fiche fermée est masqué par défaut', function () {
    $ids = fn (string $q): array => array_map('intval', array_column($this->getJson('/api/v1/search?q=ZZMPF' . $q)->assertOk()->json('contacts'), 'id'));

    expect($ids(''))->toContain($this->cOuvert)->not->toContain($this->cFerme)
        ->and($ids('&fermees=inclure'))->toContain($this->cOuvert)->toContain($this->cFerme)
        ->and($ids('&fermees=seules'))->toContain($this->cFerme)->not->toContain($this->cOuvert);
});

test('accueil : contacts qualifiés ne compte pas le contact d une fiche fermée', function () {
    expect($this->getJson('/api/v1/dashboard/stats')->assertOk()->json('contacts_qualified'))->toBe(1);
});

test('accueil et pastilles du hub : les fiches fermées se retranchent par différence, la fiche seule reste lisible', function () {
    config(['crm.console_v2' => true]);
    $this->getJson('/api/v1/crm/contacts-hub/counts')->assertOk();
    $hub = $this->getJson('/api/v1/crm/contacts-hub?per_page=50&temperature=tous')->assertOk()->json('data');
    $inclure = $this->getJson('/api/v1/crm/contacts-hub?per_page=50&temperature=tous&fermees=inclure')->assertOk()->json('data');
    $denominations = fn (array $lignes): array => array_column($lignes, 'denomination');

    // `temperature=tous` : la vue par défaut du hub ne montre que les fiches actives.
    expect($denominations($hub))->toContain('ZZ MPF OUVERTE')->not->toContain('ZZ MPF FERMEE')
        ->and($denominations($inclure))->toContain('ZZ MPF FERMEE');
    expect(DB::table('companies')->where('id', $this->fermee)->exists())->toBeTrue();
});

test('waterfall : la fiche fermée n est rattachée à aucune audience, l ouverte oui', function () {
    $audience = EmailAudience::create(['workspace_id' => $this->ws, 'name' => 'ZZ MPF auto', 'criteria' => [], 'is_active' => true, 'auto_refresh' => true]);
    $service = new AudienceBuilderService;

    expect($service->evaluateForCompany(Company::query()->findOrFail($this->ouverte)))->toContain($audience->id)
        ->and($service->evaluateForCompany(Company::query()->findOrFail($this->fermee)))->not->toContain($audience->id);
});

test('appliquerAuxContacts : deux espaces ne se mélangent pas, chacun a ses fermées', function () {
    $autre = F::espace('zz-mpf-bis');
    $ficheAutre = F::fiche($autre, 'ZZ MPF AUTRE FERMEE', ['insee_ferme_le' => '2026-08-01']);
    $contactAutre = F::contact($autre, $ficheAutre, 'Xavi', 'ZZMPFAUTRE');

    $ids = fn (string $espace, string $mode): array => EntreprisesFermees::appliquerAuxContacts(
        DB::table('contacts')->where('workspace_id', $espace),
        $mode,
    )->pluck('id')->map(fn ($id) => (int) $id)->all();

    expect($ids($this->ws, EntreprisesFermees::MASQUER))->toBe([$this->cOuvert])
        ->and($ids($this->ws, EntreprisesFermees::SEULES))->toBe([$this->cFerme])
        ->and($ids($autre, EntreprisesFermees::MASQUER))->toBe([])
        ->and($ids($autre, EntreprisesFermees::SEULES))->toBe([$contactAutre])
        ->and(fn () => EntreprisesFermees::appliquerAuxContacts(DB::table('contacts'), EntreprisesFermees::MASQUER, 'contacts.company_id; DROP TABLE x'))
        ->toThrow(InvalidArgumentException::class);
});
