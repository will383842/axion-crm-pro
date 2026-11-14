<?php

namespace App\Envoi\Temoins;

use App\Envoi\Chauffe\GardeChauffe;
use App\Envoi\JourEnvoi;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Relève automatique des témoins (R16, point g). Pour chaque témoin ayant un accès : lit son dossier d'indésirables,
 * reconnaît NOS messages par l'empreinte du Message-ID (`envoi_messages_envoyes`) ET par l'empreinte du destinataire
 * (le témoin lui-même), puis ouvre la pause vers son fournisseur ({@see GardeChauffe::indesirableChezTemoin}) et écrit une
 * journée `releve_auto`. UN CONSTAT PEUT FREINER, JAMAIS LIBÉRER : on n'écrit jamais « inbox » ni « absent » (le dossier
 * principal n'est pas lu), donc rien ici ne peut faire passer `auto.temoins_3_jours`. Un message d'un tiers n'est jamais compté.
 */
final class ReleveTemoins
{
    public const AUTEUR = 'releve_auto';

    private const FENETRE = '-48 hours';

    public function __construct(
        private readonly DepotAccesTemoins $acces,
        private readonly LecteurIndesirables $lecteur,
        private readonly GardeChauffe $garde,
    ) {}

    /**
     * @return array{temoins: int, indesirables: int, pannes: int, fournisseurs: list<string>}
     */
    public function relever(string $ws, DateTimeImmutable $maintenant): array
    {
        $bilan = ['temoins' => 0, 'indesirables' => 0, 'pannes' => 0, 'fournisseurs' => []];
        // Verrou consultatif de l'espace (le même que les autres gestes sur les témoins) : deux relèves ou une relève et
        // un geste manuel ne se croisent pas. Pris ? On ne lit rien et on le SIGNALE (panne) : le prochain passage réessaie.
        $verrou = 'envoi_temoins:' . $ws;
        try {
            $pris = (bool) DB::selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS ok', [$verrou])->ok;
        } catch (Throwable $e) {
            Log::error('[ENVOI] relève des témoins : verrou de l\'espace impossible.', ['exception' => $e::class]);
            $bilan['pannes']++;

            return $bilan;
        }
        if (! $pris) {
            Log::error('[ENVOI] relève des témoins : espace déjà occupé, relève ignorée.');
            $bilan['pannes']++;

            return $bilan;
        }
        try {
            $this->releverEspace($ws, $maintenant, $bilan);
        } catch (Throwable $e) {
            // Une erreur de l'espace (lecture des accès, base) est UNE panne de CET espace, jamais celle des suivants.
            $bilan['pannes']++;
            Log::error('[ENVOI] relève des témoins impossible pour un espace.', ['exception' => $e::class]);
        } finally {
            try {
                DB::select('SELECT pg_advisory_unlock(hashtext(?))', [$verrou]);
            } catch (Throwable) {
                // la session se ferme avec le processus : le verrou tombe avec elle
            }
        }

        return $bilan;
    }

    /** @param array{temoins: int, indesirables: int, pannes: int, fournisseurs: list<string>} $bilan */
    private function releverEspace(string $ws, DateTimeImmutable $maintenant, array &$bilan): void
    {
        $depuis = $maintenant->modify(self::FENETRE);
        $jour = JourEnvoi::de($maintenant)->format('Y-m-d');
        $liste = $this->acces->listerAvecPannes($ws);
        $bilan['pannes'] += $liste['illisibles']; // une ligne illisible = une panne, les autres lignes continuent
        foreach ($liste['acces'] as $acces) {
            $bilan['temoins']++;
            try {
                $ids = $this->lecteur->messageIdsEnIndesirables($acces, $depuis);
                $nos = $this->nosMessages($ws, $acces, $ids);
                if ($nos === 0) {
                    continue;
                }
                // La pause d'abord (idempotente) : si elle échoue, aucune journée n'est écrite et le prochain passage réessaie.
                $this->garde->indesirableChezTemoin($ws, $acces->adresse, $maintenant, self::AUTEUR);
                $this->ecrireJournee($ws, $jour, $acces->fournisseur);
                $bilan['indesirables']++;
                $bilan['fournisseurs'][] = $acces->fournisseur;
            } catch (Throwable $e) {
                $bilan['pannes']++;
                Log::error('[ENVOI] relève des témoins impossible pour un témoin.', ['exception' => $e::class, 'fournisseur' => $acces->fournisseur]);
            }
        }
    }

    /**
     * Écrit la journée rouge (rejeu sans effet grâce à l'index unique). Si l'insertion est ignorée parce qu'une AUTRE ligne
     * `releve_auto` occupe déjà la place (ligne non rouge posée à la main en base), le constat ne doit pas disparaître en
     * silence : on le signale (panne) ; la pause, elle, est déjà ouverte et le feu vert ignore de toute façon les lignes `releve_auto` vertes.
     */
    private function ecrireJournee(string $ws, string $jour, string $fournisseur): void
    {
        WorkspaceContext::run($ws, function () use ($ws, $jour, $fournisseur): void {
            DB::table('envoi_temoins_journees')->insertOrIgnore([
                'workspace_id' => $ws,
                'jour' => $jour,
                'fournisseur' => $fournisseur,
                'reception' => 'indesirables',
                'auth_alignee' => false,
                'bouton_vu' => false,
                'post_effectif' => false,
                'auteur' => self::AUTEUR,
            ]);
            $rouge = DB::table('envoi_temoins_journees')->where('workspace_id', $ws)->where('jour', $jour)
                ->where('fournisseur', $fournisseur)->where('auteur', self::AUTEUR)->where('reception', 'indesirables')->exists();
            if (! $rouge) {
                throw new RuntimeException('Journée rouge non écrite : une autre ligne de la relève occupe la place.');
            }
        });
    }

    /** @param list<string> $ids */
    private function nosMessages(string $ws, AccesTemoin $acces, array $ids): int
    {
        $empreintes = [];
        foreach ($ids as $id) {
            $empreintes[] = hash('sha256', 'mid:' . trim($id, " \t\r\n<>"));
        }
        if ($empreintes === []) {
            return 0;
        }

        return WorkspaceContext::run($ws, fn (): int => DB::table('envoi_messages_envoyes')
            ->where('workspace_id', $ws)
            ->whereIn('message_id_empreinte', $empreintes)
            ->where('empreinte_destinataire', ListeSuppression::empreinte($acces->adresse))
            ->count());
    }
}
