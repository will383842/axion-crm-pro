<?php

namespace App\Console\Commands;

use App\Crm\EspaceProspection;
use App\Crm\Joignabilite\Joignabilite;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * CALCUL DE LA JOIGNABILITÉ — `companies.joignabilite` et `contacts.joignabilite`
 * (chantier D, définition dans `App\Crm\Joignabilite\Joignabilite`).
 *
 *  - PAR LOTS d'entreprises (`--lot`, 1 000 par défaut), chacune avec SES
 *    personnes, chaque lot dans SA transaction courte (`lock_timeout` 5 s) ;
 *  - curseur par identifiant : interrompue, elle dit où reprendre
 *    (`--depuis-id`) ;
 *  - idempotente : un état qui n'a pas changé n'est pas réécrit ;
 *  - `updated_at` n'est pas touché (`app.conserver_updated_at`) : calculer
 *    n'est pas modifier la fiche ;
 *  - RIEN n'est supprimé, aucune adresse n'est réécrite — seule la colonne
 *    `joignabilite` est écrite ;
 *  - journalisée : une entrée de la chaîne d'audit par lot écrit, une de fin ;
 *    aucune adresse à l'écran ni au journal, seulement des nombres.
 *
 * `--dry-run` lit et calcule TOUT avec le même code, n'écrit RIEN, et annonce
 * la répartition qu'aurait l'exécution.
 *
 * `crm:emails:verifier` recalcule lui-même les fiches dont il change une
 * vérification. Cette commande sert au PREMIER calcul, et à rafraîchir après
 * des oppositions ou des rebonds (l'état est une photo — l'envoi, lui,
 * repose la question adresse par adresse).
 */
