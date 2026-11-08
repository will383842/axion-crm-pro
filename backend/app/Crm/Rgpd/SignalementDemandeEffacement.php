<?php

namespace App\Crm\Rgpd;

use App\Events\NotificationCreated;
use App\Http\Controllers\Api\Crm\ATraiterController;
use App\Socle\Alertes\Alerte;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * L3c-0 (ADR contrat v3 § 6, B2) — SIGNALER à Will une demande d'effacement.
 *
 * Une demande d'effacement (venue du site ou de la console) ne supprime rien :
 * elle met la personne à l'écart et PRÉVIENT le propriétaire, qui décide. Le
 * signal est double :
 *  - une notification in-app (`notifications`) pour chaque compte `owner` de
 *    l'espace, diffusée par l'événement `NotificationCreated` ;
 *  - la pastille de l'entrée « Demandes RGPD » du menu (compteur
 *    `demandes_rgpd` de `ATraiterController`, demandes `pending`).
 *
 * Le texte ne porte JAMAIS l'adresse de la personne. Une panne du signal ne
 * fait jamais échouer la mise à l'écart déjà actée : journalisée, jamais avalée.
 * À appeler DANS le contexte d'espace de la demande.
 */
final class SignalementDemandeEffacement
{
    public const TYPE = 'rgpd.demande_effacement';

    public const LIEN = '/rgpd/requests?statut=ouverte';

    public static function signaler(string $workspaceId, string $origine): void
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

            $titre = 'Demande d’effacement RGPD à traiter';
            $corps = 'Une personne a demandé l’effacement de ses données (origine : ' . $origine
                . '). Elle est mise à l’écart, rien n’est supprimé : ouvrez « Demandes RGPD » pour décider.';

            foreach ($proprietaires as $userId) {
                $id = DB::table('notifications')->insertGetId([
                    'workspace_id' => $workspaceId,
                    'user_id' => (string) $userId,
                    'type' => self::TYPE,
                    'title' => $titre,
                    'body' => $corps,
                    'action_url' => self::LIEN,
                    'created_at' => now(),
                ]);

                event(new NotificationCreated(
                    $workspaceId,
                    $id,
                    self::TYPE,
                    $titre,
                    $corps,
                    'warning',
                    self::LIEN,
                    (string) $userId,
                ));
            }

            if ($proprietaires === []) {
                Log::warning('rgpd.demande_effacement: aucun propriétaire à prévenir', ['workspace_id' => $workspaceId]);
            }

            ATraiterController::oublier($workspaceId);

            self::alerter($workspaceId, $origine);
        } catch (\Throwable $e) {
            Log::error('rgpd.demande_effacement: signalement en échec', [
                'workspace_id' => $workspaceId,
                'exception' => $e::class,
            ]);
            report($e);
        }
    }

    /**
     * Alerte #411 (cloche + e-mail, éteinte par défaut). Corps SANS donnée de
     * personne : l'origine et le nombre de demandes ouvertes. Une panne ici ne
     * remet jamais en cause la mise à l'écart ni le filet ci-dessus.
     */
    private static function alerter(string $workspaceId, string $origine): void
    {
        try {
            $ouvertes = DB::table('rgpd_requests')
                ->where('workspace_id', $workspaceId)->where('type', 'erasure')
                ->whereIn('status', ['pending', 'processing'])->count();

            app(Alerte::class)->envoyer(
                'Demande d’effacement à traiter',
                'Une demande d’effacement est arrivée (origine : ' . $origine . '). Demandes ouvertes : ' . $ouvertes
                . '. La personne est mise à l’écart, rien n’est supprimé.',
                'crm',
                'attention',
                self::LIEN,
                ['workspace_id' => $workspaceId],
            );
        } catch (\Throwable $e) {
            Log::error('rgpd.demande_effacement: alerte en échec', ['exception' => $e::class]);
        }
    }
}
