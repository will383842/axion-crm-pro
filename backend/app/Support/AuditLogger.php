<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sprint H4 — Helper centralisé pour logger les events business waterfall
 * dans la table business_events (distincte de audit_logs hash-chain).
 *
 * Usage :
 *   AuditLogger::log('audience.refreshed', [
 *       'workspace_id' => $audience->workspace_id,
 *       'resource_type'=> 'audience',
 *       'resource_id'  => (string) $audience->id,
 *       'member_count' => $audience->member_count,
 *   ]);
 *
 * Fail-open : si insert échoue (table absente en rollback, FK violation…),
 * on Log::warning mais on ne propage jamais (un audit log ne doit pas
 * casser une opération business réussie).
 */
class AuditLogger
{
    /**
     * @param  array{
     *   workspace_id?: string,
     *   resource_type?: string,
     *   resource_id?: string|int,
     *   actor_user_id?: string|null,
     *   ...
     * }  $context
     */
    public static function log(string $action, array $context): void
    {
        $workspaceId = $context['workspace_id'] ?? null;
        if (! $workspaceId) {
            // Sans workspace impossible de respecter la RLS — on skip silencieux.
            Log::debug('AuditLogger skipped: no workspace_id', ['action' => $action]);
            return;
        }

        $resourceType = $context['resource_type'] ?? null;
        $resourceId   = isset($context['resource_id']) ? (string) $context['resource_id'] : null;
        $actorUserId  = $context['actor_user_id'] ?? self::resolveActor();

        // Le payload context = tout le reste (on retire les colonnes dédiées)
        $payload = $context;
        unset(
            $payload['workspace_id'],
            $payload['resource_type'],
            $payload['resource_id'],
            $payload['actor_user_id'],
        );

        try {
            DB::table('business_events')->insert([
                'workspace_id'  => $workspaceId,
                'actor_user_id' => $actorUserId,
                'action'        => $action,
                'resource_type' => $resourceType,
                'resource_id'   => $resourceId,
                'context'       => empty($payload) ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
                'created_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('AuditLogger insert failed', [
                'action' => $action,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    private static function resolveActor(): ?string
    {
        try {
            $user = Auth::user();
            return $user?->id !== null ? (string) $user->id : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
