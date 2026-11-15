<?php

namespace App\Http\Controllers\Api;

use App\Envoi\Conformite\ImportOppositions;
use App\Http\Middleware\AuditHashChainLogger;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * POST /envoi/opposition/import (P1-LN-B, D8) : import des listes de refus en deux temps. Propriétaire seul.
 * Corps `application/json` {etape, contenu, jeton?} : JAMAIS multipart (PHP écrirait le fichier avant le code). Le texte
 * reste en mémoire. Le journal HTTP générique est coupé : il hacherait `contenu` et `jeton` (oracle par dictionnaire) ;
 * la ligne d'audit est écrite ici, sans le corps ni son empreinte.
 */
class EnvoiOppositionImportController extends ApiController
{
    public function __invoke(Request $request, ImportOppositions $import): JsonResponse
    {
        $request->attributes->set(AuditHashChainLogger::ATTRIBUT_JOURNAL_PROPRE, true);
        $v = $request->validate([
            'etape' => ['required', 'string', 'in:simulation,confirmation'],
            'contenu' => ['required', 'string'],
            'jeton' => ['nullable', 'string', 'max:64'],
        ]);
        $ws = (string) WorkspaceContext::current();
        $uid = $request->user()?->id;
        $auteur = is_string($uid) ? $uid : 'inconnu';
        try {
            $r = $v['etape'] === 'simulation'
                ? $import->simuler($ws, $auteur, $v['contenu'])
                : $import->confirmer($ws, $auteur, $v['contenu'], $v['jeton'] ?? null);
        } catch (ConflictHttpException $e) {
            $this->auditer($request, $ws, $v['etape'], 409, '');

            return response()->json(['message' => $e->getMessage()], 409);
        }
        $this->auditer($request, $ws, $v['etape'], 200, 'valides=' . $r['valides'] . ' deja=' . $r['deja_presentes'] . ' refusees=' . count($r['refusees'])
            . (isset($r['import_id']) ? ' import_id=' . $r['import_id'] : ''));

        return response()->json($r);
    }

    private function auditer(Request $request, string $ws, string $etape, int $statut, string $nombres): void
    {
        try {
            $uid = $request->user()?->id;
            app(AuditHashChain::class)->record([
                'workspace_id' => $ws,
                'user_id' => is_string($uid) ? $uid : null,
                'method' => $request->method(),
                'path' => trim('api/v1/envoi/opposition/import | etape=' . $etape . ' ' . $nombres),
                'status' => $statut,
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'payload_hash' => hash('sha256', '[]'),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
