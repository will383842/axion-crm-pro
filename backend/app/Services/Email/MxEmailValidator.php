<?php

namespace App\Services\Email;

/**
 * Validateur d'email maison sans dépendance externe (Sprint H7 — 2026-05-18).
 *
 * Port PHP du système maison sos-expat-project/backlink-engine/src/services/email/emailValidator.ts.
 *
 * 5 checks effectués (du moins coûteux au plus coûteux) :
 *   1. Syntax (regex)
 *   2. Disposable (blacklist ~120 domaines)
 *   3. Role-based (info@, contact@, etc)
 *   4. Free provider (gmail, yahoo, orange, etc → risky pour B2B)
 *   5. DNS MX records lookup (le seul check réseau)
 *
 * Pas de SMTP verification (volontaire) → évite blacklist Spamhaus IP serveur.
 * Pour valider l'existence réelle d'un email de boîte, il faut un service externe
 * type ZeroBounce/NeverBounce. Ce validator répond à la question "le domaine
 * peut-il recevoir un mail ?", pas "cette adresse précise existe-t-elle ?".
 *
 * Convention de retour pour rester compatible avec EmailFinderService :
 *   verified  → email syntaxiquement valide + domain a MX + n'est ni disposable
 *                ni free ni role
 *   risky     → free provider (gmail, etc) ou catch-all suspect
 *   invalid   → syntax KO ou aucun MX
 *   disposable→ 10minutemail / yopmail / etc
 *   role      → info@, contact@, noreply@…
 */
class MxEmailValidator
{
    /** Top 120 disposable / temp mail providers (port direct de la liste TS) */
    private const DISPOSABLE_DOMAINS = [
        '10minutemail.com', '10minutemail.net', 'guerrillamail.com', 'guerrillamail.net',
        'mailinator.com', 'temp-mail.org', 'tempmail.com', 'tempmailaddress.com',
        'throwaway.email', 'trashmail.com', 'yopmail.com', 'fakeinbox.com',
        'getnada.com', 'maildrop.cc', 'mintemail.com', 'sharklasers.com',
        'grr.la', 'guerrillamailblock.com', 'pokemail.net', 'spam4.me',
        'mvrht.net', 'bccto.me', 'bugmenot.com', 'getairmail.com',
        'armyspy.com', 'cuvox.de', 'dayrep.com', 'einrot.com',
        'fleckens.hu', 'gustr.com', 'jourrapide.com', 'rhyta.com',
        'superrito.com', 'teleworm.us', '33mail.com', 'anonbox.net',
        'emailondeck.com', 'filzmail.com', 'emailsensei.com', 'etranquil.com',
        'incognitomail.org', 'mailcatch.com', 'mailmetrash.com', 'mailnesia.com',
        'mailsac.com', 'mailtemp.info', 'mytrashmail.com', 'noclickemail.com',
        'nomail.xl.cx', 'pookmail.com', 'smoug.net', 'sogetthis.com',
        'spambox.us', 'spamfree24.org', 'spamgourmet.com', 'spamhole.com',
        'spamstack.net', 'spamthisplease.com', 'suremail.info', 'tempinbox.com',
        'tempmails.net', 'throwawayemailaddress.com', 'trashymail.com', 'vpn.st',
        'wegwerfmail.de', 'wegwerpmailadres.nl', 'wh4f.org', 'whatpaas.com',
        'banana-mail.com', 'banit.club', 'beefmilk.com', 'binkmail.com',
        'bobmail.info', 'bofthew.com', 'bootybay.de', 'brennendesreich.de',
        'bunsenhoneydew.com', 'card.zp.ua', 'casualdx.com', 'cek.pm',
        'centermail.com', 'centermail.net', 'choicemail1.com', 'clrmail.com',
        'cmail.net', 'cmail.org', 'coldemail.info', 'cool.fr.nf',
        'correo.blogos.net', 'cosmorph.com', 'courriel.fr.nf', 'cubiclink.com',
        'curryworld.de', 'cust.in', 'dacoolest.com', 'dandikmail.com',
        'dawin.com', 'dcemail.com', 'deadaddress.com', 'deadspam.com',
        'delikkt.de', 'despam.it', 'despammed.com', 'devnullmail.com',
        'dfgh.net', 'digitalsanctuary.com', 'discardmail.com', 'discardmail.de',
        'disposableaddress.com', 'disposableemailaddresses.com', 'disposableinbox.com',
    ];

    private const ROLE_PREFIXES = [
        'abuse', 'admin', 'administrator', 'all', 'billing', 'contact', 'help',
        'info', 'mail', 'marketing', 'noreply', 'no-reply', 'postmaster', 'root',
        'sales', 'security', 'spam', 'support', 'webmaster', 'hostmaster',
        'mailer-daemon', 'newsletter', 'accounts', 'service', 'services',
        'team', 'office', 'hello', 'press', 'media', 'news', 'jobs',
        'careers', 'hr', 'humanresources', 'legal', 'finance', 'accounting',
        'enquiry', 'enquiries', 'inquiry', 'inquiries', 'feedback', 'complaints',
    ];

