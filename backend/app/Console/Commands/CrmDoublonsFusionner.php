<?php

namespace App\Console\Commands;

use App\Crm\Doublons\FusionFiches;
use App\Crm\Doublons\RefusFusion;
use App\Crm\EspaceProspection;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/**
 * FUSIONNER LES DOUBLONS CERTAINS — et annuler une fusion (chantier 5).
 *
 * Ne traite QUE les paires dont la preuve est certaine (`fusion_auto`), pas
 * encore traitées, et dont aucune fusion n'a jamais été annulée (une paire
 * défaite par un humain ne repart jamais seule). La preuve est RE-VÉRIFIÉE sur
 * les données du moment (`FusionFiches` → `Rapprochement::preuveCertaine`) :
 * une paire qui ne la tient plus reste dans la file de vérification.
 *
 * Chaque fusion : UNE transaction courte (jamais deux fusions dans la même —
 * le nombre de verrous reste celui d'une fusion, quel que soit le nombre de
 * paires), la fiche absorbée à la CORBEILLE (jamais supprimée), tout ce qui
 * pointait vers elle rattaché à la fiche gardée, un journal (`fusions_fiches`)
 * et une entrée de la chaîne d'audit. `--annuler=<id>` remet tout en place.
 *
 * `--dry-run` : chaque fusion passe par EXACTEMENT le même chemin, déclencheurs
 * compris, puis sa transaction est annulée. Ce qui est MESURÉ vaut pour chaque
 * paire prise seule : une fusion à blanc ne voit pas les précédentes (une
 * fiche absorbée par la première serait refusée, en vrai, dans la seconde).
 *
 * Reprenable (`--depuis-id` : dernier identifiant de paire annoncé) ; une
 * paire déjà fusionnée ne peut pas l'être deux fois (`reviewed_at`).
 */
