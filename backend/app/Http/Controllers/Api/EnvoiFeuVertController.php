<?php

namespace App\Http\Controllers\Api;

use App\Envoi\FeuVert\CatalogueManuels;
use App\Envoi\FeuVert\DecisionFeuVert;
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
        if ($ws === '') {
            return $this->sansEspace();
        }

        return response()->json(self::liste($liste, $ws));
    }

    /** « accorde » ou « retire » le feu vert final (D12). Propriétaire seul (middleware de la route) ; If-Match, 428 et 412 propres à « accorde ». */
    public function decision(Request $request, DecisionFeuVert $decision, ListeFeuVert $liste): JsonResponse
    {
        $corps = $request->json()->all();
        $voulu = $corps['decision'] ?? null;
        $motif = $corps['motif'] ?? null;
        if (! is_string($voulu) || ! is_string($motif)) {
            return $this->erreur('validation', 'La décision (accorde ou retire) et le motif (texte) sont obligatoires.', 422);
        }
        $ws = (string) WorkspaceContext::current();
        if ($ws === '') {
            return $this->sansEspace();
        }
        try {
            $r = $decision->decider($ws, $voulu, $motif, (string) $request->user()?->name, $request->header('If-Match'));
        } catch (InvalidArgumentException $e) {
            return $this->erreur('validation', $e->getMessage(), 422);
        }
        if ($r['statut'] !== 200) {
            return $this->erreur($r['erreur'] ?? 'conflit', $r['message'] ?? '', $r['statut']);
        }

        return response()->json(self::liste($liste, $ws));
    }

    /** @return array<string, mixed> forme `ListeFeuVert` du contrat */
    private static function liste(ListeFeuVert $liste, string $ws): array
    {
        $points = $liste->points($ws);
        $faits = count(array_filter($points, static fn (PointFeuVert $p): bool => $p->fait));
        $derniere = $liste->derniereDecision($ws);

        return [
            'points' => array_map([self::class, 'exposer'], $points),
            'faits' => $faits,
            'total' => count($points),
            'bloquants_restants' => count(array_filter($points, static fn (PointFeuVert $p): bool => $p->bloquant)),
            'verrous' => $liste->verrous($ws),
            'empreinte_liste' => $liste->empreinte($ws, $points),
            'decision' => $derniere === null ? null : ['decision' => $derniere['decision'], 'motif' => $derniere['motif'], 'le' => $derniere['le']],
        ];
    }

    public function fait(Request $request, string $code, ListeFeuVert $liste, DepotPreuves $depot): JsonResponse
    {
        // 422 pour TOUT code `auto.*`, AVANT la recherche au catalogue : la réponse ne dit pas si le point automatique existe.
        if (str_starts_with($code, 'auto.')) {
            return $this->erreur('validation', 'Ce point est calculé par le système : il ne reçoit jamais de preuve.', 422);
        }
        if (! in_array($code, CatalogueManuels::codes(), true)) {
            return $this->erreur('non_trouve', 'Ce point du feu vert n’existe pas.', 404);
        }
        if (! CatalogueManuels::estManuel($code)) {
            return $this->erreur('validation', 'Ce point est calculé par le système : il ne reçoit jamais de preuve.', 422);
        }
        // Le corps JSON seulement : jamais la query string ni un formulaire.
        $corps = $request->json()->all();
        $preuve = $corps['preuve'] ?? null;
        $fait = $corps['fait'] ?? true;
        if (! is_string($preuve) || ! is_bool($fait)) {
            return $this->erreur('validation', 'La preuve (texte) est obligatoire ; « fait » doit être vrai ou faux.', 422);
        }
        $ws = (string) WorkspaceContext::current();
        if ($ws === '') {
            return $this->sansEspace();
        }
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

    private function sansEspace(): JsonResponse
    {
        return $this->erreur('interdit', 'Aucun espace de travail n’est sélectionné pour votre compte.', 403);
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
