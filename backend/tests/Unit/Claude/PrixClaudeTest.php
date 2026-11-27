<?php

use App\Crm\Claude\PrixClaude;

it('calcule le coût exact en micro-dollars sur un usage fixé', function () {
    $r = PrixClaude::cout('claude-haiku-5-5', [
        'input_tokens' => 2000,
        'output_tokens' => 100,
        'cache_creation_input_tokens' => 1000,
        'cache_read_input_tokens' => 10000,
        'server_tool_use' => ['web_search_requests' => 1],
    ]);

    // 2000 x 1 + 100 x 5 + 1000 x 1,25 (arrondi haut) + 10000 x 0,1 + 1 recherche.
    expect($r['micro'])->toBe(2000 + 500 + 1250 + 1000 + 10_000)
        ->and($r['anomalie'])->toBeFalse();
});

it('applique le tarif fort et lève le drapeau au-delà de 100 000 jetons en entrée', function () {
    $normal = PrixClaude::cout('claude-haiku-5-5', ['input_tokens' => 100_000, 'output_tokens' => 0]);
    $fort = PrixClaude::cout('claude-haiku-5-5', ['input_tokens' => 100_001, 'output_tokens' => 0]);

    expect($normal['micro'])->toBe(100_000)->and($normal['anomalie'])->toBeFalse()
        ->and($fort['anomalie'])->toBeTrue()
        ->and($fort['micro'])->toBe(200_002);
});

it('refuse tout modèle autre que Haiku 5.5 (jamais un prix à 0)', function () {
    PrixClaude::cout('claude-sonnet-5-5', ['input_tokens' => 10, 'output_tokens' => 1]);
})->throws(InvalidArgumentException::class);

it('plafonne le total du chantier par la constante, la config ne peut que le baisser', function () {
    expect(PrixClaude::plafondTotalMicro(null))->toBe(PrixClaude::PLAFOND_TOTAL_MICRO)
        ->and(PrixClaude::plafondTotalMicro(50.0))->toBe(50_000_000)
        ->and(PrixClaude::plafondTotalMicro(9999.0))->toBe(PrixClaude::PLAFOND_TOTAL_MICRO);
});
