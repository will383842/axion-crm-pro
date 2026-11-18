<?php

namespace App\Console\Commands;

use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * L8-B — constat en LECTURE SEULE des lignes encore rattachées par les anciennes formes d'identifiant d'injection
 * (cahier B6-B8, § 12.2). Il conditionne le retrait des branches de transition (L8-C, décision du fondateur).
 *
 * Trois comptes par espace :
 * - forme2 : messages dont `id_injection` est nul et `id_injection_serveur` renseigné ;
 * - forme3 : messages que seule la clé `seq-<inscription>-<étape>` du registre local rattache (ni `id_injection`
 *   ni `id_injection_serveur` ne portent l'identifiant du registre) ;
 * - replis : messages dont le registre des envoyés ne porte pas le même identifiant d'injection : seul le repli de
 *   `RegistreMessagesEnvoyesDb::parIdentifiantServeur` les retrouve.
 *
 * Sortie : identifiants d'espace et nombres, jamais d'adresse. Aucune écriture ; non planifiée.
 */
class EnvoiTransitionConstat extends Command
{
    protected $signature = 'envoi:transition:constat {workspace? : identifiant de l’espace (tous les espaces si absent)}';

    protected $description = 'Compte, sans rien écrire, les lignes d’ancienne forme d’identifiant d’injection (lecture seule)';

    public function handle(): int
    {
        $ws = $this->argument('workspace');
        $espaces = $this->espaces($ws === null ? null : (string) $ws);
        if ($espaces === []) {
            $this->error('Aucun espace correspondant.');

            return self::FAILURE;
        }
        foreach ($espaces as $espace) {
            try {
                $c = WorkspaceContext::run($espace, fn (): array => $this->compter($espace));
            } catch (\Throwable $e) {
                // Jamais le message (une QueryException porte du SQL et des valeurs) : la classe seulement.
                Log::error('[ENVOI] constat de transition : espace en erreur.', ['workspace' => $espace, 'exception' => $e::class]);
                $this->error($espace . ' — constat impossible (' . $e::class . ').');

                return self::FAILURE;
            }
            $this->line($espace . ' forme2=' . $c['forme2'] . ' forme3=' . $c['forme3'] . ' replis=' . $c['replis']);
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function espaces(?string $un): array
    {
        return WorkspaceContext::runWithoutScope(
            'envoi:transition:constat : lecture des identifiants d’espace seulement',
            function () use ($un): array {
                $q = DB::table('workspaces')->whereNull('deleted_at')->orderBy('id');
                if ($un !== null) {
                    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $un) !== 1) {
                        return [];
                    }
                    $q->where('id', strtolower($un));
                }

                return array_values(array_map('strval', $q->pluck('id')->all()));
            },
        );
    }

    /**
     * Une transaction en lecture seule, annulée à la fin (jamais de validation). Filtre d'espace explicite :
     * la RLS est contournée sous le propriétaire.
     *
     * @return array{forme2: int, forme3: int, replis: int}
     */
    private function compter(string $ws): array
    {
        DB::beginTransaction();
        try {
            DB::statement('SET LOCAL transaction_read_only = on');
            $forme2 = (int) DB::table('envoi_messages')->where('workspace_id', $ws)
                ->whereNull('id_injection')->whereNotNull('id_injection_serveur')->count();
            $forme3 = (int) (DB::selectOne(
                "SELECT count(*) AS n FROM envoi_messages m
                 JOIN envoi_injections_locales l ON l.workspace_id = m.workspace_id
                      AND l.cle_idempotence = 'seq-' || m.inscription_id::text || '-' || m.etape::text
                 WHERE m.workspace_id = ?
                   AND m.id_injection IS DISTINCT FROM l.id_injection::text
                   AND m.id_injection_serveur IS DISTINCT FROM l.id_injection::text",
                [$ws],
            )->n ?? 0);
            $replis = (int) (DB::selectOne(
                "SELECT count(*) AS n FROM envoi_messages m
                 JOIN envoi_messages_envoyes e ON e.workspace_id = m.workspace_id
                      AND e.message_id_empreinte = encode(sha256(convert_to('mid:' || btrim(m.message_id, E' \\t\\r\\n<>'), 'UTF8')), 'hex')
                 WHERE m.workspace_id = ?
                   AND m.id_injection IS DISTINCT FROM e.id_injection::text",
                [$ws],
            )->n ?? 0);
        } finally {
            DB::rollBack();
        }

        return ['forme2' => $forme2, 'forme3' => $forme3, 'replis' => $replis];
    }
}
