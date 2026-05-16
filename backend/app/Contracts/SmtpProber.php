<?php

namespace App\Contracts;

use App\Data\Email\SmtpProbeResult;

interface SmtpProber
{
    public function probe(string $email): SmtpProbeResult;
}
