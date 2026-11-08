<?php

namespace App\Envoi\Serveur;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * Destination (« sink ») de la réponse HTTP avec plafond de taille EN FLUX : dès que le plafond serait franchi,
 * `write()` rend 0 octet accepté, ce qui fait interrompre le transfert par cURL — même sans en-tête
 * Content-Length (réponse « chunked »). Rien au-delà du plafond n'est conservé.
 */
final class FluxReponsePlafonne implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    private int $recu = 0;

    private bool $depasse = false;

    public function __construct(private readonly int $plafond)
    {
        $this->stream = Utils::streamFor(fopen('php://temp', 'r+b'));
    }

    public function write($string): int
    {
        $n = strlen((string) $string);
        if ($this->recu + $n > $this->plafond) {
            $this->depasse = true;

            return 0;
        }
        $this->recu += $n;

        return $this->stream->write((string) $string);
    }

    public function depasse(): bool
    {
        return $this->depasse;
    }
}
