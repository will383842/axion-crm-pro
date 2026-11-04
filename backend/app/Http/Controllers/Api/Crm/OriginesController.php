<?php

namespace App\Http\Controllers\Api\Crm;

use App\Crm\Campagnes\EligibiliteAdresse;
use App\Crm\Ingest\OrigineDictionnaire;
use App\Crm\Origines\RequetesOrigines;
use App\Crm\ProvenanceTiers\ProvenanceTiers;
use App\Crm\Personnes\Abonnements;
use App\Crm\Sites\QuarantaineSite;
use App\Http\Controllers\Api\Crm\Concerns\GardeOrigines;
use App\Support\CelluleCsv;
use App\Support\EntreprisesFermees;
use App\Support\MasquageCoordonnees;
use App\Support\PlafondExport;
use App\Support\WorkspaceContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * API de LECTURE des origines (L2-back, contrat v3). LECTURE SEULE : aucune
 * écriture, aucune suppression. Derrière trois portes : l'interrupteur
 * `origines.lecture_enabled` (éteint par défaut → 404), la fonction d'espace
 * `origines.lecture` (L6, sans ligne → 404) et `origines.v3_enabled`
 * (éteint → 200 `{"actif": false}`, jamais 500).
 *
 * Aucun journal ne reçoit de filtre ni de corps de requête (PII).
 */
class OriginesController extends ConsoleController
{
    use GardeOrigines;

    private const MAX_JOURS = 90;

    private const MIN_JOURS = 7;

    private const PAR_PAGE_MOTS = 25;

    public function apercu(Request $request): JsonResponse
    {
        $espace = $this->gardeOrigines($request);
        $f = $this->filtres($request);
        if (! $this->actif()) {
            return $this->inactif();
        }

        return WorkspaceContext::run($espace, fn (): JsonResponse => $this->ok(array_merge(['actif' => true], RequetesOrigines::apercu($espace, $f))));
    }

    public function parReseau(Request $request): JsonResponse
    {
        $espace = $this->gardeOrigines($request);
        $f = $this->filtres($request);
        if (! $this->actif()) {
            return $this->inactif();
        }

        return WorkspaceContext::run($espace, fn (): JsonResponse => $this->ok(['actif' => true, 'data' => RequetesOrigines::parReseau($espace, $f)]));
    }

    public function parMot(Request $request): JsonResponse
    {
        $espace = $this->gardeOrigines($request);
        $f = $this->filtres($request);
        $page = max(1, min(100000, (int) $request->query('page', '1')));
        if (! $this->actif()) {
            return $this->inactif();
        }

        return WorkspaceContext::run($espace, function () use ($espace, $f, $page): JsonResponse {
            $r = RequetesOrigines::parMot($espace, $f, $page, self::PAR_PAGE_MOTS);

            return $this->ok(['actif' => true, 'data' => $r['data'], 'meta' => [
                'per_page' => self::PAR_PAGE_MOTS, 'page' => $page, 'total' => $r['total'],
                'has_more' => $page * self::PAR_PAGE_MOTS < $r['total'],
            ]]);
        });
    }

    public function tendance(Request $request): JsonResponse
    {
        $espace = $this->gardeOrigines($request);
        $f = $this->filtres($request);
        $jours = $request->validate([
            'jours' => ['nullable', 'integer', 'min:' . self::MIN_JOURS, 'max:' . self::MAX_JOURS],
        ])['jours'] ?? 30;
        if (! $this->actif()) {
            return $this->inactif();
        }

        return WorkspaceContext::run($espace, fn (): JsonResponse => $this->ok(['actif' => true, 'data' => RequetesOrigines::tendance($espace, $f, (int) $jours)]));
    }

