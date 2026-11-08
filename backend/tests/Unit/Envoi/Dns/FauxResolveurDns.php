<?php

namespace Tests\Unit\Envoi\Dns;

use App\Envoi\Dns\ResolveurDns;

/** Résolveur factice : tout vient de tableaux, aucun accès réseau. */
final class FauxResolveurDns implements ResolveurDns
{
    /** @var array<string, list<string>> */
    public array $txt = [];

    /** @var array<string, list<string>> */
    public array $a = [];

    /** @var array<string, list<string>> */
    public array $aaaa = [];

    /** @var array<string, list<string>> */
    public array $ptr = [];

    /** @var array<string, string> */
    public array $politiques = [];

    /** @var list<string> */
    public array $requetes = [];

    public function txt(string $nom): array
    {
        $this->requetes[] = "TXT $nom";

        return $this->txt[$nom] ?? [];
    }

    public function a(string $nom): array
    {
        $this->requetes[] = "A $nom";

        return $this->a[$nom] ?? [];
    }

    public function aaaa(string $nom): array
    {
        $this->requetes[] = "AAAA $nom";

        return $this->aaaa[$nom] ?? [];
    }

    public function ptr(string $ip): array
    {
        $this->requetes[] = "PTR $ip";

        return $this->ptr[$ip] ?? [];
    }

    public function politiqueMtaSts(string $domaine): ?string
    {
        $this->requetes[] = "STS $domaine";

        return $this->politiques[$domaine] ?? null;
    }

    /** Un domaine entièrement conforme (adresses de documentation, domaines en .test). */
    public static function conforme(string $domaine = 'envoi.example.test', string $sel = 'sel1', string $helo = 'mx.envoi.example.test', string $ip = '192.0.2.10'): self
    {
        $f = new self;
        $f->txt[$domaine] = ["v=spf1 ip4:$ip -all"];
        $f->txt["$sel._domainkey.$domaine"] = ['v=DKIM1; k=rsa; p=' . base64_encode(str_repeat('x', 294))];
        $f->txt["_dmarc.$domaine"] = ["v=DMARC1; p=none; rua=mailto:dmarc@$domaine"];
        $f->ptr[$ip] = [$helo];
        $f->a[$helo] = [$ip];
        $f->txt["_mta-sts.$domaine"] = ['v=STSv1; id=20260101'];
        $f->politiques[$domaine] = "version: STSv1\nmode: testing\nmx: $helo\nmax_age: 86400\n";
        $f->txt["_smtp._tls.$domaine"] = ["v=TLSRPTv1; rua=mailto:tls@$domaine"];

        return $f;
    }
}
