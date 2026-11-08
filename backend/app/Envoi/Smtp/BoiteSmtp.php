<?php

namespace App\Envoi\Smtp;

use Illuminate\Support\Facades\Crypt;
use LogicException;

/**
 * Boîte d'envoi déchiffrée en mémoire. Le secret n'apparaît jamais dans un dump,
 * une sérialisation, un cast (array), un var_export ni une trace d'exception :
 *  - il n'est conservé QUE chiffré (Crypt, clé d'application) dans l'objet ;
 *  - #[\SensitiveParameter] le masque dans les traces ;
 *  - sérialiser l'objet lève une exception.
 */
final class BoiteSmtp
{
    private readonly string $secretChiffre;

    public function __construct(
        public readonly string $id,
        public readonly string $workspaceId,
        public readonly string $adresse,
        public readonly string $hote,
        public readonly int $port,
        public readonly string $chiffrement,
        public readonly string $utilisateur,
        #[\SensitiveParameter]
        string $secret,
        public readonly bool $enPause,
    ) {
        $this->secretChiffre = Crypt::encryptString($secret);
    }

    public function secret(): string
    {
        return Crypt::decryptString($this->secretChiffre);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'hote' => $this->hote, 'port' => $this->port, 'secret' => '***'];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new LogicException('BoiteSmtp ne se sérialise pas : elle porte un secret.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('BoiteSmtp ne se désérialise pas.');
    }

    /** @return list<string> */
    public function __sleep(): array
    {
        throw new LogicException('BoiteSmtp ne se sérialise pas : elle porte un secret.');
    }
}
