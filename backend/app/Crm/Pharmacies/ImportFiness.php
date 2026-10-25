<?php

namespace App\Crm\Pharmacies;

use App\Crm\Referentiels\Classement;
use App\Crm\Referentiels\NomenclatureNaf;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use SplFileObject;

/**
 * IMPORT DES OFFICINES FINESS — rapprochement et création des seules fiches
 * manquantes (dépôt public : aucune donnée nominative n'est jamais affichée).
 *
 *  - retenues : catégorie 620, état A (officines) ;
 *  - classées À PART, jamais mélangées ni créées : 627, 628, 629, 641, 690 ;
 *  - dédoublonnage : SIRET d'abord, SIREN ensuite ;
 *  - SIRET déjà connu : rien ; SIREN connu sous un autre SIRET : l'établissement
 *    est noté dans `finess_etablissements`, la fiche n'est PAS touchée ;
 *  - SIREN à la corbeille : compté, jamais restauré ni recréé ;
 *  - JAMAIS d'écrasement, de suppression ni de purge ;
 *  - les fiches créées ont `discovery_source = finess` (provenance « tiers » :
 *    la mise à jour mensuelle INSEE les complète en passe prioritaire).
 */
final class ImportFiness
{
    public const SOURCE = 'finess';

    public const CATEGORIE_OFFICINE = '620';

    /** @var list<string> */
    public const CATEGORIES_A_PART = ['627', '628', '629', '641', '690'];

    private const COLONNES = ['finess_et', 'siret', 'categorie', 'etat', 'nom_long', 'code_postal', 'commune', 'dep', 'statut', 'ape'];

    /** Alias des en-têtes de l'export brut FINESS. */
    private const ALIAS = ['commune_acheminement' => 'commune', 'departement' => 'dep', 'ej_statut_juridique_libelle' => 'statut', 'ej_code_ape' => 'ape'];

    /**
     * @param  callable(string): void|null  $journal
     * @return array<string, mixed> bilan chiffré (aucun nom)
     */
    public function executer(string $workspaceId, string $fichier, bool $appliquer, int $paquet = 500): array
    {
        $bilan = [
            'lignes' => 0, 'officines' => 0, 'sans_siret' => 0, 'inactives' => 0, 'doublons_fichier' => 0,
            'deja_par_siret' => 0, 'siren_autre_siret' => 0, 'corbeille' => 0, 'a_creer' => 0,
            'creees' => 0, 'etablissements_ajoutes' => 0,
            'autres_categories' => [], 'par_departement' => [],
        ];

        $lignes = $this->lire($fichier);
        $lot = [];
        $sirets = [];
        foreach ($lignes as $l) {
            $bilan['lignes']++;
            $cat = $l['categorie'];
            if ($l['etat'] !== 'A') {
                $bilan['inactives']++;

                continue;
            }
            if (in_array($cat, self::CATEGORIES_A_PART, true)) {
                $bilan['autres_categories'][$cat] = ($bilan['autres_categories'][$cat] ?? 0) + 1;

                continue;
            }
            if ($cat !== self::CATEGORIE_OFFICINE) {
                continue;
            }
            $bilan['officines']++;
            if (preg_match('/^\d{14}$/', $l['siret']) !== 1) {
                $bilan['sans_siret']++;

                continue;
            }
            if (isset($sirets[$l['siret']])) {
                $bilan['doublons_fichier']++;

                continue;
            }
            $sirets[$l['siret']] = true;
            $lot[] = $l;
            if (count($lot) >= $paquet) {
                $this->traiter($workspaceId, $lot, $appliquer, $bilan);
                $lot = [];
            }
        }
        if ($lot !== []) {
            $this->traiter($workspaceId, $lot, $appliquer, $bilan);
        }
        ksort($bilan['par_departement']);
        ksort($bilan['autres_categories']);

        return $bilan;
    }

