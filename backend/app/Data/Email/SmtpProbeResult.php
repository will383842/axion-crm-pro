<?php

namespace App\Data\Email;

use Spatie\LaravelData\Data;

class SmtpProbeResult extends Data
{
    public function __construct(
        public string $email,
        public string $status,          // valid|invalid|catchall|unknown|disposable|role
        public int $score = 0,          // 0-100
        public ?string $mxHost = null,
        public ?string $message = null,
        public bool $isCatchAll = false,
        public bool $isDisposable = false,
        public bool $isRole = false,
    ) {}
}
