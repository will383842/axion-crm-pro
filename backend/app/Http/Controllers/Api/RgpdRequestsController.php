<?php

namespace App\Http\Controllers\Api;

use App\Crm\Outbound\ConsentOutboundRecorder;
use App\Models\RgpdRequest;
use App\Services\Dedup\DeduplicationService;
use App\Services\Rgpd\GdprErasureService;
use App\Services\Rgpd\GdprPortabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use LogicException;

class RgpdRequestsController extends ApiController
{
    /**
     * VOCABULAIRE FERME DES DEMANDES, aligne mot pour mot sur la contrainte
     * `CHECK (type IN (...))` de `rgpd_requests` (migration
     * `2026_05_16_000006_create_coverage_rgpd_aiact_schema`).
     *
     * La garde `AccuseDeReceptionRgpdNeMentPasTest` ne RECOPIE PAS cette liste :
     * elle la relit dans `pg_constraint` et exige que chaque valeur du
     * CATALOGUE soit reellement executee ici. Une sixieme valeur ajoutee en base
     * fera rougir la garde au lieu de tomber dans un `default` muet.
     */
    public const TYPES = ['access', 'portability', 'erasure', 'rectification', 'opposition'];

    public function __construct(
        private readonly GdprErasureService $erasure,
        private readonly GdprPortabilityService $portability,
        private readonly DeduplicationService $dedup,
    ) {}

