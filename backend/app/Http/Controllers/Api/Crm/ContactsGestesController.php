<?php

namespace App\Http\Controllers\Api\Crm;

use App\Crm\Contacts\GestesContact;
use App\Models\Contact;
use App\Support\MasquageCoordonnees;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Gestes d'écriture « Contacts » de la console (lot G1-A : modifier). */
class ContactsGestesController extends ConsoleController
{
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
