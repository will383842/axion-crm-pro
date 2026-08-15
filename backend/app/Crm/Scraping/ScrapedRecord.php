<?php

namespace App\Crm\Scraping;

use DateTimeImmutable;
use Exception;

/**
 * SCHÉMA PIVOT `ScrapedRecord` — le contrat d'entrée COMMUN de toute collecte
 * (lot L3, audit scraping §C.1).
 *
 * Tout collecteur, quel que soit son langage (worker Node, étape PHP du
 * waterfall, import CSV/JSONL), émet CE format et rien d'autre. L'embryon
 * existait (`workers/src/bridge/result-sender.ts`) mais son `payload` était
 * freeform et le récepteur un stub : un format que personne ne valide n'est
 * pas un contrat.
 *
 * Règles du contrat (mêmes principes que `SiteSyncEvent`, lot L2) :
 *   - schéma STRICT et versionné : toute clé inconnue rejette le message ;
 *   - au moins UNE clé de rattachement entreprise : `siren` (direct) ou
 *     `match_hint` (arbitrage humain) ;
 *   - PAS de PII hors-sujet : des coordonnées professionnelles B2B, rien
 *     d'autre ;
 *   - les emails seront validés côté funnel (MX) — jamais confiance au
 *     collecteur ;
 *   - le pivot ne porte NI type de relation NI cycle de vie : un scrapé est
 *     froid par définition, le classement est une décision du CRM.
 */
final class ScrapedRecord
{
    public const SCHEMA_VERSION = 1;

    public const STATUSES = ['success', 'partial', 'failed'];

    public const PERSON_KINDS = ['person', 'service_mailbox'];

    private const TOP_LEVEL_KEYS = [
        'schema_version', 'source', 'run_id', 'fetched_at', 'status',
        'company', 'persons', 'channels', 'evidence', 'confidence',
        // Champs historiques du bridge Node (result-sender.ts) — tolérés pour
        // ne pas casser les producteurs existants, ignorés par le funnel.
        'payload', 'emails', 'phones', 'error', 'latency_ms',
    ];

    private const COMPANY_KEYS = ['siren', 'foreign_id', 'country', 'nature', 'match_hint', 'fields', 'implantations'];

    /**
     * Natures d'entité — liste FERMÉE, alignée sur le CHECK SQL posé par la
     * migration `2026_08_15_120001`. Une valeur inventée ici passerait la
     * validation applicative pour mourir en base : les deux listes bougent
     * ENSEMBLE.
     */
    public const ENTITY_NATURES = [
        'entreprise', 'association', 'cci', 'enseignement',
        'cabinet', 'institution', 'media',
    ];

    private const MATCH_HINT_KEYS = ['denomination', 'postcode', 'city', 'address'];

    private const COMPANY_FIELD_KEYS = [
        'denomination', 'website', 'phone', 'email_generic', 'address',
        'postcode', 'city', 'linkedin_url',
    ];

    /**
     * Implantation à l'ÉTRANGER d'une entreprise française (filiale, usine,
     * bureau). `country` = ISO 3166-1 alpha-2 obligatoire ; le reste décrit
     * l'entité locale (raison sociale, ville, identifiant du registre local —
     * ex. CUI roumain). Aucune PII : données d'identification d'entreprise.
     */
    private const IMPLANTATION_KEYS = ['country', 'name_local', 'city', 'registry_id'];

    private const PERSON_KEYS = [
        'first_name', 'last_name', 'role', 'email', 'phone', 'linkedin_url', 'kind',
    ];

    private const CHANNELS_KEYS = ['emails', 'phones'];

    private const EVIDENCE_KEYS = ['url', 'raw_ref'];

    /**
     * @param  array<string, mixed>  $matchHint
     * @param  array<string, string>  $companyFields
     * @param  list<array<string, string>>  $implantations
     * @param  list<array<string, string>>  $persons
     * @param  list<string>  $channelEmails
     * @param  list<string>  $channelPhones
     * @param  array<string, string>  $evidence
     */
    private function __construct(
        public readonly string $source,
        public readonly string $runId,
        public readonly ?DateTimeImmutable $fetchedAt,
        public readonly string $status,
        public readonly ?string $siren,
        public readonly ?string $foreignId,
        public readonly ?string $countryCode,
        public readonly ?string $entityNature,
        public readonly array $matchHint,
        public readonly array $companyFields,
        public readonly array $implantations,
        public readonly array $persons,
        public readonly array $channelEmails,
        public readonly array $channelPhones,
        public readonly array $evidence,
        public readonly ?int $confidence,
    ) {}

