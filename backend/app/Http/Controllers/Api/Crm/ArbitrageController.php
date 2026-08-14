<?php

namespace App\Http\Controllers\Api\Crm;

use App\Crm\Console\ConsoleAccess;
use App\Crm\Ingest\ContactUpserter;
use App\Crm\Ingest\SiteSyncClassifier;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FILE D'ARBITRAGE des rapprochements (plan §2.4, conception §2.2 et §3e).
 *
 * ── D'où viennent ces lignes ───────────────────────────────────────────────
 * Quand le site envoie un formulaire SANS SIREN, l'ingestion (lot L2) refuse
 * délibérément de deviner : rapprocher une entreprise par sa dénomination est
 * une proposition, jamais une certitude, avec 4,29 M de fiches INSEE en face.
 * Elle écrit alors une activité `subject_id = NULL` portant tout ce qu'elle
 * sait dans `payload->pending_match`. Cet écran est la contrepartie de ce
 * refus : sans lui, le refus de deviner serait une perte de données.
 *
 * ── Pourquoi c'est business-only ───────────────────────────────────────────
 * Un candidat n'a pas de SIREN à rapprocher (plan §2.1) : la file n'existe pas
 * dans l'univers vivier, et l'y demander vaut 403 — pas une liste vide.
 *
 * ── Pourquoi le rattachement RÉUTILISE l'ingestion ─────────────────────────
 * `ContactUpserter` est le MÊME code que celui de la synchro automatique.
 * Réécrire la déduplication ici aurait produit deux implémentations
 * divergentes, c'est-à-dire, à terme, des doublons créés par l'écran censé les
 * résoudre.
 */
class ArbitrageController extends ConsoleController
{
    public function __construct(
        private readonly ContactUpserter $contacts,
        private readonly SiteSyncClassifier $classifier,
    ) {}

