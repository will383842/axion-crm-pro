<?php

namespace App\Crm\Ingest;

use App\Crm\Taxonomy;
use DateTimeImmutable;
use Exception;

/**
 * CONTRAT D'ENTRÉE de la synchro site → CRM, en un seul objet immuable.
 *
 * Règle du contrat : **schéma STRICT et versionné**. Toute clé inconnue est
 * REJETÉE, à tous les niveaux. C'est la même règle que celle posée pour le
 * pivot de scraping (audit scraping §C.1) et elle a une raison précise : un
 * champ inconnu accepté en silence, c'est une donnée qu'un système croit avoir
 * transmise et que l'autre a jetée — le pire des deux mondes (leçon IndexNow :
 * un agrégateur vert qui ne relaie rien).
 *
 * Ce que le payload ne contient JAMAIS :
 *   - le workspace de destination (décision du CRM, pas de l'appelant : sinon
 *     un émetteur compromis choisirait l'univers d'atterrissage, vivier
 *     compris) ;
 *   - de clé de chiffrement ni de jeton du site (les PII sont déchiffrées côté
 *     site au moment de l'envoi, le transport est TLS + signature HMAC) ;
 *   - de pièce jointe (le CV reste côté site, `cv_ref` n'est qu'une référence).
 */
final class SiteSyncEvent
{
    public const SCHEMA_VERSION = 1;

    /**
     * Types d'événements acceptés. Un type inconnu est refusé : ce sont les
     * 14 points de capture de l'audit §1.1, pas une liste ouverte.
     *
     * @var list<string>
     */
    public const EVENT_TYPES = [
        'form_submission',
        'calendly_booked',
        'calendly_completed',
        'calendly_canceled',
        'calendly_no_show',
        'newsletter_optin',
        'newsletter_optout',
        'review_posted',
        'application_submitted',
        'opt_out',
    ];

    /**
     * Types métier du formulaire unifié du site (`details.unifiedType`, 12
     * valeurs) + `podcast` et `simulateur_roi`, qui ont chacun leur table
     * dédiée côté site mais la même forme d'événement.
     *
     * 🔴 MIROIR EXACT de `CrmFormType` (site : `src/server/crm-sync/types.ts`).
     * Un type que le site émet et qui manque ici est refusé 422 : la ligne
     * d'outbox passe alors en `gave_up` et le lead n'arrive JAMAIS — c'est
     * exactement ce qui s'est produit avec `simulateur_roi`, ajouté ici après
     * relecture croisée des deux contrats. Les deux listes bougent ENSEMBLE,
     * et le test « chaque type de formulaire a un tag de provenance gouverné »
     * fait rougir tout ajout unilatéral.
     *
     * @var list<string>
     */
    public const FORM_TYPES = [
        'audit', 'implementation', 'formation', 'un_a_un', 'devis',
        'partenariat', 'presse', 'recrutement', 'speaker', 'investisseur',
        'support_client', 'autre', 'podcast', 'simulateur_roi',
    ];

    private const TOP_LEVEL_KEYS = [
        'schema_version', 'event_id', 'event_type', 'occurred_at', 'form_type',
        'source_slug', 'subject_ref', 'person', 'company', 'consent',
        'candidate', 'tags', 'payload',
    ];

    private const PERSON_KEYS = ['person_key', 'email', 'first_name', 'last_name', 'phone'];

    private const COMPANY_KEYS = ['siren', 'name', 'postcode', 'city', 'website', 'size_category', 'sector'];

    private const CONSENT_KEYS = ['version', 'at', 'text_ref', 'vivier_at'];

    private const CANDIDATE_KEYS = ['family', 'offer_slug', 'attributes', 'experiences', 'cv_ref'];

