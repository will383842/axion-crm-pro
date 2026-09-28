<?php

/**
 * EFFACEMENT (art. 17) — LES FICHES D'ORGANISATION (relecture sécurité de la
 * PR #255, 2026-09-29).
 *
 * L'adresse et le mobile d'une personne vivent aussi sur la fiche de son
 * organisation : e-mail générique, téléphone, canaux collectés (et leur fiche
 * de vérification), fiches personnes qui portent son numéro sans son adresse.
 * Les deux portes d'effacement ne les touchaient pas. Chaque garde est prouvée
 * face à un TÉMOIN (une autre organisation, d'autres coordonnées) qui, lui,
 * ne doit pas bouger. Fixtures FICTIVES (dépôt public).
 */

use App\Crm\FichesProtegees;
use App\Crm\Rgpd\EffacementCoordonneesFiches;
use App\Crm\Rgpd\SiteGdprService;
use App\Models\User;
use App\Services\Rgpd\GdprErasureService;
use Database\Seeders\PermissionsAndRolesSeeder;
use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const EFO_EMAIL = 'zoe.zzpresidente@zz-fede.example.invalid';
const EFO_MOBILE = '06 00 00 00 42';
const EFO_MOBILE_AUTRE_FORME = '+33 6 00 00 00 42';

