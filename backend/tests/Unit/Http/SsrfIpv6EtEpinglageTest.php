<?php

/**
 * SSRF — IPv6, IPv6 mappée IPv4, ports, épinglage de l'IP vérifiée (relecture
 * A09 de #270).
 *
 * `DENY_CIDR` ne contenait que de l'IPv4 et `ipInDenyCidr` sautait toute plage
 * de longueur différente : `::1`, `fd00::/8`, `fe80::/10` ou
 * `::ffff:169.254.169.254` passaient dès qu'ils venaient d'un enregistrement
 * AAAA. Et `http://[::1]/` passait sur le banc : l'hôte gardait ses crochets.
 * Adresses LITTÉRALES seulement (le banc ne résout pas de vrais noms) : le même
 * `ipInDenyCidr` juge chaque adresse résolue, A comme AAAA.
 */

use App\Services\Http\SsrfGuard;

test('IPv6 interne refusee : boucle, non specifiee, ULA, lien local, multicast, documentation, NAT64, 6to4, Teredo', function (string $url) {
    expect(SsrfGuard::check($url)['ok'])->toBeFalse();
})->with([
    'http://[::1]/', 'http://[::]/', 'http://[fd00::1]/', 'http://[fc00::5]/', 'http://[fe80::1]/',
    'http://[ff02::1]/', 'http://[2001:db8::1]/', 'http://[64:ff9b::a9fe:a9fe]/', 'http://[2002:a9fe:a9fe::1]/',
    'http://[2001:0:4136:e378::1]/', 'http://[::7f00:1]/',
]);

test('IPv6 mappee IPv4 : les regles IPv4 s appliquent a l adresse mappee', function () {
    expect(SsrfGuard::check('http://[::ffff:169.254.169.254]/')['ok'])->toBeFalse()
        ->and(SsrfGuard::check('http://[::ffff:127.0.0.1]/')['ok'])->toBeFalse()
        ->and(SsrfGuard::check('http://[::ffff:10.1.2.3]/')['ok'])->toBeFalse()
        // Témoins : une adresse publique, mappée ou non, passe.
        ->and(SsrfGuard::check('http://[::ffff:8.8.8.8]/')['ok'])->toBeTrue()
        ->and(SsrfGuard::check('http://[2606:4700:4700::1111]/')['ok'])->toBeTrue();
});

test('ports : la lecture de sites n accepte que 80 et 443 ; check() sans liste ne change pas', function () {
    expect(SsrfGuard::verifier('https://8.8.8.8:8443/', [80, 443])['reason'])->toBe('deny_port:8443')
        ->and(SsrfGuard::verifier('http://8.8.8.8:22/', [80, 443])['ok'])->toBeFalse()
        ->and(SsrfGuard::verifier('http://8.8.8.8/', [80, 443])['ok'])->toBeTrue()
        ->and(SsrfGuard::verifier('https://8.8.8.8:443/', [80, 443])['ok'])->toBeTrue()
        ->and(SsrfGuard::check('https://8.8.8.8:8443/')['ok'])->toBeTrue();
});

test('epinglage : l IP verifiee est imposee a curl (CURLOPT_RESOLVE), IPv6 entre crochets', function () {
    expect(SsrfGuard::optionsEpinglage('https://Exemple.test/x', '93.184.216.34'))
        ->toBe(['curl' => [CURLOPT_RESOLVE => ['exemple.test:443:93.184.216.34']]])
        ->and(SsrfGuard::optionsEpinglage('http://exemple.test/x', '2606:4700::1'))
        ->toBe(['curl' => [CURLOPT_RESOLVE => ['exemple.test:80:[2606:4700::1]']]])
        ->and(SsrfGuard::optionsEpinglage('https://exemple.test:8443/', '93.184.216.34'))
        ->toBe(['curl' => [CURLOPT_RESOLVE => ['exemple.test:8443:93.184.216.34']]])
        // Rien à épingler : adresse littérale, ou hôte non résolu.
        ->and(SsrfGuard::optionsEpinglage('https://exemple.test/', null))->toBe([])
        // Une IP littérale n'est pas rendue à épingler.
        ->and(SsrfGuard::verifier('http://8.8.8.8/')['ip'])->toBeNull();
});
