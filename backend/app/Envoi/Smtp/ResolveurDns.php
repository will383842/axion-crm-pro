<?php

namespace App\Envoi\Smtp;

/** Résolveur de production : A et AAAA via le DNS du système. */
final class ResolveurDns implements ResolveurHote
{
    public function resoudre(string $hote): array
    {
        if (filter_var($hote, FILTER_VALIDATE_IP) !== false) {
            return [$hote];
        }
        $adresses = [];
        foreach (@dns_get_record($hote, DNS_A | DNS_AAAA) ?: [] as $enr) {
            $ip = $enr['ip'] ?? $enr['ipv6'] ?? null;
            if (is_string($ip) && $ip !== '') {
                $adresses[] = $ip;
            }
        }

        return array_values(array_unique($adresses));
    }
}