beforeEach(function () {
    config(['crm.scrape_funnel.validate_mx' => false, 'crm.ingest.business_workspace' => 'axion-ia']);
    $this->espace = (string) (DB::table('workspaces')->where('slug', 'axion-ia')->value('id') ?? Str::uuid());
    if (! DB::table('workspaces')->where('id', $this->espace)->exists()) {
        DB::table('workspaces')->insert([
            'id' => $this->espace, 'slug' => 'axion-ia', 'name' => 'Axion-IA', 'settings' => '{}',
            'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $this->seed(ScrapingSourcesSeeder::class);

    // Une fédération PROTÉGÉE qui porte l'adresse et le mobile de sa
    // présidente aux TROIS emplacements : e-mail générique / téléphone, canaux,
    // et fiche personne (le mobile y est écrit autrement).
    $this->fede = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '900000501', 'entity_nature' => 'federation',
        'denomination' => 'ZZ Fede effacement', 'email_generic' => EFO_EMAIL, 'phone' => EFO_MOBILE,
        'signals' => json_encode([
            'contact_channels' => [
                'emails' => [strtoupper(EFO_EMAIL), 'secretariat@zz-fede.example.invalid'],
                'phones' => [EFO_MOBILE_AUTRE_FORME, '01 00 00 00 10'],
                'details' => [
                    EFO_EMAIL => ['type' => 'nominatif', 'verifie_le' => '2026-09-28'],
                    'secretariat@zz-fede.example.invalid' => ['type' => 'generique', 'verifie_le' => '2026-09-28'],
                ],
            ],
            'email_generic_verification' => ['type' => 'generique', 'verifie_le' => '2026-09-28'],
        ]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $tag = (int) DB::table('tags')->insertGetId([
        'workspace_id' => $this->espace, 'slug' => FichesProtegees::TAG_FEDERATIONS, 'name' => 'Fédérations',
        'category' => 'intent', 'kind' => 'auto', 'rules' => '{}', 'is_locked' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('company_tag')->insert([
        'company_id' => $this->fede, 'tag_id' => $tag, 'workspace_id' => $this->espace,
        'assigned_at' => now(), 'assigned_by' => 'auto-rule',
    ]);
    $this->presidente = (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $this->espace, 'company_id' => $this->fede, 'first_name' => 'Zoe', 'last_name' => 'ZZPRESIDENTE',
        'email' => EFO_EMAIL, 'phone' => EFO_MOBILE_AUTRE_FORME, 'created_at' => now(), 'updated_at' => now(),
    ]);
    // Un DOUBLON de la même personne, sans l'adresse, avec le même mobile —
    // sur la fiche de l'ANTENNE (une personne n'a qu'une fiche par organisme).
    $this->antenne = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '900000503', 'denomination' => 'ZZ Antenne',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->doublon = (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $this->espace, 'company_id' => $this->antenne, 'first_name' => 'Zoe', 'last_name' => 'ZZPRESIDENTE',
        'phone' => '0033600000042', 'created_at' => now(), 'updated_at' => now(),
    ]);
    // Une AUTRE personne qui partage ce numéro (le standard) : elle reste, sans le numéro.
    $this->collegue = (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $this->espace, 'company_id' => $this->fede, 'first_name' => 'Zed', 'last_name' => 'ZZSECRETAIRE',
        'email' => 'secretaire@zz-fede.example.invalid', 'phone' => '06.00.00.00.42', 'created_at' => now(), 'updated_at' => now(),
    ]);

    // TÉMOIN : une autre organisation, d'autres coordonnées.
    $this->temoin = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '900000502', 'denomination' => 'ZZ Temoin',
        'email_generic' => 'accueil@zz-temoin.example.invalid', 'phone' => '06 00 00 00 99',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->contactTemoin = (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $this->espace, 'company_id' => $this->temoin, 'last_name' => 'ZZTEMOIN',
        'email' => 'temoin@zz-temoin.example.invalid', 'phone' => '06 00 00 00 98', 'created_at' => now(), 'updated_at' => now(),
    ]);
});

function efoFiche(int $id): object
{
    return DB::table('companies')->where('id', $id)->first();
}

/** @return array<string, mixed> */
function efoSignals(int $id): array
{
    return (array) json_decode((string) efoFiche($id)->signals, true);
}

test('l effacement console atteint les trois emplacements d une fiche PROTEGEE, qui survit ; le temoin ne bouge pas', function () {
    $resultat = app(GdprErasureService::class)->erase(EFO_EMAIL);

    $fiche = efoFiche($this->fede);
    $canaux = efoSignals($this->fede)['contact_channels'];
    expect($fiche)->not->toBeNull()
        ->and($fiche->email_generic)->toBeNull()
        ->and($fiche->phone)->toBeNull()
        ->and(efoSignals($this->fede))->not->toHaveKey('email_generic_verification')
        ->and($canaux['emails'])->toBe(['secretariat@zz-fede.example.invalid'])
        ->and($canaux['phones'])->toBe(['01 00 00 00 10'])
        ->and(array_keys($canaux['details']))->toBe(['secretariat@zz-fede.example.invalid'])
        ->and(DB::table('contacts')->whereIn('id', [$this->presidente, $this->doublon])->count())->toBe(0)
        // Le collègue n'est PAS effacé : seul le numéro partagé part.
        ->and(DB::table('contacts')->where('id', $this->collegue)->value('email'))->toBe('secretaire@zz-fede.example.invalid')
        ->and(DB::table('contacts')->where('id', $this->collegue)->value('phone'))->toBeNull()
        ->and($resultat['complete'])->toBeTrue()
        ->and($resultat['residus'])->toBe([])
        // Témoin intact.
        ->and(efoFiche($this->temoin)->email_generic)->toBe('accueil@zz-temoin.example.invalid')
        ->and(efoFiche($this->temoin)->phone)->toBe('06 00 00 00 99')
        ->and(DB::table('contacts')->where('id', $this->contactTemoin)->exists())->toBeTrue();
});

test('l effacement pose l opposition sur l adresse ET le mobile, et le re-import ne les remet pas', function () {
    app(GdprErasureService::class)->erase(EFO_EMAIL);

    expect(DB::table('opt_out')->where('scope', 'business')->where('email_hash', hash('sha256', EFO_EMAIL))->exists())->toBeTrue()
        ->and(DB::table('opt_out')->where('scope', 'business')->whereNotNull('phone')->exists())->toBeTrue();

    $fichier = (string) tempnam(sys_get_temp_dir(), 'zz-efo-');
    file_put_contents($fichier, json_encode([
        'siren' => '900000501', 'nom' => 'ZZ Fede effacement', 'famille' => 'federation_syndicat_pro',
        'niveau' => 'national', 'pertinence' => 'haute', 'contactabilite' => 'email_verifie',
        'email_generique' => EFO_EMAIL, 'telephone' => EFO_MOBILE,
        'emails_autres' => [['email' => EFO_EMAIL, 'type' => 'nominatif', 'domaine_verifie' => true, 'verifie_le' => '2026-09-28']],
        'telephones_autres' => [EFO_MOBILE_AUTRE_FORME],
        'personnes' => [['prenom' => 'Zoe', 'nom' => 'ZZPRESIDENTE', 'fonction' => 'Présidente', 'email' => EFO_EMAIL, 'linkedin' => null]],
    ]) . "\n");
    try {
        Artisan::call('crm:import-federations', ['file' => $fichier]);
    } finally {
        @unlink($fichier);
    }

    $fiche = efoFiche($this->fede);
    $texte = mb_strtolower((string) $fiche->signals);
    expect($fiche->email_generic)->toBeNull()
        ->and($fiche->phone)->toBeNull()
        ->and($texte)->not->toContain(EFO_EMAIL)
        ->and($texte)->not->toContain('00 00 00 42')
        ->and(DB::table('contacts')->where('company_id', $this->fede)->where('last_name', 'ZZPRESIDENTE')->exists())->toBeFalse();
});

test('une adresse restee ailleurs dans les signaux rend l effacement INCOMPLET, et il le dit', function () {
    DB::table('companies')->where('id', $this->temoin)->update([
        'signals' => json_encode(['mentions_legales' => ['contact' => EFO_EMAIL]]),
    ]);

    $resultat = app(GdprErasureService::class)->erase(EFO_EMAIL);

    expect($resultat['complete'])->toBeFalse()
        ->and($resultat['residus'])->toHaveKey('companies.signals');
});

test('le journal de l effacement ne porte JAMAIS l adresse en clair', function () {
    $messages = [];
    Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$messages): void {
        $messages[] = $m->message . ' ' . json_encode($m->context);
    });

    app(GdprErasureService::class)->erase(EFO_EMAIL);

    expect($messages)->not->toBeEmpty();
    foreach ($messages as $ligne) {
        expect(mb_strtolower($ligne))->not->toContain(EFO_EMAIL);
    }
});

