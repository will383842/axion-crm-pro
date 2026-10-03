<?php

namespace App\Console\Commands;

use App\Crm\Referentiels\LibellesNaf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Charge ou actualise les tables de référence de la NAF rév. 2
 * (`naf_sections`, `naf_divisions`, `naf_groups`, `naf_classes`,
 * `naf_subclasses`) depuis les CSV versionnés de `resources/referentiels/`.
 *
 * Lot N7 (2026-10-03) : `naf_subclasses` était vide en production, donc aucun
 * libellé d'activité côté API.
 *
 * - IDEMPOTENTE : un second passage n'écrit rien (« 0 ajoutée, 0 mise à jour »).
 * - UPSERT sur le code : une ligne absente est ajoutée, un libellé ou un parent
 *   qui a changé est mis à jour, le reste n'est pas touché.
 * - JAMAIS de suppression : une ligne présente en base et absente des CSV est
 *   conservée (et comptée). `is_artisanat` n'est jamais réécrit.
 * - Tables GLOBALES (aucune colonne `workspace_id`, donc hors RLS) : la
 *   commande n'a besoin d'aucun contexte d'espace.
 *
 * Production, après déploiement : `php artisan crm:referentiels:charger-naf`.
 */
class CrmReferentielsChargerNaf extends Command
{
    protected $signature = 'crm:referentiels:charger-naf
                            {--dry-run : Compter ce qui serait ajouté ou mis à jour, sans rien écrire}';

    protected $description = 'Charge ou actualise les libellés de la NAF rév. 2 (5 niveaux) depuis les CSV du dépôt, sans jamais rien supprimer.';

    /** niveau => [table, colonne parent] — dans l'ordre imposé par les clés étrangères. */
    private const TABLES = [
        'section' => ['naf_sections', null],
        'division' => ['naf_divisions', 'section_code'],
        'groupe' => ['naf_groups', 'division_code'],
        'classe' => ['naf_classes', 'group_code'],
        'sous_classe' => ['naf_subclasses', 'class_code'],
    ];

    private const PARENT_DE = [
        'division' => 'section',
        'groupe' => 'division',
        'classe' => 'groupe',
        'sous_classe' => 'classe',
    ];

    public function handle(): int
    {
        $lignes = LibellesNaf::lignes();

        // Cohérence AVANT toute écriture : chaque parent existe au niveau
        // supérieur des CSV. Sinon la clé étrangère casserait la transaction à
        // mi-chemin — on préfère refuser proprement.
        foreach (self::PARENT_DE as $niveau => $niveauParent) {
            $parents = array_flip(array_column($lignes[$niveauParent], 'code'));
            foreach ($lignes[$niveau] as $l) {
                if ($l['parent'] === null || ! isset($parents[$l['parent']])) {
                    $this->error("Référentiel incohérent : {$niveau} {$l['code']} sans parent connu.");

                    return self::FAILURE;
                }
            }
        }

        $simulation = (bool) $this->option('dry-run');
        $bilan = [];

        DB::transaction(function () use ($lignes, $simulation, &$bilan): void {
            foreach (self::TABLES as $niveau => [$table, $colonneParent]) {
                $bilan[$niveau] = $this->chargerNiveau($table, $colonneParent, $lignes[$niveau], $simulation);
            }
        });

        $this->table(
            ['Niveau', 'Dans les CSV', 'Ajoutées', 'Mises à jour', 'Inchangées', 'Conservées hors CSV'],
            array_map(
                static fn (string $niveau, array $b): array => [$niveau, $b['csv'], $b['ajoutees'], $b['maj'], $b['inchangees'], $b['hors_csv']],
                array_keys($bilan),
                $bilan,
            ),
        );
        $this->info($simulation
            ? 'Simulation : rien n\'a été écrit.'
            : 'Référentiel NAF à jour. Aucune ligne supprimée.');

        return self::SUCCESS;
    }

    /**
     * @param  list<array{code: string, parent: string|null, label: string}>  $lignes
     * @return array{csv: int, ajoutees: int, maj: int, inchangees: int, hors_csv: int}
     */
    private function chargerNiveau(string $table, ?string $colonneParent, array $lignes, bool $simulation): array
    {
        $colonnes = $colonneParent === null ? ['code', 'label'] : ['code', 'label', $colonneParent];

        // Tables de quelques centaines de lignes : tout lire est instantané.
        $existant = [];
        foreach (DB::table($table)->get($colonnes) as $r) {
            $existant[trim((string) $r->code)] = [
                'label' => (string) $r->label,
                'parent' => $colonneParent === null ? null : trim((string) $r->{$colonneParent}),
            ];
        }

        $aEcrire = [];
        $bilan = ['csv' => count($lignes), 'ajoutees' => 0, 'maj' => 0, 'inchangees' => 0, 'hors_csv' => 0];
        $vus = [];
        foreach ($lignes as $l) {
            $vus[$l['code']] = true;
            $actuel = $existant[$l['code']] ?? null;
            if ($actuel === null) {
                $bilan['ajoutees']++;
            } elseif ($actuel['label'] !== $l['label'] || $actuel['parent'] !== $l['parent']) {
                $bilan['maj']++;
            } else {
                $bilan['inchangees']++;

                continue;
            }
            $ligne = ['code' => $l['code'], 'label' => $l['label']];
            if ($colonneParent !== null) {
                $ligne[$colonneParent] = $l['parent'];
            }
            $aEcrire[] = $ligne;
        }
        $bilan['hors_csv'] = count(array_diff_key($existant, $vus));

        if (! $simulation && $aEcrire !== []) {
            $miseAJour = $colonneParent === null ? ['label'] : ['label', $colonneParent];
            foreach (array_chunk($aEcrire, 500) as $paquet) {
                DB::table($table)->upsert($paquet, ['code'], $miseAJour);
            }
        }

        return $bilan;
    }
}
