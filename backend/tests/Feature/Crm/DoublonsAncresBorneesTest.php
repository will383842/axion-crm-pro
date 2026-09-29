<?php

/**
 * R1 exactitude (4e tour) — LA BORNE DE `ancresAbsorbees` NE COUPE PLUS EN
 * SILENCE.
 *
 * Les ancres des fiches absorbées (interrogées au registre des personnes
 * retirées) sont lues en chaîne, bornée à 16 niveaux et 200 fiches. Au-delà,
 * des ancres ne sont pas interrogées : c'est maintenant DIT — un avertissement
 * au journal (identifiants seulement, aucune donnée personnelle) et un
 * compteur au bilan. Témoin : juste sous la borne, rien n'est signalé.
 *
 * Journal des fusions écrit à la main (pas de clé étrangère vers `companies` :
 * des identifiants fictifs suffisent). Fixtures FICTIVES (dépôt public).
 */

use App\Crm\Doublons\FusionFiches;
use App\Crm\Scraping\ScrapedRecord;
use App\Crm\Scraping\ScrapedRecordIngestService;
use App\Services\Audit\AuditHashChain;
use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    $this->ws = F::espace('zz-doublons-bornes');
});

/** Journalise des fusions (garde ← absorbée) sans toucher aux fiches. @param  list<array{int, int}>  $paires */
function dabJournal(string $ws, array $paires): void
{
    DB::table('fusions_fiches')->insert(array_map(static fn (array $p): array => [
        'workspace_id' => $ws, 'garde_id' => $p[0], 'absorbee_id' => $p[1], 'motif' => 'nom_cp', 'mode' => 'manuel', 'absorbee_supprimee_le' => now(),
    ], $paires));
}

/** Une fiche qui a absorbé $n fiches directement. */
function dabEtoile(string $ws, int $garde, int $n): void
{
    dabJournal($ws, array_map(static fn (int $i): array => [$garde, 910_000_000 + $i], range(1, $n)));
}

/** Une chaîne de $n niveaux : $garde ← a1 ← a2 ← … ← an. */
function dabChaine(string $ws, int $garde, int $n): void
{
    $paires = [];
    $haut = $garde;
    for ($i = 1; $i <= $n; $i++) {
        $paires[] = [$haut, 920_000_000 + $i];
        $haut = 920_000_000 + $i;
    }
    dabJournal($ws, $paires);
}

function dabAttendAvertissement(int $fois): void
{
    if ($fois === 0) {
        // Un espion Mockery ne sait pas vérifier « zéro fois » par `times(0)`.
        Log::shouldNotHaveReceived('warning');

        return;
    }
    Log::shouldHaveReceived('warning')
        ->withArgs(static fn (string $message, array $contexte = []): bool => $message === 'crm.doublons.ancres_absorbees_tronquees'
            // Identifiants et bornes, RIEN d'autre (aucune donnée personnelle).
            && array_keys($contexte) === ['workspace_id', 'company_id', 'fiches_vues', 'niveaux_max', 'fiches_max'])
        ->times($fois);
}

test('TÉMOIN — 200 fiches absorbées : toutes lues, rien de signalé', function () {
    Log::spy();
    dabEtoile($this->ws, 900_000_001, FusionFiches::ANCRES_FICHES_MAX);

    $tronquee = null;
    $ancres = FusionFiches::ancresAbsorbees($this->ws, 900_000_001, $tronquee);

    expect(count($ancres))->toBe(200)->and($tronquee)->toBeFalse();
    dabAttendAvertissement(0);
});

test('201 fiches absorbées : 200 lues, borne signalée (journal + drapeau)', function () {
    Log::spy();
    dabEtoile($this->ws, 900_000_001, FusionFiches::ANCRES_FICHES_MAX + 1);

    $tronquee = null;
    $ancres = FusionFiches::ancresAbsorbees($this->ws, 900_000_001, $tronquee);

    expect(count($ancres))->toBe(200)->and($tronquee)->toBeTrue();
    dabAttendAvertissement(1);
});

test('TÉMOIN — une chaîne de 16 niveaux : toute lue, rien de signalé', function () {
    Log::spy();
    dabChaine($this->ws, 900_000_002, FusionFiches::ANCRES_NIVEAUX_MAX);

    $tronquee = null;
    $ancres = FusionFiches::ancresAbsorbees($this->ws, 900_000_002, $tronquee);

    expect(count($ancres))->toBe(16)->and($tronquee)->toBeFalse();
    dabAttendAvertissement(0);
});

test('une chaîne de 17 niveaux : 16 lus, borne signalée', function () {
    Log::spy();
    dabChaine($this->ws, 900_000_002, FusionFiches::ANCRES_NIVEAUX_MAX + 1);

    $tronquee = null;
    $ancres = FusionFiches::ancresAbsorbees($this->ws, 900_000_002, $tronquee);

    expect(count($ancres))->toBe(16)->and($tronquee)->toBeTrue();
    dabAttendAvertissement(1);
});

test('la collecte COMPTE la borne atteinte dans son bilan (et pas quand elle ne l est pas)', function () {
    config(['crm.scrape_funnel.validate_mx' => false]);
    $this->seed(ScrapingSourcesSeeder::class);
    config(['crm.ingest.business_workspace' => F::slug($this->ws)]);
    $bornee = F::sansSiren($this->ws, 'ZZ Club Borne', ['postcode' => '69000', 'foreign_id' => 'evt:zz-club-borne', 'country_code' => 'FR']);
    $libre = F::sansSiren($this->ws, 'ZZ Club Libre', ['postcode' => '69000', 'foreign_id' => 'evt:zz-club-libre', 'country_code' => 'FR']);
    dabEtoile($this->ws, $bornee, FusionFiches::ANCRES_FICHES_MAX + 1);
    $message = static fn (string $ancre, string $nom): array => [
        'schema_version' => ScrapedRecord::SCHEMA_VERSION, 'source' => 'evenements-pro', 'status' => 'success',
        'run_id' => 'zz-borne-' . $ancre,
        'company' => ['foreign_id' => $ancre, 'country' => 'FR', 'fields' => ['denomination' => $nom]],
        'persons' => [],
    ];

    $coupee = app(ScrapedRecordIngestService::class)->ingest(ScrapedRecord::fromArray($message('evt:zz-club-borne', 'ZZ Club Borne')));
    $entiere = app(ScrapedRecordIngestService::class)->ingest(ScrapedRecord::fromArray($message('evt:zz-club-libre', 'ZZ Club Libre')));

    expect($coupee->companyId)->toBe($bornee)
        ->and($coupee->chainesFusionTronquees)->toBe(1)
        ->and($coupee->toArray()['fusion_chains_truncated'])->toBe(1)
        ->and($entiere->companyId)->toBe($libre)
        ->and($entiere->chainesFusionTronquees)->toBe(0);
});
