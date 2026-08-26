<?php

namespace App\Http\Controllers\Api;

use App\Crm\Outbound\ConsentOutboundRecorder;
use App\Http\Controllers\Concerns\VerrouOptimiste;
use App\Http\Requests\StoreJournalistRequest;
use App\Http\Requests\UpdateJournalistRequest;
use App\Models\Journalist;
use App\Support\EligibiliteCampagne;
use App\Support\LinkedinSlug;
use App\Support\MasquageCoordonnees;
use App\Support\PlafondExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use RuntimeException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * API des JOURNALISTES (contacts rédaction). ⚠️ Données personnelles (RGPD).
 * Le champ `opt_out` reste visible (transparence) ; l'effacement = soft-delete.
 */
class JournalistsController extends ApiController
{
    // G43-005 — toute écriture de ce contrôleur passe par le verrou optimiste :
    // sans lui, deux saisies concurrentes sur la même fiche presse se perdent
    // en silence. Cf. `App\Http\Controllers\Concerns\VerrouOptimiste`.
    use VerrouOptimiste;

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
                // 🔴 SITE JUMEAU de B12-002 / F36-006, que l'audit ne nomme pas.
                // Le constat parlait de « trois listes masquees, une fiche en
                // clair ». Il en manquait une quatrieme famille, et c'est la
                // PLUS nominative du depot : un journaliste est une personne
                // physique nommee, avec son courriel et sa ligne directe. La
                // route ne porte aucune permission au-dela du groupe
                // (routes/api.php:170) : un `viewer` la lit.
                'data' => MasquageCoordonnees::masquerSiRequis($page->items()),
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

