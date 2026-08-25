<?php

namespace App\Http\Controllers\Api;

use App\Models\Media;
use App\Support\EligibiliteCampagne;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * API des MÉDIAS (TV, radio, presse, portails, agences, blogs, émissions).
 * Calquée sur {@see CompaniesController} : index paginé + query filtrée partagée
 * avec l'export CSV streamé, scoping workspace explicite, défensif (jamais 500).
 */
class MediaController extends ApiController
{
    public function index(Request $r): JsonResponse
    {
        $perPage = min(100, max(1, (int) $r->query('per_page', 25)));

        if (! Schema::hasTable('media')) {
            return $this->ok([
                'data' => [],
                'meta' => ['total' => 0, 'per_page' => $perPage, 'current_page' => 1, 'last_page' => 1],
            ]);
        }

        try {
            $page = $this->buildFilteredQuery()
                ->allowedSorts(...['name', 'enriched_at', 'created_at', 'media_type'])
                ->defaultSort('name')
                ->paginate($perPage);

            return $this->ok([
                'data' => $page->items(),
                'meta' => [
                    'total' => $page->total(),
                    'per_page' => $page->perPage(),
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('media.index failed', ['exception' => $e->getMessage()]);
            report($e);

            return $this->ok([
                'data' => [],
                'meta' => ['total' => 0, 'per_page' => $perPage, 'current_page' => 1, 'last_page' => 1],
                'degraded' => true,
            ]);
        }
    }

    /**
     * Query filtrée partagée liste (index) ↔ export → l'export = la liste affichée.
     */
    private function buildFilteredQuery(): QueryBuilder
    {
        return QueryBuilder::for(Media::query()->whereNull('deleted_at'))
            ->allowedFilters(...[
                AllowedFilter::exact('media_type'),
                AllowedFilter::exact('media_family'),
                AllowedFilter::exact('email_confidence'),
                AllowedFilter::exact('periodicity'),
                AllowedFilter::exact('editorial_theme'),
                AllowedFilter::exact('diffusion_zone'),
                AllowedFilter::exact('department_code'),
                AllowedFilter::exact('region_code'),
                AllowedFilter::exact('enrich_status'),
                AllowedFilter::exact('source'),
                AllowedFilter::partial('name'),
                AllowedFilter::callback('has_website', function ($query, $value) {
                    filter_var($value, FILTER_VALIDATE_BOOLEAN)
                        ? $query->whereNotNull('website')
                        : $query->whereNull('website');
                }),
                AllowedFilter::callback('has_email', function ($query, $value) {
                    filter_var($value, FILTER_VALIDATE_BOOLEAN)
                        ? $query->whereNotNull('email')
                        : $query->whereNull('email');
                }),
            ]);
    }

    public function export(Request $r): StreamedResponse
    {
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
        $filename = 'medias-' . now()->format('Y-m-d') . '.csv';
        $header = ['Nom', 'Type', 'Famille', 'Périodicité', 'Thème', 'Zone', 'Département', 'Région', 'Ville', 'Éditeur', 'Site web', 'Email rédaction', 'Confiance email', 'Téléphone', 'N° CPPAP', 'N° ARCOM'];

        if (! Schema::hasTable('media') || $workspaceId === null) {
            return response()->streamDownload(function () use ($header) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, $header);
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        // Scope EXPLICITE workspace (défense principale contre fuite inter-tenants).
        //
        // 🔴 Et les portes d'opposition, qui manquaient entièrement ici.
        // Cet export sort « Email rédaction » et « Téléphone » ; il ne
        // consultait NI `opt_out` NI `email_suppressions`. Une rédaction qui
        // s'est opposée y figurait quand même. Constaté le 2026-08-16 — c'est
        // le seul des trois exports qui n'avait aucun filtre d'opposition,
        // même approximatif.
        $query = EligibiliteCampagne::exclureOpposes(
            $this->buildFilteredQuery()->where('workspace_id', $workspaceId),
            'media.email',
        );

        return response()->streamDownload(function () use ($query, $header) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 → Excel FR lit les accents
            fputcsv($out, $header);
            $query->chunkById(1000, function ($medias) use ($out) {
                foreach ($medias as $m) {
                    fputcsv($out, [
                        $m->name,
                        $m->media_type,
                        $m->media_family === 'audiovisual_production' ? 'Production audiovisuelle' : 'Rédactionnel',
                        $m->periodicity,
                        $m->editorial_theme,
                        $m->diffusion_zone,
                        $m->department_code,
                        $m->region_code,
                        $m->city,
                        $m->publisher,
                        $m->website,
                        $m->email,
                        $m->email_confidence,
                        $m->phone,
                        $m->cppap_number,
                        $m->arcom_id,
                    ]);
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Refuse une fiche qui n'appartient pas au workspace courant.
     *
     * ── Pourquoi ce garde-fou est APPLICATIF et non délégué à la base ───────
     * Le dépôt pose une RLS stricte sur `media` (policy
     * `media_workspace_isolation`, `FORCE ROW LEVEL SECURITY`). Elle ne
     * s'applique PAS : le rôle de connexion est `axion`, `rolsuper = t` et
     * `rolbypassrls = t`, et la migration `harden_workspace_isolation` est
     * explicitement inerte tant que `CRM_DB_APP_ROLE_ENABLED` vaut false (voir
     * son en-tête, § « Pourquoi c'est INERTE au déploiement »).
     *
     * Or `Media` n'utilise pas le trait `BelongsToWorkspace` : sans ce contrôle,
     * `GET /media/{id}` rendait 200 sur la fiche d'un AUTRE workspace. Mesuré le
     * 2026-08-25 par `PresseEnvoisCroisementsTest`, qui échouait en rendant 200
     * là où il attendait 404.
     *
     * Le jour où le rôle durci sera activé, ce contrôle deviendra redondant —
     * et c'est très bien : une isolation portée à deux endroits vaut mieux
     * qu'une isolation portée par une garde qu'on croit active et qui ne l'est
     * pas.
     */
    private function horsWorkspace(Media $media): bool
    {
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;

        return $workspaceId === null
            || (string) $media->workspace_id !== (string) $workspaceId;
    }

    public function show(Media $media): JsonResponse
    {
        if ($this->horsWorkspace($media)) {
            abort(404);
        }

        $media->load(['journalists', 'parent', 'children', 'company']);

        // La timeline part dans le MÊME appel que l'identité, comme sur la fiche
        // journaliste : « qu'est-ce qu'on leur a envoyé » n'est pas une question
        // secondaire qu'on irait chercher dans un second onglet — c'est la
        // question. Une fiche rendue sans ses échanges laisse croire qu'il n'y
        // en a pas.
        return $this->ok([
            'data' => $media,
            'timeline' => $this->timeline($media),
        ]);
    }

    /**
     * Consigner un geste de relations presse sur une RÉDACTION.
     *
     * Jumeau de {@see JournalistsController::logActivity()}, à une différence
     * près qui justifie son existence : on n'envoie pas toujours un communiqué
     * à une personne. Le Mémorial de l'Isère se joint à `redaction@…` — il n'y
     * a pas de journaliste nommé à qui rattacher l'envoi. Sans ce point
     * d'entrée, ces envois-là ne se consignaient nulle part, et le suivi
     * n'était complet que pour les contacts nominatifs.
     *
     * Même garde d'idempotence : `external_ref` porte l'empreinte du geste, et
     * l'index unique `activities_workspace_external_ref_key` fait qu'un
     * double-clic ne dédouble pas l'historique.
     */
    public function logActivity(Request $request, Media $media): JsonResponse
    {
        // Avant toute validation : on ne consigne rien contre une fiche d'un
        // autre workspace. Sans ce refus, l'écriture aurait été acceptée et
        // rangée dans le workspace de l'appelant, en pointant une fiche
        // étrangère — une ligne d'historique impossible à interpréter ensuite.
        if ($this->horsWorkspace($media)) {
            abort(404);
        }

        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
        if (! $workspaceId) {
            return $this->ok(['error' => 'workspace required'], 422);
        }

        $data = $request->validate([
            'kind' => ['required', Rule::in(self::KINDS_PRESSE)],
            'title' => ['required', 'string', 'max:300'],
            'content' => ['nullable', 'string', 'max:5000'],
            // Un envoi se consigne souvent après coup. Date passée acceptée,
            // date future refusée : « on leur a écrit demain » est une faute de
            // saisie, pas un fait.
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);

        $occurredAt = isset($data['occurred_at'])
            ? new \DateTimeImmutable($data['occurred_at'])
            : new \DateTimeImmutable();

        $ref = 'console:media:' . $media->id . ':' . $data['kind'] . ':'
            . $occurredAt->format('Y-m-d\TH:i') . ':' . substr(sha1($data['title']), 0, 12);

        $existant = DB::table('activities')
            ->where('workspace_id', $workspaceId)
            ->where('external_ref', $ref)
            ->first();

        if ($existant !== null) {
            return $this->ok([
                'timeline' => $this->timeline($media),
                'deja_consigne' => true,
            ]);
        }

        DB::table('activities')->insert([
            'workspace_id' => $workspaceId,
            'user_id' => optional($request->user())->id,
            // `type` (texte libre, historique) reçoit la même valeur que `kind`
            // le temps de la phase « expand » — cf. SiteSyncIngestService.
            'type' => $data['kind'],
            'kind' => $data['kind'],
            'occurred_at' => $occurredAt,
            'external_ref' => $ref,
            'subject_type' => 'media',
            'subject_id' => $media->id,
            'title' => $data['title'],
            'content' => $data['content'] ?? null,
            'payload' => json_encode([
                'surface' => 'console:media',
                'saisie' => 'manuelle',
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return $this->ok(['timeline' => $this->timeline($media)], 201);
    }

    /**
     * Natures d'échange proposées sur une fiche rédaction.
     *
     * Identique au sous-ensemble de la fiche journaliste, et ce n'est pas une
     * duplication regrettable : c'est la même règle métier vue des deux côtés.
     * La garde réelle reste le `CHECK` en base sur `activities.kind`.
     *
     * @var list<string>
     */
    private const KINDS_PRESSE = [
        'press_release_sent',
        'press_followup',
        'press_reply',
        'press_coverage',
        'linkedin_message',
        'call',
    ];

    /**
     * Les échanges d'une rédaction — les siens ET ceux de ses journalistes.
     *
     * ── Le point de cette méthode : elle AGRÈGE ────────────────────────────
     * Un communiqué envoyé à un journaliste du Mémorial est un échange avec Le
     * Mémorial. Si la fiche rédaction ne montrait que les lignes portant
     * `subject_type = 'media'`, elle afficherait « aucun échange » alors qu'on
     * a écrit trois fois à ses journalistes — et c'est précisément ce genre de
     * demi-vérité qui fait renvoyer un communiqué deux fois au même titre.
     *
     * Chaque ligne porte donc `via` : null quand l'échange vise la rédaction
     * elle-même, le nom du journaliste sinon. Sans ce marqueur, la fiche
     * mélangerait « on a écrit au journal » et « on a écrit à Untel », qui ne
     * se relancent pas de la même manière.
     *
     * `occurred_at` d'abord, `created_at` en second : un échange consigné
     * aujourd'hui mais daté du mois dernier se range à SA place.
     *
     * @return array<int, object>
     */
    private function timeline(Media $media): array
    {
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
        if (! $workspaceId || ! Schema::hasTable('activities')) {
            return [];
        }

        // Chargé, pas re-requêté : `show()` vient de faire le `load`.
        $journalistes = $media->relationLoaded('journalists')
            ? $media->journalists
            : $media->journalists()->get();

        // `first_name` + `last_name` : la table ne porte AUCUNE colonne
        // `full_name` et le modèle n'expose aucun accesseur du même nom. Un
        // `$j->full_name` aurait rendu null sans erreur, et chaque échange se
        // serait affiché « journaliste #412 » — un suivi muet sur l'essentiel.
        /** @var array<string, string> $nomsParId */
        $nomsParId = [];
        foreach ($journalistes as $j) {
            $nom = trim(((string) $j->first_name) . ' ' . ((string) $j->last_name));
            $nomsParId[(string) $j->id] = $nom !== '' ? $nom : ('journaliste #' . $j->id);
        }

        $lignes = DB::table('activities')
            ->where('workspace_id', $workspaceId)
            ->where(function ($q) use ($media, $nomsParId) {
                $q->where(function ($q2) use ($media) {
                    $q2->where('subject_type', 'media')->where('subject_id', $media->id);
                });
                if ($nomsParId !== []) {
                    $q->orWhere(function ($q2) use ($nomsParId) {
                        $q2->where('subject_type', 'journalist')
                            ->whereIn('subject_id', array_keys($nomsParId));
                    });
                }
            })
            ->orderByRaw('coalesce(occurred_at, created_at) DESC')
            ->limit(200)
            ->get(['id', 'kind', 'type', 'title', 'content', 'occurred_at', 'created_at', 'subject_type', 'subject_id'])
            ->all();

        foreach ($lignes as $ligne) {
            $ligne->via = $ligne->subject_type === 'journalist'
                ? ($nomsParId[(string) $ligne->subject_id] ?? null)
                : null;
        }

        return $lignes;
    }
}
