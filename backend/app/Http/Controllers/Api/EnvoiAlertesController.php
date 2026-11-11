<?php

namespace App\Http\Controllers\Api;

use App\Envoi\Reglages\DepotAlertes;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * R14a : alertes d'envoi et règles d'alerte (contrat openapi/envoi/conformite.yaml). Aucune route ici n'écrit
 * `envoi_pauses` ni `envoi_drapeaux`, et aucune ne résout `ServeurEnvoi`.
 */
class EnvoiAlertesController extends ApiController
{
    public function index(Request $r, DepotAlertes $depot): JsonResponse
    {
        return response()->json(['data' => $depot->lister($this->ws(), (string) $r->user()?->getKey())]);
    }

    public function regler(Request $r, string $id, DepotAlertes $depot): JsonResponse
    {
        $alerte = $depot->regler($this->ws(), (string) $r->user()?->getKey(), $id, (string) ($r->user()?->name ?? 'inconnu'));

        return $alerte === null
            ? response()->json(['error' => 'non_trouve', 'message' => 'Cette alerte n’existe pas.'], 404)
            : response()->json($alerte);
    }

    public function regles(DepotAlertes $depot): JsonResponse
    {
        return response()->json($this->reponse($depot));
    }

    public function enregistrer(Request $r, DepotAlertes $depot): JsonResponse
    {
        $d = $r->json()->all();
        $erreur = fn (string $m, int $s = 422, string $c = 'validation'): JsonResponse => response()->json(['error' => $c, 'message' => $m], $s);
        if (! is_array($d['data'] ?? null) || ($d['regroupement_minutes'] ?? null) !== DepotAlertes::REGROUPEMENT_MINUTES) {
            return $erreur('Les règles sont illisibles, ou le regroupement n’est pas de 15 minutes.');
        }
        // Une seconde adresse est un relais d'e-mail potentiel : refusée tant que la confirmation par code n'existe pas.
        if (($d['seconde_adresse'] ?? null) !== null) {
            return $erreur('La seconde adresse d’alerte exige une confirmation par code, pas encore disponible.', 501, 'confirmation_requise');
        }
        $regles = [];
        foreach ($d['data'] as $l) {
            $canaux = $l['canaux'] ?? null;
            if (! is_array($l) || ! is_string($l['condition'] ?? null) || ! array_key_exists($l['condition'], DepotAlertes::TYPES)
                || ! is_string($l['nom'] ?? null) || trim($l['nom']) === '' || mb_strlen($l['nom']) > 120
                || ! is_bool($l['actif'] ?? null) || ! is_array($canaux) || array_diff($canaux, DepotAlertes::CANAUX) !== []
                || (isset($l['seuil']) && ! is_numeric($l['seuil']))) {
                return $erreur('Une règle d’alerte est invalide (type, nom, canaux console ou e-mail, actif).');
            }
            $regles[] = ['condition' => $l['condition'], 'nom' => trim($l['nom']), 'seuil' => isset($l['seuil']) ? (float) $l['seuil'] : null, 'canaux' => array_values(array_unique($canaux)), 'actif' => $l['actif']];
        }
        $depot->enregistrer($this->ws(), $regles, (string) ($r->user()?->name ?? 'inconnu'));

        return response()->json($this->reponse($depot));
    }

    /** @return array<string, mixed> */
    private function reponse(DepotAlertes $depot): array
    {
        return ['data' => $depot->regles($this->ws()), 'seconde_adresse' => null, 'regroupement_minutes' => DepotAlertes::REGROUPEMENT_MINUTES];
    }

    private function ws(): string
    {
        return (string) WorkspaceContext::current();
    }
}
