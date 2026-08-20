<?php

namespace App\Crm\Rgpd;

use App\Crm\Taxonomy;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/**
 * VOLET RGPD de la synchro site → CRM (plan §2.8.2) : art. 15 / art. 17 en
 * UNE action sur les DEUX systèmes.
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
            'business' => ['contacts' => [], 'activities' => []],
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

                return [
                    'contacts' => $contacts,
                    'activities' => $this->activities($businessId, $personKey),
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
     * Art. 17 — effacement par univers.
     *
     * @param  'both'|'business'|'vivier'  $scope
     * @return array<string, mixed>
     */
    public function erase(string $personKey, string $email, string $scope = 'both'): array
    {
        $email = mb_strtolower(trim($email));
        $emailHash = hash('sha256', $email);
        $deleted = [];

        if (in_array($scope, ['both', 'business'], true)) {
            $businessId = $this->workspaceId((string) config('crm.ingest.business_workspace', 'axion-ia'));
            if ($businessId !== null) {
                $deleted['business'] = WorkspaceContext::run(
                    $businessId,
                    fn (): array => DB::transaction(function () use ($businessId, $personKey, $email): array {
                        $contacts = DB::table('contacts')
                            ->where('workspace_id', $businessId)
                            ->where(function ($q) use ($personKey, $email): void {
                                $q->where('person_key', $personKey)
                                    ->orWhere('email', $email);
                            })
                            ->delete();

                        // 🔴 PRATICIENS DE SANTÉ — ARTICLE 9.
                        // Table visée par aucun effacement jusqu'au 2026-08-16.
                        // Suppression ferme : `nom`, `prenom`, `specialite`,
                        // `address` et `rpps` rendent la personne identifiable
                        // même sans email ni téléphone — et c'est justement la
                        // donnée de santé. Rattachée par `workspace_id`, donc
                        // couverte par le contexte posé ci-dessus.
                        $praticiens = DB::table('health_practitioners')
                            ->where('workspace_id', $businessId)
                            ->where('email', $email)
                            ->delete();

                        return [
                            'contacts' => $contacts,
                            'health_practitioners' => $praticiens,
                            'activities' => $this->deleteActivities($businessId, $personKey),
                        ];
                    }),
                );
                $this->journal($businessId, $email);
            }
            $this->optOut($email, $emailHash, 'business');
        }

        if (in_array($scope, ['both', 'vivier'], true)) {
            $vivierId = $this->workspaceId(Taxonomy::VIVIER_WORKSPACE_SLUG);
            if ($vivierId !== null) {
                $deleted['vivier'] = WorkspaceContext::run(
                    $vivierId,
                    fn (): array => DB::transaction(function () use ($vivierId, $personKey, $email): array {
                        // candidate_tag part avec la fiche (FK ON DELETE CASCADE).
                        // Le CV vit côté SITE (cv_ref n'est qu'une référence) :
                        // c'est l'effacement site, déjà exécuté avant cet appel,
                        // qui a purgé le fichier.
                        $candidates = DB::table('candidates')
                            ->where('workspace_id', $vivierId)
                            ->where(function ($q) use ($personKey, $email): void {
                                $q->where('person_key', $personKey)
                                    ->orWhere('email', $email);
                            })
                            ->delete();

                        return [
                            'candidates' => $candidates,
                            'activities' => $this->deleteActivities($vivierId, $personKey),
                        ];
                    }),
                );
                $this->journal($vivierId, $email);
            }
            $this->optOut($email, $emailHash, 'vivier');
        }

        // La chaîne d'audit porte la PREUVE de l'effacement — hash, jamais l'email.
        $this->audit->record([
            'workspace_id' => null,
            'user_id' => null,
            'method' => 'GDPR_ERASURE_BISYSTEM',
            'path' => '/internal/site-sync/gdpr',
            'status' => 200,
            'ip' => null,
            'user_agent' => null,
            'payload_hash' => hash('sha256', $personKey . '|' . $emailHash . '|' . $scope),
        ]);

        return ['deleted' => $deleted, 'opt_out_scopes' => $scope === 'both' ? ['business', 'vivier'] : [$scope]];
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

    private function deleteActivities(string $workspaceId, string $personKey): int
    {
        // La timeline d'une personne effacée part avec elle : les payloads
        // portent des PII (messages, téléphones). Les activités d'entreprise
        // (person_key NULL, ex. `scraped`) ne sont pas concernées.
        return DB::table('activities')
            ->where('workspace_id', $workspaceId)
            ->where('person_key', $personKey)
            ->delete();
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

    private function journal(string $workspaceId, string $email): void
    {
        DB::table('rgpd_requests')->insert([
            'workspace_id' => $workspaceId,
            'type' => 'erasure',
            'status' => 'done',
            'subject_email' => $email,
            'requested_at' => now(),
            'processed_at' => now(),
            'metadata' => json_encode(['origin' => 'site-sync-gdpr'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function workspaceId(string $slug): ?string
    {
        $id = DB::table('workspaces')->where('slug', $slug)->value('id');

        return $id === null ? null : (string) $id;
    }
}
