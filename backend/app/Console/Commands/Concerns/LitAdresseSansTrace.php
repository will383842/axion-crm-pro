<?php

namespace App\Console\Commands\Concerns;

/**
 * Lit une adresse de témoin SANS qu'elle passe par la ligne de commande : ni argument, ni option.
 * Une adresse en argument fuirait dans `ps`, `docker exec`, l'historique du shell et la trace « input »
 * que sentry-laravel ajoute à chaque commande. Ici : invite masquée (terminal) ou une ligne sur l'entrée
 * standard (non interactif). Le flux peut être remplacé par le conteneur sous la clé `envoi.flux_entree` (tests).
 */
trait LitAdresseSansTrace
{
    private const LONGUEUR_MAX_ADRESSE = 254;

    /** Retourne l'adresse lue (sans espaces autour), ou null si absente. */
    protected function lireAdresse(string $invite): ?string
    {
        if ($this->input->isInteractive()) {
            $brut = $this->secret($invite);
        } else {
            $flux = $this->laravel->bound('envoi.flux_entree') ? $this->laravel->make('envoi.flux_entree') : @fopen('php://stdin', 'r');
            $brut = is_resource($flux) ? fgets($flux, self::LONGUEUR_MAX_ADRESSE + 3) : false;
        }
        $adresse = is_string($brut) ? trim($brut) : '';

        return $adresse === '' ? null : $adresse;
    }
}
