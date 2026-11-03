<?php

namespace App\Crm\Ingest;

use App\Events\NotificationCreated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Contrat v3 de l'origine (ADR I3) — alerte l'équipe quand l'archive d'origines
 * d'une personne dépasse `origines.archive_seuil_alerte` (200) entrées.
 *
 * Même mécanisme que `SignalementDemandeEffacement` (déjà sur main) : une ligne
 * `notifications` par compte `owner` de l'espace + l'événement
 * `NotificationCreated` pour la cloche. Aucune dépendance aux PR ouvertes.
 *
 * Le texte ne porte JAMAIS l'adresse ni le nom : seulement l'identifiant interne
 * de la fiche et un compte. Une panne du signal ne fait jamais échouer
 * l'écriture de l'archive : journalisée, jamais avalée. Rien n'est supprimé.
 */
final class AlerteArchiveOrigines
{
    public const TYPE = 'origines.archive_volumineuse';

    /** Alerte seulement au FRANCHISSEMENT du seuil (pas à chaque charge ensuite). */
    public static function siFranchi(string $workspaceId, int $personneId, int $avant, int $apres): void
    {
        $seuil = max(1, (int) config('origines.archive_seuil_alerte', 200));
        if ($avant <= $seuil && $apres > $seuil) {
            self::signaler($workspaceId, $personneId, $apres, $seuil);
        }
    }

    private static function signaler(string $workspaceId, int $personneId, int $total, int $seuil): void
    {
        try {
            $proprietaires = DB::table('model_has_roles as mhr')
                ->join('roles as r', 'r.id', '=', 'mhr.role_id')
                ->where('r.name', 'owner')
                ->where('mhr.model_type', 'App\\Models\\User')
                ->where(function ($q) use ($workspaceId): void {
                    $q->where('mhr.team_id', $workspaceId)->orWhereNull('mhr.team_id');
                })
                ->distinct()
                ->pluck('mhr.model_id')
                ->all();

            $titre = 'Archive d’origines volumineuse';
            $corps = 'La fiche n° ' . $personneId . ' a plus de ' . $seuil . ' origines archivées (' . $total
                . '). Rien n’est supprimé ; à examiner (compte automatisé ou post très repris ?).';

            foreach ($proprietaires as $userId) {
                $id = DB::table('notifications')->insertGetId([
                    'workspace_id' => $workspaceId,
                    'user_id' => (string) $userId,
                    'type' => self::TYPE,
                    'title' => $titre,
                    'body' => $corps,
                    'action_url' => '/personnes/' . $personneId,
                    'created_at' => now(),
                ]);

                event(new NotificationCreated($workspaceId, $id, self::TYPE, $titre, $corps, 'warning', '/personnes/' . $personneId, (string) $userId));
            }

            if ($proprietaires === []) {
                Log::warning('origines.archive_volumineuse: aucun propriétaire à prévenir', ['workspace_id' => $workspaceId, 'personne_id' => $personneId]);
            }
        } catch (\Throwable $e) {
            Log::error('origines.archive_volumineuse: alerte en échec', ['workspace_id' => $workspaceId, 'exception' => $e::class]);
            report($e);
        }
    }
}
