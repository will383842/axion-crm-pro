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
