<?php

namespace App\Http\Controllers\Api;

use App\Envoi\Smtp\DepotBoites;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;

/**
 * T-BOITES (D3) : lecture des boîtes, suspendre et réactiver. Aucune réponse ne porte d'hôte, de port, d'utilisateur ni de secret.
 * Ajout, test de connexion et réglages répondent 501 AVANT toute validation, liaison ou lecture. Aucune route ne résout ServeurEnvoi.
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

    /** Ajout, test de connexion et réglages : fermés tant qu'il n'y a ni domaine d'envoi ni serveur. Aucun corps lu, aucune base lue. */
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
