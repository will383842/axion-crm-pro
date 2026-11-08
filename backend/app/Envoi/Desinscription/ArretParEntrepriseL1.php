<?php

namespace App\Envoi\Desinscription;

use App\Envoi\Sequence\Evenement;
use App\Envoi\Sequence\Inscriptions;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Implémentation L1 de l'arrêt par entreprise après une opposition (lien de désinscription).
 *
 * Dans UN espace (jamais un autre) : toutes les inscriptions de l'adresse (quel que soit leur statut : une porte
 * qui a déjà posé `garde:opposition` compte aussi) donnent leurs entreprises ; chaque inscription ACTIVE de
 * l'adresse puis de ces entreprises passe « arretee » avec le motif « opposition ». Une trace d'événement
 * (ajout seul) est posée pour l'adresse, pour que les inscriptions FUTURES de l'entreprise soient arrêtées par
 * le séquenceur. Rien n'est effacé. Idempotent. Verrous pris dans l'ordre des identifiants.
 */
final class ArretParEntrepriseL1 implements ArretParEntreprise
{
    public function declencher(string $workspaceId, string $empreinteAdresse): void
    {
        $maintenant = DateTimeImmutable::createFromInterface(now());
        WorkspaceContext::run($workspaceId, function () use ($workspaceId, $empreinteAdresse, $maintenant): void {
            DB::transaction(function () use ($workspaceId, $empreinteAdresse, $maintenant): void {
                $deLAdresse = DB::table('envoi_inscriptions')->where('workspace_id', $workspaceId)
                    ->where('empreinte_adresse', $empreinteAdresse)->orderBy('id')->lockForUpdate()->get();
                if ($deLAdresse->isEmpty()) {
                    return;
                }
                foreach ($deLAdresse as $ins) {
                    Inscriptions::tracer($workspaceId, $ins, Evenement::OPPOSITION, $maintenant);
                }
                $this->arreter($workspaceId, DB::table('envoi_inscriptions')->where('workspace_id', $workspaceId)
                    ->where('empreinte_adresse', $empreinteAdresse)->where('statut', 'active'), $maintenant);
                $entreprises = $deLAdresse->pluck('entreprise_id')->unique()->sort()->values()->all();
                $this->arreter($workspaceId, DB::table('envoi_inscriptions')->where('workspace_id', $workspaceId)
                    ->whereIn('entreprise_id', $entreprises)->where('statut', 'active'), $maintenant);
            });
        });
    }

    private function arreter(string $workspaceId, Builder $actives, DateTimeImmutable $maintenant): void
    {
        $ids = (clone $actives)->orderBy('id')->lockForUpdate()->pluck('id')->all();
        if ($ids === []) {
            return;
        }
        DB::table('envoi_inscriptions')->where('workspace_id', $workspaceId)->whereIn('id', $ids)->where('statut', 'active')
            ->update(['statut' => 'arretee', 'motif_arret' => Evenement::OPPOSITION, 'arretee_le' => $maintenant->format('c')]);
    }
}
