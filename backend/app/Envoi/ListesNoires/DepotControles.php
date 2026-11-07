<?php

namespace App\Envoi\ListesNoires;

use DateTimeImmutable;

/** Ajout seulement : un contrôle = une ligne, jamais modifiée ni effacée. */
interface DepotControles
{
    public function enregistrer(Ressource $r, StatutListe $statut, string $zone, ?string $reponse, bool $pause, DateTimeImmutable $controleLe, ?DateTimeImmutable $pauseLe = null): void;

    /** La ressource a-t-elle une pause active (dernier contrôle « listé » avec pause) ? */
    public function enPause(Ressource $r): bool;
}
