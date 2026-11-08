<?php

namespace App\Envoi\ListesNoires;

use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class DepotControlesDb implements DepotControles
{
    /** Un état inchangé n'est réécrit qu'une fois par heure. */
    private const RAFRAICHISSEMENT_S = 3600;

    public function enregistrer(Ressource $r, StatutListe $statut, string $zone, ?string $reponse, bool $pause, DateTimeImmutable $controleLe, ?DateTimeImmutable $pauseLe = null): bool
    {
        return WorkspaceContext::run($r->workspaceId, function () use ($r, $statut, $zone, $reponse, $pause, $controleLe, $pauseLe): bool {
            $dernier = $this->dernier($r);
            // Un « listé » dont la pause est déjà posée n'est pas réécrit chaque minute (1 440 lignes/jour).
            if ($dernier !== null && $dernier->statut === $statut->value && $dernier->zone === $zone
                && (! $pause || (bool) $dernier->pause)
                && $controleLe->getTimestamp() - strtotime((string) $dernier->controle_le) < self::RAFRAICHISSEMENT_S) {
                return false;
            }
            // La pause reste active tant qu'un humain ne l'a pas levée (lot ultérieur) : on la reporte.
            $pauseActive = $pause || ($dernier !== null && (bool) $dernier->pause);
            DB::table('envoi_controles_listes')->insert([
                'workspace_id' => $r->workspaceId, 'type' => $r->type, 'ressource' => $r->valeur,
                'statut' => $statut->value, 'zone' => $zone, 'reponse' => $reponse, 'pause' => $pauseActive,
                'pause_le' => $pauseLe?->format('c') ?? ($pauseActive ? $dernier?->pause_le : null),
                'controle_le' => $controleLe->format('c'),
            ]);

            return true;
        });
    }

    public function dernierStatut(Ressource $r): ?StatutListe
    {
        return WorkspaceContext::run($r->workspaceId, function () use ($r): ?StatutListe {
            $d = $this->dernier($r);

            return $d === null ? null : StatutListe::from($d->statut);
        });
    }

    public function enPause(Ressource $r): bool
    {
        return WorkspaceContext::run($r->workspaceId, function () use ($r): bool {
            $dernier = $this->dernier($r);
            if ($dernier === null) {
                return false;
            }
            if ((bool) $dernier->pause) {
                return true;
            }
            if ($dernier->statut !== StatutListe::NonFiable->value) {
                return false;
            }

            // Début de la série « non fiable » : premier contrôle après le dernier état non « non fiable ».
            $borne = (int) (DB::table('envoi_controles_listes')->where('workspace_id', $r->workspaceId)
                ->where('type', $r->type)->where('ressource', $r->valeur)
                ->where('statut', '<>', StatutListe::NonFiable->value)->max('id') ?? 0);
            $debut = DB::table('envoi_controles_listes')->where('workspace_id', $r->workspaceId)
                ->where('type', $r->type)->where('ressource', $r->valeur)->where('id', '>', $borne)->min('controle_le');
            $minutes = max(0, (int) config('envoi.listes_noires.non_fiable_bloque_apres_minutes', 30));

            return $debut !== null && (time() - strtotime((string) $debut)) >= $minutes * 60;
        });
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
