<?php

namespace App\Envoi\Smtp;

/** Boîte d'envoi déchiffrée en mémoire. Le secret n'apparaît jamais dans un dump ni un journal. */
final class BoiteSmtp
{
    public function __construct(
        public readonly string $id,
        public readonly string $workspaceId,
        public readonly string $adresse,
        public readonly string $hote,
        public readonly int $port,
        public readonly string $chiffrement,
        public readonly string $utilisateur,
        private readonly string $secret,
        public readonly bool $enPause,
    ) {}

    public function secret(): string
    {
        return $this->secret;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'hote' => $this->hote, 'port' => $this->port, 'secret' => '***'];
    }
}
