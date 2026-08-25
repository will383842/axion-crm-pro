<?php

namespace App\Http\Controllers\Api;

use App\Crm\Outbound\ConsentOutboundRecorder;
use App\Http\Requests\StoreJournalistRequest;
use App\Http\Requests\UpdateJournalistRequest;
use App\Models\Journalist;
use App\Support\EligibiliteCampagne;
use App\Support\LinkedinSlug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * API des JOURNALISTES (contacts rédaction). ⚠️ Données personnelles (RGPD).
 * Le champ `opt_out` reste visible (transparence) ; l'effacement = soft-delete.
 */
class JournalistsController extends ApiController
{
    public function index(Request $r): JsonResponse
    {
        $perPage = min(100, max(1, (int) $r->query('per_page', 25)));

        if (! Schema::hasTable('journalists')) {
            return $this->ok([
                'data' => [],
                'meta' => ['total' => 0, 'per_page' => $perPage, 'current_page' => 1, 'last_page' => 1],
            ]);
        }

        try {
            $page = $this->buildFilteredQuery()
                ->allowedIncludes(...['media'])
                ->allowedSorts(...['last_name', 'created_at'])
                ->defaultSort('last_name')
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
            Log::error('journalists.index failed', ['exception' => $e->getMessage()]);
            report($e);

            return $this->ok([
                'data' => [],
                'meta' => ['total' => 0, 'per_page' => $perPage, 'current_page' => 1, 'last_page' => 1],
                'degraded' => true,
            ]);
        }
    }

