<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ResumeObservabiliteIncomplet;
use App\Http\Controllers\Controller;
use App\Services\Scraping\GooglePlacesClient;
use App\Support\DelaiRequeteSql;
use App\Support\WorkspaceContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sprint H4 — Dashboard observability backend.
 *
 * GET /api/v1/observability/summary → KPI cards + recent activity
 *
 * Toutes les queries sont déjà scopées par workspace via RLS PG
 * (SetCurrentWorkspace middleware pose app.current_workspace_id).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * F39-007 — « RIEN À SIGNALER » ET « JE N'AI PAS PU REGARDER » NE DOIVENT PAS
 * AVOIR LA MÊME APPARENCE.
 *
 * Mesure du 2026-08-22 : six blocs d'interception de ce fichier rendaient une
 * valeur neutre (0, [], null) et le fichier n'importait même pas `Log` — aucun
 * des six avalements ne laissait donc la moindre trace. C'est l'écran de SANTÉ
 * du produit : une rubrique tombée y ressemblait exactement à une rubrique
 * calme, et personne ne pouvait le savoir, ni sur le tableau de bord, ni dans
 * les journaux. (Leçon déjà payée ailleurs : un agrégateur qui ne relaie rien
 * dans un job vert.)
 *
 * CE QUI EST FAIT ICI : chaque `catch` journalise en `warning`, avec le nom de
 * la rubrique. La panne devient consultable, et alertable.
 *
 * CE QUI N'EST PAS FAIT, ET POURQUOI : le rapport proposait aussi d'ajouter un
 * marqueur d'état (`degraded: true` / `status: 'indisponible'`) au JSON et de
 * faire afficher « non mesuré » au tableau de bord. Cela change le CONTRAT de
 * l'endpoint et l'apparence d'un écran — une décision de produit, pas un
 * correctif. À trancher avec Will avant de toucher au JSON.
 *
 * ⚠️ `countWaterfallErrors24h()` et `countArchiveReasons()` n'ont, elles, AUCUN
 * filet : une panne y remonte en 500. C'est DÉLIBÉRÉ et ce n'est pas un oubli —
 * les envelopper d'un `catch` qui rend 0 ajouterait deux zéros muets de plus,
 * c'est-à-dire le défaut qu'on répare. Une rubrique qui échoue bruyamment vaut
 * mieux qu'une rubrique qui ment doucement.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * ── 2026-10-03 : « Chargement de la santé du système… » SANS FIN ─────────────
 *
 * Mesuré en production, sous le rôle applicatif (`axion_app`, sécurité par
 * espace forcée, 4,35 M de fiches dans `companies`) : le comptage des fiches
 * « Google Places en attente » prenait 4,6 s, le décompte par motif
 * d'archivage 4,1 s. Recalculés à chaque ouverture ET toutes les 30 s par
 * l'écran ouvert, ils faisaient dépasser 10 s à la réponse ; le navigateur
 * réessayait, et l'écran restait sur « Chargement… ».
 *
 * 1. Le résumé est servi depuis un cache PAR ESPACE (`Cache::flexible`, patron
 *    de `DashboardController`) : frais 5 min, périmé servi jusqu'à 1 h pendant
 *    qu'UN seul recalcul part après la réponse.
 * 2. Un résumé INCOMPLET n'est jamais mis en cache : si une rubrique tombe
 *    dans son filet, le résumé est rendu à l'appelant (rubrique journalisée,
 *    comme avant) mais pas gardé — ses zéros de repli passeraient sinon pour
 *    des mesures pendant une heure. Une exception de `countWaterfallErrors24h`
 *    ou `countArchiveReasons` traverse le cache sans s'y écrire et reste un
 *    500, comme le veut F39-007.
 *    Dans le RECALCUL DIFFÉRÉ (valeur périmée, après la réponse), un résumé
 *    incomplet est journalisé en `warning` (sans SQL ni valeur) et n'écrase
 *    pas la valeur en cache. L'exception levée pour empêcher l'écriture est
 *    rattrapée par `rescue()` de Laravel ; elle porte `ShouldntReport`, donc
 *    ni journal d'erreur ni Sentry toutes les 5 min.
 * 3. Le calcul tient dans un BUDGET d'environ {@see self::BUDGET_MS} ms :
 *    chaque requête SQL reçoit comme délai le temps qui reste, avec un
 *    PLANCHER de 500 ms. Le budget n'est donc pas strict : une fois épuisé,
 *    chacune des requêtes restantes (dix en tout) a encore droit à 500 ms —
 *    au pire ≈ 25 s. Le délai SQL de Postgres vaut par requête, pas pour la
 *    route : sans budget, dix requêtes à 15 s chacune pourraient tenir l'écran
 *    plus de deux minutes. Le budget ne couvre QUE le SQL : la lecture du
 *    quota Google Places consommé (`currentMonthUsage()`, dans le cache Redis)
 *    n'y est pas soumise.
 * 4. La requête Google Places est scopée par espace et reprend MOT POUR MOT le
 *    prédicat de l'index partiel `idx_companies_google_places_en_attente`
 *    (migration `2026_10_03_000050`) — les opérateurs JSON ne sont pas
 *    « leakproof » : sous `axion_app`, seul un index dont le prédicat est
 *    identique évite de relire toute la table.
 */
