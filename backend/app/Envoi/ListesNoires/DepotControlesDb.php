<?php

namespace App\Envoi\ListesNoires;

use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class DepotControlesDb implements DepotControles
{
    /** Un « non listé » inchangé n'est réécrit qu'une fois par heure. */
    private const RAFRAICHISSEMENT_S = 3600;

    public function enregistrer(Ressource $r, StatutListe $statut, string $zone, ?string $reponse, bool $pause, DateTimeImmutable $controleLe, ?DateTimeImmutable $pauseLe = null): void
    {
        WorkspaceContext::run($r->workspaceId, function () use ($r, $statut, $zone, $reponse, $pause, $controleLe, $pauseLe): void {
            $dernier = $this->dernier($r);
            if ($dernier !== null && ! $pause && $dernier->statut === $statut->value && $dernier->zone === $zone
                && $controleLe->getTimestamp() - strtotime((string) $dernier->controle_le) < self::RAFRAICHISSEMENT_S) {
                return;
            }
            // La pause reste active tant qu'un humain ne l'a pas levée (lot ultérieur) : on la reporte.
            $pauseActive = $pause || ($dernier !== null && (bool) $dernier->pause);
            DB::table('envoi_controles_listes')->insert([
                'workspace_id' => $r->workspaceId, 'type' => $r->type, 'ressource' => $r->valeur,
                'statut' => $statut->value, 'zone' => $zone, 'reponse' => $reponse, 'pause' => $pauseActive,
                'pause_le' => $pauseLe?->format('c') ?? ($pauseActive ? $dernier?->pause_le : null),
                'controle_le' => $controleLe->format('c'),
            ]);
        });
    }

    public function enPause(Ressource $r): bool
    {
        return WorkspaceContext::run($r->workspaceId, fn (): bool => (bool) ($this->dernier($r)->pause ?? false));
    }

    /** @return object{statut: string, zone: string, controle_le: string, pause: bool, pause_le: ?string}|null */
    private function dernier(Ressource $r): ?object
    {
        /** @var object{statut: string, zone: string, controle_le: string, pause: bool, pause_le: ?string}|null $ligne */
        $ligne = DB::table('envoi_controles_listes')->where('workspace_id', $r->workspaceId)
            ->where('type', $r->type)->where('ressource', $r->valeur)->orderByDesc('id')->first();

        return $ligne;
    }
}
