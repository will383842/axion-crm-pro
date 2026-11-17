<?php

namespace App\Http\Controllers\Api\Crm;

use App\Crm\Console\ConsoleAccess;
use App\Crm\Opposition\StatutOpposition;
use App\Support\MasquageCoordonnees;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FICHE 360° — la timeline unifiée d'une personne (plan §2.6, conception §3b).
 *
 * ── Ce que cet endpoint révèle, et ce qu'il ne révèle PAS ──────────────────
 *
 * `person_key` est la seule clé qui traverse les deux univers (plan §2.4.3).
 * Elle permet donc de savoir qu'une même personne a une fiche des deux côtés —
 * et c'est exactement ce que le plan autorise : un BOOLÉEN, jamais un contenu.
 *
 * Concrètement, pour un utilisateur qui n'a pas accès au vivier :
 *   - `universes.vivier.exists` peut valoir `true` (l'indicateur « existe aussi
 *     dans le Vivier » de la conception §2.4 et §3b) ;
 *   - `universes.vivier.accessible` vaut `false` ;
 *   - et AUCUNE activité, AUCUN nom, AUCUNE étape du vivier n'est renvoyée.
 *
 * Ce n'est pas une demi-mesure : sans cet indicateur, un opérateur business
 * créerait une seconde fiche pour quelqu'un qui en a déjà une, et l'étanchéité
 * produirait des doublons au lieu de protéger. Avec, il sait qu'il doit changer
 * d'univers — ou demander l'accès.
 */
class PersonTimelineController extends ConsoleController
{
    private const MAX_ACTIVITIES = 200;

    public function show(Request $request, string $personKey): JsonResponse
    {
        // La clé est un sha256 hexadécimal calculé côté site : tout le reste est
        // une tentative de sondage, pas une faute de frappe.
        if (preg_match('/^[0-9a-f]{64}$/', $personKey) !== 1) {
            abort(404);
        }

        $user = $this->currentUser($request);

        $business = ConsoleAccess::businessWorkspaceId($user);
        $vivier = ConsoleAccess::vivierWorkspaceId();
        $vivierAccessible = $vivier !== null && ConsoleAccess::isMemberOf($user, $vivier);

        if ($business === null && ! $vivierAccessible) {
            abort(403, 'Aucun univers accessible.');
        }

        $activities = [];
        $subjects = [];

        if ($business !== null) {
            $activities = array_merge($activities, $this->activitiesFor($business, $personKey, 'business'));
            $subjects = array_merge($subjects, $this->businessSubjects($business, $personKey));
            $subjects = array_merge($subjects, $this->personneSubjects($business, $personKey));
        }

        if ($vivier !== null && $vivierAccessible) {
            $activities = array_merge($activities, $this->activitiesFor($vivier, $personKey, 'vivier'));
            $subjects = array_merge($subjects, $this->vivierSubjects($vivier, $personKey));
        }

        // Tri chronologique inverse SUR L'ENSEMBLE : une timeline « 360° » qui
        // affiche un univers puis l'autre n'est pas une timeline, c'est deux
        // listes accolées.
        usort($activities, static function (array $a, array $b): int {
            return self::instantDe($b) <=> self::instantDe($a);
        });

        $fiche = $business === null ? null : $this->fiche($business, $personKey);

        return $this->ok(($fiche === null ? [] : ['fiche' => $fiche]) + [
            'person_key' => $personKey,
            'universes' => [
                'business' => [
                    'accessible' => $business !== null,
                    'exists' => $business !== null && $this->existsInBusiness($business, $personKey),
                ],
                'vivier' => [
                    'accessible' => $vivierAccessible,
                    // Indicateur SANS contenu (plan §2.4.3) : la question posée
                    // à la base est un `EXISTS`, et la réponse un booléen.
                    'exists' => $vivier !== null && $this->existsInVivier($vivier, $personKey),
                ],
            ],
            // 🔴 SITE JUMEAU de B12-002 / F36-006, et le plus ironique : la
            // « fiche 360° » est LA fiche detaillee nominative du chantier CRM
            // cible. Elle rendait `email` et `phone` en clair des DEUX univers.
            // Le masquage porte sur `subjects` ET sur `data` : une activite
            // peut porter la coordonnee dans sa charge utile, et masquer le
            // seul bloc affiche laisserait la valeur ailleurs dans le JSON.
            'subjects' => MasquageCoordonnees::masquerTableauSiRequis($subjects),
            'data' => MasquageCoordonnees::masquerTableauSiRequis(
                array_slice($activities, 0, self::MAX_ACTIVITIES),
            ),
        ]);
    }

