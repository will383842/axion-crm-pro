<?php

namespace App\Models\Scopes;

use App\Exceptions\MissingWorkspaceContextException;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Ceinture applicative de l'étanchéité par workspace (la RLS Postgres est la
 * bretelle : elle tient même si un contrôleur oublie de filtrer).
 *
 * Comportement :
 *   - drapeau `crm.strict_workspace_scope` à false (DÉFAUT) → no-op complet :
 *     le comportement est exactement celui d'avant le lot L0 ;
 *   - drapeau à true + contexte posé  → filtre `workspace_id = <courant>` ;
 *   - drapeau à true + AUCUN contexte → MissingWorkspaceContextException.
 *
 * Ce dernier point est le cœur du lot : sans lui, un job sans contexte
 * verrait zéro ligne et se terminerait en succès sans rien faire.
 */
class WorkspaceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! WorkspaceContext::strictModeEnabled()) {
            return;
        }

        if (WorkspaceContext::isUnscoped()) {
            return;
        }

        $workspaceId = WorkspaceContext::current();

        if ($workspaceId === null || $workspaceId === '') {
            throw MissingWorkspaceContextException::for(
                'lecture/écriture sur ' . $model->getTable(),
            );
        }

        $builder->where($model->qualifyColumn('workspace_id'), $workspaceId);
    }
}
