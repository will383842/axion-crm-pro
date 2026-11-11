<?php

namespace App\Envoi\Temoins;

use App\Envoi\Chauffe\GardeChauffe;
use App\Envoi\JourEnvoi;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        $depuis = $maintenant->modify(self::FENETRE);
        $jour = JourEnvoi::de($maintenant)->format('Y-m-d');
        foreach ($this->acces->lister($ws) as $acces) {
            $bilan['temoins']++;
            try {
                $ids = $this->lecteur->messageIdsEnIndesirables($acces, $depuis);
                $nos = $this->nosMessages($ws, $acces, $ids);
                if ($nos === 0) {
                    continue;
                }
                // La pause d'abord (idempotente) : si elle échoue, aucune journée n'est écrite et le prochain passage réessaie.
                $this->garde->indesirableChezTemoin($ws, $acces->adresse, $maintenant, self::AUTEUR);
                WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins_journees')->insertOrIgnore([
                    'workspace_id' => $ws,
                    'jour' => $jour,
                    'fournisseur' => $acces->fournisseur,
                    'reception' => 'indesirables',
                    'auth_alignee' => false,
                    'bouton_vu' => false,
                    'post_effectif' => false,
                    'auteur' => self::AUTEUR,
                ]));
                $bilan['indesirables']++;
                $bilan['fournisseurs'][] = $acces->fournisseur;
            } catch (Throwable $e) {
                $bilan['pannes']++;
                Log::error('[ENVOI] relève des témoins impossible pour un témoin.', ['exception' => $e::class, 'fournisseur' => $acces->fournisseur]);
            }
        }

        return $bilan;
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
