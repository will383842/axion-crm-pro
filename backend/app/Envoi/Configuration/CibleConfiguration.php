<?php

namespace App\Envoi\Configuration;

/** Le service d'envoi vu par K18 : valider À CÔTÉ, appliquer, rétablir la version précédente. */
interface CibleConfiguration
{
    public function valider(Paquet $paquet): bool;

    /** @throws \Throwable si l'application échoue */
    public function appliquer(Paquet $paquet): void;

    public function retablir(): void;
}
