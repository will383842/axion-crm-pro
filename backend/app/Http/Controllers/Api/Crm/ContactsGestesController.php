<?php

namespace App\Http\Controllers\Api\Crm;

use App\Crm\Contacts\GestesContact;
use App\Http\Controllers\Api\ContactsController;
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

        return match ($r['etat']) {
            'introuvable' => response()->json(['error' => 'introuvable', 'message' => 'Contact introuvable.'], 404),
            'doublon' => response()->json(['error' => 'doublon', 'message' => 'Une autre fiche porte déjà ce nom dans cette entreprise (peut-être dans la corbeille).'], 409),
            'champ_non_modifiable' => response()->json(['error' => 'champ_non_modifiable', 'message' => "L'étape se change sur l'entreprise."], 422),
            default => response()->json(['data' => app(ContactsController::class)->present($r['contact'], MasquageCoordonnees::requis(), $r['opposition'])] + ['opposition' => $r['opposition']]),
        };
    }
}
