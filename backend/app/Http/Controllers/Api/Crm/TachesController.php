<?php

namespace App\Http\Controllers\Api\Crm;

use App\Crm\Taches\MesTaches;
use App\Models\Company;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * « Mes tâches » (lot G2-A, cahier G1-G2 § 8). Les tâches vivent dans
 * `activities` (voir `App\Crm\Taches\MesTaches`) ; aucune n'est supprimée.
 * Ce lot n'envoie aucun courriel : le rappel du matin est refusé proprement.
 */
class TachesController extends ConsoleController
{
    public function index(Request $request): JsonResponse
    {
        $ws = $this->businessWorkspace($request);
        $moi = (string) $this->currentUser($request)->getKey();
        $maintenant = CarbonImmutable::now('UTC');

        $data = WorkspaceContext::run($ws, fn (): array => MesTaches::liste($ws, $moi, $maintenant));

        return $this->ok(['aujourdhui' => MesTaches::aujourdhui($maintenant), 'data' => $data, 'rappel_matin' => false]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::forUser($this->currentUser($request))->authorize('gererTaches', Company::class);

        $d = $request->validate([
            'titre' => ['required', 'string', 'min:3', 'max:300'],
            'contexte' => ['nullable', 'string', 'max:200'],
            'echeance' => ['nullable', 'date_format:Y-m-d'],
            'heure' => ['nullable', 'date_format:H:i'],
        ]);
        $ws = $this->businessWorkspace($request);
        $moi = (string) $this->currentUser($request)->getKey();
        $maintenant = CarbonImmutable::now('UTC');

        $r = WorkspaceContext::run($ws, fn (): array => MesTaches::creer(
            $ws,
            $moi,
            (string) $d['titre'],
            $d['contexte'] ?? null,
            $d['echeance'] ?? null,
            $d['heure'] ?? null,
            $maintenant,
        ));

        return $this->ok($r['tache'], $r['creee'] ? 201 : 200);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        Gate::forUser($this->currentUser($request))->authorize('gererTaches', Company::class);

        $d = $request->validate(['faite' => ['required', 'boolean']]);
        $ws = $this->businessWorkspace($request);
        $moi = (string) $this->currentUser($request)->getKey();
        $maintenant = CarbonImmutable::now('UTC');

        $tache = WorkspaceContext::run($ws, fn (): ?array => MesTaches::basculer($ws, $moi, $id, (bool) $d['faite'], $maintenant));
        if ($tache === null) {
            abort(404, 'Tâche introuvable.');
        }

        return $this->ok($tache);
    }

    /**
     * Le rappel du matin par courriel n'existe pas encore : aucun envoi réel
     * dans ce lot. On refuse, on n'écrit rien (ESCALADE 4 du cahier).
     */
    public function rappelMatin(Request $request): JsonResponse
    {
        Gate::forUser($this->currentUser($request))->authorize('gererTaches', Company::class);

        return $this->ok([
            'error' => 'rappel_indisponible',
            'actif' => false,
            'message' => "Le rappel du matin par courriel n'est pas encore disponible.",
        ], 409);
    }
}
