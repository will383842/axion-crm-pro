<?php

namespace App\Http\Controllers\Api\Crm;

use App\Crm\Contacts\AjoutContact;
use App\Crm\Contacts\GestesContact;
use App\Http\Controllers\Concerns\VerrouOptimiste;
use App\Models\Contact;
use App\Support\MasquageCoordonnees;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Gestes « Contacts » de la console (G1-A : modifier ; G1-B : retirer, restaurer, corbeille douce ; G1-C : ajout rapide). */
class ContactsGestesController extends ConsoleController
{
    use VerrouOptimiste;

    public function modifier(Request $request, int|string $contactId): JsonResponse
    {
        // Défense en profondeur : la route porte déjà `permission:companies.update`.
        Gate::forUser($this->currentUser($request))->authorize('modifier', Contact::class);

        $workspaceId = $this->businessWorkspace($request);

        $champs = $request->validate([
            'first_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'last_name' => ['sometimes', 'string', 'min:1', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'fonction' => ['sometimes', 'nullable', 'string', 'max:200'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'lifecycle_stage' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);
        if ($champs === []) {
            return response()->json(['error' => 'aucun_champ', 'message' => 'Aucun champ à modifier.'], 422);
        }

        // Verrou optimiste (G43-005) : `If-Match` ou `updated_at` périmé → 409 ; sans l'un ni l'autre, comme ailleurs, on passe.
        $actuel = WorkspaceContext::run($workspaceId, fn () => Contact::query()->where('workspace_id', $workspaceId)->find((int) $contactId));
        if ($actuel !== null) {
            $this->refuserSiVersionPerimee($request, $actuel);
        }

        $r = WorkspaceContext::run($workspaceId, fn () => (new GestesContact)->modifier($workspaceId, (int) $contactId, $champs));

        if ($r['etat'] === 'ok') {
            return response()->json(['data' => $this->projeter($r['contact'], $r['opposition'])] + ['opposition' => $r['opposition']]);
        }

        return match ($r['etat']) {
            'doublon' => response()->json(['error' => 'doublon', 'message' => 'Une autre fiche porte déjà ce nom dans cette entreprise (peut-être dans la corbeille).'], 409),
            'champ_non_modifiable' => response()->json(['error' => 'champ_non_modifiable', 'message' => "L'étape se change sur l'entreprise."], 422),
            default => response()->json(['error' => 'introuvable', 'message' => 'Contact introuvable.'], 404),
        };
    }

    /** Doublons probables pendant la saisie (lecture seule, adresses masquées si requis). */
    public function doublons(Request $request): JsonResponse
    {
        $workspaceId = $this->businessWorkspace($request);
        $q = $request->validate([
            'email' => ['sometimes', 'nullable', 'string', 'max:254'],
            'prenom' => ['sometimes', 'nullable', 'string', 'max:120'],
            'nom' => ['sometimes', 'nullable', 'string', 'max:120'],
            'organisation' => ['sometimes', 'nullable', 'string', 'max:200'],
            'company_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $data = WorkspaceContext::run($workspaceId, function () use ($workspaceId, $q): array {
            $service = new AjoutContact;
            $e = $service->entreprise($workspaceId, isset($q['company_id']) ? (int) $q['company_id'] : null, $q['organisation'] ?? null);

            return $service->doublons($workspaceId, $q['email'] ?? null, $q['prenom'] ?? null, $q['nom'] ?? null, is_array($e) ? $e['id'] : null);
        });

        return response()->json(['data' => $data]);
    }

    /** Ajout rapide : la provenance est obligatoire ; jamais d'entreprise créée ; une personne opposée n'est pas ajoutée. */
    public function ajouter(Request $request): JsonResponse
    {
        Gate::forUser($this->currentUser($request))->authorize('creer', Contact::class);
        $workspaceId = $this->businessWorkspace($request);

        $d = $request->validate([
            'first_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'min:1', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'organisation' => ['sometimes', 'nullable', 'string', 'max:200'],
            'company_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'fonction' => ['sometimes', 'nullable', 'string', 'max:200'],
            'provenance' => ['required', 'string', Rule::in(AjoutContact::PROVENANCES)],
            'doublon' => ['sometimes', 'nullable', Rule::in(['fusionner', 'garder'])],
            'fusionner_avec' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        $userId = (string) $this->currentUser($request)->getKey();

        $r = WorkspaceContext::run($workspaceId, fn () => (new AjoutContact)->ajouter($workspaceId, $d, $userId));

        return match ($r['etat']) {
            'cree' => response()->json(['resultat' => 'cree', 'fiche' => $r['fiche']], 201),
            'fusionne' => response()->json(['resultat' => 'fusionne', 'fiche' => $r['fiche']]),
            'doublons' => response()->json(['error' => 'doublons', 'message' => 'Une fiche proche existe déjà.', 'doublons' => $r['doublons']], 409),
            'doublon' => response()->json(['error' => 'doublon', 'message' => 'Une fiche porte déjà ce nom dans cette entreprise (peut-être dans la corbeille).'], 409),
            'organisation_ambigue' => response()->json(['error' => 'organisation_ambigue', 'message' => 'Plusieurs entreprises portent ce nom : choisissez-la dans la recherche.'], 422),
            'organisation_introuvable' => response()->json(['error' => 'organisation_introuvable', 'message' => 'Entreprise introuvable : choisissez une entreprise existante.'], 422),
            'opposition' => response()->json(['error' => 'opposition', 'message' => 'Cette adresse a demandé à ne plus être contactée.'], 422),
            'fusion_invalide' => response()->json(['error' => 'fusion_invalide', 'message' => 'Indiquez la fiche à compléter.'], 422),
            default => response()->json(['error' => 'introuvable', 'message' => 'Contact introuvable.'], 404),
        };
    }

    /** Retirer = corbeille douce, restaurable. Idempotent. Rien n'est effacé. */
    public function retirer(Request $request, int|string $contactId): JsonResponse
    {
        Gate::forUser($this->currentUser($request))->authorize('retirer', Contact::class);
        $workspaceId = $this->businessWorkspace($request);
        $userId = (string) $this->currentUser($request)->getKey();

        $r = WorkspaceContext::run($workspaceId, fn () => (new GestesContact)->retirer($workspaceId, (int) $contactId, $userId));

        return $r['etat'] === 'ok'
            ? response()->json(['retire' => true])
            : response()->json(['error' => 'introuvable', 'message' => 'Contact introuvable.'], 404);
    }

    /** Restaurer un contact retiré par la console (jamais une mise à l'écart RGPD). */
    public function restaurer(Request $request, int|string $contactId): JsonResponse
    {
        Gate::forUser($this->currentUser($request))->authorize('retirer', Contact::class);
        $workspaceId = $this->businessWorkspace($request);

        $r = WorkspaceContext::run($workspaceId, fn () => (new GestesContact)->restaurer($workspaceId, (int) $contactId));

        return match ($r['etat']) {
            'ok' => response()->json(['restaure' => true]),
            'mis_a_l_ecart' => response()->json(['error' => 'mis_a_l_ecart', 'message' => 'Cette personne a été mise à l’écart : la fiche ne peut pas être restaurée ici.'], 409),
            'entreprise_corbeille' => response()->json(['error' => 'entreprise_corbeille', 'message' => 'Restaurer d’abord l’entreprise.'], 409),
            default => response()->json(['error' => 'introuvable', 'message' => 'Contact introuvable.'], 404),
        };
    }

    /** La corbeille de la console. */
    public function corbeille(Request $request): JsonResponse
    {
        $workspaceId = $this->businessWorkspace($request);

        return response()->json(['data' => WorkspaceContext::run($workspaceId, fn () => (new GestesContact)->corbeille($workspaceId))]);
    }

    /**
     * Même projection explicite que `ContactsController::present()` (adresse et téléphone masqués si requis).
     *
     * @param  array{opposee: bool, motifs: list<string>}  $opposition
     * @return array<string, mixed>
     */
    private function projeter(Contact $c, array $opposition): array
    {
        $masquer = MasquageCoordonnees::requis();

        return [
            'opposition' => $opposition,
            'id' => $c->id,
            'first_name' => $c->first_name,
            'last_name' => $c->last_name,
            'role' => $c->role,
            'email' => $masquer ? MasquageCoordonnees::email($c->email) : $c->email,
            'email_status' => $c->email_status,
            'joignabilite' => $c->getAttribute('joignabilite'),
            'email_score' => $c->email_score,
            'phone' => $masquer ? MasquageCoordonnees::telephone($c->phone) : $c->phone,
            'linkedin_url' => $c->linkedin_url,
            'discovery_source' => $c->discovery_source,
            'company_id' => $c->company_id,
            'company' => $c->company === null ? null : ['id' => $c->company->id, 'denomination' => $c->company->denomination],
        ];
    }
}
