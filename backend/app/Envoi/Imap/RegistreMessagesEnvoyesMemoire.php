<?php

namespace App\Envoi\Imap;

/** Registre de test. */
final class RegistreMessagesEnvoyesMemoire implements RegistreMessagesEnvoyes
{
    /** @var array<string, MessageOrigine> */
    private array $parMessageId = [];

    public function noter(string $messageId, MessageOrigine $origine): void
    {
        $this->parMessageId[$messageId] = $origine;
    }

    public function retrouver(string $workspaceId, array $messageIds): ?MessageOrigine
    {
        foreach ($messageIds as $id) {
            if (isset($this->parMessageId[$id])) {
                return $this->parMessageId[$id];
            }
        }

        return null;
    }
}
