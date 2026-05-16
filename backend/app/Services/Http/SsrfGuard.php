<?php

namespace App\Services\Http;

/**
 * SSRF guard — refuse les URLs qui pointent vers des IPs privées, link-local,
 * AWS/GCP metadata, loopback. À appeler avant tout fetch HTTP externe.
 *
 * Cf. spec/17_rgpd_aiact_owasp.md § A10.
 */
class SsrfGuard
{
    /** Hôtes interdits exacts. */
    private const DENY_HOSTS = [
        '169.254.169.254',  // AWS / GCP metadata
        'metadata.google.internal',
        '100.100.100.200',  // Alibaba metadata
        'metadata.azure.com',
        'localhost',
        '127.0.0.1', '::1',
        '0.0.0.0',
    ];

    /** Plages CIDR refusées (RFC 1918 + link-local + multicast + loopback). */
    private const DENY_CIDR = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    public static function enabled(): bool
    {
        return (bool) env('SSRF_GUARD_DENY_PRIVATE', true);
    }

    /**
     * @return array{ok: bool, reason: ?string}
     */
    public static function check(string $url): array
    {
        if (! self::enabled()) {
            return ['ok' => true, 'reason' => null];
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return ['ok' => false, 'reason' => 'invalid_url'];
        }

        $host = strtolower($parts['host']);

        if (in_array($host, self::DENY_HOSTS, true)) {
            return ['ok' => false, 'reason' => "deny_host:{$host}"];
        }

        // Résoudre toutes les IPs A + AAAA et vérifier chacune
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $a = @dns_get_record($host, DNS_A);
            foreach ($a ?: [] as $r) {
                if (! empty($r['ip'])) $ips[] = $r['ip'];
            }
            $aaaa = @dns_get_record($host, DNS_AAAA);
            foreach ($aaaa ?: [] as $r) {
                if (! empty($r['ipv6'])) $ips[] = $r['ipv6'];
            }
        }

        if (empty($ips)) {
            return ['ok' => false, 'reason' => 'dns_no_records'];
        }

        foreach ($ips as $ip) {
            if (self::ipInDenyCidr($ip)) {
                return ['ok' => false, 'reason' => "deny_cidr:{$ip}"];
            }
        }

        return ['ok' => true, 'reason' => null];
    }

    public static function ensure(string $url): void
    {
        $check = self::check($url);
        if (! $check['ok']) {
            throw new \RuntimeException("SSRF guard rejected URL: {$check['reason']}");
        }
    }

    private static function ipInDenyCidr(string $ip): bool
    {
        $packedIp = @inet_pton($ip);
        if ($packedIp === false) {
            return true; // fail-closed
        }

        foreach (self::DENY_CIDR as $cidr) {
            [$range, $bits] = explode('/', $cidr);
            $packedRange = inet_pton($range);
            if ($packedRange === false || strlen($packedRange) !== strlen($packedIp)) {
                continue;
            }
            $bytes = intdiv((int) $bits, 8);
            $remainingBits = ((int) $bits) % 8;
            if (substr($packedIp, 0, $bytes) !== substr($packedRange, 0, $bytes)) {
                continue;
            }
            if ($remainingBits === 0) {
                return true;
            }
            $maskByte = chr(0xff << (8 - $remainingBits) & 0xff);
            if ((ord($packedIp[$bytes]) & ord($maskByte)) === (ord($packedRange[$bytes]) & ord($maskByte))) {
                return true;
            }
        }
        return false;
    }
}
