<?php

namespace App\Crm\Ingest;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * DÉDUPLICATION PERSONNE — extraite de `SiteSyncIngestService` (lot L2) pour
 * être appelée par DEUX chemins qui doivent se comporter à l'identique :
 *
 *   1. l'ingestion automatique du site (`POST /internal/site-sync`) ;
 *   2. l'ARBITRAGE manuel (lot L6) : quand un opérateur rattache enfin un
 *      événement resté sans entreprise (SIREN absent), la fiche personne doit
 *      être créée ou retrouvée EXACTEMENT comme si l'ingestion avait su le
 *      faire toute seule.
 *
 * Réécrire cette logique dans le contrôleur d'arbitrage aurait produit deux
 * dédoublonnages divergents — c'est-à-dire, à terme, des doublons créés par
 * l'écran censé les résoudre.
 *
 * Ordre de recherche (plan §2.4, audit d'harmonisation §B.4.2), du plus sûr au
 * plus faible :
 *   `external_ref` (même enregistrement source) → `person_key` (email hashé,
 *   la seule clé qui traverse les deux systèmes) → email → nom + entreprise
 *   (`normalized_hash`, la dédup historique du scraping).
 */
final class ContactUpserter
{
    public function __construct(private readonly SiteSyncClassifier $classifier) {}

    /**
     * Crée ou met à jour la fiche personne rattachée à `$companyId`.
     *
     * @return array{0: int, 1: string}|null [contact_id, statut] — `null` si la
     *                                       personne n'est pas identifiable
     *                                       (pas de nom : on ne fabrique JAMAIS
     *                                       un patronyme depuis une adresse
     *                                       électronique).
     */
    public function upsert(
        string $workspaceId,
        int $companyId,
        string $personKey,
        ?string $externalRef,
        ?string $email,
        ?string $firstName,
        ?string $lastName,
        ?string $phone,
        string $legalBasis,
        ?string $consentVersion = null,
        ?DateTimeInterface $consentAt = null,
        ?string $consentTextRef = null,
        string $discoverySource = 'site',
    ): ?array {
        $existing = $externalRef === null ? null : DB::table('contacts')
            ->where('workspace_id', $workspaceId)
            ->where('external_ref', $externalRef)
            ->first();

        $existing ??= DB::table('contacts')
            ->where('workspace_id', $workspaceId)
            ->where('person_key', $personKey)
            ->first();

        if ($existing === null && $email !== null) {
            // C21-001 — CETTE RECHERCHE EST JOUÉE À CHAQUE FICHE ENTRANTE, et
            // aussi par l'écran d'ARBITRAGE manuel, qui passe par ce service.
            //
            // ⚠️ NE PAS RÉÉCRIRE `->whereRaw('lower(email::text) = ?', [$email])`.
            // `contacts.email` est de type `citext` : elle compare DÉJÀ sans
            // égard à la casse, et `idx_contacts_email btree (email) WHERE
            // email IS NOT NULL` porte sur la COLONNE, pas sur l'expression.
            // Le transtypage suivi de `lower()` rendait l'index
            // méconnaissable, et Postgres retombait sur le balayage complet.
            // Mesuré sur le banc `a35r`, 20 000 lignes, le 2026-08-21 :
            //     lower(email::text)  Seq Scan    coût 923,00  25,257 ms  573 tampons
            //     email (citext)      Index Scan  coût   8,43   0,174 ms    4 tampons
            // Garde : `tests/Feature/Crm/IndexEmailIngestionServentLesRequetesTest.php`.
            $existing = DB::table('contacts')
                ->where('workspace_id', $workspaceId)
                ->where('email', $email)
                ->orderByRaw('CASE WHEN company_id = ? THEN 0 ELSE 1 END', [$companyId])
                ->first();
        }

        if ($existing === null && $lastName !== null) {
            $hash = $this->normalizedHash($firstName, $lastName, $companyId);
            $existing = DB::table('contacts')
                ->where('workspace_id', $workspaceId)
                ->where('normalized_hash', $hash)
                ->first();
        }

        if ($existing === null) {
            // `contacts.last_name` est NOT NULL. Plutôt que de fabriquer un nom
            // depuis l'adresse électronique (ce que fait le scraping et que
            // l'audit relève comme une faiblesse), on renonce à créer la fiche
            // personne : l'événement reste dans la timeline de l'entreprise.
            if ($lastName === null) {
                return null;
            }

            $id = (int) DB::table('contacts')->insertGetId([
                'workspace_id' => $workspaceId,
                'company_id' => $companyId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $phone,
                'discovery_source' => $discoverySource,
                'sources' => json_encode([$discoverySource], JSON_THROW_ON_ERROR),
                'metadata' => '{}',
                'person_key' => $personKey,
                'external_ref' => $externalRef,
                'legal_basis' => $legalBasis,
                'consent_version' => $consentVersion,
                'consent_at' => $consentAt,
                'consent_text_ref' => $consentTextRef,
                'field_origins' => $this->declaredOrigins(['first_name', 'last_name', 'email', 'phone']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [$id, IngestOutcome::CREATED];
        }

        $update = [
            'person_key' => $personKey,
            'legal_basis' => $this->classifier->mergeLegalBasis(
                is_string($existing->legal_basis ?? null) ? $existing->legal_basis : null,
                $legalBasis,
            ),
            'updated_at' => now(),
        ];

        $origins = $this->decodeOrigins($existing->field_origins ?? null);

        foreach (['email' => $email, 'phone' => $phone] as $column => $value) {
            if ($value !== null) {
                $update[$column] = $value;
                $origins[$column] = 'declared';
            }
        }

        // Le nom alimente une colonne GÉNÉRÉE (`normalized_hash`) porteuse d'un
        // index UNIQUE : le modifier peut entrer en collision avec une autre
        // fiche de la même entreprise. On vérifie AVANT d'écrire plutôt que de
        // faire échouer toute la transaction sur un homonyme.
        if ($lastName !== null) {
            $target = $this->normalizedHash($firstName, $lastName, (int) $existing->company_id);
            $collision = DB::table('contacts')
                ->where('workspace_id', $workspaceId)
                ->where('normalized_hash', $target)
                ->where('id', '!=', $existing->id)
                ->exists();

            if (! $collision) {
                $update['first_name'] = $firstName;
                $update['last_name'] = $lastName;
                $origins['first_name'] = 'declared';
                $origins['last_name'] = 'declared';
            }
        }

        if ($existing->external_ref === null && $externalRef !== null) {
            $update['external_ref'] = $externalRef;
        }
        if ($consentVersion !== null) {
            $update['consent_version'] = $consentVersion;
            $update['consent_at'] = $consentAt;
            $update['consent_text_ref'] = $consentTextRef;
        }

        $update['field_origins'] = json_encode($origins, JSON_THROW_ON_ERROR);

        DB::table('contacts')->where('id', $existing->id)->update($update);

        return [(int) $existing->id, IngestOutcome::UPDATED];
    }

    /**
     * `normalized_hash` est une colonne GÉNÉRÉE côté Postgres — on la recalcule
     * avec la MÊME expression (fonction SQL `normalize_name` comprise), jamais
     * avec une approximation PHP qui divergerait au premier accent.
     */
    private function normalizedHash(?string $firstName, string $lastName, int $companyId): string
    {
        $row = DB::selectOne(
            "SELECT encode(digest(normalize_name(coalesce(?, '') || '_' || ?) || '_' || ?::TEXT, 'sha256'), 'hex') AS h",
            [$firstName, $lastName, $companyId],
        );

        return (string) ($row->h ?? '');
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
}
