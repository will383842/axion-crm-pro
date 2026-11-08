<?php

namespace App\Envoi\Serveur\Kumo;

/**
 * États d'injection par clé d'idempotence : a_injecter | injection_en_cours | accepte | a_verifier | reporte | echec.
 * Interface INTERNE à l'adaptateur Kumo (ServeurEnvoi, D4, ne change pas). Elle porte aussi l'état partagé entre
 * workers : la pause du serveur et le compteur des 5xx (jamais en mémoire d'instance).
 *
 * DETTE NOMMÉE (K9-registre-durable) : l'implémentation durable (table en AJOUT SEUL, `ecrireSi` en
 * `UPDATE … WHERE etat = ?` ou `INSERT … ON CONFLICT DO NOTHING`, pause et 5xx en lignes dédiées, le tout sous RLS
 * de l'espace) reste à écrire, hors des fichiers autorisés de K9. RegistreMemoire ne sert qu'au banc et aux tests.
 *
 * @phpstan-type Ligne array{id: string, etat: string, reporte_au: ?int, instant: int, jeton: string}
 */
interface RegistreInjections
{
    /** @return array{id: string, etat: string, reporte_au: ?int, instant: int, jeton: string}|null */
    public function lire(string $cle): ?array;

    /** @param array{id: string, etat: string, reporte_au: ?int, instant: int, jeton: string} $ligne */
    public function ecrire(string $cle, array $ligne): void;

    /**
     * Écriture conditionnelle atomique (compare-and-set) : n'écrit QUE si l'état courant de la clé est
     * `$etatAttendu` (null = clé absente). Rend vrai si l'écriture a eu lieu. Deux workers sur la même clé : un seul gagne.
     *
     * @param  array{id: string, etat: string, reporte_au: ?int, instant: int, jeton: string}  $ligne
     */
    public function ecrireSi(string $cle, ?string $etatAttendu, array $ligne): bool;

    /** @return array<string, array{id: string, etat: string, reporte_au: ?int, instant: int, jeton: string}> */
    public function tout(): array;

    public function enPause(): bool;

    public function fixerPause(bool $pause): void;

    /** Un refus définitif (5xx) REÇU À L'INJECTION. Un échec lu par interrogation n'est jamais noté ici. */
    public function noter5xx(int $instant): void;

    /** Nombre de 5xx notés depuis `$depuis` (horodatage Unix, inclus). */
    public function compter5xx(int $depuis): int;
}
