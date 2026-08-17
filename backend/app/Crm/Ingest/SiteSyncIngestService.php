<?php

namespace App\Crm\Ingest;

use App\Crm\Taxonomy;
use App\Support\WorkspaceContext;
use Database\Seeders\GovernedTagsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * FUNNEL D'INGESTION UNIQUE des événements du site (lot L2).
 *
 * Ordre d'application, identique pour TOUS les événements — c'est ce qui rend
 * le comportement prévisible et testable :
 *
 *   1. univers (business / vivier) et workspace de destination ;
 *   2. garde CANDIDATS : pas de consentement v2, pas de fiche vivier ;
 *   3. IDEMPOTENCE : l'événement a-t-il déjà été ingéré (`activities.external_ref`) ?
 *   4. OPPOSITION : la personne s'est-elle opposée dans cet univers ?
 *      (anti-réinsertion : le hash survit à l'effacement) ;
 *   5. DÉDUPLICATION : `external_ref` → `person_key` → email → nom+entreprise ;
 *   6. écriture « le DÉCLARÉ gagne » + `field_origins` ;
 *   7. tags gouvernés (jamais créés à la volée hors référentiel) ;
 *   8. timeline `activities`.
 *
 * Tout se déroule dans UNE transaction et sous le contexte workspace
 * (`WorkspaceContext::run`) : sans contexte, les policies RLS strictes du lot
 * L0 rendraient les écritures invisibles ou refusées — silencieusement.
 */
final class SiteSyncIngestService
{
    /**
     * Namespaces dont les valeurs ne sont PAS énumérables (elles dérivent des
     * données : département, code NAF, offre publiée, zone saisie). Leur
     * gouvernance est le namespace lui-même, pas une liste — ils peuvent donc
     * être créés à la demande. Tous les autres tags doivent préexister dans le
     * référentiel versionné.
     *
     * @var list<string>
     */
    private const DERIVABLE_TAG_NAMESPACES = ['geo', 'sect', 'cand-offre', 'cand-zone'];

    public function __construct(
        private readonly SiteSyncClassifier $classifier,
        private readonly ContactUpserter $contacts,
    ) {}

    public function ingest(SiteSyncEvent $event): IngestOutcome
    {
        $universe = $this->classifier->universe($event);

        if ($universe === 'vivier') {
            if (! $this->flag('candidates_enabled')) {
                throw SiteSyncRejection::unavailable(
                    'candidates_ingest_disabled',
                    'Les flux candidats sont fermés (CRM_INGEST_CANDIDATES_ENABLED). La ligne reste en attente côté site.',
                );
            }
            $this->assertCandidateConsentV2($event);
        }

        $workspaceId = $this->resolveWorkspaceId($universe);

        return WorkspaceContext::run(
            $workspaceId,
            fn (): IngestOutcome => DB::transaction(fn (): IngestOutcome => $this->apply($event, $universe, $workspaceId)),
        );
    }

    /**
     * GARDE CANDIDATS — la règle la plus dure du lot.
     *
     * Les textes de consentement v1 ne couvrent QUE l'étude de la candidature
     * en cours : ni la conservation en vivier, ni le transfert vers un outil
     * CRM. Une fiche candidat qui n'en porte pas la version v2 n'a donc AUCUNE
     * base pour exister ici. Ce n'est pas une préférence de configuration :
     * même avec `CRM_INGEST_CANDIDATES_ENABLED=true`, elle est REJETÉE.
     */
    private function assertCandidateConsentV2(SiteSyncEvent $event): void
    {
        $version = $event->consentVersion();

        if ($version === null || ! in_array($version, Taxonomy::CANDIDATE_CONSENT_VERSIONS_V2, true)) {
            throw SiteSyncRejection::invalid(
                'candidate_consent_v2_required',
                'Fiche candidat refusée : consentement v2 requis (' . implode(' | ', Taxonomy::CANDIDATE_CONSENT_VERSIONS_V2) . '), reçu : ' . ($version ?? 'aucun') . '.',
                ['received_consent_version' => $version],
            );
        }
    }

    private function apply(SiteSyncEvent $event, string $universe, string $workspaceId): IngestOutcome
    {
        $activityRef = 'site:event:' . $event->eventId;

        $already = DB::table('activities')
            ->where('workspace_id', $workspaceId)
            ->where('external_ref', $activityRef)
            ->first();

        if ($already !== null) {
            // Rejouer un événement (retry de l'outbox, rattrapage du batch)
            // ne doit RIEN créer ni modifier.
            return new IngestOutcome(
                status: IngestOutcome::IDEMPOTENT,
                subjectType: is_string($already->subject_type ?? null) ? $already->subject_type : null,
                subjectId: is_numeric($already->subject_id ?? null) ? (int) $already->subject_id : null,
                activityId: (int) $already->id,
            );
        }

        $scope = $this->oppositionScope($event, $universe);
        if (! $this->isOppositionEvent($event) && $this->hasOpposed($event, $scope)) {
            return new IngestOutcome(status: IngestOutcome::OPTED_OUT);
        }

        [$subjectType, $subjectId, $status, $contactId] = $universe === 'vivier'
            ? $this->upsertCandidate($event, $workspaceId)
            : $this->upsertBusiness($event, $workspaceId);

        $tags = [];
        if ($subjectId !== null && $subjectType !== null) {
            $tags = $this->attachTags($event, $workspaceId, $subjectType, $subjectId);
        }

        if ($this->isOppositionEvent($event)) {
            $this->recordOpposition($event, $scope);
        }

        $activityId = $this->recordActivity($event, $workspaceId, $subjectType, $subjectId, $contactId, $activityRef);

        return new IngestOutcome(
            status: $status,
            subjectType: $subjectType,
            subjectId: $subjectId,
            activityId: $activityId,
            tags: $tags,
        );
    }

    // ── Univers BUSINESS ────────────────────────────────────────────────────

    /**
     * @return array{0: ?string, 1: ?int, 2: string, 3: ?int}
     */
    private function upsertBusiness(SiteSyncEvent $event, string $workspaceId): array
    {
        $siren = $event->siren();

        // Sans SIREN, on ne crée RIEN : rapprocher une entreprise par sa
        // dénomination est une proposition, jamais une certitude (4,29 M fiches
        // INSEE en face). L'événement est conservé pour l'écran d'arbitrage.
        if ($siren === null) {
            return [null, null, IngestOutcome::PENDING_MATCH, null];
        }

        [$companyId, $companyStatus] = $this->upsertCompany($event, $workspaceId, $siren);
        $contact = $this->upsertContact($event, $workspaceId, $companyId);

        return [
            'company',
            $companyId,
            $contact === null ? $companyStatus : $contact[1],
            $contact === null ? null : $contact[0],
        ];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function upsertCompany(SiteSyncEvent $event, string $workspaceId, string $siren): array
    {
        $existing = DB::table('companies')
            ->where('workspace_id', $workspaceId)
            ->where('siren', $siren)
            ->first();

        $relation = $this->classifier->relationType($event);
        $lifecycle = $this->classifier->lifecycleStage($event);
        $legalBasis = $this->classifier->legalBasis($event);

        $declared = array_filter([
            'denomination' => $event->str('company', 'name'),
            'postcode' => $event->str('company', 'postcode'),
            'city' => $event->str('company', 'city'),
            'website' => $event->str('company', 'website'),
            'size_category' => $event->str('company', 'size_category'),
        ], static fn (?string $v): bool => $v !== null);

        if ($existing === null) {
            $id = (int) DB::table('companies')->insertGetId(array_merge([
                'workspace_id' => $workspaceId,
                'siren' => $siren,
                'discovery_source' => 'site',
                'quality_score' => 0,
                'signals' => '{}',
                'metadata' => '{}',
                'relation_type' => $relation,
                'lifecycle_stage' => $lifecycle,
                'legal_basis' => $legalBasis,
                'external_ref' => $event->subjectRef,
                'field_origins' => $this->declaredOrigins(array_keys($declared)),
                'consent_version' => $event->consentVersion(),
                'consent_at' => $event->consentAt(),
                'consent_text_ref' => $event->str('consent', 'text_ref'),
                'created_at' => now(),
                'updated_at' => now(),
            ], $declared));

            return [$id, IngestOutcome::CREATED];
        }

        $update = [
            'relation_type' => $this->classifier->mergeRelationType(
                is_string($existing->relation_type ?? null) ? $existing->relation_type : null,
                $relation,
            ),
            'lifecycle_stage' => $this->classifier->mergeLifecycleStage(
                is_string($existing->lifecycle_stage ?? null) ? $existing->lifecycle_stage : null,
                $lifecycle,
            ),
            'legal_basis' => $this->classifier->mergeLegalBasis(
                is_string($existing->legal_basis ?? null) ? $existing->legal_basis : null,
                $legalBasis,
            ),
            'updated_at' => now(),
        ];

        // Règle « le DÉCLARÉ gagne » (audit d'harmonisation §B.4.3) : une valeur
        // saisie par la personne écrase une valeur collectée. L'inverse est
        // interdit — c'est le rôle de `field_origins`, que les collecteurs
        // liront pour ne jamais réécrire du déclaré.
        $origins = $this->decodeOrigins($existing->field_origins ?? null);
        foreach ($declared as $column => $value) {
            $update[$column] = $value;
            $origins[$column] = 'declared';
        }
        $update['field_origins'] = json_encode($origins, JSON_THROW_ON_ERROR);

        if ($existing->external_ref === null) {
            $update['external_ref'] = $event->subjectRef;
        }
        if ($event->consentVersion() !== null) {
            $update['consent_version'] = $event->consentVersion();
            $update['consent_at'] = $event->consentAt();
            $update['consent_text_ref'] = $event->str('consent', 'text_ref');
        }

        DB::table('companies')->where('id', $existing->id)->update($update);

        return [(int) $existing->id, IngestOutcome::UPDATED];
    }

    /**
     * Déduplication PERSONNE. La logique vit dans `ContactUpserter` — extraite
     * au lot L6 pour que l'ARBITRAGE manuel (rattacher a posteriori un
     * événement resté sans entreprise) dédoublonne EXACTEMENT comme
     * l'ingestion automatique. Deux implémentations auraient fini par diverger,
     * c'est-à-dire par créer des doublons depuis l'écran censé les résoudre.
     *
     * @return array{0: int, 1: string}|null [contact_id, statut] — null si la
     *                                       personne n'est pas identifiable.
     */
    private function upsertContact(SiteSyncEvent $event, string $workspaceId, int $companyId): ?array
    {
        return $this->contacts->upsert(
            workspaceId: $workspaceId,
            companyId: $companyId,
            personKey: $event->personKey(),
            externalRef: $event->subjectRef,
            email: $event->email(),
            firstName: $event->str('person', 'first_name'),
            lastName: $event->str('person', 'last_name'),
            phone: $event->str('person', 'phone'),
            legalBasis: $this->classifier->legalBasis($event),
            consentVersion: $event->consentVersion(),
            consentAt: $event->consentAt(),
            consentTextRef: $event->str('consent', 'text_ref'),
            discoverySource: 'site',
        );
    }

    // ── Univers VIVIER ──────────────────────────────────────────────────────

    /**
     * @return array{0: ?string, 1: ?int, 2: string, 3: ?int}
     */
    private function upsertCandidate(SiteSyncEvent $event, string $workspaceId): array
    {
        $family = $event->candidateFamily() ?? 'candidat_autre';
        if (! in_array($family, Taxonomy::CANDIDATE_RELATION_TYPES, true)) {
            throw SiteSyncRejection::invalid(
                'unknown_candidate_family',
                "Famille de métiers hors liste fermée : « {$family} ».",
            );
        }

        $email = $event->email();
        $vivierAt = $event->consentVivierAt();

        $existing = DB::table('candidates')
            ->where('workspace_id', $workspaceId)
            ->where('external_ref', $event->subjectRef)
            ->first();

        $existing ??= DB::table('candidates')
            ->where('workspace_id', $workspaceId)
            ->where('person_key', $event->personKey())
            ->first();

        if ($existing === null && $email !== null) {
            $existing = DB::table('candidates')
                ->where('workspace_id', $workspaceId)
                ->whereRaw('lower(email::text) = ?', [$email])
                ->first();
        }

        $attributes = $event->candidate['attributes'] ?? [];
        $experiences = $event->candidate['experiences'] ?? [];

        $common = [
            'person_key' => $event->personKey(),
            'first_name' => $event->str('person', 'first_name'),
            'last_name' => $event->str('person', 'last_name') ?? 'Candidat',
            'email' => $email,
            'phone' => $event->str('person', 'phone'),
            'relation_type' => $family,
            'source' => $event->sourceSlug,
            'offer_slug' => $event->str('candidate', 'offer_slug'),
            // `consent` = conservation en vivier acceptée ; `precontractual` =
            // étude de la candidature en cours, sans conservation.
            'legal_basis' => $vivierAt !== null ? 'consent' : 'precontractual',
            'consent_version' => $event->consentVersion(),
            'consent_at' => $event->consentAt(),
            'consent_text_ref' => $event->str('consent', 'text_ref'),
            'consent_vivier_at' => $vivierAt,
            'derniere_interaction_at' => $event->occurredAt,
            'attributes' => json_encode(is_array($attributes) ? $attributes : [], JSON_THROW_ON_ERROR),
            'experiences' => json_encode(is_array($experiences) ? $experiences : [], JSON_THROW_ON_ERROR),
            'cv_ref' => $event->str('candidate', 'cv_ref'),
            'updated_at' => now(),
        ];

        if ($existing === null) {
            $id = (int) DB::table('candidates')->insertGetId(array_merge($common, [
                'workspace_id' => $workspaceId,
                'external_ref' => $event->subjectRef,
                'lifecycle_stage' => 'nouveau',
                'created_at' => now(),
            ]));

            return ['candidate', $id, IngestOutcome::CREATED, null];
        }

        if ($existing->external_ref === null) {
            $common['external_ref'] = $event->subjectRef;
        }

        DB::table('candidates')->where('id', $existing->id)->update($common);

        return ['candidate', (int) $existing->id, IngestOutcome::UPDATED, null];
    }

    // ── Tags, opposition, timeline ──────────────────────────────────────────

    /**
     * @return list<string>
     */
    private function attachTags(SiteSyncEvent $event, string $workspaceId, string $subjectType, int $subjectId): array
    {
        $attached = [];

        foreach ($this->classifier->tags($event) as $slug) {
            $tagId = $this->resolveTagId($workspaceId, $slug);
            if ($tagId === null) {
                continue;
            }

            $pivot = $subjectType === 'candidate' ? 'candidate_tag' : 'company_tag';
            $key = $subjectType === 'candidate' ? 'candidate_id' : 'company_id';

            DB::table($pivot)->insertOrIgnore([
                $key => $subjectId,
                'tag_id' => $tagId,
                'workspace_id' => $workspaceId,
                'assigned_at' => now(),
                'assigned_by' => 'auto-rule',
            ]);

            $attached[] = $slug;
        }

        return $attached;
    }

    /**
     * Un tag ne se crée JAMAIS à la volée. Deux cas seulement :
     *   - il existe déjà dans le workspace ;
     *   - il appartient au référentiel VERSIONNÉ (`GovernedTagsSeeder`) ou à un
     *     namespace dérivé des données (`geo:`, `sect:`, `cand-offre:`,
     *     `cand-zone:`), auquel cas il est matérialisé, verrouillé.
     * Sinon on l'ignore : une ingestion ne doit pas pouvoir polluer le
     * référentiel, mais elle ne doit pas non plus perdre une fiche pour un tag.
     */
    private function resolveTagId(string $workspaceId, string $slug): ?int
    {
        $existing = DB::table('tags')
            ->where('workspace_id', $workspaceId)
            ->where('slug', $slug)
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $namespace = explode(':', $slug, 2)[0];
        $referential = GovernedTagsSeeder::referential();

        if (array_key_exists($slug, $referential)) {
            $name = $referential[$slug]['name'];
            $category = $referential[$slug]['category'];
        } elseif (in_array($namespace, self::DERIVABLE_TAG_NAMESPACES, true)) {
            $name = $slug;
            $category = Taxonomy::TAG_NAMESPACES[$namespace];
        } else {
            return null;
        }

        DB::table('tags')->insertOrIgnore([
            'workspace_id' => $workspaceId,
            'slug' => $slug,
            'name' => $name,
            'category' => $category,
            'kind' => 'auto',
            'rules' => '{}',
            'is_locked' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = DB::table('tags')
            ->where('workspace_id', $workspaceId)
            ->where('slug', $slug)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function isOppositionEvent(SiteSyncEvent $event): bool
    {
        return in_array($event->eventType, ['opt_out', 'newsletter_optout'], true);
    }

    /**
     * Univers d'une OPPOSITION — qui n'est pas celui de l'événement.
     *
     * 🔴 `SiteSyncClassifier::universe()` ne rend `vivier` que pour une
     * `application_submitted` ou un formulaire de type `recrutement`. Un
     * `opt_out` n'est ni l'un ni l'autre : il retombait donc TOUJOURS en
     * `business`, y compris l'opposition d'un candidat à sa conservation au
     * vivier. Deux effets, tous deux constatés en E2E le 2026-08-17 :
     *
     *  1. l'opposition était inscrite en `scope = business` → `hasOpposed()`,
     *     qui interroge `scope = vivier` pour une candidature, ne la voyait
     *     pas : **la fiche revenait au vivier au dépôt suivant** ;
     *  2. la personne se trouvait désinscrite des communications COMMERCIALES
     *     qu'elle n'avait jamais demandé à quitter.
     *
     * Le site le dit déjà dans le corps du message (`syncVivierOppositionToCrm`
     * pose `payload.scope = "vivier"`, avec le commentaire « pour que le CRM
     * n'ait pas à le déduire de l'univers »). Il le dit UNE SECONDE FOIS par le
     * `subject_ref`, qui désigne la candidature d'origine.
     *
     * On exige **les deux** : la valeur déclarée doit appartenir à la liste
     * fermée `business|vivier`, ET être corroborée par le `subject_ref`. Le
     * payload ne CHOISIT donc pas sa destination — il confirme une déduction
     * que le CRM fait lui-même, ce qui préserve la règle du plan §B.7.d (« la
     * classification est une décision du CRM, sinon un émetteur compromis
     * choisirait l'univers d'atterrissage »).
     */
    private function oppositionScope(SiteSyncEvent $event, string $universe): string
    {
        if ($event->eventType === 'opt_out') {
            $declared = $event->str('payload', 'scope');
            $viseVivier = str_starts_with($event->subjectRef, 'site:job_application:');

            if ($declared === 'vivier' && $viseVivier) {
                return 'vivier';
            }
        }

        return $universe === 'vivier' ? 'vivier' : 'business';
    }

    private function hasOpposed(SiteSyncEvent $event, string $scope): bool
    {
        $hash = $event->emailHash();
        if ($hash === null) {
            return false;
        }

        // Requête HORS scope workspace : `opt_out` est délibérément une table
        // globale scopée par `scope` (business|vivier) et non par workspace —
        // cf. la note de la migration L1.
        return DB::table('opt_out')
            ->where('scope', $scope)
            ->where('email_hash', $hash)
            ->exists();
    }

    private function recordOpposition(SiteSyncEvent $event, string $scope): void
    {
        $hash = $event->emailHash();
        if ($hash === null) {
            return;
        }

        if (DB::table('opt_out')->where('scope', $scope)->where('email_hash', $hash)->exists()) {
            return;
        }

        DB::table('opt_out')->insert([
            // 🔴 JAMAIS l'adresse en clair (plan §B.4 et §B.10 : « email_hash
            // renseigné, email jamais en clair »). Le voisin le faisait déjà
            // bien — `SiteGdprService::optOut()` écrit `null` avec le motif :
            // « le hash suffit à l'anti-réinsertion, c'est tout l'intérêt ».
            // Ici l'adresse était conservée À CÔTÉ du hachage, qui ne protégeait
            // donc rien. Aucun lecteur ne s'en sert : les trois interrogent
            // `email_hash` (`hasOpposed`, `ScrapedRecordIngestService`,
            // `SiteGdprService`), et l'export RGPD ne renvoie même pas la
            // colonne.
            'email' => null,
            'email_hash' => $hash,
            'scope' => $scope,
            'source' => 'site-sync:' . $event->eventType,
            'reason' => $event->str('payload', 'reason'),
            'created_at' => now(),
        ]);
    }

    private function recordActivity(
        SiteSyncEvent $event,
        string $workspaceId,
        ?string $subjectType,
        ?int $subjectId,
        ?int $contactId,
        string $activityRef,
    ): int {
        $payload = $event->payload;
        $payload['source_slug'] = $event->sourceSlug;
        $payload['subject_ref'] = $event->subjectRef;

        if ($subjectId === null) {
            // Matière première de l'écran d'arbitrage : ce qu'on sait de
            // l'entreprise ET de la personne, sans avoir jamais deviné à quelle
            // fiche l'attacher.
            //
            // Le bloc `person` est ici pour une raison précise (lot L6) : au
            // moment où l'opérateur rattache enfin l'événement à une entreprise,
            // la fiche personne doit pouvoir être créée. Sans nom conservé, le
            // rattachement produirait une entreprise sans interlocuteur — et
            // l'information serait définitivement perdue, puisque le site n'a
            // aucune raison de rejouer un événement déjà accusé réception.
            $pendingMatch = array_filter([
                'denomination' => $event->str('company', 'name'),
                'postcode' => $event->str('company', 'postcode'),
                'city' => $event->str('company', 'city'),
                'website' => $event->str('company', 'website'),
                'email' => $event->email(),
                'first_name' => $event->str('person', 'first_name'),
                'last_name' => $event->str('person', 'last_name'),
                'phone' => $event->str('person', 'phone'),
                'subject_ref' => $event->subjectRef,
                'legal_basis' => $this->classifier->legalBasis($event),
                'consent_version' => $event->consentVersion(),
                'consent_text_ref' => $event->str('consent', 'text_ref'),
            ], static fn (?string $v): bool => $v !== null);

            $consentAt = $event->consentAt();
            if ($consentAt !== null) {
                $pendingMatch['consent_at'] = $consentAt->format(DATE_ATOM);
            }

            $payload['pending_match'] = $pendingMatch;
        }

        return (int) DB::table('activities')->insertGetId([
            'workspace_id' => $workspaceId,
            'contact_id' => $contactId,
            // `type` (texte libre, historique) est conservée le temps de la
            // phase « expand » : elle reçoit la même valeur que `kind`.
            'type' => $this->classifier->activityKind($event),
            'kind' => $this->classifier->activityKind($event),
            'occurred_at' => $event->occurredAt,
            'person_key' => $event->personKey(),
            'external_ref' => $activityRef,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'title' => $this->activityTitle($event),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    private function activityTitle(SiteSyncEvent $event): string
    {
        return match ($event->eventType) {
            'form_submission' => 'Formulaire — ' . (string) $event->formType,
            'application_submitted' => 'Candidature — ' . ($event->str('candidate', 'offer_slug') ?? 'spontanée'),
            default => str_replace('_', ' ', $event->eventType),
        };
    }

    // ── Utilitaires ─────────────────────────────────────────────────────────

    private function resolveWorkspaceId(string $universe): string
    {
        $slug = $universe === 'vivier'
            ? Taxonomy::VIVIER_WORKSPACE_SLUG
            : (string) config('crm.ingest.business_workspace');

        $id = DB::table('workspaces')->where('slug', $slug)->value('id');

        if ($id === null) {
            throw SiteSyncRejection::unavailable(
                'workspace_missing',
                "Workspace de destination introuvable : « {$slug} ».",
            );
        }

        return (string) $id;
    }

    /** @param  list<string>  $columns */
    private function declaredOrigins(array $columns): string
    {
        $origins = [];
        foreach ($columns as $column) {
            $origins[$column] = 'declared';
        }

        return json_encode($origins, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> */
    private function decodeOrigins(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $origins = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $origins[$key] = $value;
            }
        }

        return $origins;
    }

    private function flag(string $key): bool
    {
        return filter_var(config('crm.ingest.' . $key, false), FILTER_VALIDATE_BOOLEAN);
    }
}
