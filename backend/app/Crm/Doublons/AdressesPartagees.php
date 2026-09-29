<?php

namespace App\Crm\Doublons;

use Illuminate\Support\Facades\DB;

/**
 * LES ADRESSES PARTAGÉES, CÔTÉ CAMPAGNE (chantier 5).
 *
 * ── Ce que le moteur de campagnes doit garantir (REQ-CAM-008) ───────────────
 *
 * Une même adresse ne reçoit JAMAIS deux fois le même message, même portée
 * par dix fiches (un cabinet comptable, une domiciliation, un siège) ou par
 * une fiche et une personne : `crm:campagne:destinataires` regroupe TOUTES
 * les occurrences d'une adresse (clé : l'adresse en minuscules, sans espaces)
 * avant d'en faire UNE ligne, qui cite toutes ses organisations. Le futur
 * moteur de campagnes du plan d'envoi doit garder ce dédoublonnage PAR
 * ADRESSE, à la construction de chaque vague et entre les règles d'une même
 * campagne — jamais par fiche ni par personne.
 *
 * ── Ce que cette classe ajoute ──────────────────────────────────────────────
 *
 * Les adresses de cabinet comptable et de domiciliation portées par au moins
 * N fiches (réglable : `crm.doublons.campagne`) sont écartées par défaut : le
 * message n'atteindrait pas le dirigeant. La liste vient de
 * `adresses_partagees` (empreintes, jamais les adresses), calculée par
 * `crm:doublons:detecter`.
 */
final class AdressesPartagees
{
    /**
     * Les empreintes (`ListeSuppression::empreinte`) des adresses à écarter
     * des campagnes. À appeler dans le contexte de l'espace.
     *
     * @return array<string, true>
     */
    public static function aExclure(string $workspaceId): array
    {
        $natures = config('crm.doublons.campagne.natures_exclues', []);
        $natures = is_array($natures) ? array_values(array_filter($natures, 'is_string')) : [];
        $seuil = max(2, (int) config('crm.doublons.campagne.seuil_fiches', 3));
        if ($natures === []) {
            return [];
        }

        $exclues = [];
        foreach (DB::table('adresses_partagees')
            ->where('workspace_id', $workspaceId)
            ->whereIn('nature', $natures)
            ->where('nb_fiches', '>=', $seuil)
            ->pluck('email_empreinte') as $empreinte) {
            $exclues[(string) $empreinte] = true;
        }

        return $exclues;
    }
}
