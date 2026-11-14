<?php

namespace App\Envoi\Creation;

use RuntimeException;

/** Refus de l'assistant de création : code machine stable (`error`), phrase française, statut HTTP. */
final class ErreurCreation extends RuntimeException
{
    public function __construct(public readonly string $erreur, string $message, public readonly int $statut)
    {
        parent::__construct($message, $statut);
    }

    public static function envoiFerme(): self
    {
        return new self('envoi_ferme_palier_0', 'Les envois sont fermés au palier 0 : aucun message de test ne peut partir pour le moment.', 409);
    }

    public static function introuvable(): self
    {
        return new self('non_trouve', 'Introuvable dans cet espace.', 404);
    }

    public static function interdit(string $message): self
    {
        return new self('interdit', $message, 403);
    }

    public static function conflit(string $erreur, string $message): self
    {
        return new self($erreur, $message, 409);
    }

    public static function invalide(string $erreur, string $message): self
    {
        return new self($erreur, $message, 422);
    }
}
