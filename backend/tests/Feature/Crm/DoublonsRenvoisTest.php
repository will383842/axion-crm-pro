<?php

/**
 * DOUBLONS — APRÈS UNE FUSION, LES ANCRES DE LA FICHE ABSORBÉE MÈNENT À LA
 * FICHE GARDÉE (E4, chantier 5).
 *
 * Un import qui cherche une fiche par son identifiant de source la trouvait à
 * la corbeille : l'événement perdait son organisateur, la fédération était
 * refusée. `FusionFiches::gardeDe` suit le journal des fusions ; l'annulation
 * défait le renvoi (et rend à la fiche absorbée le lien posé entre-temps).
 *
 * Fixtures FICTIVES (dépôt public).
 */

use App\Crm\Doublons\FusionFiches;
use App\Crm\Doublons\Rapprochement;
use App\Crm\Scraping\ScrapedRecord;
use App\Crm\Scraping\ScrapedRecordIngestService;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    config(['crm.scrape_funnel.validate_mx' => false]);
    $this->seed(ScrapingSourcesSeeder::class);
    $this->ws = F::espace('zz-doublons-renvois');
    config(['crm.ingest.business_workspace' => F::slug($this->ws)]);
    $GLOBALS['zz_drv_fichiers'] = [];
});

afterEach(function () {
    foreach ($GLOBALS['zz_drv_fichiers'] ?? [] as $f) {
        @unlink($f);
    }
});

/** @param  list<array<string, mixed>>  $lignes */
function drvFichier(array $lignes): string
{
    $chemin = (string) tempnam(sys_get_temp_dir(), 'zz-drv-');
    $GLOBALS['zz_drv_fichiers'][] = $chemin;
    file_put_contents($chemin, implode("\n", array_map(static fn (array $l): string => (string) json_encode($l, JSON_UNESCAPED_UNICODE), $lignes)) . "\n");

    return $chemin;
}

function drvFusionner(string $ws, int $garde, int $absorbee): int
{
    return WorkspaceContext::run($ws, fn (): int => app(FusionFiches::class)->fusionner($ws, $garde, $absorbee, Rapprochement::NOM_CP, FusionFiches::MODE_MANUEL, null, null, 'test'));
}

function drvAnnuler(string $ws, int $fusion): void
{
    WorkspaceContext::run($ws, fn (): array => app(FusionFiches::class)->annuler($ws, $fusion, 'test'));
}

/** @return list<int> */
function drvOrganisateurs(string $ref): array
{
    $event = DB::table('events')->where('external_ref', $ref)->value('id');

    return DB::table('event_organizers')->where('event_id', $event)->orderBy('company_id')->pluck('company_id')->map(fn ($id): int => (int) $id)->all();
}

test('un import d événement dont l organisateur a été absorbé se relie à la fiche gardée — l annulation défait le lien', function () {
    $garde = F::fiche($this->ws, 'ZZ Club Renvoi', ['postcode' => '69030']);
    $absorbee = F::sansSiren($this->ws, 'ZZ Club Renvoi', ['postcode' => '69030', 'foreign_id' => 'evt:zz-club-renvoi']);
    $fusion = drvFusionner($this->ws, $garde, $absorbee);
    $fiches = DB::table('companies')->where('workspace_id', $this->ws)->count();
    $ligne = [
        'external_ref' => 'zz-renvoi-1', 'nom' => 'ZZ Événement renvoi', 'type' => 'club-affaires', 'date_debut' => '2026-11-15',
        'ville' => 'Lyon', 'region' => 'AURA', 'verifie' => true,
        'organisateurs' => [['country' => 'FR', 'foreign_id' => 'evt:zz-club-renvoi']],
    ];

    Artisan::call('crm:import-evenements', ['file' => drvFichier([$ligne])]);
    $sortie = Artisan::output();

    expect(drvOrganisateurs('zz-renvoi-1'))->toBe([$garde])
        ->and(F::compteur($sortie, 'organisateurs_introuvables'))->toBe(0)
        ->and(F::compteur($sortie, 'liens_via_une_fusion'))->toBe(1)
        // Aucune fiche créée : pas de doublon.
        ->and(DB::table('companies')->where('workspace_id', $this->ws)->count())->toBe($fiches);

    drvAnnuler($this->ws, $fusion);

    // Le lien posé par le renvoi revient à la fiche absorbée…
    expect(drvOrganisateurs('zz-renvoi-1'))->toBe([$absorbee]);
    // … et un nouvel import relie la fiche restaurée, plus la gardée.
    Artisan::call('crm:import-evenements', ['file' => drvFichier([array_replace($ligne, ['external_ref' => 'zz-renvoi-2'])])]);
    expect(drvOrganisateurs('zz-renvoi-2'))->toBe([$absorbee]);
    // Une fusion ANNULÉE ne renvoie plus rien : la fiche, remise à la
    // corbeille à la main ensuite, n'est plus reliée du tout.
    DB::table('companies')->where('id', $absorbee)->update(['deleted_at' => now()]);
    Artisan::call('crm:import-evenements', ['file' => drvFichier([array_replace($ligne, ['external_ref' => 'zz-renvoi-4'])])]);
    expect(drvOrganisateurs('zz-renvoi-4'))->toBe([])
        ->and(F::compteur(Artisan::output(), 'organisateurs_introuvables'))->toBe(1);
});

