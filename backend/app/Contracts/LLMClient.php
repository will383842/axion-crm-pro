<?php

namespace App\Contracts;

use App\Data\LLM\LLMRequestData;
use App\Data\LLM\LLMResponseData;

interface LLMClient
{
    public function complete(LLMRequestData $request): LLMResponseData;
}
