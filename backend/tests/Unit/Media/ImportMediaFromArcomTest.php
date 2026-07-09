<?php

use App\Console\Commands\ImportMediaFromArcom;

/**
 * Construit un XLSX minimal (ZipArchive natif, cellules inlineStr) reproduisant
 * la structure de la base ARCOM « transparence des médias » : en-tête ligne 1,
 * puis les données. Colonnes A..I = nom, nature, catégorie, dénomination, forme,
 * adresse, code postal, commune, pays.
 *
 * @param  array<int,array<int,string>>  $dataRows
 */
function makeArcomXlsx(array $dataRows): string
{
    $cols = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'];
    $rowsXml = '';

    // Ligne 1 : en-tête (ignorée par extractRows).
    $header = ['Nom du service', 'Nature', 'Catégorie', 'Dénomination', 'Forme', 'Adresse', 'Code postal', 'Commune', 'Pays'];
    $cellsXml = '';
    foreach ($header as $i => $val) {
        $cellsXml .= '<c r="' . $cols[$i] . '1" t="inlineStr"><is><t>' . htmlspecialchars($val, ENT_XML1) . '</t></is></c>';
    }
    $rowsXml .= '<row r="1">' . $cellsXml . '</row>';

    // Lignes de données.
    foreach ($dataRows as $n => $row) {
        $r = $n + 2;
        $cellsXml = '';
        foreach ($row as $i => $val) {
            if ($val === '') {
                continue; // cellule vide → absente (cas réel : cellules creuses)
            }
            $cellsXml .= '<c r="' . $cols[$i] . $r . '" t="inlineStr"><is><t>'
                . htmlspecialchars($val, ENT_XML1) . '</t></is></c>';
        }
        $rowsXml .= '<row r="' . $r . '">' . $cellsXml . '</row>';
    }

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . $rowsXml . '</sheetData></worksheet>';

    $path = tempnam(sys_get_temp_dir(), 'arcom_test_') . '.xlsx';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="xml" ContentType="application/xml"/></Types>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();

    return $path;
}

it('parse la base ARCOM et dédoublonne les stations par nom+type', function () {
    // Échantillon fake : 7 lignes → 5 stations distinctes attendues.
    $sample = [
        ['Radio Test A', 'Radio', 'A', 'Assoc Test', 'Association', '1 rue X', '38000', 'Grenoble', 'France'],
        // Doublon exact (même nom + même type) → fusionné :
        ['Radio Test A', 'Radio', 'A', 'Assoc Test', 'Association', '2 rue Y', '38000', 'Grenoble', 'France'],
        ['Radio Nat', 'Radio', 'E', 'SA Nationale', 'SA', '10 av Champs', '75008', 'Paris', 'France'],
        ['Canal Test', 'TV', 'NC', 'SAS Télé', 'SAS', '5 bd Media', '92200', 'Neuilly-sur-Seine', 'France'],
        ['Radio Régio', 'Radio', 'C', 'SARL Régionale', 'SARL', '3 quai', '69001', 'Lyon', 'France'],
        ['Radio Étranger', 'Radio', 'A', 'GmbH', 'GmbH', 'Strasse 1', '10000', 'Berlin', 'Allemagne'],
        // Nom vide → ignoré :
        ['', 'Radio', 'A', 'Sans nom', 'Association', '', '75000', 'Paris', 'France'],
    ];

    $path = makeArcomXlsx($sample);
    $matrix = ImportMediaFromArcom::extractRows($path);
    @unlink($path);

    expect($matrix)->toHaveCount(7); // toutes les lignes de données (hors en-tête)

    $res = ImportMediaFromArcom::mapAndDedup($matrix, 'ws-fake', '2026-07-09');
    $rows = $res['rows'];

    // 7 lignes − 1 doublon − 1 nom vide = 5 stations distinctes.
    expect($rows)->toHaveCount(5)
        ->and($res['duplicates'])->toBe(1)
        ->and($res['skipped'])->toBe(1);

    $byName = collect($rows)->keyBy('name');

    // Radio associative locale : zone local, siège Grenoble (38 / région ARA 84).
    expect($byName['Radio Test A']['media_type'])->toBe('radio')
        ->and($byName['Radio Test A']['diffusion_zone'])->toBe('local')
        ->and($byName['Radio Test A']['department_code'])->toBe('38')
        ->and($byName['Radio Test A']['region_code'])->toBe('84')
        ->and($byName['Radio Test A']['source'])->toBe('arcom');

    // Radio catégorie E → national ; siège Paris (75 / IDF 11).
    expect($byName['Radio Nat']['diffusion_zone'])->toBe('national')
        ->and($byName['Radio Nat']['department_code'])->toBe('75')
        ->and($byName['Radio Nat']['region_code'])->toBe('11');

    // Radio catégorie C → régional.
    expect($byName['Radio Régio']['diffusion_zone'])->toBe('régional')
        ->and($byName['Radio Régio']['department_code'])->toBe('69');

    // TV : type tv, zone non déterminable (NC) → null.
    expect($byName['Canal Test']['media_type'])->toBe('tv')
        ->and($byName['Canal Test']['diffusion_zone'])->toBeNull()
        ->and($byName['Canal Test']['department_code'])->toBe('92');

    // Siège hors France → pas de département déduit, mais la catégorie reste mappée.
    expect($byName['Radio Étranger']['department_code'])->toBeNull()
        ->and($byName['Radio Étranger']['diffusion_zone'])->toBe('local');
});
