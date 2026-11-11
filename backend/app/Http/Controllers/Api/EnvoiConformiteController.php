<?php

namespace App\Http\Controllers\Api;

use App\Envoi\Conformite\LecteurOppositions;
use App\Support\MasquageCoordonnees;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Conformité d'envoi, en LECTURE (lot R05 ; contrat backend/openapi/envoi/conformite.yaml).
 * Aucune écriture, aucune suppression, aucune résolution de ServeurEnvoi. Réservé à `users.manage` (routes).
 */
class EnvoiConformiteController extends ApiController
{
    public function desinscriptions(Request $request, LecteurOppositions $lecteur): JsonResponse
    {
        [$ws, $q, $page, $parPage] = $this->entree($request);
        $r = $lecteur->page($ws, $q, $page, $parPage, ! MasquageCoordonnees::requis());

        return response()->json([
            'data' => array_map(static fn (array $l): array => [
                'id' => $l['id'],
                'adresse' => $l['adresse'],
                'adresse_masquee' => $l['adresse_masquee'],
                'survenue_le' => $l['survenue_le'],
                'source' => LecteurOppositions::SOURCE,
                'campagne_id' => null,
            ], $r['data']),
            'meta' => $this->meta($page, $parPage, $r['total']),
        ]);
    }

    public function opposition(Request $request, LecteurOppositions $lecteur): JsonResponse
    {
        [$ws, $q, $page, $parPage] = $this->entree($request);
        $r = $lecteur->page($ws, $q, $page, $parPage, ! MasquageCoordonnees::requis());

        return response()->json([
            'data' => array_map(static fn (array $l): array => [
                'id' => $l['id'],
                'portee' => 'adresse',
                'valeur' => $l['adresse_masquee'],
                'motif' => $l['canal'] === 'one_click'
                    ? 'Désinscription en un clic depuis l’en-tête du message'
                    : 'Désinscription depuis le lien du message',
                'inscrite_le' => $l['survenue_le'],
                'source' => LecteurOppositions::SOURCE,
            ], $r['data']),
            'meta' => $this->meta($page, $parPage, $r['total']),
            'resume' => $lecteur->resume($ws),
        ]);
    }

    /** @return array{0: string, 1: ?string, 2: int, 3: int} */
    private function entree(Request $request): array
    {
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'par_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return [(string) WorkspaceContext::current(), $v['q'] ?? null, (int) ($v['page'] ?? 1), (int) ($v['par_page'] ?? 25)];
    }

    /** @return array{page: int, par_page: int, total: int, pages: int} */
    private function meta(int $page, int $parPage, int $total): array
    {
        return ['page' => $page, 'par_page' => $parPage, 'total' => $total, 'pages' => (int) ceil($total / $parPage)];
    }
}
