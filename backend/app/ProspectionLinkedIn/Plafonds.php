<?php

namespace App\ProspectionLinkedIn;

use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * PONT-LINKEDIN LI-B2 — invitations restantes aujourd'hui et cette semaine (ISO, lundi à dimanche) pour un expéditeur.
 * Dernière ligne de `pl_plafonds` (ajout seul) ou défauts de `config/prospection_linkedin.php` ; les invitations déjà faites
 * sont comptées dans `pl_journal` (geste `invitation_faite`, hors « sans effet »). Samedi et dimanche ⇒ 0 si
 * `jours_ouvres_seulement`. LECTURE SEULE ; une erreur de lecture remonte (l'appelant refuse).
 */
final class Plafonds
{
    /** @return array{jour: int, semaine: int} */
    public function restants(string $workspaceId, string $expediteurId, ?CarbonImmutable $maintenant = null): array
    {
        $t = $maintenant ?? CarbonImmutable::now((string) config('app.timezone'));
        $defauts = (array) config('prospection_linkedin.plafonds', []);

        $ligne = WorkspaceContext::run($workspaceId, static fn () => DB::table('pl_plafonds')
            ->where('workspace_id', $workspaceId)->where('expediteur_user_id', $expediteurId)->orderByDesc('id')->first());
        $jourMax = $ligne !== null ? (int) $ligne->invitations_jour : (int) ($defauts['invitations_jour'] ?? 0);
        $semaineMax = $ligne !== null ? (int) $ligne->invitations_semaine : (int) ($defauts['invitations_semaine'] ?? 0);
        $ouvres = $ligne !== null ? (bool) $ligne->jours_ouvres_seulement : (bool) ($defauts['jours_ouvres_seulement'] ?? true);

        if ($ouvres && $t->isWeekend()) {
            return ['jour' => 0, 'semaine' => 0];
        }

        $faitesJour = $this->faites($workspaceId, $expediteurId, $t->startOfDay());
        $faitesSemaine = $this->faites($workspaceId, $expediteurId, $t->startOfWeek(CarbonImmutable::MONDAY));

        return ['jour' => max(0, $jourMax - $faitesJour), 'semaine' => max(0, $semaineMax - $faitesSemaine)];
    }

    private function faites(string $workspaceId, string $expediteurId, CarbonImmutable $depuis): int
    {
        return (int) WorkspaceContext::run($workspaceId, static fn () => DB::table('pl_journal as j')
            ->join('pl_prospects as p', static fn ($jn) => $jn->on('p.workspace_id', '=', 'j.workspace_id')->on('p.id', '=', 'j.prospect_id'))
            ->where('j.workspace_id', $workspaceId)->where('p.expediteur_user_id', $expediteurId)
            ->where('j.evenement', 'invitation_faite')->where('j.sans_effet', false)
            ->where('j.recu_le', '>=', $depuis->toIso8601String())->count());
    }
}
