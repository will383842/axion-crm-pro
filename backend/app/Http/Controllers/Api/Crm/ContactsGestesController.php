<?php

namespace App\Http\Controllers\Api\Crm;

use App\Crm\Contacts\AjoutContact;
use App\Crm\Contacts\GestesContact;
use App\Crm\Contacts\ImportContacts;
use App\Crm\Opposition\StatutOpposition;
use App\Http\Controllers\Concerns\VerrouOptimiste;
use App\Models\Contact;
use App\Support\AuditLogger;
use App\Support\CelluleCsv;
use App\Support\MasquageCoordonnees;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Gestes « Contacts » de la console (G1-A : modifier ; G1-B : retirer, restaurer, corbeille douce ; G1-C : ajout rapide ; G1-D : import ; G1-E : appel, rappel, export). */
class ContactsGestesController extends ConsoleController
{
    use VerrouOptimiste;

    public function modifier(Request $request, int|string $contactId): JsonResponse
    {
        // Défense en profondeur : la route porte déjà `permission:companies.update`.
        Gate::forUser($this->currentUser($request))->authorize('modifier', Contact::class);

        $workspaceId = $this->businessWorkspace($request);

        $champs = $request->validate([
            'first_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'last_name' => ['sometimes', 'string', 'min:1', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'fonction' => ['sometimes', 'nullable', 'string', 'max:200'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'lifecycle_stage' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);
        if ($champs === []) {
            return response()->json(['error' => 'aucun_champ', 'message' => 'Aucun champ à modifier.'], 422);
        }

        // Verrou optimiste (G43-005) : `If-Match` ou `updated_at` périmé → 409 ; sans l'un ni l'autre, comme ailleurs, on passe.
        $actuel = WorkspaceContext::run($workspaceId, fn () => Contact::query()->where('workspace_id', $workspaceId)->find((int) $contactId));
        if ($actuel !== null) {
            $this->refuserSiVersionPerimee($request, $actuel);
        }

        $r = WorkspaceContext::run($workspaceId, fn () => (new GestesContact)->modifier($workspaceId, (int) $contactId, $champs));

        if ($r['etat'] === 'ok') {
            return response()->json(['data' => $this->projeter($r['contact'], $r['opposition'])] + ['opposition' => $r['opposition']]);
        }

        return match ($r['etat']) {
            'doublon' => response()->json(['error' => 'doublon', 'message' => 'Une autre fiche porte déjà ce nom dans cette entreprise (peut-être dans la corbeille).'], 409),
            'champ_non_modifiable' => response()->json(['error' => 'champ_non_modifiable', 'message' => "L'étape se change sur l'entreprise."], 422),
            default => response()->json(['error' => 'introuvable', 'message' => 'Contact introuvable.'], 404),
        };
    }

    /** Doublons probables pendant la saisie (lecture seule, adresses masquées si requis). */
    public function doublons(Request $request): JsonResponse
    {
        $workspaceId = $this->businessWorkspace($request);
        $q = $request->validate([
            'email' => ['sometimes', 'nullable', 'string', 'max:254'],
            'prenom' => ['sometimes', 'nullable', 'string', 'max:120'],
            'nom' => ['sometimes', 'nullable', 'string', 'max:120'],
            'organisation' => ['sometimes', 'nullable', 'string', 'max:200'],
            'company_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $data = WorkspaceContext::run($workspaceId, function () use ($workspaceId, $q): array {
            $service = new AjoutContact;
            $e = $service->entreprise($workspaceId, isset($q['company_id']) ? (int) $q['company_id'] : null, $q['organisation'] ?? null);

            return $service->doublons($workspaceId, $q['email'] ?? null, $q['prenom'] ?? null, $q['nom'] ?? null, is_array($e) ? $e['id'] : null);
        });

        return response()->json(['data' => $data]);
    }

    /** Ajout rapide : la provenance est obligatoire ; jamais d'entreprise créée ; une personne opposée n'est pas ajoutée. */
    public function ajouter(Request $request): JsonResponse
    {
        Gate::forUser($this->currentUser($request))->authorize('creer', Contact::class);
        $workspaceId = $this->businessWorkspace($request);

        $d = $request->validate([
            'first_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'min:1', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'organisation' => ['sometimes', 'nullable', 'string', 'max:200'],
            'company_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'fonction' => ['sometimes', 'nullable', 'string', 'max:200'],
            'provenance' => ['required', 'string', Rule::in(AjoutContact::PROVENANCES)],
            'doublon' => ['sometimes', 'nullable', Rule::in(['fusionner', 'garder'])],
            'fusionner_avec' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        $userId = (string) $this->currentUser($request)->getKey();

        $r = WorkspaceContext::run($workspaceId, fn () => (new AjoutContact)->ajouter($workspaceId, $d, $userId));

        return match ($r['etat']) {
            'cree' => response()->json(['resultat' => 'cree', 'fiche' => $r['fiche']], 201),
            'fusionne' => response()->json(['resultat' => 'fusionne', 'fiche' => $r['fiche']]),
            'doublons' => response()->json(['error' => 'doublons', 'message' => 'Une fiche proche existe déjà.', 'doublons' => $r['doublons']], 409),
            'doublon' => response()->json(['error' => 'doublon', 'message' => 'Une fiche porte déjà ce nom dans cette entreprise (peut-être dans la corbeille).'], 409),
            'organisation_ambigue' => response()->json(['error' => 'organisation_ambigue', 'message' => 'Plusieurs entreprises portent ce nom : choisissez-la dans la recherche.'], 422),
            'organisation_introuvable' => response()->json(['error' => 'organisation_introuvable', 'message' => 'Entreprise introuvable : choisissez une entreprise existante.'], 422),
            'opposition' => response()->json(['error' => 'opposition', 'message' => 'Cette adresse a demandé à ne plus être contactée.'], 422),
            'fusion_invalide' => response()->json(['error' => 'fusion_invalide', 'message' => 'Indiquez la fiche à compléter.'], 422),
            default => response()->json(['error' => 'introuvable', 'message' => 'Contact introuvable.'], 404),
        };
    }

    /** Contrôle d'un fichier avant import : AUCUNE écriture ; numéros de ligne et codes seulement. */
    public function importControle(Request $request): JsonResponse
    {
        Gate::forUser($this->currentUser($request))->authorize('importer', Contact::class);
        $workspaceId = $this->businessWorkspace($request);
        $lignes = $this->lignesImport($request);

        return response()->json(WorkspaceContext::run($workspaceId, fn () => (new ImportContacts)->controle($workspaceId, $lignes)));
    }

    /** Import synchrone (1 000 lignes au plus) : rapport gardé 1 h, lisible par le seul lanceur. */
    public function importer(Request $request): JsonResponse
    {
        Gate::forUser($this->currentUser($request))->authorize('importer', Contact::class);
        $workspaceId = $this->businessWorkspace($request);
        $lignes = $this->lignesImport($request);
        $userId = (string) $this->currentUser($request)->getKey();

        $travail = WorkspaceContext::run($workspaceId, function () use ($workspaceId, $lignes, $userId): array {
            $service = new ImportContacts;

            return $service->memoriser($workspaceId, $userId, $service->importer($workspaceId, $lignes, $userId));
        });

        return response()->json($travail);
    }

    /** Relit le rapport d'un import (même utilisateur, même espace) ; sinon 404. */
    public function importSuivi(Request $request, string $id): JsonResponse
    {
        Gate::forUser($this->currentUser($request))->authorize('importer', Contact::class);
        $workspaceId = $this->businessWorkspace($request);
        $t = (new ImportContacts)->suivi($workspaceId, (string) $this->currentUser($request)->getKey(), $id);

        return $t === null ? response()->json(['error' => 'introuvable', 'message' => 'Import introuvable.'], 404) : response()->json($t);
    }

    /** @return list<array<string, mixed>> */
    private function lignesImport(Request $request): array
    {
        $max = ImportContacts::MAX_CHAMP;
        $d = $request->validate([
            'lignes' => ['required', 'array', 'min:1', 'max:' . ImportContacts::MAX_LIGNES],
            'lignes.*' => ['array'],
            'lignes.*.ligne' => ['required', 'integer', 'min:0'],
            'lignes.*.prenom' => ['nullable', 'string', 'max:' . $max],
            'lignes.*.nom' => ['nullable', 'string', 'max:' . $max],
            'lignes.*.email' => ['nullable', 'string', 'max:' . $max],
            'lignes.*.organisation' => ['nullable', 'string', 'max:' . $max],
            'lignes.*.fonction' => ['nullable', 'string', 'max:' . $max],
            'lignes.*.provenance' => ['nullable', 'string', 'max:' . $max],
        ]);

        return array_values($d['lignes']);
    }

    /**
     * G1-E — le contact actif d'une personne, pour un geste (appel, rappel). Échec FERMÉ : introuvable, mis à l'écart,
     * retiré, opposé ou verdict d'opposition impossible → `null` (l'appelant rend 404, jamais d'oracle).
     */
    private function contactPourGeste(string $workspaceId, string $personKey): ?\stdClass
    {
        if (preg_match('/^[0-9a-f]{64}$/', $personKey) !== 1) {
            return null;
        }
        $ecart = DB::selectOne("SELECT 1 AS x FROM contacts WHERE workspace_id = ? AND person_key = ? AND metadata ?? 'mis_a_l_ecart_le' LIMIT 1", [$workspaceId, $personKey]);
        if ($ecart !== null) {
            return null;
        }
        $c = DB::table('contacts')->where('workspace_id', $workspaceId)->where('person_key', $personKey)->whereNull('deleted_at')
            ->orderByDesc('id')->first(['id', 'person_key', 'email', 'company_id']);
        if ($c === null) {
            return null;
        }
        try {
            $siren = DB::table('companies')->where('workspace_id', $workspaceId)->where('id', $c->company_id)->value('siren');
            $v = (new StatutOpposition)->pourLignes($workspaceId, [
                $c->id => ['email' => is_string($c->email) ? $c->email : null, 'siren' => is_string($siren) ? $siren : null],
            ]);
        } catch (\Throwable) {
            return null;
        }

        return ($v[$c->id]['opposee'] ?? true) === true ? null : $c;
    }

    /**
     * Écrit une activité ; `insertOrIgnore` (anti double clic). Rend [id, créée ?].
     *
     * @param  array<string, mixed>  $ligne
     * @return array{int, bool}
     */
    private function consigner(string $workspaceId, string $ref, array $ligne): array
    {
        $cree = DB::table('activities')->insertOrIgnore($ligne + [
            'workspace_id' => $workspaceId, 'external_ref' => $ref, 'occurred_at' => now(), 'created_at' => now(),
        ]);

        return [(int) DB::table('activities')->where('workspace_id', $workspaceId)->where('external_ref', $ref)->value('id'), $cree > 0];
    }

    /** Consigner un appel (compte rendu) sur la fiche d'une personne. */
    public function appel(Request $request, string $personKey): JsonResponse
    {
        Gate::forUser($this->currentUser($request))->authorize('modifier', Contact::class);
        $workspaceId = $this->businessWorkspace($request);
        $d = $request->validate(['compte_rendu' => ['required', 'string', 'min:1', 'max:5000']]);
        $userId = $this->currentUser($request)->getKey();

        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $personKey, $d, $userId): JsonResponse {
            $c = $this->contactPourGeste($workspaceId, $personKey);
            if ($c === null) {
                return response()->json(['error' => 'introuvable', 'message' => 'Personne introuvable.'], 404);
            }
            [$id, $cree] = $this->consigner($workspaceId, 'console:contact:' . $c->id . ':call:' . now()->format('Y-m-d\TH:i') . ':' . substr(sha1((string) $d['compte_rendu']), 0, 12), [
                'user_id' => $userId, 'contact_id' => $c->id, 'person_key' => $c->person_key, 'type' => 'call', 'kind' => 'call',
                'subject_type' => 'contact', 'subject_id' => $c->id, 'title' => 'Appel', 'content' => (string) $d['compte_rendu'],
                'payload' => json_encode(['surface' => 'fiche:appel'], JSON_THROW_ON_ERROR),
            ]);

            return response()->json(['activity_id' => $id, 'deja_consignee' => ! $cree], $cree ? 201 : 200);
        });
    }

    /** Planifier un rappel : une tâche (`kind='task'`) rattachée au contact, visible dans « Mes tâches ». */
    public function rappel(Request $request, string $personKey): JsonResponse
    {
        Gate::forUser($this->currentUser($request))->authorize('modifier', Contact::class);
        $workspaceId = $this->businessWorkspace($request);
        $d = $request->validate(['le' => ['required', 'date_format:Y-m-d'], 'note' => ['nullable', 'string', 'max:200']]);
        $userId = $this->currentUser($request)->getKey();
        $note = trim((string) ($d['note'] ?? ''));
        // 09 h à Paris le jour demandé (jamais minuit : le jour reste le bon après conversion en UTC).
        $echeance = Carbon::parse($d['le'] . ' 09:00', 'Europe/Paris')->utc();

        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $personKey, $d, $userId, $note, $echeance): JsonResponse {
            $c = $this->contactPourGeste($workspaceId, $personKey);
            if ($c === null) {
                return response()->json(['error' => 'introuvable', 'message' => 'Personne introuvable.'], 404);
            }
            [$id, $cree] = $this->consigner($workspaceId, 'console:contact:' . $c->id . ':rappel:' . now()->format('Y-m-d\TH:i') . ':' . substr(sha1($d['le'] . '|' . $note), 0, 12), [
                'user_id' => $userId, 'contact_id' => $c->id, 'person_key' => $c->person_key, 'type' => 'task', 'kind' => 'task',
                'subject_type' => 'contact', 'subject_id' => $c->id, 'title' => $note !== '' ? $note : 'Rappel', 'due_at' => $echeance,
                'payload' => json_encode(['surface' => 'fiche:rappel', 'origine' => 'manuelle', 'genre' => 'relance', 'avec_heure' => false], JSON_THROW_ON_ERROR),
            ]);

            return response()->json(['activity_id' => $id, 'deja_consignee' => ! $cree], $cree ? 201 : 200);
        });
    }

