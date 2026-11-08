<?php

namespace App\Envoi\Smtp;

/** Alerte : 3 refus définitifs (5xx) en 10 minutes, la boîte est mise en pause. Aucun secret. */
final class BoitePauseAutomatique
{
    public function __construct(
        public readonly string $workspaceId,
        public readonly string $boiteId,
        public readonly int $nombre5xx,
    ) {}
}
