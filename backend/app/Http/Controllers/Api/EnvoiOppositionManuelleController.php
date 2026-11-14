<?php

namespace App\Http\Controllers\Api;

use App\Envoi\Conformite\RegistreOppositionManuel;
use App\Http\Middleware\AuditHashChainLogger;
use App\Services\Audit\AuditHashChain;
use App\Support\MasquageCoordonnees;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * POST /envoi/opposition (lot R13) : inscrit une adresse, un SIREN ou un domaine au registre manuel. Propriétaire seul.
 * 201 à la création, 200 si déjà inscrit (aucun doublon). N'envoie rien, ne retire rien. L'audit écrit la portée,
 * jamais la valeur.
 */
class EnvoiOppositionManuelleController extends ApiController
{
    public function __invoke(Request $request, RegistreOppositionManuel $registre): JsonResponse
    {
        // La valeur peut être une adresse : le journal HTTP générique s'efface, la ligne d'audit est écrite ici.
        $request->attributes->set(AuditHashChainLogger::ATTRIBUT_JOURNAL_PROPRE, true);
        $v = $request->validate([
            'portee' => ['required', 'string', 'in:' . implode(',', RegistreOppositionManuel::PORTEES)],
            'valeur' => ['required', 'string', 'max:254'],
            'motif' => ['required', 'string', 'min:1', 'max:500', 'not_regex:/@/'],
        ], ['motif.not_regex' => 'Le motif ne doit contenir aucune adresse e-mail.']);

        $ws = (string) WorkspaceContext::current();
        $uid = $request->user()?->id;
        try {
            ['ligne' => $ligne, 'creee' => $creee] = $registre->inscrire($ws, $v['portee'], $v['valeur'], $v['motif'], is_string($uid) ? $uid : 'inconnu');
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['valeur' => [$e->getMessage()]])->errorBag('default')->status(422);
        }
        $this->auditer($request, $ws, $v['portee'], $creee ? 201 : 200);

        $valeur = $ligne->portee === 'adresse'
            ? (string) MasquageCoordonnees::email(Crypt::decryptString((string) $ligne->valeur_chiffree))
            : (string) $ligne->valeur;

        return response()->json([
            'id' => (string) $ligne->id,
            'portee' => (string) $ligne->portee,
            'valeur' => $valeur,
            'motif' => (string) $ligne->motif,
            'inscrite_le' => Carbon::parse((string) $ligne->cree_le)->toIso8601String(),
            'source' => (string) $ligne->source,
        ], $creee ? 201 : 200);
    }

    private function auditer(Request $request, string $ws, string $portee, int $statut): void
    {
        try {
            $uid = $request->user()?->id;
            app(AuditHashChain::class)->record([
                'workspace_id' => $ws,
                'user_id' => is_string($uid) ? $uid : null,
                'method' => $request->method(),
                'path' => 'api/v1/envoi/opposition | portee=' . $portee,
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
