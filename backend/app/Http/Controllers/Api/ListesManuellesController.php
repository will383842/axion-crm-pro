<?php

namespace App\Http\Controllers\Api;

use App\Crm\Campagnes\GardePresse;
use App\Crm\Listes\ImportListe;
use App\Crm\Listes\ListesManuelles;
use App\Http\Controllers\Concerns\VerrouOptimiste;
use App\Models\EmailAudience;
use App\Models\ListeManuelle;
use App\Services\Audiences\AudienceBuilderService;
use App\Support\AuditLogger;
use App\Support\MasquageCoordonnees;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * LISTES MANUELLES — des fiches choisies à la main, sous un nom.
 *
 * Droits (ceux des fiches, comme les audiences) :
 *  - lire les listes et leurs membres : `companies.view` (lecture seule
 *    comprise, adresses MASQUÉES sans `contacts.view_pii`) ;
 *  - créer, renommer, cocher, retirer, importer : `companies.update` ;
 *  - mettre à la corbeille et en sortir : `companies.delete`.
 *
 * Rien n'est jamais supprimé : une liste va à la corbeille (`deleted_at`),
 * un membre retiré garde sa ligne (`retire_le`), et une fiche n'est jamais
 * touchée par une liste.
 *
 * La presse (`GardePresse`) n'entre dans aucune liste tant que son segment est
 * fermé : l'ajout la REFUSE en le disant (`presse_refusees` + message), et une
 * ligne écrite avant qu'une fiche ne devienne presse n'est plus ni lue, ni
 * comptée, ni montrée avec ses adresses (`membres`, effectifs).
 */
class ListesManuellesController extends ApiController
{
    use VerrouOptimiste;

    /** Le refus d'une fiche de presse, dit à l'écran (vouvoiement). */
    public const MESSAGE_PRESSE = 'Les médias et les journalistes ne peuvent pas être ajoutés à une liste tant que le segment presse est fermé : '
        . 'la ou les fiches de presse désignées n\'ont pas été ajoutées (elles restent intactes dans le CRM).';