class ObservabilityController extends Controller
{
    public const FRAIS_SECONDES = 300;

    public const PERIME_SECONDES = 3600;

    /**
     * Budget du calcul, en millisecondes (requêtes SQL seulement). Pas strict :
     * plancher de {@see self::PLANCHER_MS} ms par requête une fois épuisé.
     */
    public const BUDGET_MS = 20000;

    /** Délai SQL minimal accordé à une requête quand le budget est presque épuisé. */
    private const PLANCHER_MS = 500;

    /** Début du calcul en cours (`hrtime`, ns) — `null` hors calcul. */
    private ?int $debutCalcul = null;

    /** Une rubrique est-elle tombée dans son filet pendant le calcul en cours ? */
    private bool $incomplet = false;

    /**
     * Vrai pendant l'appel à `Cache::flexible` de la requête : un calcul lancé
     * hors de cette fenêtre est le recalcul DIFFÉRÉ d'une valeur périmée.
     */
    private bool $dansLaRequete = false;

    public static function cle(string $espace): string
    {
        return 'observability:summary:v1:' . $espace;
    }

    public function summary(Request $request): JsonResponse
    {
        // Le même espace que celui posé par `SetCurrentWorkspace` pour la
        // sécurité par espace (identifiant validé, sinon chaîne vide).
        $workspaceId = WorkspaceContext::validIdOrNull($request->user()->current_workspace_id ?? null) ?? '';

        // Sans espace : aucun cache (la clé n'aurait pas de propriétaire), le
        // calcul se comporte comme avant.
        if ($workspaceId === '') {
            return response()->json(['data' => $this->calculer($workspaceId)]);
        }

        $this->dansLaRequete = true;
        try {
            /** @var mixed $resume */
            $resume = Cache::flexible(
                self::cle($workspaceId),
                [self::FRAIS_SECONDES, self::PERIME_SECONDES],
                fn (): array => $this->calculerPourLeCache($workspaceId),
                lock: ['seconds' => 60],
            );
        } catch (ResumeObservabiliteIncomplet $e) {
            $resume = $e->resume;
        } finally {
            $this->dansLaRequete = false;
        }

        if (! is_array($resume)) {
            $resume = $this->calculer($workspaceId);
        }

        return response()->json(['data' => $resume]);
    }

    /**
     * Le calcul confié à `Cache::flexible`. Un résumé incomplet ne doit jamais
     * être écrit : la seule façon d'empêcher `flexible` d'écrire ce que rend
     * le calcul est de lever une exception (cf. point 2 de l'en-tête).
     *
     * - Dans la requête : rattrapée par `summary()`, le résumé est servi.
     * - Dans le recalcul différé : journalisée ICI en `warning` — l'espace,
     *   jamais le SQL ni les valeurs — puis levée vers `rescue()`, qui ne la
     *   signale pas (`ShouldntReport`). La valeur en cache reste l'ancienne,
     *   complète ; le recalcul suivant retentera.
     *
     * @return array<string, mixed>
     */
    private function calculerPourLeCache(string $workspaceId): array
    {
        $resume = $this->calculer($workspaceId);
        if (! $this->incomplet) {
            return $resume;
        }

        if (! $this->dansLaRequete) {
            Log::warning('observability.summary recalcul différé incomplet : valeur en cache conservée', [
                'workspace_id' => $workspaceId,
            ]);
        }

        throw new ResumeObservabiliteIncomplet($resume);
    }