    /**
     * @OA\Get(path="/rgpd/requests", tags={"RGPD"}, summary="Liste demandes RGPD (art. 15-22)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="status", in="query", @OA\Schema(type="string", enum={"pending","done","rejected"})),
     *
     *     @OA\Response(response=200, description="OK"))
     */
    public function index(Request $r): JsonResponse
    {
        // Sprint 18.9 — defensive : tables RGPD peuvent ne pas exister en env partielle
        if (! Schema::hasTable('rgpd_requests')) {
            return $this->ok(['data' => [], 'meta' => ['total' => 0]]);
        }

        // 🔴 CONSTAT P6-API-002 (S0). Cette liste rendait les demandes RGPD de
        // TOUS les espaces, `subject_email` EN CLAIR, a tout compte
        // authentifie. Le controleur voisin `AuditLogsController` porte, ecrit
        // noir sur blanc, le raisonnement qui manquait ici -- « une permission
        // separe les ROLES, jamais les CLIENTS » -- et cette route-ci n'avait
        // recu que la permission.
        //
        // Une fuite par la LISTE est pire qu'une fuite par la fiche : la fiche
        // demande de deviner un identifiant, la liste les donne tous.
        $espaceCourant = $this->espaceCourantOuNull();

        try {
            $rows = RgpdRequest::query()
                ->when(
                    $espaceCourant !== null,
                    fn ($q) => $q->where('workspace_id', $espaceCourant),
                    // Sans contexte d'espace, on ne rend RIEN : sur des donnees
                    // nominatives, le doute se tranche en faveur du silence.
                    fn ($q) => $q->whereRaw('1 = 0'),
                )
                ->when($r->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->orderByDesc('requested_at')
                ->paginate(25);

            return $this->ok($rows);
        } catch (\Throwable $e) {
            Log::error('rgpd.requests.index failed', ['exception' => $e->getMessage()]);
            report($e);

            return $this->ok(['data' => [], 'meta' => ['total' => 0], 'degraded' => true]);
        }
    }

    /**
     * @OA\Post(path="/rgpd/requests", tags={"RGPD"}, summary="Crée une demande RGPD",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"type","subject_email"},
     *
     *         @OA\Property(property="type", type="string", enum={"access","portability","erasure","rectification","opposition"}),
     *         @OA\Property(property="subject_email", type="string", format="email"),
     *         @OA\Property(property="metadata", type="object"))),
     *
     *     @OA\Response(response=201, description="Créée"))
     */
    public function store(Request $r): JsonResponse
    {
        $validated = $r->validate([
            'type' => ['required', Rule::in(self::TYPES)],
            'subject_email' => ['required', 'email', 'max:254'],
            'metadata' => ['nullable', 'array'],
        ]);

        $req = RgpdRequest::create([
            'workspace_id' => app()->bound('workspace.id') ? app('workspace.id') : null,
            'type' => $validated['type'],
            'status' => 'pending',
            'subject_email' => $validated['subject_email'],
            'requested_at' => now(),
            'metadata' => $validated['metadata'] ?? [],
        ]);

        return $this->ok($req, 201);
    }

    /**
     * @OA\Post(path="/rgpd/requests/{req}/process", tags={"RGPD"}, summary="Traite une demande (erasure / portability)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="req", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Traité"),
     *     @OA\Response(response=409, description="Déjà traité"))
     */
    public function process(Request $r, RgpdRequest $req): JsonResponse
    {
        // Constat B12-001 / F36-005 : la resolution de route rendait
        // l'enregistrement sans aucun filtre d'espace. 404, jamais 403 :
        // « interdit » confirmerait son existence.
        $this->refuserHorsEspace($req);

        if ($req->status === 'done') {
            return response()->json(['error' => 'already_processed'], 409);
        }

        // 🔴 CONSTAT B14-002 / E31-001 (S0) — « L'ACCUSE DE RECEPTION QUI MENT ».
        //
        // Le constat vise l'effacement, et sur l'effacement il est FAUX cote CRM :
        // mesure du 2026-08-21 sur `axion_crm_test_lot7`, une demande `erasure`
        // traitee ici supprime reellement la fiche (`contacts_restants=0`),
        // inscrit l'opposition dans les DEUX univers (`opt_out=2`) et met le
        // signal en file vers le site. Sa moitie vraie vit dans le depot du site
        // (`crm-sync/inbound.ts:243-261`), qui repond « applied » apres un simple
        // desabonnement. Rien a reparer ici pour `erasure` — la garde le REPROUVE.
        //
        // MAIS LA MEME MESURE A TROUVE LE DEFAUT, INTACT, SUR TROIS DES CINQ
        // TYPES QUE CE POINT D'ENTREE ACCEPTE :
        //
        //   type            HTTP  status  ce qui a ete fait
        //   access          200   done    RIEN  (`{"noop":true}`)  ← art. 15
        //   rectification   200   done    RIEN  (`{"noop":true}`)  ← art. 16
        //   opposition      200   done    RIEN  (`{"noop":true}`)  ← art. 21
        //
        // `opposition` porte exactement la consequence de B14-002 : la personne
        // est inscrite « traitee » au REGISTRE — la piece que le CRM opposerait a
        // un controle — et reste parfaitement joignable, aucune ligne `opt_out`,
        // la porte des campagnes grande ouverte. `access` produisait, lui, un
        // droit d'acces sans le moindre export.
        //
        // Le `default => ['noop' => true]` etait le mecanisme : il ACCUEILLAIT en
        // silence tout type non cable. Il disparait — le `match` est desormais
        // exhaustif sur le CATALOGUE, et un type inconnu leve au lieu de mentir.
        $valide = $r->validate([
            // La console POSTe deja `note` (`RgpdRequestsPage.tsx`, champ
            // « Action effectuee, donnees purgees, motif de rejet… ») et ce
            // controleur la jetait sans la lire : l'operateur ecrivait la trace
            // de son geste dans le vide. Elle est desormais VALIDEE et ARCHIVEE.
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $note = trim((string) ($valide['note'] ?? ''));

        // ── ARTICLE 16 : LE SEUL DROIT QUE CE CRM NE SAIT PAS EXECUTER ───────
        //
        // Rectifier suppose de savoir QUOI corriger et PAR QUOI ; ni la demande
        // ni ce point d'entree ne portent cette information, et aucun service du
        // depot ne la traite. On ne fabrique donc pas un automatisme : on refuse
        // d'estampiller « traitee » une demande dont personne n'a dit ce qu'il en
        // avait fait. Le geste reste manuel — il doit seulement etre DECLARE.
        if ($req->type === 'rectification' && $note === '') {
            return response()->json([
                'error' => 'manual_act_required',
                'message' => 'Une rectification (art. 16) n\'a aucun traitement automatique dans le CRM. '
                    . 'La note est obligatoire : ecrivez ce qui a ete corrige, sur quelle fiche. '
                    . 'Sans elle, la demande reste « en attente » plutot que d\'etre inscrite au registre comme traitee.',
            ], 422);
        }

        $result = match (true) {
            $req->type === 'erasure' => $this->erasure->erase($req->subject_email),

            // Art. 15 et art. 20 rendent le MEME inventaire, et le service
            // s'appelle deja pour les deux (« l'export des articles 15 et 20 »,
            // commit ad7ae55). `access` tombait pourtant dans le `default` : la
            // personne recevait un accuse et aucun fichier.
            //
            // La liste est celle du SERVICE, pas une copie : le jeton qu'il
            // produit n'est echangeable que pour les types qu'il inscrit
            // lui-meme (`TYPES_AVEC_EXPORT`). Recopier ici en dur, c'etait se
            // donner rendez-vous avec un lien de telechargement mort.
            in_array($req->type, GdprPortabilityService::TYPES_AVEC_EXPORT, true) => $this->portability->export($req->subject_email),

            $req->type === 'opposition' => $this->executerOpposition($req->subject_email, $req->id),

            // Acte MANUEL, declare ci-dessus. On l'inscrit comme tel : le
            // registre ne dira jamais d'une rectification qu'un automatisme l'a
            // appliquee.
            $req->type === 'rectification' => ['executed_automatically' => false, 'article' => 16, 'acte' => 'manuel_declare'],

            default => throw new LogicException(
                'Type de demande RGPD sans traitement : ' . $req->type . '. Le catalogue '
                . '(CHECK sur rgpd_requests.type) a gagne une valeur que ce point d\'entree ne sait pas '
                . 'executer ; il vaut mieux echouer bruyamment que repondre « traitee » sans rien faire.',
            ),
        };

        if ($req->type === 'erasure') {
            // Lot L5 — l'effacement décidé DANS la console CRM doit remonter au
            // site : sinon le site continue d'adresser une personne que le CRM
            // a effacée. Mise en file locale, jamais un POST synchrone : la
            // réussite d'un droit art. 17 ne dépend pas de la disponibilité du
            // site (l'émission est portée par `crm:flush-outbound`).
            $this->queueOutbound('erasure', $req->subject_email, $req->id);
        }

        // 🔴 LE JETON DE TELECHARGEMENT NE SE PERSISTE PAS EN CLAIR.
        //
        // `$result` porte, pour une demande de portabilite, le jeton EN CLAIR qui
        // ouvre l'archive chiffree des donnees personnelles de la personne. Il
        // partait tel quel dans `rgpd_requests.metadata` - alors que la colonne
        // dediee, elle, ne garde deliberement qu'un HACHAGE (`export_token`).
        // Quiconque lisait cette table, ou la reponse de l'API, pouvait
        // telecharger l'export complet de n'importe qui.
        // Mesure le 2026-08-19 (audit 360, B15-013).
        //
        // Le jeton reste rendu a l'operateur qui declenche le traitement - il
        // doit bien le transmettre a la personne - mais il n'est plus ECRIT.
        $resultatArchive = $result;
        unset($resultatArchive['token']);

        $archive = ['result' => $resultatArchive];
        if ($note !== '') {
            $archive['note'] = $note;
        }

        $req->update([
            'status' => 'done',
            'processed_at' => now(),
            'processed_by' => $r->user()?->id,
            'metadata' => array_merge((array) $req->metadata, $archive),
        ]);

        return $this->ok(['request' => $req->fresh(), 'result' => $result]);
    }

    /**
     * Art. 21 — OPPOSITION, et elle n'existe que si elle FERME les portes.
     *
     * On ne reinvente rien : c'est exactement le geste par lequel
     * `GdprErasureService::erase()` solde un effacement — `addOptOut()` sur
     * `DeduplicationService::UNIVERS_OPPOSITION`, c'est-a-dire les deux univers
     * a la fois. Le constat B15-001 a montre ce que coute d'en oublier un :
     * opposee en `business` seulement, la personne revenait au vivier a la
     * candidature suivante. La liste des univers est prise a la CONSTANTE, jamais
     * reecrite ici.
     *
     * Puis le signal part vers le site, par la meme mini-outbox que l'effacement.
     * `consent_optout` et non `erasure` : la personne s'oppose au traitement, elle
     * ne demande pas l'effacement — dire au site autre chose que ce qui a ete
     * decide serait, encore, un accuse qui ment.
     *
     * @return array<string, mixed>
     */
    private function executerOpposition(string $email, int $requestId): array
    {
        $this->dedup->addOptOut(
            $email,
            null,
            source: 'gdpr_art21_console',
            reason: 'gdpr_art21',
            scopes: DeduplicationService::UNIVERS_OPPOSITION,
        );

        $this->queueOutbound('consent_optout', $email, $requestId);

        return [
            'opt_out_added' => true,
            'opt_out_scopes' => DeduplicationService::UNIVERS_OPPOSITION,
            'article' => 21,
        ];
    }

    /**
     * Met en file un événement à destination du site (lot L5).
     *
     * Enveloppé : une panne de la mini-outbox ne doit JAMAIS faire échouer un
     * droit RGPD déjà exécuté en base. L'échec est journalisé — pas avalé — et
     * le batch de réconciliation quotidien (plan §2.9) rattrape la divergence.
     *
     * B14-002 : la methode s'appelait `queueOutboundErasure` et ne savait dire
     * qu'`erasure`. L'opposition art. 21, elle, n'emettait RIEN — le site
     * continuait d'ecrire a quelqu'un qui s'y etait oppose dans la console.
     */
    private function queueOutbound(string $eventType, string $email, int $requestId): void
    {
        try {
            app(ConsentOutboundRecorder::class)->recordForEmail(
                $eventType,
                $email,
                // L'effacement console porte sur le stock commercial
                // (contacts / journalistes / médias). Le vivier a son propre
                // canal, `POST /internal/site-sync/gdpr`, dont l'origine est le
                // SITE — et qui, lui, ne doit rien réémettre.
                'business',
                payload: ['surface' => 'console:rgpd_requests', 'rgpd_request_id' => $requestId],
            );
        } catch (\Throwable $e) {
            Log::error('crm.outbound.record_failed', [
                'event_type' => $eventType,
                'rgpd_request_id' => $requestId,
                'exception' => $e->getMessage(),
            ]);
            report($e);
        }
    }

    /**
     * @OA\Get(path="/rgpd/export/{token}", tags={"RGPD"}, summary="Télécharge l'export RGPD via token signé",
     *
     *     @OA\Parameter(name="token", in="path", required=true, @OA\Schema(type="string", maxLength=64)),
     *
     *     @OA\Response(response=200, description="JSON export"),
     *     @OA\Response(response=404, description="Token invalide/expiré"))
     */
    public function export(string $token): JsonResponse
    {
        $json = $this->portability->retrieve($token);
        if (! $json) {
            return response()->json(['error' => 'invalid_or_expired_token'], 404);
        }

        return response()->json(json_decode($json, true), 200, [
            'Content-Disposition' => 'attachment; filename="gdpr-export.json"',
        ]);
    }
}
