<?php

namespace App\Http\Controllers\Api;

use App\Envoi\Dns\ResolveurDns;
use App\Envoi\Dns\ResolveurDnsSysteme;
use App\Envoi\Infrastructure\EcrituresInfrastructure;
use App\Envoi\Infrastructure\LectureInfrastructure;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * R15 : écritures LIMITÉES (propriétaire seul). Aucune route ne change un DNS ni un serveur, aucune n'envoie.
 * `appliquer`, `revenir` et `serveurs/{id}/test` restent fermés (409) : ils ne référencent ni l'Applicateur ni ServeurEnvoi.
 * Les erreurs ne citent jamais la saisie.
 */
class EnvoiInfrastructureEcrituresController extends ApiController
{
    public function __construct(private readonly EcrituresInfrastructure $ecritures, private readonly LectureInfrastructure $lecture) {}

    public function ajouterDomaine(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), ['nom' => ['required', 'string', 'max:600'], 'flux' => ['required', 'in:prospection,lettre']]);
        if ($v->fails()) {
            return $this->invalide('Le nom du domaine et le flux (prospection ou lettre) sont attendus.');
        }
        $ws = $this->ws();
        try {
            $r = $this->ecritures->ajouterDomaine($ws, (string) $request->input('nom'), (string) $request->input('flux'));
        } catch (InvalidArgumentException) {
            return $this->invalide('Ce nom n\'est pas un domaine public valide (adresse IP, domaine interne ou réservé, nom trop long).');
        }

        return response()->json(['domaine' => $this->lecture->domaine($ws, (string) $r['id'])['domaine'] ?? null, 'dns' => $r['dns']], $r['cree'] ? 201 : 200);
    }

    public function verifierDns(string $id, ResolveurDns $dns): JsonResponse
    {
        $r = $this->ecritures->verifierDns($this->ws(), (int) $id, $dns, new DateTimeImmutable('now'));

        return $r === null ? $this->introuvable() : response()->json($r);
    }

    public function rappelDmarc(Request $request, string $id): JsonResponse
    {
        $v = Validator::make($request->all(), ['politique' => ['required', 'in:none,quarantine,reject'], 'rappel_le' => ['nullable', 'date', 'after:now', 'before:+2 years']]);
        if ($v->fails()) {
            return $this->invalide('Une politique (none, quarantine ou reject) est attendue ; le rappel, s\'il est donné, doit être une date future.');
        }
        $ws = $this->ws();
        $quand = $request->filled('rappel_le') ? new DateTimeImmutable((string) $request->input('rappel_le')) : null;
        $rappel = $this->ecritures->rappelDmarc($ws, (int) $id, (string) $request->input('politique'), $quand, (string) $request->user()?->id);
        if ($rappel === null) {
            return $this->introuvable();
        }

        return response()->json([
            'domaine' => $this->lecture->domaine($ws, $id)['domaine'] ?? null, 'rappel' => $rappel, 'applique' => false,
            'message' => 'Rappel enregistré. La politique DMARC se change dans le DNS, à la main : rien n\'a été publié.',
        ]);
    }

    public function ajouterAdresseIp(Request $request, string $id): JsonResponse
    {
        $v = Validator::make($request->all(), ['adresse' => ['required', 'string', 'max:45'], 'flux' => ['required', 'in:prospection,lettre'], 'domaine_id' => ['required', 'integer', 'min:1']]);
        if ($v->fails() || ! ResolveurDnsSysteme::adressePublique((string) $request->input('adresse'))) {
            return $this->invalide('Une adresse IP publique, un flux (prospection ou lettre) et un domaine sont attendus.');
        }
        $ws = $this->ws();
        $r = $this->ecritures->preparerAdresseIp($ws, (int) $id, (int) $request->input('domaine_id'), (string) $request->input('flux'));

        return match ($r) {
            'ok' => response()->json($this->lecture->configuration($ws)),
            'serveur', 'domaine' => $this->introuvable(),
            'flux' => $this->invalide('Le flux de l\'adresse doit être celui du domaine.'),
            default => $this->invalide('Le paquet de configuration est refusé par la validation : rien n\'est préparé.'),
        };
    }

    /** Fermé : un test de serveur enverrait. Le serveur doit toutefois être de cet espace (sinon 404). */
    public function testerServeur(string $id): JsonResponse
    {
        return $this->lecture->santeServeur($this->ws(), $id) === null ? $this->introuvable() : $this->ferme();
    }

    /** Fermé (appliquer) : seul geste qui changerait le vrai serveur ; reste fermé tant qu'un ADR de K17 ne l'ouvre pas. */
    public function appliquer(): JsonResponse
    {
        return $this->ferme();
    }

    /** Fermé (revenir) : même raison. */
    public function revenir(string $numero): JsonResponse
    {
        return $this->ferme();
    }

    private function ws(): string
    {
        return (string) WorkspaceContext::current();
    }

    private function ferme(): JsonResponse
    {
        return response()->json(['error' => 'envoi_ferme', 'message' => 'Envoi fermé : ce geste n\'est pas disponible au palier 0.'], 409);
    }

    private function invalide(string $message): JsonResponse
    {
        return response()->json(['error' => 'validation', 'message' => $message], 422);
    }

    private function introuvable(): JsonResponse
    {
        return response()->json(['error' => 'introuvable', 'message' => 'Ressource introuvable.'], 404);
    }
}
