<?php

/**
 * LIBELLÉS NAF rév. 2 (lot N7) — cohérence des CSV versionnés que charge
 * `crm:referentiels:charger-naf`, sans base.
 *
 * Les comptes sont ceux de la nomenclature officielle (INSEE, NAF rév. 2,
 * 2008) : 21 sections, 88 divisions, 272 groupes, 615 classes, 732
 * sous-classes. Un écart signale un CSV tronqué ou mal régénéré.
 */

use App\Crm\Referentiels\LibellesNaf;

test('les cinq niveaux portent les comptes officiels de la NAF rév. 2', function () {
    $l = LibellesNaf::lignes();

    expect($l['section'])->toHaveCount(21)
        ->and($l['division'])->toHaveCount(88)
        ->and($l['groupe'])->toHaveCount(272)
        ->and($l['classe'])->toHaveCount(615)
        ->and($l['sous_classe'])->toHaveCount(732);
});

test('chaque ligne a un libellé, un code unique au format des tables, et un parent au niveau supérieur', function () {
    $l = LibellesNaf::lignes();
    $formats = [
        'section' => '/^[A-U]$/',
        'division' => '/^\d{2}$/',
        'groupe' => '/^\d{3}$/',
        'classe' => '/^\d{4}$/',
        'sous_classe' => '/^\d{4}[A-Z]$/',
    ];
    $parentDe = ['division' => 'section', 'groupe' => 'division', 'classe' => 'groupe', 'sous_classe' => 'classe'];

    foreach ($formats as $niveau => $motif) {
        $codes = array_column($l[$niveau], 'code');
        expect($codes)->toHaveCount(count(array_unique($codes)), "{$niveau} : code en double");
        foreach ($l[$niveau] as $ligne) {
            expect(preg_match($motif, $ligne['code']))->toBe(1, "{$niveau} mal formé : {$ligne['code']}")
                ->and(trim($ligne['label']))->not->toBe('');
            if (isset($parentDe[$niveau])) {
                $parents = array_column($l[$parentDe[$niveau]], 'code');
                expect(in_array($ligne['parent'], $parents, true))->toBeTrue("{$niveau} {$ligne['code']} : parent {$ligne['parent']} inconnu");
            }
        }
    }
});

test('exemples réels : sous-classe, classe, groupe, division, section', function () {
    $index = [];
    foreach (LibellesNaf::lignes() as $niveau => $lignes) {
        foreach ($lignes as $ligne) {
            $index[$niveau][$ligne['code']] = $ligne;
        }
    }

    expect($index['sous_classe']['6201Z']['label'])->toBe('Programmation informatique')
        ->and($index['sous_classe']['6201Z']['parent'])->toBe('6201')
        ->and($index['classe']['6201']['parent'])->toBe('620')
        ->and($index['groupe']['620']['parent'])->toBe('62')
        ->and($index['division']['62']['parent'])->toBe('J')
        ->and($index['section']['J']['parent'])->toBeNull()
        // Une division de section A, dont le code commence par 0.
        ->and($index['division']['01']['parent'])->toBe('A');
});

test('sansPoint : l écriture INSEE devient celle des tables', function () {
    expect(LibellesNaf::sansPoint('62.01Z'))->toBe('6201Z')
        ->and(LibellesNaf::sansPoint(' 01.1 '))->toBe('011')
        ->and(LibellesNaf::sansPoint('81.30z'))->toBe('8130Z')
        ->and(LibellesNaf::sansPoint('J'))->toBe('J');
});

test('sqlLibelleSousClasse refuse un nom de table qui ne serait pas un identifiant', function () {
    expect(fn () => LibellesNaf::sqlLibelleSousClasse('companies; drop table x'))->toThrow(RuntimeException::class);
    expect(LibellesNaf::sqlLibelleSousClasse('c'))->toContain('c.naf_rev2');
});