    /** Liste nominative filtrée, adresses masquées sans `contacts.view_pii`. */
    public function personnes(Request $request, PersonnesController $personnes): JsonResponse
    {
        $espace = $this->gardeOrigines($request);
        $f = $this->filtres($request);
        $parPage = $this->perPage($request);
        if (! $this->actif()) {
            return $this->inactif();
        }

        return WorkspaceContext::run($espace, function () use ($espace, $f, $parPage, $personnes): JsonResponse {
            $requete = $personnes->requete($espace, ['source' => null, 'statut_lettre' => null, 'nature' => null, 'rattachee' => null, 'q' => null, 'fermees' => EntreprisesFermees::mode(null)]);
            [$cond, $bindings] = RequetesOrigines::conditionPersonnes($espace, $f);
            $page = $requete->whereRaw($cond, $bindings)->orderByDesc('personnes.id')->cursorPaginate($parPage);

            /** @var list<\stdClass> $lignes */
            $lignes = $page->items();
            $ids = array_map(static fn (\stdClass $p): int => (int) $p->id, $lignes);
            $entrees = RequetesOrigines::entreesDesPersonnes($espace, $ids, $f);

            $data = [];
            foreach ($lignes as $p) {
                $liste = $entrees[(int) $p->id] ?? [];
                $data[] = [
                    'id' => (int) $p->id,
                    'email' => $p->email,
                    'first_name' => $p->first_name,
                    'last_name' => $p->last_name,
                    'entreprise' => ($p->denomination ?? null) ?? ($p->siren ?? null),
                    'demandes' => count($liste),
                    'derniere_origine' => $liste[0] ?? null,
                ];
            }

            return $this->ok([
                'actif' => true,
                'data' => MasquageCoordonnees::masquerTableauSiRequis($data),
                'meta' => [
                    'per_page' => $page->perPage(),
                    'next_cursor' => $page->nextCursor()?->encode(),
                    'has_more' => $page->hasMorePages(),
                ],
            ]);
        });
    }

    /** Historique COMPLET d'une personne (fiche + archive), plus récent d'abord. */
    public function historique(Request $request, int $personneId): JsonResponse
    {
        $espace = $this->gardeOrigines($request);
        $v = $request->validate([
            'par_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'curseur' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);
        if (! $this->actif()) {
            return $this->inactif();
        }

        return WorkspaceContext::run($espace, function () use ($espace, $personneId, $v): JsonResponse {
            $existe = DB::table('personnes')->where('workspace_id', $espace)->where('id', $personneId)->exists();
            abort_unless($existe, 404);

            $parPage = (int) ($v['par_page'] ?? 50);
            $decalage = (int) ($v['curseur'] ?? 0);
            $r = RequetesOrigines::historique($espace, $personneId, $decalage, $parPage);
            $suivant = $decalage + $parPage < $r['total'] ? $decalage + $parPage : null;

            return $this->ok(['actif' => true, 'data' => $r['data'], 'meta' => ['total' => $r['total'], 'par_page' => $parPage, 'suivant' => $suivant]]);
        });
    }

    /**
     * EXPORT CSV nominatif filtré. Exige `data.export` ET `contacts.view_pii`
     * (routes). Jamais : personnes opposées, adresses mortes, fiches non
     * diffusibles, quarantaine, provenance tiers insuffisante, entrées mises
     * à l'écart. `commentaire_ref` ne sort jamais.
     */
    public function export(Request $request, PersonnesController $personnes): StreamedResponse
    {
        $espace = $this->gardeOrigines($request);
        $f = $this->filtres($request);
        $f['oppose'] = 'non';
        abort_unless($this->actif(), 404);

        $entete = ['Adresse', 'Prénom', 'Nom', 'Date', 'Réseau', 'Compte', 'Canal', 'Mot', 'Guide', 'Famille', 'Détail', 'Référence du post'];

        return response()->streamDownload(function () use ($espace, $f, $entete, $personnes): void {
            WorkspaceContext::run($espace, function () use ($espace, $f, $entete, $personnes): void {
                $out = fopen('php://output', 'w');
                if ($out === false) {
                    throw new RuntimeException("Export CSV : impossible d'ouvrir php://output.");
                }
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, $entete);

                $requete = $personnes->requete($espace, ['source' => null, 'statut_lettre' => null, 'nature' => null, 'rattachee' => null, 'q' => null, 'fermees' => EntreprisesFermees::mode(null)]);
                $requete = Abonnements::exclureOpposees($requete);
                if (Schema::hasColumn('personnes', 'opposition_at')) {
                    $requete->whereNull('personnes.opposition_at');
                }
                $requete->whereNull('companies.insee_non_diffusible_le');
                $requete->whereRaw('NOT ' . QuarantaineSite::personneLettreSql('personnes', 'companies'));
                $requete->whereRaw('NOT ' . ProvenanceTiers::informationInsuffisanteSql('personnes', 'contact_id'));
                [$cond, $bindings] = RequetesOrigines::conditionPersonnes($espace, $f);
                $requete->whereRaw($cond, $bindings);

                $plafond = PlafondExport::lignes();
                $lues = 0;
                $tronque = false;
                $masquer = MasquageCoordonnees::requis();
                $requete->chunkById(200, function ($lot) use ($espace, $f, $out, &$lues, &$tronque, $plafond, $masquer): bool {
                    $ids = [];
                    foreach ($lot as $p) {
                        $ids[] = (int) $p->id;
                    }
                    $entrees = RequetesOrigines::entreesDesPersonnes($espace, $ids, $f);
                    foreach ($lot as $p) {
                        foreach ($entrees[(int) $p->id] ?? [] as $o) {
                            $lues++;
                            if ($lues > $plafond) {
                                $tronque = true;

                                return false;
                            }
                            fputcsv($out, CelluleCsv::ligne([
                                $masquer ? MasquageCoordonnees::email($p->email) : $p->email,
                                $p->first_name, $p->last_name,
                                $o['date'], $o['reseau'], $o['compte'], $o['canal'], $o['mot'], $o['guide'], $o['famille'], $o['detail'], $o['post_ref'],
                            ]));
                        }
                    }

                    return true;
                }, 'personnes.id', 'id');
                if ($tronque) {
                    PlafondExport::ecrireAvertissement($out, count($entete));
                }
                fclose($out);
            });
        }, 'origines-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8'] + PlafondExport::entetes());
    }