    /**
     * @param  array<mixed>  $raw
     *
     * @throws ScrapeIngestRejection
     */
    public static function fromArray(array $raw): self
    {
        self::assertOnlyKeys($raw, self::TOP_LEVEL_KEYS, 'racine');

        $version = $raw['schema_version'] ?? null;
        if ($version !== self::SCHEMA_VERSION) {
            throw ScrapeIngestRejection::invalid(
                'unsupported_schema_version',
                'Version de schéma pivot non supportée : ' . var_export($version, true) . ' (attendu : ' . self::SCHEMA_VERSION . ').',
            );
        }

        $source = self::requiredString($raw, 'source');
        $runId = self::requiredString($raw, 'run_id');

        $status = self::requiredString($raw, 'status');
        if (! in_array($status, self::STATUSES, true)) {
            throw ScrapeIngestRejection::invalid('invalid_status', "Statut inconnu : « {$status} ».");
        }

        $company = self::section($raw, 'company', self::COMPANY_KEYS);

        $siren = null;
        if (isset($company['siren'])) {
            if (! is_string($company['siren']) || preg_match('/^\d{9}$/', $company['siren']) !== 1) {
                throw ScrapeIngestRejection::invalid('invalid_siren', 'company.siren doit comporter exactement 9 chiffres.');
            }
            $siren = $company['siren'];
        }

        $matchHint = self::stringSection($company['match_hint'] ?? [], self::MATCH_HINT_KEYS, 'company.match_hint');
        $companyFields = self::stringSection($company['fields'] ?? [], self::COMPANY_FIELD_KEYS, 'company.fields');

        // ── Entités SANS SIREN (prospection internationale) ─────────────────
        // Le SIREN n'est pas un besoin métier : c'est une CLÉ DE DÉDUP. Une
        // entité étrangère porte la sienne (`foreign_id`), qui n'a de sens que
        // rapportée à un registre — d'où `country` obligatoire avec elle.
        $foreignId = null;
        if (isset($company['foreign_id'])) {
            if (! is_string($company['foreign_id']) || trim($company['foreign_id']) === '') {
                throw ScrapeIngestRejection::invalid('invalid_foreign_id', 'company.foreign_id doit être une chaîne non vide.');
            }
            $foreignId = trim($company['foreign_id']);
        }

        $countryCode = null;
        if (isset($company['country'])) {
            $candidate = is_string($company['country']) ? strtoupper(trim($company['country'])) : '';
            if (preg_match('/^[A-Z]{2}$/', $candidate) !== 1) {
                throw ScrapeIngestRejection::invalid(
                    'invalid_country',
                    'company.country doit être un code ISO 3166-1 alpha-2 (ex. RO).',
                );
            }
            $countryCode = $candidate;
        }

        if ($foreignId !== null && $countryCode === null) {
            throw ScrapeIngestRejection::invalid(
                'missing_country',
                'company.foreign_id exige company.country : un identifiant de registre sans son pays est indédupliquable.',
            );
        }

        // Un SIREN est français par définition : annoncer un autre pays avec
        // lui est une contradiction, pas une précision.
        if ($siren !== null && $countryCode !== null && $countryCode !== 'FR') {
            throw ScrapeIngestRejection::invalid(
                'country_siren_mismatch',
                "company.siren est français par définition : country « {$countryCode} » est contradictoire.",
            );
        }

        $entityNature = null;
        if (isset($company['nature'])) {
            $candidate = is_string($company['nature']) ? trim($company['nature']) : '';
            if (! in_array($candidate, self::ENTITY_NATURES, true)) {
                throw ScrapeIngestRejection::invalid(
                    'invalid_entity_nature',
                    'company.nature inconnue : « ' . $candidate . ' » (attendu : ' . implode(', ', self::ENTITY_NATURES) . ').',
                );
            }
            $entityNature = $candidate;
        }

        // Au moins UNE clé de rattachement : sans ancre ni indice, le funnel ne
        // pourrait qu'inventer un rattachement — refusé à la porte.
        if ($siren === null && $foreignId === null && $matchHint === []) {
            throw ScrapeIngestRejection::invalid(
                'missing_company_anchor',
                'company doit porter un siren, un foreign_id OU un match_hint (au moins une clé de rattachement).',
            );
        }

        $implantations = [];
        $rawImplantations = $company['implantations'] ?? [];
        if (! is_array($rawImplantations)) {
            throw ScrapeIngestRejection::invalid('invalid_type', '« company.implantations » doit être une liste.');
        }
        foreach ($rawImplantations as $i => $implantation) {
            if (! is_array($implantation)) {
                throw ScrapeIngestRejection::invalid('invalid_type', "« company.implantations[{$i}] » doit être un objet.");
            }
            $clean = self::stringSection($implantation, self::IMPLANTATION_KEYS, "company.implantations[{$i}]");
            $country = strtoupper($clean['country'] ?? '');
            if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
                throw ScrapeIngestRejection::invalid(
                    'invalid_implantation_country',
                    "company.implantations[{$i}].country doit être un code ISO 3166-1 alpha-2 (ex. RO).",
                );
            }
            $clean['country'] = $country;
            $implantations[] = $clean;
        }

