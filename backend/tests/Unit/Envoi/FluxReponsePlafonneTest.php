<?php

use App\Envoi\Serveur\FluxReponsePlafonne;

test('m2 : le flux compte les octets reçus et refuse d’en écrire plus que le plafond (même sans Content-Length)', function () {
    $f = new FluxReponsePlafonne(10);
    expect($f->write('12345'))->toBe(5)->and($f->write('67890'))->toBe(5)->and($f->depasse())->toBeFalse()
        ->and($f->write('x'))->toBe(0)               // 0 octet accepté : curl interrompt le transfert
        ->and($f->depasse())->toBeTrue()
        ->and((string) $f)->toBe('1234567890');      // rien au-delà du plafond n'est conservé
});

test('m2 : un seul gros bloc dépassant le plafond est refusé en entier', function () {
    $f = new FluxReponsePlafonne(4);
    expect($f->write('123456'))->toBe(0)->and($f->depasse())->toBeTrue()->and((string) $f)->toBe('');
});