    // ── Internes ────────────────────────────────────────────────────────────

    private function actif(): bool
    {
        return (bool) config('origines.v3_enabled') && Schema::hasColumn('personnes', 'origines');
    }

    private function inactif(): JsonResponse
    {
        return $this->ok(['actif' => false]);
    }

    /**
     * Filtres communs, liste fermée. Une valeur refusée donne 422 SANS écho de la valeur reçue.
     *
     * @return array{famille: ?string, detail: ?string, reseau: ?string, mot: ?string, oppose: string, du: ?string, au: ?string}
     */
    private function filtres(Request $request): array
    {
        $v = $request->validate([
            'famille' => ['nullable', 'string', 'max:64'],
            'detail' => ['nullable', 'string', 'max:64'],
            'reseau' => ['nullable', 'string', 'regex:' . RequetesOrigines::JETON],
            'mot' => ['nullable', 'string', 'regex:' . RequetesOrigines::JETON],
            'oppose' => ['nullable', 'string', 'in:oui,non,tous'],
            'du' => ['nullable', 'date_format:Y-m-d'],
            'au' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $famille = $v['famille'] ?? null;
        $detail = $v['detail'] ?? null;
        $valide = match (true) {
            $famille !== null && $detail !== null => OrigineDictionnaire::valide($famille, $detail),
            $famille !== null => OrigineDictionnaire::familleConnue($famille),
            default => $detail === null, // un détail seul est ambigu
        };
        if (! $valide) {
            throw ValidationException::withMessages(['famille' => 'Famille ou détail d\'origine inconnu du dictionnaire.']);
        }
        $du = $v['du'] ?? null;
        $au = $v['au'] ?? null;
        if ($du !== null && $au !== null && strtotime($au) - strtotime($du) > 366 * 86400) {
            throw ValidationException::withMessages(['du' => 'Période trop longue (366 jours au plus).']);
        }

        return [
            'famille' => $famille, 'detail' => $detail,
            'reseau' => $v['reseau'] ?? null, 'mot' => $v['mot'] ?? null,
            'oppose' => $v['oppose'] ?? 'tous', 'du' => $du, 'au' => $au,
        ];
    }
}
