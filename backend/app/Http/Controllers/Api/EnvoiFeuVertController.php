<?php

namespace App\Http\Controllers\Api;

use App\Envoi\FeuVert\CatalogueManuels;
use App\Envoi\FeuVert\DepotPreuves;
use App\Envoi\FeuVert\ListeFeuVert;
use App\Envoi\FeuVert\PointFeuVert;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Feu vert avant le premier envoi réel (contrat backend/openapi/envoi/pilotage.yaml).
 * Lecture de la liste calculée + dépôt / retrait de la PREUVE d'un point manuel. Aucune route n'ouvre un verrou,
 * ne signe, ni n'écrit `envoi_drapeaux` : tout se joue dans ListeFeuVert (lecture) et DepotPreuves (ajout seul).
 */
class EnvoiFeuVertController extends ApiController
{
    public function index(ListeFeuVert $liste): JsonResponse
    {
        $ws = (string) WorkspaceContext::current();
        $points = $liste->points($ws);
        $faits = count(array_filter($points, static fn (PointFeuVert $p): bool => $p->fait));

        return response()->json([
            'points' => array_map([self::class, 'exposer'], $points),
            'faits' => $faits,
            'total' => count($points),
            'bloquants_restants' => count(array_filter($points, static fn (PointFeuVert $p): bool => $p->bloquant)),
            'verrous' => $liste->verrous($ws),
        ]);
    }

    public function fait(Request $request, string $code, ListeFeuVert $liste, DepotPreuves $depot): JsonResponse
    {
        if (! in_array($code, CatalogueManuels::codes(), true)) {
            return $this->erreur('non_trouve', 'Ce point du feu vert n’existe pas.', 404);
        }
        if (! CatalogueManuels::estManuel($code)) {
            return $this->erreur('validation', 'Ce point est calculé par le système : il ne reçoit jamais de preuve.', 422);
        }
        $preuve = $request->input('preuve');
        $fait = $request->input('fait', true);
        if (! is_string($preuve) || ! is_bool($fait)) {
            return $this->erreur('validation', 'La preuve (texte) est obligatoire ; « fait » doit être vrai ou faux.', 422);
        }
        $ws = (string) WorkspaceContext::current();
        $par = DepotPreuves::auteurAffichable($request->user()?->name);
        try {
            $fait ? $depot->ajouter($ws, $code, $preuve, $par) : $depot->defaire($ws, $code, $preuve, $par);
        } catch (InvalidArgumentException $e) {
            return $this->erreur('validation', $e->getMessage(), 422);
        }
        foreach ($liste->points($ws) as $p) {
            if ($p->code === $code) {
                return response()->json(self::exposer($p));
            }
        }

        return $this->erreur('non_trouve', 'Ce point du feu vert n’existe pas.', 404);
    }

    private function erreur(string $code, string $message, int $statut): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => $message], $statut);
    }

    /** @return array<string, mixed> forme `PointFeuVert` du contrat */
    private static function exposer(PointFeuVert $p): array
    {
        return [
            'code' => $p->code,
            'libelle' => $p->libelle,
            'groupe' => $p->groupe,
            'nature' => $p->nature,
            'etat' => $p->etat,
            'fait' => $p->fait,
            'bloquant' => $p->bloquant,
            'source' => $p->source,
            'detail' => $p->detail,
            'preuve' => $p->preuve === null ? null : ['texte' => $p->preuve, 'par' => (string) $p->preuvePar, 'le' => (string) $p->preuveLe],
        ];
    }
}
