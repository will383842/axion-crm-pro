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
|
| ─────────────────────────────────────────────────────────────────────────
| 🔴 LE CAST `(int)` SUR UN UUID AUTORISAIT TOUT LE MONDE (corrigé 2026-08-16)
| ─────────────────────────────────────────────────────────────────────────
|
| Les deux callbacks comparaient ainsi :
|
|     function ($user, int $workspaceId) {
|         return (int) $user->current_workspace_id === $workspaceId;
|     }
|
| Or `workspaces.id` et `users.id` sont des UUID. `(int) 'a1b2c3d4-…'` vaut
| **0**, des DEUX côtés de la comparaison. L'expression était donc
| `0 === 0` — c'est-à-dire **true pour n'importe quel workspace et n'importe
| quel utilisateur**. Le typage `int $workspaceId` du paramètre convertissait
| aussi le segment d'URL, ce qui masquait l'anomalie : rien ne levait d'erreur.
|
| N'importe quel utilisateur authentifié pouvait donc s'abonner au canal privé
| d'un AUTRE workspace, et au canal privé d'un AUTRE utilisateur — et y recevoir
| notifications, résultats de scrape et enrichissements d'entreprises.
|
| Le défaut est aujourd'hui NEUTRALISÉ par le pilote `log` : le bloc entier
| est court-circuité. Il se serait activé à la seconde où Reverb aurait été
| rebranché — c'est-à-dire au moment où personne ne l'aurait cherché.
|
| ⚠️ NE PAS retyper ces paramètres en `int`. Ce sont des UUID, ils se comparent
| en CHAÎNES. `hash_equals` fait une comparaison stricte, à temps constant.
*/

$driver = config('broadcasting.default', 'log');
if (! in_array($driver, ['log', 'null'], true)) {
    Broadcast::channel('workspace.{workspaceId}', function ($user, string $workspaceId): bool {
        return hash_equals((string) $user->current_workspace_id, $workspaceId);
    });

    Broadcast::channel('user.{userId}', function ($user, string $userId): bool {
        return hash_equals((string) $user->id, $userId);
    });
}