    /**
     * Export CSV de contacts choisis. Tout identifiant hors espace, retiré ou inconnu → 422 (jamais d'export partiel
     * silencieux). Les personnes opposées ne sortent pas (échec fermé) : leur nombre est rendu en `X-Lignes-Exclues`.
     */
    public function exporter(Request $request): Response
    {
        Gate::forUser($this->currentUser($request))->authorize('exporter', Contact::class);
        $workspaceId = $this->businessWorkspace($request);
        $d = $request->validate([
            'company_ids' => ['sometimes', 'array', 'max:500'], 'company_ids.*' => ['integer', 'min:1'],
            'contact_ids' => ['sometimes', 'array', 'max:500'], 'contact_ids.*' => ['integer', 'min:1'],
        ]);
        $entreprises = array_values(array_unique(array_map('intval', $d['company_ids'] ?? [])));
        $contacts = array_values(array_unique(array_map('intval', $d['contact_ids'] ?? [])));
        if ($entreprises === [] && $contacts === []) {
            return response()->json(['error' => 'aucun_identifiant', 'message' => 'Aucune fiche choisie.'], 422);
        }
        if (count($entreprises) + count($contacts) > 500) {
            return response()->json(['error' => 'trop_d_identifiants', 'message' => '500 fiches au plus.'], 422);
        }

        $rendu = WorkspaceContext::run($workspaceId, function () use ($workspaceId, $entreprises, $contacts): array|JsonResponse {
            $valides = $entreprises === [] ? 0 : DB::table('companies')->where('workspace_id', $workspaceId)->whereNull('deleted_at')->whereIn('id', $entreprises)->count();
            $valides2 = $contacts === [] ? 0 : DB::table('contacts')->where('workspace_id', $workspaceId)->whereNull('deleted_at')->whereIn('id', $contacts)->count();
            if ($valides !== count($entreprises) || $valides2 !== count($contacts)) {
                return response()->json(['error' => 'identifiant_invalide', 'message' => 'Une fiche choisie est introuvable : rien n’est exporté.'], 422);
            }
            $lignes = DB::table('contacts as c')
                ->join('companies as e', fn ($j) => $j->on('e.id', '=', 'c.company_id')->on('e.workspace_id', '=', 'c.workspace_id'))
                ->where('c.workspace_id', $workspaceId)->whereNull('c.deleted_at')->whereNull('e.deleted_at')
                ->where(fn ($q) => $q->whereIn('c.id', $contacts ?: [0])->orWhereIn('c.company_id', $entreprises ?: [0]))
                ->orderBy('c.id')->limit(10001)
                ->get(['c.id', 'c.first_name', 'c.last_name', 'c.email', 'c.role', 'e.denomination', 'e.siren']);
            if ($lignes->count() > 10000) {
                return response()->json(['error' => 'trop_de_lignes', 'message' => 'Choisissez moins de fiches.'], 422);
            }
            $verdicts = (new StatutOpposition)->pourLignes($workspaceId, $lignes->mapWithKeys(fn ($l) => [
                $l->id => ['email' => is_string($l->email) ? $l->email : null, 'siren' => is_string($l->siren) ? $l->siren : null],
            ])->all());
            $sortie = [];
            foreach ($lignes as $l) {
                if (($verdicts[$l->id]['opposee'] ?? true) === true) {
                    continue;
                }
                $sortie[] = [$l->first_name, $l->last_name, $l->email, $l->role, $l->denomination];
            }
            AuditLogger::log('contacts.export', ['workspace_id' => $workspaceId, 'resource_type' => 'contact', 'lignes' => count($sortie)]);

            return ['lignes' => $sortie, 'exclues' => $lignes->count() - count($sortie)];
        });
        if ($rendu instanceof JsonResponse) {
            return $rendu;
        }

        return response()->streamDownload(function () use ($rendu): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                throw new \RuntimeException('Export CSV : impossible d’ouvrir php://output.');
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['prenom', 'nom', 'email', 'fonction', 'organisation'], ';');
            foreach ($rendu['lignes'] as $ligne) {
                fputcsv($out, CelluleCsv::ligne($ligne), ';');
            }
            fclose($out);
        }, 'contacts.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Lignes-Exclues' => (string) $rendu['exclues']]);
    }

    /** Retirer = corbeille douce, restaurable. Idempotent. Rien n'est effacé. */
    public function retirer(Request $request, int|string $contactId): JsonResponse
    {
        Gate::forUser($this->currentUser($request))->authorize('retirer', Contact::class);
        $workspaceId = $this->businessWorkspace($request);
        $userId = (string) $this->currentUser($request)->getKey();

        $r = WorkspaceContext::run($workspaceId, fn () => (new GestesContact)->retirer($workspaceId, (int) $contactId, $userId));

        return $r['etat'] === 'ok'
            ? response()->json(['retire' => true])
            : response()->json(['error' => 'introuvable', 'message' => 'Contact introuvable.'], 404);
    }

    /** Restaurer un contact retiré par la console (jamais une mise à l'écart RGPD). */
    public function restaurer(Request $request, int|string $contactId): JsonResponse
    {
        Gate::forUser($this->currentUser($request))->authorize('retirer', Contact::class);
        $workspaceId = $this->businessWorkspace($request);

        $r = WorkspaceContext::run($workspaceId, fn () => (new GestesContact)->restaurer($workspaceId, (int) $contactId));

        return match ($r['etat']) {
            'ok' => response()->json(['restaure' => true]),
            'mis_a_l_ecart' => response()->json(['error' => 'mis_a_l_ecart', 'message' => 'Cette personne a été mise à l’écart : la fiche ne peut pas être restaurée ici.'], 409),
            'entreprise_corbeille' => response()->json(['error' => 'entreprise_corbeille', 'message' => 'Restaurer d’abord l’entreprise.'], 409),
            default => response()->json(['error' => 'introuvable', 'message' => 'Contact introuvable.'], 404),
        };
    }

    /** La corbeille de la console. */
    public function corbeille(Request $request): JsonResponse
    {
        $workspaceId = $this->businessWorkspace($request);

        return response()->json(['data' => WorkspaceContext::run($workspaceId, fn () => (new GestesContact)->corbeille($workspaceId))]);
    }

    /**
     * Même projection explicite que `ContactsController::present()` (adresse et téléphone masqués si requis).
     *
     * @param  array{opposee: bool, motifs: list<string>}  $opposition
     * @return array<string, mixed>
     */
    private function projeter(Contact $c, array $opposition): array
    {
        $masquer = MasquageCoordonnees::requis();

        return [
            'opposition' => $opposition,
            'id' => $c->id,
            'first_name' => $c->first_name,
            'last_name' => $c->last_name,
            'role' => $c->role,
            'email' => $masquer ? MasquageCoordonnees::email($c->email) : $c->email,
            'email_status' => $c->email_status,
            'joignabilite' => $c->getAttribute('joignabilite'),
            'email_score' => $c->email_score,
            'phone' => $masquer ? MasquageCoordonnees::telephone($c->phone) : $c->phone,
            'linkedin_url' => $c->linkedin_url,
            'discovery_source' => $c->discovery_source,
            'company_id' => $c->company_id,
            'company' => $c->company === null ? null : ['id' => $c->company->id, 'denomination' => $c->company->denomination],
        ];
    }
}
