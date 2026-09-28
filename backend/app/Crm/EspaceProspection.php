<?php

namespace App\Crm;

use Illuminate\Support\Facades\DB;

/**
 * L'espace où vivent les fiches collectées à l'INSEE — UNE définition.
 *
 * `prospection:collect` écrivait « dans le premier espace créé » ; le
 * reclassement de masse, lui, visait `crm.ingest.business_workspace`. Si les
 * deux ne désignaient pas le même espace, le reclassement aurait tourné à vide
 * sur un espace sans fiche INSEE, en annonçant « 0 à modifier ». Les deux
 * commandes lisent désormais cette classe (règle historique de la collecte,
 * inchangée), et le reclassement refuse de partir sur un espace vide quand un
 * autre porte les fiches INSEE.
 */
final class EspaceProspection
{
    /**
     * L'espace par défaut : le premier créé (règle de `prospection:collect`),
     * hors corbeille — comme `resoudre()` : un espace supprimé ne reçoit ni
     * collecte ni reclassement.
     */
    public static function parDefaut(): ?string
    {
        $id = DB::table('workspaces')->whereNull('deleted_at')->orderBy('created_at')->value('id');

        return $id === null ? null : (string) $id;
    }

    /**
     * Un espace désigné par son identifiant OU son slug ; l'espace par défaut
     * si rien n'est donné. Null s'il n'existe pas.
     */
    public static function resoudre(?string $designation): ?string
    {
        $designation = trim((string) $designation);
        if ($designation === '') {
            return self::parDefaut();
        }

        $requete = DB::table('workspaces')->whereNull('deleted_at');
        $requete = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $designation) === 1
            ? $requete->where('id', $designation)
            : $requete->where('slug', $designation);
        $id = $requete->value('id');

        return $id === null ? null : (string) $id;
    }
}