test('TÉMOIN — une fiche mise à la corbeille SANS fusion n est jamais reliée', function () {
    $corbeille = F::sansSiren($this->ws, 'ZZ Club Corbeille', ['foreign_id' => 'evt:zz-club-corbeille', 'deleted_at' => now()]);

    Artisan::call('crm:import-evenements', ['file' => drvFichier([[
        'external_ref' => 'zz-renvoi-3', 'nom' => 'ZZ Événement corbeille', 'type' => 'club-affaires', 'date_debut' => '2026-11-15',
        'ville' => 'Lyon', 'region' => 'AURA', 'verifie' => true,
        'organisateurs' => [['country' => 'FR', 'foreign_id' => 'evt:zz-club-corbeille']],
    ]])]);

    expect(drvOrganisateurs('zz-renvoi-3'))->toBe([])
        ->and(F::compteur(Artisan::output(), 'organisateurs_introuvables'))->toBe(1)
        ->and($corbeille)->toBeGreaterThan(0);
});

test('un import de fédération qui vise une fiche absorbée met à jour la fiche gardée, sans doublon ni refus', function () {
    $garde = F::fiche($this->ws, 'ZZ Union Renvoi', ['postcode' => '69000']);
    $absorbee = F::sansSiren($this->ws, 'ZZ Union Renvoi', ['postcode' => '69000', 'foreign_id' => 'section:zz-renvoi:69', 'discovery_source' => 'federations-2026']);
    drvFusionner($this->ws, $garde, $absorbee);
    $fiches = DB::table('companies')->where('workspace_id', $this->ws)->count();

    $code = Artisan::call('crm:import-federations', ['file' => drvFichier([[
        'siren' => null, 'identifiant' => 'section:zz-renvoi:69', 'nom' => 'ZZ UNION RENVOI', 'famille' => 'confederation',
        'niveau' => 'departemental', 'secteurs' => ['interprofessionnel'], 'tailles_adherents' => ['tpe'], 'pertinence' => 'haute',
        'contactabilite' => 'email_verifie', 'departement' => '69', 'email_generique' => 'contact@zz-renvoi.example.invalid',
        'personnes' => [['prenom' => 'Zoe', 'nom' => 'ZZRENVOI', 'fonction' => 'Secrétaire', 'email' => null, 'linkedin' => null]],
        'tete_de_reseau' => null,
    ]])]);
    $sortie = Artisan::output();

    expect($code)->toBe(0)
        ->and($sortie)->not->toContain('fiche_a_la_corbeille')
        ->and(DB::table('companies')->where('workspace_id', $this->ws)->count())->toBe($fiches)
        ->and(DB::table('federations')->where('company_id', $garde)->exists())->toBeTrue()
        ->and(DB::table('contacts')->where('company_id', $garde)->where('last_name', 'ZZRENVOI')->exists())->toBeTrue()
        ->and(DB::table('contacts')->where('company_id', $absorbee)->count())->toBe(0)
        ->and(DB::table('companies')->where('id', $absorbee)->value('deleted_at'))->not->toBeNull();
});

