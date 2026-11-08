<?php

namespace App\Crm\Rgpd;

use App\Crm\Ingest\PersonnesIngestService;
use App\Crm\Taxonomy;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/**
 * VOLET RGPD de la synchro site → CRM (plan §2.8.2) : art. 15 / art. 17 en
 * UNE action sur les DEUX systèmes.
 *
 * ⚠️ L3c-0 (ADR contrat v3 § 6) : `erase()` NE SUPPRIME PLUS RIEN. Il enregistre
 * la demande, met la personne à l'écart et la signale ; le texte historique
 * ci-dessous décrit l'ancien effacement.
 *
 * Le site est le point d'entrée du self-service (export/effacement, réparés
 * le 2026-08-13) ; après exécution LOCALE, il appelle
 * `POST /internal/site-sync/gdpr` — ce service — qui exporte / efface par
 * `person_key` ET par email dans les DEUX univers (business + vivier), puis
 * inscrit `email_hash` en liste d'opposition par univers.
 *
 * Pourquoi les DEUX clés :
 *   - `person_key` (salé côté site) identifie ce que la SYNCHRO a créé ;
 *   - l'email identifie ce que la COLLECTE a créé (le scraping ne connaît pas
 *     le sel du site — ses fiches n'ont pas de person_key).
 * Chercher par l'une OU l'autre est la seule façon de couvrir tout le stock.
 *
 * L'effacement journalise dans `rgpd_requests` (le journal d'exécution
 * existant, un par workspace touché) + la chaîne d'audit. L'opt-out par
 * univers (email hashé) survit à l'effacement : c'est l'anti-réinsertion.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * C21-001 — POURQUOI LES RECHERCHES PAR E-MAIL DE CE FICHIER S'ÉCRIVENT
 * `->where('email', $email)` ET NON `->whereRaw('lower(email::text) = ?')`.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Les cinq recherches par adresse de ce service s'écrivaient :
 *
 *     ->whereRaw('lower(email::text) = ?', [$email])
 *
 * `contacts.email`, `candidates.email` et `health_practitioners.email` sont de
 * type **`citext`** : la comparaison y est DÉJÀ insensible à la casse. Le
 * `lower()` ne changeait donc RIEN à la sémantique — d'autant que `export()` et
 * `erase()` mettent l'adresse en minuscules dès leur première ligne. Il ne
 * faisait qu'une chose : transformer la colonne en EXPRESSION (`citext → text`,
 * puis `lower()`), et un index B-tree posé sur la COLONNE ne couvre pas une
 * expression. Postgres n'avait plus que le balayage séquentiel — alors que
 * `idx_contacts_email` et `idx_candidates_email` étaient là, et pesaient.
 * C'est `G41-003` mot pour mot : l'index existe et ne sert pas.
 *
 * Mesure du 2026-08-20 sur le banc, 20 000 lignes par table, `EXPLAIN` du SQL
 * RÉELLEMENT émis par ce service (intercepté par `DB::listen`) :
 *
 *   AVANT   Seq Scan on contacts .................. 13,172 ms · 589 tampons
 *             Filter: (workspace_id = … AND (person_key = … OR
 *                      lower((email)::text) = 'fe7ecc…@exemple.fr'))
 *
 *   APRÈS   BitmapOr(idx_contacts_workspace_person_key,
 *                    idx_contacts_email) ........... 0,094 ms ·   6 tampons
 *
 * Soit 140 fois moins de temps et 98 fois moins de pages lues — sur 20 000
 * lignes seulement. `health_practitioners` : 11,764 ms → 0,034 ms.
 * `candidates` : 9,500 ms → 0,049 ms.
 *
 * Le coût d'un balayage croît LINÉAIREMENT avec la table ; celui de l'index,
 * logarithmiquement. Une demande d'accès ou d'effacement est un droit avec un
 * délai légal, pas une requête de confort.
 *
 * ⚠️ NE PAS RÉINTRODUIRE `lower(email::text)` ICI. Une garde le vérifie
 * littéralement : `tests/Feature/Infra/IndexEmailRgpdServentLesRequetesTest.php`,
 * test « les chemins repares n emploient plus lower(email::text) » ; et les
 * quatre mesures voisines redemandent à Postgres, par `EXPLAIN`, si le plan
 * emploie l'index.
 *
 * ⚠️ CE N'EST PAS FINI AILLEURS, et c'est consigné plutôt que tu. La même forme
 * survit dans `app/Services/Rgpd/GdprErasureService.php` (1 occurrence, sur
 * `email_verification_logs`),
 * `app/Services/Rgpd/GdprPortabilityService.php` (3), `app/Crm/Ingest/ContactUpserter.php`
 * (1) et `app/Crm/Ingest/SiteSyncIngestService.php` (1) : SIX occurrences de code,
 * mesurées le 2026-08-20.
 * Hors du périmètre du lot qui a réparé celui-ci ; remonté au rapport.
 */
