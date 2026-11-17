<?php

namespace App\Envoi\Reponses;

use App\Events\NotificationCreated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RR-5B — avis « réponse intéressée » : une ligne `notifications` (cloche seulement, aucun courriel) pour chaque
 * propriétaire / administrateur de l'espace, sauf l'auteur du geste. Une seule fois par réponse.
 * Ni nom, ni adresse, ni extrait. Une panne est journalisée (classe seule) et ne défait jamais le geste.
 * À appeler sous le `WorkspaceContext` de l'espace ; l'écriture a lieu APRÈS validation de la transaction du geste.
 */
final class AvisReponseInteressee
{
    public const TYPE = 'envoi.reponse_interessee';

    private const TITRE = 'Réponse intéressée';

    private const CORPS = 'Une réponse a été classée « intéressée » ; une tâche de rappel est prête.';

    public static function apres(string $ws, int $reponseId, ?string $auteurId): void
    {
        DB::afterCommit(static function () use ($ws, $reponseId, $auteurId): void {
            self::envoyer($ws, $reponseId, $auteurId);
        });
    }

    private static function envoyer(string $ws, int $reponseId, ?string $auteurId): void
    {
        try {
            $lien = '/envoi/reponses/' . $reponseId;
            $dejaFait = DB::table('notifications')->where('workspace_id', $ws)->where('type', self::TYPE)->where('action_url', $lien)->exists();
            if ($dejaFait) {
                return;
            }
            $destinataires = DB::table('user_workspaces as uw')
                ->join('users as u', 'u.id', '=', 'uw.user_id')
                ->where('uw.workspace_id', $ws)
                ->whereIn('uw.role_slug', ['owner', 'admin'])
                ->whereNotNull('uw.joined_at')->whereNull('uw.revoked_at')
                ->whereNull('u.deleted_at')
                ->when($auteurId !== null, fn ($q) => $q->where('uw.user_id', '<>', $auteurId))
                ->orderBy('uw.user_id')->pluck('uw.user_id')->map(fn ($v): string => (string) $v)->all();

            foreach ($destinataires as $userId) {
                $id = DB::table('notifications')->insertGetId([
                    'workspace_id' => $ws, 'user_id' => $userId, 'type' => self::TYPE, 'title' => self::TITRE, 'body' => self::CORPS,
                    'action_url' => $lien, 'produit' => 'envoi', 'gravite' => 'info', 'created_at' => now(),
                ]);
                event(new NotificationCreated($ws, $id, self::TYPE, self::TITRE, self::CORPS, 'info', $lien, $userId));
            }
        } catch (\Throwable $e) {
            Log::error('envoi.reponse_interessee: avis en échec', ['workspace_id' => $ws, 'exception' => $e::class]);
        }
    }
}
