<?php

namespace App\Http\Controllers\Api\Crm;

use App\Crm\Console\FilesATraiter;
use App\Crm\Propositions\PropositionImpossible;
use App\Crm\Propositions\PropositionIntrouvable;
use App\Crm\Propositions\Propositions;
use App\Crm\ProvenanceTiers\ProvenanceTiers;
use App\Support\MasquageCoordonnees;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * « PROPOSITIONS À VALIDER » — rôle OWNER seulement (N13, 03/10/2026).
 *
 * Les valeurs venues d'un tiers qui différaient de la fiche
 * (`App\Crm\Propositions\Propositions`). Tout autre rôle reçoit un 403 sans
 * corps utile, comme pour la provenance tiers (même règle de lecture :
 * `ProvenanceTiers::lisiblePar`).
 *
 * `GET  /v1/crm/propositions`               : la file, paginée, plus anciennes d'abord ;
 * `POST /v1/crm/propositions/{id}/accepter` : écrit la valeur sur la fiche, si
 *   elle porte encore ce que l'écran a montré (corps : `empreinte`, sinon 409) ;
 * `POST /v1/crm/propositions/{id}/refuser`  : la fiche ne bouge pas.
 *
 * Aucune route ici ne CRÉE de proposition : rien n'est branché au canal
 * Partners.
 */
class PropositionsController extends ConsoleController
{
    public function __construct(private readonly Propositions $propositions) {}

