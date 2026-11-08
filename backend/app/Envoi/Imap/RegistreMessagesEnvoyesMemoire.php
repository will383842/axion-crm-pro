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

    /** @var array<string, MessageOrigine> */
    private array $parInjection = [];

    public function noterInjection(string $idInjection, MessageOrigine $origine): void
    {
        $this->parInjection[$idInjection] = $origine;
    }

    public function retrouverParInjection(string $workspaceId, string $idInjection): ?MessageOrigine
    {
        return $this->parInjection[$idInjection] ?? $this->parInjectionDepuisMessages($idInjection);
    }

    private function parInjectionDepuisMessages(string $idInjection): ?MessageOrigine
    {
        foreach ($this->parMessageId as $origine) {
            if ($origine->idInjection === $idInjection) {
                return $origine;
            }
        }

        return null;
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