    /**
     * 🔴 CONSTAT P6-API-001 (S0). Cette requete de base ne portait AUCUN filtre
     * d'espace. L'unique `where('workspace_id')` du fichier vivait dans
     * `export()` : la LISTE fuyait, l'export ne fuyait pas. Un compte en
     * lecture seule lisait donc nom, adresse et telephone des journalistes de
     * tous les clients.
     *
     * `Journalist` ne porte pas le trait `BelongsToWorkspace`, le scope global
     * est inerte par defaut (`CRM_STRICT_WORKSPACE_SCOPE=false`), et la RLS est
     * contournee par le role de connexion. Le filtre est donc pose ICI, a la
     * source de toutes les lectures du controleur.
     *
     * Sans contexte d'espace : on ne rend RIEN.
     *
     * ⚠️ `@return QueryBuilder<Journalist>` est OBLIGATOIRE, pas decoratif.
     * `Spatie\QueryBuilder\QueryBuilder` est `@template TModel of Model` : sans
     * le parametre, `TModel` reste non resolu et tout ce qui recoit ensuite le
     * Builder — ici `EligibiliteCampagne::exclureOpposes()`, elle aussi
     * templatee — devient inanalysable. Mesure du 2026-08-21 : c'est la cause
     * de 5 des 38 erreurs PHPStan de la branche.
     *
     * @return QueryBuilder<Journalist>
     */
    private function buildFilteredQuery(): QueryBuilder
    {
        $espaceCourant = $this->espaceCourantOuNull();

        return QueryBuilder::for(
            Journalist::query()
                ->whereNull('deleted_at')
                ->when(
                    $espaceCourant !== null,
                    fn ($q) => $q->where('workspace_id', $espaceCourant),
                    fn ($q) => $q->whereRaw('1 = 0'),
                ),
        )
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
                if ($out === false) {
                    // `php://output` ne s'ouvre pas : il n'y a aucun flux ou ecrire. On LEVE
                    // plutot que de poursuivre — `fputcsv(false, ...)` est une TypeError en
                    // PHP 8, et le telechargement rendrait un fichier vide ou tronque sans
                    // que l'operateur puisse savoir que son export est incomplet. C'est le
                    // defaut meme que le plafond partage (G41-007) sert a rendre VISIBLE.
                    throw new RuntimeException("Export CSV : impossible d'ouvrir php://output.");
                }
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
        // 🔴 `getEloquentBuilder()` — MÊME DÉFAUT QU'EN `MediaController`, et
        // c'est le patron A-011 du dépôt : le même geste faux recopié à deux
        // endroits. Constat F36-008 (S1), mesuré le 2026-08-20 :
        //
        //   TypeError: App\Support\EligibiliteCampagne::exclureOpposes():
        //   Argument #1 ($query) must be of type
        //   Illuminate\Database\Eloquent\Builder,
        //   Spatie\QueryBuilder\QueryBuilder given
        //
        // `Spatie\QueryBuilder\QueryBuilder` v6 n'étend plus `Eloquent\Builder`
        // (enveloppe à `__call`) : `->where(...)` rend l'enveloppe.
        // `GET /journalists/export` rendait donc 500 à tous les ayants droit.
        // `getEloquentBuilder()` rend le sujet réel, filtres déjà appliqués.
        // ⚠️ ON NE CHAINE PAS `->where(...)->getEloquentBuilder()`, ET CE N'EST
        // PAS UN GOUT. `Spatie\QueryBuilder\QueryBuilder` porte
        // `@mixin EloquentBuilder<TModel>` : pour l'analyse statique, `->where()`
        // rend un `Eloquent\Builder`, qui n'a AUCUN `getEloquentBuilder()`.
        // A l'execution le chainage marche — `__call` reforwarde puis rend
        // l'enveloppe — mais PHPStan ne peut pas le savoir, et il avait raison
        // de se plaindre : c'est le meme ecart enveloppe/Builder qui a produit
        // le 500 du constat F36-008. On garde donc l'enveloppe dans une
        // variable, on la mute, et on ne la deballe qu'une fois.
        $filtree = $this->buildFilteredQuery();
        $filtree->where('workspace_id', $workspaceId);
        $filtree->where('opt_out', false);

        $query = EligibiliteCampagne::exclureOpposes(
            $filtree->getEloquentBuilder(),
            'journalists.email',
        )->with('media');

        return response()->streamDownload(function () use ($query, $header) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                // `php://output` ne s'ouvre pas : il n'y a aucun flux ou ecrire. On LEVE
                // plutot que de poursuivre — `fputcsv(false, ...)` est une TypeError en
                // PHP 8, et le telechargement rendrait un fichier vide ou tronque sans
                // que l'operateur puisse savoir que son export est incomplet. C'est le
                // defaut meme que le plafond partage (G41-007) sert a rendre VISIBLE.
                throw new RuntimeException("Export CSV : impossible d'ouvrir php://output.");
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            // Plafond partagé (constat G41-007) : cf. App\Support\PlafondExport.
            $tronque = PlafondExport::parcourirBorne($query, function ($j) use ($out) {
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
            });
            if ($tronque) {
                PlafondExport::ecrireAvertissement($out, count($header));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8'] + PlafondExport::entetes());
    }

    /**
     * Fiche d'un contact presse, AVEC sa timeline.
     *
     * La timeline est jointe ici plutôt que servie par un second appel : la
     * question à laquelle cette fiche répond — « qu'est-ce que je lui ai envoyé
     * et qu'est-ce qu'on s'est dit ? » — n'a pas de sens en deux moitiés. Une
     * fiche affichée sans ses échanges donnerait à croire qu'il n'y en a pas.
     */
    public function show(Journalist $journalist): JsonResponse
    {
        // Constat B12-001 / F36-005 : la resolution de route rendait
        // l'enregistrement sans aucun filtre d'espace. 404, jamais 403 :
        // « interdit » confirmerait son existence.
        $this->refuserHorsEspace($journalist);

        // 🔴 SITE JUMEAU de B12-002 / F36-006. La fiche detaillee d'un
        // journaliste rendait le modele BRUT, courriel et telephone compris —
        // exactement le defaut decrit sur `companies`, sur la famille de
        // donnees la plus sensible. `masquerSiRequis` descend aussi dans
        // `media`, deja chargee.
        //
        // ⚠️ Rejeu du 2026-08-26 : le lot presse est antérieur à ce masquage et
        // rendait la fiche en clair. Sur la table la plus sensible du CRM, le
        // reprendre tel quel aurait rouvert la fuite en croyant n'ajouter
        // qu'une timeline. Les deux apports se cumulent.
        $fiche = MasquageCoordonnees::masquerSiRequis($journalist->load('media'));

        return $this->ok([
            'data' => $fiche,
            'timeline' => $this->timeline($journalist),
        ]);
    }

    /**
     * Consigne un échange avec un journaliste (appel, message LinkedIn,
     * communiqué envoyé, réponse reçue, retombée).
     *
     * ── Pourquoi la saisie manuelle est ici la bonne réponse ──────────────
     * Rien dans ce système ne reçoit d'email : le relais est sortant seul.
     * Capturer les réponses automatiquement demanderait une infrastructure
     * entrante complète. À l'échelle d'un fichier presse, consigner à la main
     * prend dix secondes et ne ment jamais sur ce qui s'est réellement dit —
     * alors qu'un parseur de réponses se trompe en silence.
     *
     * `external_ref` porte une empreinte du geste : deux clics sur le même
     * bouton ne créent qu'une ligne (l'index unique
     * `activities_workspace_external_ref_key` s'en charge). Sans elle, un
     * double-clic dédoublerait l'historique — et un historique qu'on soupçonne
     * de compter double ne sert plus à décider.
     */
    public function logActivity(Request $request, Journalist $journalist): JsonResponse
    {
        // Avant toute validation : on n'annote pas la fiche d'un autre workspace.
        // ⚠️ La pièce partagée d'`ApiController`, pas une garde locale : c'est
        // celle que le recensement B12-001 reconnaît.
        $this->refuserHorsEspace($journalist);

        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
        if (! $workspaceId) {
            return $this->ok(['error' => 'workspace required'], 422);
        }

        $data = $request->validate([
            'kind' => ['required', Rule::in(self::KINDS_PRESSE)],
            'title' => ['required', 'string', 'max:300'],
            'content' => ['nullable', 'string', 'max:5000'],
            // Un échange se consigne souvent après coup — hier, la semaine
            // dernière. On accepte donc une date passée, jamais future : « on
            // s'est parlé demain » n'est pas un fait, c'est une faute de saisie.
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);

        $occurredAt = isset($data['occurred_at']) ? new \DateTimeImmutable($data['occurred_at']) : now();

        // Empreinte du geste : même journaliste + même nature + même minute +
        // même titre ⇒ même ligne. La minute (et non la seconde) est le grain
        // qui neutralise un double-clic sans empêcher de consigner deux
        // échanges distincts le même jour.
        $ref = 'console:journalist:' . $journalist->id . ':' . $data['kind'] . ':'
            . $occurredAt->format('Y-m-d\TH:i') . ':' . substr(sha1($data['title']), 0, 12);

        $existant = DB::table('activities')
            ->where('workspace_id', $workspaceId)
            ->where('external_ref', $ref)
            ->first();

        if ($existant !== null) {
            return $this->ok([
                'data' => $existant,
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
            'subject_type' => 'journalist',
            'subject_id' => $journalist->id,
            'title' => $data['title'],
            'content' => $data['content'] ?? null,
            'payload' => json_encode([
                'surface' => 'console:journalists',
                'saisie' => 'manuelle',
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return $this->ok(['timeline' => $this->timeline($journalist)], 201);
    }

    /**
     * Natures d'échange proposées sur une fiche presse.
     *
     * Sous-ensemble volontaire de `Taxonomy::ACTIVITY_KINDS` : la fiche ne doit
     * pas offrir `gdpr_erasure` ou `calendly_no_show` dans une liste déroulante
     * de saisie d'échange. Restreindre ici n'affaiblit pas le CHECK — il reste
     * la garde en base ; c'est l'inverse qui serait faux (proposer plus que ce
     * que la base accepte).
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
     * Les échanges d'un journaliste, du plus récent au plus ancien.
     *
     * `occurred_at` d'abord, `created_at` en second : un échange consigné
     * aujourd'hui mais daté du mois dernier doit se ranger à SA place dans
     * l'histoire, pas en tête parce qu'on vient de le taper.
     *
     * @return array<int, object>
     */
    private function timeline(Journalist $journalist): array
    {
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
        if (! $workspaceId || ! Schema::hasTable('activities')) {
            return [];
        }

        return DB::table('activities')
            ->where('workspace_id', $workspaceId)
            ->where('subject_type', 'journalist')
            ->where('subject_id', $journalist->id)
            ->orderByRaw('coalesce(occurred_at, created_at) DESC')
            ->limit(200)
            ->get(['id', 'kind', 'type', 'title', 'content', 'occurred_at', 'created_at'])
            ->all();
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

        // `refresh()` et non `fresh()` : `fresh()` rend `Journalist|null` (la
        // ligne peut avoir disparu entre-temps) et l'enchaîner sur `->load()`
        // était un appel sur null en puissance. `refresh()` recharge en place et
        // rend `$this` — même effet, sans le trou.
        return $this->ok(['data' => $journalist->refresh()->load('media')], 201);
    }

    /**
     * Modification d'un contact presse.
     *
     * `opt_out` n'est pas modifiable ici — il a son point d'entrée dédié, qui
     * émet aussi vers le site (cf. `optOut()`). Voir `StoreJournalistRequest`.
     */
    public function update(UpdateJournalistRequest $request, Journalist $journalist): JsonResponse
    {
        // Deux gardes que le lot presse ne portait pas, parce qu'il est
        // antérieur aux recensements qui les exigent :
        //
        // B12-001 — la résolution de route rend la fiche qui porte cet
        // identifiant, quel qu'en soit le propriétaire. Sans ce refus, on
        // modifiait la fiche presse d'un autre client.
        $this->refuserHorsEspace($journalist);

        // G43-005 — sans verrou, deux saisies concurrentes se perdent EN
        // SILENCE : le second enregistrement écrase le premier sans que
        // personne ne l'apprenne. Le contrôle précède la validation, comme en
        // `CompaniesController` : un conflit d'édition n'est pas une faute de
        // saisie, et annoncer un 422 sur un formulaire qui ne devait de toute
        // façon pas être écrit brouille le diagnostic.
        $this->refuserSiVersionPerimee($request, $journalist);

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

        // Même raison qu'en `store()` : `fresh()` peut rendre null.
        return $this->ok(['data' => $journalist->refresh()->load('media')]);
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
     * @return Collection<int, object>
     */
    private function doublonsProbables(string $workspaceId, array $data): Collection
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
            // `->name` et non `?->name` : `??` couvre déjà le cas où la relation
            // est absente, et PHPStan refuse la double protection comme du bruit.
            'media' => $j->media->name ?? $j->media_raw,
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
        // Constat B12-001 / F36-005 : la resolution de route rendait
        // l'enregistrement sans aucun filtre d'espace. 404, jamais 403 :
        // « interdit » confirmerait son existence.
        $this->refuserHorsEspace($journalist);

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
        // Constat B12-001 / F36-005 : la resolution de route rendait
        // l'enregistrement sans aucun filtre d'espace. 404, jamais 403 :
        // « interdit » confirmerait son existence.
        $this->refuserHorsEspace($journalist);

        // L'email est lu AVANT l'effacement : le soft-delete conserve les
        // attributs, mais dépendre de ce détail rendrait le code faux le jour
        // où l'effacement deviendra une anonymisation.
        $email = $journalist->email;

        $journalist->delete();

        // 🔴 CONSTAT B14-010 (S1), mesuré le 2026-08-20. L'effacement n'émettait
        // RIEN, dans le contrôleur même où `optOut()` — deux méthodes plus haut —
        // émet correctement depuis le lot L5. Patron A-011 du dépôt : le
        // correctif existait déjà à quelques lignes de là et n'avait pas été
        // porté.
        //
        // Conséquence : le site continuait d'adresser une personne dont le CRM
        // a effacé la fiche, et la prochaine synchro site → CRM la recréait.
        // L'article 17 n'est pas « effacer ici », c'est « effacer partout où on
        // l'a diffusée » — le canal CRM → site EST le moyen de le tenir.
        //
        // Sans email, il n'y a pas de hash — donc rien que le site puisse
        // rapprocher : on ne met rien en file plutôt qu'un message inexploitable
        // qui grossirait un backlog ne convergeant jamais.
        if (is_string($email) && trim($email) !== '') {
            try {
                app(ConsentOutboundRecorder::class)->recordForEmail(
                    'erasure',
                    $email,
                    'business',
                    payload: ['surface' => 'console:journalists', 'journalist_id' => $journalist->id],
                );
            } catch (\Throwable $e) {
                // Une panne de la mini-outbox ne fait pas échouer un droit à
                // l'effacement déjà acté en base. Journalisé, jamais avalé —
                // même règle qu'en `optOut()`.
                Log::error('crm.outbound.record_failed', [
                    'event_type' => 'erasure',
                    'journalist_id' => $journalist->id,
                    'exception' => $e->getMessage(),
                ]);
                report($e);
            }
        }

        return response()->json(null, 204);
    }
}
