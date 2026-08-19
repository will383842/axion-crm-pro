<?php

namespace App\Http\Controllers\Api\Crm;

use App\Crm\Console\CompteursHub;
use App\Crm\Ingest\SiteSyncClassifier;
use App\Crm\Taxonomy;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ACTIONS DE MASSE (plan §2.11, conception §3c).
 *
 * ── Ce que cette route ne fait PAS, et n'a pas à refuser ───────────────────
 * Il n'y a pas d'action « changer le type » ni « déplacer vers l'autre
 * univers ». Ce n'est pas un refus à l'exécution, c'est une ABSENCE : une
 * frontière qu'on peut demander à franchir, même pour se voir opposer un
 * refus, est une frontière qu'un jour quelqu'un franchira par un bug de
 * validation. Le seul chemin reste l'action unitaire « créer une fiche dans
 * l'autre univers » (plan §2.2), hors périmètre de ce lot.
 *
 * ── « On ne recule jamais » ────────────────────────────────────────────────
 * `set_lifecycle` ne fait JAMAIS reculer une étape. La règle vient du plan
 * §2.2b et elle est appliquée par le MÊME code que l'ingestion
 * (`SiteSyncClassifier::mergeLifecycleStage` / `mergeCandidateLifecycleStage`),
 * pour qu'il n'existe pas deux définitions du mot « reculer ».
 *
 * Les fiches concernées ne sont pas modifiées et reviennent dans
 * `refused_regressions` : un silence aurait laissé croire à un succès, et
 * « ignoré » aurait pu passer pour une panne. Le recul individuel — parfois
 * légitime, un client redevenu prospect après résiliation — reste possible par
 * l'édition unitaire, où il est motivé et journalisé. En masse, il ne l'est
 * jamais : on ne motive pas 400 fiches d'un seul clic.
 */
class BulkController extends ConsoleController
{
    private const MAX_IDS = 500;

