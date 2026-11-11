<?php

namespace App\Support;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

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

    /**
     * R05 : « Puis-je écrire à… ? » reçoit l'adresse testée dans l'URL. Pour cette route, la query string et l'adresse
     * (dans l'URL, les données supplémentaires et le contexte) ne partent jamais chez Sentry.
     */
    public static function avantEnvoi(Event $event, ?EventHint $hint = null): ?Event
    {
        $req = $event->getRequest();
        if (! str_contains((string) ($req['url'] ?? ''), '/envoi/puis-je-ecrire')) {
            return $event;
        }
        $req['url'] = explode('?', (string) $req['url'], 2)[0];
        unset($req['query_string'], $req['data'], $req['cookies']);
        $event->setRequest($req);
        $event->setExtra([]);

        return $event;
    }
}