/**
 * Une ligne de fédération SANS SIREN, fictive.
 *
 * @param  list<array<string, mixed>>  $personnes
 * @return array<string, mixed>
 */
function drvLigneFede(string $identifiant, array $personnes): array
{
    return [
        'siren' => null, 'identifiant' => $identifiant, 'nom' => 'ZZ UNION VETO', 'famille' => 'confederation',
        'niveau' => 'departemental', 'secteurs' => ['interprofessionnel'], 'tailles_adherents' => ['tpe'], 'pertinence' => 'haute',
        'contactabilite' => 'email_verifie', 'departement' => '69', 'email_generique' => null,
        'personnes' => $personnes, 'tete_de_reseau' => null,
    ];
}

test('VETO RGPD — une personne retirée de la fiche GARDÉE ne revient pas par l ancre de la fiche absorbée (avec ou sans e-mail)', function () {
    $garde = F::fiche($this->ws, 'ZZ Union Veto', ['postcode' => '69000']);
    $absorbee = F::sansSiren($this->ws, 'ZZ Union Veto', ['postcode' => '69000', 'foreign_id' => 'section:zz-veto:69', 'discovery_source' => 'federations-2026']);
    drvFusionner($this->ws, $garde, $absorbee);
    // Zoé (avec e-mail) et Zed (SANS e-mail) sont sur la fiche gardée, puis
    // retirées : le registre (qui suit les personnes venues de l'import des
    // fédérations) les inscrit sous l'ancre de la GARDÉE (son SIREN).
    F::contact($this->ws, $garde, 'Zoe', 'ZZVETOUN', ['email' => 'zoe.veto@zz-veto.example.invalid', 'sources' => '["federations-2026"]']);
    F::contact($this->ws, $garde, 'Zed', 'ZZVETODEUX', ['sources' => '["federations-2026"]']);
    DB::table('contacts')->where('company_id', $garde)->whereIn('last_name', ['ZZVETOUN', 'ZZVETODEUX'])->delete();
    expect(DB::table('contacts_retires')->where('workspace_id', $this->ws)->count())->toBe(2);

    Artisan::call('crm:import-federations', ['file' => drvFichier([drvLigneFede('section:zz-veto:69', [
        ['prenom' => 'Zoe', 'nom' => 'ZZVETOUN', 'fonction' => 'Présidente', 'email' => 'zoe.veto@zz-veto.example.invalid', 'linkedin' => null],
        ['prenom' => 'Zed', 'nom' => 'ZZVETODEUX', 'fonction' => 'Trésorier', 'email' => null, 'linkedin' => null],
    ])])]);
    $sortie = Artisan::output();

    expect(DB::table('contacts')->where('workspace_id', $this->ws)->whereIn('last_name', ['ZZVETOUN', 'ZZVETODEUX'])->count())->toBe(0)
        // Écartées PAR L'IMPORT (registre interrogé avec les deux ancres).
        ->and(F::compteur($sortie, 'personnes_retirees_ignorees'))->toBe(2);
});