final class SiteGdprService
{
    private const MOTIF = 'demande_effacement';

    public function __construct(private readonly AuditHashChain $audit) {}

    /**
     * Art. 15 — export agrégé des deux univers. Le site fusionne cette réponse
     * avec son propre export avant remise à la personne.
     *
     * @return array<string, mixed>
     */
    public function export(string $personKey, string $email): array
    {
        $email = mb_strtolower(trim($email));

        $result = [
            'business' => ['contacts' => [], 'personnes' => [], 'abonnements' => [], 'activities' => [], 'fiches_organisation' => []],
            'vivier' => ['candidates' => [], 'activities' => []],
            'opt_out' => [],
        ];

        $businessId = $this->workspaceId((string) config('crm.ingest.business_workspace', 'axion-ia'));
        $vivierId = $this->workspaceId(Taxonomy::VIVIER_WORKSPACE_SLUG);

        if ($businessId !== null) {
            $result['business'] = WorkspaceContext::run($businessId, function () use ($personKey, $email, $businessId): array {
                $contacts = DB::table('contacts')
                    ->where('workspace_id', $businessId)
                    ->where(function ($q) use ($personKey, $email): void {
                        $q->where('person_key', $personKey)
                            ->orWhere('email', $email);
                    })
                    ->get(['id', 'first_name', 'last_name', 'email', 'phone', 'role', 'linkedin_url', 'discovery_source', 'sources', 'legal_basis', 'consent_version', 'consent_at', 'created_at'])
                    ->map(fn (object $row): array => (array) $row)
                    ->all();

                // Lot L4-C — la personne connue par la lettre ou le guide, et
                // ses abonnements. Ce qu'on sait effacer, on doit savoir
                // l'exporter.
                $personnes = DB::table('personnes')
                    ->where('workspace_id', $businessId)
                    ->where(function ($q) use ($personKey, $email): void {
                        $q->where('person_key', $personKey)
                            ->orWhere('email', $email);
                    })
                    ->get(array_merge(
                        ['id', 'email', 'first_name', 'last_name', 'email_nature', 'locale', 'premiere_source', 'premiere_source_at', 'derniere_interaction_at', 'legal_basis', 'rattachee_at', 'origine_contenu', 'guides_recus', 'consentements', 'opposition_at', 'created_at'],
                        // Fiche demande : ce qu'on sait créer, on doit savoir l'exporter.
                        PersonnesIngestService::colonnesDemandePresentes() ? ['phone', 'entreprise_declaree'] : [],
                    ))
                    ->map(fn (object $row): array => (array) $row)
                    ->all();

                $abonnements = DB::table('abonnements')
                    ->where('workspace_id', $businessId)
                    ->whereIn('personne_id', array_map(static fn (array $p): int => (int) $p['id'], $personnes))
                    ->get(['personne_id', 'canal', 'statut', 'consent_version', 'consent_at', 'source_slug', 'abonne_at', 'desabonne_at', 'motif_desabonnement'])
                    ->map(fn (object $row): array => (array) $row)
                    ->all();

                // Les fiches d'ORGANISATION qui portent son adresse ou un de ses
                // numéros PERSONNELS — ses valeurs et leur emplacement, jamais
                // celles d'un tiers (relecture S1 et S6). Ce qu'on sait
                // effacer, on sait l'exporter.
                $telephones = EffacementCoordonneesFiches::telephonesDesContacts($email, $businessId, $personKey);
                $clesNom = EffacementCoordonneesFiches::clesNomDesContacts($email, $businessId, $personKey);
                $personnels = EffacementCoordonneesFiches::numerosPersonnels($telephones, $email, $clesNom, $businessId);

                return [
                    'contacts' => $contacts,
                    'personnes' => $personnes,
                    'abonnements' => $abonnements,
                    'activities' => $this->activities($businessId, $personKey),
                    'fiches_organisation' => EffacementCoordonneesFiches::fichesPortant($email, $personnels, $businessId),
                ];
            });
        }

        if ($vivierId !== null) {
            $result['vivier'] = WorkspaceContext::run($vivierId, function () use ($personKey, $email, $vivierId): array {
                $candidates = DB::table('candidates')
                    ->where('workspace_id', $vivierId)
                    ->where(function ($q) use ($personKey, $email): void {
                        $q->where('person_key', $personKey)
                            ->orWhere('email', $email);
                    })
                    ->get(['id', 'first_name', 'last_name', 'email', 'phone', 'relation_type', 'lifecycle_stage', 'source', 'offer_slug', 'legal_basis', 'consent_version', 'consent_at', 'consent_vivier_at', 'attributes', 'experiences', 'cv_ref', 'created_at'])
                    ->map(fn (object $row): array => (array) $row)
                    ->all();

                return [
                    'candidates' => $candidates,
                    'activities' => $this->activities($vivierId, $personKey),
                ];
            });
        }

        $result['opt_out'] = DB::table('opt_out')
            ->where('email_hash', hash('sha256', $email))
            ->get(['scope', 'source', 'created_at'])
            ->map(fn (object $row): array => (array) $row)
            ->all();

        return $result;
    }