    /**
     * @param  array<string, mixed>  $person
     * @param  array<string, mixed>  $company
     * @param  array<string, mixed>  $consent
     * @param  array<string, mixed>  $candidate
     * @param  list<string>  $tags
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        public readonly string $eventId,
        public readonly string $eventType,
        public readonly DateTimeImmutable $occurredAt,
        public readonly ?string $formType,
        public readonly ?string $sourceSlug,
        public readonly string $subjectRef,
        public readonly array $person,
        public readonly array $company,
        public readonly array $consent,
        public readonly array $candidate,
        public readonly array $tags,
        public readonly array $payload,
    ) {}

    /**
     * @param  array<mixed>  $raw
     *
     * @throws SiteSyncRejection
     */
    public static function fromArray(array $raw): self
    {
        self::assertOnlyKeys($raw, self::TOP_LEVEL_KEYS, 'racine');

        $version = $raw['schema_version'] ?? null;
        if ($version !== self::SCHEMA_VERSION) {
            throw SiteSyncRejection::invalid(
                'unsupported_schema_version',
                'Version de schéma non supportée : ' . var_export($version, true) . ' (attendu : ' . self::SCHEMA_VERSION . ').',
            );
        }

        $eventId = self::requiredString($raw, 'event_id');
        if (mb_strlen($eventId) < 8 || mb_strlen($eventId) > 128) {
            throw SiteSyncRejection::invalid('invalid_event_id', 'event_id doit faire entre 8 et 128 caractères.');
        }

        $eventType = self::requiredString($raw, 'event_type');
        if (! in_array($eventType, self::EVENT_TYPES, true)) {
            throw SiteSyncRejection::invalid('unknown_event_type', "Type d'événement inconnu : « {$eventType} ».");
        }

        $subjectRef = self::requiredString($raw, 'subject_ref');
        if (! str_starts_with($subjectRef, 'site:')) {
            throw SiteSyncRejection::invalid('invalid_subject_ref', 'subject_ref doit être préfixé « site: » (ex. site:submission:<uuid>).');
        }

        $occurredAt = self::parseDate(self::requiredString($raw, 'occurred_at'), 'occurred_at');

        $formType = self::optionalString($raw, 'form_type');
        if ($eventType === 'form_submission' && $formType === null) {
            throw SiteSyncRejection::invalid('missing_form_type', 'form_type est obligatoire pour un event_type form_submission.');
        }
        if ($formType !== null && ! in_array($formType, self::FORM_TYPES, true)) {
            throw SiteSyncRejection::invalid('unknown_form_type', "Type de formulaire inconnu : « {$formType} ».");
        }

        $person = self::section($raw, 'person', self::PERSON_KEYS);
        $company = self::section($raw, 'company', self::COMPANY_KEYS);
        $consent = self::section($raw, 'consent', self::CONSENT_KEYS);
        $candidate = self::section($raw, 'candidate', self::CANDIDATE_KEYS);

        self::assertPerson($person);
        self::assertCompany($company);

        return new self(
            eventId: $eventId,
            eventType: $eventType,
            occurredAt: $occurredAt,
            formType: $formType,
            sourceSlug: self::optionalString($raw, 'source_slug'),
            subjectRef: $subjectRef,
            person: $person,
            company: $company,
            consent: $consent,
            candidate: $candidate,
            tags: self::assertTags($raw['tags'] ?? []),
            payload: self::assocArray($raw['payload'] ?? [], 'payload'),
        );
    }

    public function personKey(): string
    {
        return (string) $this->person['person_key'];
    }

    /** Email NORMALISÉ : `lower(trim())`, comme la clé personne du plan §2.4.1. */
    public function email(): ?string
    {
        $email = $this->person['email'] ?? null;

        return is_string($email) && $email !== '' ? mb_strtolower(trim($email)) : null;
    }

    /**
     * Hash de l'email normalisé, SANS sel : c'est la clé d'interrogation de la
     * liste d'opposition (`opt_out.email_hash`), qui doit pouvoir être calculée
     * par les deux systèmes indépendamment. À ne pas confondre avec
     * `person_key`, qui est salé côté site et sert au rapprochement des fiches.
     */
    public function emailHash(): ?string
    {
        $email = $this->email();

        return $email === null ? null : hash('sha256', $email);
    }

    public function siren(): ?string
    {
        $siren = $this->company['siren'] ?? null;

        return is_string($siren) && preg_match('/^\d{9}$/', $siren) === 1 ? $siren : null;
    }

