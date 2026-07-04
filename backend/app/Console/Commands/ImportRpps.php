<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;

/**
 * Import Annuaire Santé / RPPS (« PS LibreAccès ») — apporte la SPÉCIALITÉ
 * médicale + téléphone + adresse des professionnels de santé, rattachés à une
 * entreprise (company) par SIREN.
 *
 * ⚠️ Donnée nominative de SANTÉ (RGPD art. 9). L'import RÉEL est REFUSÉ tant que
 * SANTE_INGESTION_ENABLED n'est pas positionné (valider l'AIPD dédiée avant).
 *
 * Fichier : « PS LibreAccès » (délimiteur `|`), à déposer dans
 * `storage/app/sante/ps-libreacces.txt`. Téléchargement :
 * https://annuaire.sante.fr/web/site-pro/extractions-publiques
 *
 * Le mapping des colonnes est DÉFENSIF (par mots-clés d'en-tête) car les libellés
 * exacts du fichier officiel peuvent varier — à vérifier au 1er import réel.
 */
class ImportRpps extends Command
{
    protected $signature = 'rpps:import '
        . '{--file=storage/app/sante/ps-libreacces.txt} '
        . '{--workspace= : UUID du workspace cible (défaut = 1er workspace)} '
        . '{--mock : seed de praticiens fictifs} '
        . '{--delimiter=| : délimiteur CSV}';

    protected $description = 'Importe l\'Annuaire Santé / RPPS (spécialité + tél). Gaté par SANTE_INGESTION_ENABLED.';

    public function handle(): int
    {
        $workspaceId = $this->option('workspace')
            ?: DB::table('workspaces')->orderBy('created_at')->value('id');
        if (! $workspaceId) {
            $this->error('Aucun workspace cible (--workspace=UUID).');
            return self::FAILURE;
        }

        $mock = (bool) $this->option('mock') || (bool) env('MOCK_RPPS', env('MOCK_MODE', true));
        if ($mock) {
            $this->warn('Mode mock — seed de professionnels de santé fictifs (données non réelles).');
            return $this->seedMock((string) $workspaceId);
        }

        // Garde-fou RGPD art. 9 — import RÉEL de données nominatives de santé.
        if (! config('services.sante.ingestion_enabled')) {
            $this->error('Ingestion santé RÉELLE désactivée : données nominatives de santé (RGPD art. 9).');
            $this->line('Valider l\'AIPD dédiée puis positionner SANTE_INGESTION_ENABLED=true.');
            return self::FAILURE;
        }

        $path = base_path($this->option('file'));
        if (! file_exists($path)) {
            $this->error("Fichier RPPS absent : {$path}");
            $this->line('Télécharger « PS LibreAccès » : https://annuaire.sante.fr/web/site-pro/extractions-publiques');
            return self::FAILURE;
        }

        return $this->importCsv($path, (string) $workspaceId);
    }

    private function importCsv(string $path, string $workspaceId): int
    {
        $csv = Reader::createFromPath($path, 'r');
        $csv->setDelimiter((string) $this->option('delimiter'));
        $csv->setHeaderOffset(0);

        $map = $this->mapColumns($csv->getHeader());
        if (! isset($map['rpps'])) {
            $this->error('Colonne identifiant RPPS introuvable (en-têtes : ' . implode(', ', $csv->getHeader()) . ').');
            return self::FAILURE;
        }

        $sirenToCompany = [];
        $imported = 0;
        $linked = 0;

        foreach ($csv->getRecords() as $row) {
            $rpps = trim((string) ($row[$map['rpps']] ?? ''));
            if ($rpps === '') {
                continue;
            }

            $siren = $this->resolveSiren($row, $map);
            $companyId = null;
            if ($siren !== null) {
                if (! array_key_exists($siren, $sirenToCompany)) {
                    $sirenToCompany[$siren] = DB::table('companies')
                        ->where('workspace_id', $workspaceId)
                        ->where('siren', $siren)
                        ->value('id');
                }
                $companyId = $sirenToCompany[$siren];
                if ($companyId) {
                    $linked++;
                }
            }

            DB::table('health_practitioners')->updateOrInsert(
                ['workspace_id' => $workspaceId, 'rpps' => $rpps],
                [
                    'company_id' => $companyId,
                    'siren'      => $siren,
                    'nom'        => $this->val($row, $map, 'nom'),
                    'prenom'     => $this->val($row, $map, 'prenom'),
                    'specialite' => $this->val($row, $map, 'specialite'),
                    'phone'      => $this->val($row, $map, 'phone'),
                    'address'    => $this->val($row, $map, 'address'),
                    'postcode'   => $this->val($row, $map, 'postcode'),
                    'city'       => $this->val($row, $map, 'city'),
                    'source'     => 'rpps-libreacces',
                    'updated_at' => now(),
                ],
            );
            $imported++;
        }

        $this->info("RPPS importé : {$imported} praticiens ({$linked} rattachés à une entreprise).");
        return self::SUCCESS;
    }