    /**
     * Art. 17 — demande d'effacement venue du site (L3c-0, ADR contrat v3 § 6
     * et B2) : ELLE NE SUPPRIME RIEN.
     *
     * Par univers, la demande est ENREGISTRÉE dans `rgpd_requests` (statut
     * `pending`, donc visible et signalée dans « Demandes RGPD » de la barre
     * latérale), la personne est MISE À L'ÉCART (`opposition_at` + motif,
     * abonnements désabonnés, contacts à la corbeille douce, candidats à la corbeille douce)
     * et son empreinte entre dans `opt_out` (plus aucun envoi, anti-réinsertion).
     * Les données sont gardées ; c'est Will qui décide, outil par outil.
     *
     * @param  'both'|'business'|'vivier'  $scope
     * @return array<string, mixed>
     */
    public function erase(string $personKey, string $email, string $scope = 'both'): array
    {
        $email = mb_strtolower(trim($email));
        $emailHash = hash('sha256', $email);
        $ecart = [];

        if (in_array($scope, ['both', 'business'], true)) {
            $businessId = $this->workspaceId((string) config('crm.ingest.business_workspace', 'axion-ia'));
            if ($businessId !== null) {
                // Numéros de la personne et clés de nom, relevés AVANT la mise
                // à l'écart (ses fiches contacts les portent) : ils servent à
                // l'anti-réinsertion par téléphone, comme `GdprErasureService`.
                [$telephones, $clesNom] = WorkspaceContext::run(
                    $businessId,
                    static fn (): array => [
                        EffacementCoordonneesFiches::telephonesDesContacts($email, $businessId, $personKey),
                        EffacementCoordonneesFiches::clesNomDesContacts($email, $businessId, $personKey),
                    ],
                );

                $res = WorkspaceContext::run(
                    $businessId,
                    fn (): array => DB::transaction(function () use ($businessId, $personKey, $email, $telephones, $clesNom): array {
                        $personnesIds = DB::table('personnes')
                            ->where('workspace_id', $businessId)
                            ->where(function ($q) use ($personKey, $email): void {
                                $q->where('person_key', $personKey)
                                    ->orWhere('email', $email);
                            })
                            ->pluck('id')
                            ->all();

                        $contactsIds = DB::table('contacts')
                            ->where('workspace_id', $businessId)
                            ->where(function ($q) use ($personKey, $email): void {
                                $q->where('person_key', $personKey)
                                    ->orWhere('email', $email);
                            })
                            ->pluck('id')
                            ->all();

                        $praticiens = DB::table('health_practitioners')
                            ->where('workspace_id', $businessId)
                            ->where('email', $email)
                            ->whereNull('deleted_at')
                            ->update(['deleted_at' => now()]);

                        // Numéros PERSONNELS, relevés avant le masquage, sans rien modifier.
                        $personnels = EffacementCoordonneesFiches::numerosPersonnels($telephones, $email, $clesNom, $businessId);

                        $personnes = MiseAEcart::personnes($personnesIds, self::MOTIF);
                        [$demande, $nouvelle] = $this->journal($businessId, $email);

                        return [
                            'demande' => $demande,
                            'nouvelle' => $nouvelle,
                            'personnels' => $personnels,
                            'bilan' => [
                                'contacts' => MiseAEcart::contacts($contactsIds, self::MOTIF),
                                'personnes' => $personnes['personnes'],
                                'abonnements' => $personnes['abonnements'],
                                'health_practitioners' => $praticiens,
                            ],
                        ];
                    }),
                );
                $ecart['business'] = $res['bilan'];

                // Anti-réinsertion par téléphone : les numéros PERSONNELS
                // seulement, et personnels dans CHAQUE espace où on les trouve
                // (`opt_out.phone` est global : un standard ailleurs n'est pas
                // opposé).
                foreach (EffacementCoordonneesFiches::opposablesPartout($email, $res['personnels'], $clesNom, $businessId) as $telephone) {
                    $this->optOutTelephone($telephone, 'business');
                }

                // Un rejeu de la même demande ne re-signale pas.
                if ($res['nouvelle']) {
                    SignalementDemandeEffacement::signaler($businessId, 'site');
                }
            }
            $this->optOut($email, $emailHash, 'business');
        }

        if (in_array($scope, ['both', 'vivier'], true)) {
            $vivierId = $this->workspaceId(Taxonomy::VIVIER_WORKSPACE_SLUG);
            if ($vivierId !== null) {
                $res = WorkspaceContext::run(
                    $vivierId,
                    fn (): array => DB::transaction(function () use ($vivierId, $personKey, $email): array {
                        $candidatsIds = DB::table('candidates')
                            ->where('workspace_id', $vivierId)
                            ->where(function ($q) use ($personKey, $email): void {
                                $q->where('person_key', $personKey)
                                    ->orWhere('email', $email);
                            })
                            ->pluck('id')
                            ->all();

                        [, $nouvelle] = $this->journal($vivierId, $email);

                        return [
                            'nouvelle' => $nouvelle,
                            'bilan' => ['candidates' => MiseAEcart::candidats($candidatsIds, self::MOTIF)],
                        ];
                    }),
                );
                $ecart['vivier'] = $res['bilan'];

                if ($res['nouvelle']) {
                    SignalementDemandeEffacement::signaler($vivierId, 'site');
                }
            }
            $this->optOut($email, $emailHash, 'vivier');
        }

        // La chaîne d'audit porte la PREUVE de la demande — hash, jamais l'email.
        $this->audit->record([
            'workspace_id' => null,
            'user_id' => null,
            'method' => 'GDPR_ERASURE_REQUEST_BISYSTEM',
            'path' => '/internal/site-sync/gdpr',
            'status' => 200,
            'ip' => null,
            'user_agent' => null,
            'payload_hash' => hash('sha256', $personKey . '|' . $emailHash . '|' . $scope),
        ]);

        return [
            // Clé conservée pour les lecteurs existants : rien n'est supprimé.
            'deleted' => [],
            'mis_a_l_ecart' => $ecart,
            'opt_out_scopes' => $scope === 'both' ? ['business', 'vivier'] : [$scope],
            'verification' => 'aucune',
        ];
    }