    public function __construct(private readonly SiteSyncClassifier $classifier) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity' => ['nullable', 'string', 'in:company,candidate'],
            'ids' => ['required', 'array', 'min:1', 'max:' . self::MAX_IDS],
            'ids.*' => ['integer', 'min:1'],
            'action' => ['required', 'string', 'in:add_tag,remove_tag,set_lifecycle'],
            'params' => ['nullable', 'array'],
            'params.tag' => ['nullable', 'string', 'max:120'],
            'params.stage' => ['nullable', 'string', 'max:40'],
        ]);

        $entity = is_string($validated['entity'] ?? null) ? $validated['entity'] : 'company';
        $isCandidate = $entity === 'candidate';

        $workspaceId = $isCandidate
            ? $this->vivierWorkspace($request)
            : $this->businessWorkspace($request);

        /** @var list<int> $ids */
        $ids = array_values(array_unique(array_map('intval', $validated['ids'])));
        $params = is_array($validated['params'] ?? null) ? $validated['params'] : [];
        $action = (string) $validated['action'];

        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $isCandidate, $ids, $action, $params, $request): JsonResponse {
            return DB::transaction(function () use ($workspaceId, $isCandidate, $ids, $action, $params, $request): JsonResponse {
                $result = match ($action) {
                    'add_tag' => $this->addTag($workspaceId, $isCandidate, $ids, $params),
                    'remove_tag' => $this->removeTag($workspaceId, $isCandidate, $ids, $params),
                    default => $this->setLifecycle($workspaceId, $isCandidate, $ids, $params, $request),
                };

                return $this->ok($result);
            });
        });
    }

    /**
     * Poser un tag. Le tag doit PRÉEXISTER dans le workspace : la gouvernance
     * du référentiel (plan §2.2c) interdit la création à la volée, ici comme à
     * l'ingestion. Un référentiel qu'on peut étendre pendant une action de
     * masse n'est pas un référentiel.
     *
     * @param  list<int>  $ids
     * @param  array<mixed>  $params
     * @return array<string, mixed>
     */
    private function addTag(string $workspaceId, bool $isCandidate, array $ids, array $params): array
    {
        $tagId = $this->requireTagId($workspaceId, $params);
        [$pivot, $key, $table] = $this->pivotFor($isCandidate);

        $existing = $this->existingIds($workspaceId, $table, $ids);

        $rows = [];
        foreach ($existing as $id) {
            $rows[] = [
                $key => $id,
                'tag_id' => $tagId,
                'workspace_id' => $workspaceId,
                'assigned_at' => now(),
                // Vocabulaire FERMÉ par un CHECK SQL (`auto-rule | llm | user`).
                // Une action de masse est déclenchée par un humain : c'est
                // `user`. Inventer une quatrième valeur « bulk » aurait exigé
                // une migration du CHECK — le CHECK a d'ailleurs refusé la
                // tentative, ce qui est exactement son rôle.
                'assigned_by' => 'user',
            ];
        }

        if ($rows !== []) {
            DB::table($pivot)->insertOrIgnore($rows);
        }

        return [
            'action' => 'add_tag',
            'matched' => count($existing),
            'updated' => count($existing),
            'skipped' => array_values(array_diff($ids, $existing)),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @param  array<mixed>  $params
     * @return array<string, mixed>
     */
    private function removeTag(string $workspaceId, bool $isCandidate, array $ids, array $params): array
    {
        $tagId = $this->requireTagId($workspaceId, $params);
        [$pivot, $key] = $this->pivotFor($isCandidate);

        $removed = DB::table($pivot)
            ->where('workspace_id', $workspaceId)
            ->where('tag_id', $tagId)
            ->whereIn($key, $ids)
            ->delete();

        return [
            'action' => 'remove_tag',
            'matched' => count($ids),
            'updated' => $removed,
            'skipped' => [],
        ];
    }

    /**
     * Changer l'étape — SANS jamais reculer.
     *
     * @param  list<int>  $ids
     * @param  array<mixed>  $params
     * @return array<string, mixed>
     */
    private function setLifecycle(string $workspaceId, bool $isCandidate, array $ids, array $params, Request $request): array
    {
        $stage = $params['stage'] ?? null;
        $allowed = $isCandidate
            ? Taxonomy::CANDIDATE_LIFECYCLE_STAGES
            : Taxonomy::BUSINESS_LIFECYCLE_STAGES;

        if (! is_string($stage) || ! in_array($stage, $allowed, true)) {
            abort(422, 'Étape inconnue pour cet univers : ' . implode(' | ', $allowed) . '.');
        }

        $table = $isCandidate ? 'candidates' : 'companies';

        $rows = DB::table($table)
            ->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at')
            ->whereIn('id', $ids)
            ->lockForUpdate()
            ->get(['id', 'lifecycle_stage']);

        $toUpdate = [];
        $regressions = [];
        $unchanged = [];

        foreach ($rows as $row) {
            $current = is_string($row->lifecycle_stage) ? $row->lifecycle_stage : null;
            $merged = $isCandidate
                ? $this->classifier->mergeCandidateLifecycleStage($current, $stage)
                : $this->classifier->mergeLifecycleStage($current, $stage);

            if ($merged !== $stage) {
                // Le moteur d'arbitrage a REFUSÉ la valeur demandée : c'est un
                // recul. On ne l'applique pas, et on le DIT.
                $regressions[] = ['id' => (int) $row->id, 'from' => $current, 'refused' => $stage];

                continue;
            }

            if ($current === $stage) {
                $unchanged[] = (int) $row->id;

                continue;
            }

            $toUpdate[] = ['id' => (int) $row->id, 'from' => $current];
        }

        if ($toUpdate !== []) {
            DB::table($table)
                ->whereIn('id', array_column($toUpdate, 'id'))
                ->update(['lifecycle_stage' => $stage, 'updated_at' => now()]);

            $this->journal($workspaceId, $isCandidate, $toUpdate, $stage, $request);

            // Les pastilles de la navigation comptent PAR ÉTAPE : déplacer des
            // fiches d'une case à l'autre sans oublier le cache laisserait
            // l'écran afficher jusqu'à cinq minutes des chiffres que l'on vient
            // soi-même de contredire.
            //
            // `afterCommit` et non ici même : nous sommes dans une transaction.
            // Vider le cache avant le COMMIT ouvre la fenêtre où une lecture
            // concurrente le REMPLIT avec les valeurs d'AVANT — et les fige
            // alors pour toute la fenêtre de fraîcheur. L'oubli doit suivre le
            // commit, jamais le précéder.
            if (! $isCandidate) {
                DB::afterCommit(static function () use ($workspaceId): void {
                    CompteursHub::oublier($workspaceId);
                });
            }
        }

        return [
            'action' => 'set_lifecycle',
            'stage' => $stage,
            'matched' => $rows->count(),
            'updated' => count($toUpdate),
            'unchanged' => $unchanged,
            // Nom explicite : « ignoré » aurait pu passer pour une erreur
            // technique. Ce sont des refus de RÈGLE, et ils sont attendus.
            'refused_regressions' => $regressions,
        ];
    }

    /**
     * Journal de la timeline : une ligne `stage_changed` par fiche modifiée
     * (plan §2.2, « action explicite, motivée et journalisée »). Le motif n'est
     * pas demandé en masse — la qualification rapide sans motif est un choix
     * acté (conception §5, choix n°3) — mais l'auteur et l'ancienne valeur, si.
     *
     * @param  list<array{id: int, from: ?string}>  $changes
     */
    private function journal(string $workspaceId, bool $isCandidate, array $changes, string $stage, Request $request): void
    {
        $userId = (string) $this->currentUser($request)->getKey();
        $subjectType = $isCandidate ? 'candidate' : 'company';
        $now = now();

        $rows = [];
        foreach ($changes as $change) {
            $rows[] = [
                'workspace_id' => $workspaceId,
                // `type` (texte libre, historique) reçoit la même valeur que
                // `kind` — phase « expand » d'expand/migrate/contract.
                'type' => 'stage_changed',
                'kind' => 'stage_changed',
                'occurred_at' => $now,
                'subject_type' => $subjectType,
                'subject_id' => $change['id'],
                'user_id' => $userId,
                'title' => 'Étape : ' . ($change['from'] ?? '—') . ' → ' . $stage,
                'payload' => json_encode([
                    'from' => $change['from'],
                    'to' => $stage,
                    'source' => 'console:bulk',
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ];
        }

        DB::table('activities')->insert($rows);
    }

    /**
     * @param  array<mixed>  $params
     */
    private function requireTagId(string $workspaceId, array $params): int
    {
        $slug = $params['tag'] ?? null;
        if (! is_string($slug) || trim($slug) === '') {
            abort(422, 'Le slug du tag est obligatoire.');
        }

        $tagId = DB::table('tags')
            ->where('workspace_id', $workspaceId)
            ->where('slug', trim($slug))
            ->value('id');

        if ($tagId === null) {
            abort(422, 'Tag inconnu dans cet univers : « ' . trim($slug) . ' ». Les tags ne se créent pas depuis une action de masse.');
        }

        return (int) $tagId;
    }

    /**
     * @return array{0: string, 1: string, 2: string} [pivot, clé, table]
     */
    private function pivotFor(bool $isCandidate): array
    {
        return $isCandidate
            ? ['candidate_tag', 'candidate_id', 'candidates']
            : ['company_tag', 'company_id', 'companies'];
    }

    /**
     * Identifiants réellement présents DANS CE WORKSPACE — un id d'un autre
     * univers n'est pas une erreur à signaler en détail, il est simplement
     * absent du résultat.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function existingIds(string $workspaceId, string $table, array $ids): array
    {
        $found = DB::table($table)
            ->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at')
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        return array_values(array_map(static fn (mixed $id): int => (int) $id, $found));
    }
}
