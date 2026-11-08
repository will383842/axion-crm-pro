<?php

namespace App\Envoi\Serveur\Kumo;

/**
 * États d'injection par clé d'idempotence : a_injecter | injection_en_cours | accepte | a_verifier | reporte | echec.
 * Interface INTERNE à l'adaptateur Kumo (ServeurEnvoi, D4, ne change pas). Elle porte aussi l'état partagé entre
 * workers : la pause du serveur et le compteur des 5xx (jamais en mémoire d'instance).
 *
 * TOUT est cloisonné par ESPACE (`$workspaceId`) : clés, pause, compteur de 5xx et séquence. Une pause ou des 5xx
 * d'un espace n'arrêtent jamais un autre ; deux espaces peuvent réutiliser la même clé d'idempotence.
 *
 * DETTE NOMMÉE (K9-registre-durable) : l'implémentation durable (table en AJOUT SEUL, `ecrireSi` en
 * `UPDATE … WHERE etat = ? AND instant = ?` ou `INSERT … ON CONFLICT DO NOTHING`, pause, 5xx et séquence en lignes
 * dédiées, le tout sous RLS de l'espace) reste à écrire, hors des fichiers autorisés de K9. RegistreMemoire ne sert
 * qu'au banc et aux tests (il refuse de se construire hors environnement `testing`).
 *
 * `seq` : numéro de séquence STABLE, assigné une seule fois quand la ligne devient `accepte` (curseur des événements).
 *
 * @phpstan-type Ligne array{id: string, etat: string, reporte_au: ?int, instant: int, jeton: string, seq?: int}
 */
interface RegistreInjections
{
    /** @return array{id: string, etat: string, reporte_au: ?int, instant: int, jeton: string, seq?: int}|null */
    public function lire(string $workspaceId, string $cle): ?array;

    /** @param array{id: string, etat: string, reporte_au: ?int, instant: int, jeton: string, seq?: int} $ligne */
    public function ecrire(string $workspaceId, string $cle, array $ligne): void;

    /**
     * Écriture conditionnelle atomique (compare-and-set) : n'écrit QUE si l'état courant de la clé est
     * `$etatAttendu` (null = clé absente) ET que son `instant` est `$instantAttendu` (null = clé absente) : sans
     * l'instant, un worker lent verrait « reporte » et écraserait un cycle reporte → injection_en_cours → reporte
     * fait entre-temps par un autre (ABA). Rend vrai si l'écriture a eu lieu.
     *
     * @param  array{id: string, etat: string, reporte_au: ?int, instant: int, jeton: string, seq?: int}  $ligne
     */
    public function ecrireSi(string $workspaceId, string $cle, ?string $etatAttendu, ?int $instantAttendu, array $ligne): bool;

    /** @return array<string, array{id: string, etat: string, reporte_au: ?int, instant: int, jeton: string, seq?: int}> */
    public function tout(string $workspaceId): array;

    public function enPause(string $workspaceId): bool;

    public function fixerPause(string $workspaceId, bool $pause): void;

    /** Un refus définitif (5xx) REÇU À L'INJECTION. Un échec lu par interrogation n'est jamais noté ici. */
    public function noter5xx(string $workspaceId, int $instant): void;

    /** Nombre de 5xx notés depuis `$depuis` (horodatage Unix, inclus). */
    public function compter5xx(string $workspaceId, int $depuis): int;

    /** Remise à zéro du compteur de 5xx (à la reprise : on ne repart pas avec le passif qui a causé la pause). */
    public function reinitialiser5xx(string $workspaceId): void;

    /** Numéro de séquence suivant de l'espace (1, 2, 3…), atomique dans une implémentation durable. */
    public function prochaineSequence(string $workspaceId): int;
}
