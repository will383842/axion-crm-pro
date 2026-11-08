<?php

namespace App\Socle\Alertes;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** Diffusion de `.notification.created` pour une ligne de la cloche (identifiant UUID). */
final class NotificationCreee implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $userId,
        public readonly string $notificationId,
        public readonly string $titre,
        public readonly string $corps,
        public readonly string $produit,
        public readonly string $gravite,
        public readonly ?string $lien,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.' . $this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'notification_id' => $this->notificationId,
            'title' => $this->titre,
            'body' => $this->corps,
            'produit' => $this->produit,
            'severity' => $this->gravite === 'critique' ? 'error' : ($this->gravite === 'attention' ? 'warning' : 'info'),
            'action_url' => $this->lien,
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
