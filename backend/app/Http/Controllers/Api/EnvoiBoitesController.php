<?php

namespace App\Http\Controllers\Api;

use App\Envoi\Boites\AjoutBoite;
use App\Envoi\Creation\ErreurCreation;
use App\Envoi\Smtp\DepotBoites;
use App\Http\Middleware\AuditHashChainLogger;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * T-BOITES (D3) : lecture des boîtes, suspendre et réactiver. Aucune réponse ne porte d'hôte, de port, d'utilisateur ni de secret.
 * Test de connexion et réglages répondent 501 AVANT toute validation, liaison ou lecture. Aucune route ne résout ServeurEnvoi.
 * F3-B1 : l'ajout (`ajouterCode`, `ajouter`) est servi. Permission requise : propriétaire (`workspaces.manage`), 403 sinon.
 */
class EnvoiBoitesController extends ApiController
{
    public function __construct(private readonly DepotBoites $boites) {}

    public function lister(): JsonResponse
    {
        return response()->json(['data' => $this->boites->lister($this->ws())]);
    }

    public function lire(string $id): JsonResponse
    {
        $boite = $this->boites->resumer($this->ws(), $id);
        if ($boite === null) {
            return $this->introuvable();
        }

        return response()->json([
            'boite' => $boite,
            'reponses' => [],
            'reglages' => ['signature' => '', 'mode_absence' => ['actif' => false, 'jusqu_au' => null, 'message' => null]],
            'jours' => [],
            'derniers_refus' => [],
        ]);
    }

    public function suspendre(string $id): JsonResponse
    {
        $ws = $this->ws();
        $this->boites->suspendreManuellement($ws, $id);

        return $this->apres($ws, $id);
    }

    public function reactiver(string $id): JsonResponse
    {
        $ws = $this->ws();
        $r = $this->boites->reactiverSuspensionManuelle($ws, $id);
        if ($r === 'pause_automatique') {
            return response()->json(['error' => 'pause_automatique', 'message' => 'Cette boîte est sous pause de frein ou pause automatique : elle ne peut pas être réactivée depuis l\'écran.'], 409);
        }

        return $this->apres($ws, $id);
    }

    /** POST /envoi/boites/ajout/code : corps SANS secret ; le code part à l'adresse de connexion du propriétaire. */
    public function demanderCodeAjout(Request $request, AjoutBoite $ajout): JsonResponse
    {
        return $this->ajoutAudite($request, '/envoi/boites/ajout/code', function (string $ws, string $uid) use ($request, $ajout) {
            $v = $this->valider($request, false);

            return response()->json($ajout->demanderCode($ws, $uid, (string) $request->user()?->email, $this->demande($v)), 202);
        });
    }

    /** POST /envoi/boites : création avec secret et code ; la boîte naît en pause. Réponse sans secret ni identifiants. */
    public function ajouter(Request $request, AjoutBoite $ajout): JsonResponse
    {
        return $this->ajoutAudite($request, '/envoi/boites', function (string $ws, string $uid) use ($request, $ajout) {
            $v = $this->valider($request, true);

            return response()->json($ajout->creer($ws, $uid, $this->demande($v), (string) $v['secret'], (string) $v['code']), 201);
        });
    }

    /**
     * Le corps porte un secret : le journal HTTP générique est coupé dès l'entrée (il hacherait le corps) ; la ligne d'audit
     * est écrite ici, sans corps, sans secret, sans empreinte du corps.
     *
     * @param  \Closure(string, string): JsonResponse  $f
     */
    private function ajoutAudite(Request $request, string $chemin, \Closure $f): JsonResponse
    {
        $request->attributes->set(AuditHashChainLogger::ATTRIBUT_JOURNAL_PROPRE, true);
        $uid = $request->user()?->id;
        $ws = $this->ws();
        try {
            $r = $f($ws, is_string($uid) ? $uid : '');
            $this->auditer($request, $ws, $chemin, $r->getStatusCode());

            return $r;
        } catch (ValidationException $e) {
            $this->auditer($request, $ws, $chemin, 422);

            throw $e;
        } catch (ErreurCreation $e) {
            $this->auditer($request, $ws, $chemin, $e->statut);

            return response()->json(['error' => $e->erreur, 'message' => $e->getMessage()], $e->statut);
        } catch (\Throwable $e) {
            $this->auditer($request, $ws, $chemin, 500);

            // Message générique : celui d'origine pourrait citer des valeurs liées (secret, code).
            throw new \RuntimeException('Échec de l\'ajout de boîte (' . $e::class . ').');
        }
    }

    /** @return array<string, mixed> */
    private function valider(Request $request, bool $avecSecret): array
    {
        $regles = [
            'adresse' => ['required', 'string', 'max:254', 'email:rfc'],
            'hote' => ['required', 'string', 'max:253'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'chiffrement' => ['required', 'string', 'in:tls,starttls'],
            'utilisateur' => ['required', 'string', 'max:254'],
        ];
        if ($avecSecret) {
            $regles['secret'] = ['required', 'string', 'min:1', 'max:512'];
            $regles['code'] = ['required', 'string', 'regex:/^[0-9]{6}$/'];
        }

        return $request->validate($regles);
    }

    /**
     * @param  array<string, mixed>  $v
     * @return array{adresse: string, hote: string, port: int, chiffrement: string, utilisateur: string}
     */
    private function demande(array $v): array
    {
        return [
            'adresse' => strtolower((string) $v['adresse']), 'hote' => trim((string) $v['hote']), 'port' => (int) $v['port'],
            'chiffrement' => (string) $v['chiffrement'], 'utilisateur' => (string) $v['utilisateur'],
        ];
    }

    private function auditer(Request $request, string $ws, string $chemin, int $statut): void
    {
        try {
            $uid = $request->user()?->id;
            app(AuditHashChain::class)->record([
                'workspace_id' => $ws,
                'user_id' => is_string($uid) ? $uid : null,
                'method' => $request->method(),
                'path' => 'api/v1' . $chemin,
                'status' => $statut,
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'payload_hash' => hash('sha256', '[]'),
            ]);
        } catch (\Throwable) {
            report(new \RuntimeException('Échec de l\'écriture de la ligne d\'audit de l\'ajout de boîte.'));
        }
    }

    /** Test de connexion et réglages : fermés tant qu'il n'y a ni domaine d'envoi ni serveur. Aucun corps lu, aucune base lue. */
    public function serveurReelRequis(): JsonResponse
    {
        return response()->json(['error' => 'serveur_reel_requis', 'message' => 'Indisponible tant qu\'aucun serveur d\'envoi réel n\'est branché : une boîte s\'ajoute par commande locale.'], 501);
    }

    private function apres(string $ws, string $id): JsonResponse
    {
        $boite = $this->boites->resumer($ws, $id);

        return $boite === null ? $this->introuvable() : response()->json($boite);
    }

    private function ws(): string
    {
        return (string) WorkspaceContext::current();
    }

    private function introuvable(): JsonResponse
    {
        return response()->json(['error' => 'introuvable', 'message' => 'Ressource introuvable.'], 404);
    }
}
