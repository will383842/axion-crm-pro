<?php

namespace App\Http\Controllers\Api;

use App\Envoi\Conformite\ImportOppositions;
use App\Exceptions\DelaiRequeteDepassee;
use App\Http\Middleware\AuditHashChainLogger;
use App\Services\Audit\AuditHashChain;
use App\Support\DelaiRequeteSql;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
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
        $ws = (string) WorkspaceContext::current();
        $etapeBrute = $request->input('etape');
        $etapeAudit = in_array($etapeBrute, ['simulation', 'confirmation'], true) ? (string) $etapeBrute : 'invalide';
        try {
            $v = $request->validate([
                'etape' => ['required', 'string', 'in:simulation,confirmation'],
                'contenu' => ['required', 'string'],
                'jeton' => ['nullable', 'string', 'max:64'],
            ]);
        } catch (ValidationException $e) {
            $this->auditer($request, $ws, $etapeAudit, 422, '');

            throw $e;
        }
        $uid = $request->user()?->id;
        $auteur = is_string($uid) ? $uid : 'inconnu';
        try {
            $r = $v['etape'] === 'simulation'
                ? $import->simuler($ws, $auteur, $v['contenu'])
                : $import->confirmer($ws, $auteur, $v['contenu'], $v['jeton'] ?? null);
        } catch (ConflictHttpException $e) {
            $this->auditer($request, $ws, $v['etape'], 409, '');

            return response()->json(['message' => $e->getMessage()], 409);
        } catch (ValidationException $e) {
            $this->auditer($request, $ws, $v['etape'], 422, '');

            throw $e;
        } catch (QueryException $e) {
            // Un délai SQL dépassé (57014) : exception dédiée sans SQL ni précédente, rendue en 503 par le gestionnaire global.
            if (DelaiRequeteSql::estDepassement($e)) {
                $this->auditer($request, $ws, $v['etape'], 503, '');

                throw new DelaiRequeteDepassee;
            }
            // Le message SQL contient les valeurs liées (jeton, empreintes) : seul un message générique part au journal.
            $this->auditer($request, $ws, $v['etape'], 500, '');

            throw new RuntimeException('Échec de l’import des listes de refus (erreur base de données).');
        } catch (\Throwable $e) {
            $this->auditer($request, $ws, $v['etape'], 500, '');

            throw $e;
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
        } catch (\Throwable) {
            report(new RuntimeException('Échec de l’écriture de la ligne d’audit de l’import.'));
        }
    }
}
