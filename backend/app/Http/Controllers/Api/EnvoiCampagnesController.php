<?php

namespace App\Http\Controllers\Api;

use App\Envoi\Campagnes\GestesCampagne;
use App\Envoi\Campagnes\LectureCampagnes;
use App\Envoi\Exceptions\CampagneRefusee;
use App\Envoi\FeuVert\DepotPreuves;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Suivi et gestes des campagnes (contrat backend/openapi/envoi/campagnes.yaml). Lecture : `users.manage` ; gestes : `workspaces.manage`.
 * Aucune route ne résout ServeurEnvoi, n'écrit `sequence_id` ni ne touche aux séquences : voir GestesCampagne.
 */
class EnvoiCampagnesController extends ApiController
{
    private const ETATS = ['a_envoyer', 'envoye', 'remis', 'rebond_mou', 'rebond_dur', 'a_repondu', 'desinscrit', 'plainte', 'exclu', 'relances_annulees'];

    private const GROUPES = ['en_file', 'partis', 'refuses', 'ont_repondu', 'arretes', 'exclus'];

    public function index(Request $request, LectureCampagnes $lecture): JsonResponse
    {
        return $this->proteger(function () use ($request, $lecture): JsonResponse {
            $statut = $request->query('statut');
            if ($statut !== null && (! is_string($statut) || ! in_array($statut, LectureCampagnes::STATUTS, true))) {
                return $this->erreur('validation', 'Statut inconnu.', 422);
            }
            [$page, $parPage] = $this->pagination($request);
            $recherche = is_string($request->query('recherche')) ? mb_substr(trim($request->query('recherche')), 0, 100) : null;

            return response()->json(['maintenant' => CarbonImmutable::now()->toIso8601ZuluString()] + $lecture->liste($this->espace(), $statut, $recherche, $page, $parPage));
        });
    }

    public function calendrier(Request $request, LectureCampagnes $lecture): JsonResponse
    {
        return $this->proteger(function () use ($request, $lecture): JsonResponse {
            $du = $this->jour($request->query('du'));
            $au = $this->jour($request->query('au'));
            if ($du === false || $au === false) {
                return $this->erreur('validation', 'Dates attendues au format AAAA-MM-JJ.', 422);
            }
            $du ??= $au === null ? CarbonImmutable::now()->startOfWeek() : $au->subDays(6);
            $au ??= $du->addDays(6);
            if ($au < $du || $du->diffInDays($au) >= LectureCampagnes::CALENDRIER_JOURS_MAX) {
                return $this->erreur('validation', 'Plage invalide : « du » avant « au », ' . LectureCampagnes::CALENDRIER_JOURS_MAX . ' jours au plus.', 422);
            }

            return response()->json([
                'maintenant' => CarbonImmutable::now()->toIso8601ZuluString(), 'du' => $du->format('Y-m-d'), 'au' => $au->format('Y-m-d'),
                'data' => $lecture->calendrier($this->espace(), $du, $au),
            ]);
        });
    }

    public function show(string $id, LectureCampagnes $lecture): JsonResponse
    {
        return $this->proteger(fn (): JsonResponse => response()->json($lecture->fiche($this->espace(), $id)));
    }

    public function destinataires(Request $request, string $id, LectureCampagnes $lecture): JsonResponse
    {
        return $this->proteger(function () use ($request, $id, $lecture): JsonResponse {
            $lecture->existe($this->espace(), $id);
            if (($request->query('etat') !== null && ! in_array($request->query('etat'), self::ETATS, true))
                || ($request->query('groupe') !== null && ! in_array($request->query('groupe'), self::GROUPES, true))) {
                return $this->erreur('validation', 'État ou groupe inconnu.', 422);
            }
            [$page, $parPage] = $this->pagination($request);

            // Aucune séquence = aucun destinataire (sequence_id n'est jamais écrit par ce dépôt).
            return response()->json(['data' => [], 'meta' => ['page' => $page, 'par_page' => $parPage, 'total' => 0, 'pages' => 0]]);
        });
    }

