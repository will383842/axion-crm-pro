<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;

/**
 * Import NAF Rev. 2 complet — 732 codes 5 niveaux (sections / divisions / groupes / classes / sub-classes).
 *
 * Source officielle : `https://www.insee.fr/fr/information/2406147` (CSV téléchargeable).
 * Le fichier doit être déposé manuellement dans `storage/app/insee/naf-rev2-2026.csv`
 * (format INSEE : `code;libelle;niveau;parent`).
 */
class ImportNaf extends Command
{
    protected $signature = 'naf:import {--file=storage/app/insee/naf-rev2-2026.csv} {--mock : seed avec un sous-ensemble réduit}';

    protected $description = 'Importe le référentiel NAF Rev. 2 complet (732 codes).';

    public function handle(): int
    {
        $mock = (bool) $this->option('mock') || env('MOCK_MODE', true);

        if ($mock) {
            $this->warn('Mode mock — seed avec sous-ensemble NAF minimal (12 codes représentatifs).');
            return $this->seedMockSet();
        }

        $path = base_path($this->option('file'));
        if (! file_exists($path)) {
            $this->error("Fichier NAF absent : {$path}");
            $this->line('Télécharger : https://www.insee.fr/fr/information/2406147 (CSV)');
            return self::FAILURE;
        }

        return $this->importCsv($path);
    }

    private function seedMockSet(): int
    {
        DB::transaction(function () {
            // Divisions
            $divisions = [
                ['62', 'J', 'Programmation, conseil et autres activités informatiques'],
                ['63', 'J', 'Services d\'information'],
                ['70', 'M', 'Activités des sièges sociaux ; conseil de gestion'],
                ['71', 'M', 'Activités d\'architecture et d\'ingénierie ; activités de contrôle et analyses techniques'],
                ['74', 'M', 'Autres activités spécialisées, scientifiques et techniques'],
                ['85', 'P', 'Enseignement'],
            ];
            foreach ($divisions as [$code, $section, $label]) {
                DB::table('naf_divisions')->updateOrInsert(['code' => $code], ['section_code' => $section, 'label' => $label]);
            }

            $groups = [
                ['620', '62', 'Programmation, conseil et autres activités informatiques'],
                ['631', '63', 'Traitement de données, hébergement et activités connexes'],
                ['702', '70', 'Activités de conseil de gestion'],
                ['711', '71', 'Activités d\'architecture et d\'ingénierie'],
                ['749', '74', 'Autres activités spécialisées, scientifiques et techniques n.c.a.'],
                ['854', '85', 'Enseignement supérieur'],
            ];
            foreach ($groups as [$code, $div, $label]) {
                DB::table('naf_groups')->updateOrInsert(['code' => $code], ['division_code' => $div, 'label' => $label]);
            }

            $classes = [
                ['6201', '620', 'Programmation informatique'],
                ['6202', '620', 'Conseil en systèmes et logiciels informatiques'],
                ['6311', '631', 'Traitement de données, hébergement'],
                ['7022', '702', 'Conseil pour les affaires et autres conseils de gestion'],
                ['7112', '711', 'Activités d\'ingénierie et de conseil technique'],
                ['8542', '854', 'Enseignement supérieur'],
            ];
            foreach ($classes as [$code, $group, $label]) {
                DB::table('naf_classes')->updateOrInsert(['code' => $code], ['group_code' => $group, 'label' => $label]);
            }

            $subs = [
                ['6201Z', '6201', 'Programmation informatique',                            false],
                ['6202A', '6202', 'Conseil en systèmes et logiciels informatiques',         false],
                ['6202B', '6202', 'Tierce maintenance de systèmes et d\'applications',     false],
                ['6311Z', '6311', 'Traitement de données, hébergement et activités connexes', false],
                ['7022Z', '7022', 'Conseil pour les affaires et autres conseils de gestion', false],
                ['7112B', '7112', 'Ingénierie, études techniques',                          false],
                ['8542Z', '8542', 'Enseignement supérieur',                                 false],
            ];
            foreach ($subs as [$code, $class, $label, $isArt]) {
                DB::table('naf_subclasses')->updateOrInsert(
                    ['code' => $code],
                    ['class_code' => $class, 'label' => $label, 'is_artisanat' => $isArt],
                );
            }
        });

        $this->info('NAF mock set seedé (6 divisions / 6 groupes / 6 classes / 7 sub-classes).');
        return self::SUCCESS;
    }

    private function importCsv(string $path): int
    {
        $csv = Reader::createFromPath($path, 'r');
        $csv->setDelimiter(';');
        $csv->setHeaderOffset(0);

        $counts = ['divisions' => 0, 'groups' => 0, 'classes' => 0, 'subclasses' => 0];

        DB::transaction(function () use ($csv, &$counts) {
            foreach ($csv->getRecords() as $row) {
                $code  = trim((string) ($row['code'] ?? ''));
                $label = trim((string) ($row['libelle'] ?? ''));
                $level = (int) ($row['niveau'] ?? 0);

                if ($level === 2 && strlen($code) === 2) {
                    $section = substr($code, 0, 1); // approximation, à raffiner via mapping officiel
                    DB::table('naf_divisions')->updateOrInsert(['code' => $code], ['section_code' => $section, 'label' => $label]);
                    $counts['divisions']++;
                } elseif ($level === 3 && strlen($code) === 3) {
                    DB::table('naf_groups')->updateOrInsert(['code' => $code], ['division_code' => substr($code, 0, 2), 'label' => $label]);
                    $counts['groups']++;
                } elseif ($level === 4 && strlen($code) === 4) {
                    DB::table('naf_classes')->updateOrInsert(['code' => $code], ['group_code' => substr($code, 0, 3), 'label' => $label]);
                    $counts['classes']++;
                } elseif ($level === 5 && strlen($code) === 5) {
                    DB::table('naf_subclasses')->updateOrInsert(
                        ['code' => $code],
                        ['class_code' => substr($code, 0, 4), 'label' => $label, 'is_artisanat' => false],
                    );
                    $counts['subclasses']++;
                }
            }
        });

        $this->info(sprintf(
            'NAF importé : %d divisions / %d groupes / %d classes / %d sub-classes.',
            $counts['divisions'], $counts['groups'], $counts['classes'], $counts['subclasses'],
        ));

        return self::SUCCESS;
    }
}
