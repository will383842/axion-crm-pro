<?php

namespace App\Envoi\Configuration;

use Illuminate\Support\Facades\Log;

final class AlerteDeriveLog implements AlerteDerive
{
    public function signaler(string $workspaceId, array $cles): void
    {
        Log::critical('envoi.config.derive_socle', ['workspace' => $workspaceId, 'cles' => $cles]);
    }
}
