<?php

namespace App\Envoi\ListesNoires;

use DateTimeImmutable;

/** Ajout seulement : un contrôle = une ligne, jamais modifiée ni effacée. */
interface DepotControles
{
    /** @return bool true si une ligne a été écrite (false : état inchangé, dédoublonné à l'heure) */
    public function enregistrer(Ressource $r, StatutListe $statut, string $zone, ?string $reponse, bool $pause, DateTimeImmutable $controleLe, ?DateTimeImmutable $pauseLe = null): bool;

    /** Statut du dernier contrôle enregistré (null : jamais contrôlée). */
    public function dernierStatut(Ressource $r): ?StatutListe;

    /**
     * La ressource est-elle à traiter comme en pause ? Oui si le dernier contrôle « listé » a posé une
     * pause, OU si elle est « non fiable » sans interruption depuis plus de
     * `envoi.listes_noires.non_fiable_bloque_apres_minutes` (fail-closed).
     */
    public function enPause(Ressource $r): bool;
}
