<?php

namespace App\Http\Controllers\Api;

use App\Crm\Taxonomy;
use App\Models\Company;
use App\Support\MasquageCoordonnees;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * ÉVÉNEMENTS PROFESSIONNELS — la liste, la fiche, la démarche (2026-09-27).
 *
 * Lecture de `events` / `event_organizers` (RLS forcée : l'espace est posé par
 * le middleware `workspace`, et chaque requête le filtre AUSSI explicitement).
 *
 * Aucune coordonnée de personne ne sort d'ici : un organisateur est renvoyé
 * par son nom, sa nature, sa ville, son site et son formulaire de contact. Ses
 * contacts se lisent sur sa fiche, qui applique déjà le masquage selon le rôle.
 *
 * Faire avancer la démarche écrit l'état courant sur `events` ET une ligne
 * d'historique datée dans `activities` (`subject_type = 'event'`).
 */
class EvenementsController extends ApiController
{
    private const PAR_PAGE_MAX = 100;

    public function index(Request $request): JsonResponse
    {
        $workspaceId = $this->espace();
        if ($workspaceId === null) {
            return $this->ok(['error' => 'workspace required'], 422);
        }

        $filtres = $request->validate([
            // Code INSEE de région (`84`), comme `companies.region_code` — le
            // référentiel unique ; un sigle (`AURA`) n'est plus stocké.
            'region' => ['nullable', Rule::in(array_map(static fn (int|string $c): string => (string) $c, array_keys(Taxonomy::REGIONS)))],
            'type' => ['nullable', Rule::in(Taxonomy::EVENEMENT_TYPES)],
            'participation' => ['nullable', Rule::in(Taxonomy::EVENEMENT_PARTICIPATIONS)],
            'intervention' => ['nullable', Rule::in(Taxonomy::EVENEMENT_INTERVENTIONS)],
            'appel_intervenants' => ['nullable', Rule::in(Taxonomy::EVENEMENT_APPELS_INTERVENANTS)],
            'periode' => ['nullable', Rule::in(['a_venir', 'passes', 'sans_date'])],
            'relance' => ['nullable', Rule::in(['a_faire'])],
            'verifie' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:' . self::PAR_PAGE_MAX],
        ]);

        $requete = DB::table('events')->where('workspace_id', $workspaceId);
        $this->filtrer($requete, $filtres);

        $total = (clone $requete)->count();
        $parPage = (int) ($filtres['per_page'] ?? 50);
        $page = (int) ($filtres['page'] ?? 1);

        $lignes = $requete
            // Les datés d'abord, du plus proche au plus lointain ; les
            // récurrents sans date ensuite.
            ->orderByRaw('date_debut IS NULL, date_debut ASC, id ASC')
            ->forPage($page, $parPage)
            ->get();

        $organisateurs = $this->organisateurs($workspaceId, $lignes->pluck('id')->map(fn ($id) => (int) $id)->values()->all());

        return $this->ok([
            'data' => $lignes->map(fn (\stdClass $e) => $this->resume($e, $organisateurs[(int) $e->id] ?? []))->values(),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $parPage],
        ]);
    }

    public function show(int $event): JsonResponse
    {
        $workspaceId = $this->espace();
        if ($workspaceId === null) {
            return $this->ok(['error' => 'workspace required'], 422);
        }

        $ligne = DB::table('events')->where('workspace_id', $workspaceId)->where('id', $event)->first();
        if ($ligne === null) {
            abort(404);
        }

        return $this->ok($this->fiche($workspaceId, $ligne));
    }

    public function updateDemarche(Request $request, int $event): JsonResponse
    {
        $workspaceId = $this->espace();
        if ($workspaceId === null) {
            return $this->ok(['error' => 'workspace required'], 422);
        }

        $data = $request->validate([
            'participation' => ['sometimes', Rule::in(Taxonomy::EVENEMENT_PARTICIPATIONS)],
            'intervention' => ['sometimes', Rule::in(Taxonomy::EVENEMENT_INTERVENTIONS)],
            'prochaine_relance_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'demarche_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $fiche = DB::transaction(function () use ($workspaceId, $event, $data, $request): ?array {
            $ligne = DB::table('events')
                ->where('workspace_id', $workspaceId)
                ->where('id', $event)
                ->lockForUpdate()
                ->first();
            if ($ligne === null) {
                return null;
            }

            $changements = [];
            foreach (['participation', 'intervention', 'prochaine_relance_at', 'demarche_note'] as $champ) {
                if (array_key_exists($champ, $data)) {
                    $changements[$champ] = $data[$champ];
                }
            }
            if ($changements === []) {
                return $this->fiche($workspaceId, $ligne);
            }

            DB::table('events')->where('workspace_id', $workspaceId)->where('id', $event)->update($changements + ['updated_at' => now()]);

            // Une ligne d'historique par ÉTAPE franchie (pas pour la note ni la
            // date de relance, qui ne sont pas des étapes).
            $etapes = [];
            if (isset($changements['participation']) && $changements['participation'] !== $ligne->participation) {
                $etapes[] = ['evenement_' . $changements['participation'], $ligne->participation, $changements['participation']];
            }
            if (isset($changements['intervention']) && $changements['intervention'] !== $ligne->intervention
                && $changements['intervention'] !== 'aucune') {
                $etapes[] = ['intervention_' . $changements['intervention'], $ligne->intervention, $changements['intervention']];
            }

            foreach ($etapes as [$kind, $avant, $apres]) {
                DB::table('activities')->insert([
                    'workspace_id' => $workspaceId,
                    'user_id' => optional($request->user())->id,
                    'type' => $kind,
                    'kind' => $kind,
                    'occurred_at' => now(),
                    'external_ref' => 'console:event:' . $event . ':' . $kind . ':' . now()->format('Y-m-d\TH:i:s.u'),
                    'subject_type' => 'event',
                    'subject_id' => $event,
                    'title' => (string) $ligne->nom,
                    'payload' => json_encode(['event_id' => $event, 'avant' => $avant, 'apres' => $apres], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            }

            $apresMaj = DB::table('events')->where('workspace_id', $workspaceId)->where('id', $event)->first();

            return $apresMaj === null ? null : $this->fiche($workspaceId, $apresMaj);
        });

        if ($fiche === null) {
            abort(404);
        }

        return $this->ok($fiche);
    }

    /** Les événements d'un organisateur, pour le bloc de sa fiche. */
    public function pourEntreprise(Company $company): JsonResponse
    {
        $this->refuserHorsEspace($company);

        $lignes = DB::table('events')
            ->join('event_organizers', 'event_organizers.event_id', '=', 'events.id')
            ->where('event_organizers.company_id', $company->id)
            ->where('events.workspace_id', $company->workspace_id)
            ->orderByRaw('events.date_debut IS NULL, events.date_debut ASC, events.id ASC')
            ->select('events.*')
            ->limit(200)
            ->get();

        return $this->ok([
            'data' => $lignes->map(fn (\stdClass $e) => $this->resume($e, []))->values(),
        ]);
    }

    // ── Construction ────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $filtres */
    private function filtrer(Builder $requete, array $filtres): void
    {
        foreach (['region', 'type', 'participation', 'intervention', 'appel_intervenants'] as $champ) {
            if (($filtres[$champ] ?? null) !== null && $filtres[$champ] !== '') {
                $requete->where($champ, $filtres[$champ]);
            }
        }
        if (array_key_exists('verifie', $filtres) && $filtres['verifie'] !== null) {
            $requete->where('verifie', (bool) $filtres['verifie']);
        }

        $aujourdhui = now()->toDateString();
        $periode = $filtres['periode'] ?? null;
        // Un événement de plusieurs jours reste « à venir » tant qu'il n'est
        // pas terminé.
        if ($periode === 'a_venir') {
            $requete->whereRaw('COALESCE(date_fin, date_debut) >= ?', [$aujourdhui]);
        } elseif ($periode === 'passes') {
            $requete->whereRaw('COALESCE(date_fin, date_debut) < ?', [$aujourdhui]);
        } elseif ($periode === 'sans_date') {
            $requete->whereNull('date_debut');
        }

        if (($filtres['relance'] ?? null) === 'a_faire') {
            $requete->whereNotNull('prochaine_relance_at')->where('prochaine_relance_at', '<=', now());
        }

        $q = trim((string) ($filtres['q'] ?? ''));
        if ($q !== '') {
            $motif = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $requete->where(function (Builder $w) use ($motif): void {
                $w->where('nom', 'ILIKE', $motif)
                    ->orWhere('ville', 'ILIKE', $motif)
                    ->orWhereExists(function (Builder $o) use ($motif): void {
                        $o->selectRaw('1')
                            ->from('event_organizers')
                            ->join('companies', 'companies.id', '=', 'event_organizers.company_id')
                            ->whereColumn('event_organizers.event_id', 'events.id')
                            ->whereColumn('companies.workspace_id', 'event_organizers.workspace_id')
                            ->whereNull('companies.deleted_at')
                            ->where('companies.denomination', 'ILIKE', $motif);
                    });
            });
        }
    }

    /**
     * @param  array<int>  $eventIds
     * @return array<int, list<array{id: int, denomination: ?string, entity_nature: ?string}>>
     */
    private function organisateurs(string $workspaceId, array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        $parEvenement = [];
        $lignes = DB::table('event_organizers')
            ->join('companies', 'companies.id', '=', 'event_organizers.company_id')
            ->where('event_organizers.workspace_id', $workspaceId)
            ->whereIn('event_organizers.event_id', $eventIds)
            ->where('companies.workspace_id', $workspaceId)
            ->whereNull('companies.deleted_at')
            ->get(['event_organizers.event_id', 'companies.id', 'companies.denomination', 'companies.entity_nature']);

        foreach ($lignes as $l) {
            $parEvenement[(int) $l->event_id][] = [
                'id' => (int) $l->id,
                'denomination' => $l->denomination,
                'entity_nature' => $l->entity_nature,
            ];
        }

        return $parEvenement;
    }

    /**
     * @param  list<array{id: int, denomination: ?string, entity_nature: ?string}>  $organisateurs
     * @return array<string, mixed>
     */
    private function resume(\stdClass $e, array $organisateurs): array
    {
        return [
            'id' => (int) $e->id,
            'nom' => $e->nom,
            'type' => $e->type,
            'date_debut' => $e->date_debut,
            'date_fin' => $e->date_fin,
            'recurrence' => $e->recurrence,
            'heure' => $e->heure,
            'ville' => $e->ville,
            'departement_code' => $e->departement_code,
            'region' => $e->region,
            'appel_intervenants' => $e->appel_intervenants,
            'verifie' => (bool) $e->verifie,
            'participation' => $e->participation,
            'intervention' => $e->intervention,
            'prochaine_relance_at' => $e->prochaine_relance_at,
            'organisateurs' => $organisateurs,
        ];
    }

    /** @return array<string, mixed> */
    private function fiche(string $workspaceId, \stdClass $e): array
    {
        $organisateurs = DB::table('event_organizers')
            ->join('companies', 'companies.id', '=', 'event_organizers.company_id')
            ->where('event_organizers.workspace_id', $workspaceId)
            ->where('event_organizers.event_id', $e->id)
            ->where('companies.workspace_id', $workspaceId)
            ->whereNull('companies.deleted_at')
            ->get(['companies.id', 'companies.denomination', 'companies.entity_nature', 'companies.city', 'companies.website', 'companies.signals'])
            ->map(function (object $c): array {
                $signals = json_decode(is_string($c->signals) ? $c->signals : '{}', true);

                return [
                    'id' => (int) $c->id,
                    'denomination' => $c->denomination,
                    'entity_nature' => $c->entity_nature,
                    'city' => $c->city,
                    'website' => $c->website,
                    'contact_form_url' => is_array($signals) && is_string($signals['contact_form_url'] ?? null)
                        ? $signals['contact_form_url']
                        : null,
                ];
            })
            ->values()
            ->all();

        $historique = DB::table('activities')
            ->where('workspace_id', $workspaceId)
            ->where('subject_type', 'event')
            ->where('subject_id', $e->id)
            ->orderByDesc('occurred_at')
            ->limit(100)
            ->get(['id', 'kind', 'occurred_at'])
            ->map(fn (\stdClass $a): array => [
                'id' => (int) $a->id,
                'kind' => $a->kind,
                'occurred_at' => $a->occurred_at,
            ])
            ->values()
            ->all();

        // array_merge et non `+` : l'union garde la clé de GAUCHE, et la
        // liste vide de resume() masquait les organisateurs.
        return array_merge($this->resume($e, []), [
            'lieu' => $e->lieu,
            'public_vise' => $e->public_vise,
            'taille' => $e->taille,
            'prix' => $e->prix,
            'lien_evenement' => $e->lien_evenement,
            'lien_inscription' => $e->lien_inscription,
            'appel_intervenants_limite' => $e->appel_intervenants_limite,
            'source_url' => $e->source_url,
            'notes' => $e->notes,
            // Texte libre de Will : il peut contenir un nom ou un numéro malgré
            // la consigne. Masqué, comme une coordonnée, pour qui n'a pas le
            // droit de voir les coordonnées.
            'demarche_note' => MasquageCoordonnees::requis() ? null : $e->demarche_note,
            'organisateurs' => $organisateurs,
            'historique' => $historique,
        ]);
    }

    private function espace(): ?string
    {
        $id = app()->bound('workspace.id') ? app('workspace.id') : null;

        return is_string($id) && $id !== '' ? $id : null;
    }
}
