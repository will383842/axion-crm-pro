<?php

namespace App\Envoi\Imap;

use RuntimeException;

/**
 * Liaison PAR DÉFAUT de `FabriqueLecteurs` : la fabrique réelle (qui tirerait les identifiants d'une boîte du
 * gestionnaire de secrets) n'est pas livrée dans le dépôt. Tant qu'elle n'est pas posée, la relève échoue de
 * façon visible et propre. Aucune connexion réseau, aucun identifiant lu, aucun secret ici.
 */
final class FabriqueLecteursNonFournie implements FabriqueLecteurs
{
    public const MESSAGE = 'Relève IMAP impossible : aucune fabrique de lecteurs IMAP n\'est fournie (pas de boîte réelle branchée). Poser une liaison FabriqueLecteurs avant d\'allumer ENVOI_RELEVE_ACTIF.';

    public function fabrique(string $boite): LecteurBoite
    {
        throw new RuntimeException(self::MESSAGE);
    }
}
