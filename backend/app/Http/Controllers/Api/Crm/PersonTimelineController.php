<?php

namespace App\Http\Controllers\Api\Crm;

use App\Crm\Console\ConsoleAccess;
use App\Support\MasquageCoordonnees;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FICHE 360° — la timeline unifiée d'une personne (plan §2.6, conception §3b).
 *
 * ── Ce que cet endpoint révèle, et ce qu'il ne révèle PAS ──────────────────
 *
 * `person_key` est la seule clé qui traverse les deux univers (plan §2.4.3).
 * Elle permet donc de savoir qu'une même personne a une fiche des deux côtés —
 * et c'est exactement ce que le plan autorise : un BOOLÉEN, jamais un contenu.
 *
 * Concrètement, pour un utilisateur qui n'a pas accès au vivier :
 *   - `universes.vivier.exists` peut valoir `true` (l'indicateur « existe aussi
 *     dans le Vivier » de la conception §2.4 et §3b) ;
 *   - `universes.vivier.accessible` vaut `false` ;
 *   - et AUCUNE activité, AUCUN nom, AUCUNE étape du vivier n'est renvoyée.
 *
 * Ce n'est pas une demi-mesure : sans cet indicateur, un opérateur business
 * créerait une seconde fiche pour quelqu'un qui en a déjà une, et l'étanchéité
 * produirait des doublons au lieu de protéger. Avec, il sait qu'il doit changer
 * d'univers — ou demander l'accès.
 */
class PersonTimelineController extends ConsoleController
{
    private const MAX_ACTIVITIES = 200;

