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
 * `adresses_partagees` (empreintes SALÉES, jamais les adresses), calculée par
 * `crm:doublons:detecter`.
 */
final class AdressesPartagees
{
    /**
     * Inscrit (ou met à jour) les adresses partagées trouvées par la détection.
     * L'empreinte SALÉE est calculée DANS la base (`doublons_inscrire_adresses`) :
     * le rôle applicatif n'exécute pas la fonction d'empreinte (#255). À
     * appeler dans le contexte de l'espace.
     *
     * @param  list<array{email: string, domaine: ?string, nb: int, nature: string}>  $lignes
     */
    public static function inscrire(string $workspaceId, array $lignes): int
    {
        $n = 0;
        foreach (array_chunk($lignes, 2000) as $morceau) {
            $r = DB::selectOne('SELECT public.doublons_inscrire_adresses(?::uuid, ?::jsonb) AS n', [$workspaceId, json_encode($morceau, JSON_THROW_ON_ERROR)]);
            $n += (int) ($r->n ?? 0);
        }

        return $n;
    }

    /**
     * Combien de lignes de la table n'ont PAS été revues par un parcours qui a
     * vu ces adresses (le ménage, annoncé à blanc).
     *
     * @param  list<string>  $emails
     */
    public static function nonRevues(string $workspaceId, array $emails): int
    {
        $r = DB::selectOne('SELECT public.doublons_adresses_non_revues(?::uuid, ?::jsonb) AS n', [$workspaceId, json_encode(array_values($emails), JSON_THROW_ON_ERROR)]);

        return (int) ($r->n ?? 0);
    }

    /**
     * Parmi CES adresses (celles d'une campagne), celles à écarter : cabinet
     * comptable ou domiciliation (réglable) portées par au moins N fiches. Une
     * question oui/non bornée à l'espace du contexte.
     *
     * @param  list<string>  $emails
     * @return array<string, true> adresse telle que donnée => écartée
     */
    public static function exclues(string $workspaceId, array $emails): array
    {
        $natures = config('crm.doublons.campagne.natures_exclues', []);
        $natures = is_array($natures) ? array_values(array_filter($natures, 'is_string')) : [];
        $seuil = max(2, (int) config('crm.doublons.campagne.seuil_fiches', 3));
        if ($natures === [] || $emails === []) {
            return [];
        }

        $exclues = [];
        foreach (array_chunk(array_values(array_unique($emails)), 5000) as $morceau) {
            foreach (DB::select(
                'SELECT e FROM public.doublons_adresses_exclues(?::uuid, ?::jsonb, ARRAY(SELECT jsonb_array_elements_text(?::jsonb)), ?::int) AS e',
                [$workspaceId, json_encode($morceau, JSON_THROW_ON_ERROR), json_encode($natures, JSON_THROW_ON_ERROR), $seuil],
            ) as $l) {
                $exclues[(string) $l->e] = true;
            }
        }

        return $exclues;
    }
}
