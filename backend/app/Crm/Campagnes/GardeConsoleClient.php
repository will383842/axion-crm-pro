<?php

namespace App\Crm\Campagnes;

use App\Crm\FichesProtegees;
use App\Crm\Ingest\DossierClientIngest;
use Illuminate\Support\Facades\DB;

/**
 * LES CLIENTS DE LA CONSOLE NE SONT JAMAIS DESTINATAIRES D'UNE CAMPAGNE
 * (25/10/2026, synchro du dossier client — garde-fou (h)).
 *
 * Une adresse est écartée (`EligibiliteAdresse::FICHE_CONSOLE`) dès qu'elle
 * est portée :
 *  - par une personne venue de la console (`contacts.discovery_source =
 *    'console'`), quelle que soit la fiche où elle a été rangée depuis ;
 *  - par N'IMPORTE QUELLE personne d'une fiche marquée
 *    `src:console-client` (une personne collectée plus tard sur un client) ;
 *  - par l'adresse générique d'une fiche marquée `src:console-client`.
 *
 * Question posée à la base EN UNE FOIS pour toutes les adresses d'une
 * campagne, DANS le contexte d'espace de l'appelant (sous la RLS forcée, une
 * requête hors contexte ne verrait aucune personne et laisserait tout passer
 * — la garde doit fermer, pas ouvrir). Patron de `AdressesPartagees::exclues`.
 * Aucune ligne ici n'envoie quoi que ce soit.
 */
final class GardeConsoleClient
{
    /**
     * @param  list<string>  $adresses  adresses NORMALISÉES (minuscules, sans espaces)
     * @return array<string, true> adresse => true pour celles à écarter
     */
    public static function exclues(string $workspaceId, array $adresses): array
    {
        $adresses = array_values(array_unique(array_filter($adresses, static fn (string $a): bool => $a !== '')));
        if ($adresses === []) {
            return [];
        }

        $exclues = [];
        foreach (array_chunk($adresses, 1000) as $paquet) {
            $marques = implode(', ', array_fill(0, count($paquet), '?'));
            $lignes = DB::select(
                "SELECT lower(c.email::text) AS adresse
                   FROM contacts c
                  WHERE c.workspace_id = ?
                    AND c.email IN ({$marques})
                    AND (c.discovery_source = ?
                         OR EXISTS (SELECT 1 FROM company_tag gc_ct JOIN tags gc_t ON gc_t.id = gc_ct.tag_id
                                     WHERE gc_ct.company_id = c.company_id AND gc_t.slug = ?))
                 UNION
                 SELECT lower(btrim(co.email_generic)) AS adresse
                   FROM companies co
                  WHERE co.workspace_id = ?
                    AND co.email_generic IS NOT NULL AND co.email_generic <> ''
                    AND lower(btrim(co.email_generic)) IN ({$marques})
                    AND EXISTS (SELECT 1 FROM company_tag gc_ct JOIN tags gc_t ON gc_t.id = gc_ct.tag_id
                                 WHERE gc_ct.company_id = co.id AND gc_t.slug = ?)",
                array_merge(
                    [$workspaceId],
                    $paquet,
                    [DossierClientIngest::DISCOVERY_SOURCE, FichesProtegees::TAG_CONSOLE_CLIENT, $workspaceId],
                    $paquet,
                    [FichesProtegees::TAG_CONSOLE_CLIENT],
                ),
            );
            foreach ($lignes as $ligne) {
                $exclues[(string) $ligne->adresse] = true;
            }
        }

        return $exclues;
    }
}
