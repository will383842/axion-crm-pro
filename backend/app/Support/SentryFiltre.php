<?php

namespace App\Support;

use Sentry\Breadcrumb;

/** Filtres Sentry (appelables statiques : la config reste sérialisable par `config:cache`). */
final class SentryFiltre
{
    /** Les traces de commande des témoins ne partent jamais chez Sentry (défense en profondeur : aucune adresse n'y entre). */
    public static function avantBreadcrumb(Breadcrumb $breadcrumb): ?Breadcrumb
    {
        if ($breadcrumb->getCategory() === 'artisan.command' && str_contains($breadcrumb->getMessage() ?? '', 'envoi:temoin')) {
            return null;
        }

        return $breadcrumb;
    }
}
