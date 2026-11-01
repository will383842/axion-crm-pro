<?php

/**
 * NR-01 — R3, l’opposition (`opt_out`) et la suppression (`email_suppressions`).
 *
 * Plan 22 § 3 : « une adresse en opt_out ou en email_suppressions n’est JAMAIS
 * proposée, quel que soit le chemin ». Les gardes existantes (`EligibiliteCampagneTest`,
 * `AucunEnvoiSansEligibiliteTest`, `OppositionCouvreTousLesUniversTest`,
 * `ExportsRespectentOppositionsTest`) sont rattachées au groupe par `tests/Pest.php`.
 *
 * Ce fichier ajoute la version « DEUX ESPACES ». Le filet CONSTATE :
 *
 *   ⚠️ AUJOURD’HUI l’opposition est GLOBALE, pas par espace : `opt_out` et
 *   `email_suppressions` n’ont pas de `workspace_id` (elles sont distinguées par
 *   `scope`, l’univers « business » ou « vivier »). Une adresse opposée depuis
 *   l’Espace A est donc refusée aussi dans l’Espace B. C’est le comportement
 *   protecteur actuel ; le lot P2 (opposition par espace) devra le changer en
 *   connaissance de cause, avec la ligne « Filet modifié : pourquoi ».
 *
 * Fixtures FICTIVES : adresses en `@example.test`.
 */

use App\Models\Company;
use App\Support\EligibiliteCampagne;
use App\Support\ListeSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\NonRegression\EspacesFactices;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function nr01Fiche(string $espace, string $nom, string $courriel): void
{
    Company::create([
        'workspace_id' => $espace,
        'siren' => (string) random_int(100000000, 999999999),
        'denomination' => $nom,
        'email_generic' => $courriel,
        'signals' => [],
        'metadata' => [],
    ]);
}

/** @return list<string> */
function nr01Eligibles(string $espace): array
{
    return EligibiliteCampagne::appliquer(Company::query()->where('workspace_id', $espace))
        ->orderBy('denomination')->pluck('denomination')->all();
}

beforeEach(function () {
    $this->espaces = EspacesFactices::creer();
    foreach (['a' => 'A', 'b' => 'B'] as $cle => $lettre) {
        nr01Fiche($this->espaces[$cle], "Libre {$lettre}", 'libre@nr01.example.test');
        nr01Fiche($this->espaces[$cle], "Opposee {$lettre}", 'oppose@nr01.example.test');
        nr01Fiche($this->espaces[$cle], "Rebond {$lettre}", 'rebond@nr01.example.test');
    }
});

test('NR-01 une adresse sans opposition reste éligible dans les deux espaces (témoin)', function () {
    expect(nr01Eligibles($this->espaces['a']))->toBe(['Libre A', 'Opposee A', 'Rebond A'])
        ->and(nr01Eligibles($this->espaces['b']))->toBe(['Libre B', 'Opposee B', 'Rebond B'])
        ->and(EligibiliteCampagne::peutRecevoir('libre@nr01.example.test'))->toBeTrue();
});

test('NR-01 une opposition retire l’adresse de la liste des DEUX espaces (opposition globale aujourd’hui)', function () {
    DB::table('opt_out')->insert([
        'email_hash' => ListeSuppression::empreinte('oppose@nr01.example.test'),
        'scope' => 'business', 'source' => 'nr01', 'created_at' => now(),
    ]);

    expect(nr01Eligibles($this->espaces['a']))->toBe(['Libre A', 'Rebond A'])
        ->and(nr01Eligibles($this->espaces['b']))->toBe(['Libre B', 'Rebond B'])
        ->and(EligibiliteCampagne::peutRecevoir('oppose@nr01.example.test'))->toBeFalse()
        ->and(EligibiliteCampagne::peutRecevoir('libre@nr01.example.test'))->toBeTrue();
});

test('NR-01 une suppression (rebond dur) retire l’adresse des DEUX espaces', function () {
    ListeSuppression::inscrire('rebond@nr01.example.test', ListeSuppression::REBOND_DUR, 'nr01');

    expect(nr01Eligibles($this->espaces['a']))->toBe(['Libre A', 'Opposee A'])
        ->and(nr01Eligibles($this->espaces['b']))->toBe(['Libre B', 'Opposee B'])
        ->and(EligibiliteCampagne::peutRecevoir('rebond@nr01.example.test'))->toBeFalse();
});

test('NR-01 l’opposition est casse-insensible et ignore les espaces autour de l’adresse', function () {
    DB::table('opt_out')->insert([
        'email_hash' => ListeSuppression::empreinte('oppose@nr01.example.test'),
        'scope' => 'business', 'source' => 'nr01', 'created_at' => now(),
    ]);

    expect(EligibiliteCampagne::peutRecevoir('  OPPOSE@NR01.Example.Test '))->toBeFalse();
});

test('NR-01 une adresse vide n’est jamais autorisée', function () {
    expect(EligibiliteCampagne::peutRecevoir('   '))->toBeFalse();
});
