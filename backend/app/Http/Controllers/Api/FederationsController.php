<?php

namespace App\Http\Controllers\Api;

use App\Crm\Federations\EtiquettesFederation;
use App\Crm\Taxonomy;
use App\Support\MasquageCoordonnees;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * FÉDÉRATIONS ET ORGANISATIONS PROFESSIONNELLES — la liste, la fiche, la
 * démarche « partenariat » (chantier 3, 2026-09-29).
 *
 * Lecture de `federations` jointe à `companies` (RLS forcée sur les deux :
 * l'espace est posé par le middleware `workspace`, et chaque requête le filtre
 * AUSSI explicitement). La liste ne rend aucune coordonnée ; la fiche rend
 * celles de l'organisme et de ses contacts, MASQUÉES pour un compte sans
 * `contacts.view_pii` (`MasquageCoordonnees`).
 *
 * Faire avancer le partenariat écrit l'état courant sur `federations` ET une
 * ligne d'historique datée dans `activities` (`subject_type = 'company'`) —
 * même patron que la démarche d'un événement.
 */
class FederationsController extends ApiController
{
    private const PAR_PAGE_MAX = 100;

    private const ANTENNES_MAX = 500;

    public function index(Request $request): JsonResponse
    {
        $workspaceId = $this->espace();
        if ($workspaceId === null) {
            return $this->ok(['error' => 'workspace required'], 422);
        }

        $filtres = $request->validate([
            'famille' => ['nullable', Rule::in(array_keys(Taxonomy::FEDERATION_FAMILLES))],
            'niveau' => ['nullable', Rule::in(array_keys(Taxonomy::FEDERATION_NIVEAUX))],
            'secteur' => ['nullable', Rule::in(Taxonomy::secteursRepresentables())],
            'taille_adherents' => ['nullable', Rule::in(array_keys(Taxonomy::TAILLES))],
            'pertinence' => ['nullable', Rule::in(array_keys(Taxonomy::FEDERATION_PERTINENCES))],
            'contactabilite' => ['nullable', Rule::in(array_keys(Taxonomy::FEDERATION_CONTACTABILITES))],
            'partenariat' => ['nullable', Rule::in(array_keys(Taxonomy::FEDERATION_PARTENARIATS))],
            'region' => ['nullable', Rule::in(array_map(static fn (int|string $c): string => (string) $c, array_keys(Taxonomy::REGIONS)))],
            'departement' => ['nullable', 'string', 'regex:/^(\d{2,3}|2[AB])$/i'],
            'evenement_a_venir' => ['nullable', 'boolean'],
            'tete' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:' . self::PAR_PAGE_MAX],
        ]);

        $requete = $this->base($workspaceId);
        $this->filtrer($requete, $filtres);

        $total = (clone $requete)->count();
        $parPage = (int) ($filtres['per_page'] ?? 50);
        $page = (int) ($filtres['page'] ?? 1);

        $lignes = $requete
            // Les plus utiles d'abord : pertinence, puis niveau (national avant
            // local), puis le nom.
            ->orderByRaw("CASE f.pertinence WHEN 'haute' THEN 0 WHEN 'moyenne' THEN 1 ELSE 2 END")
            ->orderByRaw("CASE f.niveau WHEN 'national' THEN 0 WHEN 'regional' THEN 1 WHEN 'departemental' THEN 2 ELSE 3 END")
            ->orderBy('c.denomination')
            ->orderBy('c.id')
            ->forPage($page, $parPage)
            ->get([
                'c.id', 'c.denomination', 'c.city', 'c.department_code', 'c.region_code', 'c.entity_nature',
                'f.sigle', 'f.famille', 'f.niveau', 'f.secteurs', 'f.tailles_adherents', 'f.pertinence',
                'f.contactabilite', 'f.certitude', 'f.partenariat', 'f.partenariat_relance_at',
                'f.parent_company_id', 't.denomination AS tete_denomination',
            ]);

        $ids = $lignes->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $antennes = $this->compterAntennes($workspaceId, $ids);
        $avenir = $this->avecEvenementAVenir($workspaceId, $ids);

        return $this->ok([
            'data' => $lignes->map(fn (\stdClass $l): array => $this->resume($l, $antennes[(int) $l->id] ?? 0, isset($avenir[(int) $l->id])))->values(),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $parPage],
        ]);
    }

    public function show(int $federation): JsonResponse
    {
        $workspaceId = $this->espace();
        if ($workspaceId === null) {
            return $this->ok(['error' => 'workspace required'], 422);
        }

        $ligne = $this->ligne($workspaceId, $federation);
        if ($ligne === null) {
            abort(404);
        }

        return $this->ok($this->fiche($workspaceId, $ligne));
    }

    public function updateDemarche(Request $request, int $federation): JsonResponse
    {
        $workspaceId = $this->espace();
        if ($workspaceId === null) {
            return $this->ok(['error' => 'workspace required'], 422);
        }

        $data = $request->validate([
            'partenariat' => ['sometimes', Rule::in(array_keys(Taxonomy::FEDERATION_PARTENARIATS))],
            'partenariat_relance_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'partenariat_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $company = $federation;
        $fiche = DB::transaction(function () use ($workspaceId, $company, $data, $request): ?array {
            $actuelle = DB::table('federations')
                ->where('workspace_id', $workspaceId)
                ->where('company_id', $company)
                ->lockForUpdate()
                ->first();
            if ($actuelle === null) {
                return null;
            }

            $changements = [];
            foreach (['partenariat', 'partenariat_relance_at', 'partenariat_note'] as $champ) {
                if (array_key_exists($champ, $data)) {
                    $changements[$champ] = $data[$champ];
                }
            }

            if ($changements !== []) {
                DB::table('federations')
                    ->where('workspace_id', $workspaceId)
                    ->where('company_id', $company)
                    ->update($changements + ['updated_at' => now()]);

                // Une ligne d'historique par ÉTAPE franchie (pas pour la note
                // ni la date de relance, qui ne sont pas des étapes).
                $apres = $changements['partenariat'] ?? null;
                if ($apres !== null && $apres !== $actuelle->partenariat && $apres !== 'aucun') {
                    $kind = 'partenariat_' . $apres;
                    $nom = DB::table('companies')->where('workspace_id', $workspaceId)->where('id', $company)->value('denomination');
                    DB::table('activities')->insert([
                        'workspace_id' => $workspaceId,
                        'user_id' => optional($request->user())->id,
                        'type' => $kind,
                        'kind' => $kind,
                        'occurred_at' => now(),
                        'external_ref' => 'console:federation:' . $company . ':' . $kind . ':' . now()->format('Y-m-d\TH:i:s.u'),
                        'subject_type' => 'company',
                        'subject_id' => $company,
                        'title' => is_string($nom) ? $nom : null,
                        'payload' => json_encode(['avant' => $actuelle->partenariat, 'apres' => $apres], JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                    ]);
                }
            }

            $ligne = $this->ligne($workspaceId, $company);

            return $ligne === null ? null : $this->fiche($workspaceId, $ligne);
        });

        if ($fiche === null) {
            abort(404);
        }

        return $this->ok($fiche);
    }

    // ── Construction ────────────────────────────────────────────────────────

    private function base(string $workspaceId): Builder
    {
        return DB::table('federations as f')
            ->join('companies as c', 'c.id', '=', 'f.company_id')
            ->leftJoin('companies as t', function ($j) use ($workspaceId): void {
                $j->on('t.id', '=', 'f.parent_company_id')
                    ->where('t.workspace_id', '=', $workspaceId)
                    ->whereNull('t.deleted_at');
            })
            ->where('f.workspace_id', $workspaceId)
            ->where('c.workspace_id', $workspaceId)
            ->whereNull('c.deleted_at');
    }

    private function ligne(string $workspaceId, int $companyId): ?\stdClass
    {
        $ligne = $this->base($workspaceId)
            ->where('c.id', $companyId)
            ->first([
                'c.id', 'c.siren', 'c.denomination', 'c.entity_nature', 'c.naf', 'c.legal_form',
                'c.effectif_range', 'c.address', 'c.postcode', 'c.city', 'c.department_code', 'c.region_code',
                'c.sector_main', 'c.website', 'c.linkedin_url', 'c.phone', 'c.email_generic', 'c.signals',
                'f.sigle', 'f.nom_developpe', 'f.date_creation', 'f.nb_etablissements',
                'f.famille', 'f.niveau', 'f.secteurs', 'f.tailles_adherents', 'f.pertinence',
                'f.contactabilite', 'f.certitude', 'f.origine_classement',
                'f.partenariat', 'f.partenariat_relance_at', 'f.partenariat_note',
                'f.parent_company_id', 't.denomination AS tete_denomination',
            ]);

        return $ligne instanceof \stdClass ? $ligne : null;
    }

    /** @param  array<string, mixed>  $filtres */
    private function filtrer(Builder $requete, array $filtres): void
    {
        foreach (['famille', 'niveau', 'pertinence', 'contactabilite', 'partenariat'] as $champ) {
            if (($filtres[$champ] ?? null) !== null && $filtres[$champ] !== '') {
                $requete->where('f.' . $champ, $filtres[$champ]);
            }
        }
        // `= ANY(tableau)` : le secteur est l'un des secteurs REPRÉSENTÉS.
        if (($filtres['secteur'] ?? null) !== null && $filtres['secteur'] !== '') {
            $requete->whereRaw('? = ANY(f.secteurs)', [$filtres['secteur']]);
        }
        if (($filtres['taille_adherents'] ?? null) !== null && $filtres['taille_adherents'] !== '') {
            $requete->whereRaw('? = ANY(f.tailles_adherents)', [$filtres['taille_adherents']]);
        }
        if (($filtres['region'] ?? null) !== null && $filtres['region'] !== '') {
            $requete->where('c.region_code', $filtres['region']);
        }
        if (($filtres['departement'] ?? null) !== null && $filtres['departement'] !== '') {
            $requete->where('c.department_code', strtoupper((string) $filtres['departement']));
        }
        if (($filtres['tete'] ?? null) !== null) {
            $requete->where('f.parent_company_id', (int) $filtres['tete']);
        }
        if (array_key_exists('evenement_a_venir', $filtres) && $filtres['evenement_a_venir'] !== null) {
            $clause = function (Builder $q): void {
                $q->selectRaw('1')
                    ->from('event_organizers as eo')
                    ->join('events as e', 'e.id', '=', 'eo.event_id')
                    ->whereColumn('eo.company_id', 'c.id')
                    ->whereColumn('e.workspace_id', 'c.workspace_id')
                    ->whereRaw('COALESCE(e.date_fin, e.date_debut) >= CURRENT_DATE');
            };
            if ((bool) $filtres['evenement_a_venir']) {
                $requete->whereExists($clause);
            } else {
                $requete->whereNotExists($clause);
            }
        }

        $q = trim((string) ($filtres['q'] ?? ''));
        if ($q !== '') {
            $motif = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $requete->where(function (Builder $w) use ($motif): void {
                $w->where('c.denomination', 'ILIKE', $motif)
                    ->orWhere('f.sigle', 'ILIKE', $motif)
                    ->orWhere('f.nom_developpe', 'ILIKE', $motif)
                    ->orWhere('c.city', 'ILIKE', $motif);
            });
        }
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function compterAntennes(string $workspaceId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('federations')
            ->where('workspace_id', $workspaceId)
            ->whereIn('parent_company_id', $ids)
            ->groupBy('parent_company_id')
            ->selectRaw('parent_company_id, count(*) AS n')
            ->get()
            ->mapWithKeys(fn (\stdClass $r): array => [(int) $r->parent_company_id => (int) $r->n])
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private function avecEvenementAVenir(string $workspaceId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('event_organizers as eo')
            ->join('events as e', 'e.id', '=', 'eo.event_id')
            ->where('eo.workspace_id', $workspaceId)
            ->where('e.workspace_id', $workspaceId)
            ->whereIn('eo.company_id', $ids)
            ->whereRaw('COALESCE(e.date_fin, e.date_debut) >= CURRENT_DATE')
            ->distinct()
            ->pluck('eo.company_id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();
    }

    /** @return array<string, mixed> */
    private function resume(\stdClass $l, int $antennes, bool $evenementAVenir): array
    {
        return [
            'id' => (int) $l->id,
            'denomination' => $l->denomination,
            'sigle' => $l->sigle,
            'entity_nature' => $l->entity_nature,
            'city' => $l->city,
            'department_code' => $l->department_code,
            'region_code' => $l->region_code,
            'famille' => $l->famille,
            'niveau' => $l->niveau,
            'secteurs' => EtiquettesFederation::tableau($l->secteurs),
            'tailles_adherents' => EtiquettesFederation::tableau($l->tailles_adherents),
            'pertinence' => $l->pertinence,
            'contactabilite' => $l->contactabilite,
            'certitude' => $l->certitude,
            'partenariat' => $l->partenariat,
            'partenariat_relance_at' => $l->partenariat_relance_at,
            'tete' => $l->parent_company_id !== null && $l->tete_denomination !== null
                ? ['id' => (int) $l->parent_company_id, 'denomination' => $l->tete_denomination]
                : null,
            'nb_antennes' => $antennes,
            'evenement_a_venir' => $evenementAVenir,
        ];
    }

    /** @return array<string, mixed> */
    private function fiche(string $workspaceId, \stdClass $l): array
    {
        $companyId = (int) $l->id;

        // L'arborescence : les têtes successives jusqu'à la racine (la garde
        // anti-cycle de la base garantit que la remontée se termine).
        $ascendants = [];
        $courant = $l->parent_company_id !== null ? (int) $l->parent_company_id : null;
        while ($courant !== null && count($ascendants) < 64) {
            $parent = DB::table('companies as c')
                ->leftJoin('federations as f', 'f.company_id', '=', 'c.id')
                ->where('c.workspace_id', $workspaceId)
                ->where('c.id', $courant)
                ->whereNull('c.deleted_at')
                ->first(['c.id', 'c.denomination', 'f.niveau', 'f.parent_company_id']);
            if ($parent === null) {
                break;
            }
            $ascendants[] = ['id' => (int) $parent->id, 'denomination' => $parent->denomination, 'niveau' => $parent->niveau];
            $courant = $parent->parent_company_id !== null ? (int) $parent->parent_company_id : null;
        }

        $antennesRequete = DB::table('federations as f')
            ->join('companies as c', 'c.id', '=', 'f.company_id')
            ->where('f.workspace_id', $workspaceId)
            ->where('c.workspace_id', $workspaceId)
            ->whereNull('c.deleted_at')
            ->where('f.parent_company_id', $companyId);
        $nbAntennes = (clone $antennesRequete)->count();
        $antennes = $antennesRequete
            ->orderByRaw("CASE f.niveau WHEN 'national' THEN 0 WHEN 'regional' THEN 1 WHEN 'departemental' THEN 2 ELSE 3 END")
            ->orderBy('c.denomination')
            ->limit(self::ANTENNES_MAX)
            ->get(['c.id', 'c.denomination', 'c.city', 'c.department_code', 'f.niveau'])
            ->map(fn (\stdClass $a): array => [
                'id' => (int) $a->id,
                'denomination' => $a->denomination,
                'city' => $a->city,
                'department_code' => $a->department_code,
                'niveau' => $a->niveau,
            ])
            ->values()
            ->all();

        $contacts = DB::table('contacts')
            ->where('workspace_id', $workspaceId)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->orderBy('last_name')
            ->limit(200)
            ->get(['id', 'first_name', 'last_name', 'role', 'email', 'phone', 'linkedin_url', 'first_info_at'])
            ->map(fn (\stdClass $c): array => [
                'id' => (int) $c->id,
                'first_name' => $c->first_name,
                'last_name' => $c->last_name,
                'role' => $c->role,
                'email' => $c->email,
                'phone' => $c->phone,
                'linkedin_url' => $c->linkedin_url,
                // Art. 14 : a-t-il reçu la mention d'information ?
                'informe' => $c->first_info_at !== null,
            ])
            ->values()
            ->all();

        $evenements = DB::table('events')
            ->join('event_organizers', 'event_organizers.event_id', '=', 'events.id')
            ->where('event_organizers.company_id', $companyId)
            ->where('events.workspace_id', $workspaceId)
            ->orderByRaw('events.date_debut IS NULL, events.date_debut ASC, events.id ASC')
            ->limit(200)
            ->get(['events.id', 'events.nom', 'events.type', 'events.date_debut', 'events.date_fin',
                'events.recurrence', 'events.ville', 'events.participation', 'events.intervention'])
            ->map(fn (\stdClass $e): array => [
                'id' => (int) $e->id,
                'nom' => $e->nom,
                'type' => $e->type,
                'date_debut' => $e->date_debut,
                'date_fin' => $e->date_fin,
                'recurrence' => $e->recurrence,
                'ville' => $e->ville,
                'participation' => $e->participation,
                'intervention' => $e->intervention,
            ])
            ->values()
            ->all();

        $historique = DB::table('activities')
            ->where('workspace_id', $workspaceId)
            ->where('subject_type', 'company')
            ->where('subject_id', $companyId)
            ->where('kind', 'like', 'partenariat\_%')
            ->orderByDesc('occurred_at')
            ->limit(100)
            ->get(['id', 'kind', 'occurred_at'])
            ->map(fn (\stdClass $a): array => ['id' => (int) $a->id, 'kind' => $a->kind, 'occurred_at' => $a->occurred_at])
            ->values()
            ->all();

        $signals = json_decode(is_string($l->signals) ? $l->signals : '{}', true);

        $fiche = array_merge($this->resume($l, $nbAntennes, false), [
            'siren' => $l->siren,
            'naf' => $l->naf,
            'legal_form' => $l->legal_form,
            'effectif_range' => $l->effectif_range,
            'address' => $l->address,
            'postcode' => $l->postcode,
            'sector_main' => $l->sector_main,
            'website' => $l->website,
            'linkedin_url' => $l->linkedin_url,
            'phone' => $l->phone,
            'email_generic' => $l->email_generic,
            'contact_form_url' => is_array($signals) && is_string($signals['contact_form_url'] ?? null) ? $signals['contact_form_url'] : null,
            'nom_developpe' => $l->nom_developpe,
            'date_creation' => $l->date_creation,
            'nb_etablissements' => $l->nb_etablissements !== null ? (int) $l->nb_etablissements : null,
            'origine_classement' => $l->origine_classement,
            // Texte libre de Will : il peut contenir un nom ou un numéro malgré
            // la consigne. Retiré, comme une coordonnée, pour qui n'a pas le
            // droit de voir les coordonnées.
            'partenariat_note' => MasquageCoordonnees::requis() ? null : $l->partenariat_note,
            'ascendants' => $ascendants,
            'antennes' => $antennes,
            'contacts' => $contacts,
            'evenements' => $evenements,
            'evenement_a_venir' => $this->avecEvenementAVenir($workspaceId, [$companyId]) !== [],
            'historique' => $historique,
        ]);

        /** @var array<string, mixed> $masquee */
        $masquee = MasquageCoordonnees::masquerTableauSiRequis($fiche);

        return $masquee;
    }

    private function espace(): ?string
    {
        $id = app()->bound('workspace.id') ? app('workspace.id') : null;

        return is_string($id) && $id !== '' ? $id : null;
    }
}
