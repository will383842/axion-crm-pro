<?php

namespace App\Envoi\Serveur;

use App\Envoi\Serveur\Contrat\EvenementSuivi;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/**
 * Écrit un lot d'événements en UNE transaction ; l'appelant ne répond 2xx qu'après son retour
 * (donc après COMMIT). Doublons : ON CONFLICT (source, event_id) DO NOTHING. Désordre toléré :
 * l'état est monotone (le rang ne fait que monter) et le curseur n'avance que sur des rangs contigus.
 */
final class RecepteurEvenements
{
    /** Rang de gravité : un « livré » tardif ne rétablit pas un « rebond_dur ». */
    public const RANGS = ['envoye' => 1, 'livre' => 2, 'rebond_mou' => 2, 'refuse' => 3, 'rebond_dur' => 3, 'plainte' => 4];

    /**
     * @param  list<EvenementSuivi>  $evenements
     * @return array{recus: int, doublons: int, trou: bool}
     */
    public function recevoir(string $workspaceId, string $source, array $evenements): array
    {
        return WorkspaceContext::run($workspaceId, fn (): array => DB::transaction(function () use ($workspaceId, $source, $evenements): array {
            $recus = 0;
            foreach ($evenements as $e) {
                $rang = self::RANGS[$e->type] ?? null; // tout autre type (ouverture comprise) est écarté
                $n = DB::affectingStatement(
                    'INSERT INTO envoi_evenements (workspace_id, source, event_id, seq, type, id_injection) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT (source, event_id) DO NOTHING',
                    [$workspaceId, $source, $e->id, $e->seq, $rang === null ? 'ignore' : $e->type, $e->idInjection],
                );
                $recus += $n;
                if ($n === 1 && $rang !== null) {
                    DB::affectingStatement(
                        'INSERT INTO envoi_etats_message (workspace_id, source, id_injection, etat, rang) VALUES (?, ?, ?, ?, ?) ON CONFLICT (workspace_id, source, id_injection) DO UPDATE SET etat = EXCLUDED.etat, rang = EXCLUDED.rang, mis_a_jour_le = now() WHERE envoi_etats_message.rang < EXCLUDED.rang',
                        [$workspaceId, $source, $e->idInjection, $e->type, $rang],
                    );
                }
            }

            return ['recus' => $recus, 'doublons' => count($evenements) - $recus, 'trou' => $this->avancerCurseur($workspaceId, $source)];
        }));
    }

    /** Avance sur les rangs contigus ; vrai s'il reste un trou. */
    private function avancerCurseur(string $workspaceId, string $source): bool
    {
        DB::statement('INSERT INTO envoi_curseurs_serveur (workspace_id, source) VALUES (?, ?) ON CONFLICT (workspace_id, source) DO NOTHING', [$workspaceId, $source]);
        $dernier = (int) DB::table('envoi_curseurs_serveur')->where('workspace_id', $workspaceId)->where('source', $source)->lockForUpdate()->value('dernier_seq');
        $trou = false;
        foreach (DB::table('envoi_evenements')->where('workspace_id', $workspaceId)->where('source', $source)->where('seq', '>', $dernier)->orderBy('seq')->pluck('seq') as $seq) {
            if ((int) $seq === $dernier + 1) {
                $dernier++;
            } elseif ((int) $seq > $dernier + 1) {
                $trou = true;
                break;
            }
        }
        DB::table('envoi_curseurs_serveur')->where('workspace_id', $workspaceId)->where('source', $source)->update(['dernier_seq' => $dernier, 'mis_a_jour_le' => now()]);

        return $trou;
    }
}
