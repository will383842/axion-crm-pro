<?php

/**
 * FÉDÉRATIONS — les corrections de la double relecture de la PR #255
 * (2026-09-29). Un test par point, chacun face à un témoin quand l'absence
 * d'effet pourrait passer pour une réussite. Fixtures FICTIVES (dépôt public).
 */

use App\Crm\FichesProtegees;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\ResolveurDnsSimule;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

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
});

afterEach(function () {
    foreach ($GLOBALS['zz_fedc_fichiers'] ?? [] as $f) {
        @unlink($f);
    }
    $GLOBALS['zz_fedc_fichiers'] = [];
});

/**
 * @param  array<string, mixed>  $surcharge
 * @return array<string, mixed>
 */
function fedcLigne(array $surcharge = []): array
{
    return array_replace([
        'siren' => '900000601', 'nom' => 'ZZ FEDE CORRECTIONS', 'famille' => 'federation_syndicat_pro',
        'niveau' => 'national', 'secteurs' => ['btp'], 'pertinence' => 'haute', 'contactabilite' => 'email_verifie',
        'personnes' => [],
    ], $surcharge);
}

function fedcFichier(string $contenu): string
{
    $chemin = (string) tempnam(sys_get_temp_dir(), 'zz-fedc-');
    $GLOBALS['zz_fedc_fichiers'][] = $chemin;
    file_put_contents($chemin, $contenu);

    return $chemin;
}

/**
 * @param  list<array<string, mixed>>  $lignes
 */
function fedcImporter(array $lignes): string
{
    $contenu = implode("\n", array_map(static fn (array $l): string => (string) json_encode($l), $lignes)) . "\n";
    Artisan::call('crm:import-federations', ['file' => fedcFichier($contenu)]);

    return Artisan::output();
}

function fedcCompteur(string $sortie, string $cle): int
{
    preg_match('/\|\s*' . preg_quote($cle, '/') . '\s*\|\s*(\d+)\s*\|/', $sortie, $m);
    expect($m)->not->toBeEmpty("Compteur « {$cle} » absent de la sortie.");

    return (int) $m[1];
}

function fedcFiche(string $siren): object
{
    $fiche = DB::table('companies')->where('siren', $siren)->first();
    expect($fiche)->not->toBeNull();

    return $fiche;
}

/** @return array<string, mixed> */
function fedcCanaux(string $siren): array
{
    $signals = (array) json_decode((string) fedcFiche($siren)->signals, true);

    return is_array($signals['contact_channels'] ?? null) ? $signals['contact_channels'] : [];
}

