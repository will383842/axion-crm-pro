<?php

namespace App\Crm\Scraping;

/**
 * Validation MX des emails collectés — « jamais confiance au collecteur »
 * (audit scraping §C.1).
 *
 * Un email scrapé sans domaine résolvable est du bruit : il polluerait la
 * dédup (une fiche personne par coquille) et le futur moteur de campagnes
 * (bounces). On vérifie donc que le domaine a un MX (ou à défaut un A, des
 * domaines valides délèguent parfois sans MX explicite).
 *
 * Désactivable par `crm.scrape_funnel.validate_mx=false` : indispensable pour
 * la suite de tests (aucun appel réseau réel — même règle que les MOCK_*) et
 * pour les imports en environnement coupé du DNS.
 */
class EmailMxValidator
{
    /** @var array<string, bool> cache par domaine, le temps du process */
    private array $cache = [];

    public function isDeliverable(string $email): bool
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if (! filter_var(config('crm.scrape_funnel.validate_mx', true), FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        $domain = strtolower(substr($email, (int) strrpos($email, '@') + 1));
        if ($domain === '') {
            return false;
        }

        return $this->cache[$domain] ??= (checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A'));
    }
}
