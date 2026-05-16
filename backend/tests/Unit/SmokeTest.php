<?php

use App\Data\LLM\LLMRequestData;
use App\Data\LLM\LLMResponseData;
use App\Services\LLM\Mocks\MockLLMClient;

test('mock LLM client returns a generic response for known use case', function () {
    $client = new MockLLMClient();
    $resp = $client->complete(new LLMRequestData(useCaseSlug: 'classify_company_axion'));

    expect($resp)->toBeInstanceOf(LLMResponseData::class);
    expect($resp->providerUsed)->toBe('mock');
    expect($resp->asJson())->toBeArray();
    expect($resp->costEur)->toBe(0.0);
});

test('LLMResponseData decodes JSON text via asJson()', function () {
    $resp = new LLMResponseData(
        text: '{"foo":42}',
        providerUsed: 'mock',
        modelUsed: 'm',
    );
    expect($resp->asJson())->toBe(['foo' => 42]);
});
