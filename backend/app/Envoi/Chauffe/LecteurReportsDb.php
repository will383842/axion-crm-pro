<?php

namespace App\Envoi\Chauffe;

use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Source réelle des reports (R06) : table `envoi_tentatives`, dernière heure glissante ]maintenant − 1 h, maintenant].
 * « Envoyés » = tentatives acceptées + reportées (les refus définitifs 5xx ne diluent pas le taux : choix prudent).
 * Un constat peut freiner, jamais libérer. Au palier 0 la table est vide : le frein n'est PAS actif avant T2.
 */
final class LecteurReportsDb implements LecteurReports
{
    public function sur1Heure(string $ws, string $fournisseur, DateTimeImmutable $maintenant): array
    {
        $l = WorkspaceContext::run($ws, fn () => DB::selectOne(
            "SELECT count(*) FILTER (WHERE issue IN ('acceptee', 'reportee')) AS envoyes,
                    count(*) FILTER (WHERE issue = 'reportee') AS reports
               FROM envoi_tentatives
              WHERE workspace_id = ? AND fournisseur = ? AND cree_le > ? AND cree_le <= ?",
            [$ws, $fournisseur, $maintenant->modify('-1 hour')->format('Y-m-d H:i:sP'), $maintenant->format('Y-m-d H:i:sP')],
        ));

        return ['envoyes' => (int) ($l->envoyes ?? 0), 'reports' => (int) ($l->reports ?? 0)];
    }
}