    /**
     * Le calcul, sans cache.
     *
     * `WorkspaceContext::run` : le recalcul différé de `Cache::flexible` tourne
     * APRÈS la réponse, quand `SetCurrentWorkspace` a retiré la variable de
     * session de la sécurité par espace — sans elle, tout compterait zéro, et
     * ces zéros partiraient en cache (cf. `DashboardController::calculer`).
     *
     * @return array<string, mixed>
     */
    private function calculer(string $workspaceId): array
    {
        $this->incomplet = false;
        $this->debutCalcul = hrtime(true);
        $delaiAvant = DelaiRequeteSql::courantMs();

        $rubriques = fn (): array => [
            'waterfall_errors_24h' => $this->countWaterfallErrors24h($workspaceId),
            'hunter_quota_month' => $this->countHunterMonth($workspaceId),
            'google_places_quota' => $this->googlePlacesQuotaSummary($workspaceId),
            'archive_reasons' => $this->countArchiveReasons($workspaceId),
            'audience_failures_7d' => $this->countAudienceFailures7d($workspaceId),
            'recent_events' => $this->recentBusinessEvents($workspaceId),
            'site_sync' => $this->siteSyncReceptions($workspaceId),
            'outbound' => $this->outboundBacklog(),
        ];

        try {
            return $workspaceId === '' ? $rubriques() : WorkspaceContext::run($workspaceId, $rubriques);
        } finally {
            $this->debutCalcul = null;
            if ($delaiAvant !== null) {
                DelaiRequeteSql::poser($delaiAvant);
            }
        }
    }

    /**
     * Donne à la PROCHAINE requête le temps qui reste sur le budget du calcul
     * (plancher {@see self::PLANCHER_MS} ms). Sans effet hors Postgres et hors
     * calcul.
     */
    private function borner(): void
    {
        if ($this->debutCalcul === null) {
            return;
        }

        $ecouleMs = intdiv(hrtime(true) - $this->debutCalcul, 1_000_000);
        DelaiRequeteSql::poser(max(self::PLANCHER_MS, self::BUDGET_MS - $ecouleMs));
    }

    /**
     * Les fiches dont l'enrichissement Google Places attend le quota du mois
     * prochain, dans l'espace. Le prédicat est celui, MOT POUR MOT, de l'index
     * partiel `idx_companies_google_places_en_attente` (et de
     * `RetryGooglePlacesCommand`) : réécrit autrement (`signals ? '…'`),
     * l'index ne servirait plus — sans aucune erreur.
     */
    public static function requeteGooglePlacesEnAttente(string $workspaceId): Builder
    {
        return DB::table('companies')
            ->where('workspace_id', $workspaceId)
            ->whereRaw("(signals->'google_places_pending') IS NOT NULL")
            ->whereRaw("(signals->'google_places'->>'enriched_at') IS NULL");
    }

    /** Le décompte par motif d'archivage — servi par `idx_companies_archive_reason`. */
    public static function requeteMotifsArchivage(string $workspaceId): Builder
    {
        return DB::table('companies')
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('archive_reason')
            ->select('archive_reason', DB::raw('COUNT(*) AS c'))
            ->groupBy('archive_reason');
    }

