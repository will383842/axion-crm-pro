<?php

namespace App\Envoi\Serveur\Kumo;

/**
 * États d'injection par clé d'idempotence : a_injecter | injection_en_cours | accepte | a_verifier | reporte | echec.
 * DETTE : l'implémentation durable (table en AJOUT SEUL, hors des fichiers autorisés de K9) reste à écrire.
 */
interface RegistreInjections
{
    /** @return array{id: string, etat: string, reporte_au: ?int, instant: int}|null */
    public function lire(string $cle): ?array;

    /** @param array{id: string, etat: string, reporte_au: ?int, instant: int} $ligne */
    public function ecrire(string $cle, array $ligne): void;

    /** @return array<string, array{id: string, etat: string, reporte_au: ?int, instant: int}> */
    public function tout(): array;
}