    public function index(Request $request): JsonResponse
    {
        $workspaceId = $this->espaceOwner($request);
        $perPage = $this->perPage($request);
        $page = max(1, (int) $request->query('page', '1'));

        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $perPage, $page): JsonResponse {
            // La MÊME file que la pastille du menu (`ATraiterController`).
            $query = FilesATraiter::propositions($workspaceId);
            $total = (clone $query)->count();

            $lignes = $query->orderBy('id')->offset(($page - 1) * $perPage)->limit($perPage)->get();
            $fiches = $this->fiches($workspaceId, $lignes->all());

            $data = [];
            foreach ($lignes as $l) {
                $entite = (string) $l->entite;
                $champ = (string) $l->champ;
                $fiche = $fiches[$entite][(int) $l->entite_id] ?? null;
                // La valeur de la fiche AUJOURD'HUI : c'est elle qu'accepter
                // remplace (celle du jour de la proposition reste en base).
                $actuelle = $fiche === null ? $l->valeur_actuelle : ($fiche->{$champ} ?? null);
                $data[] = [
                    'id' => (int) $l->id,
                    // Ce que l'écran montre, en empreinte : accepter la renvoie,
                    // et refuse d'écrire si la fiche a changé entre-temps.
                    'empreinte' => $fiche === null ? null : Propositions::empreinte($entite, $fiche, $champ),
                    // Fiche à la corbeille (ou disparue) : seul « Refuser » a un sens.
                    'fiche_supprimee' => $fiche === null,
                    'fiche_modifiee_depuis' => $fiche !== null && Propositions::modifieeDepuis($fiche, $champ, $l->valeur_actuelle === null ? null : (string) $l->valeur_actuelle),
                    'champ_declare' => $fiche !== null && Propositions::declare($fiche, $champ),
                    'entite' => $entite,
                    'entite_id' => (int) $l->entite_id,
                    'entreprise_id' => $fiche === null ? null : (int) ($entite === Propositions::ENTREPRISE ? $fiche->id : $fiche->company_id),
                    'fiche' => $fiche === null ? null : $this->nomFiche($entite, $fiche),
                    'champ' => $champ,
                    'libelle_champ' => Propositions::libelleChamp($entite, $champ),
                    'valeur_actuelle' => $this->masquer($champ, $actuelle === null ? null : (string) $actuelle),
                    'valeur_proposee' => $this->masquer($champ, (string) $l->valeur_proposee),
                    'origine' => (string) $l->origine,
                    'recue_le' => $l->created_at,
                ];
            }

            return $this->ok([
                'data' => $data,
                'meta' => ['total' => $total, 'per_page' => $perPage, 'page' => $page],
            ]);
        });
    }

    public function accepter(Request $request, int $id): JsonResponse
    {
        return $this->decider($request, $id, 'acceptee');
    }

    /** L'empreinte de ce que l'écran a montré (`index`) : obligatoire pour accepter. */
    private function empreinteVue(Request $request): string
    {
        $empreinte = $request->validate(['empreinte' => ['required', 'string', 'max:128']])['empreinte'];

        return is_string($empreinte) ? $empreinte : '';
    }

    public function refuser(Request $request, int $id): JsonResponse
    {
        return $this->decider($request, $id, 'refusee');
    }

    private function decider(Request $request, int $id, string $statut): JsonResponse
    {
        $workspaceId = $this->espaceOwner($request);
        $user = $this->currentUser($request);

        try {
            if ($statut === 'acceptee') {
                $this->propositions->accepter($workspaceId, $id, $user, $this->empreinteVue($request));
            } else {
                $this->propositions->refuser($workspaceId, $id, $user);
            }
        } catch (PropositionIntrouvable) {
            abort(404);
        } catch (PropositionImpossible $e) {
            abort(409, $e->getMessage());
        }

        return $this->ok(['id' => $id, 'statut' => $statut]);
    }

    private function espaceOwner(Request $request): string
    {
        if (! ProvenanceTiers::lisiblePar($this->currentUser($request))) {
            abort(403, 'Réservé au rôle owner.');
        }

        return $this->businessWorkspace($request);
    }

    /**
     * Les fiches visées par une page, en deux lectures (une par type).
     *
     * @param  array<int, mixed>  $lignes
     * @return array<string, array<int, stdClass>>
     */
    private function fiches(string $workspaceId, array $lignes): array
    {
        $ids = [Propositions::ENTREPRISE => [], Propositions::PERSONNE => []];
        foreach ($lignes as $l) {
            if ($l instanceof stdClass && isset($ids[$l->entite])) {
                $ids[$l->entite][] = (int) $l->entite_id;
            }
        }
        $colonnes = [
            Propositions::ENTREPRISE => array_merge(['id', 'field_origins'], Propositions::colonnes(Propositions::ENTREPRISE)),
            Propositions::PERSONNE => array_merge(['id', 'company_id', 'first_name', 'last_name', 'field_origins'], Propositions::colonnes(Propositions::PERSONNE)),
        ];
        $tables = [Propositions::ENTREPRISE => 'companies', Propositions::PERSONNE => 'contacts'];

        $fiches = [];
        foreach ($ids as $entite => $liste) {
            // Une fiche à la corbeille n'est pas montrée comme vivante : accepter
            // la refuserait (409), l'écran ne propose alors que « Refuser ».
            $fiches[$entite] = $liste === [] ? [] : DB::table($tables[$entite])
                ->where('workspace_id', $workspaceId)
                ->whereNull('deleted_at')
                ->whereIn('id', array_values(array_unique($liste)))
                ->get(array_values(array_unique($colonnes[$entite])))
                ->keyBy('id')
                ->all();
        }

        return $fiches;
    }

    private function nomFiche(string $entite, stdClass $fiche): string
    {
        if ($entite === Propositions::ENTREPRISE) {
            return trim((string) ($fiche->denomination ?? '')) ?: 'Entreprise sans nom';
        }

        return trim(trim((string) ($fiche->first_name ?? '')) . ' ' . trim((string) ($fiche->last_name ?? ''))) ?: 'Personne sans nom';
    }

    /** Owner seulement, mais la règle de masquage des coordonnées reste la même partout. */
    private function masquer(string $champ, ?string $valeur): ?string
    {
        if (! MasquageCoordonnees::requis()) {
            return $valeur;
        }

        return match ($champ) {
            'phone' => MasquageCoordonnees::telephone($valeur),
            'email_generic' => MasquageCoordonnees::email($valeur),
            default => $valeur,
        };
    }
}
