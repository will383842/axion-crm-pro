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
            // Liste des identifiants en deux requêtes indexées (NAF + espace, puis FINESS + espace),
            // puis paquets par clé primaire : aucun parcours complet de `companies`.
            $ids = $this->identifiants($workspaceId);
            $paquets = array_chunk($ids, 1000);
            $requete = fn (array $paquet) => DB::table('companies')->where('workspace_id', $workspaceId)->whereNull('deleted_at')->whereIn('id', $paquet);
            $b = ['total' => 0, 'inconnue_avant' => 0, 'taille' => 0, 'effectif' => 0, 'officines' => 0];
            foreach ($paquets as $paquet) {
                $b['total'] += $requete($paquet)->count();
                $b['inconnue_avant'] += $this->inconnues($requete($paquet));
            }

            foreach ($paquets as $paquet) {
                $fiches = $requete($paquet)->orderBy('id')->get(['id', 'effectif_range', 'effectif_min', 'effectif_max', 'size_category', 'metadata']);
                $nb = DB::table('finess_etablissements')->where('workspace_id', $workspaceId)->where('categorie', '620')
                    ->whereIn('company_id', $fiches->pluck('id')->all())->groupBy('company_id')
                    ->selectRaw('company_id, count(*) AS n')->pluck('n', 'company_id');
                foreach ($fiches as $f) {
                    $tranche = trim((string) $f->effectif_range);
                    $taille = null;
                    if (trim((string) $f->size_category) === '' && $tranche !== '') {
                        $taille = Classement::tailleDepuisInsee($tranche, null);
                        $b['taille']++;
                    }
                    $bornes = null;
                    if ($f->effectif_min === null && $f->effectif_max === null && isset(self::TRANCHES[$tranche])) {
                        $bornes = self::TRANCHES[$tranche];
                        $b['effectif']++;
                    }
                    $meta = json_decode((string) $f->metadata, true);
                    $poserOfficines = ! (is_array($meta) && array_key_exists('nb_officines_societe', $meta)) && isset($nb[$f->id]);
                    if ($poserOfficines) {
                        $b['officines']++;
                    }
                    if ($essai) {
                        continue;
                    }
                    // Écritures conditionnelles en SQL : jamais de relecture ni de réécriture complète de metadata.
                    if ($poserOfficines) {
                        DB::update(
                            "UPDATE companies SET metadata = COALESCE(metadata, '{}'::jsonb) || jsonb_build_object('nb_officines_societe', ?::int), updated_at = now() WHERE id = ? AND workspace_id = ? AND NOT jsonb_exists(COALESCE(metadata, '{}'::jsonb), 'nb_officines_societe')",
                            [(int) $nb[$f->id], $f->id, $workspaceId],
                        );
                    }
                    if ($taille !== null) {
                        DB::table('companies')->where('id', $f->id)->where('workspace_id', $workspaceId)->where(fn ($q) => $q->whereNull('size_category')->orWhere('size_category', ''))->update(['size_category' => $taille, 'updated_at' => now()]);
                    }
                    if ($bornes !== null) {
                        DB::table('companies')->where('id', $f->id)->where('workspace_id', $workspaceId)->whereNull('effectif_min')->whereNull('effectif_max')->update(['effectif_min' => $bornes[0], 'effectif_max' => $bornes[1], 'updated_at' => now()]);
                    }
                }
            }
            $b['inconnue_apres'] = null;
            if (! $essai) {
                $b['inconnue_apres'] = 0;
                foreach ($paquets as $paquet) {
                    $b['inconnue_apres'] += $this->inconnues($requete($paquet));
                }
            }

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

    /**
     * Identifiants des fiches pharmacie : NAF 47.73Z (index espace + NAF) UNION fiches portant une ligne FINESS.
     *
     * @return list<int>
     */
    private function identifiants(string $workspaceId): array
    {
        $parNaf = DB::table('companies')->select('id')->where('workspace_id', $workspaceId)->whereIn('naf', ['47.73Z', '4773Z'])->whereNull('deleted_at');
        $parFiness = DB::table('finess_etablissements')->select('company_id AS id')->where('workspace_id', $workspaceId);

        return array_values(array_map('intval', $parNaf->union($parFiness)->orderBy('id')->pluck('id')->all()));
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
