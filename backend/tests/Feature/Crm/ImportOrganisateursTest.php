<?php

/**
 * IMPORT DES ORGANISATEURS D'ÉVÉNEMENTS PAR LA PORTE COMMUNE (2026-09-27).
 *
 * Les pièges relevés avant l'import, chacun prouvé par son effet face à un
 * témoin. Fixtures FICTIVES uniquement (dépôt public).
 */

use App\Crm\Scraping\ScrapedRecord;
use App\Crm\Scraping\ScrapedRecordIngestService;
use App\Crm\Scraping\ScrapeIngestOutcome;
use App\Crm\Scraping\ScrapeIngestRejection;
use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config([
        'crm.scrape_funnel.enabled' => true,
        'crm.scrape_funnel.validate_mx' => false,
        'crm.ingest.business_workspace' => 'axion-ia',
    ]);

    $this->espace = (string) (DB::table('workspaces')->where('slug', 'axion-ia')->value('id') ?? Str::uuid());
    if (! DB::table('workspaces')->where('id', $this->espace)->exists()) {
        DB::table('workspaces')->insert([
            'id' => $this->espace, 'slug' => 'axion-ia', 'name' => 'Axion-IA', 'settings' => '{}',
            'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $this->seed(ScrapingSourcesSeeder::class);
});

/** @param  array<string, mixed>  $surcharge */
function orgaLigne(array $surcharge = []): array
{
    return array_replace_recursive([
        'schema_version' => 1,
        'source' => 'evenements-pro',
        'run_id' => 'evt-' . Str::random(8),
        'status' => 'success',
        'company' => [
            'foreign_id' => 'evt:zz-club-affaires',
            'country' => 'FR',
            'nature' => 'reseau',
            'fields' => ['denomination' => 'ZZ Club d affaires'],
        ],
        'persons' => [],
    ], $surcharge);
}

function orgaIngerer(array $brut): ScrapeIngestOutcome
{
    return app(ScrapedRecordIngestService::class)->ingest(ScrapedRecord::fromArray($brut), false);
}

function orgaOpposer(?string $email, ?string $telephone = null): void
{
    DB::table('opt_out')->insert([
        'email' => null,
        'email_hash' => $email !== null ? hash('sha256', $email) : null,
        'phone' => $telephone,
        'scope' => 'business',
        'source' => 'test',
        'created_at' => now(),
    ]);
}

function orgaFiche(): object
{
    return DB::table('companies')->where('foreign_id', 'evt:zz-club-affaires')->first();
}

test('une adresse generique en opposition n entre pas sur la fiche, le temoin oui', function () {
    orgaOpposer('bureau@zz-club.example.invalid');

    orgaIngerer(orgaLigne(['company' => ['fields' => ['email_generic' => 'bureau@zz-club.example.invalid']]]));
    expect(orgaFiche()->email_generic)->toBeNull();

    orgaIngerer(orgaLigne(['company' => [
        'foreign_id' => 'evt:zz-temoin', 'fields' => ['email_generic' => 'accueil@zz-temoin.example.invalid'],
    ]]));
    expect(DB::table('companies')->where('foreign_id', 'evt:zz-temoin')->value('email_generic'))
        ->toBe('accueil@zz-temoin.example.invalid');
});

test('une boite service en opposition ne devient pas l adresse generique', function () {
    orgaOpposer('contact@zz-club.example.invalid');

    orgaIngerer(orgaLigne(['persons' => [[
        'last_name' => 'Contact', 'email' => 'contact@zz-club.example.invalid', 'kind' => 'service_mailbox',
    ]]]));

    expect(orgaFiche()->email_generic)->toBeNull();
});

test('un telephone en opposition n entre ni sur la fiche ni sur une personne', function () {
    orgaOpposer(null, '0600000001');

    $outcome = orgaIngerer(orgaLigne([
        'company' => ['fields' => ['phone' => '06 00 00 00 01']],
        'persons' => [['last_name' => 'ZZ Opposee', 'phone' => '06.00.00.00.01']],
    ]));

    expect(orgaFiche()->phone)->toBeNull()
        ->and($outcome->personsSkippedOptOut)->toBe(1)
        ->and(DB::table('contacts')->count())->toBe(0);
});

test('un telephone oppose en 06 est reconnu ecrit en +33, 0033 ou +33 (0)', function () {
    orgaOpposer(null, '0600000004');

    foreach (['+33 6 00 00 00 04', '0033600000004', '+33 (0)6 00 00 00 04'] as $i => $ecriture) {
        $outcome = orgaIngerer(orgaLigne([
            'company' => ['foreign_id' => 'evt:zz-format-' . $i],
            'persons' => [['last_name' => 'ZZ Format ' . $i, 'phone' => $ecriture]],
        ]));
        expect($outcome->personsSkippedOptOut)->toBe(1);
    }

    // Témoin : un autre numéro passe.
    $temoin = orgaIngerer(orgaLigne([
        'company' => ['foreign_id' => 'evt:zz-format-temoin'],
        'persons' => [['last_name' => 'ZZ Format temoin', 'phone' => '+33 6 00 00 00 05']],
    ]));
    expect($temoin->contactsCreated)->toBe(1);
});

test('les adresses et telephones recoltes en opposition sont ecartes des canaux', function () {
    orgaOpposer('opposee@zz-club.example.invalid', '0600000002');

    orgaIngerer(orgaLigne(['channels' => [
        'emails' => ['opposee@zz-club.example.invalid', 'libre@zz-club.example.invalid'],
        'phones' => ['0600000002', '0600000003'],
    ]]));

    $canaux = json_decode((string) orgaFiche()->signals, true)['contact_channels'];
    expect($canaux['emails'])->toBe(['libre@zz-club.example.invalid'])
        ->and($canaux['phones'])->toBe(['0600000003']);
});

test('le formulaire de contact et le departement sont gardes', function () {
    orgaIngerer(orgaLigne(['company' => ['fields' => [
        'contact_form_url' => 'https://zz-club.example.invalid/contact',
        'department_code' => '69',
    ]]]));

    $fiche = orgaFiche();
    expect(json_decode((string) $fiche->signals, true)['contact_form_url'])->toBe('https://zz-club.example.invalid/contact')
        ->and($fiche->department_code)->toBe('69');
});

test('un formulaire ou un departement mal forme est refuse a la porte', function () {
    expect(fn () => ScrapedRecord::fromArray(orgaLigne(['company' => ['fields' => ['contact_form_url' => 'javascript:alert(1)']]])))
        ->toThrow(ScrapeIngestRejection::class)
        ->and(fn () => ScrapedRecord::fromArray(orgaLigne(['company' => ['fields' => ['department_code' => 'Rhone']]])))
        ->toThrow(ScrapeIngestRejection::class);
});

test('une adresse grand public est marquee personnelle, une adresse pro non', function () {
    orgaIngerer(orgaLigne(['persons' => [
        ['last_name' => 'ZZ Perso', 'email' => 'zz.perso@gmail.com'],
        ['last_name' => 'ZZ Pro', 'email' => 'zz.pro@zz-club.example.invalid'],
    ]]));

    $perso = json_decode((string) DB::table('contacts')->where('last_name', 'ZZ Perso')->value('metadata'), true);
    $pro = json_decode((string) DB::table('contacts')->where('last_name', 'ZZ Pro')->value('metadata'), true);
    expect($perso['email_nature'] ?? null)->toBe('perso')
        ->and($pro['email_nature'] ?? null)->toBeNull();
});

test('pour cette source, la personne est rattachee a l organisateur qui l affiche, pas a une autre fiche', function () {
    // La même personne dirige sa propre entreprise, déjà en base.
    $entreprise = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '900000911', 'denomination' => 'ZZ Entreprise du president',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('contacts')->insert([
        'workspace_id' => $this->espace, 'company_id' => $entreprise, 'last_name' => 'ZZ President',
        'email' => 'president@zz-club.example.invalid', 'created_at' => now(), 'updated_at' => now(),
    ]);

    orgaIngerer(orgaLigne(['persons' => [['last_name' => 'ZZ President', 'email' => 'president@zz-club.example.invalid']]]));

    expect(DB::table('contacts')->where('company_id', orgaFiche()->id)->count())->toBe(1)
        ->and(DB::table('contacts')->where('company_id', $entreprise)->count())->toBe(1);
});

test('TEMOIN : pour une autre source, la dedup par adresse reste globale', function () {
    $entreprise = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '900000912', 'denomination' => 'ZZ Autre',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('contacts')->insert([
        'workspace_id' => $this->espace, 'company_id' => $entreprise, 'last_name' => 'ZZ Global',
        'email' => 'global@zz-autre.example.invalid', 'created_at' => now(), 'updated_at' => now(),
    ]);

    orgaIngerer(orgaLigne([
        'source' => 'implantations-fr-etranger',
        'company' => ['foreign_id' => 'evt:zz-autre-source'],
        'persons' => [['last_name' => 'ZZ Global', 'email' => 'global@zz-autre.example.invalid']],
    ]));

    expect(DB::table('contacts')->where('email', 'global@zz-autre.example.invalid')->count())->toBe(1);
});

test('un re-import d un run dont la cle a ete purgee ne fait plus echouer la ligne', function () {
    $ligne = orgaLigne(['run_id' => 'evt-rejeu-fixe']);
    orgaIngerer($ligne);
    // La purge à 90 jours retire la clé d'idempotence ; l'activité, elle, reste.
    DB::table('scraper_runs')->where('dedup_key', 'pivot:evenements-pro:evt-rejeu-fixe')->delete();

    $outcome = orgaIngerer($ligne);

    expect($outcome->status)->toBe(ScrapeIngestOutcome::UPDATED)
        ->and(DB::table('companies')->where('foreign_id', 'evt:zz-club-affaires')->count())->toBe(1);
});

test('l essai a blanc du fichier n ecrit rien et compte comme l import reel', function () {
    // Le MÊME organisateur sur deux lignes : annulé ligne par ligne, il était
    // annoncé « créé » deux fois.
    $chemin = tempnam(sys_get_temp_dir(), 'zz-orga-');
    file_put_contents($chemin, implode("\n", [
        json_encode(orgaLigne(['persons' => [['last_name' => 'ZZ Un', 'email' => 'un@zz-club.example.invalid']]])),
        json_encode(orgaLigne()),
    ]) . "\n");

    try {
        Artisan::call('scraping:ingest-file', ['source' => 'evenements-pro', 'file' => $chemin, '--dry-run' => true]);
        $aBlanc = Artisan::output();
        expect(DB::table('companies')->where('foreign_id', 'evt:zz-club-affaires')->exists())->toBeFalse();

        Artisan::call('scraping:ingest-file', ['source' => 'evenements-pro', 'file' => $chemin]);
        $reel = Artisan::output();
    } finally {
        @unlink($chemin);
    }

    foreach (['created : 1', 'updated : 1', 'contacts_crees : 1'] as $attendu) {
        expect($aBlanc)->toContain($attendu)
            ->and($reel)->toContain($attendu);
    }
});