class CrmJoignabiliteCalculer extends Command
{
    protected $signature = 'crm:joignabilite:calculer
                            {--dry-run : Tout lire et calculer, ne RIEN écrire}
                            {--workspace= : Identifiant ou slug de l\'espace (défaut : l\'espace business des campagnes)}
                            {--lot=1000 : Entreprises par lot (1 à 2000)}
                            {--depuis-id=0 : Reprendre APRÈS cette entreprise}
                            {--max-lots=0 : S\'arrêter après N lots (0 = jusqu\'au bout)}
                            {--pause-ms=0 : Pause entre deux lots}
                            {--compteurs-seulement : N\'afficher que des nombres (journaux publics)}';

    protected $description = 'Calcule la joignabilité (e-mail valide, invalide, interdit, non vérifié, téléphone, sans contact) des entreprises et des personnes — sans rien supprimer.';

    private const LOT_MAX = 2000;

    /** @var array<string, int> */
    private array $compteurs = [];

    /** @var array<string, array<string, array<string, int>>> table => avant|apres => état => n */
    private array $repartitions = [];

    public function handle(AuditHashChain $audit): int
    {
        $designation = is_string($this->option('workspace')) && $this->option('workspace') !== ''
            ? $this->option('workspace')
            : (string) config('crm.ingest.business_workspace', 'axion-ia');
        $workspaceId = EspaceProspection::resoudre($designation);
        if ($workspaceId === null) {
            $this->error("Espace introuvable : « {$designation} ».");

            return self::FAILURE;
        }
        $lot = (int) $this->option('lot');
        if ($lot < 1 || $lot > self::LOT_MAX) {
            $this->error('--lot : entre 1 et ' . self::LOT_MAX . '.');

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        $discret = (bool) $this->option('compteurs-seulement');
        $maxLots = max(0, (int) $this->option('max-lots'));
        $pauseMs = max(0, (int) $this->option('pause-ms'));
        $dernier = max(0, (int) $this->option('depuis-id'));
        $operateur = self::operateur();
        $this->compteurs = array_fill_keys([
            'lots', 'entreprises_lues', 'personnes_lues', 'entreprises_a_modifier', 'personnes_a_modifier',
            'entreprises_modifiees', 'personnes_modifiees',
        ], 0);
        $this->repartitions = [];

        $this->info(sprintf(
            '%s — espace %s, lots de %d, à partir de l\'id %d.',
            $dryRun ? '[À BLANC] rien ne sera écrit' : 'Calcul de la joignabilité',
            $discret ? '(masqué)' : $workspaceId,
            $lot,
            $dernier,
        ));

        $termine = false;
        $erreur = null;
        try {
            WorkspaceContext::run($workspaceId, function () use ($workspaceId, $lot, $maxLots, $pauseMs, $dryRun, $audit, $operateur, &$dernier, &$termine, &$erreur): void {
                $univers = Joignabilite::universDe($workspaceId);
                $lots = 0;
                while (true) {
                    $ids = DB::table('companies')
                        ->where('workspace_id', $workspaceId)
                        ->where('id', '>', $dernier)
                        ->whereNull('deleted_at')
                        ->orderBy('id')
                        ->limit($lot)
                        ->pluck('id')
                        ->map(static fn (mixed $id): int => (int) $id)
                        ->all();
                    if ($ids === []) {
                        $termine = true;

                        return;
                    }
                    $bas = min($ids);
                    $haut = max($ids);
                    $avant = $this->compteurs;
                    $avantRepartitions = $this->repartitions;

                    try {
                        if ($dryRun) {
                            $this->compter(Joignabilite::calculer($workspaceId, $ids, $univers));
                        } else {
                            // Relire ET écrire dans la MÊME transaction, fiches et
                            // personnes verrouillées (`FOR UPDATE`) : jamais un état
                            // périmé par-dessus un résultat récent de
                            // `crm:emails:verifier` (réserve R4 de la relecture).
                            DB::transaction(function () use ($workspaceId, $ids, $univers, $audit, $operateur, $bas, $haut): void {
                                DB::statement("SET LOCAL lock_timeout = '5s'");
                                DB::statement("SET LOCAL app.conserver_updated_at = 'on'");
                                $calcul = Joignabilite::calculer($workspaceId, $ids, $univers, true);
                                $this->compter($calcul);
                                $ecrites = Joignabilite::ecrire($workspaceId, $calcul);
                                $this->compteurs['entreprises_modifiees'] += $ecrites['entreprises'];
                                $this->compteurs['personnes_modifiees'] += $ecrites['personnes'];
                                if ($ecrites['entreprises'] + $ecrites['personnes'] > 0) {
                                    $this->auditer($audit, $workspaceId, $operateur, 'JOIGNABILITE_LOT', 200, [
                                        'ids' => [$bas, $haut],
                                        'entreprises_modifiees' => $ecrites['entreprises'],
                                        'personnes_modifiees' => $ecrites['personnes'],
                                    ], "ids {$bas}-{$haut}");
                                }
                            });
                        }
                    } catch (Throwable $e) {
                        $erreur = $e instanceof QueryException ? 'SQLSTATE ' . $e->getCode() : get_class($e);
                        Log::error('crm:joignabilite:calculer : lot annulé', ['apres_id' => $dernier, 'erreur' => $erreur]);
                        $this->compteurs = $avant;
                        $this->repartitions = $avantRepartitions;

                        return;
                    }

                    $lots++;
                    $this->compteurs['lots']++;
                    $dernier = $haut;
                    $this->line(sprintf('  lot %d : ids %d à %d', $lots, $bas, $haut));
                    if ($maxLots > 0 && $lots >= $maxLots) {
                        return;
                    }
                    if ($pauseMs > 0) {
                        usleep($pauseMs * 1000);
                    }
                }
            });
        } finally {
            if (! $dryRun) {
                try {
                    $this->auditer($audit, $workspaceId, $operateur, 'JOIGNABILITE_FIN', $erreur === null ? 200 : 500, [
                        'termine' => $termine, 'dernier_id' => $dernier, 'erreur' => $erreur, 'compteurs' => $this->compteurs,
                    ], $termine ? 'terminé' : "arrêté après l'id {$dernier}");
                } catch (Throwable $e) {
                    Log::error('crm:joignabilite:calculer : entrée d\'audit de fin NON écrite', ['erreur' => get_class($e)]);
                    $erreur ??= 'audit de fin : ' . get_class($e);
                }
            }
        }

        $this->afficherBilan($dryRun);

        if ($erreur !== null) {
            $this->error("ÉCHEC ({$erreur}). Rien n'a été écrit pour le lot en cours ; tout ce qui précède est acquis et journalisé. Reprendre avec : --depuis-id={$dernier}");

            return self::FAILURE;
        }
        if (! $termine) {
            $this->warn("Arrêt demandé. Reprendre avec : --depuis-id={$dernier}");
        } else {
            $this->info($dryRun ? '[À BLANC] terminé : rien n\'a été écrit.' : 'Calcul terminé.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{entreprises: array<int, array{avant: ?string, apres: string}>, personnes: array<int, array{avant: ?string, apres: string}>}  $calcul
     */
    private function compter(array $calcul): void
    {
        foreach (['entreprises', 'personnes'] as $cle) {
            foreach ($calcul[$cle] as $e) {
                $this->compteurs[$cle . '_lues']++;
                if ($e['avant'] !== $e['apres']) {
                    $this->compteurs[$cle . '_a_modifier']++;
                }
                $avant = $e['avant'] ?? '(non calculé)';
                $this->repartitions[$cle]['avant'][$avant] = ($this->repartitions[$cle]['avant'][$avant] ?? 0) + 1;
                $this->repartitions[$cle]['apres'][$e['apres']] = ($this->repartitions[$cle]['apres'][$e['apres']] ?? 0) + 1;
            }
        }
    }

    private function afficherBilan(bool $dryRun): void
    {
        $this->newLine();
        $this->info($dryRun ? '═══ BILAN DE L\'ESSAI À BLANC (rien n\'a été écrit) ═══' : '═══ BILAN DU CALCUL ═══');
        $compteurs = $this->compteurs;
        if ($dryRun) {
            unset($compteurs['entreprises_modifiees'], $compteurs['personnes_modifiees']);
        }
        $this->table(['compteur', 'nombre'], array_map(
            static fn (string $cle, int $n): array => [$cle, $n],
            array_keys($compteurs),
            array_values($compteurs),
        ));
        foreach (['entreprises', 'personnes'] as $cle) {
            $lignes = [];
            foreach (array_merge(Joignabilite::ETATS, ['(non calculé)']) as $etat) {
                $avant = $this->repartitions[$cle]['avant'][$etat] ?? 0;
                $apres = $this->repartitions[$cle]['apres'][$etat] ?? 0;
                if ($avant > 0 || $apres > 0) {
                    $lignes[] = [$etat, $avant, $apres];
                }
            }
            $this->line(ucfirst($cle) . ' :');
            $this->table(['état', 'avant', 'après'], $lignes);
        }
        $this->line('Aucune adresse supprimée ni réécrite : seule la colonne `joignabilite` est écrite.');
    }

    /** « utilisateur@hôte » du processus qui a lancé la commande. */
    private static function operateur(): string
    {
        $utilisateur = get_current_user();
        $hote = gethostname();

        return ($utilisateur !== '' ? $utilisateur : '?') . '@' . ($hote !== false ? $hote : '?');
    }

    /** @param  array<string, mixed>  $details */
    private function auditer(AuditHashChain $audit, string $workspaceId, string $operateur, string $evenement, int $statut, array $details, string $resume): void
    {
        $audit->record([
            'workspace_id' => $workspaceId,
            'user_id' => null,
            'method' => $evenement,
            'path' => 'artisan crm:joignabilite:calculer — ' . $resume,
            'status' => $statut,
            'ip' => null,
            'user_agent' => 'cli ' . $operateur,
            'payload_hash' => hash('sha256', json_encode($details, JSON_THROW_ON_ERROR)),
        ]);
    }
}
