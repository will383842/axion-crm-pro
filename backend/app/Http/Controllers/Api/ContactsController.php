<?php

namespace App\Http\Controllers\Api;

use App\Models\Contact;
use App\Support\ContactQueryFilters;
use App\Support\MasquageCoordonnees;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spatie\QueryBuilder\QueryBuilder;

class ContactsController extends ApiController
{
    /**
     * @OA\Get(path="/contacts", tags={"Contacts"}, summary="Liste paginée des contacts",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Response(response=200, description="OK"))
     *
     * ⚠️ Cette méthode a longtemps renvoyé `['data' => [], 'meta' => ['total' => 0]]`
     * EN DUR. La page « Contacts » de la console était donc vide pour tout le
     * monde, alors que la base en compte 1,3 M — et personne ne l'a vu, parce
     * que les deux seuls tests qui touchaient cette route vérifiaient qu'elle
     * répondait 200 et qu'elle ne renvoyait pas 500. Un bouchon vide satisfait
     * les deux. Les tests de `ContactsIndexTest` vérifient désormais le
     * CONTENU, seule chose qu'un bouchon ne peut pas imiter.
     */
    public function index(Request $r): JsonResponse
    {
        $perPage = min(100, max(1, (int) $r->query('per_page', 25)));
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;

        // Table absente (environnement frais) ou pas de workspace résolu →
        // liste vide plutôt qu'une 500, et SURTOUT jamais de fuite entre
        // tenants : sans workspace, on ne montre rien.
        if (! Schema::hasTable('contacts') || $workspaceId === null) {
            return $this->ok(['data' => [], 'meta' => $this->meta(0, $perPage, 1, 1)]);
        }

        $masquer = MasquageCoordonnees::requis();

        try {
            $page = QueryBuilder::for(
                Contact::query()
                    // Scope EXPLICITE en plus de la RLS : la défense en
                    // profondeur du lot L0 vaut pour les deux couches.
                    ->where('workspace_id', $workspaceId)
                    ->whereNull('deleted_at')
                    ->with('company:id,denomination'),
            )
                ->allowedFilters(...ContactQueryFilters::allowed())
                ->allowedSorts(...['last_name', 'email_score', 'created_at'])
                // Tri par défaut sur `id` DÉCROISSANT, adossé à l'index
                // `(workspace_id, id DESC)` : un tri sur `last_name` aurait
                // imposé un tri sur disque de 1,3 M de lignes pour en afficher
                // 50 — le défaut mesuré à 6,4 s sur la liste entreprises.
                ->defaultSort('-id')
                ->paginate($perPage)
                ->appends($r->query());

            return $this->ok([
                'data' => array_map(fn (Contact $c): array => $this->present($c, $masquer), $page->items()),
                'meta' => $this->meta($page->total(), $page->perPage(), $page->currentPage(), $page->lastPage()),
            ]);
        } catch (\Throwable $e) {
            Log::error('contacts.index failed', ['exception' => $e->getMessage()]);
            report($e);

            return $this->ok([
                'data' => [],
                'meta' => $this->meta(0, $perPage, 1, 1) + ['degraded' => true],
            ]);
        }
    }

    /**
     * @return array{total: int, per_page: int, current_page: int, last_page: int}
     */
    private function meta(int $total, int $perPage, int $currentPage, int $lastPage): array
    {
        return ['total' => $total, 'per_page' => $perPage, 'current_page' => $currentPage, 'last_page' => $lastPage];
    }

    /**
     * Projection EXPLICITE : renvoyer le modèle brut exposerait au fil de l'eau
     * toute colonne ajoutée par une migration future — y compris des colonnes
     * qui n'ont rien à faire dans une liste (`normalized_hash`, `metadata`…).
     *
     * @return array<string, mixed>
     */
    private function present(Contact $c, bool $masquer): array
    {
        return [
            'id' => $c->id,
            'first_name' => $c->first_name,
            'last_name' => $c->last_name,
            'role' => $c->role,
            // Coordonnées masquées pour les comptes en lecture seule (§2.10).
            // Le masquage est décidé UNE fois par requête, pas par ligne : sur
            // 50 lignes, cinquante vérifications de permission identiques.
            'email' => $masquer ? MasquageCoordonnees::email($c->email) : $c->email,
            'email_status' => $c->email_status,
            'email_score' => $c->email_score,
            'phone' => $masquer ? MasquageCoordonnees::telephone($c->phone) : $c->phone,
            'linkedin_url' => $c->linkedin_url,
            'discovery_source' => $c->discovery_source,
            'company_id' => $c->company_id,
            'company' => $c->company === null ? null : [
                'id' => $c->company->id,
                'denomination' => $c->company->denomination,
            ],
        ];
    }

    /**
     * @OA\Get(path="/contacts/{contact}", tags={"Contacts"}, summary="Détail contact",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="contact", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="OK"))
     */
    public function show(Contact $contact): JsonResponse
    {
        // Sans ce refus, un compte d'un autre espace lisait la fiche en devinant
        // l'identifiant — et ce sont des entiers consecutifs (F36-005, S0).
        $this->refuserHorsEspace($contact);

        return $this->ok($contact);
    }

    /**
     * @OA\Put(path="/contacts/{contact}", tags={"Contacts"}, summary="Update contact (Sprint 5)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="contact", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function update(Request $r, Contact $contact): JsonResponse
    {
        // Constat B12-001 / F36-005 : la resolution de route rendait
        // l'enregistrement sans aucun filtre d'espace. 404, jamais 403 :
        // « interdit » confirmerait son existence.
        $this->refuserHorsEspace($contact);

        return $this->notImplemented('5');
    }

    /**
     * @OA\Delete(path="/contacts/{contact}", tags={"Contacts"}, summary="Delete contact (Sprint 5)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="contact", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function destroy(Contact $contact): JsonResponse
    {
        // Constat B12-001 / F36-005 : la resolution de route rendait
        // l'enregistrement sans aucun filtre d'espace. 404, jamais 403 :
        // « interdit » confirmerait son existence.
        $this->refuserHorsEspace($contact);

        return $this->notImplemented('5');
    }
}
