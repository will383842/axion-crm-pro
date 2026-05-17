<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Sprint 18.9 — défensif : on n'enregistre les private channels que si un
| broadcaster real-time est actif (reverb / pusher). Avec driver=log ou null,
| ces appels feraient un no-op mais peuvent encore résoudre BroadcastManager
| qui essaie d'instancier Pusher\Pusher avec key=null → crash boot.
|
| Quand le broadcaster repasse à 'reverb' (cf. config/broadcasting.php), les
| channels sont automatiquement réenregistrés au prochain boot.
*/

$driver = config('broadcasting.default', 'log');
if (! in_array($driver, ['log', 'null'], true)) {
    Broadcast::channel('workspace.{workspaceId}', function ($user, int $workspaceId) {
        return (int) $user->current_workspace_id === $workspaceId;
    });

    Broadcast::channel('user.{userId}', function ($user, int $userId) {
        return (int) $user->id === $userId;
    });
}
