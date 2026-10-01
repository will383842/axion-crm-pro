<?php

namespace App\Http\Controllers\Api;

use App\Crm\Campagnes\GardePresse;
use App\Crm\Campagnes\ReglageDestinataires;
use App\Crm\Campagnes\ResolveurDestinataires;
use App\Crm\Campagnes\Segments;
use App\Http\Controllers\Concerns\VerrouOptimiste;
use App\Http\Requests\StoreEmailAudienceRequest;
use App\Http\Resources\EmailAudienceResource;
use App\Models\EmailAudience;
use App\Services\Audiences\AudienceBuilderService;
use App\Services\Audiences\CritereAudienceInvalide;
use App\Support\MasquageCoordonnees;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AudiencesController extends ApiController
{
    use VerrouOptimiste;

    /** Le refus d'afficher une audience presse quand le segment presse est fermé (vouvoiement). */
    public const MESSAGE_PRESSE_FERMEE = 'Le segment presse est fermé : les membres de cette audience presse ne sont pas affichés. '
        . 'Rouvrez le segment presse pour les consulter.';

    public function __construct(private readonly AudienceBuilderService $builder) {}

    /**
     * @OA\Get(path="/audiences", tags={"Audiences"}, summary="Liste audiences",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Response(response=200, description="OK"))
     */
    public function index(Request $r): JsonResponse
    {
        if (! Schema::hasTable('email_audiences')) {
            return $this->ok(['data' => []]);
        }
        try {
            $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
            $q = EmailAudience::query()->whereNull('deleted_at')->orderByDesc('created_at');
            if ($workspaceId) {
                $q->where('workspace_id', $workspaceId);
            }

            return $this->ok(['data' => EmailAudienceResource::collection($q->limit(200)->get())]);
        } catch (\Throwable $e) {
            Log::error('audiences.index failed', ['error' => $e->getMessage()]);

            return $this->ok(['data' => [], 'degraded' => true]);
        }
    }

    public function store(StoreEmailAudienceRequest $request): JsonResponse
    {
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
        if (! $workspaceId) {
            return $this->ok(['error' => 'workspace required'], 422);
        }
        $data = $request->validated();
        // Des critères refusés (champ hors liste, audience presse alors que
        // le segment presse est fermé…) : refus clair, rien n'est créé.
        if (($refus = self::refusCriteres($data['criteria'] ?? [])) !== null) {
            return $refus;
        }

        $audience = EmailAudience::create([
            'workspace_id' => $workspaceId,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'criteria' => $data['criteria'],
            'is_active' => $data['is_active'] ?? true,
            'auto_refresh' => $data['auto_refresh'] ?? true,
            'created_by' => optional($request->user())->id,
        ] + self::reglageAEcrire($data));

        // First refresh inline (rapide pour audience nouvelle)
        try {
            $this->builder->refresh($audience);
        } catch (\Throwable $e) {
            Log::warning('audience initial refresh failed', ['id' => $audience->id, 'error' => $e->getMessage()]);
        }

        return $this->ok(['data' => new EmailAudienceResource($audience->fresh())], 201);
    }

    public function show(EmailAudience $audience): JsonResponse
    {
        $this->assertWorkspace($audience);

        return $this->ok(['data' => new EmailAudienceResource($audience)]);
    }

    public function update(Request $request, EmailAudience $audience): JsonResponse
    {
        $this->assertWorkspace($audience);
        // ── VERROU OPTIMISTE (G43-005) ───────────────────────────────────
        //
        // Deux personnes ouvrent la meme fiche, la modifient, enregistrent :
        // la seconde ecrasait la premiere, et les DEUX recevaient « succes ».
        // Rien ne le disait a personne. La saisie perdue ne laisse aucune trace.
        //
        // Le mecanisme n'est pas invente ici : `CompaniesController` le porte
        // depuis le lot G43-005, par le trait partage. Il reste OPTIONNEL —
        // sans en-tete `If-Match`, le comportement historique ne change pas, ce
        // qui evite de casser les clients existants. Mais le client qui l'envoie
        // est desormais protege ICI AUSSI.
        $this->refuserSiVersionPerimee($request, $audience);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'criteria' => ['sometimes', 'required', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'auto_refresh' => ['sometimes', 'boolean'],
        ] + StoreEmailAudienceRequest::reglesDestinataires());
        if (array_key_exists('criteria', $data) && ($refus = self::refusCriteres($data['criteria'])) !== null) {
            return $refus;
        }
        $audience->update(self::reglageAEcrire($data) + array_diff_key($data, array_flip([
            'destinataires_mode', 'destinataires_fonctions',
            'destinataires_personnes_listees', 'destinataires_avec_adresses_partagees',
        ])));

        // ⚠️ L'EN-TETE `ETag` PORTE LE JETON DE L'ETAT D'APRES.
        //
        // Sans lui, aucun client ne peut obtenir de jeton, et le verrou pose
        // au-dessus serait du DECOR : `refuserSiVersionPerimee()` ne se declenche
        // que si le client annonce un etat, et il ne peut l'annoncer que s'il l'a
        // recu. Le CORPS de la reponse n'est pas modifie d'un octet.
        // `refresh()` et non `fresh()` : `fresh()` peut rendre `null` (PHPStan le
        // signale a juste titre — la ligne peut avoir disparu entre l'ecriture et
        // la relecture), et il partait ici DEUX fois en base, une pour le corps
        // et une pour le jeton. `refresh()` recharge l'instance en place et rend
        // `$this` : un seul aller-retour, et un modele non nul.
        $audience->refresh();

        return $this->avecJetonDeVersion(
            $this->ok(['data' => new EmailAudienceResource($audience)]),
            $audience,
        );
    }

    public function destroy(EmailAudience $audience): JsonResponse
    {
        $this->assertWorkspace($audience);
        $audience->delete();

        return $this->ok(['ok' => true]);
    }

    /**
     * POST /audiences/preview — count companies/contacts pour criteria donnés (sans persist).
     */
    public function preview(Request $request): JsonResponse
    {
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
        if (! $workspaceId) {
            return $this->ok(['companies' => 0, 'contacts' => 0]);
        }
        $request->validate(['criteria' => ['required', 'array']]);
        try {
            $result = $this->builder->preview($workspaceId, $request->input('criteria', []));
        } catch (CritereAudienceInvalide $e) {
            // Dit en clair (audience presse refusée quand le segment est fermé…).
            return $this->ok(['message' => 'Critères refusés : ' . $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::warning('audiences.preview failed', ['error' => $e->getMessage()]);

            return $this->ok(['companies' => 0, 'contacts' => 0, 'error' => 'preview_failed']);
        }

        return $this->ok($result);
    }

    /**
     * GET /audiences/{audience}/destinataires — À QUI l'audience écrirait,
     * selon son réglage : organisations, adresses distinctes, exclues par
     * motif, écartées par le réglage, et un échantillon. N'envoie rien,
     * n'écrit rien. Adresses masquées sans `contacts.view_pii`.
     */
    public function destinataires(EmailAudience $audience, ResolveurDestinataires $resolveur): JsonResponse
    {
        $this->assertWorkspace($audience);
        $criteres = $audience->getAttribute('criteria');

        return $this->apercu(
            $resolveur,
            (string) $audience->workspace_id,
            is_array($criteres) ? $criteres : [],
            ReglageDestinataires::deLAudience($audience),
        );
    }

    /**
     * POST /audiences/apercu-destinataires — le même aperçu pour des critères
     * et un réglage pas encore enregistrés (écran de création).
     */
    public function apercuDestinataires(Request $request, ResolveurDestinataires $resolveur): JsonResponse
    {
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
        if (! $workspaceId) {
            return $this->ok(['error' => 'workspace required'], 422);
        }
        $data = $request->validate([
            'criteria' => ['required', 'array'],
        ] + StoreEmailAudienceRequest::reglesDestinataires());
        $criteres = $request->input('criteria', []);

        return $this->apercu(
            $resolveur,
            (string) $workspaceId,
            is_array($criteres) ? $criteres : [],
            ReglageDestinataires::depuisTableau([
                'mode' => $data['destinataires_mode'] ?? null,
                'fonctions' => $data['destinataires_fonctions'] ?? [],
                'personnes_listees' => $data['destinataires_personnes_listees'] ?? false,
                'avec_adresses_partagees' => $data['destinataires_avec_adresses_partagees'] ?? false,
            ]),
        );
    }

    private static function refusCriteres(mixed $criteres): ?JsonResponse
    {
        try {
            AudienceBuilderService::validerCriteres(is_array($criteres) ? $criteres : []);
        } catch (CritereAudienceInvalide $e) {
            return response()->json(['message' => 'Critères refusés : ' . $e->getMessage()], 422);
        }

        return null;
    }

    /** @param  array<mixed>  $criteres */
    private function apercu(ResolveurDestinataires $resolveur, string $workspaceId, array $criteres, ReglageDestinataires $reglage): JsonResponse
    {
        try {
            $resultat = $resolveur->resoudre($workspaceId, $criteres, $reglage);
        } catch (CritereAudienceInvalide $e) {
            return $this->ok(['message' => 'Critères refusés : ' . $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return $this->ok(['message' => $e->getMessage()], 422);
        }

        // Les adresses de l'échantillon, masquées pour la lecture seule.
        if (MasquageCoordonnees::requis()) {
            $resultat['lignes'] = array_map(static function (array $l): array {
                $l['email'] = MasquageCoordonnees::email(is_string($l['email'] ?? null) ? $l['email'] : null);

                return $l;
            }, is_array($resultat['lignes'] ?? null) ? $resultat['lignes'] : []);
        }

        return $this->ok(['data' => $resultat]);
    }

    /**
     * Les colonnes du réglage à écrire, depuis une entrée validée.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function reglageAEcrire(array $data): array
    {
        $colonnes = [];
        if (array_key_exists('destinataires_mode', $data)) {
            $colonnes['destinataires_mode'] = (string) $data['destinataires_mode'];
        }
        if (array_key_exists('destinataires_fonctions', $data)) {
            $fonctions = is_array($data['destinataires_fonctions']) ? $data['destinataires_fonctions'] : [];
            $colonnes['destinataires_fonctions'] = ReglageDestinataires::versTableauPg(
                ReglageDestinataires::nettoyerFonctions($fonctions),
            );
        }
        foreach (['destinataires_personnes_listees', 'destinataires_avec_adresses_partagees'] as $cle) {
            if (array_key_exists($cle, $data)) {
                $colonnes[$cle] = (bool) $data[$cle];
            }
        }

        return $colonnes;
    }

    public function refresh(EmailAudience $audience): JsonResponse
    {
        $this->assertWorkspace($audience);
        try {
            $this->builder->refresh($audience);
        } catch (\Throwable $e) {
            Log::error('audiences.refresh failed', ['id' => $audience->id, 'error' => $e->getMessage()]);

            return $this->ok(['error' => 'refresh failed'], 500);
        }

        return $this->ok(['data' => new EmailAudienceResource($audience->fresh())]);
    }

    public function members(EmailAudience $audience, Request $request): JsonResponse
    {
        $this->assertWorkspace($audience);
        $limit = max(1, min(500, (int) $request->query('limit', 50)));
        $criteres = $audience->getAttribute('criteria');
        $criteres = is_array($criteres) ? $criteres : [];
        // Audience presse, segment presse REFERMÉ (`crm.segments_ouverts`) :
        // ses membres ne se lisent plus — refus dit en clair (relecture A09).
        if (AudienceBuilderService::estAudiencePresse($criteres) && ! Segments::ouvert(Segments::PRESSE)) {
            return $this->ok(['message' => self::MESSAGE_PRESSE_FERMEE], 422);
        }

        $rows = DB::table('audience_members as am')
            ->leftJoin('companies as c', 'c.id', '=', 'am.company_id')
            ->leftJoin('contacts as ct', 'ct.id', '=', 'am.contact_id')
            ->where('am.audience_id', $audience->id)
            // La presse n'est lisible dans AUCUNE audience ordinaire : elle
            // n'entre que par une audience presse (`GardePresse`, relecture
            // sécurité de #264) : un membre inscrit avant l'harmonisation reste
            // dans `audience_members` jusqu'au prochain rafraîchissement — il
            // ne s'affiche plus d'ici là. Par fiche ET par contact, comme
            // `AudienceBuilderService`. Une audience presse, elle, ne contient
            // que des adresses de provenance fiable (`lignesMembres`).
            ->when(! AudienceBuilderService::estAudiencePresse($criteres), static fn ($q) => $q
                ->whereRaw(GardePresse::conditionSql('am.company_id'))
                ->whereRaw('(am.contact_id IS NULL OR ' . GardePresse::conditionContactsSql('ct') . ')'))
            ->select(
                'am.id',
                'am.added_at',
                'c.id as company_id',
                'c.denomination',
                'c.department_code',
                'c.size_category',
                'c.sector_main',
                'ct.id as contact_id',
                'ct.first_name',
                'ct.last_name',
                'ct.email',
            )
            ->orderByDesc('am.added_at')
            ->limit($limit)
            ->get();

        // 🔴 SITE JUMEAU de B12-002 / F36-006, et le plus volumineux : `limit`
        // monte jusqu'a 500, et une audience est PRECISEMENT une selection de
        // personnes a demarcher. Un compte en lecture seule y lisait
        // `ct.email` en clair, 500 par appel, sans plafond d'export ni trace.
        //
        // `DB::table(...)->get()` rend des `stdClass`, pas des modeles : c'est
        // la branche `stdClass` de `masquer()` qui travaille ici. Sans elle,
        // cet appel aurait traverse la collection SANS RIEN FAIRE, et le vert
        // aurait ete un vert de facade.
        return $this->ok(['data' => MasquageCoordonnees::masquerSiRequis($rows)]);
    }

    /**
     * 🔴 CONSTAT B12-001 / F36-005. Cette methode etait FAIL-OPEN : `if ($workspaceId && ...)`
     * ne refusait rien quand le contexte manquait (« tolerant en test/dev »).
     * Rien ne distingue un test d'une production, et la tolerance devenait la
     * regle des qu'un appel arrivait avant le middleware.
     *
     * Elle delegue desormais a `ApiController::refuserHorsEspace()`, qui repond
     * 404 quand elle ne sait pas -- et 404, non 403 : « interdit » confirmerait
     * l'existence de la fiche a qui balaie les identifiants.
     */
    private function assertWorkspace(EmailAudience $audience): void
    {
        $this->refuserHorsEspace($audience);
    }
}