    /**
     * Mapping défensif en-tête → champ, par mots-clés (insensible à la casse).
     * `nom` exclut explicitement `prénom` (« prénom » contient « nom »).
     *
     * @param array<int, string> $header
     * @return array<string, string>
     */
    private function mapColumns(array $header): array
    {
        $map = [];
        foreach ($header as $col) {
            $l = mb_strtolower($col);
            $has = fn (string $n) => str_contains($l, $n);
            $isPrenom = $has('prénom') || $has('prenom');

            if (! isset($map['rpps']) && ($has('identifiant pp') || $has('rpps'))) {
                $map['rpps'] = $col;
            }
            if (! isset($map['siret']) && $has('siret')) {
                $map['siret'] = $col;
            }
            if (! isset($map['siren']) && $has('siren')) {
                $map['siren'] = $col;
            }
            if (! isset($map['prenom']) && $isPrenom) {
                $map['prenom'] = $col;
            }
            if (! isset($map['nom']) && $has('nom') && ! $isPrenom) {
                $map['nom'] = $col;
            }
            if (! isset($map['specialite'])
                && ($has('savoir-faire') || $has('profession') || $has('spécialité') || $has('specialite'))) {
                $map['specialite'] = $col;
            }
            if (! isset($map['phone']) && ($has('téléphone') || $has('telephone'))) {
                $map['phone'] = $col;
            }
            if (! isset($map['address']) && $has('adresse')) {
                $map['address'] = $col;
            }
            if (! isset($map['postcode']) && $has('code postal')) {
                $map['postcode'] = $col;
            }
            if (! isset($map['city']) && ($has('commune') || $has('ville'))) {
                $map['city'] = $col;
            }
        }
        return $map;
    }

    /**
     * @param array<string, string> $row
     * @param array<string, string> $map
     */
    private function resolveSiren(array $row, array $map): ?string
    {
        $siren = isset($map['siren']) ? preg_replace('/\D/', '', (string) ($row[$map['siren']] ?? '')) : '';
        if ($siren === '' && isset($map['siret'])) {
            $siret = preg_replace('/\D/', '', (string) ($row[$map['siret']] ?? ''));
            if (strlen($siret) >= 9) {
                $siren = substr($siret, 0, 9);
            }
        }
        return ($siren !== '' && strlen($siren) === 9) ? $siren : null;
    }

    /**
     * @param array<string, string> $row
     * @param array<string, string> $map
     */
    private function val(array $row, array $map, string $key): ?string
    {
        if (! isset($map[$key])) {
            return null;
        }
        $v = trim((string) ($row[$map[$key]] ?? ''));
        return $v === '' ? null : $v;
    }

    private function seedMock(string $workspaceId): int
    {
        $samples = [
            ['rpps' => '10000000001', 'nom' => 'Martin', 'prenom' => 'Claire', 'specialite' => 'Cardiologie', 'phone' => '+33140000001', 'city' => 'Grenoble', 'postcode' => '38000'],
            ['rpps' => '10000000002', 'nom' => 'Bernard', 'prenom' => 'Paul', 'specialite' => 'Ophtalmologie', 'phone' => '+33140000002', 'city' => 'Lyon', 'postcode' => '69001'],
            ['rpps' => '10000000003', 'nom' => 'Dubois', 'prenom' => 'Sophie', 'specialite' => 'Dermatologie', 'phone' => '+33140000003', 'city' => 'Paris', 'postcode' => '75008'],
        ];
        foreach ($samples as $s) {
            DB::table('health_practitioners')->updateOrInsert(
                ['workspace_id' => $workspaceId, 'rpps' => $s['rpps']],
                array_merge($s, ['source' => 'mock', 'updated_at' => now()]),
            );
        }
        $this->info('RPPS mock seedé : ' . count($samples) . ' praticiens fictifs.');
        return self::SUCCESS;
    }
}