test('VETO RGPD — la collecte (funnel) qui suit un renvoi n ajoute pas une personne retirée de la fiche gardée', function () {
    $garde = F::fiche($this->ws, 'ZZ Club Veto', ['postcode' => '69000']);
    $absorbee = F::sansSiren($this->ws, 'ZZ Club Veto', ['postcode' => '69000', 'foreign_id' => 'evt:zz-club-veto']);
    drvFusionner($this->ws, $garde, $absorbee);
    F::contact($this->ws, $garde, 'Zed', 'ZZVETOTROIS', ['sources' => '["federations-2026"]']);
    DB::table('contacts')->where('company_id', $garde)->where('last_name', 'ZZVETOTROIS')->delete();
    expect(DB::table('contacts_retires')->where('workspace_id', $this->ws)->count())->toBe(1);

    $message = [
        'schema_version' => ScrapedRecord::SCHEMA_VERSION, 'source' => 'evenements-pro', 'status' => 'success',
        'run_id' => 'zz-veto-funnel-1',
        'company' => ['foreign_id' => 'evt:zz-club-veto', 'country' => 'FR', 'fields' => ['denomination' => 'ZZ Club Veto']],
        'persons' => [
            ['kind' => 'person', 'first_name' => 'Zed', 'last_name' => 'ZZVETOTROIS'],
            // TÉMOIN : une personne jamais retirée est bien ajoutée.
            ['kind' => 'person', 'first_name' => 'Zoe', 'last_name' => 'ZZVETOTEMOIN'],
        ],
    ];
    $outcome = app(ScrapedRecordIngestService::class)->ingest(ScrapedRecord::fromArray($message));

    expect($outcome->companyId)->toBe($garde)
        ->and($outcome->personsSkipped['retiree_via_fiche_absorbee'] ?? 0)->toBe(1)
        ->and(DB::table('contacts')->where('company_id', $garde)->where('last_name', 'ZZVETOTROIS')->exists())->toBeFalse()
        ->and(DB::table('contacts')->where('company_id', $garde)->where('last_name', 'ZZVETOTEMOIN')->exists())->toBeTrue();
});

test('chaîne A→B puis B→C : le lien d événement est journalisé dans les DEUX fusions ; annulées, elles le rendent à A', function () {
    $a = F::sansSiren($this->ws, 'ZZ Chaine', ['postcode' => '69040', 'foreign_id' => 'evt:zz-chaine-a']);
    $b = F::sansSiren($this->ws, 'ZZ Chaine', ['postcode' => '69040', 'foreign_id' => 'evt:zz-chaine-b']);
    $c = F::fiche($this->ws, 'ZZ Chaine', ['postcode' => '69040']);
    $ab = drvFusionner($this->ws, $b, $a);
    $bc = drvFusionner($this->ws, $c, $b);

    Artisan::call('crm:import-evenements', ['file' => drvFichier([[
        'external_ref' => 'zz-chaine-1', 'nom' => 'ZZ Événement chaîne', 'type' => 'club-affaires', 'date_debut' => '2026-11-15',
        'ville' => 'Lyon', 'region' => 'AURA', 'verifie' => true,
        'organisateurs' => [['country' => 'FR', 'foreign_id' => 'evt:zz-chaine-a']],
    ]])]);
    expect(drvOrganisateurs('zz-chaine-1'))->toBe([$c]);

    drvAnnuler($this->ws, $bc);
    expect(drvOrganisateurs('zz-chaine-1'))->toBe([$b]);
    drvAnnuler($this->ws, $ab);
    expect(drvOrganisateurs('zz-chaine-1'))->toBe([$a]);
});

test('une fusion annulée PENDANT l import : la ligne est refusée en entier, rien n est écrit à moitié', function () {
    $garde = F::fiche($this->ws, 'ZZ Club Perdu', ['postcode' => '69050']);
    $absorbee = F::sansSiren($this->ws, 'ZZ Club Perdu', ['postcode' => '69050', 'foreign_id' => 'evt:zz-club-perdu']);
    $fusion = drvFusionner($this->ws, $garde, $absorbee);
    // Juste après la lecture du renvoi, la fusion est annulée (course).
    $fait = false;
    DB::listen(function ($requete) use (&$fait, $fusion): void {
        if ($fait || ! str_starts_with(ltrim($requete->sql), 'SELECT id, garde_id FROM fusions_fiches')) {
            return;
        }
        $fait = true;
        DB::table('fusions_fiches')->where('id', $fusion)->update(['annulee_at' => now()]);
    });

    Artisan::call('crm:import-evenements', ['file' => drvFichier([[
        'external_ref' => 'zz-perdu-1', 'nom' => 'ZZ Événement perdu', 'type' => 'club-affaires', 'date_debut' => '2026-11-15',
        'ville' => 'Lyon', 'region' => 'AURA', 'verifie' => true,
        'organisateurs' => [['country' => 'FR', 'foreign_id' => 'evt:zz-club-perdu']],
    ]])]);
    $sortie = Artisan::output();

    expect($fait)->toBeTrue()
        ->and(F::compteur($sortie, 'rejetes'))->toBe(1)
        ->and(DB::table('events')->where('external_ref', 'zz-perdu-1')->exists())->toBeFalse()
        ->and(DB::table('event_organizers')->where('company_id', $garde)->exists())->toBeFalse();
});

