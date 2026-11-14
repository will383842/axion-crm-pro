<?php

namespace Tests\Support;

/**
 * Liste EXPLICITE des chemins du contrat d'envoi (backend/openapi/envoi/*.yaml) qui existent comme route mais ne sont
 * pas servis au palier 0 : ils répondent 409 (envois fermés) ou 501 (pas encore branché), jamais autre chose.
 *
 * Une entrée n'est admise que si la route existe réellement ET renvoie bien le code annoncé : le test de couverture
 * (CouvertureContratEnvoiTest) fait l'appel. Un chemin sans route n'a PAS sa place ici : c'est un trou réel, listé dans
 * docs/envoi/lots/R19.md.
 *
 * Clé = « MÉTHODE /chemin du contrat » (paramètres tels que dans le yaml). Valeur = code attendu + raison en une phrase.
 */
final class NonServiAuPalier0
{
    /** @return array<string, array{code:int, raison:string}> */
    public static function entrees(): array
    {
        return [
            // Vide au lot R19 : toute route de contrat présente sur main répond ou est servie (voir R19.md).
        ];
    }
}
