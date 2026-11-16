<?php

namespace App\Envoi\Desinscription;

use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C3-3 — retrait réel des envois en file (R2). Appelé DANS la transaction de l'opposition : AUCUN réseau ici.
 * Il écrit une demande dans la boîte `envoi_retraits_demandes` (empreinte seulement, jamais d'adresse) ; la commande
 * `envoi:retraits:envoyer`, hors transaction, l'envoie au serveur. Il ne supprime ni ne modifie aucune ligne de personne.
 */
final class RetraitEnvoisEnFileServeur implements RetraitEnvoisEnFile
{
    public function __construct(private readonly EnvoiRetraitsDemandes $demandes) {}

    public function retirerPour(string $workspaceId, string $empreinteAdresse): int
    {
        // Garde de déploiement : le code est remplacé AVANT `migrate`. Table absente : rien n'est écrit (une requête en échec
        // avorterait la transaction de l'opposition) ; l'opposition reste « à terminer » côté effets, jamais perdue.
        if (! Schema::hasTable('envoi_retraits_demandes') || ! Schema::hasTable('envoi_injections_locales')) {
            return 0;
        }

        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $empreinteAdresse): int {
            $existe = DB::table('envoi_retraits_demandes')->where('workspace_id', $workspaceId)
                ->where('empreinte_adresse', $empreinteAdresse)->where('statut', 'en_attente')->exists();
            if (! $existe) {
                DB::table('envoi_retraits_demandes')->insert(['workspace_id' => $workspaceId, 'empreinte_adresse' => $empreinteAdresse]);
            }

            return count($this->demandes->cibles($workspaceId, $empreinteAdresse));
        });
    }
}
