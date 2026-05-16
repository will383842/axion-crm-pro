<?php

namespace App\Services\Smtp;

use App\Contracts\SmtpProber;
use App\Data\Email\SmtpProbeResult;

class RealSmtpProber implements SmtpProber
{
    public function probe(string $email): SmtpProbeResult
    {
        throw new \LogicException('RealSmtpProber requires MOCK_SMTP=false + Sprint 8 implementation.');
    }
}
