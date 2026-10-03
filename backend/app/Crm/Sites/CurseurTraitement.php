<?php

namespace App\Crm\Sites;

use Illuminate\Support\Facades\DB;

/**
 * Le curseur PERSISTANT d'un traitement par lots (table
 * `curseurs_traitements`, lot N6) : la dernière fiche traitée, par espace et
 * par traitement. À écrire dans la transaction du paquet qu'il clôt.
 */
final class CurseurTraitement
{
    /** La dernière fiche traitée, null si le traitement n'a jamais validé de paquet. */
    public static function lire(string $workspaceId, string $traitement): ?int
    {
        $id = DB::table('curseurs_traitements')
            ->where('workspace_id', $workspaceId)
            ->where('traitement', $traitement)
            ->value('dernier_id');

        return $id === null ? null : (int) $id;
    }

    public static function ecrire(string $workspaceId, string $traitement, int $dernierId): void
    {
        DB::statement(
            'INSERT INTO curseurs_traitements (workspace_id, traitement, dernier_id, mis_a_jour_le)
             VALUES (?, ?, ?, now())
             ON CONFLICT (workspace_id, traitement)
             DO UPDATE SET dernier_id = EXCLUDED.dernier_id, mis_a_jour_le = now()',
            [$workspaceId, $traitement, $dernierId],
        );
    }

    /** Repartir du début : le curseur revient à 0 (la ligne n'est pas supprimée). */
    public static function remettreAZero(string $workspaceId, string $traitement): void
    {
        self::ecrire($workspaceId, $traitement, 0);
    }
}