/** @return list<array<string, mixed>> */
function fedcDestinataires(array $options = [], string $segment = 'federations'): array
{
    // La liste ne retient que des adresses VÉRIFIÉES valides : on vérifie
    // d'abord, avec un DNS simulé où tout domaine reçoit.
    ResolveurDnsSimule::toutVerifier();
    $sortie = fedcFichier('');
    Artisan::call('crm:campagne:destinataires', ['segment' => $segment, 'sortie' => $sortie] + $options);

    return array_values(array_filter(array_map(
        fn ($l) => json_decode($l, true),
        file($sortie, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
    )));
}

function fedcTaguer(string $espace, int $companyId, string $slug): void
{
    $tag = DB::table('tags')->where('workspace_id', $espace)->where('slug', $slug)->value('id')
        ?? DB::table('tags')->insertGetId([
            'workspace_id' => $espace, 'slug' => $slug, 'name' => $slug, 'category' => 'intent',
            'kind' => 'auto', 'rules' => '{}', 'is_locked' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    DB::table('company_tag')->insertOrIgnore([
        'company_id' => $companyId, 'tag_id' => (int) $tag, 'workspace_id' => $espace,
        'assigned_at' => now(), 'assigned_by' => 'auto-rule',
    ]);
}

// ── R2 : une personne retirée ne revient pas ───────────────────────────────

test('R2 — une personne SANS e-mail supprimee ne revient pas au re-import, meme si la ligne change ; le temoin entre', function () {
    fedcImporter([fedcLigne(['personnes' => [['prenom' => 'Zed', 'nom' => 'ZZRETIRE', 'fonction' => 'Président', 'email' => null, 'linkedin' => null]]])]);
    $retire = DB::table('contacts')->where('last_name', 'ZZRETIRE')->value('id');
    expect($retire)->not->toBeNull();

    // Suppression (console, effacement ou purge : tous passent par DELETE).
    DB::table('contacts')->where('id', $retire)->delete();

    // La ligne change (autre téléphone, une personne de plus) : nouveau run_id.
    $sortie = fedcImporter([fedcLigne([
        'telephone' => '01 00 00 00 61',
        'personnes' => [
            ['prenom' => 'Zed', 'nom' => 'ZZRETIRE', 'fonction' => 'Président', 'email' => null, 'linkedin' => null],
            ['prenom' => 'Zia', 'nom' => 'ZZNOUVELLE', 'fonction' => 'Trésorière', 'email' => null, 'linkedin' => null],
        ],
    ])]);

    expect(DB::table('contacts')->where('last_name', 'ZZRETIRE')->exists())->toBeFalse()
        ->and(DB::table('contacts')->where('last_name', 'ZZNOUVELLE')->exists())->toBeTrue()
        ->and(fedcCompteur($sortie, 'personnes_retirees_ignorees'))->toBe(1)
        // Le registre ne garde qu'une EMPREINTE, jamais le nom.
        ->and(json_encode(DB::table('contacts_retires')->get()))->not->toContain('ZZRETIRE');
});

// ── R4 : la ligne et sa fiche dans le même espace ──────────────────────────

test('R4 — la base refuse une ligne federations dans un autre espace que sa fiche, et le deplacement d une fiche', function () {
    $autre = (string) Str::uuid();
    Workspace::create(['id' => $autre, 'slug' => 'zz-fedc-autre-' . Str::random(5), 'name' => 'ZZ autre']);
    $fiche = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '900000602', 'denomination' => 'ZZ espace',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $ligne = ['company_id' => $fiche, 'famille' => 'ordre', 'niveau' => 'national', 'pertinence' => 'haute', 'contactabilite' => 'aucun_contact'];

    $refuse = static function (callable $ecriture): bool {
        try {
            DB::transaction(fn () => $ecriture());

            return false;
        } catch (QueryException $e) {
            return str_contains($e->getMessage(), 'federation_espace_incoherent');
        }
    };

    expect($refuse(fn () => DB::table('federations')->insert($ligne + ['workspace_id' => $autre])))->toBeTrue();

    // Témoin : dans le bon espace, la ligne passe.
    DB::table('federations')->insert($ligne + ['workspace_id' => $this->espace]);
    expect(DB::table('federations')->where('company_id', $fiche)->exists())->toBeTrue()
        ->and($refuse(fn () => DB::table('companies')->where('id', $fiche)->update(['workspace_id' => $autre])))->toBeTrue();
});

// ── R3, B, D4 : le segment ─────────────────────────────────────────────────

test('R3 — une fiche taguee federation SANS classement n est jamais visee, meme avec --avec-pertinence-faible', function () {
    $sansLigne = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '900000603', 'denomination' => 'ZZ sans ligne',
        'email_generic' => 'contact@zz-sans-ligne.example.invalid', 'created_at' => now(), 'updated_at' => now(),
    ]);
    fedcTaguer($this->espace, $sansLigne, FichesProtegees::TAG_FEDERATIONS);
    fedcImporter([fedcLigne(['email_generique' => 'contact@zz-avec-ligne.example.invalid'])]);

    foreach ([[], ['--avec-pertinence-faible' => true]] as $options) {
        $emails = collect(fedcDestinataires($options))->pluck('email')->all();
        expect($emails)->toContain('contact@zz-avec-ligne.example.invalid')
            ->not->toContain('contact@zz-sans-ligne.example.invalid');
    }
});

test('B — les syndicats de salaries sont ecartes par defaut, et vises seulement sur option explicite', function () {
    fedcImporter([
        fedcLigne(['siren' => '900000604', 'famille' => 'syndicat_salaries', 'email_generique' => 'contact@zz-syndicat.example.invalid']),
        fedcLigne(['siren' => '900000605', 'email_generique' => 'contact@zz-patronal.example.invalid']),
    ]);

    $parDefaut = collect(fedcDestinataires())->pluck('email')->all();
    $avecOption = collect(fedcDestinataires(['--avec-syndicats-salaries' => true]))->pluck('email')->all();

    expect($parDefaut)->toBe(['contact@zz-patronal.example.invalid'])
        ->and($avecOption)->toContain('contact@zz-syndicat.example.invalid', 'contact@zz-patronal.example.invalid');
});

test('D4 — la liste de campagne dit le type d adresse et la date de verification du domaine', function () {
    fedcImporter([fedcLigne([
        'email_generique' => 'contact@zz-verifie.example.invalid', 'email_generique_verifie_le' => '2026-09-28',
        'personnes' => [['prenom' => 'Zoe', 'nom' => 'ZZVERIFIEE', 'fonction' => 'Présidente', 'email' => 'zoe@zz-verifie.example.invalid', 'email_verifie_le' => '2026-09-27', 'linkedin' => null]],
    ])]);

    // L'import écrit les dates du fichier…
    $verif = (array) json_decode((string) fedcFiche('900000601')->signals, true);
    expect($verif['email_generic_verification']['verifie_le'] ?? null)->toBe('2026-09-28');

    // … puis la liste, qui exige une vérification (`crm:emails:verifier`),
    // porte la date de la vérification la plus RÉCENTE — celle du jour. Le
    // TYPE, lui, reste celui du fichier.
    $lignes = collect(fedcDestinataires())->keyBy('email');

    expect($lignes['contact@zz-verifie.example.invalid']['nature_adresse'])->toBe('generique')
        ->and($lignes['contact@zz-verifie.example.invalid']['domaine_verifie_le'])->toBe(now()->toDateString())
        ->and($lignes['zoe@zz-verifie.example.invalid']['nature_adresse'])->toBe('nominatif')
        ->and($lignes['zoe@zz-verifie.example.invalid']['domaine_verifie_le'])->toBe(now()->toDateString());
});

// ── Canaux typés (adresses nominatives importées, effaçables) ──────────────

test('les adresses de emails_autres entrent en canaux TYPES ; une adresse opposee n y entre pas', function () {
    DB::table('opt_out')->insert([
        'email_hash' => hash('sha256', 'opposee@zz-canaux.example.invalid'), 'scope' => 'business',
        'source' => 'test', 'created_at' => now(),
    ]);

    fedcImporter([fedcLigne(['emails_autres' => [
        ['email' => 'jean.zz@zz-canaux.example.invalid', 'type' => 'nominatif', 'domaine_verifie' => true, 'verifie_le' => '2026-09-28'],
        ['email' => 'accueil@zz-canaux.example.invalid', 'type' => 'generique', 'domaine_verifie' => true, 'verifie_le' => '2026-09-28'],
        ['email' => 'opposee@zz-canaux.example.invalid', 'type' => 'nominatif', 'domaine_verifie' => true, 'verifie_le' => '2026-09-28'],
    ]])]);

    $canaux = fedcCanaux('900000601');
    expect($canaux['emails'])->toBe(['jean.zz@zz-canaux.example.invalid', 'accueil@zz-canaux.example.invalid'])
        ->and($canaux['details']['jean.zz@zz-canaux.example.invalid']['type'])->toBe('nominatif')
        ->and($canaux['details']['jean.zz@zz-canaux.example.invalid']['verifie_le'])->toBe('2026-09-28')
        ->and($canaux['details']['accueil@zz-canaux.example.invalid']['type'])->toBe('generique')
        ->and($canaux['details'])->not->toHaveKey('opposee@zz-canaux.example.invalid');
});

// ── D2 : la nature d'une fiche rattachée ───────────────────────────────────

test('D2 — une fiche INSEE « entreprise » rattachee devient federation ; une autre nature n est jamais remplacee', function () {
    DB::table('companies')->insert([
        ['workspace_id' => $this->espace, 'siren' => '900000606', 'denomination' => 'ZZ INSEE', 'entity_nature' => 'entreprise', 'created_at' => now(), 'updated_at' => now()],
        ['workspace_id' => $this->espace, 'siren' => '900000607', 'denomination' => 'ZZ ASSO', 'entity_nature' => 'association', 'created_at' => now(), 'updated_at' => now()],
        ['workspace_id' => $this->espace, 'siren' => '900000608', 'denomination' => 'ZZ CCI', 'entity_nature' => 'cci', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $sortie = fedcImporter([
        fedcLigne(['siren' => '900000606']),
        fedcLigne(['siren' => '900000607']),
        fedcLigne(['siren' => '900000608']),
    ]);

    expect(fedcFiche('900000606')->entity_nature)->toBe('federation')
        ->and(fedcFiche('900000607')->entity_nature)->toBe('association')
        ->and(fedcFiche('900000608')->entity_nature)->toBe('cci')
        ->and(fedcCompteur($sortie, 'natures_posees'))->toBe(1);
});

// ── D3 : aucune donnée vérifiée jetée ──────────────────────────────────────

test('D3 — une coordonnee du fichier differente de celle de la fiche part en canal, rien n est jete', function () {
    DB::table('companies')->insert([
        'workspace_id' => $this->espace, 'siren' => '900000609', 'denomination' => 'ZZ DEJA',
        'email_generic' => 'ancien@zz-deja.example.invalid', 'phone' => '01 00 00 00 01',
        'website' => 'https://ancien.example.invalid', 'linkedin_url' => 'https://www.linkedin.com/company/zz-ancien',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $sortie = fedcImporter([fedcLigne([
        'siren' => '900000609',
        'email_generique' => 'nouveau@zz-deja.example.invalid', 'telephone' => '01 00 00 00 02',
        'site' => 'https://nouveau.example.invalid', 'sites_autres' => ['https://autre.example.invalid'],
        'linkedin' => 'https://www.linkedin.com/company/zz-nouveau', 'linkedin_autres' => ['https://www.linkedin.com/in/zz-page-asso'],
    ])]);

    $fiche = fedcFiche('900000609');
    $canaux = fedcCanaux('900000609');
    expect($fiche->email_generic)->toBe('ancien@zz-deja.example.invalid')
        ->and($fiche->website)->toBe('https://ancien.example.invalid')
        ->and($canaux['emails'])->toContain('nouveau@zz-deja.example.invalid')
        ->and($canaux['phones'])->toContain('01 00 00 00 02')
        ->and($canaux['sites'])->toContain('https://nouveau.example.invalid', 'https://autre.example.invalid')
        ->and($canaux['linkedin'])->toContain('https://www.linkedin.com/company/zz-nouveau', 'https://www.linkedin.com/in/zz-page-asso')
        ->and(fedcCompteur($sortie, 'coordonnees_gardees_en_canaux'))->toBe(4);
});

// ── D6 : le secteur posé par cet import se corrige ─────────────────────────

test('D6 — un secteur pose par l import se corrige au re-import ; un secteur pose autrement ne bouge pas', function () {
    DB::table('companies')->insert([
        'workspace_id' => $this->espace, 'siren' => '900000610', 'denomination' => 'ZZ NAF',
        'sector_main' => 'agriculture', 'created_at' => now(), 'updated_at' => now(),
    ]);
    fedcImporter([fedcLigne(['siren' => '900000601', 'secteurs' => ['btp']]), fedcLigne(['siren' => '900000610', 'secteurs' => ['btp']])]);
    expect(fedcFiche('900000601')->sector_main)->toBe('btp');

    $sortie = fedcImporter([fedcLigne(['siren' => '900000601', 'secteurs' => ['sante']]), fedcLigne(['siren' => '900000610', 'secteurs' => ['sante']])]);

    expect(fedcFiche('900000601')->sector_main)->toBe('sante')
        ->and(fedcFiche('900000610')->sector_main)->toBe('agriculture')
        ->and(fedcCompteur($sortie, 'secteurs_corriges'))->toBe(1);
});

// ── D9 : la casse d'une adresse ────────────────────────────────────────────

test('D9 — la meme adresse ecrite avec d autres majuscules n est ni « non posee » ni recopiee en canal', function () {
    DB::table('companies')->insert([
        'workspace_id' => $this->espace, 'siren' => '900000611', 'denomination' => 'ZZ CASSE',
        'email_generic' => 'Contact@ZZ-Casse.example.invalid', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $sortie = fedcImporter([fedcLigne(['siren' => '900000611', 'email_generique' => 'contact@zz-casse.example.invalid'])]);

    expect(fedcCompteur($sortie, 'emails_generiques_non_poses'))->toBe(0)
        ->and(fedcCompteur($sortie, 'coordonnees_gardees_en_canaux'))->toBe(0);
});

// ── D8 : une seule définition de « événement à venir » ─────────────────────

test('D8 — l onglet et la campagne disent la MEME chose d un evenement recurrent et d un evenement sans date', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'admin-' . Str::random(6) . '@example.invalid', 'name' => 'ZZ admin',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $this->espace,
        'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($this->espace);
    $user->assignRole('admin');
    $this->actingAs($user);

    fedcImporter([
        fedcLigne(['siren' => '900000612', 'email_generique' => 'contact@zz-recurrent.example.invalid']),
        fedcLigne(['siren' => '900000613', 'email_generique' => 'contact@zz-sans-date.example.invalid']),
    ]);
    foreach (['900000612' => 'chaque mardi', '900000613' => null] as $siren => $recurrence) {
        $evt = (int) DB::table('events')->insertGetId([
            'workspace_id' => $this->espace, 'external_ref' => 'zz-' . $siren, 'nom' => 'ZZ réunion', 'type' => 'club-affaires',
            'date_debut' => null, 'recurrence' => $recurrence, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('event_organizers')->insert([
            'event_id' => $evt, 'company_id' => fedcFiche($siren)->id, 'workspace_id' => $this->espace, 'created_at' => now(),
        ]);
    }

    $api = collect($this->getJson('/api/v1/federations')->assertOk()->json('data'))->keyBy('id');
    $campagne = collect(fedcDestinataires())->keyBy('email');

    $recurrent = (int) fedcFiche('900000612')->id;
    $sansDate = (int) fedcFiche('900000613')->id;
    expect($api[$recurrent]['evenement_a_venir'])->toBeTrue()
        ->and($campagne['contact@zz-recurrent.example.invalid']['evenement'])->not->toBeNull()
        ->and($api[$sansDate]['evenement_a_venir'])->toBeFalse()
        ->and($campagne['contact@zz-sans-date.example.invalid']['evenement'])->toBeNull();
});

// ── S7 : le registre des retraits ──────────────────────────────────────────

test('S7 — l empreinte du registre est SALEE : ce n est pas le SHA-256 du nom', function () {
    fedcImporter([fedcLigne(['personnes' => [['prenom' => 'Zed', 'nom' => 'ZZSALE', 'fonction' => 'Président', 'email' => null, 'linkedin' => null]]])]);
    DB::table('contacts')->where('last_name', 'ZZSALE')->delete();

    $cle = (string) DB::table('contacts_retires')->value('cle_nom');
    $sansSel = (string) DB::selectOne("SELECT encode(digest(normalize_name('Zed' || '_' || 'ZZSALE'), 'sha256'), 'hex') AS h")->h;

    expect($cle)->not->toBe('')
        ->and($cle)->not->toBe($sansSel)
        // La même personne donne la même empreinte (le registre la reconnaît).
        ->and($cle)->toBe((string) DB::selectOne("SELECT contacts_retires_empreinte('Zed', 'ZZSALE') AS h")->h);
});

test('S7 — une fiche supprimee EN CASCADE garde le SIREN de ses personnes au registre', function () {
    fedcImporter([fedcLigne(['personnes' => [['prenom' => 'Zed', 'nom' => 'ZZCASCADE', 'fonction' => 'Président', 'email' => null, 'linkedin' => null]]])]);
    $fiche = (int) fedcFiche('900000601')->id;

    DB::transaction(function () use ($fiche): void {
        // Levée volontaire documentée : la fiche est protégée.
        DB::statement("SET LOCAL app.autoriser_suppression_protegee = 'on'");
        DB::table('companies')->where('id', $fiche)->delete();
    });

    expect(DB::table('contacts')->where('last_name', 'ZZCASCADE')->exists())->toBeFalse()
        ->and(DB::table('contacts_retires')->pluck('siren')->map(fn ($s) => trim((string) $s))->all())->toBe(['900000601']);
});

test('B4 — supprimer un ESPACE en cascade n est pas bloque par le registre, et n y inscrit rien', function () {
    $espace = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $espace, 'slug' => 'zz-fedc-b4', 'name' => 'ZZ espace supprime', 'settings' => '{}',
        'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    // Une fiche NON protégée, et une personne venue de l'import des
    // fédérations : exactement ce que les deux déclencheurs mémorisent.
    $fiche = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $espace, 'siren' => '900000690', 'denomination' => 'ZZ fiche de l espace supprime',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('contacts')->insert([
        'workspace_id' => $espace, 'company_id' => $fiche, 'first_name' => 'Zed', 'last_name' => 'ZZESPACE',
        'sources' => json_encode(['federations-2026']), 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('workspaces')->where('id', $espace)->delete();

    expect(DB::table('workspaces')->where('id', $espace)->exists())->toBeFalse()
        ->and(DB::table('companies')->where('id', $fiche)->exists())->toBeFalse()
        ->and(DB::table('contacts_retires')->where('workspace_id', $espace)->count())->toBe(0);
});

// ── B1 : le registre survit au retour arrière ──────────────────────────────

test('B1 — le retour arriere de la migration garde le registre des retraits ET sa cle', function () {
    fedcImporter([fedcLigne(['personnes' => [['prenom' => 'Zed', 'nom' => 'ZZRETOUR', 'fonction' => 'Président', 'email' => null, 'linkedin' => null]]])]);
    DB::table('contacts')->where('last_name', 'ZZRETOUR')->delete();
    $cle = (string) DB::selectOne("SELECT encode(cle, 'hex') AS c FROM contacts_retires_cle")->c;
    expect(DB::table('contacts_retires')->count())->toBe(1);

    // Dans l'ordre inverse des migrations. Aucune fiche `federation` ici :
    // `down()` refuserait sinon (cf. son garde-fou).
    DB::table('companies')->where('entity_nature', 'federation')->update(['entity_nature' => null]);
    (require database_path('migrations/2026_09_29_000002_federations_validation_des_check.php'))->down();
    (require database_path('migrations/2026_09_29_000001_federations.php'))->down();

    expect(DB::table('contacts_retires')->count())->toBe(1)
        ->and((string) DB::selectOne("SELECT encode(cle, 'hex') AS c FROM contacts_retires_cle")->c)->toBe($cle)
        ->and(DB::selectOne("SELECT to_regprocedure('public.contacts_retires_empreinte(text,text)') IS NOT NULL AS e")->e)->toBeTrue();
});
