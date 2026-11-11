<?php

namespace App\Envoi\Exceptions;

use RuntimeException;

/** Écriture de campagne ou de modèle refusée (transition, contenu, version périmée). Code 409 : conflit d'état. */
final class CampagneRefusee extends RuntimeException
{
    public const STATUT_HTTP = 409;

    public static function transition(string $de, string $vers): self
    {
        return new self("Transition de campagne interdite : {$de} -> {$vers}.", self::STATUT_HTTP);
    }

    public static function sansSequence(string $vers): self
    {
        return new self("Le statut « {$vers} » est impossible : cette campagne n'a pas de séquence.", self::STATUT_HTTP);
    }

    public static function contenu(string $raison): self
    {
        return new self("Campagne refusée : {$raison}.", 422);
    }

    public static function versionPerimee(): self
    {
        return new self('Conflit : la campagne a changé depuis votre lecture (version périmée).', self::STATUT_HTTP);
    }

    public static function introuvable(): self
    {
        return new self('Introuvable dans cet espace.', 404);
    }
}
