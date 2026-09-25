<?php

namespace App\Http\Controllers\Api\Crm;

use App\Crm\Ingest\ContactUpserter;
use App\Crm\Personnes\Abonnements;
use App\Crm\Taxonomy;
use App\Support\CelluleCsv;
use App\Support\MasquageCoordonnees;
use App\Support\PlafondExport;
use App\Support\WorkspaceContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * « PERSONNES (LETTRE ET GUIDE) » — l'écran des personnes sans entreprise
 * (lot L4-C).
 *
 * Nommé ainsi, et pas « Audience », pour ne pas le confondre avec
 * `AudienceDetailPage` (les audiences d'entreprises). Une personne n'entre dans
 * le hub de contacts, les audiences et leurs exports qu'APRÈS rattachement à
 * une entreprise : `contacts.company_id` et `audience_members.company_id`
 * restent NOT NULL, et c'est voulu.
 *
 * Ce que Will peut faire d'une personne :
 *   - la retrouver par segment (source, statut de la lettre, nature de
 *     l'adresse, rattachée ou non) ;
 *   - ouvrir sa fiche 360° (même timeline que `PersonTimelineController`) ;
 *   - lui poser une tâche ou une relance (`activities.kind = 'task'`) ;
 *   - exporter le segment en CSV — coordonnées réservées à `contacts.view_pii` ;
 *   - la rattacher à une entreprise (par `ContactUpserter`, le MÊME code que
 *     l'ingestion et l'arbitrage).
 *
 * Business seulement : une personne de la lettre n'a rien à faire dans le
 * vivier, et l'y chercher vaut 403 (pas une liste vide).
 */
class PersonnesController extends ConsoleController
{
    private const MAX_TIMELINE = 200;

    public function __construct(private readonly ContactUpserter $contacts) {}

    public function index(Request $request): JsonResponse
    {
        $workspaceId = $this->businessWorkspace($request);
        $perPage = $this->perPage($request);
        $filtres = $this->filtres($request);

        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $filtres, $perPage): JsonResponse {
            $page = $this->requete($workspaceId, $filtres)
                ->orderByDesc('personnes.id')
                ->cursorPaginate($perPage);

            $data = [];
            foreach ($page->items() as $row) {
                /** @var \stdClass $row */
                $data[] = $this->presenter($row);
            }

            return $this->ok([
                'data' => MasquageCoordonnees::masquerTableauSiRequis($data),
                'meta' => [
                    'per_page' => $page->perPage(),
                    'next_cursor' => $page->nextCursor()?->encode(),
                    'prev_cursor' => $page->previousCursor()?->encode(),
                    'has_more' => $page->hasMorePages(),
                ],
            ]);
        });
    }

    /**
     * Les pastilles des segments. Comptées en base, jamais recopiées.
     */
    public function counts(Request $request): JsonResponse
    {
        $workspaceId = $this->businessWorkspace($request);

        return WorkspaceContext::run($workspaceId, function () use ($workspaceId): JsonResponse {
            $total = DB::table('personnes')->where('workspace_id', $workspaceId)->count();

            $parStatut = array_fill_keys(array_merge(Taxonomy::ABONNEMENT_STATUTS, ['aucun']), 0);
            $lignes = DB::table('personnes')
                ->leftJoin('abonnements', function ($join): void {
                    $join->on('abonnements.personne_id', '=', 'personnes.id')
                        ->where('abonnements.canal', '=', 'lettre');
                })
                ->where('personnes.workspace_id', $workspaceId)
                ->selectRaw("coalesce(abonnements.statut, 'aucun') AS statut, count(*) AS total")
                ->groupByRaw("coalesce(abonnements.statut, 'aucun')")
                ->get();
            foreach ($lignes as $ligne) {
                $parStatut[(string) $ligne->statut] = (int) $ligne->total;
            }

            $parNature = array_fill_keys(Taxonomy::PERSONNE_EMAIL_NATURES, 0);
            foreach (DB::table('personnes')->where('workspace_id', $workspaceId)
                ->selectRaw('email_nature, count(*) AS total')->groupBy('email_nature')->get() as $ligne) {
                $parNature[(string) $ligne->email_nature] = (int) $ligne->total;
            }

            $parSource = [];
            foreach (DB::table('personnes')->where('workspace_id', $workspaceId)
                ->selectRaw('premiere_source, count(*) AS total')->groupBy('premiere_source')
                ->orderByDesc('total')->limit(20)->get() as $ligne) {
                $parSource[(string) $ligne->premiere_source] = (int) $ligne->total;
            }

            $rattachees = DB::table('personnes')->where('workspace_id', $workspaceId)->whereNotNull('contact_id')->count();

            return $this->ok([
                'total' => $total,
                'by_statut_lettre' => $parStatut,
                'by_nature' => $parNature,
                'by_source' => $parSource,
                'rattachees' => $rattachees,
                'non_rattachees' => $total - $rattachees,
            ]);
        });
    }

    /**
     * FICHE 360° de la personne : identité, abonnement, tâches et timeline
     * (par `person_key`, la même clé que `PersonTimelineController`).
     */
    public function show(Request $request, int $personneId): JsonResponse
    {
        $id = $personneId;

        $workspaceId = $this->businessWorkspace($request);

        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $id): JsonResponse {
            $personne = $this->personne($workspaceId, $id);

            $abonnement = DB::table('abonnements')
                ->where('workspace_id', $workspaceId)
                ->where('personne_id', $id)
                ->where('canal', 'lettre')
                ->first(['canal', 'statut', 'legal_basis', 'consent_version', 'consent_at', 'consent_text_ref', 'source_slug', 'abonne_at', 'desabonne_at', 'motif_desabonnement']);

            $timeline = DB::table('activities')
                ->where('workspace_id', $workspaceId)
                ->where('person_key', $personne->person_key)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(self::MAX_TIMELINE)
                ->get(['id', 'kind', 'title', 'content', 'occurred_at', 'due_at', 'done_at', 'external_ref'])
                ->map(static fn (object $row): array => [
                    'id' => (int) $row->id,
                    'kind' => $row->kind,
                    'title' => $row->title,
                    // Le texte libre n'est rendu que pour une TÂCHE (saisie ici) :
                    // les notes des autres activités peuvent porter des
                    // coordonnées, et la fiche 360° ne les expose pas non plus.
                    'content' => $row->kind === 'task' ? $row->content : null,
                    'occurred_at' => $row->occurred_at,
                    'due_at' => $row->due_at,
                    'done_at' => $row->done_at,
                    'external_ref' => $row->external_ref,
                ])
                ->all();

            $entreprise = $personne->company_id === null ? null : DB::table('companies')
                ->where('workspace_id', $workspaceId)
                ->where('id', $personne->company_id)
                ->whereNull('deleted_at')
                ->first(['id', 'denomination', 'siren']);

            return $this->ok([
                'personne' => MasquageCoordonnees::masquerTableauSiRequis($this->presenter($personne)),
                'abonnement' => $abonnement === null ? null : (array) $abonnement,
                'entreprise' => $entreprise === null ? null : (array) $entreprise,
                'taches' => array_values(array_filter($timeline, static fn (array $a): bool => $a['kind'] === 'task')),
                'timeline' => $timeline,
            ]);
        });
    }

    /**
     * TÂCHE ou RELANCE sur une personne — une ligne `activities` de nature
     * `task`, avec son échéance (`due_at`) : la colonne existe depuis le
     * premier schéma, il n'y avait rien à créer. Un double clic ne crée qu'une
     * ligne (empreinte du geste à la minute, comme sur la fiche presse).
     */
    public function storeTache(Request $request, int $personneId): JsonResponse
    {
        $id = $personneId;

        $workspaceId = $this->businessWorkspace($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:300'],
            'content' => ['nullable', 'string', 'max:5000'],
            'due_at' => ['nullable', 'date'],
        ]);

        $userId = $this->currentUser($request)->getKey();

        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $id, $data, $userId): JsonResponse {
            $personne = $this->personne($workspaceId, $id);

            $ref = 'console:personne:' . $id . ':task:' . now()->format('Y-m-d\TH:i') . ':' . substr(sha1((string) $data['title']), 0, 12);

            $existante = DB::table('activities')
                ->where('workspace_id', $workspaceId)
                ->where('external_ref', $ref)
                ->value('id');

            if ($existante !== null) {
                return $this->ok(['activity_id' => (int) $existante, 'deja_consignee' => true]);
            }

            $activityId = (int) DB::table('activities')->insertGetId([
                'workspace_id' => $workspaceId,
                'user_id' => $userId,
                'contact_id' => $personne->contact_id,
                'type' => 'task',
                'kind' => 'task',
                'occurred_at' => now(),
                'due_at' => isset($data['due_at']) ? new \DateTimeImmutable((string) $data['due_at']) : null,
                'person_key' => $personne->person_key,
                'external_ref' => $ref,
                'subject_type' => 'personne',
                'subject_id' => $id,
                'title' => (string) $data['title'],
                'content' => isset($data['content']) ? (string) $data['content'] : null,
                'payload' => json_encode(['surface' => 'console:personnes', 'saisie' => 'manuelle'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return $this->ok(['activity_id' => $activityId, 'deja_consignee' => false], 201);
        });
    }

    public function terminerTache(Request $request, int $personneId, int $activityId): JsonResponse
    {
        $id = $personneId;

        $workspaceId = $this->businessWorkspace($request);

        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $id, $activityId): JsonResponse {
            $this->personne($workspaceId, $id);

            $mises = DB::table('activities')
                ->where('workspace_id', $workspaceId)
                ->where('id', $activityId)
                ->where('subject_type', 'personne')
                ->where('subject_id', $id)
                ->where('kind', 'task')
                ->whereNull('done_at')
                ->update(['done_at' => now()]);

            if ($mises === 0) {
                abort(404, 'Tâche introuvable ou déjà terminée.');
            }

            return $this->ok(['activity_id' => $activityId, 'done' => true]);
        });
    }

    /**
     * « RATTACHER À UNE ENTREPRISE » — l'acte qui fait d'une personne un
     * contact du hub. Réutilise `ContactUpserter` : le même dédoublonnage que
     * l'ingestion et l'arbitrage, et le même rattachement automatique par
     * `person_key` (c'est lui qui remplit `personnes.contact_id`).
     *
     * Un NOM est exigé : `contacts.last_name` est NOT NULL, et on ne fabrique
     * jamais un patronyme depuis une adresse. S'il n'a pas été déclaré sur le
     * site, l'opérateur le saisit — il devient alors une valeur déclarée.
     */
    public function rattacher(Request $request, int $personneId): JsonResponse
    {
        $id = $personneId;

        $workspaceId = $this->businessWorkspace($request);

        $data = $request->validate([
            'company_id' => ['required', 'integer', 'min:1'],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
        ]);

        $userId = (string) $this->currentUser($request)->getKey();

        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $id, $data, $userId): JsonResponse {
            return DB::transaction(function () use ($workspaceId, $id, $data, $userId): JsonResponse {
                $personne = DB::table('personnes')
                    ->where('workspace_id', $workspaceId)
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->first();

                if ($personne === null) {
                    abort(404);
                }
                if ($personne->contact_id !== null) {
                    abort(409, 'Cette personne est déjà rattachée à une entreprise.');
                }

                $company = DB::table('companies')
                    ->where('workspace_id', $workspaceId)
                    ->where('id', (int) $data['company_id'])
                    ->whereNull('deleted_at')
                    ->first(['id']);
                if ($company === null) {
                    // 404 et non 403 : pas d'oracle d'existence inter-espaces.
                    abort(404, 'Entreprise introuvable dans cet univers.');
                }

                // Rattacher fait entrer la personne au hub et dans les
                // audiences, qui servent à la PROSPECTION : jamais une adresse
                // personnelle sans consentement (L.34-5 CPCE), ni une fiche sans
                // adresse connue.
                $statutLettre = DB::table('abonnements')
                    ->where('personne_id', $id)
                    ->where('canal', 'lettre')
                    ->value('statut');
                if (! Abonnements::prospectionAutorisee(
                    is_string($personne->email_nature ?? null) ? $personne->email_nature : null,
                    is_string($statutLettre) ? $statutLettre : null,
                )) {
                    abort(422, 'Rattachement refusé : adresse personnelle sans consentement, ou adresse inconnue. Aucune prospection n’est possible pour cette personne.');
                }

                $firstName = $this->texte($data['first_name'] ?? null) ?? $this->texte($personne->first_name);
                $lastName = $this->texte($data['last_name'] ?? null) ?? $this->texte($personne->last_name);
                if ($lastName === null) {
                    abort(422, 'Nom de famille requis pour créer la fiche contact : il n’a pas été déclaré sur le site.');
                }

                $abonnement = DB::table('abonnements')
                    ->where('personne_id', $id)
                    ->where('canal', 'lettre')
                    ->where('statut', 'abonne')
                    ->first(['consent_version', 'consent_at', 'consent_text_ref']);

                $upserted = $this->contacts->upsert(
                    workspaceId: $workspaceId,
                    companyId: (int) $company->id,
                    personKey: (string) $personne->person_key,
                    externalRef: null,
                    email: $this->texte($personne->email),
                    firstName: $firstName,
                    lastName: $lastName,
                    phone: null,
                    legalBasis: (string) $personne->legal_basis,
                    consentVersion: $abonnement === null ? null : $this->texte($abonnement->consent_version),
                    consentAt: $abonnement === null || $abonnement->consent_at === null ? null : new \DateTimeImmutable((string) $abonnement->consent_at),
                    consentTextRef: $abonnement === null ? null : $this->texte($abonnement->consent_text_ref),
                    discoverySource: 'site',
                );

                if ($upserted === null) {
                    throw new RuntimeException('ContactUpserter a refusé une fiche munie d’un nom : état incohérent.');
                }

                // Le rattachement automatique de `ContactUpserter` suit le
                // drapeau d'ingestion ; ce geste-ci est explicite, il lie
                // toujours (à l'entreprise EFFECTIVE du contact retrouvé).
                $this->contacts->lierPersonne(
                    $workspaceId,
                    (string) $personne->person_key,
                    $upserted[0],
                    (int) (DB::table('contacts')->where('id', $upserted[0])->whereNull('deleted_at')->value('company_id') ?? $company->id),
                );

                // Le nom saisi par l'opérateur devient la valeur déclarée de la
                // personne : les deux fiches disent désormais la même chose.
                DB::table('personnes')->where('id', $id)->update(array_filter([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'updated_at' => now(),
                ], static fn (mixed $v): bool => $v !== null));

                $relue = DB::table('personnes')->where('id', $id)->first(['contact_id', 'company_id']);

                DB::table('activities')->insert([
                    'workspace_id' => $workspaceId,
                    'user_id' => $userId,
                    'contact_id' => $upserted[0],
                    'type' => 'reclassified',
                    'kind' => 'reclassified',
                    'occurred_at' => now(),
                    'person_key' => $personne->person_key,
                    'subject_type' => 'personne',
                    'subject_id' => $id,
                    'title' => 'Rattachée à une entreprise',
                    'payload' => json_encode([
                        'surface' => 'console:personnes',
                        'company_id' => $relue?->company_id === null ? null : (int) $relue->company_id,
                        'contact_id' => $upserted[0],
                        'by' => $userId,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);

                return $this->ok([
                    'personne_id' => $id,
                    'contact_id' => $upserted[0],
                    // L'entreprise EFFECTIVE : si un contact de même clé existait
                    // déjà ailleurs, `ContactUpserter` l'a retrouvé — on le dit.
                    'company_id' => $relue?->company_id === null ? null : (int) $relue->company_id,
                    'contact_created' => $upserted[1] === 'created',
                ]);
            });
        });
    }

    /**
     * EXPORT CSV du segment filtré. Les coordonnées ne sortent en clair
     * qu'avec `contacts.view_pii` ; les personnes opposées ou à l'adresse
     * morte n'en sortent jamais (même règle que l'éligibilité).
     */
    public function export(Request $request): StreamedResponse
    {
        $workspaceId = $this->businessWorkspace($request);
        $filtres = $this->filtres($request);
        $masquer = MasquageCoordonnees::requis();
        // Par défaut, l'export ne sort QUE les personnes qu'on peut prospecter
        // (`Abonnements::prospectionAutorisee`) : c'est le fichier type qu'on
        // réimporte un jour dans un outil d'envoi. Les autres ne sortent que
        // sur demande explicite, et la colonne le dit ligne par ligne.
        $choix = $request->validate([
            'inclure_non_prospectables' => ['nullable', 'string', 'in:oui,non'],
        ]);
        $tous = ($choix['inclure_non_prospectables'] ?? null) === 'oui';

        $entete = ['Adresse', 'Prénom', 'Nom', 'Nature', 'Source', 'Statut lettre', 'Base légale (lettre)', 'Consentement (version)', 'Consentement le', 'Prospection autorisée', 'Rattachée', 'SIREN', 'Dernière interaction'];

        // La requête est construite ICI, sous le contexte d'espace, et lue dans
        // le flux ; le contexte est reposé autour de la lecture.
        return response()->streamDownload(function () use ($workspaceId, $filtres, $masquer, $entete, $tous): void {
            WorkspaceContext::run($workspaceId, function () use ($workspaceId, $filtres, $masquer, $entete, $tous): void {
                $out = fopen('php://output', 'w');
                if ($out === false) {
                    throw new RuntimeException("Export CSV : impossible d'ouvrir php://output.");
                }
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, $entete);

                $requete = Abonnements::exclureOpposees($this->requete($workspaceId, $filtres));
                if (! $tous) {
                    $requete = Abonnements::limiterAuxProspectables($requete);
                }

                // Même plafond que tous les exports du dépôt (G41-007), même
                // ligne témoin lue en plus pour distinguer « complet » de
                // « coupé ». `PlafondExport::parcourirBorne()` n'accepte qu'un
                // builder Eloquent ; `personnes` n'a pas de modèle — le
                // parcours est donc écrit ici, à l'identique.
                $plafond = PlafondExport::lignes();
                $lues = 0;
                $tronque = false;
                $ecrire = function (\stdClass $p) use ($out, $masquer): void {
                    // Chaque cellule est NEUTRALISÉE : prénom, nom, source et
                    // version viennent d'un formulaire public (injection de
                    // formule à l'ouverture dans un tableur).
                    fputcsv($out, CelluleCsv::ligne([
                        $masquer ? MasquageCoordonnees::email($p->email) : $p->email,
                        $p->first_name,
                        $p->last_name,
                        $p->email_nature,
                        $p->premiere_source,
                        $p->statut_lettre ?? 'aucun',
                        $p->base_lettre,
                        $p->consent_version,
                        $p->consent_at,
                        Abonnements::prospectionAutorisee(
                            is_string($p->email_nature) ? $p->email_nature : null,
                            is_string($p->statut_lettre) ? $p->statut_lettre : null,
                        ) ? 'oui' : 'non',
                        $p->contact_id === null ? 'non' : 'oui',
                        $p->siren,
                        $p->derniere_interaction_at,
                    ]));
                };
                $requete->chunkById(min(1000, $plafond + 1), function ($lot) use (&$lues, &$tronque, $plafond, $ecrire): bool {
                    foreach ($lot as $ligne) {
                        $lues++;
                        if ($lues > $plafond) {
                            $tronque = true;

                            return false;
                        }
                        $ecrire($ligne);
                    }

                    return true;
                }, 'personnes.id', 'id');
                if ($tronque) {
                    PlafondExport::ecrireAvertissement($out, count($entete));
                }
                fclose($out);
            });
        }, 'personnes-lettre-guide-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8'] + PlafondExport::entetes());
    }

    // ── Internes ────────────────────────────────────────────────────────────

    /**
     * @return array{source: ?string, statut_lettre: ?string, nature: ?string, rattachee: ?string, q: ?string}
     */
    private function filtres(Request $request): array
    {
        $v = $request->validate([
            'source' => ['nullable', 'string', 'max:80'],
            'statut_lettre' => ['nullable', 'string', 'in:abonne,desabonne,aucun'],
            'nature' => ['nullable', 'string', 'in:' . implode(',', Taxonomy::PERSONNE_EMAIL_NATURES)],
            'rattachee' => ['nullable', 'string', 'in:oui,non'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        return [
            'source' => $this->texte($v['source'] ?? null),
            'statut_lettre' => $this->texte($v['statut_lettre'] ?? null),
            'nature' => $this->texte($v['nature'] ?? null),
            'rattachee' => $this->texte($v['rattachee'] ?? null),
            'q' => $this->texte($v['q'] ?? null),
        ];
    }

    /**
     * @param  array{source: ?string, statut_lettre: ?string, nature: ?string, rattachee: ?string, q: ?string}  $f
     */
    private function requete(string $workspaceId, array $f): Builder
    {
        $requete = DB::table('personnes')
            ->leftJoin('abonnements', function ($join): void {
                $join->on('abonnements.personne_id', '=', 'personnes.id')
                    ->where('abonnements.canal', '=', 'lettre');
            })
            ->leftJoin('companies', 'companies.id', '=', 'personnes.company_id')
            ->where('personnes.workspace_id', $workspaceId)
            ->select([
                'personnes.id',
                'personnes.person_key',
                'personnes.email',
                'personnes.first_name',
                'personnes.last_name',
                'personnes.email_nature',
                'personnes.locale',
                'personnes.premiere_source',
                'personnes.premiere_source_at',
                'personnes.derniere_interaction_at',
                'personnes.legal_basis',
                'personnes.contact_id',
                'personnes.company_id',
                'personnes.rattachee_at',
                'abonnements.statut AS statut_lettre',
                'abonnements.legal_basis AS base_lettre',
                'abonnements.consent_version',
                'abonnements.consent_at',
                'abonnements.source_slug AS placement',
                'companies.denomination',
                'companies.siren',
            ]);

        if ($f['source'] !== null) {
            $requete->where('personnes.premiere_source', $f['source']);
        }
        if ($f['statut_lettre'] === 'aucun') {
            $requete->whereNull('abonnements.id');
        } elseif ($f['statut_lettre'] !== null) {
            $requete->where('abonnements.statut', $f['statut_lettre']);
        }
        if ($f['nature'] !== null) {
            $requete->where('personnes.email_nature', $f['nature']);
        }
        if ($f['rattachee'] === 'oui') {
            $requete->whereNotNull('personnes.contact_id');
        } elseif ($f['rattachee'] === 'non') {
            $requete->whereNull('personnes.contact_id');
        }
        if ($f['q'] !== null) {
            // Les jokers `%` et `_` sont NEUTRALISÉS : sans cela, un compte qui
            // voit l'adresse masquée la reconstituerait lettre par lettre
            // (`q=a%`, `q=ab%`…). Et sans `contacts.view_pii`, on ne cherche
            // que dans les NOMS : chercher dans l'adresse serait le même oracle.
            $terme = addcslashes($f['q'], '%_\\');
            $dansAdresse = ! MasquageCoordonnees::requis();
            $requete->where(function (Builder $q) use ($terme, $dansAdresse): void {
                $q->where('personnes.last_name', 'ilike', $terme . '%')
                    ->orWhere('personnes.first_name', 'ilike', $terme . '%');
                if ($dansAdresse) {
                    $q->orWhere('personnes.email', 'ilike', $terme . '%');
                }
            });
        }

        return $requete;
    }

    /** @return array<string, mixed> */
    private function presenter(\stdClass $p): array
    {
        return [
            'id' => (int) $p->id,
            'person_key' => $p->person_key,
            'email' => $p->email,
            'first_name' => $p->first_name,
            'last_name' => $p->last_name,
            'email_nature' => $p->email_nature,
            'locale' => $p->locale ?? null,
            'premiere_source' => $p->premiere_source,
            'premiere_source_at' => $p->premiere_source_at,
            'derniere_interaction_at' => $p->derniere_interaction_at,
            'legal_basis' => $p->legal_basis,
            'statut_lettre' => $p->statut_lettre ?? null,
            'base_lettre' => $p->base_lettre ?? null,
            // LA règle (`Abonnements::prospectionAutorisee`), calculée ici pour
            // que la console n'en écrive pas une seconde.
            'prospection_autorisee' => Abonnements::prospectionAutorisee(
                is_string($p->email_nature ?? null) ? $p->email_nature : null,
                is_string($p->statut_lettre ?? null) ? $p->statut_lettre : null,
            ),
            'placement' => $p->placement ?? null,
            'rattachee' => $p->contact_id !== null,
            'contact_id' => $p->contact_id === null ? null : (int) $p->contact_id,
            'company_id' => $p->company_id === null ? null : (int) $p->company_id,
            'entreprise' => ($p->denomination ?? null) ?? ($p->siren ?? null),
            // Horloge de conservation AFFICHÉE : 3 ans sans interaction pour une
            // personne non rattachée et non abonnée (purge `rgpd:purge-personnes`).
            'purge_prevue_le' => $this->purgePrevue($p),
        ];
    }

    private function purgePrevue(\stdClass $p): ?string
    {
        if ($p->contact_id !== null || ($p->statut_lettre ?? null) === 'abonne') {
            return null;
        }

        $base = $p->derniere_interaction_at ?? $p->premiere_source_at ?? null;
        if (! is_string($base) || $base === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($base))->modify('+3 years')->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }

    private function personne(string $workspaceId, int $id): \stdClass
    {
        $personne = $this->requete($workspaceId, ['source' => null, 'statut_lettre' => null, 'nature' => null, 'rattachee' => null, 'q' => null])
            ->where('personnes.id', $id)
            ->first();

        if ($personne === null) {
            abort(404);
        }

        return $personne;
    }

    private function texte(mixed $valeur): ?string
    {
        return is_string($valeur) && trim($valeur) !== '' ? trim($valeur) : null;
    }
}