    /**
     * GET /listes-manuelles — les listes de l'espace, avec leurs effectifs.
     * `?corbeille=1` : celles de la corbeille. `?company_id=` / `?contact_id=` :
     * dit, pour chaque liste, si cette fiche y est (`contient`).
     */
    public function index(Request $r): JsonResponse
    {
        $ws = $this->espaceOuRefus();
        $q = ListeManuelle::query()->where('workspace_id', $ws)->orderBy('nom');
        if ($r->boolean('corbeille')) {
            $q->onlyTrashed();
        }
        $listes = $q->limit(500)->get();
        $effectifs = $this->effectifs(array_values($listes->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all()));

        $companyId = (int) $r->query('company_id', '0');
        $contactId = (int) $r->query('contact_id', '0');
        $contient = [];
        if ($companyId > 0 || $contactId > 0) {
            $contient = DB::table('listes_manuelles_membres')
                ->where('workspace_id', $ws)
                ->whereNull('retire_le')
                ->where(function ($w) use ($companyId, $contactId): void {
                    $w->where('company_id', $companyId > 0 ? $companyId : -1)
                        ->orWhere('contact_id', $contactId > 0 ? $contactId : -1);
                })
                ->pluck('liste_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->flip()
                ->all();
        }

        return $this->ok(['data' => $listes->map(fn (ListeManuelle $l): array => $this->presenter($l, $effectifs, $contient))->values()]);
    }

    public function store(Request $r): JsonResponse
    {
        $ws = $this->espaceOuRefus();
        $data = $r->validate([
            'nom' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
        $nom = trim((string) $data['nom']);
        if ($nom === '') {
            return $this->ok(['message' => 'Le nom de la liste est obligatoire.'], 422);
        }
        if ($this->nomPris($ws, $nom, null)) {
            return $this->ok(['message' => 'Une liste porte déjà ce nom.'], 422);
        }

        $liste = ListeManuelle::create([
            'workspace_id' => $ws,
            'nom' => $nom,
            'description' => $data['description'] ?? null,
            'created_by' => optional($r->user())->id,
        ]);
        $this->journal('liste_manuelle.creee', $liste);

        return $this->ok(['data' => $this->presenter($liste, [], [])], 201);
    }

    public function show(ListeManuelle $liste): JsonResponse
    {
        $this->refuserHorsEspace($liste);

        return $this->avecJetonDeVersion(
            $this->ok(['data' => $this->presenter($liste, $this->effectifs([(int) $liste->id]), [])]),
            $liste,
        );
    }

    /** PUT /listes-manuelles/{liste} — renommer, changer la description. */
    public function update(Request $r, ListeManuelle $liste): JsonResponse
    {
        $this->refuserHorsEspace($liste);
        $this->refuserSiVersionPerimee($r, $liste);
        $data = $r->validate([
            'nom' => ['sometimes', 'required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        if (array_key_exists('nom', $data)) {
            $nom = trim((string) $data['nom']);
            if ($nom === '') {
                return $this->ok(['message' => 'Le nom de la liste est obligatoire.'], 422);
            }
            if ($this->nomPris((string) $liste->workspace_id, $nom, (int) $liste->id)) {
                return $this->ok(['message' => 'Une liste porte déjà ce nom.'], 422);
            }
            $data['nom'] = $nom;
        }
        $liste->update($data);
        $liste->refresh();
        $this->journal('liste_manuelle.modifiee', $liste);

        return $this->avecJetonDeVersion(
            $this->ok(['data' => $this->presenter($liste, $this->effectifs([(int) $liste->id]), [])]),
            $liste,
        );
    }

    /**
     * DELETE /listes-manuelles/{liste} — à la CORBEILLE, jamais supprimée.
     * Refusé tant qu'une audience vivante s'en sert : la corbeille rendrait
     * ses critères incompilables.
     */
    public function destroy(ListeManuelle $liste): JsonResponse
    {
        $this->refuserHorsEspace($liste);
        $utilisatrices = $this->audiencesQuiUtilisent($liste);
        if ($utilisatrices !== []) {
            return $this->ok([
                'message' => 'Cette liste sert au ciblage de ' . count($utilisatrices) . ' audience(s) : la retirer de leurs critères d’abord.',
                'audiences' => $utilisatrices,
            ], 409);
        }
        $liste->delete();
        $this->journal('liste_manuelle.corbeille', $liste);

        return $this->ok(['ok' => true, 'corbeille' => true]);
    }

    /** POST /listes-manuelles/{liste}/restaurer — sortir de la corbeille. */
    public function restaurer(ListeManuelle $liste): JsonResponse
    {
        $this->refuserHorsEspace($liste);
        if ($liste->trashed()) {
            if ($this->nomPris((string) $liste->workspace_id, (string) $liste->nom, (int) $liste->id)) {
                return $this->ok(['message' => 'Une autre liste porte déjà ce nom : la renommer d’abord.'], 422);
            }
            $liste->restore();
            $this->journal('liste_manuelle.restauree', $liste);
        }

        return $this->ok(['data' => $this->presenter($liste, $this->effectifs([(int) $liste->id]), [])]);
    }

    /**
     * GET /listes-manuelles/{liste}/membres — les fiches de la liste, par page.
     * Adresses MASQUÉES pour qui n'a pas `contacts.view_pii`.
     */
    public function membres(Request $r, ListeManuelle $liste): JsonResponse
    {
        $this->refuserHorsEspace($liste);
        $parPage = max(1, min(100, (int) $r->query('per_page', '50')));
        $page = max(1, (int) $r->query('page', '1'));

        $base = self::membresLisibles(DB::table('listes_manuelles_membres as m')
            ->leftJoin('contacts as ct', 'ct.id', '=', 'm.contact_id')
            ->where('m.liste_id', $liste->id)
            ->whereNull('m.retire_le'));
        $total = (clone $base)->count();

        $lignes = (clone $base)
            ->leftJoin('companies as c', 'c.id', '=', DB::raw('coalesce(m.company_id, ct.company_id)'))
            ->orderByDesc('m.ajoute_le')
            ->orderByDesc('m.id')
            ->offset(($page - 1) * $parPage)
            ->limit($parPage)
            ->get([
                'm.id', 'm.origine', 'm.ajoute_le', 'm.company_id', 'm.contact_id',
                'c.id as organisation_id', 'c.denomination', 'c.siren', 'c.email_generic',
                'c.deleted_at as organisation_supprimee_le',
                'ct.first_name', 'ct.last_name', 'ct.role', 'ct.email', 'ct.deleted_at as personne_supprimee_le',
            ])
            ->map(static function (\stdClass $l): \stdClass {
                $l->type = $l->contact_id !== null ? 'personne' : 'organisation';

                return $l;
            });

        return $this->ok([
            'data' => MasquageCoordonnees::masquerSiRequis($lignes),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $parPage],
        ]);
    }

    /** POST /listes-manuelles/{liste}/membres — cocher des fiches. */
    public function ajouterMembres(Request $r, ListeManuelle $liste): JsonResponse
    {
        $this->refuserHorsEspace($liste);
        [$companyIds, $contactIds] = $this->idsDuGeste($r);
        $bilan = ListesManuelles::ajouter($liste, $companyIds, $contactIds, $this->utilisateur($r), ListesManuelles::ORIGINE_COCHE);
        $this->journal('liste_manuelle.fiches_ajoutees', $liste, $bilan);

        // Rien d'autre que de la presse désignée : REFUS explicite (422), rien
        // n'a été écrit. Un geste mixte passe pour le reste, et le dit.
        if ($bilan['presse_refusees'] > 0
            && $bilan['ajoutes'] + $bilan['reactives'] + $bilan['deja_presents'] + $bilan['introuvables'] === 0) {
            return $this->ok(['message' => self::MESSAGE_PRESSE . ' Rien n\'a été ajouté.', 'data' => $bilan], 422);
        }

        return $this->ok(['data' => $bilan] + ($bilan['presse_refusees'] > 0 ? ['message' => self::MESSAGE_PRESSE] : []));
    }

    /**
     * POST /listes-manuelles/{liste}/membres/retirer — retirer des fiches de
     * la liste. La fiche n'est PAS supprimée ; la ligne d'appartenance reçoit
     * `retire_le`.
     */
    public function retirerMembres(Request $r, ListeManuelle $liste): JsonResponse
    {
        $this->refuserHorsEspace($liste);
        [$companyIds, $contactIds] = $this->idsDuGeste($r);
        $bilan = ListesManuelles::retirer($liste, $companyIds, $contactIds, $this->utilisateur($r));
        $this->journal('liste_manuelle.fiches_retirees', $liste, $bilan);

        return $this->ok(['data' => $bilan]);
    }

    /**
     * POST /listes-manuelles/{liste}/import — rapprocher un fichier (CSV ou
     * JSONL) de fiches EXISTANTES. `a_blanc=1` : le bilan seul, rien n'est
     * écrit. Une ligne non rapprochée est comptée et rejetée.
     */
    public function importer(Request $r, ListeManuelle $liste): JsonResponse
    {
        $this->refuserHorsEspace($liste);
        $r->validate([
            'fichier' => ['required_without:contenu', 'file', 'max:' . (int) (ImportListe::TAILLE_MAX / 1024)],
            'contenu' => ['required_without:fichier', 'string', 'max:' . ImportListe::TAILLE_MAX],
            'a_blanc' => ['sometimes', 'boolean'],
        ]);
        $fichier = $r->file('fichier');
        $contenu = $fichier instanceof UploadedFile
            ? (string) file_get_contents((string) $fichier->getRealPath())
            : (string) $r->input('contenu', '');

        try {
            if ($r->boolean('a_blanc')) {
                $bilan = ImportListe::analyser((string) $liste->workspace_id, $contenu)['bilan'] + ['a_blanc' => true];
            } else {
                $bilan = ImportListe::importer($liste, $contenu, $this->utilisateur($r)) + ['a_blanc' => false];
                $this->journal('liste_manuelle.import', $liste, [
                    'lignes_lues' => $bilan['lignes_lues'],
                    'rapprochees' => $bilan['rapprochees'],
                    'rejetees' => $bilan['rejetees'],
                    'ajout' => $bilan['ajout'],
                ]);
            }
        } catch (InvalidArgumentException $e) {
            return $this->ok(['message' => $e->getMessage()], 422);
        }

        // Des lignes rapprochées de la presse : refusées, et dit (`GardePresse`).
        return $this->ok(['data' => $bilan] + ((int) ($bilan['presse_refusees'] ?? 0) > 0 ? ['message' => self::MESSAGE_PRESSE] : []));
    }

    // ── Outils ───────────────────────────────────────────────────────────────

    /**
     * Les lignes d'appartenance LISIBLES : sans la presse tant que son segment
     * est fermé, par fiche (`coalesce(m.company_id, ct.company_id)`) ET par
     * personne. La requête doit joindre `contacts as ct` sur `m.contact_id`.
     */
    private static function membresLisibles(QueryBuilder $q): QueryBuilder
    {
        return $q
            ->whereRaw(GardePresse::conditionSql('coalesce(m.company_id, ct.company_id)'))
            ->whereRaw('(m.contact_id IS NULL OR ' . GardePresse::conditionContactsSql('ct') . ')');
    }

    private function espaceOuRefus(): string
    {
        $ws = $this->espaceCourantOuNull();
        if ($ws === null) {
            abort(404);
        }

        return $ws;
    }

    private function utilisateur(Request $r): ?string
    {
        $id = optional($r->user())->id;

        return is_string($id) ? $id : null;
    }

    /** @return array{0: list<int>, 1: list<int>} */
    private function idsDuGeste(Request $r): array
    {
        $r->validate([
            'company_ids' => ['sometimes', 'array', 'max:' . ListesManuelles::MAX_PAR_GESTE],
            'company_ids.*' => ['integer', 'min:1'],
            'contact_ids' => ['sometimes', 'array', 'max:' . ListesManuelles::MAX_PAR_GESTE],
            'contact_ids.*' => ['integer', 'min:1'],
        ]);
        $companyIds = ListesManuelles::entiers((array) $r->input('company_ids', []));
        $contactIds = ListesManuelles::entiers((array) $r->input('contact_ids', []));
        if ($companyIds === [] && $contactIds === []) {
            abort(response()->json(['message' => 'Aucune fiche désignée : cocher au moins une organisation ou une personne.'], 422));
        }

        return [$companyIds, $contactIds];
    }

    private function nomPris(string $ws, string $nom, ?int $sauf): bool
    {
        return ListeManuelle::query()
            ->where('workspace_id', $ws)
            ->whereRaw('lower(btrim(nom)) = lower(btrim(?))', [$nom])
            ->when($sauf !== null, fn ($q) => $q->where('id', '!=', $sauf))
            ->exists();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{organisations: int, personnes: int}>
     */
    private function effectifs(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $effectifs = [];
        foreach (self::membresLisibles(DB::table('listes_manuelles_membres as m')
            ->leftJoin('contacts as ct', 'ct.id', '=', 'm.contact_id')
            ->whereIn('m.liste_id', $ids)
            ->whereNull('m.retire_le'))
            ->groupBy('m.liste_id')
            ->selectRaw('m.liste_id, count(m.company_id) AS organisations, count(m.contact_id) AS personnes')
            ->get() as $l) {
            $effectifs[(int) $l->liste_id] = ['organisations' => (int) $l->organisations, 'personnes' => (int) $l->personnes];
        }

        return $effectifs;
    }

    /**
     * Les audiences VIVANTES dont un critère cite cette liste.
     *
     * @return list<array{id: int, nom: string}>
     */
    private function audiencesQuiUtilisent(ListeManuelle $liste): array
    {
        $trouvees = [];
        foreach (EmailAudience::query()->where('workspace_id', $liste->workspace_id)->get(['id', 'name', 'criteria']) as $a) {
            $criteres = $a->getAttribute('criteria');
            $citees = AudienceBuilderService::listesCitees(is_array($criteres) ? $criteres : []);
            if (in_array((int) $liste->id, $citees['toutes'], true)) {
                $trouvees[] = ['id' => (int) $a->id, 'nom' => (string) $a->name];
            }
        }

        return $trouvees;
    }

    /**
     * @param  array<int, array{organisations: int, personnes: int}>  $effectifs
     * @param  array<int, int>  $contient
     * @return array<string, mixed>
     */
    private function presenter(ListeManuelle $l, array $effectifs, array $contient): array
    {
        $e = $effectifs[(int) $l->id] ?? ['organisations' => 0, 'personnes' => 0];

        return [
            'id' => (int) $l->id,
            'nom' => $l->nom,
            'description' => $l->description,
            'organisations' => $e['organisations'],
            'personnes' => $e['personnes'],
            'contient' => isset($contient[(int) $l->id]),
            'created_by' => $l->created_by,
            'created_at' => optional($l->created_at)?->toIso8601String(),
            'updated_at' => optional($l->updated_at)?->toIso8601String(),
            'deleted_at' => optional($l->deleted_at)?->toIso8601String(),
        ];
    }

    /** @param  array<string, mixed>  $details */
    private function journal(string $action, ListeManuelle $liste, array $details = []): void
    {
        AuditLogger::log($action, [
            'workspace_id' => (string) $liste->workspace_id,
            'resource_type' => 'liste_manuelle',
            'resource_id' => (string) $liste->id,
            'nom' => $liste->nom,
        ] + $details);
    }
}
