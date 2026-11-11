<?php

namespace App\Envoi\FeuVert;

/** Un point du feu vert : fait / pas fait + détail + source. Le détail ne contient JAMAIS de valeur secrète. */
final readonly class PointFeuVert
{
    public const ETAT_FAIT = 'fait';

    public const ETAT_A_FAIRE = 'a_faire';

    public const ETAT_ATTEND_SERVEUR = 'attend_serveur_reel';

    /** Point manuel : jamais calculé, seulement prouvé. */
    public bool $manuel;

    /** Tant que le point n'est pas fait, il bloque le feu vert. */
    public bool $bloquant;

    /** auto | manuel (aussi lisible dans le préfixe du code). */
    public string $nature;

    /**
     * @param  string  $etat  fait | a_faire | attend_serveur_reel (point manuel qui a besoin du vrai serveur tant que K17 n'est pas fait, preuve déposée ou non ; alors `fait` vaut false et `bloquant` true). Règle : `fait` === (`etat` === « fait »)
     */
    public function __construct(
        public string $code,
        public string $libelle,
        public bool $automatique,
        public bool $fait,
        public string $detail,
        public string $source,
        public string $groupe,
        public string $etat,
        public ?string $preuve = null,
        public ?string $preuvePar = null,
        public ?string $preuveLe = null,
    ) {
        $this->manuel = ! $automatique;
        $this->bloquant = ! $fait;
        $this->nature = $automatique ? 'auto' : 'manuel';
    }
}