    /**
     * Lot L5 — COMPTEUR DE RÉCEPTIONS (plan §2.9). Le site tient le tableau de
     * santé de l'émission ; le CRM expose en miroir ce qu'il a REÇU. Sans ce
     * miroir, un canal muet ressemble exactement à un canal calme : c'est la
     * leçon IndexNow (un agrégateur qui ne relaie rien dans un job vert).
     *
     * Chaque événement ingéré laisse une activité `external_ref = site:event:*`
     * (l'idempotence de L2 repose déjà dessus) : c'est donc la trace de
     * réception, sans table de compteurs à maintenir.
     *
     * Scopé au workspace COURANT, comme le reste du résumé : l'étanchéité
     * business / vivier vaut aussi pour les compteurs (un commercial n'a pas à
     * déduire le volume de candidatures reçues).
     *
     * @return array{ingested_today: int, ingested_7d: int, last_ingested_at: ?string}
     */
    private function siteSyncReceptions(string $workspaceId): array
    {
        try {
            $base = function () use ($workspaceId): Builder {
                $this->borner();

                return DB::table('activities')
                    ->where('workspace_id', $workspaceId)
                    ->where('external_ref', 'LIKE', 'site:event:%');
            };

            $last = $base()->max('created_at');

            return [
                'ingested_today' => (int) $base()->where('created_at', '>=', now()->startOfDay())->count(),
                'ingested_7d' => (int) $base()->where('created_at', '>=', now()->subDays(7))->count(),
                'last_ingested_at' => $last === null ? null : (string) $last,
            ];
        } catch (\Throwable $e) {
            // F39-007 — sans cette ligne, un canal d'ingestion MUET rendait
            // exactement les mêmes zéros qu'un canal simplement calme.
            $this->incomplet = true;
            Log::warning('observability.site_sync indisponible', ['exception' => $e->getMessage()]);

            return ['ingested_today' => 0, 'ingested_7d' => 0, 'last_ingested_at' => null];
        }
    }

    /**
     * Lot L5 — santé de la mini-outbox CRM → site. `gave_up` est l'état qui
     * doit se voir : une opposition abandonnée est une divergence RGPD
     * durable, pas un incident technique mineur.
     *
     * Table GLOBALE (infrastructure, sans workspace_id) : le compteur ne se
     * scope pas.
     *
     * @return array{pending: int, gave_up: int}
     */
    private function outboundBacklog(): array
    {
        try {
            $this->borner();
            $rows = DB::table('crm_outbound_events')
                ->select('status', DB::raw('COUNT(*) AS c'))
                ->whereIn('status', ['pending', 'failed', 'gave_up'])
                ->groupBy('status')
                ->pluck('c', 'status')
                ->all();

            return [
                // Un `failed` est encore en attente de rejeu : il compte dans le
                // backlog, sans quoi un backlog en échec paraîtrait vide.
                'pending' => (int) ($rows['pending'] ?? 0) + (int) ($rows['failed'] ?? 0),
                'gave_up' => (int) ($rows['gave_up'] ?? 0),
            ];
        } catch (\Throwable $e) {
            // F39-007 — `gave_up = 0` est la valeur qu'on ESPÈRE : la rendre en
            // avalant l'erreur transforme une divergence RGPD en bonne nouvelle.
            $this->incomplet = true;
            Log::warning('observability.outbound indisponible', ['exception' => $e->getMessage()]);

            return ['pending' => 0, 'gave_up' => 0];
        }
    }

    /**
     * Sprint H13 — KPI quota Google Places mensuel. Le quota consommé est un
     * compteur global de la clé d'API partagée ; les fiches EN ATTENTE sont
     * comptées dans l'espace courant.
     *
     * @return array{used: int, soft_limit: int, percent: float, pending_companies: int}
     */
    private function googlePlacesQuotaSummary(string $workspaceId): array
    {
        try {
            $client = app(GooglePlacesClient::class);
            // Lecture du cache Redis, pas du SQL : hors budget (point 3).
            $used = $client->currentMonthUsage();
            $limit = $client->monthlyQuotaLimit();
            // 2026-10-03 : scopée par espace. Sous la sécurité par espace, le
            // comptage ne voyait de toute façon que l'espace courant ; sans ce
            // filtre explicite, l'index partiel restait inutilisable.
            $this->borner();
            $pending = (int) self::requeteGooglePlacesEnAttente($workspaceId)->count();
        } catch (\Throwable $e) {
            // F39-007 — un quota à 0 % affiché parce que le client Google Places
            // ne répond pas est un feu vert fabriqué : il faut qu'il se voie.
            $this->incomplet = true;
            Log::warning('observability.google_places_quota indisponible', ['exception' => $e->getMessage()]);

            $used = 0;
            $limit = 11500;
            $pending = 0;
        }

        return [
            'used' => $used,
            'soft_limit' => $limit,
            'percent' => $limit > 0 ? min(100, round($used / $limit * 100, 1)) : 0,
            'pending_companies' => $pending,
        ];
    }