test('l export CSV des entreprises ne rend plus l adresse ni le mobile apres effacement', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'admin-' . Str::random(6) . '@example.invalid', 'name' => 'ZZ admin',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $this->espace,
        'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($this->espace);
    $user->assignRole('admin');
    $this->actingAs($user);

    $avant = $this->get('/api/v1/companies/export')->streamedContent();
    // Témoin de l'instrument : AVANT, l'export porte bien l'adresse.
    expect($avant)->toContain(EFO_EMAIL);

    app(GdprErasureService::class)->erase(EFO_EMAIL);

    $apres = $this->get('/api/v1/companies/export')->streamedContent();
    expect($apres)->not->toContain(EFO_EMAIL)
        ->and($apres)->not->toContain(EFO_MOBILE)
        ->and($apres)->toContain('accueil@zz-temoin.example.invalid');
});

test('l effacement venu du site atteint aussi les fiches d organisation', function () {
    app(SiteGdprService::class)->erase(hash('sha256', 'zz-efo'), EFO_EMAIL, 'business');

    $fiche = efoFiche($this->fede);
    expect($fiche->email_generic)->toBeNull()
        ->and($fiche->phone)->toBeNull()
        ->and(efoSignals($this->fede)['contact_channels']['emails'])->toBe(['secretariat@zz-fede.example.invalid'])
        ->and(DB::table('contacts')->where('id', $this->doublon)->exists())->toBeFalse()
        ->and(efoFiche($this->temoin)->email_generic)->toBe('accueil@zz-temoin.example.invalid');
});

test('ce qu on sait effacer, on sait l exporter : les fiches d organisation qui portent l adresse', function () {
    $fiches = EffacementCoordonneesFiches::fichesPortant(strtoupper(EFO_EMAIL));

    expect(array_column($fiches, 'id'))->toBe([$this->fede]);
});
