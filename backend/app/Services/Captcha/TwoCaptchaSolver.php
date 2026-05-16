<?php

namespace App\Services\Captcha;

use App\Contracts\CaptchaSolver;

class TwoCaptchaSolver implements CaptchaSolver
{
    public function solve(array $payload, int $timeoutS = 60): string
    {
        throw new \LogicException('TwoCaptchaSolver requires MOCK_CAPTCHA=false + Sprint 7 implementation.');
    }
}