    private function countWaterfallErrors24h(string $workspaceId): int
    {
        $this->borner();

        return (int) DB::table('scraper_runs')
            ->where('workspace_id', $workspaceId)
            ->where('status', 'failed')
            ->where('created_at', '>', now()->subDay())
            ->count();
    }

    private function countHunterMonth(string $workspaceId): array
    {
        try {
            // Sprint H2 verif fix (2026-05-18) : BETWEEN sur début/fin de mois courant
            // au lieu de date_trunc(timestamptz) — utilise l'index range scan
            // (workspace_id, verified_at) sans avoir besoin d'index fonctionnel IMMUTABLE.
            $monthStart = now()->startOfMonth();
            $monthEnd = now()->endOfMonth();
            $this->borner();
            $count = (int) DB::table('email_verification_logs')
                ->where('workspace_id', $workspaceId)
                ->where('provider', 'hunter')
                ->whereBetween('verified_at', [$monthStart, $monthEnd])
                ->count();
        } catch (\Throwable $e) {
            // F39-007 — le commentaire « table peut être absente avant migrate »
            // dit l'intention, il ne la trace pas : après la migration, la même
            // branche avale une vraie panne d'index avec le même silence.
            $this->incomplet = true;
            Log::warning('observability.hunter_quota_month indisponible', ['exception' => $e->getMessage()]);

            $count = 0;  // table peut être absente avant migrate
        }

        return [
            'used' => $count,
            'soft_limit' => 1000,  // plan Starter Hunter par défaut, ajuster via env si Growth
            'percent' => $count > 0 ? min(100, round($count / 1000 * 100, 1)) : 0,
        ];
    }

    /** @return array<string, int> */
    private function countArchiveReasons(string $workspaceId): array
    {
        $this->borner();
        $rows = self::requeteMotifsArchivage($workspaceId)
            ->pluck('c', 'archive_reason')
            ->all();

        return array_map(static fn ($v) => (int) $v, $rows);
    }

    private function countAudienceFailures7d(string $workspaceId): int
    {
        try {
            $this->borner();

            return (int) DB::table('business_events')
                ->where('workspace_id', $workspaceId)
                ->where('action', 'audience.refresh.failed')
                ->where('created_at', '>', now()->subDays(7))
                ->count();
        } catch (\Throwable $e) {
            // F39-007 — « 0 échec de rafraîchissement d'audience sur 7 jours »
            // est précisément ce qu'on veut lire : ne l'écrivons pas à l'aveugle.
            $this->incomplet = true;
            Log::warning('observability.audience_failures_7d indisponible', ['exception' => $e->getMessage()]);

            return 0;
        }
    }

    /** @return list<array<string, mixed>> */
    private function recentBusinessEvents(string $workspaceId): array
    {
        try {
            $this->borner();

            return DB::table('business_events')
                ->where('workspace_id', $workspaceId)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(['id', 'action', 'resource_type', 'resource_id', 'context', 'created_at'])
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'action' => $r->action,
                    'resource_type' => $r->resource_type,
                    'resource_id' => $r->resource_id,
                    'context' => is_string($r->context) ? json_decode($r->context, true) : $r->context,
                    'created_at' => $r->created_at,
                ])
                ->all();
        } catch (\Throwable $e) {
            // F39-007 — un flux d'activité vide raconte « il ne se passe rien »,
            // ce qui est la lecture la plus rassurante et la moins verifiable.
            $this->incomplet = true;
            Log::warning('observability.recent_events indisponible', ['exception' => $e->getMessage()]);

            return [];
        }
    }
}