    public function pause(Request $request, string $id, GestesCampagne $gestes): JsonResponse
    {
        return $this->geste($request, fn (string $ws, string $par, ?string $cle) => $gestes->pause($ws, $id, $par, $cle));
    }

    public function reprise(Request $request, string $id, GestesCampagne $gestes): JsonResponse
    {
        return $this->geste($request, fn (string $ws, string $par, ?string $cle) => $gestes->reprendre($ws, $id, $par, $cle));
    }

    public function terminer(Request $request, string $id, GestesCampagne $gestes): JsonResponse
    {
        return $this->geste($request, fn (string $ws, string $par, ?string $cle) => $gestes->terminer($ws, $id, $par, $cle));
    }

    public function dupliquer(Request $request, string $id, GestesCampagne $gestes): JsonResponse
    {
        $corps = $request->json()->all();
        $nom = $corps['nom'] ?? null;
        foreach (['garder_cible', 'garder_messages', 'exclure_deja_recus'] as $b) {
            if (isset($corps[$b]) && ! is_bool($corps[$b])) {
                return $this->erreur('validation', "« {$b} » doit être vrai ou faux.", 422);
            }
        }
        if (! is_string($nom) || trim($nom) === '' || mb_strlen(trim($nom)) > 200) {
            return $this->erreur('validation', 'Le nom de la copie compte de 1 à 200 caractères.', 422);
        }
        // `exclure_deja_recus` : sans destinataire (palier 0) il n'y a personne à exclure ; l'option est acceptée et sans effet.
        $options = ['nom' => trim($nom), 'garder_cible' => $corps['garder_cible'] ?? true, 'garder_messages' => $corps['garder_messages'] ?? true];

        return $this->geste($request, fn (string $ws, string $par, ?string $cle) => $gestes->dupliquer($ws, $id, $options, $par, $cle), 201);
    }

    public function date(Request $request, string $id, GestesCampagne $gestes): JsonResponse
    {
        $le = $request->json('le');
        try {
            $instant = is_string($le) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/', $le) === 1 ? new DateTimeImmutable($le) : null;
        } catch (\Exception) {
            $instant = null;
        }
        if ($instant === null) {
            return $this->erreur('validation', 'La date « le » est obligatoire (ISO 8601 avec fuseau).', 422);
        }

        return $this->geste($request, fn (string $ws, string $par, ?string $cle) => $gestes->changerDate($ws, $id, $instant, $par, $cle));
    }

    /** @param \Closure(string, string, ?string): \stdClass $f */
    private function geste(Request $request, \Closure $f, int $statut = 200): JsonResponse
    {
        $cle = $request->header('Idempotency-Key');
        if ($cle !== null && preg_match('/^[A-Za-z0-9._-]{8,100}$/', $cle) !== 1) {
            return $this->erreur('validation', 'Idempotency-Key : 8 à 100 caractères (lettres, chiffres, point, tiret, souligné).', 422);
        }

        return $this->proteger(fn (): JsonResponse => response()->json(LectureCampagnes::exposer($f($this->espace(), DepotPreuves::auteurAffichable($request->user()?->name), $cle)), $statut));
    }

    /** Refus métier (409, 422, 404) rendus avec un code stable et un texte français ; rien d'autre n'est avalé. */
    private function proteger(\Closure $f): JsonResponse
    {
        try {
            return $f();
        } catch (CampagneRefusee $e) {
            return $this->erreur($e->codeErreur, $e->getMessage(), $e->getCode());
        }
    }

    private function espace(): string
    {
        return (string) WorkspaceContext::current();
    }

    /** @return array{0: int, 1: int} */
    private function pagination(Request $request): array
    {
        return [max(1, min(100000, (int) $request->query('page', 1))), max(1, min(LectureCampagnes::PAR_PAGE_MAX, (int) $request->query('par_page', 25)))];
    }

    private function jour(mixed $v): CarbonImmutable|false|null
    {
        if ($v === null) {
            return null;
        }

        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)) ? CarbonImmutable::parse($v) : false;
    }

    private function erreur(string $code, string $message, int $statut): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => $message], $statut);
    }
}
