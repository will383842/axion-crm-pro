<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditLog;
use App\Services\Audit\AuditHashChain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AuditLogsController extends ApiController
{
    public function __construct(private readonly AuditHashChain $chain) {}

    /**
     * @OA\Get(path="/audit-logs", tags={"AuditLogs"}, summary="Audit logs paginés (hash-chained)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Response(response=200, description="OK"))
     */
    public function index(Request $r): JsonResponse
    {
        if (! Schema::hasTable('audit_logs')) {
            return $this->ok(['data' => []]);
        }

        // 🔴 CLOISONNEMENT PAR ESPACE DE TRAVAIL — constats B16-004 et P5-35-007.
        //
        // Cette requete etait `AuditLog::query()`, sans aucun filtre : le
        // journal de TOUS les espaces etait rendu a quiconque franchissait la
        // route. Un premier correctif a pose la permission `audit.view`, ce qui
        // a repousse le compte en lecture seule — mais une permission separe
        // les ROLES, jamais les CLIENTS. L'administrateur de l'espace A lisait
        // toujours le journal de l'espace B.
        //
        // Le filtre est pose ICI, explicitement, et non par le global scope :
        // `AuditLog` ne porte pas `BelongsToWorkspace`, et le drapeau
        // `crm.strict_workspace_scope` est eteint. Un cloisonnement qui depend
        // d'un drapeau eteint n'est pas un cloisonnement.
        //
        // Sans contexte d'espace, on ne rend RIEN : sur un journal d'audit, le
        // doute se tranche en faveur du silence, jamais de la divulgation.
        $espaceCourant = app()->bound('workspace.id') ? (string) app('workspace.id') : null;

        try {
            $page = AuditLog::query()
                ->when(
                    $espaceCourant !== null && $espaceCourant !== '',
                    fn ($q) => $q->where('workspace_id', $espaceCourant),
                    fn ($q) => $q->whereRaw('1 = 0'),
                )
                ->orderByDesc('id')
                ->paginate(50);

            return $this->ok([
                'data' => $page->items(),
                'meta' => [
                    'total' => $page->total(),
                    'per_page' => $page->perPage(),
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('audit-logs.index failed', ['exception' => $e->getMessage()]);
            report($e);

            return $this->ok(['data' => [], 'degraded' => true]);
        }
    }

    /**
     * @OA\Get(path="/audit-logs/verify-chain", tags={"AuditLogs"}, summary="Vérifie l'intégrité de la chaîne de hashs SHA-256",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Response(response=200, description="Booléen valid"))
     */
    public function verifyChain(): JsonResponse
    {
        try {
            // On rend la RAISON, pas seulement le verdict. Un `valid: false` muet
            // envoie chercher une falsification la ou il n'y a qu'une variable
            // d'environnement absente - et inversement, un `valid: true` sans
            // secret utilisable serait un mensonge (B16-001).
            $secretUtilisable = $this->chain->secretEstUtilisable();

            return $this->ok([
                'valid' => $this->chain->verifyChain(),
                'verifiable' => $secretUtilisable,
                'raison' => $secretUtilisable ? null : $this->chain->raisonSecretInutilisable(),
            ]);
        } catch (\Throwable $e) {
            Log::error('audit-logs.verify-chain failed', ['exception' => $e->getMessage()]);
            report($e);

            return $this->ok(['valid' => false, 'degraded' => true]);
        }
    }
}
