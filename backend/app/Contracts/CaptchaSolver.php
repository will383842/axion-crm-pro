<?php

namespace App\Contracts;

interface CaptchaSolver
{
    /**
     * @param  array<string,mixed>  $payload (siteKey, pageUrl, type)
     * @return string  token solved
     */
    public function solve(array $payload, int $timeoutS = 60): string;
}
