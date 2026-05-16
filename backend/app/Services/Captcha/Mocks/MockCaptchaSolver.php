<?php

namespace App\Services\Captcha\Mocks;

use App\Contracts\CaptchaSolver;

class MockCaptchaSolver implements CaptchaSolver
{
    public function solve(array $payload, int $timeoutS = 60): string
    {
        return 'mock-captcha-token-' . substr(sha1(json_encode($payload)), 0, 12);
    }
}
