<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\VerrouOptimiste;
use App\Jobs\EnrichCompanyJob;
use App\Models\Company;
use App\Services\Email\EmailConfidenceService;
use App\Services\Waterfall\WaterfallOrchestrator;
use App\Support\CompanyQueryFilters;
use App\Support\EligibiliteCampagne;
use App\Support\MasquageCoordonnees;
use App\Support\PlafondExport;
use App\Support\TotalListe;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompaniesController extends ApiController
{
    // 🔴 G43-005 (S0) — verrouillage optimiste OPTIONNEL sur `update()`. Le trait
    // ne s'active que si le client annonce l'etat qu'il modifiait ; sans jeton,
    // rien ne change. Cf. `App\Http\Controllers\Concerns\VerrouOptimiste`.
    use VerrouOptimiste;

    public function __construct(private readonly WaterfallOrchestrator $waterfall) {}

    /**
     * @OA\Get(
     *     path="/companies",
     *     tags={"Companies"},
     *     summary="Liste paginée des entreprises du workspace courant",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=25, maximum=100)),
     *     @OA\Parameter(name="filter[naf]", in="query", @OA\Schema(type="string", example="6201Z")),
     *     @OA\Parameter(name="filter[size_category]", in="query", @OA\Schema(type="string", enum={"tpe","pme","eti","ge"})),
     *     @OA\Parameter(name="filter[priority]", in="query", @OA\Schema(type="string", enum={"haute","moyenne","basse","gelee"})),
     *     @OA\Parameter(name="filter[denomination]", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="sort", in="query", @OA\Schema(type="string", example="-quality_score")),
     *
     *     @OA\Response(response=200, description="Liste paginée"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     * )
     */
    public function index(Request $r): JsonResponse
    {
        $perPage = min(100, max(1, (int) $r->query('per_page', 25)));

        // Sprint 18.9 — defensive : table absente en env fraîche → liste vide
        if (! Schema::hasTable('companies')) {
            return $this->ok([
                'data' => [],
                'meta' => ['total' => 0, 'per_page' => $perPage, 'current_page' => 1, 'last_page' => 1],
            ]);
        }

        // 🔴 Scope EXPLICITE, comme `export()` le porte déjà. Cette liste ne
        // s'appuyait QUE sur la RLS : la défense en profondeur du lot L0 vaut
        // pour les DEUX couches, pas pour l'une OU l'autre. Un test
        // d'étanchéité (`EtancheiteUniversTest`) l'a découvert — un membre du
        // seul vivier obtenait des fiches commerciales dès que la RLS n'était
        // pas la couche active.
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;

        if ($workspaceId === null) {
            return $this->ok([
                'data' => [],
                'meta' => ['total' => 0, 'per_page' => $perPage, 'current_page' => 1, 'last_page' => 1],
            ]);
        }

        try {
            // Les méthodes de `QueryBuilder` (allowedSorts/defaultSort) AVANT
            // le `where` Eloquent : l'ordre inverse perd le type et le scope
            // ne compilerait pas.
            $query = $this->buildFilteredQuery()
                ->allowedSorts(...['quality_score', 'enriched_at', 'denomination', 'created_at'])
                ->defaultSort('-quality_score')
                ->where('workspace_id', $workspaceId);

            // 🔴 G41-006 (S1) — LE TOTAL COUTAIT 148 FOIS LA PAGE.
            //
            // `paginate($perPage)` emet TOUJOURS un `select count(*)` complet
            // avant d'aller chercher la page. Mesure du 2026-08-20 sur
            // `axion_crm_perf4m` (2 800 000 fiches), les deux requetes du meme
            // affichage au MEME instant :
            //
            //     count(*) ... Index Only Scan, Heap Fetches: 0
            //                  1 818,064 ms froid / 490,431 ms chaud
            //     la page  ... Index Scan, 4 tampons
            //                      3,310 ms
            //
            // Le comptage est DEJA servi par le meilleur index possible
            // (`idx_companies_ws_counts`) : aucun index ne le reparera, car
            // compter 2,8 M de lignes exige d'en visiter 2,8 M. Le seul remede
            // est de ne pas recompter a chaque affichage.
            //
            // Le 5e parametre de `paginate()` est le point d'accroche prevu par
            // le cadre lui-meme (`Eloquent\Builder::paginate()`, l. 1120 :
            // `$total = value($total) ?? $this->toBase()->getCountForPagination()`).
            // En le fournissant, on saute le comptage -- et RIEN D'AUTRE ne
            // change : le paginateur reste un `LengthAwarePaginator`, donc
            // `total`, `per_page`, `current_page` et `last_page` gardent leur
            // sens et leurs valeurs. C'est ce qui distingue ce correctif de
            // `simplePaginate()`, qui aurait supprime deux de ces quatre champs
            // et casse `CompaniesListPage.tsx:751`.
            //
            // Le total reste un comptage EXACT, simplement pas a la seconde
            // pres (60 s de fraicheur). Cf. `App\Support\TotalListe`.
            $page = $query->paginate(
                $perPage,
                ['*'],
                'page',
                null,
                // `toBase()` et non `getEloquentBuilder()` : un comptage
                // n'hydrate aucun modele, et `toBase()` est compris a la fois
                // par l'enveloppe `Spatie\QueryBuilder` (reforwarde par
                // `__call`) et par l'analyse statique, qui perd le type Spatie
                // des le premier `->where()`.
                TotalListe::pour($query->toBase(), $workspaceId),
            );

            // Masquage des coordonnées pour les comptes en lecture seule
            // (§2.10). On ne transforme la sortie QUE dans ce cas : pour les
            // autres rôles, la forme de la réponse reste rigoureusement celle
            // d'avant — un masquage ne doit pas devenir une refonte d'API.
            $lignes = MasquageCoordonnees::requis()
                // Le paginateur est typé `Model` : on ne présume pas de la
                // classe concrète, on masque les colonnes si elles existent.
                ? array_map(static function (Model $ligne): Model {
                    $ligne->setAttribute(
                        'email_generic',
                        MasquageCoordonnees::email($ligne->getAttribute('email_generic')),
                    );
                    $ligne->setAttribute(
                        'phone',
                        MasquageCoordonnees::telephone($ligne->getAttribute('phone')),
                    );

                    return $ligne;
                }, $page->items())
                : $page->items();

            return $this->ok([
                'data' => $lignes,
                'meta' => [
                    'total' => $page->total(),
                    'per_page' => $page->perPage(),
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('companies.index failed', ['exception' => $e->getMessage()]);
            report($e);

            return $this->ok([
                'data' => [],
                'meta' => ['total' => 0, 'per_page' => $perPage, 'current_page' => 1, 'last_page' => 1],
                'degraded' => true,
            ]);
        }
    }

    /**
     * Query filtrée partagée entre la liste (index) et l'export.
     * Applique les MÊMES filtres → l'export = exactement la liste affichée.
     * (Les 4 derniers filtres étaient envoyés par le front mais absents ici → ignorés.)
     */
    private function buildFilteredQuery(): QueryBuilder
    {
        // Liste PARTAGÉE avec la console v2 (lot L6) : deux listes jumelles
        // finissent toujours par diverger, et l'écart se découvre par un export
        // qui ne correspond plus à la liste affichée.
        return QueryBuilder::for(Company::query()->whereNull('deleted_at'))
            ->allowedFilters(...CompanyQueryFilters::allowed());
    }

    /**
     * @OA\Get(
     *     path="/companies/export",
     *     tags={"Companies"},
     *     summary="Exporte en CSV la liste filtrée (emails, téléphones, dirigeants) pour transfert/emailing",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Response(response=200, description="Fichier CSV (streamé)"),
     * )
     */
    public function export(Request $r): StreamedResponse
    {
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
        $filename = 'entreprises-' . now()->format('Y-m-d') . '.csv';
        $header = ['SIREN', 'Dénomination', 'Enseigne', 'NAF', 'Taille', 'Département', 'Ville', 'Email', 'Confiance email', 'Téléphone', 'Site web', 'Google Maps', 'Contacts / dirigeants', 'Spécialité(s) santé'];
        $hasSante = Schema::hasTable('health_practitioners');

        // Table absente ou pas de workspace → CSV vide (jamais 500, jamais de fuite).
        if (! Schema::hasTable('companies') || $workspaceId === null) {
            return response()->streamDownload(function () use ($header) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, $header);
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        // Scope EXPLICITE par workspace (défense principale : pas de fuite entre
        // tenants, indépendamment de l'état RLS pendant le streaming).
        // 🔴 LES CONTACTS OPPOSÉS NE SORTENT PAS DANS LE CSV.
        //
        // Cet export embarquait `nom prénom (rôle) email téléphone` de CHAQUE
        // contact, y compris ceux inscrits en `opt_out` ou en
        // `email_suppressions` — sur 4,29 M de fiches. La garde d'éligibilité
        // existait (`EligibiliteCampagne`) mais n'était appliquée que si
        // l'appelant passait `filter[eligible_campagne]` ; l'export ne le
        // passait pas. Constaté le 2026-08-16.
        //
        // Exporter un CSV de prospects EST un usage de prospection : une
        // personne qui s'y est opposée ne doit pas y figurer, même sans son
        // adresse. On filtre donc au chargement de la relation.
        // La fermeture reçoit une `HasMany`, pas un `Builder` : on passe donc
        // `getQuery()`, qui EST la requête de la relation — les contraintes
        // s'y appliquent bien. C'est aussi ce qui rend le type générique
        // résoluble par l'analyse statique.
        $chargeContacts = static function (Relation $relation): void {
            EligibiliteCampagne::exclureOpposes($relation->getQuery(), 'contacts.email');
        };

        // `getEloquentBuilder()` : `buildFilteredQuery()` rend un
        // `Spatie\QueryBuilder\QueryBuilder`, enveloppe qui n'étend PLUS
        // `Eloquent\Builder` depuis la v6. Ici le code tournait — `chunkById`
        // est reforwardé par `__call` — mais le même geste, en `MediaController`
        // et `JournalistsController`, levait un TypeError et rendait 500
        // (constat F36-008). On déballe donc explicitement, pour que le plafond
        // partagé reçoive un vrai Builder et que les trois exports parlent le
        // même langage.
        $query = $this->buildFilteredQuery()
            ->where('workspace_id', $workspaceId)
            ->with($hasSante
                ? ['contacts' => $chargeContacts, 'healthPractitioners']
                : ['contacts' => $chargeContacts])
            ->getEloquentBuilder();

        $confidenceScorer = new EmailConfidenceService;

        // `$hasSante` doit traverser les DEUX fermetures : il est décidé ici
        // (la table `health_practitioners` existe-t-elle ?) mais relu tout au
        // fond, à la ligne des spécialités. Il manquait des deux `use` : la
        // colonne « Spécialité(s) santé » était donc TOUJOURS vide à l'export,
        // et PHP lisait une variable indéfinie à chaque ligne.
        return response()->streamDownload(function () use ($query, $header, $confidenceScorer, $hasSante) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 → Excel FR lit les accents
            fputcsv($out, $header);
            // 🔴 PLAFOND (constat G41-007, S1). `chunkById(1000)` était stable
            // en mémoire, mais SANS BORNE : au volume mesuré — 4 295 349 fiches
            // — cela fait 4 296 allers-retours SQL et gèle un worker PHP-FPM au
            // moins deux minutes. Le plafond rend le pire cas FINI, et le
            // fichier DIT qu'il est coupé. Cf. App\Support\PlafondExport.
            $tronque = PlafondExport::parcourirBorne($query, function ($c) use ($out, $confidenceScorer, $hasSante) {
                $contacts = $c->contacts
                    ->map(function ($ct) {
                        $name = trim(($ct->first_name ?? '') . ' ' . ($ct->last_name ?? ''));
                        $bits = array_filter([
                            $name,
                            $ct->role ? "({$ct->role})" : '',
                            $ct->email ?? '',
                            $ct->phone ?? '',
                        ]);

                        return trim(implode(' ', $bits));
                    })
                    ->filter()
                    ->implode(' | ');
                $specialites = $hasSante
                    ? $c->healthPractitioners->pluck('specialite')->filter()->unique()->implode(', ')
                    : '';
                // Lien Google Maps : coordonnées GPS si dispo (précis), sinon
                // requête textuelle sur l'adresse postale.
                if ($c->lat !== null && $c->lon !== null) {
                    $mapsUrl = 'https://www.google.com/maps/search/?api=1&query=' . $c->lat . ',' . $c->lon;
                } else {
                    $mapsUrl = 'https://www.google.com/maps/search/?api=1&query='
                        . rawurlencode(trim(($c->address ?? '') . ', ' . ($c->postcode ?? '') . ' ' . ($c->city_name ?? $c->city ?? '')));
                }
                fputcsv($out, [
                    $c->siren,
                    $c->denomination,
                    $c->enseigne,
                    $c->naf,
                    $c->size_category,
                    $c->department_code,
                    $c->city_name,
                    $c->email_generic,
                    $this->resolveBestConfidence($c, $confidenceScorer),
                    $c->phone,
                    $c->website,
                    $mapsUrl,
                    $contacts,
                    $specialites,
                ]);
            });
            if ($tronque) {
                PlafondExport::ecrireAvertissement($out, count($header));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8'] + PlafondExport::entetes());
    }

    /**
     * Confiance email A/B/C à afficher pour une société dans l'export.
     * Utilise `best_email_confidence` s'il est déjà calculé (cron
     * prospection:score-email-confidence) ; sinon recalcule à la volée depuis
     * les contacts déjà eager-loaded + email_generic (aucune requête N+1).
     */
    private function resolveBestConfidence(Company $c, EmailConfidenceService $scorer): ?string
    {
        if (! empty($c->best_email_confidence)) {
            return $c->best_email_confidence;
        }

        $rank = ['A' => 1, 'B' => 2, 'C' => 3];
        $best = null;
        $consider = function (?string $conf) use (&$best, $rank): void {
            if ($conf !== null && isset($rank[$conf]) && ($best === null || $rank[$conf] < $rank[$best])) {
                $best = $conf;
            }
        };

        foreach ($c->contacts as $ct) {
            $consider($ct->email_confidence ?? ($ct->email ? $scorer->score((string) $ct->email, $c->website) : null));
        }
        if (! empty($c->email_generic)) {
            $consider($scorer->score((string) $c->email_generic, $c->website));
        }

        return $best;
    }

    /**
     * @OA\Post(
     *     path="/companies",
     *     tags={"Companies"},
     *     summary="Crée une entreprise manuellement (SIREN obligatoire)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"siren"},
     *
     *         @OA\Property(property="siren", type="string", example="123456789"),
     *         @OA\Property(property="denomination", type="string"),
     *         @OA\Property(property="discovery_source", type="string"),
     *     )),
     *
     *     @OA\Response(response=201, description="Créée"),
     *     @OA\Response(response=422, description="Validation"),
     * )
     */
    public function store(Request $r): JsonResponse
    {
        $validated = $r->validate([
            'siren' => ['required', 'string', 'size:9', 'regex:/^\d{9}$/'],
            'denomination' => ['nullable', 'string', 'max:255'],
            'discovery_source' => ['nullable', 'string', 'max:64'],
        ]);
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
        $company = Company::create([
            'workspace_id' => $workspaceId,
            'siren' => $validated['siren'],
            'denomination' => $validated['denomination'] ?? null,
            'discovery_source' => $validated['discovery_source'] ?? 'manual',
        ]);

        // G41-006 : le total de la liste est mis en cache 60 s. Sans cet oubli,
        // l'operateur creerait une fiche, la verrait s'afficher, et le compteur
        // juste au-dessus continuerait d'annoncer l'ancien nombre pendant une
        // minute -- le produit contredirait le geste qu'il vient de faire.
        // Le patron est celui de `CompteursHub::oublier`, appele au meme titre
        // par `BulkController` apres une action de masse.
        if (is_string($workspaceId) && $workspaceId !== '') {
            TotalListe::oublier($workspaceId);
        }

        // Meme regle qu'a la fiche : ce qui sort porte `phone` et
        // `email_generic`. Une creation par un role sans `contacts.view_pii`
        // (role sur mesure : le referentiel seme n'en produit pas) ne doit pas
        // devenir la porte que `show()` vient de fermer.
        return $this->ok(MasquageCoordonnees::masquerSiRequis($company), 201);
    }

    /**
     * @OA\Get(
     *     path="/companies/{company}",
     *     tags={"Companies"},
     *     summary="Détail d'une entreprise (contacts + tags inclus)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="company", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="OK"),
     *     @OA\Response(response=404, description="Not found"),
     * )
     */
    public function show(Company $company): JsonResponse
    {
        // Meme raison que sur les contacts : la fiche d'un autre locataire etait
        // lisible en devinant un identifiant (F36-005 / B12-001, S0).
        $this->refuserHorsEspace($company);

        $relations = ['contacts', 'tags'];
        if (Schema::hasTable('health_practitioners')) {
            $relations[] = 'healthPractitioners';
        }

        // 🔴 B12-002 / F36-006 (S1). Le masquage couvrait TROIS listes et
        // AUCUNE fiche. Ici la fiche charge `contacts` : elle livrait donc les
        // coordonnees NOMINATIVES, pas seulement l'accueil de la societe. Et
        // cette route ne porte aucune permission au-dela du groupe
        // (routes/api.php:138) : un `viewer` -- qui n'a que `companies.view`,
        // `llm.view_usage`, `rgpd.view` -- l'atteint. Les identifiants de
        // `companies` etant des entiers consecutifs, il suffisait de lire la
        // liste masquee, d'y relever les identifiants, puis de rappeler chaque
        // fiche une par une. Le masquage des listes ne coutait rien.
        //
        // `masquerSiRequis` descend dans les relations DEJA chargees : la regle
        // ne vit plus dans l'appelant, qui n'a plus a savoir quelles colonnes
        // portent une coordonnee.
        return $this->ok(MasquageCoordonnees::masquerSiRequis($company->load($relations)));
    }

    /**
     * @OA\Put(
     *     path="/companies/{company}",
     *     tags={"Companies"},
     *     summary="Met à jour une entreprise (champs éditables côté commercial)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="company", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="If-Match", in="header", required=false,
     *         description="Jeton de version OPTIONNEL (G43-005) : celui de l'en-tête `ETag` d'une réponse 200. La mise à jour est refusée en 409 si la fiche a changé depuis. Sans cet en-tête, aucun contrôle de concurrence — comportement historique.",
     *
     *         @OA\Schema(type="string", example="3f1c9a0b2d4e6f8091a2b3c4d5e6f708")),
     *
     *     @OA\RequestBody(@OA\JsonContent(
     *
     *         @OA\Property(property="priority", type="string", enum={"haute","moyenne","basse","gelee"}),
     *         @OA\Property(property="denomination", type="string"),
     *         @OA\Property(property="website", type="string", format="url"),
     *         @OA\Property(property="phone", type="string"),
     *         @OA\Property(property="linkedin_url", type="string", format="url"),
     *         @OA\Property(property="updated_at", type="string", format="date-time",
     *             description="Forme dégradée du jeton de version, alternative à `If-Match`. À prendre dans un GET, jamais dans le corps d'un PUT : celui-ci rend l'horodatage calculé par PHP, pas celui posé par le trigger Postgres."),
     *     )),
     *
     *     @OA\Response(response=200, description="Updated — l'en-tête `ETag` porte le jeton de l'état d'après"),
     *     @OA\Response(response=409, description="version_conflict — la fiche a été modifiée depuis la lecture du client. Le corps porte `current_version`, avec lequel rejouer."),
     * )
     */
    public function update(Request $r, Company $company): JsonResponse
    {
        // Constat B12-001 / F36-005 : la resolution de route rendait
        // l'enregistrement sans aucun filtre d'espace. 404, jamais 403 :
        // « interdit » confirmerait son existence.
        $this->refuserHorsEspace($company);

        // 🔴 G43-005 (S0) — DEUX SAISIES SIMULTANEES, UNE DISPARAISSAIT EN SILENCE.
        //
        // Cette methode ecrivait sur ce que la resolution de route avait charge,
        // sans jamais comparer avec l'etat que le client croyait modifier. Deux
        // commerciaux ouvrent la meme fiche, enregistrent chacun leur
        // formulaire : les DEUX recoivent 200, la premiere saisie est effacee,
        // personne n'est averti. Mesure le 2026-08-20 par
        // `tests/Feature/EditionConcurrenteTest.php`.
        //
        // Le controle est OPTIONNEL, et c'est delibere. Le client qui veut etre
        // protege envoie l'etat sur lequel il travaillait (`If-Match`, ou
        // `updated_at` dans le corps) et recoit 409 s'il a ete double. Celui qui
        // n'envoie rien garde EXACTEMENT le comportement d'avant : imposer le
        // jeton reviendrait a rendre 409 a tout appelant existant, c'est-a-dire
        // a changer le contrat de la route au lieu de corriger un defaut.
        //
        // Ce controle precede la validation : un conflit d'edition n'est pas une
        // faute de saisie, et le signaler en premier evite d'annoncer un 422 sur
        // un formulaire qui, de toute facon, ne devait pas etre ecrit.
        $this->refuserSiVersionPerimee($r, $company);

        $validated = $r->validate([
            'priority' => ['nullable', Rule::in(['haute', 'moyenne', 'basse', 'gelee'])],
            'denomination' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'linkedin_url' => ['nullable', 'url', 'max:255'],
        ]);
        $company->update($validated);

        // L'ecriture est faite AVANT le masquage : ce qui part en reponse est
        // masque, ce qui est en base ne l'est pas. Une garde relit la base
        // apres coup pour l'interdire (MasquageFicheDetailleeTest).
        //
        // L'en-tete `ETag` porte le jeton de l'etat d'APRES : sans lui, aucun
        // client ne pourrait obtenir de jeton, et le verrou serait du decor. Le
        // CORPS de la reponse, lui, n'est pas modifie d'un octet.
        return $this->avecJetonDeVersion(
            $this->ok(MasquageCoordonnees::masquerSiRequis($company)),
            $company,
        );
    }

    /**
     * @OA\Delete(
     *     path="/companies/{company}",
     *     tags={"Companies"},
     *     summary="Soft-delete une entreprise (deleted_at posé)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="company", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=204, description="No content"),
     * )
     */
    public function destroy(Company $company): JsonResponse
    {
        // Constat B12-001 / F36-005 : la resolution de route rendait
        // l'enregistrement sans aucun filtre d'espace. 404, jamais 403 :
        // « interdit » confirmerait son existence.
        $this->refuserHorsEspace($company);

        $company->delete();

        // Meme raison qu'a la creation (G41-006) : la corbeille sort la fiche
        // de la liste, donc du total. Un compteur qui ne redescend pas apres
        // une suppression est le defaut le plus visible qu'un cache puisse
        // produire.
        $workspaceId = $company->getAttribute('workspace_id');
        if (is_string($workspaceId) && $workspaceId !== '') {
            TotalListe::oublier($workspaceId);
        }

        return response()->json(null, 204);
    }

    /**
     * @OA\Post(
     *     path="/companies/{company}/enrich",
     *     tags={"Companies"},
     *     summary="Déclenche l'enrichissement waterfall (NAF→SIRENE→LLM→scrape)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="company", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Entreprise enrichie"),
     * )
     */
    public function enrich(Company $company): JsonResponse
    {
        // Constat B12-001 / F36-005 : la resolution de route rendait
        // l'enregistrement sans aucun filtre d'espace. 404, jamais 403 :
        // « interdit » confirmerait son existence.
        $this->refuserHorsEspace($company);

        $this->waterfall->enrich($company);

        // Quatrieme site qui rend une coordonnee : l'enrichissement RENVOIE
        // les contacts qu'il vient de decouvrir -- c'est meme sa raison d'etre.
        return $this->ok(MasquageCoordonnees::masquerSiRequis($company->fresh()->load('contacts')));
    }

    /**
     * @OA\Post(
     *     path="/companies/bulk-enrich",
     *     tags={"Companies"},
     *     summary="Enrichit en bulk jusqu'à 500 entreprises via job Horizon",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"ids"},
     *
     *         @OA\Property(property="ids", type="array", maxItems=500, @OA\Items(type="integer")),
     *     )),
     *
     *     @OA\Response(response=200, description="Jobs queués"),
     * )
     */
    public function bulkEnrich(Request $r): JsonResponse
    {
        $ids = $r->validate(['ids' => 'required|array|max:500', 'ids.*' => 'integer'])['ids'];

        // B11-002 : l'espace vient de la REQUETE, pas de la ligne. Sous RLS
        // armee, un job ne peut pas relire lui-meme le `workspace_id` d'une
        // entreprise : sa lecture d'amorcage serait filtree, elle aussi.
        $workspaceId = app()->bound('workspace.id') ? (string) app('workspace.id') : null;

        foreach ($ids as $id) {
            dispatch((new EnrichCompanyJob((int) $id))->pourEspace($workspaceId));
        }

        return $this->ok(['queued' => count($ids)]);
    }

    /**
     * @OA\Post(
     *     path="/companies/{company}/recompute-score",
     *     tags={"Companies"},
     *     summary="Recalcule le quality_score (fonction Postgres)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="company", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Score recalculé"),
     * )
     */
    public function recomputeScore(Company $company): JsonResponse
    {
        // Constat B12-001 / F36-005 : la resolution de route rendait
        // l'enregistrement sans aucun filtre d'espace. 404, jamais 403 :
        // « interdit » confirmerait son existence.
        $this->refuserHorsEspace($company);

        DB::statement('SELECT recompute_company_quality_score(?)', [$company->id]);

        return $this->ok(MasquageCoordonnees::masquerSiRequis($company->fresh()));
    }
}
