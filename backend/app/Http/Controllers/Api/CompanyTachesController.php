<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * TÂCHES PROPRES AU CRM sur une ENTREPRISE (lot A4).
 *
 * Même modèle que les tâches par personne (`Crm\PersonnesController::storeTache`
 * et `terminerTache`) : une ligne `activities` de nature `task`, avec son
 * échéance (`due_at`) et sa clôture (`done_at`). Seul le sujet change :
 * `subject_type = 'company'`, convention déjà portée par `FusionFiches` (une
 * fusion de fiches rattache donc ces tâches à la fiche conservée). Aucune
 * migration.
 *
 * Ces tâches restent DANS le CRM : rien n'est émis vers la console. Et on ne
 * supprime jamais une tâche : on la marque faite.
 */
class CompanyTachesController extends ApiController
{
    public function index(Company $company): JsonResponse
    {
        $this->refuserHorsEspace($company);

        $lignes = DB::table('activities')
            ->where('workspace_id', $company->workspace_id)
            ->where('subject_type', 'company')
            ->where('subject_id', $company->id)
            ->where('kind', 'task')
            ->orderByRaw('done_at IS NOT NULL, due_at IS NULL, due_at ASC, id DESC')
            ->limit(200)
            ->get(['id', 'title', 'content', 'due_at', 'done_at']);

        return $this->ok(['data' => $lignes->map(static fn (\stdClass $t): array => [
            'id' => (int) $t->id,
            'title' => (string) $t->title,
            'content' => $t->content,
            'due_at' => $t->due_at,
            'done_at' => $t->done_at,
            'done' => $t->done_at !== null,
        ])->values()]);
    }

    public function store(Request $request, Company $company): JsonResponse
    {
        $this->refuserHorsEspace($company);

        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:300'],
            'content' => ['nullable', 'string', 'max:5000'],
            'due_at' => ['nullable', 'date'],
        ]);

        // Un double clic ne crée qu'une ligne (empreinte du geste à la minute,
        // comme sur la fiche personne).
        $ref = 'console:company:' . $company->id . ':task:' . now()->format('Y-m-d\TH:i') . ':' . substr(sha1((string) $data['title']), 0, 12);

        // `insertOrIgnore` (ON CONFLICT DO NOTHING sur l'index unique
        // workspace + external_ref) : deux clics simultanés ne donnent jamais
        // d'erreur 500, le second relit la ligne du premier.
        $cree = DB::table('activities')->insertOrIgnore([
            'workspace_id' => $company->workspace_id,
            'user_id' => $request->user()?->getKey(),
            'type' => 'task',
            'kind' => 'task',
            'occurred_at' => now(),
            'due_at' => isset($data['due_at']) ? new DateTimeImmutable((string) $data['due_at']) : null,
            'external_ref' => $ref,
            'subject_type' => 'company',
            'subject_id' => $company->id,
            'title' => (string) $data['title'],
            'content' => isset($data['content']) ? (string) $data['content'] : null,
            'payload' => json_encode(['surface' => 'console:entreprises', 'saisie' => 'manuelle'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        $activityId = (int) DB::table('activities')
            ->where('workspace_id', $company->workspace_id)
            ->where('external_ref', $ref)
            ->value('id');

        if ($cree === 0) {
            return $this->ok(['activity_id' => $activityId, 'deja_consignee' => true]);
        }

        return $this->ok(['activity_id' => $activityId, 'deja_consignee' => false], 201);
    }

    public function terminer(Company $company, int $activityId): JsonResponse
    {
        $this->refuserHorsEspace($company);

        $mises = DB::table('activities')
            ->where('workspace_id', $company->workspace_id)
            ->where('id', $activityId)
            ->where('subject_type', 'company')
            ->where('subject_id', $company->id)
            ->where('kind', 'task')
            ->whereNull('done_at')
            ->update(['done_at' => now()]);

        if ($mises === 0) {
            abort(404, 'Tâche introuvable ou déjà terminée.');
        }

        return $this->ok(['activity_id' => $activityId, 'done' => true]);
    }
}
