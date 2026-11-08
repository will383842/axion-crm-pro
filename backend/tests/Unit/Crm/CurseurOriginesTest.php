<?php

use App\Crm\Origines\RequetesOrigines;

test('le curseur reste décodable pour une date longue ou contenant \\x1f', function () {
    foreach (['2026-11-03T10:00:00Z', str_repeat('x', 200), "a\x1fb", "é\"\\/\x00"] as $date) {
        $c = RequetesOrigines::encoderCurseur($date, -3);
        expect($c)->toMatch('/^[A-Za-z0-9_-]{1,2200}$/')
            ->and(RequetesOrigines::decoderCurseur($c))->toBe([$date, -3]);
    }
});

test('la lecture reste stricte : formes invalides refusées', function () {
    $b = static fn (string $j): string => rtrim(strtr(base64_encode($j), '+/', '-_'), '=');
    foreach (['pas-du-json', $b('[1,2]'), $b('["a"]'), $b('["a",1,2]'), $b('{"0":"a","1":1}'), $b('["a","1"]'), $b('["' . str_repeat('x', 256) . '",1]')] as $c) {
        expect(RequetesOrigines::decoderCurseur($c))->toBeNull();
    }
    expect(RequetesOrigines::decoderCurseur('forge;drop'))->toBeNull();
});