        $persons = [];
        $rawPersons = $raw['persons'] ?? [];
        if (! is_array($rawPersons)) {
            throw ScrapeIngestRejection::invalid('invalid_type', '« persons » doit être une liste.');
        }
        foreach ($rawPersons as $i => $person) {
            if (! is_array($person)) {
                throw ScrapeIngestRejection::invalid('invalid_type', "« persons[{$i}] » doit être un objet.");
            }
            $clean = self::stringSection($person, self::PERSON_KEYS, "persons[{$i}]");
            $kind = $clean['kind'] ?? 'person';
            if (! in_array($kind, self::PERSON_KINDS, true)) {
                throw ScrapeIngestRejection::invalid('invalid_person_kind', "persons[{$i}].kind inconnu : « {$kind} ».");
            }
            $clean['kind'] = $kind;
            $persons[] = $clean;
        }

        $channels = self::section($raw, 'channels', self::CHANNELS_KEYS);

        return new self(
            source: $source,
            runId: $runId,
            fetchedAt: isset($raw['fetched_at']) && is_string($raw['fetched_at'])
                ? self::parseDate($raw['fetched_at'], 'fetched_at')
                : null,
            status: $status,
            siren: $siren,
            foreignId: $foreignId,
            countryCode: $countryCode,
            entityNature: $entityNature,
            matchHint: $matchHint,
            companyFields: $companyFields,
            implantations: $implantations,
            persons: $persons,
            channelEmails: self::stringList($channels['emails'] ?? [], 'channels.emails'),
            channelPhones: self::stringList($channels['phones'] ?? [], 'channels.phones'),
            evidence: self::stringSection($raw['evidence'] ?? [], self::EVIDENCE_KEYS, 'evidence'),
            confidence: self::confidence($raw['confidence'] ?? null),
        );
    }

    // ── Validation ──────────────────────────────────────────────────────────

    /**
     * @param  array<mixed>  $data
     * @param  list<string>  $allowed
     */
    private static function assertOnlyKeys(array $data, array $allowed, string $where): void
    {
        $unknown = array_diff(array_keys($data), $allowed);
        if ($unknown !== []) {
            throw ScrapeIngestRejection::invalid(
                'unknown_field',
                'Champ(s) inconnu(s) dans « ' . $where . ' » : ' . implode(', ', array_map('strval', $unknown)) . '.',
                ['unknown' => array_values(array_map('strval', $unknown))],
            );
        }
    }

    /**
     * @param  array<mixed>  $raw
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    private static function section(array $raw, string $key, array $allowed): array
    {
        // `?? []` couvre à la fois la clé absente et un `"company": null`
        // explicite (l'opérateur traite les deux comme null).
        $section = $raw[$key] ?? [];
        if (! is_array($section)) {
            throw ScrapeIngestRejection::invalid('invalid_type', "« {$key} » doit être un objet.");
        }
        self::assertOnlyKeys($section, $allowed, $key);

        /** @var array<string, mixed> $section */
        return $section;
    }

    /**
     * Section dont toutes les valeurs sont des chaînes non vides (trim), les
     * autres valeurs étant simplement omises.
     *
     * @param  list<string>  $allowed
     * @return array<string, string>
     */
    private static function stringSection(mixed $data, array $allowed, string $where): array
    {
        if ($data === null) {
            return [];
        }
        if (! is_array($data)) {
            throw ScrapeIngestRejection::invalid('invalid_type', "« {$where} » doit être un objet.");
        }
        self::assertOnlyKeys($data, $allowed, $where);

        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($value) && trim($value) !== '') {
                $clean[(string) $key] = trim($value);
            }
        }

        return $clean;
    }

    /** @return list<string> */
    private static function stringList(mixed $data, string $where): array
    {
        if ($data === null) {
            return [];
        }
        if (! is_array($data)) {
            throw ScrapeIngestRejection::invalid('invalid_type', "« {$where} » doit être une liste de chaînes.");
        }

        $clean = [];
        foreach ($data as $value) {
            if (is_string($value) && trim($value) !== '') {
                $clean[trim($value)] = true;
            }
        }

        return array_keys($clean);
    }

    /** @param  array<mixed>  $raw */
    private static function requiredString(array $raw, string $key): string
    {
        $value = $raw[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw ScrapeIngestRejection::invalid('missing_field', "Champ obligatoire manquant ou vide : « {$key} ».");
        }

        return trim($value);
    }

    private static function confidence(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value) || $value < 0 || $value > 100) {
            throw ScrapeIngestRejection::invalid('invalid_confidence', '« confidence » doit être un entier entre 0 et 100.');
        }

        return $value;
    }

    private static function parseDate(string $value, string $where): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            throw ScrapeIngestRejection::invalid('invalid_date', "« {$where} » n'est pas une date ISO 8601 valide.");
        }
    }
}