    /**
     * @param  list<array<string, string>>  $lot
     * @param  array<string, mixed>  $bilan
     */
    private function traiter(string $workspaceId, array $lot, bool $appliquer, array &$bilan): void
    {
        WorkspaceContext::run($workspaceId, function () use ($workspaceId, $lot, $appliquer, &$bilan): void {
            $sirets = array_column($lot, 'siret');
            $sirens = array_values(array_unique(array_map(static fn (string $s): string => substr($s, 0, 9), $sirets)));

            $parSiret = DB::table('companies')->where('workspace_id', $workspaceId)->whereIn('siret', $sirets)->whereNull('deleted_at')->pluck('id', 'siret')->all();
            // Corbeille INCLUSE : l'unicité (espace, SIREN) vaut aussi pour elle.
            $parSiren = [];
            foreach (DB::table('companies')->where('workspace_id', $workspaceId)->whereIn('siren', $sirens)->get(['id', 'siren', 'deleted_at']) as $c) {
                if (! isset($parSiren[$c->siren]) || $c->deleted_at === null) {
                    $parSiren[$c->siren] = ['id' => (int) $c->id, 'corbeille' => $c->deleted_at !== null];
                }
            }

            $creations = [];
            $etablissements = []; // siret => [ligne, siren]
            foreach ($lot as $l) {
                $siren = substr($l['siret'], 0, 9);
                $dep = $this->departement($l);
                if (isset($parSiret[$l['siret']])) {
                    $bilan['deja_par_siret']++;
                    $etablissements[$l['siret']] = [$l, $siren];

                    continue;
                }
                if (isset($parSiren[$siren])) {
                    if ($parSiren[$siren]['corbeille']) {
                        $bilan['corbeille']++;
                    } else {
                        $bilan['siren_autre_siret']++;
                        $etablissements[$l['siret']] = [$l, $siren];
                    }

                    continue;
                }
                if (isset($creations[$siren])) {
                    // Même société, autre officine, dans ce lot : fiche unique.
                    $bilan['siren_autre_siret']++;
                    $etablissements[$l['siret']] = [$l, $siren];

                    continue;
                }
                $bilan['a_creer']++;
                $bilan['par_departement'][$dep] = ($bilan['par_departement'][$dep] ?? 0) + 1;
                $creations[$siren] = $this->ligneFiche($workspaceId, $l, $siren, $dep);
                $etablissements[$l['siret']] = [$l, $siren];
            }

            if (! $appliquer) {
                return;
            }

            DB::transaction(function () use ($workspaceId, $creations, $etablissements, &$bilan): void {
                if ($creations !== []) {
                    // ON CONFLICT DO NOTHING : une fiche apparue entre-temps n'est jamais écrasée.
                    $bilan['creees'] += DB::table('companies')->insertOrIgnore(array_values($creations));
                }
                $ids = DB::table('companies')->where('workspace_id', $workspaceId)->whereNull('deleted_at')
                    ->whereIn('siren', array_values(array_unique(array_column($etablissements, 1))))
                    ->pluck('id', 'siren')->all();
                $lignes = [];
                foreach ($etablissements as $siret => [$l, $siren]) {
                    if (! isset($ids[$siren])) {
                        continue;
                    }
                    $lignes[] = [
                        'workspace_id' => $workspaceId, 'company_id' => $ids[$siren], 'siret' => $siret,
                        'finess_et' => $l['finess_et'] ?: null, 'categorie' => $l['categorie'],
                        'code_postal' => $l['code_postal'] ?: null, 'commune' => $l['commune'] ?: null,
                        'departement' => $this->departement($l), 'created_at' => now(),
                    ];
                }
                if ($lignes !== []) {
                    $bilan['etablissements_ajoutes'] += DB::table('finess_etablissements')->insertOrIgnore($lignes);
                }
            });
        });
    }

    /**
     * @param  array<string, string>  $l
     * @return array<string, mixed>
     */
    private function ligneFiche(string $workspaceId, array $l, string $siren, string $dep): array
    {
        $naf = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $l['ape']) ?? '');
        $naf = $naf !== '' ? $naf : '4773Z';
        $classe = NomenclatureNaf::classer($naf);

        return [
            'workspace_id' => $workspaceId,
            'siren' => $siren,
            'siret' => $l['siret'],
            'denomination' => $l['nom_long'] !== '' ? $l['nom_long'] : null,
            'naf' => $naf,
            'sector_main' => $classe->secteur,
            'naf_nomenclature' => $classe->nomenclature,
            'naf_rev2' => $classe->codeRev2,
            'postcode' => $l['code_postal'] ?: null,
            'city' => $l['commune'] ?: null,
            'city_name' => $l['commune'] ?: null,
            'department_code' => $dep !== '??' ? $dep : null,
            'region_code' => $dep !== '??' ? Classement::regionDuDepartement($dep) : null,
            'discovery_source' => self::SOURCE,
            'metadata' => json_encode(['finess_et' => $l['finess_et'], 'finess_statut' => $l['statut']], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @param array<string, string> $l */
    private function departement(array $l): string
    {
        $dep = strtoupper(trim($l['dep']));
        if ($dep === '') {
            $dep = (string) Classement::departementDuCodePostal($l['code_postal']);
        }

        return $dep !== '' ? $dep : '??';
    }

    /** @return \Generator<array<string, string>> */
    private function lire(string $fichier): \Generator
    {
        $f = new SplFileObject($fichier, 'r');
        $f->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $f->setCsvControl(',', '"', '');
        $entete = null;
        foreach ($f as $row) {
            if (! is_array($row) || $row === [null]) {
                continue;
            }
            if ($entete === null) {
                $entete = array_map(static fn ($c): string => self::ALIAS[trim(ltrim((string) $c, "\xEF\xBB\xBF"))] ?? trim(ltrim((string) $c, "\xEF\xBB\xBF")), $row);
                $manque = array_diff(self::COLONNES, $entete);
                if ($manque !== []) {
                    throw new \InvalidArgumentException('Colonnes manquantes : ' . implode(', ', $manque));
                }

                continue;
            }
            $ligne = [];
            foreach (self::COLONNES as $c) {
                $i = array_search($c, $entete, true);
                $ligne[$c] = trim((string) ($row[$i] ?? ''));
            }
            $ligne['etat'] = strtoupper($ligne['etat']);
            $ligne['siret'] = preg_replace('/\s+/', '', $ligne['siret']) ?? '';

            yield $ligne;
        }
    }
}