    public function consentVersion(): ?string
    {
        $v = $this->consent['version'] ?? null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    public function consentAt(): ?DateTimeImmutable
    {
        $at = $this->consent['at'] ?? null;

        return is_string($at) && $at !== '' ? self::parseDate($at, 'consent.at') : null;
    }

    public function consentVivierAt(): ?DateTimeImmutable
    {
        $at = $this->consent['vivier_at'] ?? null;

        return is_string($at) && $at !== '' ? self::parseDate($at, 'consent.vivier_at') : null;
    }

    public function candidateFamily(): ?string
    {
        $family = $this->candidate['family'] ?? null;

        return is_string($family) && $family !== '' ? $family : null;
    }

    /**
     * Lecture typée d'une chaîne non vide dans une section du payload.
     * `$section` ∈ person | company | consent | candidate | payload.
     */
    public function str(string $section, string $key): ?string
    {
        $data = match ($section) {
            'person' => $this->person,
            'company' => $this->company,
            'consent' => $this->consent,
            'candidate' => $this->candidate,
            'payload' => $this->payload,
            default => [],
        };

        $value = $data[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
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
            throw SiteSyncRejection::invalid(
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
        $section = self::assocArray($raw[$key] ?? [], $key);
        self::assertOnlyKeys($section, $allowed, $key);

        return $section;
    }

    /** @return array<string, mixed> */
    private static function assocArray(mixed $value, string $where): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value)) {
            throw SiteSyncRejection::invalid('invalid_type', "« {$where} » doit être un objet.");
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @param  array<string, mixed>  $person */
    private static function assertPerson(array $person): void
    {
        $key = $person['person_key'] ?? null;
        if (! is_string($key) || preg_match('/^[0-9a-f]{64}$/', $key) !== 1) {
            throw SiteSyncRejection::invalid(
                'invalid_person_key',
                'person.person_key est obligatoire et doit être un sha256 hexadécimal de 64 caractères (calculé côté site AVANT chiffrement).',
            );
        }

        $email = $person['email'] ?? null;
        if ($email !== null && (! is_string($email) || ! str_contains($email, '@'))) {
            throw SiteSyncRejection::invalid('invalid_email', 'person.email doit être une adresse électronique.');
        }
    }

    /** @param  array<string, mixed>  $company */
    private static function assertCompany(array $company): void
    {
        $siren = $company['siren'] ?? null;
        if ($siren !== null && (! is_string($siren) || preg_match('/^\d{9}$/', $siren) !== 1)) {
            throw SiteSyncRejection::invalid('invalid_siren', 'company.siren doit comporter exactement 9 chiffres.');
        }
    }

    /**
     * Les tags reçus doivent être NAMESPACÉS et le namespace doit appartenir au
     * référentiel gouverné. Un tag orphelin de namespace est refusé : c'est la
     * seule façon de rester lisible à 100 000+ fiches (plan §2.2c).
     *
     * @return list<string>
     */
    private static function assertTags(mixed $tags): array
    {
        if ($tags === null) {
            return [];
        }
        if (! is_array($tags)) {
            throw SiteSyncRejection::invalid('invalid_type', '« tags » doit être une liste de chaînes.');
        }

        $clean = [];
        foreach ($tags as $tag) {
            if (! is_string($tag) || preg_match('/^[a-z0-9-]+:[a-z0-9._-]+$/', $tag) !== 1) {
                throw SiteSyncRejection::invalid(
                    'invalid_tag',
                    'Tag mal formé : ' . var_export($tag, true) . ' (attendu : namespace:valeur, minuscules, sans accent).',
                );
            }
            $namespace = explode(':', $tag, 2)[0];
            if (! array_key_exists($namespace, Taxonomy::TAG_NAMESPACES)) {
                throw SiteSyncRejection::invalid(
                    'ungoverned_tag_namespace',
                    "Namespace de tag hors référentiel gouverné : « {$namespace} ».",
                );
            }
            $clean[$tag] = $tag;
        }

        return array_values($clean);
    }

    /** @param  array<mixed>  $raw */
    private static function requiredString(array $raw, string $key): string
    {
        $value = $raw[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw SiteSyncRejection::invalid('missing_field', "Champ obligatoire manquant ou vide : « {$key} ».");
        }

        return trim($value);
    }

    /** @param  array<mixed>  $raw */
    private static function optionalString(array $raw, string $key): ?string
    {
        $value = $raw[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw SiteSyncRejection::invalid('invalid_type', "« {$key} » doit être une chaîne.");
        }

        return trim($value) === '' ? null : trim($value);
    }

    private static function parseDate(string $value, string $where): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            throw SiteSyncRejection::invalid('invalid_date', "« {$where} » n'est pas une date ISO 8601 valide.");
        }
    }
}