class CrmDoublonsFusionner extends Command
{
    protected $signature = 'crm:doublons:fusionner
                            {--dry-run : Chaque fusion passe par le même chemin puis est annulée : rien n\'est écrit}
                            {--workspace= : Identifiant ou slug de l\'espace (défaut : celui de prospection:collect)}
                            {--lot=100 : Paires lues par lot (1 à 1000)}
                            {--depuis-id=0 : Reprendre APRÈS cette paire}
                            {--max-lots=0 : S\'arrêter après N lots (0 = jusqu\'au bout)}
                            {--pause-ms=0 : Pause entre deux fusions}
                            {--annuler= : Annuler la fusion n° <id> (remet tout en place)}
                            {--compteurs-seulement : N\'afficher que des nombres (journaux publics des workflows)}';

    protected $description = 'Fusionne les doublons CERTAINS (fiche absorbée à la corbeille, jamais supprimée), ou annule une fusion.';

    public function handle(FusionFiches $fusion, AuditHashChain $audit): int
    {
        $designation = is_string($this->option('workspace')) ? $this->option('workspace') : null;
        $ws = EspaceProspection::resoudre($designation);
        if ($ws === null) {
            $this->error('Espace introuvable : « ' . ($designation ?? '(défaut)') . ' ».');

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        $operateur = self::operateur();

        $annuler = $this->option('annuler');
        if ($annuler !== null && $annuler !== '') {
            if (preg_match('/^[1-9]\d{0,17}$/', $annuler) !== 1) {
                $this->error('--annuler attend le numéro d\'une fusion.');

                return self::FAILURE;
            }

            return $this->annuler($fusion, $ws, (int) $annuler, $operateur, $dryRun);
        }

        $lot = max(1, min(1000, (int) $this->option('lot')));
        $dernier = max(0, (int) $this->option('depuis-id'));
        $maxLots = max(0, (int) $this->option('max-lots'));
        $pauseMs = max(0, (int) $this->option('pause-ms'));
        $discret = (bool) $this->option('compteurs-seulement');

        $this->info(sprintf(
            '%s — espace %s, paires CERTAINES seulement, à partir de la paire %d.',
            $dryRun ? '[À BLANC] chaque fusion sera annulée' : 'Fusion des doublons certains',
            $discret ? '(masqué)' : $ws,
            $dernier,
        ));

        $compteurs = ['lots' => 0, 'paires_lues' => 0, 'fusionnees' => 0, 'refusees' => 0];
        $refus = [];
        $termine = false;
        $erreur = null;
        try {
            WorkspaceContext::run($ws, function () use ($fusion, $ws, $dryRun, $lot, $maxLots, $pauseMs, $operateur, &$dernier, &$termine, &$compteurs, &$refus): void {
                $lots = 0;
                while (true) {
                    $paires = $this->lirePaires($ws, $dernier, $lot);
                    if ($paires === []) {
                        $termine = true;

                        return;
                    }
                    foreach ($paires as $p) {
                        $compteurs['paires_lues']++;
                        try {
                            $fusion->fusionner(
                                $ws,
                                (int) $p->entity_a_id,
                                (int) $p->entity_b_id,
                                (string) $p->motif,
                                FusionFiches::MODE_AUTO,
                                (int) $p->id,
                                null,
                                $operateur,
                                $dryRun,
                            );
                            $compteurs['fusionnees']++;
                        } catch (RefusFusion $r) {
                            $compteurs['refusees']++;
                            $refus[$r->raison] = ($refus[$r->raison] ?? 0) + 1;
                        }
                        $dernier = (int) $p->id;
                        if ($pauseMs > 0) {
                            usleep($pauseMs * 1000);
                        }
                    }
                    $lots++;
                    $compteurs['lots']++;
                    $this->line(sprintf('  lot %d : jusqu\'à la paire %d — %d fusionnée(s), %d refusée(s)', $lots, $dernier, $compteurs['fusionnees'], $compteurs['refusees']));
                    if ($maxLots > 0 && $lots >= $maxLots) {
                        return;
                    }
                }
            });
        } catch (Throwable $e) {
            $erreur = get_class($e);
            Log::error('crm:doublons:fusionner : arrêt', ['apres_paire' => $dernier, 'erreur' => $erreur]);
        } finally {
            if (! $dryRun) {
                try {
                    $audit->record([
                        'workspace_id' => $ws, 'user_id' => null, 'method' => 'FUSION_DOUBLONS_FIN',
                        'path' => 'artisan crm:doublons:fusionner — ' . ($termine ? 'terminé' : "arrêté après la paire {$dernier}"),
                        'status' => $erreur === null ? 200 : 500, 'ip' => null, 'user_agent' => 'cli ' . $operateur,
                        'payload_hash' => hash('sha256', json_encode(['compteurs' => $compteurs, 'refus' => $refus, 'erreur' => $erreur], JSON_THROW_ON_ERROR)),
                    ]);
                } catch (Throwable $e) {
                    $erreur ??= 'audit de fin : ' . get_class($e);
                }
            }
        }

        $this->newLine();
        $this->info($dryRun ? '═══ BILAN DE L\'ESSAI À BLANC (chaque fusion annulée : rien n\'a été écrit) ═══' : '═══ BILAN DES FUSIONS ═══');
        $lignes = [];
        foreach ($compteurs as $cle => $n) {
            $lignes[] = [$cle, $n];
        }
        ksort($refus);
        foreach ($refus as $raison => $n) {
            $lignes[] = ["refus_{$raison}", $n];
        }
        $this->table(['compteur', 'nombre'], $lignes);
        $this->line("Verrous tenus au plus pendant une fusion : {$fusion->verrousMax} (dont {$fusion->verrousTxMax} d'identifiants de transaction)");
        if ($dryRun) {
            $this->line('MESURÉ : chaque fusion prise seule, sur le même chemin que l\'exécution réelle, puis annulée — une fusion à blanc ne voit pas les précédentes.');
        }

        if ($erreur !== null) {
            $this->error("ÉCHEC ({$erreur}). Les fusions déjà validées sont acquises et journalisées. Reprendre avec : --depuis-id={$dernier}");

            return self::FAILURE;
        }
        if (! $termine) {
            $this->warn("Arrêt demandé. Reprendre avec : --depuis-id={$dernier}");
        } elseif (! $dryRun) {
            $this->info('Terminé. Une fusion s\'annule par : php artisan crm:doublons:fusionner --annuler=<numéro>');
        }

        return self::SUCCESS;
    }

    private function annuler(FusionFiches $fusion, string $ws, int $id, string $operateur, bool $dryRun): int
    {
        try {
            $bilan = WorkspaceContext::run($ws, fn (): array => $fusion->annuler($ws, $id, $operateur, $dryRun));
        } catch (RefusFusion $r) {
            $this->error("REFUS ({$r->raison}) : {$r->getMessage()} Rien n'a été écrit.");

            return self::FAILURE;
        }
        $this->info($dryRun ? "[À BLANC] l'annulation de la fusion {$id} passerait (rien n'a été écrit) :" : "Fusion {$id} annulée : tout est remis en place.");
        $this->table(['compteur', 'nombre'], array_map(
            static fn (string $cle, int $n): array => [$cle, $n],
            array_keys($bilan),
            array_values($bilan),
        ));

        return self::SUCCESS;
    }

    /** @return list<stdClass> */
    private function lirePaires(string $ws, int $apres, int $taille): array
    {
        // `idx_dup_flags_file_fusion_auto` (workspace_id, id) partiel ; la
        // sous-requête passe par `idx_fusions_fiches_flag`. Alias distincts.
        $lignes = DB::select(
            "SELECT d.id, d.entity_a_id, d.entity_b_id, d.motif
             FROM duplicate_flags d
             WHERE d.workspace_id = ? AND d.reviewed_at IS NULL AND d.fusion_auto
               AND d.entity_type = 'company' AND d.motif IS NOT NULL AND d.id > ?
               AND NOT EXISTS (SELECT 1 FROM fusions_fiches ff WHERE ff.workspace_id = d.workspace_id AND ff.flag_id = d.id)
             ORDER BY d.id
             LIMIT {$taille}",
            [$ws, $apres],
        );
        $paires = [];
        foreach ($lignes as $l) {
            if ($l instanceof stdClass) {
                $paires[] = $l;
            }
        }

        return $paires;
    }

    private static function operateur(): string
    {
        $utilisateur = get_current_user();
        $hote = gethostname();

        return ($utilisateur !== '' ? $utilisateur : '?') . '@' . ($hote !== false ? $hote : '?');
    }
}
