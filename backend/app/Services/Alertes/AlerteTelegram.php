<?php

namespace App\Services\Alertes;

use App\Socle\Alertes\Alerte;

/**
 * Ancien nom du canal d'alerte, conservé le temps que les derniers appelants
 * passent à `App\Socle\Alertes\Alerte`. Il n'envoie plus rien lui-même : il
 * transmet à l'alerte à deux canaux (cloche + e-mail).
 *
 * @deprecated utiliser `App\Socle\Alertes\Alerte`
 */
class AlerteTelegram
{
    /** @param array<string, mixed> $contexte */
    public function envoyer(string $titre, string $corps, array $contexte = []): bool
    {
        return app(Alerte::class)->envoyer($titre, $corps, 'crm', 'critique', null, $contexte);
    }

    public static function oublierLAvertissement(): void
    {
        Alerte::oublierLAvertissement();
    }
}
