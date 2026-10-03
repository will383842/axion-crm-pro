<?php

namespace App\Http\Controllers\Api;

use App\Crm\Console\FilesATraiter;
use App\Crm\Doublons\FusionFiches;
use App\Crm\Doublons\Rapprochement;
use App\Crm\Doublons\RefusFusion;
use App\Crm\FichesProtegees;
use App\Http\Controllers\Api\Crm\ATraiterController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use stdClass;

/**
 * « DOUBLONS À VÉRIFIER » — la file de `duplicate_flags` (chantier 5).
 *
 * Chaque ligne : la paire, son motif, les deux fiches côte à côte (celle qu'on
 * GARDERAIT, celle qui serait ABSORBÉE), et deux gestes :
 *  - « Fusionner » (`companies.delete` : la fiche absorbée part à la
 *    corbeille) — même service que la commande, même journal, annulable ;
 *  - « Ce ne sont pas des doublons » (`companies.update`) — la paire n'est
 *    plus jamais reproposée.
 * Aucune coordonnée nominative n'est rendue : nom de l'organisation, SIREN ou
 * identifiant, code postal, ville, site, source, nombre de personnes.
 */
class DoublonsController extends ApiController
{
    private const PAR_PAGE_MAX = 100;

    public function index(Request $request): JsonResponse
    {
        $ws = $this->espace();
        if ($ws === null) {
            return $this->ok(['error' => 'workspace required'], 422);
        }
        $filtres = $request->validate([
            'motif' => ['nullable', Rule::in(array_keys(Rapprochement::MOTIFS))],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:' . self::PAR_PAGE_MAX],
        ]);
        $parPage = (int) ($filtres['per_page'] ?? 50);
        $page = (int) ($filtres['page'] ?? 1);

        // La MÊME file que la pastille du menu (`ATraiterController`) : un
        // seul constructeur, sinon le menu et l'écran finiraient par diverger.
        $base = FilesATraiter::doublons($ws);

        $parMotif = [];
        foreach ((clone $base)->groupBy('d.motif')->select('d.motif', DB::raw('count(*) AS n'))->get() as $l) {
            $parMotif[(string) $l->motif] = (int) $l->n;
        }
        if (isset($filtres['motif'])) {
            $base->where('d.motif', $filtres['motif']);
        }
        $total = (clone $base)->count();

        $colonnes = ['d.id', 'd.motif', 'd.similarity', 'd.fusion_auto'];
        foreach (['ga' => 'garde', 'ab' => 'absorbee'] as $alias => $role) {
            foreach (['id', 'denomination', 'siren', 'foreign_id', 'postcode', 'city', 'website', 'discovery_source'] as $c) {
                $colonnes[] = "{$alias}.{$c} AS {$role}_{$c}";
            }
            $colonnes[] = DB::raw("(SELECT count(*) FROM contacts nb_{$alias} WHERE nb_{$alias}.company_id = {$alias}.id AND nb_{$alias}.deleted_at IS NULL) AS {$role}_nb_contacts");
            $colonnes[] = DB::raw('NOT ' . FichesProtegees::conditionSql("{$alias}.id") . " AS {$role}_protegee");
        }
        $lignes = $base->orderByDesc('d.similarity')->orderBy('d.id')->forPage($page, $parPage)->get($colonnes);

        $adresses = [];
        foreach (DB::table('adresses_partagees')->where('workspace_id', $ws)->groupBy('nature')
            ->select('nature', DB::raw('count(*) AS n'))->get() as $l) {
            $adresses[(string) $l->nature] = (int) $l->n;
        }

        return $this->ok([
            'data' => $lignes->map(fn (stdClass $l): array => $this->ligne($l))->values(),
            'meta' => [
                'total' => $total, 'page' => $page, 'per_page' => $parPage,
                'par_motif' => $parMotif,
                'adresses_partagees' => $adresses,
                'motifs' => array_map(static fn (array $m): string => $m[1], Rapprochement::MOTIFS),
            ],
        ]);
    }

    public function fusionner(Request $request, int $paire, FusionFiches $fusion): JsonResponse
    {
        $ws = $this->espace();
        if ($ws === null) {
            return $this->ok(['error' => 'workspace required'], 422);
        }
        $flag = $this->paire($ws, $paire);
        if ($flag === null) {
            abort(404);
        }
        $user = $request->user();
        try {
            $id = $fusion->fusionner(
                $ws,
                (int) $flag->entity_a_id,
                (int) $flag->entity_b_id,
                (string) $flag->motif,
                FusionFiches::MODE_MANUEL,
                (int) $flag->id,
                $user?->getAuthIdentifier() === null ? null : (string) $user->getAuthIdentifier(),
                'console',
            );
        } catch (RefusFusion $r) {
            return $this->ok(['error' => $r->raison, 'message' => $r->getMessage()], 409);
        }

        // La pastille « Doublons à vérifier » du menu suit le geste.
        ATraiterController::oublier($ws);

        return $this->ok(['fusion_id' => $id]);
    }

    public function ignorer(Request $request, int $paire): JsonResponse
    {
        $ws = $this->espace();
        if ($ws === null) {
            return $this->ok(['error' => 'workspace required'], 422);
        }
        $flag = $this->paire($ws, $paire);
        if ($flag === null) {
            abort(404);
        }
        $user = $request->user();
        $n = DB::table('duplicate_flags')
            ->where('workspace_id', $ws)
            ->where('id', $paire)
            ->whereNull('reviewed_at')
            ->update([
                'reviewed_at' => now(),
                'reviewed_by' => $user?->getAuthIdentifier() === null ? null : (string) $user->getAuthIdentifier(),
                'resolution' => 'keep_both',
            ]);
        if ($n === 0) {
            return $this->ok(['error' => 'deja_traitee', 'message' => RefusFusion::MESSAGES['deja_traitee']], 409);
        }

        ATraiterController::oublier($ws);

        return $this->ok(['ok' => true]);
    }

    private function espace(): ?string
    {
        return $this->espaceCourantOuNull();
    }

    private function paire(string $ws, int $id): ?stdClass
    {
        $flag = DB::table('duplicate_flags')
            ->where('workspace_id', $ws)
            ->where('entity_type', 'company')
            ->where('id', $id)
            ->whereNotNull('motif')
            ->first(['id', 'entity_a_id', 'entity_b_id', 'motif', 'reviewed_at']);

        return $flag instanceof stdClass ? $flag : null;
    }

    /** @return array<string, mixed> */
    private function ligne(stdClass $l): array
    {
        $fiche = static function (stdClass $l, string $role): array {
            $siren = $l->{"{$role}_siren"};

            return [
                'id' => (int) $l->{"{$role}_id"},
                'denomination' => $l->{"{$role}_denomination"},
                'siren' => $siren,
                'identifiant' => $siren === null ? $l->{"{$role}_foreign_id"} : null,
                'code_postal' => $l->{"{$role}_postcode"},
                'ville' => $l->{"{$role}_city"},
                'site' => $l->{"{$role}_website"},
                'source' => $l->{"{$role}_discovery_source"},
                'nb_contacts' => (int) $l->{"{$role}_nb_contacts"},
                'protegee' => (bool) $l->{"{$role}_protegee"},
            ];
        };

        return [
            'id' => (int) $l->id,
            'motif' => (string) $l->motif,
            'motif_libelle' => Rapprochement::libelle((string) $l->motif),
            'score' => (float) $l->similarity,
            'fusion_auto' => (bool) $l->fusion_auto,
            'garde' => $fiche($l, 'garde'),
            'absorbee' => $fiche($l, 'absorbee'),
        ];
    }
}
