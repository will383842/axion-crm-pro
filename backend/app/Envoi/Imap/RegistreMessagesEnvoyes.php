<?php

namespace App\Envoi\Imap;

/**
 * Retrouve un message ENVOYÉ par son Message-ID. Interface seule : le registre
 * des messages envoyés est tenu par le lot d'injection ; tant qu'il n'est pas
 * branché, tout retour va dans la file « à rattacher » (jamais d'effet auto).
 */
interface RegistreMessagesEnvoyes
{
    /** @param list<string> $messageIds sans chevrons, par ordre de préférence */
    public function retrouver(string $workspaceId, array $messageIds): ?MessageOrigine;

    /** Rattachement par un jeton VERP DÉJÀ vérifié (`Verp`) : l'injection → son message d'origine. */
    public function retrouverParInjection(string $workspaceId, string $idInjection): ?MessageOrigine;
}
