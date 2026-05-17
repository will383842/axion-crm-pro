<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sprint 18.3 — broadcast d'une notification in-app.
 * Émis par NotificationsController::create ou par les workers (jobs system).
 */
class NotificationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $workspaceId,
        public readonly int $notificationId,
        public readonly string $type,
        public readonly string $title,
        public readonly string $body,
        public readonly string $severity = 'info',     // info|success|warning|error
        public readonly ?string $actionUrl = null,
        public readonly ?string $userId = null,         // null = workspace-wide
    ) {}

    public function broadcastOn(): array
    {
        // Si userId fourni → channel privé user. Sinon → workspace.
        if ($this->userId !== null) {
            return [new PrivateChannel('user.' . $this->userId)];
        }
        return [new PrivateChannel('workspace.' . $this->workspaceId)];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function broadcastWith(): array
    {
        return [
            'notification_id' => $this->notificationId,
            'type'            => $this->type,
            'title'           => $this->title,
            'body'            => $this->body,
            'severity'        => $this->severity,
            'action_url'      => $this->actionUrl,
            'occurred_at'     => now()->toIso8601String(),
        ];
    }
}
