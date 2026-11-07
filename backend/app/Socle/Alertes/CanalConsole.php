<?php

namespace App\Socle\Alertes;

/** Canal console : la cloche de l'en-tête. */
final class CanalConsole
{
    public function __construct(private readonly Notifier $notifier) {}

    /** @param array<string, mixed> $contexte */
    public function ecrire(string $titre, string $corps, string $produit, string $gravite, ?string $lien, array $contexte): bool
    {
        return $this->notifier->envoyer($titre, $corps, $produit, $gravite, $lien, $contexte) > 0;
    }
}
