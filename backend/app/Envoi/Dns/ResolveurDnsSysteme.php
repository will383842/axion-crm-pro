<?php

namespace App\Envoi\Dns;

use Illuminate\Support\Facades\Http;
use Throwable;

/** Résolveur réel (lecture seule). Jamais utilisé par les tests. */
final class ResolveurDnsSysteme implements ResolveurDns
{
    public function txt(string $nom): array
    {
        $sortie = [];
        foreach ($this->interroger($nom, DNS_TXT) as $e) {
            $sortie[] = (string) ($e['txt'] ?? '');
        }

        return $sortie;
    }

    public function a(string $nom): array
    {
        $sortie = [];
        foreach ($this->interroger($nom, DNS_A) as $e) {
            $sortie[] = (string) ($e['ip'] ?? '');
        }

        return array_values(array_filter($sortie));
    }

    public function ptr(string $ip): array
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return [];
        }
        $inverse = implode('.', array_reverse(explode('.', $ip))) . '.in-addr.arpa';
        $sortie = [];
        foreach ($this->interroger($inverse, DNS_PTR) as $e) {
            $sortie[] = rtrim(strtolower((string) ($e['target'] ?? '')), '.');
        }

        return array_values(array_filter($sortie));
    }

    public function politiqueMtaSts(string $domaine): ?string
    {
        try {
            $r = Http::timeout(5)->withoutRedirecting()->get('https://mta-sts.' . $domaine . '/.well-known/mta-sts.txt');

            return $r->successful() ? $r->body() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<array<string, mixed>> */
    private function interroger(string $nom, int $type): array
    {
        $r = @dns_get_record($nom, $type);

        return is_array($r) ? array_values($r) : [];
    }
}