    /**
     * File d'attente : événements sans sujet, non écartés, les plus anciens
     * d'abord (une file se traite par le haut, sinon elle n'est pas une file).
     */
    public function index(Request $request): JsonResponse
    {
        $workspaceId = $this->arbitrageWorkspace($request);
        $perPage = $this->perPage($request);

        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $perPage): JsonResponse {
            $query = DB::table('activities')
                ->where('workspace_id', $workspaceId)
                ->whereNull('subject_id')
                ->whereRaw("payload -> 'pending_match' IS NOT NULL")
                ->whereRaw("payload -> 'arbitrage_dismissed' IS NULL");

            $total = (clone $query)->count();

            $rows = $query->orderBy('occurred_at')->orderBy('id')->limit($perPage)->get();

            $data = [];
            foreach ($rows as $row) {
                $payload = $this->decodePayload($row->payload ?? null);
                $pending = $payload['pending_match'] ?? [];

                $data[] = [
                    'activity_id' => (int) $row->id,
                    'kind' => $row->kind ?? $row->type,
                    'title' => $row->title,
                    'occurred_at' => $row->occurred_at ?? $row->created_at,
                    'external_ref' => $row->external_ref,
                    'person_key' => $row->person_key,
                    'pending_match' => is_array($pending) ? $pending : [],
                ];
            }

            return $this->ok([
                'data' => $data,
                'meta' => ['total' => $total, 'per_page' => $perPage],
            ]);
        });
    }

    /**
     * RATTACHEMENT — l'acte central de l'écran.
     *
     * Tout se fait dans UNE transaction : si la création de la fiche personne
     * échoue, l'activité ne doit pas rester rattachée à une entreprise sans
     * interlocuteur. Un rattachement à moitié fait est pire qu'un rattachement
     * pas fait — le premier sort de la file, donc personne n'y revient.
     */
    public function attach(Request $request, int $activityId): JsonResponse
    {
        $workspaceId = $this->arbitrageWorkspace($request);

        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'min:1'],
        ]);
        $companyId = (int) $validated['company_id'];

        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $activityId, $companyId, $request): JsonResponse {
            return DB::transaction(function () use ($workspaceId, $activityId, $companyId, $request): JsonResponse {
                $activity = $this->lockedActivity($workspaceId, $activityId);

                if ($activity->subject_id !== null) {
                    abort(409, 'Cet événement est déjà rattaché.');
                }

                $payload = $this->decodePayload($activity->payload ?? null);
                $pending = $payload['pending_match'] ?? null;
                if (! is_array($pending)) {
                    abort(409, "Cet événement n'est pas en attente de rapprochement.");
                }

                $company = DB::table('companies')
                    ->where('workspace_id', $workspaceId)
                    ->where('id', $companyId)
                    ->whereNull('deleted_at')
                    ->first();

                if ($company === null) {
                    // 404 et non 403 : côté opérateur, une entreprise d'un autre
                    // workspace et une entreprise inexistante sont la même chose
                    // — et doivent le rester, sinon la réponse devient un
                    // oracle d'existence inter-tenants.
                    abort(404, 'Entreprise introuvable dans cet univers.');
                }

                $legalBasis = $this->stringOrNull($pending, 'legal_basis') ?? 'precontractual';

                // La base légale enregistrée à l'ingestion l'emporte sur celle
                // d'une fiche collectée (`legitimate_interest_b2b`) : la
                // personne s'est manifestée. On ne redescend jamais.
                DB::table('companies')->where('id', $companyId)->update([
                    'legal_basis' => $this->classifier->mergeLegalBasis(
                        is_string($company->legal_basis ?? null) ? $company->legal_basis : null,
                        $legalBasis,
                    ),
                    'updated_at' => now(),
                ]);

                $contactId = null;
                $personKey = is_string($activity->person_key) ? $activity->person_key : null;

                if ($personKey !== null) {
                    $upserted = $this->contacts->upsert(
                        workspaceId: $workspaceId,
                        companyId: $companyId,
                        personKey: $personKey,
                        externalRef: $this->stringOrNull($pending, 'subject_ref'),
                        email: $this->stringOrNull($pending, 'email'),
                        firstName: $this->stringOrNull($pending, 'first_name'),
                        lastName: $this->stringOrNull($pending, 'last_name'),
                        phone: $this->stringOrNull($pending, 'phone'),
                        legalBasis: $legalBasis,
                        consentVersion: $this->stringOrNull($pending, 'consent_version'),
                        consentAt: $this->dateOrNull($pending, 'consent_at'),
                        consentTextRef: $this->stringOrNull($pending, 'consent_text_ref'),
                        discoverySource: 'site',
                    );

                    $contactId = $upserted === null ? null : $upserted[0];
                }

                $payload['arbitrage'] = [
                    'attached_at' => now()->toIso8601String(),
                    'attached_by' => (string) $this->currentUser($request)->getKey(),
                    'company_id' => $companyId,
                    'contact_id' => $contactId,
                ];

                DB::table('activities')->where('id', $activityId)->update([
                    'subject_type' => 'company',
                    'subject_id' => $companyId,
                    'contact_id' => $contactId,
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                ]);

                return $this->ok([
                    'activity_id' => $activityId,
                    'company_id' => $companyId,
                    'contact_id' => $contactId,
                    // `null` ici n'est pas un échec : c'est le cas « aucun nom
                    // transmis », où l'on refuse de fabriquer un patronyme
                    // depuis une adresse électronique. L'événement reste dans la
                    // timeline de l'entreprise.
                    'contact_created' => $contactId !== null,
                ]);
            });
        });
    }

    /**
     * ÉCARTER — l'autre issue, celle qu'on oublie toujours de construire.
     *
     * Un événement qu'on ne sait pas rattacher doit pouvoir sortir de la file
     * SANS être rattaché n'importe où : sans cette porte, l'opérateur finit par
     * rattacher au hasard pour vider l'écran. Le motif est obligatoire — c'est
     * ce qui distingue « écarté » de « perdu ».
     */
    public function dismiss(Request $request, int $activityId): JsonResponse
    {
        $workspaceId = $this->arbitrageWorkspace($request);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $reason = (string) $validated['reason'];

        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $activityId, $reason, $request): JsonResponse {
            return DB::transaction(function () use ($workspaceId, $activityId, $reason, $request): JsonResponse {
                $activity = $this->lockedActivity($workspaceId, $activityId);

                if ($activity->subject_id !== null) {
                    abort(409, 'Cet événement est déjà rattaché.');
                }

                $payload = $this->decodePayload($activity->payload ?? null);
                $payload['arbitrage_dismissed'] = [
                    'reason' => $reason,
                    'at' => now()->toIso8601String(),
                    'by' => (string) $this->currentUser($request)->getKey(),
                ];

                DB::table('activities')->where('id', $activityId)->update([
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                ]);

                return $this->ok(['activity_id' => $activityId, 'dismissed' => true]);
            });
        });
    }

    /**
     * Workspace de la file — business, jamais le vivier.
     */
    private function arbitrageWorkspace(Request $request): string
    {
        $user = $this->currentUser($request);

        if (ConsoleAccess::currentIsVivier($user)) {
            abort(403, "L'arbitrage des rapprochements n'existe pas dans l'univers vivier.");
        }

        return $this->businessWorkspace($request);
    }

    /**
     * Verrou de ligne : deux opérateurs (ou deux onglets) qui rattachent le
     * même événement doivent produire un 409, pas deux fiches personne.
     */
    private function lockedActivity(string $workspaceId, int $activityId): \stdClass
    {
        $activity = DB::table('activities')
            ->where('workspace_id', $workspaceId)
            ->where('id', $activityId)
            ->lockForUpdate()
            ->first();

        if ($activity === null) {
            abort(404);
        }

        return $activity;
    }

    /** @return array<string, mixed> */
    private function decodePayload(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }

    /** @param  array<mixed>  $data */
    private function stringOrNull(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @param  array<mixed>  $data */
    private function dateOrNull(array $data, string $key): ?\DateTimeImmutable
    {
        $value = $this->stringOrNull($data, $key);
        if ($value === null) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
