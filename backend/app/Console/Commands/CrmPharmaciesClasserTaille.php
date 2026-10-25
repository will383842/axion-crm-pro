<?php

namespace App\Console\Commands;

use App\Crm\EspaceProspection;
use App\Crm\Referentiels\Classement;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * TAILLE DES FICHES PHARMACIE — ne remplit QUE les champs vides.
 *
 *  - `size_category` depuis la tranche d'effectif SIRENE déjà portée par la fiche ;
 *  - `effectif_min` / `effectif_max` depuis cette tranche ;
 *  - `metadata.nb_officines_societe` : officines de la même société
 *    (`finess_etablissements`, catégorie 620), si la clé est absente.
 *
 * Aucune valeur existante n'est modifiée, aucun appel réseau. Affiche la part
 * de taille inconnue (avant / après).
 */
class CrmPharmaciesClasserTaille extends Command
{
    protected $signature = 'crm:pharmacies:classer-taille
                            {--dry-run : Calcule et affiche, n écrit rien}
                            {--workspace= : Identifiant ou slug de l espace}';

    protected $description = 'Remplit les champs de taille VIDES des fiches pharmacie (effectif SIRENE, tranche, nombre d officines) et affiche la part de taille inconnue.';

    /** Tranche INSEE → [min, max]. */
    private const TRANCHES = [
        '00' => [0, 0], '01' => [1, 2], '02' => [3, 5], '03' => [6, 9], '11' => [10, 19], '12' => [20, 49],
        '21' => [50, 99], '22' => [100, 199], '31' => [200, 249], '32' => [250, 499], '41' => [500, 999],
        '42' => [1000, 1999], '51' => [2000, 4999], '52' => [5000, 9999], '53' => [10000, null],
    ];

    public function handle(): int
    {
        $designation = $this->option('workspace');
        $workspaceId = EspaceProspection::resoudre(is_string($designation) ? $designation : null);
        if ($workspaceId === null) {
            $this->error('Aucun espace cible (--workspace=UUID ou slug).');

            return self::FAILURE;
        }
        $essai = (bool) $this->option('dry-run');

        $bilan = WorkspaceContext::run($workspaceId, function () use ($workspaceId, $essai): array {
            $cible = fn () => DB::table('companies')->where('workspace_id', $workspaceId)->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('discovery_source', 'finess')->orWhereRaw("replace(naf, '.', '') = '4773Z'"));
            $b = ['total' => $cible()->count(), 'inconnue_avant' => $this->inconnues($cible()), 'taille' => 0, 'effectif' => 0, 'officines' => 0];

            $cible()->orderBy('id')->select(['id', 'effectif_range', 'effectif_min', 'effectif_max', 'size_category', 'metadata'])->chunkById(1000, function ($fiches) use ($workspaceId, $essai, &$b): void {
                $nb = DB::table('finess_etablissements')->where('workspace_id', $workspaceId)->where('categorie', '620')
                    ->whereIn('company_id', $fiches->pluck('id')->all())->groupBy('company_id')
                    ->selectRaw('company_id, count(*) AS n')->pluck('n', 'company_id');
                foreach ($fiches as $f) {
                    $maj = [];
                    $tranche = trim((string) $f->effectif_range);
                    if (trim((string) $f->size_category) === '' && $tranche !== '') {
                        $maj['size_category'] = Classement::tailleDepuisInsee($tranche, null);
                        $b['taille']++;
                    }
                    if ($f->effectif_min === null && $f->effectif_max === null && isset(self::TRANCHES[$tranche])) {
                        [$maj['effectif_min'], $maj['effectif_max']] = self::TRANCHES[$tranche];
                        $b['effectif']++;
                    }
                    $meta = json_decode((string) $f->metadata, true);
                    $meta = is_array($meta) ? $meta : [];
                    if (! array_key_exists('nb_officines_societe', $meta) && isset($nb[$f->id])) {
                        $meta['nb_officines_societe'] = (int) $nb[$f->id];
                        $maj['metadata'] = json_encode($meta, JSON_UNESCAPED_UNICODE);
                        $b['officines']++;
                    }
                    if ($maj !== [] && ! $essai) {
                        DB::table('companies')->where('id', $f->id)->update($maj);
                    }
                }
            });
            $b['inconnue_apres'] = $essai ? null : $this->inconnues($cible());

            return $b;
        });

        $this->info('Bilan' . ($essai ? ' (ESSAI À BLANC)' : ''));
        $this->line(sprintf('  fiches pharmacie : %d', $bilan['total']));
        $this->line(sprintf('  tailles %s : %d, effectifs %s : %d, nombres d\'officines %s : %d', $essai ? 'à remplir' : 'remplies', $bilan['taille'], $essai ? 'à remplir' : 'remplis', $bilan['effectif'], $essai ? 'à poser' : 'posés', $bilan['officines']));
        $this->line(sprintf('  taille inconnue avant : %s', $this->part($bilan['inconnue_avant'], $bilan['total'])));
        if ($bilan['inconnue_apres'] !== null) {
            $this->line(sprintf('  taille inconnue après : %s', $this->part($bilan['inconnue_apres'], $bilan['total'])));
        }

        return self::SUCCESS;
    }

    private function inconnues(Builder $requete): int
    {
        return (int) $requete->where(fn ($q) => $q->whereNull('size_category')->orWhere('size_category', ''))->count();
    }

    private function part(int $n, int $total): string
    {
        return sprintf('%d (%s %%)', $n, $total > 0 ? number_format($n * 100 / $total, 1, ',', '') : '0');
    }
}
