<?php

namespace App\Http\Controllers\Api;

use App\Envoi\Chauffe\BudgetChauffe;
use App\Envoi\Chauffe\CourbeChauffe;
use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Chauffe\Seuils;
use App\Envoi\Exceptions\ReculRefuse;
use App\Envoi\JourEnvoi;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * D5 : chauffe des domaines et des adresses IP (contrat `openapi/envoi/infrastructure.yaml`).
 *
 * `index` lit les compteurs de l'espace courant (jamais `ServeurEnvoi`). `reculer` est le seul geste : il ramène UNE
 * ressource d'un palier (propriétaire seul), il est idempotent pour la même journée et ne peut jamais faire monter.
 * L'identifiant `ip:<clé>` / `domaine:<clé>` est la clé sous laquelle `envoi_chauffe_jours` compte la ressource.
 * Chaque requête est bornée à l'espace courant, et une ressource d'un autre espace est inconnue (404).
 */
class EnvoiChauffeController extends ApiController
{
    private const TERMES = [
        BudgetChauffe::GOULOT_SERVEUR => ['plafond_serveur', 'Plafond du serveur d\'envoi'],
        BudgetChauffe::GOULOT_BOITES => ['plafond_boites', 'Plafond des boîtes d\'envoi'],
        BudgetChauffe::RESSOURCE_IP => ['courbe_ip', 'Montée en douceur de l\'adresse IP'],
        BudgetChauffe::RESSOURCE_DOMAINE => ['courbe_domaine', 'Montée en douceur du domaine'],
        'reponse' => ['capacite_reponse', 'Capacité de réponse'],
    ];

    public function __construct(private readonly DepotChauffe $depot) {}

    public function index(): JsonResponse
    {
        $ws = (string) WorkspaceContext::current();
        $jour = JourEnvoi::de(new DateTimeImmutable('now'));
        $ressources = [];
        $suivis = ['ip' => null, 'domaine' => null];
        foreach ($this->ressourcesConnues($ws, $jour) as $r) {
            $v = $this->vue($ws, $r['type'], $r['cle'], $r['envoyes'], $jour);
            $ressources[] = $v['ressource'];
            // Le budget suit la ressource la plus basse de chaque type : le sens prudent.
            if ($suivis[$r['type']] === null || $v['jourDeChauffe'] < $suivis[$r['type']]['jourDeChauffe']) {
                $suivis[$r['type']] = $v;
            }
        }
        usort($ressources, fn ($a, $b) => strcmp($a['id'], $b['id']));
        $nbBoites = (int) WorkspaceContext::run($ws, fn () => DB::table('envoi_boites')->where('workspace_id', $ws)->count());
        $budget = BudgetChauffe::duJourComplet(
            $suivis['ip']['effectifs'] ?? [],
            $suivis['ip']['reculs'] ?? [],
            $suivis['domaine']['effectifs'] ?? [],
            $suivis['domaine']['reculs'] ?? [],
            $jour,
            $nbBoites,
        );
        [$terme, $libelle] = self::TERMES[$budget->goulot];
        $source = $suivis[$budget->goulot] ?? null;

        return response()->json([
            'jour' => $jour->format('Y-m-d'),
            'budget_du_jour' => $budget->budget,
            'plafond_boite' => CourbeChauffe::PLAFOND_BOITE_JOUR,
            'goulot' => ['terme' => $terme, 'ressource_id' => $source['ressource']['id'] ?? null, 'libelle' => $libelle],
            'termes' => [
                'courbe_ip' => $budget->courbeIp, 'courbe_domaine' => $budget->courbeDomaine, 'plafond_boites' => $budget->plafondBoites,
                'plafond_serveur' => $budget->plafondServeur, 'capacite_reponse' => $budget->capaciteReponse,
            ],
            'ressources' => $ressources,
            'paliers' => $this->paliers(),
        ]);
    }

    public function reculer(Request $request, string $id): JsonResponse
    {
        $ws = (string) WorkspaceContext::current();
        $jour = JourEnvoi::de(new DateTimeImmutable('now'));
        [$type, $cle] = explode(':', $id, 2) + [1 => ''];
        $connue = null;
        foreach ($this->ressourcesConnues($ws, $jour) as $r) {
            if ($r['type'] === $type && $r['cle'] === $cle) {
                $connue = $r;
            }
        }
        if ($connue === null) {
            return response()->json(['error' => 'non_trouve', 'message' => 'Cette ressource de chauffe est introuvable.'], 404);
        }
        try {
            $this->depot->reculer($ws, $type, $cle, 'Recul demandé par le propriétaire', (string) $request->user()?->id, new DateTimeImmutable('now'));
        } catch (ReculRefuse) {
            return response()->json(['error' => 'recul_impossible', 'message' => 'Cette ressource est déjà au palier le plus bas : elle ne peut pas reculer.'], 409);
        }

        return response()->json($this->vue($ws, $type, $cle, $connue['envoyes'], $jour)['ressource']);
    }

