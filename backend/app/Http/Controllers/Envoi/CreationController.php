<?php

namespace App\Http\Controllers\Envoi;

use App\Envoi\Creation\AssistantCreation;
use App\Envoi\Creation\ErreurCreation;
use App\Envoi\Creation\ValidationCampagne;
use App\Envoi\Exceptions\CampagneRefusee;
use App\Http\Controllers\Controller;
use App\Support\AuditLogger;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Création de campagne, partie 2 (R11). Aucune méthode ne résout ServeurEnvoi, Sequenceur ni une boîte d'envoi.
 * Les gestes qui feraient partir un message (test) répondent 409 `envoi_ferme_palier_0` sans aucun effet.
 */
final class CreationController extends Controller
{
    public function __construct(private readonly AssistantCreation $assistant, private readonly ValidationCampagne $validation) {}

    public function creer(Request $r): JsonResponse
    {
        $nom = $r->validate(['nom' => ['required', 'string', 'max:200']])['nom'];

        return $this->garde(function () use ($r, $nom): JsonResponse {
            $b = $this->assistant->creer($this->ws($r), (string) $r->user()?->id, $nom);
            $this->audit($r, 'envoi.brouillon.creer', $b['id']);

            return $this->brouillon($b, 201);
        });
    }

    public function lire(Request $r, string $id): JsonResponse
    {
        return $this->garde(fn () => $this->brouillon($this->assistant->presenter($this->assistant->lire($this->ws($r), $id))));
    }

    public function etape(Request $r, string $id, int $etape): JsonResponse
    {
        $version = preg_match('/^"?(\d+)"?$/', (string) $r->header('If-Match'), $m) ? (int) $m[1] : null;

        return $this->garde(function () use ($r, $id, $etape, $version): JsonResponse {
            [$b] = $this->assistant->enregistrerEtape($this->ws($r), $id, $etape, $version, (array) $r->json()->all());
            $this->audit($r, 'envoi.brouillon.etape', $id, ['etape' => $etape]);

            return $this->brouillon($b);
        });
    }

    public function estimation(Request $r, string $id): JsonResponse
    {
        $cible = $r->validate(['type' => ['required', 'in:liste,audience,etiquette,carnet'], 'reference' => ['required', 'string', 'max:200']]);

        return $this->garde(function () use ($r, $id, $cible): JsonResponse {
            $this->assistant->lire($this->ws($r), $id);

            return response()->json($this->assistant->estimer($this->ws($r), $cible));
        });
    }

    public function controles(Request $r, string $id): JsonResponse
    {
        return $this->garde(fn () => response()->json($this->assistant->controles($this->ws($r), $this->assistant->lire($this->ws($r), $id))));
    }

    /** L'aperçu réel dépend du moteur de rendu des messages (R10) : pas encore branché, réponse explicite. */
    public function apercu(Request $r, string $id): JsonResponse
    {
        return $this->garde(function () use ($r, $id): JsonResponse {
            $this->assistant->lire($this->ws($r), $id);
            throw new ErreurCreation('apercu_non_branche', 'L\'aperçu réel n\'est pas encore disponible dans cette version.', 501);
        });
    }

    /** « M'envoyer un test » ferait partir un message : fermé au palier 0, sans aucun effet. */
    public function test(Request $r, string $id): JsonResponse
    {
        return $this->garde(function () use ($r, $id): JsonResponse {
            $this->assistant->lire($this->ws($r), $id);
            throw ErreurCreation::envoiFerme();
        });
    }

    public function code(Request $r, string $id): JsonResponse
    {
        return $this->garde(function () use ($r, $id): JsonResponse {
            $res = $this->validation->demanderCode($this->ws($r), (string) $r->user()?->id, (string) $r->user()?->email, $id, $this->cle($r));
            $this->audit($r, 'envoi.brouillon.code', $id);

            return response()->json($res);
        });
    }

    public function valider(Request $r, string $id): JsonResponse
    {
        $code = $r->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']])['code'];

        return $this->garde(function () use ($r, $id, $code): JsonResponse {
            $res = $this->validation->valider($this->ws($r), (string) $r->user()?->id, $id, $code, $this->cle($r));
            $this->audit($r, 'envoi.brouillon.valider', $id);

            return response()->json($res);
        });
    }

    public function annuler(Request $r, string $id): JsonResponse
    {
        return $this->garde(function () use ($r, $id): JsonResponse {
            $b = $this->validation->annuler($this->ws($r), (string) $r->user()?->id, $id, $this->cle($r));
            $this->audit($r, 'envoi.brouillon.annuler_validation', $id);

            return $this->brouillon($b);
        });
    }

    public function cibles(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->assistant->cibles($this->ws($r), $r->query('q') ? (string) $r->query('q') : null, $r->query('entreprise') ? (string) $r->query('entreprise') : null)]);
    }

    public function boites(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->assistant->boites($this->ws($r))]);
    }

    public function destinatairesExemples(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->assistant->destinatairesExemples($this->ws($r))]);
    }

    private function ws(Request $r): string
    {
        return (string) $r->user()?->current_workspace_id;
    }

    private function cle(Request $r): ?string
    {
        $cle = (string) $r->header('Idempotency-Key');

        return strlen($cle) >= 8 && strlen($cle) <= 90 ? $cle : null;
    }

    /** @param array<string, mixed> $b */
    private function brouillon(array $b, int $statut = 200): JsonResponse
    {
        return response()->json($b, $statut)->header('ETag', '"' . $b['version'] . '"');
    }

    /** @param array<string, mixed> $contexte */
    private function audit(Request $r, string $action, string $id, array $contexte = []): void
    {
        AuditLogger::log($action, ['workspace_id' => $this->ws($r), 'resource_type' => 'envoi_campagne', 'resource_id' => $id] + $contexte);
    }

    /** @param Closure(): JsonResponse $f */
    private function garde(Closure $f): JsonResponse
    {
        try {
            return $f();
        } catch (ErreurCreation $e) {
            return response()->json(['error' => $e->erreur, 'message' => $e->getMessage()], $e->statut);
        } catch (CampagneRefusee $e) {
            return response()->json(['error' => $e->getCode() === 404 ? 'non_trouve' : ($e->getCode() === 409 ? 'conflit' : 'validation'), 'message' => $e->getMessage()], $e->getCode());
        }
    }
}