    private const FREE_PROVIDERS = [
        'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com',
        'aol.com', 'icloud.com', 'mail.com', 'protonmail.com', 'gmx.com',
        'zoho.com', 'yandex.com', 'mail.ru', 'inbox.com', 'fastmail.com',
        'yahoo.fr', 'yahoo.co.uk', 'hotmail.fr', 'hotmail.co.uk', 'live.fr',
        'orange.fr', 'wanadoo.fr', 'free.fr', 'laposte.net', 'sfr.fr',
        'gmx.de', 'gmx.fr', 'web.de', 't-online.de', 'freenet.de',
    ];

    /**
     * @return array{
     *   status: string,
     *   email: string,
     *   reason: ?string,
     *   mx_records: array<int,string>,
     *   is_disposable: bool,
     *   is_role: bool,
     *   is_free_provider: bool,
     *   has_mx_records: bool
     * }
     */
    public function validate(string $email): array
    {
        $email = strtolower(trim($email));

        // 1. Syntax
        if (! $this->isValidSyntax($email)) {
            return $this->result('invalid', $email, 'Invalid email syntax');
        }

        $domain = $this->extractDomain($email);
        $localPart = $this->extractLocalPart($email);

        // 2. Disposable
        if (in_array($domain, self::DISPOSABLE_DOMAINS, true)) {
            return $this->result('disposable', $email, 'Temporary/disposable email service', isDisposable: true);
        }

        // 3. Role-based
        if (in_array($localPart, self::ROLE_PREFIXES, true)) {
            return $this->result('role', $email, 'Role-based email address', isRole: true);
        }

        // 4. MX records (le seul check réseau, peut prendre 100-500ms)
        $mxRecords = $this->resolveMxRecords($domain);
        $hasMx = ! empty($mxRecords);

        if (! $hasMx) {
            return $this->result(
                'invalid',
                $email,
                'No MX records found for domain',
                isFreeProvider: in_array($domain, self::FREE_PROVIDERS, true),
            );
        }

        // 5. Free provider → risky pour B2B (mais MX OK)
        if (in_array($domain, self::FREE_PROVIDERS, true)) {
            return $this->result(
                'risky',
                $email,
                'Free email provider (not B2B)',
                mxRecords: $mxRecords,
                isFreeProvider: true,
                hasMx: true,
            );
        }

        // 6. Tous les checks passent
        return $this->result(
            'verified',
            $email,
            reason: null,
            mxRecords: $mxRecords,
            hasMx: true,
        );
    }

    /**
     * Validation simple → retourne juste 'verified'|'invalid'|'risky'|'role'|'disposable'.
     * Utile quand on a juste besoin du verdict sans le détail.
     */
    public function quickStatus(string $email): string
    {
        return $this->validate($email)['status'];
    }

    public function isValidSyntax(string $email): bool
    {
        // FILTER_VALIDATE_EMAIL est plus strict que la regex JS, on garde le standard PHP.
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && mb_strlen($email) <= 254;
    }

    /**
     * @return array<int,string> MX records sorted by priority, host names only
     */
    public function resolveMxRecords(string $domain): array
    {
        if ($domain === '' || ! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)) {
            return [];
        }

        try {
            $records = @dns_get_record($domain, DNS_MX);
        } catch (\Throwable) {
            return [];
        }
        if (! is_array($records) || empty($records)) {
            return [];
        }

        // Sort by priority (lower first)
        usort($records, fn ($a, $b) => ($a['pri'] ?? 999) <=> ($b['pri'] ?? 999));

        return array_values(array_filter(array_map(
            static fn ($r) => isset($r['target']) && is_string($r['target']) ? strtolower($r['target']) : null,
            $records,
        )));
    }

    private function extractDomain(string $email): string
    {
        $parts = explode('@', $email);
        return count($parts) >= 2 ? strtolower(end($parts)) : '';
    }

    private function extractLocalPart(string $email): string
    {
        $parts = explode('@', $email);
        return strtolower($parts[0] ?? '');
    }

    /**
     * @param  array<int,string>  $mxRecords
     * @return array{
     *   status: string,
     *   email: string,
     *   reason: ?string,
     *   mx_records: array<int,string>,
     *   is_disposable: bool,
     *   is_role: bool,
     *   is_free_provider: bool,
     *   has_mx_records: bool
     * }
     */
    private function result(
        string $status,
        string $email,
        ?string $reason = null,
        array $mxRecords = [],
        bool $isDisposable = false,
        bool $isRole = false,
        bool $isFreeProvider = false,
        bool $hasMx = false,
    ): array {
        return [
            'status'           => $status,
            'email'            => $email,
            'reason'           => $reason,
            'mx_records'       => $mxRecords,
            'is_disposable'    => $isDisposable,
            'is_role'          => $isRole,
            'is_free_provider' => $isFreeProvider,
            'has_mx_records'   => $hasMx,
        ];
    }
}
