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
     * R05 : pour toute route /envoi/*, la chaîne de requête (et les données, cookies) ne part jamais chez Sentry.
     * « Puis-je écrire à… ? » passe l'adresse dans le corps (POST), mais l'ancien appel GET ?adresse= doit rester sans fuite.
     */
    public static function avantEnvoi(Event $event, ?EventHint $hint = null): Event
    {
        $req = $event->getRequest();
        $url = (string) ($req['url'] ?? '');
        if (! preg_match('#/envoi(/|\?|$)#', (string) parse_url($url, PHP_URL_PATH))) {
            return $event;
        }
        $req['url'] = explode('?', $url, 2)[0];
        unset($req['query_string'], $req['data'], $req['cookies']);
        $event->setRequest($req);
        $event->setExtra([]);

        return $event;
    }
}
