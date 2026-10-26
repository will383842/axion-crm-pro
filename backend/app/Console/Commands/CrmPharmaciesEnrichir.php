<?php

namespace App\Console\Commands;

use App\Crm\EspaceProspection;
use App\Crm\Pharmacies\Groupement;
use App\Crm\Pharmacies\SelectionEnrichissement;
use App\Jobs\EnrichCompanyJob;
use App\Jobs\EnrichirSiteOfficineJob;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ENRICHIR AU MAXIMUM LES PHARMACIES OUVERTES (NAF 47.73Z) — en réutilisant
 * les enrichisseurs existants, sans en réécrire aucun :
 *
 *  - société / EI à forme morale : `EnrichCompanyJob` (waterfall : annuaire,
 *    recherche du site, mentions légales, e-mail générique, téléphone,
 *    contacts, géocodage BAN, proxys Webshare du CRM pour Pages Jaunes), en
 *    mode `sansPayant` : Google Places et la classification LLM sont SAUTÉS ;
 *  - personne physique (`metadata.personne_physique`) : `EnrichirSiteOfficineJob`
 *    — le site et le téléphone de l'officine seulement, jamais le nom ni
 *    l'adresse de la personne.
 *
 * Ordre : grandes officines d'abord, puis les plus lacunaires.
 * Idempotent : une fiche passée depuis moins de `--jours` jours, ou déjà
 * complète, n'est pas remise en file ; rien n'est écrasé ni supprimé ; aucun
 * e-mail ni SMS n'est envoyé. Le groupement (`metadata.groupement`) est
 * déduit de l'enseigne et du site, sur un champ vide seulement.
 *
 * `--dry-run` (défaut) n'écrit rien et ne met rien en file. Sortie : totaux.
 */
class CrmPharmaciesEnrichir extends Command
{
    protected $signature = 'crm:pharmacies:enrichir
                            {--dry-run : Essai à blanc (défaut) — compte, n écrit et ne met rien en file}
                            {--apply : Pose le groupement et met les enrichisseurs en file}
                            {--limite=0 : Fiches mises en file au plus (0 = toutes)}
                            {--lot=500 : Taille d un lot}
                            {--pause=30 : Secondes de décalage entre deux lots}
                            {--jours=30 : Pas de nouveau passage avant ce délai}
                            {--workspace= : Identifiant ou slug de l espace}';

    protected $description = 'Met en file les enrichisseurs existants pour les pharmacies ouvertes (grandes d abord) ; idempotent, dry-run par défaut.';

    public function handle(): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('--apply et --dry-run s excluent.');

            return self::FAILURE;
        }
        $appliquer = (bool) $this->option('apply');
        $espace = EspaceProspection::resoudre(is_string($this->option('workspace')) ? $this->option('workspace') : null);
        if ($espace === null) {
            $this->error('Aucun espace cible (--workspace=UUID ou slug).');

            return self::FAILURE;
        }
        $limite = max(0, (int) $this->option('limite'));
        $lot = max(1, (int) $this->option('lot'));
        $pause = max(0, (int) $this->option('pause'));
        $jours = max(0, (int) $this->option('jours'));

        $bilan = WorkspaceContext::run($espace, fn (): array => $this->traiter($espace, $appliquer, $limite, $lot, $pause, $jours));

        $this->info('Bilan' . ($appliquer ? '' : ' (ESSAI À BLANC)'));
        $this->line(sprintf('  pharmacies ouvertes : %d', $bilan['ouvertes']));
        $this->line(sprintf('  à enrichir (champ manquant, pas de passage récent) : %d', $bilan['a_enrichir']));
        $this->line(sprintf('  groupements %s : %d', $appliquer ? 'posés' : 'à poser', $bilan['groupements']));
        $this->line(sprintf('  %s : complet %d, personnes physiques (site et téléphone seulement) %d, en %d lot(s)', $appliquer ? 'mises en file' : 'à mettre en file', $bilan['complet'], $bilan['physiques'], $bilan['lots']));

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function traiter(string $espace, bool $appliquer, int $limite, int $lot, int $pause, int $jours): array
    {
        $b = [
            'ouvertes' => SelectionEnrichissement::ouvertes($espace)->count(),
            'a_enrichir' => SelectionEnrichissement::aEnrichir($espace, $jours)->count(),
            'groupements' => $this->groupements($espace, $appliquer),
            'complet' => 0, 'physiques' => 0, 'lots' => 0,
        ];

        $restant = $limite > 0 ? $limite : PHP_INT_MAX;
        // Sélection figée avant toute mise en file : le marqueur posé en cours de route ne la fait pas bouger.
        $ids = SelectionEnrichissement::classer(SelectionEnrichissement::aEnrichir($espace, $jours))
            ->limit($limite > 0 ? $limite : PHP_INT_MAX)->get(['id', 'metadata']);
        foreach ($ids->chunk($lot) as $numero => $paquet) {
            if ($restant <= 0) {
                break;
            }
            $b['lots']++;
            foreach ($paquet as $f) {
                if ($restant-- <= 0) {
                    break;
                }
                $physique = SelectionEnrichissement::estPersonnePhysique($f->metadata);
                $physique ? $b['physiques']++ : $b['complet']++;
                if (! $appliquer) {
                    continue;
                }
                $this->marquer((int) $f->id, $physique);
                $job = $physique
                    ? new EnrichirSiteOfficineJob((int) $f->id)
                    : (new EnrichCompanyJob((int) $f->id))->sansPayant();
                $job->pourEspace($espace);
                if ($pause > 0 && $numero > 0) {
                    $job->delay(now()->addSeconds($pause * $numero));
                }
                dispatch($job);
            }
        }

        return $b;
    }

    /** Date + source du passage, dans `metadata` (clé fusionnée, rien d autre n est touché). */
    private function marquer(int $id, bool $physique): void
    {
        $sources = $physique ? ['domain-finder', 'site-officine'] : ['waterfall-existant:sans-google-places-ni-llm'];
        DB::update(
            "UPDATE companies SET metadata = COALESCE(metadata, '{}'::jsonb) || jsonb_build_object(?::text, jsonb_build_object('le', now()::text, 'mode', ?::text, 'sources', ?::jsonb)) WHERE id = ?",
            [SelectionEnrichissement::CLE_PASSAGE, $physique ? 'physique' : 'complet', json_encode($sources), $id],
        );
    }

    /** Pose `metadata.groupement` sur les fiches ouvertes qui n en ont pas ; renvoie le nombre trouvé. */
    private function groupements(string $espace, bool $appliquer): int
    {
        $n = 0;
        SelectionEnrichissement::ouvertes($espace)
            ->whereRaw("NOT jsonb_exists(COALESCE(metadata, '{}'::jsonb), 'groupement')")
            ->orderBy('id')->select(['id', 'enseigne', 'website'])
            ->chunkById(1000, function ($fiches) use ($appliquer, &$n): void {
                foreach ($fiches as $f) {
                    $g = Groupement::detecter($f->enseigne, $f->website);
                    if ($g === null) {
                        continue;
                    }
                    $n++;
                    if ($appliquer) {
                        DB::update(
                            "UPDATE companies SET metadata = COALESCE(metadata, '{}'::jsonb) || jsonb_build_object('groupement', ?::text, 'groupement_source', 'mots-cles:enseigne-site') WHERE id = ? AND NOT jsonb_exists(COALESCE(metadata, '{}'::jsonb), 'groupement')",
                            [$g, $f->id],
                        );
                    }
                }
            });

        return $n;
    }
}
