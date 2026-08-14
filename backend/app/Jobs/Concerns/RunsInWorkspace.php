<?php

namespace App\Jobs\Concerns;

use App\Exceptions\MissingWorkspaceContextException;
use App\Support\WorkspaceContext;
use Closure;

/**
 * À utiliser par tout job / toute commande artisan qui touche des données
 * workspace-scopées.
 *
 * Le middleware SetCurrentWorkspace ne couvre QUE le HTTP : hors requête, il
 * n'existe aucun contexte. Sans ce garde-fou, un job de purge verrait zéro
 * ligne sous RLS stricte et sortirait en succès sans avoir rien purgé.
 *
 * Usage :
 *
 *     class PurgeCandidatsJob implements ShouldQueue
 *     {
 *         use RunsInWorkspace;
 *
 *         public function handle(): void
 *         {
 *             $this->inWorkspace($this->workspaceId, function () {
 *                 // ... requêtes scopées ...
 *             });
 *         }
 *     }
 */
trait RunsInWorkspace
{
    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function inWorkspace(?string $workspaceId, Closure $callback)
    {
        if ($workspaceId === null || $workspaceId === '') {
            throw MissingWorkspaceContextException::for(static::class);
        }

        return WorkspaceContext::run($workspaceId, $callback);
    }
}
