<?php

namespace App\Services\Email;

/**
 * Scoring de confiance email DÉTERMINISTE A/B/C (audit deep fixes 2026-07-14).
 *
 * Décision produit (Will) : PAS de vérification SMTP (jamais depuis le VPS —
 * risque de blacklist Spamhaus de l'IP serveur). À la place, un score de
 * confiance calculé sans AUCUN I/O réseau (pur, testable, reproductible),
 * exploitable pour prioriser l'ordre d'envoi via un ESP à gestion de rebonds.
 *
 * Barème :
 *   A → le domaine de l'email == le domaine racine (registrable / eTLD+1) du
 *       `website` de l'entreprise. Ex. contact@beldelec.fr + site beldelec.fr.
 *   B → domaine pro propre : ni A, ni fournisseur grand public (domaine
 *       d'entreprise, différent du site ou site absent).
 *   C → boîte grand public (gmail, orange, free, wanadoo, hotmail, outlook,
 *       live, yahoo, laposte, sfr, bbox, gmx, icloud, protonmail, aol…).
 *
 * Les domaines jetables/invalides sont déjà exclus en amont (MxEmailValidator +
 * looksLikeRealEmail) → pas de tier « D ». Retourne null si l'email est
 * inexploitable (syntaxe cassée / pas de domaine).
 */
class EmailConfidenceService
{
    /**
     * Fournisseurs de mail grand public (tier C). Domaine complet (eTLD+1).
     * Superset de MxEmailValidator::FREE_PROVIDERS + variantes FR courantes.
     */
    private const PUBLIC_PROVIDERS = [
        // Google / Microsoft / Apple / Yahoo / AOL
        'gmail.com', 'googlemail.com',
        'outlook.com', 'outlook.fr', 'hotmail.com', 'hotmail.fr', 'hotmail.co.uk',
        'live.com', 'live.fr', 'msn.com',
        'icloud.com', 'me.com', 'mac.com',
        'yahoo.com', 'yahoo.fr', 'yahoo.co.uk', 'ymail.com',
        'aol.com',
        // FAI / boîtes FR grand public
        'orange.fr', 'wanadoo.fr', 'free.fr', 'laposte.net',
        'sfr.fr', 'neuf.fr', 'bbox.fr', 'numericable.fr', 'club-internet.fr',
        'aliceadsl.fr',
        // Autres fournisseurs grand public
        'gmx.com', 'gmx.fr', 'gmx.de', 'web.de', 't-online.de', 'freenet.de',
        'protonmail.com', 'proton.me', 'pm.me',
        'mail.com', 'mail.ru', 'yandex.com', 'yandex.ru',
        'zoho.com', 'fastmail.com', 'inbox.com', 'tutanota.com', 'tuta.io',
    ];

    /**
     * Suffixes composés (eTLD à 2 labels) fréquents FR/EU/UK. Si le domaine se
     * termine par l'un d'eux, le domaine racine = 3 derniers labels ; sinon =
     * 2 derniers labels. Liste courte volontairement (pas la PSL complète, hors
     * périmètre) — couvre les cas rencontrés en prospection FR.
     */
    private const COMPOUND_SUFFIXES = [
        'co.uk', 'org.uk', 'gov.uk', 'ac.uk', 'me.uk', 'ltd.uk', 'plc.uk',
        'com.fr', 'asso.fr', 'gouv.fr', 'nom.fr', 'prd.fr', 'tm.fr',
        'co.jp', 'com.au', 'co.nz', 'com.br', 'co.za',
        'com.es', 'com.pt', 'com.pl', 'com.tr', 'com.mx',
    ];

    /**
     * Score de confiance A/B/C d'un email, relatif au site de l'entreprise.
     *
     * @param  string       $email    email du contact (ou email_generic société)
     * @param  string|null  $website  site web connu de l'entreprise (peut être null)
     * @return 'A'|'B'|'C'|null
     */
    public function score(string $email, ?string $website): ?string
    {
        $domain = $this->emailDomain($email);
        if ($domain === null) {
            return null;
        }

        // C — boîte grand public (comparaison sur le domaine racine pour couvrir
        // les sous-domaines type mail.gmail.com).
        $root = $this->registrableDomain($domain);
        if (in_array($root, self::PUBLIC_PROVIDERS, true)
            || in_array($domain, self::PUBLIC_PROVIDERS, true)) {
            return 'C';
        }

        // A — même domaine racine que le site de l'entreprise.
        $siteRoot = $this->websiteRootDomain($website);
        if ($siteRoot !== null && $siteRoot === $root) {
            return 'A';
        }

        // B — domaine pro propre (ni public, ni aligné sur le site).
        return 'B';
    }

    /** Extrait le domaine (lowercased) d'un email, ou null si inexploitable. */
    private function emailDomain(string $email): ?string
    {
        $email = strtolower(trim($email));
        $at = strrpos($email, '@');
        if ($at === false) {
            return null;
        }
        $domain = trim(substr($email, $at + 1));
        $domain = rtrim($domain, '.');
        if ($domain === '' || ! str_contains($domain, '.')) {
            return null;
        }
        return $domain;
    }

    /**
     * Domaine racine (eTLD+1) d'un `website` (URL ou nu). Null si inexploitable.
     */
    private function websiteRootDomain(?string $website): ?string
    {
        if ($website === null) {
            return null;
        }
        $website = trim($website);
        if ($website === '') {
            return null;
        }

        // Extrait le host, que l'entrée soit une URL (http://…/…) ou un domaine nu.
        $host = parse_url($website, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            // Domaine nu éventuellement suivi d'un path (« beldelec.fr/contact »).
            $host = preg_replace('#^[a-z]+://#i', '', $website) ?? $website;
            $host = explode('/', $host)[0];
            $host = explode('?', $host)[0];
        }

        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host; // retire :port
        $host = rtrim($host, '.');
        if ($host === '' || ! str_contains($host, '.')) {
            return null;
        }

        return $this->registrableDomain($host);
    }

    /**
     * Domaine enregistrable (eTLD+1) d'un host déjà nettoyé/lowercased.
     * Gère une petite liste de suffixes composés (co.uk, com.fr…) ; sinon
     * retombe sur les 2 derniers labels.
     */
    private function registrableDomain(string $host): string
    {
        $host = ltrim($host, '.');
        $labels = explode('.', $host);
        $count = count($labels);
        if ($count <= 2) {
            return $host;
        }

        $lastTwo = $labels[$count - 2] . '.' . $labels[$count - 1];
        if (in_array($lastTwo, self::COMPOUND_SUFFIXES, true)) {
            // eTLD+1 = 3 derniers labels
            return $labels[$count - 3] . '.' . $lastTwo;
        }

        return $lastTwo;
    }
}