    public function show(Request $request, string $personKey): JsonResponse
    {
        // La clé est un sha256 hexadécimal calculé côté site : tout le reste est
        // une tentative de sondage, pas une faute de frappe.
        if (preg_match('/^[0-9a-f]{64}$/', $personKey) !== 1) {
            abort(404);
        }

        $user = $this->currentUser($request);

        $business = ConsoleAccess::businessWorkspaceId($user);
        $vivier = ConsoleAccess::vivierWorkspaceId();
        $vivierAccessible = $vivier !== null && ConsoleAccess::isMemberOf($user, $vivier);

        if ($business === null && ! $vivierAccessible) {
            abort(403, 'Aucun univers accessible.');
        }

        $activities = [];
        $subjects = [];

        if ($business !== null) {
            $activities = array_merge($activities, $this->activitiesFor($business, $personKey, 'business'));
            $subjects = array_merge($subjects, $this->businessSubjects($business, $personKey));
        }

        if ($vivier !== null && $vivierAccessible) {
            $activities = array_merge($activities, $this->activitiesFor($vivier, $personKey, 'vivier'));
            $subjects = array_merge($subjects, $this->vivierSubjects($vivier, $personKey));
        }

        // Tri chronologique inverse SUR L'ENSEMBLE : une timeline « 360° » qui
        // affiche un univers puis l'autre n'est pas une timeline, c'est deux
        // listes accolées.
        usort($activities, static function (array $a, array $b): int {
            return strcmp((string) ($b['occurred_at'] ?? ''), (string) ($a['occurred_at'] ?? ''));
        });

        return $this->ok([
            'person_key' => $personKey,
            'universes' => [
                'business' => [
                    'accessible' => $business !== null,
                    'exists' => $business !== null && $this->existsInBusiness($business, $personKey),
                ],
                'vivier' => [
                    'accessible' => $vivierAccessible,
                    // Indicateur SANS contenu (plan §2.4.3) : la question posée
                    // à la base est un `EXISTS`, et la réponse un booléen.
                    'exists' => $vivier !== null && $this->existsInVivier($vivier, $personKey),
                ],
            ],
            // 🔴 SITE JUMEAU de B12-002 / F36-006, et le plus ironique : la
            // « fiche 360° » est LA fiche detaillee nominative du chantier CRM
            // cible. Elle rendait `email` et `phone` en clair des DEUX univers.
            // Le masquage porte sur `subjects` ET sur `data` : une activite
            // peut porter la coordonnee dans sa charge utile, et masquer le
            // seul bloc affiche laisserait la valeur ailleurs dans le JSON.
            'subjects' => MasquageCoordonnees::masquerTableauSiRequis($subjects),
            'data' => MasquageCoordonnees::masquerTableauSiRequis(
                array_slice($activities, 0, self::MAX_ACTIVITIES),
            ),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activitiesFor(string $workspaceId, string $personKey, string $universe): array
    {
        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $personKey, $universe): array {
            $rows = DB::table('activities')
                ->where('workspace_id', $workspaceId)
                ->where('person_key', $personKey)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(self::MAX_ACTIVITIES)
                ->get();

            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'id' => (int) $row->id,
                    'universe' => $universe,
                    'kind' => $row->kind ?? $row->type,
                    'title' => $row->title,
                    'occurred_at' => $row->occurred_at ?? $row->created_at,
                    'external_ref' => $row->external_ref,
                    'subject_type' => $row->subject_type,
                    'subject_id' => $row->subject_id === null ? null : (int) $row->subject_id,
                ];
            }

            return $out;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function businessSubjects(string $workspaceId, string $personKey): array
    {
        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $personKey): array {
            $rows = DB::table('contacts')
                ->leftJoin('companies', 'companies.id', '=', 'contacts.company_id')
                ->where('contacts.workspace_id', $workspaceId)
                ->where('contacts.person_key', $personKey)
                ->select([
                    'contacts.id AS contact_id',
                    'contacts.first_name',
                    'contacts.last_name',
                    'contacts.email',
                    'contacts.phone',
                    'contacts.legal_basis',
                    'contacts.consent_version',
                    'contacts.consent_at',
                    'companies.id AS company_id',
                    'companies.denomination',
                    'companies.siren',
                    'companies.relation_type',
                    'companies.lifecycle_stage',
                ])
                ->limit(20)
                ->get();

            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'universe' => 'business',
                    'type' => 'contact',
                    'id' => (int) $row->contact_id,
                    'first_name' => $row->first_name,
                    'last_name' => $row->last_name,
                    'email' => $row->email,
                    'phone' => $row->phone,
                    'legal_basis' => $row->legal_basis,
                    'consent_version' => $row->consent_version,
                    'consent_at' => $row->consent_at,
                    'company' => $row->company_id === null ? null : [
                        'id' => (int) $row->company_id,
                        'denomination' => $row->denomination,
                        'siren' => $row->siren,
                        'relation_type' => $row->relation_type,
                        'lifecycle_stage' => $row->lifecycle_stage,
                    ],
                ];
            }

            return $out;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function vivierSubjects(string $workspaceId, string $personKey): array
    {
        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $personKey): array {
            $rows = DB::table('candidates')
                ->where('workspace_id', $workspaceId)
                ->where('person_key', $personKey)
                ->whereNull('deleted_at')
                ->limit(20)
                ->get();

            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'universe' => 'vivier',
                    'type' => 'candidate',
                    'id' => (int) $row->id,
                    'first_name' => $row->first_name,
                    'last_name' => $row->last_name,
                    'email' => $row->email,
                    'relation_type' => $row->relation_type,
                    'lifecycle_stage' => $row->lifecycle_stage,
                    'offer_slug' => $row->offer_slug,
                    'consent_version' => $row->consent_version,
                    'consent_vivier_at' => $row->consent_vivier_at,
                    'derniere_interaction_at' => $row->derniere_interaction_at,
                    'cv_ref' => $row->cv_ref,
                ];
            }

            return $out;
        });
    }

    private function existsInBusiness(string $workspaceId, string $personKey): bool
    {
        return WorkspaceContext::run($workspaceId, static fn (): bool => DB::table('contacts')
            ->where('workspace_id', $workspaceId)
            ->where('person_key', $personKey)
            ->exists());
    }

    /**
     * Requête d'EXISTENCE pure, volontairement isolée dans sa propre méthode :
     * c'est le seul endroit du code où l'on interroge le vivier sans en être
     * membre, et la seule information qui en sorte est un booléen.
     */
    private function existsInVivier(string $workspaceId, string $personKey): bool
    {
        return WorkspaceContext::run($workspaceId, static fn (): bool => DB::table('candidates')
            ->where('workspace_id', $workspaceId)
            ->where('person_key', $personKey)
            ->whereNull('deleted_at')
            ->exists());
    }
}
