<?php

namespace App\Envoi\FeuVert;

/** Un point du feu vert : fait / pas fait + détail + source. Le détail ne contient JAMAIS de valeur secrète. */
final readonly class PointFeuVert
{
    /** Point manuel : jamais calculé, seulement prouvé. */
    public bool $manuel;

    /** Tant que le point n'est pas fait, il bloque le feu vert. */
    public bool $bloquant;

    public function __construct(
        public string $code,
        public string $libelle,
        public bool $automatique,
        public bool $fait,
        public string $detail,
        public string $source,
        public ?string $preuve = null,
    ) {
        $this->manuel = ! $automatique;
        $this->bloquant = ! $fait;
    }
}
