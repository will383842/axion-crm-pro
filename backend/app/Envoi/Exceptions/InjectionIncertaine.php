<?php

namespace App\Envoi\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Échec de l'appel au serveur APRÈS la réservation, sans certitude que rien ne soit parti (coupure, réponse inconnue).
 * La passerelle a gardé la place (`a_verifier`) : l'appelant ne renvoie JAMAIS et attend une vérification humaine.
 * Toute AUTRE exception de la passerelle (avant la réservation, erreur SQL passagère) n'a rien envoyé.
 */
final class InjectionIncertaine extends RuntimeException
{
    public function __construct(Throwable $cause)
    {
        parent::__construct('Issue incertaine de l\'injection (' . $cause::class . ') : aucun renvoi automatique.', 0, $cause);
    }
}
