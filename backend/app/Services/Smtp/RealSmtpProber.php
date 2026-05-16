<?php

namespace App\Services\Smtp;

use App\Contracts\SmtpProber;
use App\Data\Email\SmtpProbeResult;
use Illuminate\Support\Facades\Cache;

/**
 * Cascade SMTP validation N1-N5 :
 *   N1 syntaxe RFC + non disposable + non role
 *   N2 MX records DNS
 *   N3 SMTP handshake HELO/EHLO + MAIL FROM + RCPT TO
 *   N4 Catch-all detection (probe email random_xxx@domain)
 *   N5 Scoring composite 0-100
 *
 * Liste embarquée : disposable domains + role emails (info, sales, contact, …).
 */
class RealSmtpProber implements SmtpProber
{
    private const ROLE_LOCAL_PARTS = [
        'admin', 'administrator', 'contact', 'info', 'sales', 'support', 'no-reply',
        'noreply', 'postmaster', 'hostmaster', 'webmaster', 'security', 'abuse',
        'help', 'billing', 'service', 'team', 'staff', 'office', 'hello', 'press',
    ];

    private const DISPOSABLE_DOMAINS = [
        'mailinator.com', 'trashmail.com', 'guerrillamail.com', '10minutemail.com',
        'tempmail.com', 'yopmail.com', 'temp-mail.org', 'throwaway.email', 'fakeinbox.com',
    ];

    public function probe(string $email): SmtpProbeResult
    {
        $email = strtolower(trim($email));

        // N1 syntaxe
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return new SmtpProbeResult($email, 'invalid', 0, message: 'syntax_invalid');
        }
        [$local, $domain] = explode('@', $email, 2);

        $isRole       = in_array($local, self::ROLE_LOCAL_PARTS, true);
        $isDisposable = in_array($domain, self::DISPOSABLE_DOMAINS, true);
        if ($isDisposable) {
            return new SmtpProbeResult($email, 'disposable', 0, isDisposable: true);
        }

        // N2 MX records
        $mxHost = $this->getMxHost($domain);
        if (! $mxHost) {
            return new SmtpProbeResult($email, 'invalid', 0, message: 'no_mx');
        }

        // N3 SMTP handshake
        $smtpOk = $this->smtpRcptCheck($mxHost, $email);
        if (! $smtpOk) {
            return new SmtpProbeResult($email, 'invalid', 5, $mxHost, message: 'rcpt_rejected');
        }

        // N4 Catch-all detection
        $isCatchAll = $this->isCatchAll($domain, $mxHost);

        // N5 Scoring composite
        $score = 95;
        if ($isCatchAll) $score = 60;
        if ($isRole)     $score = min($score, 50);

        return new SmtpProbeResult(
            email: $email,
            status: $isCatchAll ? 'catchall' : 'valid',
            score: $score,
            mxHost: $mxHost,
            isCatchAll: $isCatchAll,
            isDisposable: false,
            isRole: $isRole,
        );
    }

    private function getMxHost(string $domain): ?string
    {
        return Cache::remember("mx:{$domain}", 86400, function () use ($domain) {
            $mx = [];
            $weights = [];
            if (! getmxrr($domain, $mx, $weights)) {
                return null;
            }
            array_multisort($weights, SORT_ASC, $mx);
            return $mx[0] ?? null;
        });
    }

    private function smtpRcptCheck(string $mxHost, string $email): bool
    {
        $timeoutS = (int) env('SMTP_PROBE_TIMEOUT_S', 8);
        $fromEmail = (string) env('SMTP_PROBE_FROM_EMAIL', 'postmaster@axion-crm.local');

        $fp = @fsockopen($mxHost, 25, $errno, $errstr, $timeoutS);
        if (! $fp) {
            return false;
        }
        stream_set_timeout($fp, $timeoutS);

        try {
            if (! $this->expect($fp, '220')) return false;
            fwrite($fp, "EHLO " . parse_url((string) env('APP_URL', 'localhost'), PHP_URL_HOST) . "\r\n");
            if (! $this->expect($fp, '250')) return false;
            fwrite($fp, "MAIL FROM:<{$fromEmail}>\r\n");
            if (! $this->expect($fp, '250')) return false;
            fwrite($fp, "RCPT TO:<{$email}>\r\n");
            $accept = $this->expect($fp, '250');
            fwrite($fp, "QUIT\r\n");
            return $accept;
        } finally {
            fclose($fp);
        }
    }

    private function isCatchAll(string $domain, string $mxHost): bool
    {
        return Cache::remember("catchall:{$domain}", 604800, function () use ($domain, $mxHost) {
            $fakeEmail = 'axion-probe-' . bin2hex(random_bytes(6)) . '@' . $domain;
            return $this->smtpRcptCheck($mxHost, $fakeEmail);
        });
    }

    private function expect($fp, string $code): bool
    {
        $resp = '';
        while (! feof($fp)) {
            $line = fgets($fp, 1024);
            if ($line === false) return false;
            $resp .= $line;
            if (preg_match('/^\d{3} /', $line)) break;
        }
        return str_starts_with(trim($resp), $code);
    }
}
