<?php

namespace App\Models\Concerns;

use App\Models\Scopes\WorkspaceScope;
use App\Support\WorkspaceContext;

/**
 * À appliquer sur tout modèle porteur d'une colonne `workspace_id`.
 *
 * Ajoute :
 *   - le global scope WorkspaceScope (inerte tant que
 *     `crm.strict_workspace_scope` est à false) ;
 *   - le remplissage automatique de `workspace_id` à la création quand le
 *     contexte est posé et que la valeur n'a pas été fournie explicitement.
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope);

        static::creating(function ($model): void {
            if ($model->getAttribute('workspace_id') !== null) {
                return;
            }
            if (! WorkspaceContext::strictModeEnabled()) {
                return;
            }
            $current = WorkspaceContext::current();
            if ($current !== null) {
                $model->setAttribute('workspace_id', $current);
            }
        });
    }
}
