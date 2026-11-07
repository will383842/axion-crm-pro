<?php

namespace App\Envoi\Sequence;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Fenêtre d'envoi en heure LOCALE d'Europe/Paris, [debut, fin[ (la fin est
 * exclue), les jours ouvrés français seulement. Les calculs passent par
 * l'heure locale : le jour du changement d'heure, 09h00 reste 09h00 (UTC+1 ou
 * UTC+2), jamais décalé d'une heure.
 */
final class FenetreHoraire
{
    public function __construct(public readonly int $debut = 9, public readonly int $fin = 17)
    {
        if ($debut < 0 || $fin > 24 || $debut >= $fin) {
            throw new InvalidArgumentException("Fenêtre horaire invalide : {$debut}h–{$fin}h.");
        }
    }

    public function contient(DateTimeImmutable $instant): bool
    {
        $local = $instant->setTimezone(new DateTimeZone(JoursOuvres::FUSEAU));
        $h = (int) $local->format('G');

        return JoursOuvres::estOuvre($local) && $h >= $this->debut && $h < $this->fin;
    }

    /** Le premier instant >= $instant qui est dans la fenêtre. */
    public function prochain(DateTimeImmutable $instant): DateTimeImmutable
    {
        $paris = new DateTimeZone(JoursOuvres::FUSEAU);
        $local = $instant->setTimezone($paris);
        if ($this->contient($local)) {
            return $local;
        }
        $h = (int) $local->format('G');
        if (JoursOuvres::estOuvre($local) && $h < $this->debut) {
            return $local->setTime($this->debut, 0);
        }
        // Après la fermeture, ou jour non ouvré : ouverture du prochain jour ouvré.
        $suivant = JoursOuvres::auPlusTot($local->setTime(0, 0)->modify('+1 day'));

        return $suivant->setTime($this->debut, 0);
    }

    /** Échéance d'une étape : $delai jours ouvrés après $base, puis calée dans la fenêtre. */
    public function echeance(DateTimeImmutable $base, int $delai): DateTimeImmutable
    {
        return $this->prochain(JoursOuvres::ajouter($base, $delai));
    }
}
