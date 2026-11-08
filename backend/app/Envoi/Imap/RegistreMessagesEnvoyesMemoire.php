<?php

namespace App\Envoi\Imap;

/**
 * Registre de test. Comme la base, il filtre par espace : une entrée notée AVEC un espace n'est retrouvée que
 * pour cet espace ; sans espace (anciens tests), elle vaut pour tous.
 */
final class RegistreMessagesEnvoyesMemoire implements RegistreMessagesEnvoyes
{
    /** @var array<string, MessageOrigine> */
    private array $parMessageId = [];

    /** @var array<string, string> espace de chaque entrée (clé « m:<id> » ou « i:<id> ») */
    private array $espaces = [];

    public function noter(string $messageId, MessageOrigine $origine, ?string $workspaceId = null): void
    {
        $this->parMessageId[$messageId] = $origine;
        $this->espaceDe('m:' . $messageId, $workspaceId);
    }

    private function espaceDe(string $cle, ?string $workspaceId): void
    {
        if ($workspaceId !== null) {
            $this->espaces[$cle] = $workspaceId;
        }
    }

    private function visible(string $cle, string $workspaceId): bool
    {
        return ! isset($this->espaces[$cle]) || $this->espaces[$cle] === $workspaceId;
    }

    /** @var array<string, MessageOrigine> */
    private array $parInjection = [];

    public function noterInjection(string $idInjection, MessageOrigine $origine, ?string $workspaceId = null): void
    {
        $this->parInjection[$idInjection] = $origine;
        $this->espaceDe('i:' . $idInjection, $workspaceId);
    }

    public function retrouverParInjection(string $workspaceId, string $idInjection): ?MessageOrigine
    {
        if (isset($this->parInjection[$idInjection]) && $this->visible('i:' . $idInjection, $workspaceId)) {
            return $this->parInjection[$idInjection];
        }

        return $this->parInjectionDepuisMessages($workspaceId, $idInjection);
    }

    private function parInjectionDepuisMessages(string $workspaceId, string $idInjection): ?MessageOrigine
    {
        foreach ($this->parMessageId as $id => $origine) {
            if ($origine->idInjection === $idInjection && $this->visible('m:' . $id, $workspaceId)) {
                return $origine;
            }
        }

        return null;
    }

    public function retrouver(string $workspaceId, array $messageIds): ?MessageOrigine
    {
        foreach ($messageIds as $id) {
            if (isset($this->parMessageId[$id]) && $this->visible('m:' . $id, $workspaceId)) {
                return $this->parMessageId[$id];
            }
        }

        return null;
    }
}