test('RÉSERVE C — une personne retirée de A AVANT A→B ne revient pas par l ancre de B (import des fédérations)', function () {
    $b = F::fiche($this->ws, 'ZZ Union Avant', ['postcode' => '69000']);
    $a = F::sansSiren($this->ws, 'ZZ Union Avant', ['postcode' => '69000', 'foreign_id' => 'section:zz-avant:69', 'discovery_source' => 'federations-2026']);
    // Zed, sans e-mail, est retiré de A : le registre l'inscrit sous l'ancre de A.
    F::contact($this->ws, $a, 'Zed', 'ZZAVANT', ['sources' => '["federations-2026"]']);
    DB::table('contacts')->where('company_id', $a)->where('last_name', 'ZZAVANT')->delete();
    drvFusionner($this->ws, $b, $a);
    $sirenB = (string) DB::table('companies')->where('id', $b)->value('siren');

    Artisan::call('crm:import-federations', ['file' => drvFichier([array_replace(drvLigneFede('section:zz-inutile:69', [
        ['prenom' => 'Zed', 'nom' => 'ZZAVANT', 'fonction' => 'Trésorier', 'email' => null, 'linkedin' => null],
    ]), ['siren' => $sirenB, 'identifiant' => null])])]);
    $sortie = Artisan::output();

    expect(DB::table('contacts')->where('workspace_id', $this->ws)->where('last_name', 'ZZAVANT')->count())->toBe(0)
        ->and(F::compteur($sortie, 'personnes_retirees_ignorees'))->toBe(1);
});

test('RÉSERVE C — la collecte par l ancre de la fiche gardée n ajoute pas une personne retirée d une fiche absorbée', function () {
    $b = F::fiche($this->ws, 'ZZ Club Avant', ['postcode' => '69000']);
    $a = F::sansSiren($this->ws, 'ZZ Club Avant', ['postcode' => '69000', 'foreign_id' => 'evt:zz-club-avant']);
    F::contact($this->ws, $a, 'Zed', 'ZZAVANTDEUX', ['sources' => '["federations-2026"]']);
    DB::table('contacts')->where('company_id', $a)->where('last_name', 'ZZAVANTDEUX')->delete();
    drvFusionner($this->ws, $b, $a);

    $outcome = app(ScrapedRecordIngestService::class)->ingest(ScrapedRecord::fromArray([
        'schema_version' => ScrapedRecord::SCHEMA_VERSION, 'source' => 'evenements-pro', 'status' => 'success',
        'run_id' => 'zz-avant-funnel-1',
        'company' => ['siren' => (string) DB::table('companies')->where('id', $b)->value('siren'), 'country' => 'FR', 'fields' => ['denomination' => 'ZZ Club Avant']],
        'persons' => [
            ['kind' => 'person', 'first_name' => 'Zed', 'last_name' => 'ZZAVANTDEUX'],
            ['kind' => 'person', 'first_name' => 'Zoe', 'last_name' => 'ZZAVANTTEMOIN'],
        ],
    ]));

    expect($outcome->companyId)->toBe($b)
        ->and($outcome->personsSkipped['retiree_via_fiche_absorbee'] ?? 0)->toBe(1)
        ->and(DB::table('contacts')->where('company_id', $b)->where('last_name', 'ZZAVANTDEUX')->exists())->toBeFalse()
        ->and(DB::table('contacts')->where('company_id', $b)->where('last_name', 'ZZAVANTTEMOIN')->exists())->toBeTrue();
});
