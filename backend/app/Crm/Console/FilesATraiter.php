<?php

namespace App\Crm\Console;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * LES FILES « À TRAITER » — une seule définition par file, partagée par
 * l'écran qui la montre et par le compteur du menu qui la résume.
 *
 * Pourquoi cette classe existe : la pastille du menu (« Doublons à vérifier
 * [12] ») doit dire EXACTEMENT le total de l'écran. Deux requêtes jumelles,
 * l'une dans le contrôleur de l'écran, l'autre dans celui des compteurs,
 * finissent toujours par diverger — et l'écart se découvre quand le menu
 * annonce 12 paires et que l'écran en montre 9. Ici, l'écran et le compteur
 * partent du MÊME constructeur ; l'écran y ajoute ses filtres, son tri et sa
 * page, le compteur se contente de `count()`.
 *
 * Les deux files sont cloisonnées EXPLICITEMENT par espace (`workspace_id =
 * ?`), en plus de la RLS : le filtre explicite est aussi ce qui permet à
 * Postgres d'utiliser l'index — la politique de RLS compare
 * `workspace_id::TEXT`, qu'aucun index ne sert.
 */
final class FilesATraiter
{
    /**
     * « Doublons à vérifier » — les paires d'entreprises non revues, dont les
     * deux fiches existent encore dans l'espace (ni corbeille, ni autre
     * espace).
     *
     * Index : `idx_dup_flags_pending (workspace_id, similarity DESC) WHERE
     * reviewed_at IS NULL`, puis les deux fiches par clé primaire.
     */
    public static function doublons(string $espace): Builder
    {
        return DB::table('duplicate_flags as d')
            ->join('companies as ga', 'ga.id', '=', 'd.entity_a_id')
            ->join('companies as ab', 'ab.id', '=', 'd.entity_b_id')
            ->where('d.workspace_id', $espace)
            ->where('d.entity_type', 'company')
            ->whereNull('d.reviewed_at')
            ->whereNotNull('d.motif')
            ->where('ga.workspace_id', $espace)
            ->where('ab.workspace_id', $espace)
            ->whereNull('ga.deleted_at')
            ->whereNull('ab.deleted_at');
    }

    /**
     * « Personnes à rattacher » — les événements du site arrivés sans SIREN
     * (`payload -> pending_match`), ni rattachés, ni écartés.
     *
     * Index : `idx_activities_a_rattacher` (migration
     * `2026_10_03_000040`), PARTIEL sur exactement ces trois conditions — les
     * opérateurs JSON `->` ne sont pas « leakproof » : sous la RLS forcée
     * (`axion_app`), Postgres ne peut les évaluer qu'après la politique, ligne
     * à ligne. Un index partiel dont le prédicat les contient les évalue une
     * fois pour toutes, à l'écriture.
     */
    public static function aRattacher(string $espace): Builder
    {
        return DB::table('activities')
            ->where('workspace_id', $espace)
            ->whereNull('subject_id')
            ->whereRaw("payload -> 'pending_match' IS NOT NULL")
            ->whereRaw("payload -> 'arbitrage_dismissed' IS NULL");
    }
}
