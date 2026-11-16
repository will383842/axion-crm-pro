<?php

namespace App\Envoi\Message;

/** Lecture MINIMALE des valeurs de variables d'un message (cahier F2a §2.4). Lecture seule. */
interface LecteurVariables
{
    /**
     * @param  list<int>  $contactIds
     * @param  list<string>  $noms  sous-ensemble de VariablesMessage::NOMS
     * @return array<int, array<string, ?string>> une entrée par contact trouvé, les seuls noms demandés
     */
    public function pourContacts(string $ws, array $contactIds, array $noms): array;
}
