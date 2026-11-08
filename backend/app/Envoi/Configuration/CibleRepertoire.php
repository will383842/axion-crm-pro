<?php

namespace App\Envoi\Configuration;

use RuntimeException;

/** Cible locale : fichiers dans un répertoire du serveur. courant -> precedent, candidat -> courant. */
final class CibleRepertoire implements CibleConfiguration
{
    public function __construct(private readonly string $dir) {}

    public function valider(Paquet $paquet): bool
    {
        $candidat = $this->dir . '/.candidat.json';
        if (file_put_contents($candidat, $paquet->json()) === false) {
            return false;
        }
        $relu = json_decode((string) file_get_contents($candidat), true);

        return is_array($relu) && Paquet::deBrut($relu)->empreinte() === $paquet->empreinte();
    }

    public function appliquer(Paquet $paquet): void
    {
        $courant = $this->dir . '/domaines.json';
        $candidat = $this->dir . '/.candidat.json';
        if (! is_file($candidat) && file_put_contents($candidat, $paquet->json()) === false) {
            throw new RuntimeException('écriture impossible');
        }
        if (is_file($courant) && ! rename($courant, $this->dir . '/.precedent.json')) {
            throw new RuntimeException('sauvegarde impossible');
        }
        if (! rename($candidat, $courant)) {
            throw new RuntimeException('remplacement impossible');
        }
    }

    public function retablir(): void
    {
        $precedent = $this->dir . '/.precedent.json';
        if (is_file($precedent)) {
            rename($precedent, $this->dir . '/domaines.json');
        }
    }
}