    // ── Internes ────────────────────────────────────────────────────────────

    /** @return list<array<mixed>> */
    private function activities(string $workspaceId, string $personKey): array
    {
        return array_values(DB::table('activities')
            ->where('workspace_id', $workspaceId)
            ->where('person_key', $personKey)
            ->get(['kind', 'occurred_at', 'title', 'payload', 'external_ref'])
            ->map(fn (object $row): array => (array) $row)
            ->all());
    }

    private function optOut(string $email, string $emailHash, string $scope): void
    {
        $exists = DB::table('opt_out')->where('scope', $scope)->where('email_hash', $emailHash)->exists();
        if ($exists) {
            return;
        }

        DB::table('opt_out')->insert([
            // L'email en clair n'est PAS conservé sur une ligne née d'un
            // effacement : le hash suffit à l'anti-réinsertion, c'est tout
            // l'intérêt (« l'effacement laisse le hash », plan §B.6.10).
            'email' => null,
            'email_hash' => $emailHash,
            'scope' => $scope,
            'source' => 'gdpr_erasure_bisystem',
            'created_at' => now(),
        ]);
    }

    /**
     * Enregistre la DEMANDE d'effacement (`pending` : signalée à Will dans
     * « Demandes RGPD », qui décide). IDEMPOTENT : si une demande en attente
     * existe déjà pour la même adresse et le même espace, elle est reprise, pas
     * dupliquée (rejeu du site). À appeler DANS `WorkspaceContext::run()` et
     * DANS la transaction : `rgpd_requests` porte une RLS forcée.
     *
     * @return array{0: int, 1: bool} [identifiant, vraie si la demande vient d'être créée]
     */
    private function journal(string $workspaceId, string $email): array
    {
        // M3 : deux rejeux SIMULTANÉS du site liraient tous deux « aucune
        // demande » puis créeraient chacun la leur. Un verrou consultatif de
        // transaction, propre à (espace, adresse), sérialise le « lire puis
        // créer » ; il tombe tout seul à la fin de la transaction de l'appelant.
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['rgpd_requests.pending.erasure|' . $workspaceId . '|' . $email]);

