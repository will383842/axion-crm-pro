<?php

namespace App\Http\Controllers\Api\Crm;

use App\Crm\Affaires\Affaires;
use App\Models\Company;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Affaires (lot G2-D, cahier G1-G2 § 11). Aucune suppression : ni route, ni requête.
 */
class AffairesController extends ConsoleController
{
    public function index(Request $request): JsonResponse
    {
        $ws = $this->businessWorkspace($request);
        $data = WorkspaceContext::run($ws, fn (): array => Affaires::liste($ws));

        return $this->ok(['aujourdhui' => Affaires::aujourdhui(CarbonImmutable::now('UTC')), 'data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::forUser($this->currentUser($request))->authorize('gererAffaires', Company::class);

        $d = $request->validate([
            'entreprise_id' => ['required', 'integer', 'min:1'],
            'titre' => ['nullable', 'string', 'max:300'],
            'montant' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'chance' => ['nullable', 'integer', 'between:0,100'],
            'signature_prevue' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $ws = $this->businessWorkspace($request);

        $affaire = WorkspaceContext::run($ws, fn (): ?array => Affaires::creer(
            $ws,
            (int) $d['entreprise_id'],
            $d['titre'] ?? null,
            isset($d['montant']) ? (float) $d['montant'] : null,
            isset($d['chance']) ? (int) $d['chance'] : null,
            $d['signature_prevue'] ?? null,
        ));
        if ($affaire === null) {
            abort(404, 'Entreprise introuvable.');
        }

        return $this->ok($affaire, 201);
    }

    /**
     * Change l'étape d'UNE affaire (geste explicite et idempotent, sans saisie multi-champs à perdre : pas de
     * verrou optimiste ; la ligne est verrouillée le temps du changement). Nommée `deplacer`, pas `update`.
     */
    public function deplacer(Request $request, int $id): JsonResponse
    {
        Gate::forUser($this->currentUser($request))->authorize('gererAffaires', Company::class);

        $d = $request->validate([
            'etape' => ['required', 'string', Rule::in(Affaires::ETAPES)],
            'raison_perte' => [
                Rule::requiredIf(fn () => $request->input('etape') === 'perdu'),
                Rule::prohibitedIf(fn () => $request->input('etape') !== 'perdu'),
                'nullable', 'string', 'min:1', 'max:500',
            ],
        ]);
        $ws = $this->businessWorkspace($request);
        $moi = (string) $this->currentUser($request)->getKey();

        $affaire = WorkspaceContext::run($ws, fn (): ?array => Affaires::deplacer(
            $ws,
            $moi,
            $id,
            (string) $d['etape'],
            $d['raison_perte'] ?? null,
            CarbonImmutable::now('UTC'),
        ));
        if ($affaire === null) {
            abort(404, 'Affaire introuvable.');
        }

        return $this->ok($affaire);
    }
}
