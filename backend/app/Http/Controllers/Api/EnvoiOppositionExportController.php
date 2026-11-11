<?php

namespace App\Http\Controllers\Api;

use App\Envoi\Conformite\ExporteurOppositions;
use App\Http\Middleware\AuditHashChainLogger;
use App\Services\Audit\AuditHashChain;
use App\Support\MasquageCoordonnees;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /envoi/opposition/export (lot R05). Propriétaire (`workspaces.manage`) ET `data.export`, 2 par heure (routes).
 * Flux CSV ; UNE ligne d'audit écrite dans `finally` (qui, quand, combien de lignes ; jamais d'adresse), même si le
 * client coupe. Lecture seule : aucune suppression, aucune écriture dans le registre.
 */
class EnvoiOppositionExportController extends ApiController
{
    public function __invoke(Request $request, ExporteurOppositions $exporteur): StreamedResponse
    {
        $ws = (string) WorkspaceContext::current();
        abort_if($ws === '', 404);
        $voir = ! MasquageCoordonnees::requis();
        // L'export écrit lui-même sa ligne d'audit (avec le compte) : le journal HTTP générique s'efface.
        $request->attributes->set(AuditHashChainLogger::ATTRIBUT_JOURNAL_PROPRE, true);

        return response()->streamDownload(function () use ($request, $exporteur, $ws, $voir): void {
            ignore_user_abort(true);
            $lignes = 0;
            $sortie = fopen('php://output', 'w');
            if ($sortie === false) {
                throw new RuntimeException('Export CSV : impossible d’ouvrir la sortie.');
            }
            try {
                $lignes = $exporteur->ecrire($ws, $sortie, $voir);
            } finally {
                fclose($sortie);
                $this->auditer($request, $ws, $lignes);
            }
        }, 'registre-opposition-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    private function auditer(Request $request, string $ws, int $lignes): void
    {
        try {
            $uid = $request->user()?->id;
            app(AuditHashChain::class)->record([
                'workspace_id' => $ws,
                'user_id' => is_string($uid) ? $uid : null,
                'method' => $request->method(),
                'path' => 'api/v1/envoi/opposition/export | lignes=' . $lignes,
                'status' => 200,
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'payload_hash' => hash('sha256', '[]'),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
