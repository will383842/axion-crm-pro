<?php

declare(strict_types=1);

namespace Passerelle;

final class Requete
{
    /** Méthode en MAJUSCULES : normalisée ici, une seule fois, pour le routage comme pour la signature. */
    public readonly string $methode;

    /** @param array<string,list<string>> $entetes noms en minuscules */
    public function __construct(
        string $methode,
        public readonly string $chemin, // chemin + requête, tel que reçu
        public readonly string $corps,
        public readonly array $entetes,
    ) {
        $this->methode = strtoupper($methode);
    }

    /** Valeur unique de l'en-tête ; null si absent OU en double. */
    public function entete(string $nom): ?string
    {
        $v = $this->entetes[$nom] ?? [];

        return count($v) === 1 ? $v[0] : null;
    }
}
