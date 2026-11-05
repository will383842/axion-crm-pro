<?php

/** L7 — le secret est un paramètre sensible : absent des traces d'exception. */

use App\Crm\Usine\ClientUsine;
use App\Crm\Usine\SignatureAgregats;

test('le secret est marqué #[\SensitiveParameter] (ClientUsine::__construct, SignatureAgregats::signer)', function () {
    foreach ([[ClientUsine::class, '__construct'], [SignatureAgregats::class, 'signer']] as [$classe, $methode]) {
        $param = collect((new ReflectionMethod($classe, $methode))->getParameters())->first(fn ($p) => $p->getName() === 'secret');
        expect($param)->not->toBeNull()
            ->and($param->getAttributes(SensitiveParameter::class))->toHaveCount(1);
    }
});