    private function buildFilteredQuery(): QueryBuilder
    {
        return QueryBuilder::for(Journalist::query()->whereNull('deleted_at'))
            ->allowedFilters(...[
                AllowedFilter::exact('media_id'),
                AllowedFilter::exact('beat'),
                AllowedFilter::exact('opt_out'),
                AllowedFilter::partial('last_name'),
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
        $filename = 'journalistes-' . now()->format('Y-m-d') . '.csv';
        $header = ['Prénom', 'Nom', 'Rôle', 'Rubrique', 'Email', 'Téléphone', 'Média', 'Opt-out', 'Source'];

        if (! Schema::hasTable('journalists') || $workspaceId === null) {
            return response()->streamDownload(function () use ($header) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, $header);
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        // 🔴 `opt_out` EST UNE COLONNE LOCALE, PAS LES TABLES D'OPPOSITION.
        //
        // `journalists.opt_out` n'enregistre qu'une opposition signalée sur la
        // fiche elle-même. Elle ignore `opt_out` et `email_suppressions` — les
        // tables où atterrissent les oppositions venues du site et les
        // effacements RGPD. Une personne qui s'est opposée par le site, et qui
        // est aussi journaliste, SORTAIT dans ce CSV avec nom, email et
        // téléphone. Constaté le 2026-08-16.
        //
        // On garde le filtre local (il dit quelque chose de vrai) et on ajoute
        // les deux portes partagées.
        $query = EligibiliteCampagne::exclureOpposes(
            $this->buildFilteredQuery()
                ->where('workspace_id', $workspaceId)
                ->where('opt_out', false),
            'journalists.email',
        )->with('media');

        return response()->streamDownload(function () use ($query, $header) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            $query->chunkById(1000, function ($journalists) use ($out) {
                foreach ($journalists as $j) {
                    fputcsv($out, [
                        $j->first_name,
                        $j->last_name,
                        $j->role,
                        $j->beat,
                        $j->email,
                        $j->phone,
                        $j->media?->name,
                        $j->opt_out ? 'oui' : 'non',
                        $j->source,
                    ]);
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function show(Journalist $journalist): JsonResponse
    {
        return $this->ok($journalist->load('media'));
    }

    /**
     * Création d'un contact presse.
     *
     * ── Le point de cette méthode : elle REFUSE avant de créer ────────────
     * Jusqu'ici la table n'était alimentée que par le scraping : aucun humain
     * ne pouvait y ajouter une fiche, et donc aucun humain ne pouvait y créer
     * de doublon. En ouvrant la porte, on ouvre le risque — d'où le contrôle
     * préalable, qui répond **409** avec les fiches ressemblantes plutôt que de
     * créer une seconde ligne.
     *
     * Elle ne fusionne pas d'autorité : sur des noms de personnes, une fusion
     * automatique est irréversible et se trompe (deux homonymes réels existent).
     * Elle présente, l'humain trancher. `?force=1` crée quand même, pour le cas
     * légitime des homonymes.
     */
    public function store(StoreJournalistRequest $request): JsonResponse
    {
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
        if (! $workspaceId) {
            return $this->ok(['error' => 'workspace required'], 422);
        }

        $data = $request->validated();

        $slug = $this->resolveSlug($data);
        if ($slug instanceof JsonResponse) {
            return $slug;
        }
        $data['linkedin_slug'] = $slug;
        unset($data['linkedin_url']);

        if (! $request->boolean('force')) {
            $doublons = $this->doublonsProbables($workspaceId, $data);
            if ($doublons->isNotEmpty()) {
                return $this->ok([
                    'error' => 'doublon_probable',
                    'message' => 'Une ou plusieurs fiches ressemblent à celle-ci. Vérifiez avant de créer.',
                    'candidats' => $doublons,
                    // Le client renvoie la même requête avec ?force=1 s'il
                    // s'agit bien d'une personne différente (homonyme).
                    'force_param' => 'force=1',
                ], 409);
            }
        }

        // `source` est NOT NULL en base. Un client qui l'envoie explicitement à
        // `null` passerait la validation (`nullable`) puis violerait la
        // contrainte : on retire la clé pour laisser le défaut s'appliquer,
        // plutôt que de rendre une 500 pour une saisie qui n'a rien de fautif.
        if (! isset($data['source']) || trim((string) $data['source']) === '') {
            unset($data['source']);
        }

        $journalist = Journalist::create($data + [
            'workspace_id' => $workspaceId,
            // Défaut de traçabilité : une fiche saisie à la main vient de la
            // console, et le dire évite qu'elle soit prise plus tard pour un
            // résultat de scraping non vérifié.
            'source' => 'console',
        ]);

        return $this->ok(['data' => $journalist->fresh()->load('media')], 201);
    }

    /**
     * Modification d'un contact presse.
     *
     * `opt_out` n'est pas modifiable ici — il a son point d'entrée dédié, qui
     * émet aussi vers le site (cf. `optOut()`). Voir `StoreJournalistRequest`.
     */
    public function update(UpdateJournalistRequest $request, Journalist $journalist): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('linkedin_url', $data)) {
            $slug = $this->resolveSlug($data);
            if ($slug instanceof JsonResponse) {
                return $slug;
            }
            $data['linkedin_slug'] = $slug;
            unset($data['linkedin_url']);
        }

        // L'invariant « au moins un nom » se vérifie sur l'état FUSIONNÉ, pas
        // sur la requête : un PATCH qui ne porte que `first_name: null` est
        // légitime tant qu'un `last_name` subsiste en base. La règle
        // `required_without` du formulaire ne peut pas le savoir — elle ne voit
        // que la requête — d'où ce contrôle ici, et non là-bas.
        $apres = array_merge($journalist->only(['first_name', 'last_name']), $data);
        if (trim((string) ($apres['first_name'] ?? '')) === '' && trim((string) ($apres['last_name'] ?? '')) === '') {
            return $this->ok([
                'error' => 'nom_requis',
                'message' => 'Un contact doit conserver au moins un nom ou un prénom.',
            ], 422);
        }

        $journalist->update($data);

        return $this->ok(['data' => $journalist->fresh()->load('media')]);
    }

    /**
     * Normalise `linkedin_url` en slug, ou rend une réponse 422 qui DIT
     * pourquoi. Un `null` silencieux serait pire que l'erreur : l'utilisateur
     * croirait avoir enregistré l'URL, et la fiche perdrait sa meilleure clé
     * d'identité sans que rien ne l'indique.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveSlug(array $data): string|null|JsonResponse
    {
        $url = $data['linkedin_url'] ?? null;
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $slug = LinkedinSlug::normalize($url);
        if ($slug === null) {
            return $this->ok([
                'error' => 'linkedin_url_illisible',
                'message' => "Cette URL n'est pas un profil LinkedIn de personne (/in/…). "
                    . "Une page d'entreprise ou d'école ne peut pas identifier un contact.",
            ], 422);
        }

        return $slug;
    }

    /**
     * Fiches ressemblant à celle qu'on s'apprête à créer, par ordre de force
     * de preuve décroissante.
     *
     * Le calcul de la clé nom+média est délégué à Postgres (`normalize_name`,
     * `digest`) plutôt que réimplémenté en PHP. Deux implémentations d'une même
     * normalisation finissent toujours par diverger — et le jour où elles
     * divergent, ce contrôle laisse passer exactement les doublons qu'il est
     * censé arrêter.
     *
     * @param  array<string, mixed>  $data
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function doublonsProbables(string $workspaceId, array $data): \Illuminate\Support\Collection
    {
        $base = Journalist::query()
            ->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at');

        // 1. Le slug LinkedIn : preuve d'identité, pas ressemblance.
        if (! empty($data['linkedin_slug'])) {
            $parSlug = (clone $base)->where('linkedin_slug', $data['linkedin_slug'])->get();
            if ($parSlug->isNotEmpty()) {
                return $parSlug->map(fn ($j) => $this->candidat($j, 'meme_profil_linkedin'));
            }
        }

        // 2. L'email : quasi aussi fort, et `email` est CITEXT (insensible à la
        //    casse) — la comparaison est donc juste sans lower() de part et d'autre.
        if (! empty($data['email'])) {
            $parEmail = (clone $base)->where('email', $data['email'])->get();
            if ($parEmail->isNotEmpty()) {
                return $parEmail->map(fn ($j) => $this->candidat($j, 'meme_email'));
            }
        }

        // 3. Nom + média, via la clé que la base calcule elle-même.
        $cle = DB::selectOne(
            "SELECT encode(digest(
                 normalize_name(coalesce(?, '') || '_' || coalesce(?, ''))
                 || '@' ||
                 coalesce(?::TEXT, normalize_name(coalesce(?, '')), ''),
                 'sha256'
             ), 'hex') AS k",
            [
                $data['first_name'] ?? null,
                $data['last_name'] ?? null,
                $data['media_id'] ?? null,
                $data['media_raw'] ?? null,
            ],
        );

        if ($cle === null || ! isset($cle->k)) {
            return collect();
        }

        return (clone $base)->where('dedup_key', $cle->k)->get()
            ->map(fn ($j) => $this->candidat($j, 'meme_nom_et_media'));
    }

    /**
     * Réduit une fiche candidate à ce qui permet de TRANCHER, et rien de plus.
     * Un doublon se lève en regardant le média, la rubrique et la provenance —
     * pas en relisant le téléphone, qui est une donnée personnelle de plus
     * exposée sans nécessité.
     */
    private function candidat(Journalist $j, string $motif): object
    {
        return (object) [
            'id' => $j->id,
            'motif' => $motif,
            'first_name' => $j->first_name,
            'last_name' => $j->last_name,
            'media' => $j->media?->name ?? $j->media_raw,
            'beat' => $j->beat,
            'source' => $j->source,
            'source_url' => $j->source_url,
            'created_at' => $j->created_at,
        ];
    }

    /**
     * Droit d'opposition RGPD : bascule opt_out (le contact reste en base mais
     * exclu des exports/campagnes).
     */
    public function optOut(Journalist $journalist): JsonResponse
    {
        $email = $journalist->email;

        $journalist->update(['opt_out' => true]);

        // Lot L5 — l'opposition décidée DANS la console doit converger vers le
        // site : sans cela le site continuerait d'adresser une personne que le
        // CRM a opposée, et la prochaine synchro site → CRM la « rouvrirait ».
        // Sans email, il n'y a pas de hash — donc rien que le site puisse
        // rapprocher : on ne met rien en file plutôt qu'un message inexploitable.
        if (is_string($email) && trim($email) !== '') {
            try {
                app(ConsentOutboundRecorder::class)->recordForEmail(
                    'consent_optout',
                    $email,
                    'business',
                    payload: ['surface' => 'console:journalists', 'journalist_id' => $journalist->id],
                );
            } catch (\Throwable $e) {
                // Une panne de la mini-outbox ne fait pas échouer un droit
                // d'opposition déjà acté en base. Journalisé, jamais avalé.
                Log::error('crm.outbound.record_failed', [
                    'event_type' => 'consent_optout',
                    'journalist_id' => $journalist->id,
                    'exception' => $e->getMessage(),
                ]);
                report($e);
            }
        }

        return $this->ok($journalist);
    }

    /** Droit à l'effacement RGPD : soft-delete. */
    public function destroy(Journalist $journalist): JsonResponse
    {
        $journalist->delete();

        return response()->json(null, 204);
    }
}