    /**
     * G1-E — la fiche d'action de la personne (contact business), y compris RETIRÉ par la console (pour le restaurer).
     * Absente si la personne a été mise à l'écart (RGPD) ou n'a aucun contact utilisable : aucun geste proposé.
     * Aucune table `envoi_*` n'est lue (`envois` reste vide, à brancher par un lot Envoi).
     *
     * @return array<string, mixed>|null
     */
    private function fiche(string $workspaceId, string $personKey): ?array
    {
        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $personKey): ?array {
            $ecart = DB::selectOne("SELECT 1 AS x FROM contacts WHERE workspace_id = ? AND person_key = ? AND metadata ?? 'mis_a_l_ecart_le' LIMIT 1", [$workspaceId, $personKey]);
            if ($ecart !== null) {
                return null;
            }
            $c = DB::selectOne(
                "SELECT c.id, c.first_name, c.last_name, c.email::text AS email, c.role, c.discovery_source, c.deleted_at,
                        e.denomination, e.siren, e.lifecycle_stage
                 FROM contacts c LEFT JOIN companies e ON e.id = c.company_id AND e.workspace_id = c.workspace_id
                 WHERE c.workspace_id = ? AND c.person_key = ? AND (c.deleted_at IS NULL OR c.metadata ?? 'retire_console_le')
                 ORDER BY (c.deleted_at IS NOT NULL), c.id DESC LIMIT 1",
                [$workspaceId, $personKey],
            );
            if ($c === null) {
                return null;
            }
            $courriel = is_string($c->email) ? $c->email : null;
            try {
                $v = (new StatutOpposition)->pourLignes($workspaceId, [$c->id => ['email' => $courriel, 'siren' => is_string($c->siren) ? $c->siren : null]]);
                $oppose = ($v[$c->id]['opposee'] ?? true) === true; // sans verdict : échec fermé
            } catch (\Throwable) {
                $oppose = true;
            }
            $rappel = DB::selectOne(
                "SELECT to_char(due_at AT TIME ZONE 'Europe/Paris', 'YYYY-MM-DD') AS le, title FROM activities
                 WHERE workspace_id = ? AND kind = 'task' AND done_at IS NULL AND subject_type = 'contact' AND subject_id = ?
                   AND payload ->> 'surface' = 'fiche:rappel' AND due_at IS NOT NULL ORDER BY due_at, id LIMIT 1",
                [$workspaceId, $c->id],
            );
            $retire = DB::selectOne("SELECT to_char(deleted_at AT TIME ZONE 'Europe/Paris', 'YYYY-MM-DD\"T\"HH24:MI:SS') AS le FROM contacts WHERE workspace_id = ? AND id = ? AND deleted_at IS NOT NULL", [$workspaceId, $c->id]);

            return [
                'contact_id' => (int) $c->id,
                'first_name' => (string) ($c->first_name ?? ''),
                'last_name' => (string) $c->last_name,
                'email' => MasquageCoordonnees::requis() ? (string) MasquageCoordonnees::email($courriel) : (string) $courriel,
                'fonction' => (string) ($c->role ?? ''),
                'organisation' => (string) ($c->denomination ?? ''),
                'etape' => (string) ($c->lifecycle_stage ?? 'nouveau'),
                'provenance' => (string) ($c->discovery_source ?? ''),
                'retire_le' => $retire?->le,
                'droit_ecrire' => $oppose
                    ? ['verdict' => 'non', 'raison' => 'opposition']
                    : ['verdict' => 'prudence', 'raison' => 'verifier_avant_envoi'],
                'envois' => [],
                'rappel_le' => $rappel?->le,
                'rappel_note' => $rappel?->title,
            ];
        });
    }

    /**
     * D29-008 — L'INSTANT, PAS LA CHAÎNE.
     *
     * `activities.occurred_at` est un TIMESTAMPTZ (migration
     * `2026_08_14_000004_crm_socle_tags_optout_timeline.php`, l. 109) et arrive
     * ici en CHAÎNE, rendue par Postgres avec le décalage de la session — que
     * `config/database.php` aligne sur `DB_TIMEZONE` (Europe/Paris en test,
     * cf. `phpunit.xml`). Comparer ces chaînes, c'est comparer des suffixes.
     *
     * Le 25 octobre 2026, l'heure locale 02:30 existe DEUX fois :
     *   · `2026-10-25 02:30:00+02` = 00:30 Z (l'ANTÉRIEUR)
     *   · `2026-10-25 02:30:00+01` = 01:30 Z (le plus récent)
     * Les deux chaînes ne diffèrent que par le suffixe, et un tri décroissant
     * par `strcmp` place « +02 » devant « +01 » : la timeline « 360° » remonte
     * l'événement le plus ANCIEN en tête, une fois par an, pendant une heure.
     * Mesure du 2026-08-22 : aucune garde ne couvrait ce cas.
     *
     * On compare donc l'instant, en microsecondes (`U.u`) pour ne pas perdre
     * l'ordre des événements d'une même seconde. Un horodatage vide ou
     * illisible descend en fin de liste — comme le faisait la chaîne vide.
     *
     * @param  array<string, mixed>  $activite
     */
    private static function instantDe(array $activite): float
    {
        $brut = trim((string) ($activite['occurred_at'] ?? ''));

        if ($brut === '') {
            return -INF;
        }

        try {
            return (float) (new \DateTimeImmutable($brut))->format('U.u');
        } catch (\Exception) {
            // Une valeur qu'on ne sait pas lire ne doit pas décider de l'ordre
            // du reste : elle sort en queue, elle ne fait pas planter la fiche.
            return -INF;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activitiesFor(string $workspaceId, string $personKey, string $universe): array
    {
        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $personKey, $universe): array {
            $rows = DB::table('activities')
                ->where('workspace_id', $workspaceId)
                ->where('person_key', $personKey)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(self::MAX_ACTIVITIES)
                ->get();

            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'id' => (int) $row->id,
                    'universe' => $universe,
                    'kind' => $row->kind ?? $row->type,
                    'title' => $row->title,
                    'occurred_at' => $row->occurred_at ?? $row->created_at,
                    'external_ref' => $row->external_ref,
                    'subject_type' => $row->subject_type,
                    'subject_id' => $row->subject_id === null ? null : (int) $row->subject_id,
                ];
            }

            return $out;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function businessSubjects(string $workspaceId, string $personKey): array
    {
        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $personKey): array {
            $rows = DB::table('contacts')
                ->leftJoin('companies', 'companies.id', '=', 'contacts.company_id')
                ->where('contacts.workspace_id', $workspaceId)
                ->where('contacts.person_key', $personKey)
                ->select([
                    'contacts.id AS contact_id',
                    'contacts.first_name',
                    'contacts.last_name',
                    'contacts.email',
                    'contacts.phone',
                    'contacts.legal_basis',
                    'contacts.consent_version',
                    'contacts.consent_at',
                    'companies.id AS company_id',
                    'companies.denomination',
                    'companies.siren',
                    'companies.relation_type',
                    'companies.lifecycle_stage',
                ])
                ->limit(20)
                ->get();

            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'universe' => 'business',
                    'type' => 'contact',
                    'id' => (int) $row->contact_id,
                    'first_name' => $row->first_name,
                    'last_name' => $row->last_name,
                    'email' => $row->email,
                    'phone' => $row->phone,
                    'legal_basis' => $row->legal_basis,
                    'consent_version' => $row->consent_version,
                    'consent_at' => $row->consent_at,
                    'company' => $row->company_id === null ? null : [
                        'id' => (int) $row->company_id,
                        'denomination' => $row->denomination,
                        'siren' => $row->siren,
                        'relation_type' => $row->relation_type,
                        'lifecycle_stage' => $row->lifecycle_stage,
                    ],
                ];
            }

            return $out;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function vivierSubjects(string $workspaceId, string $personKey): array
    {
        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $personKey): array {
            $rows = DB::table('candidates')
                ->where('workspace_id', $workspaceId)
                ->where('person_key', $personKey)
                ->whereNull('deleted_at')
                ->limit(20)
                ->get();

            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'universe' => 'vivier',
                    'type' => 'candidate',
                    'id' => (int) $row->id,
                    'first_name' => $row->first_name,
                    'last_name' => $row->last_name,
                    'email' => $row->email,
                    'relation_type' => $row->relation_type,
                    'lifecycle_stage' => $row->lifecycle_stage,
                    'offer_slug' => $row->offer_slug,
                    'consent_version' => $row->consent_version,
                    'consent_vivier_at' => $row->consent_vivier_at,
                    'derniere_interaction_at' => $row->derniere_interaction_at,
                    'cv_ref' => $row->cv_ref,
                ];
            }

            return $out;
        });
    }

    private function existsInBusiness(string $workspaceId, string $personKey): bool
    {
        return WorkspaceContext::run($workspaceId, static fn (): bool => DB::table('contacts')
            ->where('workspace_id', $workspaceId)
            ->where('person_key', $personKey)
            ->exists()
            // Lot L4-C : une personne connue par la lettre ou le guide existe
            // dans l'univers business, même sans entreprise.
            || DB::table('personnes')
                ->where('workspace_id', $workspaceId)
                ->where('person_key', $personKey)
                ->exists());
    }

    /**
     * Lot L4-C — la PERSONNE (lettre et guide), branchée par `person_key` : la
     * même clé que la timeline, donc les mêmes activités. Elle porte son statut
     * de lettre, sa provenance et, si elle est rattachée, son entreprise.
     *
     * @return list<array<string, mixed>>
     */
    private function personneSubjects(string $workspaceId, string $personKey): array
    {
        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $personKey): array {
            $rows = DB::table('personnes')
                ->leftJoin('abonnements', function ($join): void {
                    $join->on('abonnements.personne_id', '=', 'personnes.id')
                        ->where('abonnements.canal', '=', 'lettre');
                })
                ->leftJoin('companies', 'companies.id', '=', 'personnes.company_id')
                ->where('personnes.workspace_id', $workspaceId)
                ->where('personnes.person_key', $personKey)
                ->select([
                    'personnes.id',
                    'personnes.first_name',
                    'personnes.last_name',
                    'personnes.email',
                    'personnes.email_nature',
                    'personnes.premiere_source',
                    'personnes.legal_basis',
                    'personnes.contact_id',
                    'abonnements.statut AS statut_lettre',
                    'abonnements.consent_version',
                    'abonnements.consent_at',
                    'companies.id AS company_id',
                    'companies.denomination',
                    'companies.siren',
                    'companies.relation_type',
                    'companies.lifecycle_stage',
                ])
                ->limit(5)
                ->get();

            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'universe' => 'business',
                    'type' => 'personne',
                    'id' => (int) $row->id,
                    'first_name' => $row->first_name,
                    'last_name' => $row->last_name,
                    'email' => $row->email,
                    'email_nature' => $row->email_nature,
                    'premiere_source' => $row->premiere_source,
                    'legal_basis' => $row->legal_basis,
                    'statut_lettre' => $row->statut_lettre,
                    'consent_version' => $row->consent_version,
                    'consent_at' => $row->consent_at,
                    'contact_id' => $row->contact_id === null ? null : (int) $row->contact_id,
                    'company' => $row->company_id === null ? null : [
                        'id' => (int) $row->company_id,
                        'denomination' => $row->denomination,
                        'siren' => $row->siren,
                        'relation_type' => $row->relation_type,
                        'lifecycle_stage' => $row->lifecycle_stage,
                    ],
                ];
            }

            return $out;
        });
    }

    /**
     * Requête d'EXISTENCE pure, volontairement isolée dans sa propre méthode :
     * c'est le seul endroit du code où l'on interroge le vivier sans en être
     * membre, et la seule information qui en sorte est un booléen.
     */
    private function existsInVivier(string $workspaceId, string $personKey): bool
    {
        return WorkspaceContext::run($workspaceId, static fn (): bool => DB::table('candidates')
            ->where('workspace_id', $workspaceId)
            ->where('person_key', $personKey)
            ->whereNull('deleted_at')
            ->exists());
    }
}
