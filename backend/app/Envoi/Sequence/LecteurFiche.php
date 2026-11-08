<?php

namespace App\Envoi\Sequence;

interface LecteurFiche
{
    /** La fiche du contact dans l'espace courant, ou null si elle n'existe plus. */
    public function lire(string $workspaceId, int $contactId): ?Fiche;
}