    /**
     * Ressources comptées pour l'espace (une ligne par type et par clé) et messages envoyés aujourd'hui.
     *
     * @return list<array{type: string, cle: string, envoyes: int}>
     */
    private function ressourcesConnues(string $ws, DateTimeImmutable $jour): array
    {
        $lignes = WorkspaceContext::run($ws, fn () => DB::table('envoi_chauffe_jours')->where('workspace_id', $ws)
            ->groupBy('ressource_type', 'boite')->orderBy('ressource_type')->orderBy('boite')
            ->selectRaw('ressource_type, boite, COALESCE(SUM(envoyes) FILTER (WHERE jour = ?), 0) AS aujourdhui', [$jour->format('Y-m-d')])->get());
        $liste = [];
        foreach ($lignes as $l) {
            $type = (string) $l->ressource_type;
            $cle = (string) $l->boite;
            if ($type === BudgetChauffe::RESSOURCE_IP && str_starts_with($cle, 'ip:')) {
                $cle = substr($cle, 3);
            }
            $liste[] = ['type' => $type, 'cle' => $cle, 'envoyes' => (int) $l->aujourdhui];
        }

        return $liste;
    }

    /**
     * @return array{ressource: array<string, mixed>, jourDeChauffe: int, effectifs: list<DateTimeImmutable>, reculs: list<DateTimeImmutable>}
     */
    private function vue(string $ws, string $type, string $cle, int $envoyes, DateTimeImmutable $jour): array
    {
        $reculs = $this->depot->reculsDe($ws, $type, $cle, $jour);
        $effectifs = BudgetChauffe::joursEffectifs($this->depot->volumesParJour($ws, $cle, $jour, $type), Seuils::JOUR_EFFECTIF_PART, $reculs);
        $jc = CourbeChauffe::jourDeChauffeAvecReculs($effectifs, $reculs, $jour);
        $palier = CourbeChauffe::palierEtendu($jc);
        $fin = null;
        foreach (CourbeChauffe::PALIERS_ETENDUS as $ligne) {
            if ($ligne['jusqua'] === null || max(1, $jc) <= $ligne['jusqua']) {
                $fin = $ligne['jusqua'];
                break;
            }
        }
        $reculAujourdhui = false;
        foreach ($reculs as $r) {
            $reculAujourdhui = $reculAujourdhui || $r->format('Y-m-d') === $jour->format('Y-m-d');
        }

        return [
            'jourDeChauffe' => $jc, 'effectifs' => $effectifs, 'reculs' => $reculs,
            'ressource' => [
                'id' => $type . ':' . $cle,
                'type' => $type,
                'libelle' => $this->libelle($ws, $type, $cle),
                'palier' => $palier,
                'jours_effectifs' => count($effectifs),
                'jours_avant_palier_suivant' => $fin === null ? null : max(0, $fin - max(1, $jc) + 1),
                'total_palier' => CourbeChauffe::quotaRessourceEtendu($jc),
                'envoyes_aujourdhui' => $envoyes,
                'tenu_jusqu_au' => null,
                'motif_tenue' => null,
                'peut_reculer' => $palier !== 'P1' && ! $reculAujourdhui,
            ],
        ];
    }

    /** Nom affiché : le domaine de la boîte pour un domaine (jamais l'adresse entière), la clé elle-même pour une IP. */
    private function libelle(string $ws, string $type, string $cle): string
    {
        if ($type !== BudgetChauffe::RESSOURCE_DOMAINE) {
            return $cle;
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $cle) !== 1) {
            return $cle;
        }
        $adresse = WorkspaceContext::run($ws, fn () => DB::table('envoi_boites')->where('workspace_id', $ws)->where('id', $cle)->value('adresse'));

        return is_string($adresse) && str_contains($adresse, '@') ? strtolower(substr($adresse, (int) strrpos($adresse, '@') + 1)) : $cle;
    }

    /** @return list<array{palier: string, jours_min: int, jours_max: int, total_jour: int}> P8 n'a pas de fin : `jours_max` y vaut `jours_min`. */
    private function paliers(): array
    {
        $table = [];
        $debut = 1;
        foreach (CourbeChauffe::PALIERS_ETENDUS as $ligne) {
            $fin = $ligne['jusqua'] ?? $debut;
            $table[] = ['palier' => $ligne['palier'], 'jours_min' => $debut, 'jours_max' => $fin, 'total_jour' => $ligne['total']];
            $debut = $fin + 1;
        }

        return $table;
    }
}
