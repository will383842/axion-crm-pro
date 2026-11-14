<?php

namespace App\Http\Controllers\Api;

use App\Envoi\Reponses\ServiceReponses;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Réponses (contrat backend/openapi/envoi/reponses.yaml, lot R12). Lecture : `users.manage` ; gestes : `workspaces.manage`.
 * « Répondre » ne fait JAMAIS partir de message au palier 0 : 409 `envoi_ferme_palier_0`, sans aucun effet.
 * Aucune route ne résout `ServeurEnvoi`, n'écrit `envoi_drapeaux` ni ne touche aux témoins.
 */
class EnvoiReponsesController extends ApiController
{
    public function __construct(private readonly ServiceReponses $service) {}

    public function index(Request $request): JsonResponse
    {
        $file = (string) $request->query('file', 'a_traiter');
        $classement = $request->query('classement');
        if (! in_array($file, ['a_traiter', 'a_rattacher', 'traitees'], true)
            || ($classement !== null && ! in_array($classement, [...ServiceReponses::CLASSEMENTS, 'a_rattacher'], true))) {
            return $this->erreur('validation', 'La file ou le classement demandé n’existe pas.', 422);
        }
        $parPage = min(100, max(1, (int) $request->query('par_page', 25)));
        $q = $request->query('q');

        return response()->json($this->service->lister(
            $this->espace(),
            $file,
            $classement === null ? null : (string) $classement,
            is_string($q) ? $q : null,
            max(1, (int) $request->query('page', 1)),
            $parPage,
        ));
    }

    public function show(string $id): JsonResponse
    {
        $r = ctype_digit($id) ? $this->service->lire($this->espace(), (int) $id) : null;

        return $r === null ? $this->introuvable() : response()->json($r);
    }

    public function classer(Request $request, string $id): JsonResponse
    {
        $classement = $request->json('classement');
        if (! is_string($classement) || ! in_array($classement, ServiceReponses::CLASSEMENTS, true)) {
            return $this->erreur('validation', 'Ce classement n’existe pas.', 422);
        }
        $r = ctype_digit($id) ? $this->service->classer($this->espace(), (string) $request->user()?->getAuthIdentifier(), (int) $id, $classement) : null;

        return $r === null ? $this->introuvable() : response()->json($r);
    }

    public function rattacher(Request $request, string $id): JsonResponse
    {
        $type = $request->json('type');
        $reference = $request->json('reference');
        if ($type !== 'contact' || ! is_string($reference) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $reference) !== 1) {
            return $this->erreur('validation', 'Seul le rattachement à un envoi précis (type « contact », identifiant d’envoi) est possible pour le moment.', 422);
        }
        if (! ctype_digit($id)) {
            return $this->introuvable();
        }
        [$etat, $res] = $this->service->rattacher($this->espace(), (string) $request->user()?->getAuthIdentifier(), (int) $id, $reference);

        return match ($etat) {
            'ok' => response()->json($res),
            'inconnue' => $this->introuvable(),
            'inconnue_injection' => $this->erreur('validation', 'Cet envoi est inconnu dans votre espace.', 422),
            default => $this->erreur('deja_rattachee', 'Cette réponse est déjà rattachée.', 409),
        };
    }

    public function creerFiche(Request $request, string $id): JsonResponse
    {
        $d = $request->json()->all();
        foreach (['prenom', 'nom', 'provenance'] as $champ) {
            if (! is_string($d[$champ] ?? null) || trim($d[$champ]) === '' || mb_strlen($d[$champ]) > 200) {
                return $this->erreur('validation', 'Le prénom, le nom et la provenance sont obligatoires.', 422);
            }
        }
        $organisation = is_string($d['organisation'] ?? null) && trim($d['organisation']) !== '' ? $d['organisation'] : null;
        if (! ctype_digit($id)) {
            return $this->introuvable();
        }
        [$etat, $res] = $this->service->creerFiche($this->espace(), (int) $id, trim($d['prenom']), trim($d['nom']), $organisation, trim($d['provenance']));

        return match ($etat) {
            'ok' => response()->json($res),
            'inconnue' => $this->introuvable(),
            default => $this->erreur('validation', 'Indiquez une organisation déjà connue : la création d’une organisation n’est pas possible depuis cet écran.', 422),
        };
    }

    public function repondre(string $id): JsonResponse
    {
        // Seule une lecture : la réponse d'un autre espace reste introuvable. Au palier 0 rien ne part, aucune écriture.
        if (! ctype_digit($id) || $this->service->resumeDe($this->espace(), (int) $id) === null) {
            return $this->introuvable();
        }

        return $this->erreur('envoi_ferme_palier_0', 'L’envoi est fermé pour le moment : votre réponse n’est pas partie.', 409);
    }

    public function raccourcis(): JsonResponse
    {
        return response()->json(['data' => [], 'collegues' => []]);
    }

    private function espace(): string
    {
        return (string) WorkspaceContext::current();
    }

    private function introuvable(): JsonResponse
    {
        return $this->erreur('non_trouve', 'Cette réponse n’existe pas.', 404);
    }

    private function erreur(string $code, string $message, int $statut): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => $message], $statut);
    }
}