        $existante = DB::table('rgpd_requests')
            ->where('workspace_id', $workspaceId)
            ->where('type', 'erasure')
            ->where('status', 'pending')
            ->where('subject_email', $email)
            ->value('id');
        if ($existante !== null) {
            return [(int) $existante, false];
        }

        $id = (int) DB::table('rgpd_requests')->insertGetId([
            'workspace_id' => $workspaceId,
            'type' => 'erasure',
            'status' => 'pending',
            'subject_email' => $email,
            'requested_at' => now(),
            'metadata' => json_encode([
                'origin' => 'site-sync-gdpr',
                'mise_a_l_ecart' => true,
                'decision' => 'en_attente_de_will',
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$id, true];
    }

    /**
     * Le mobile d'une personne mise à l'écart ne revient pas par la collecte
     * suivante (même règle que `DeduplicationService::addOptOut`, qui ôte
     * espaces, points et tirets).
     */
    private function optOutTelephone(string $telephone, string $scope): void
    {
        $telephone = (string) preg_replace('/[\s.-]/', '', $telephone);
        if ($telephone === '' || DB::table('opt_out')->where('scope', $scope)->where('phone', $telephone)->exists()) {
            return;
        }

        DB::table('opt_out')->insert([
            'email' => null,
            'email_hash' => null,
            'phone' => $telephone,
            'scope' => $scope,
            'source' => 'gdpr_erasure_bisystem',
            'created_at' => now(),
        ]);
    }

    private function workspaceId(string $slug): ?string
    {
        $id = DB::table('workspaces')->where('slug', $slug)->value('id');

        return $id === null ? null : (string) $id;
    }
}
