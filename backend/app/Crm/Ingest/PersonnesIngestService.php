<?php

namespace App\Crm\Ingest;

use App\Crm\Personnes\NatureEmail;
use App\Crm\Taxonomy;
use App\Support\ListeSuppression;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * BRANCHE « PERSONNES » DE L'INGESTION — la lettre et le guide (lot L4-C).
 *
 * Appelée par `SiteSyncIngestService::apply()` APRÈS le contrôle d'idempotence
 * et AVANT `upsertBusiness`, dans la même transaction et sous le même contexte
 * d'espace. Elle ne s'applique qu'aux quatre types de
 * `Taxonomy::PERSONNES_EVENT_TYPES`, et seulement drapeau ouvert
 * (`crm.ingest.personnes_enabled`). Drapeau fermé : comportement d'avant,
 * au bit près — c'est le retour arrière.
 *
 * ── Ce qu'elle fait que le chemin historique ne faisait pas ─────────────────
 *
 *   - la personne EXISTE sans SIREN ni nom (table `personnes`) ;
 *   - l'événement est rattaché à elle dans la timeline
 *     (`subject_type = 'personne'`) : JAMAIS de `pending_match`, donc jamais
 *     l'adresse en clair dans la file d'arbitrage ;
 *   - le consentement vit sur l'ABONNEMENT de la personne, plus sur une
 *     entreprise ;
 *   - se désabonner inscrit une opposition de CANAL (`lettre`), plus une
 *     opposition `business` qui fermait le CRM à la personne pour toujours.
 *
 * ── Deux formats de `newsletter_optin` acceptés ─────────────────────────────
 *
 * Le site émet aujourd'hui `occurred_at = maintenant`, sans `source_slug`, avec
 * un `payload.source` éventuel. Il émettra demain `occurred_at = confirmedAt`,
 * `source_slug = "newsletter"` et `payload.placement` / `payload.locale`. Les
 * deux donnent une personne : le contrat d'entrée n'a pas changé, seules des
 * valeurs facultatives diffèrent. Le test « format actuel du site » le garde.
 *
 * ── Ce qu'elle ne fait JAMAIS ───────────────────────────────────────────────
 *
 *   - fabriquer un nom depuis une adresse ;
 *   - écrire un consentement sur une entreprise ;
 *   - créer une personne pour un simple rebond (l'adresse n'a jamais cliqué) ;
 *   - journaliser une adresse : identifiants et comptes seulement.
 */
final class PersonnesIngestService
{
    /** Rang de protection des bases légales : on ne redescend jamais. */
    private const RANG_BASE_LEGALE = [
        'legitimate_interest_b2b' => 0,
        'precontractual' => 1,
        'consent' => 2,
        'contract' => 3,
        'legal_obligation' => 3,
    ];

    public function __construct(private readonly SiteSyncClassifier $classifier) {}

    public static function drapeauOuvert(): bool
    {
        return filter_var(config('crm.ingest.personnes_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    public function prendEnCharge(SiteSyncEvent $event): bool
    {
        return self::drapeauOuvert() && in_array($event->eventType, Taxonomy::PERSONNES_EVENT_TYPES, true);
    }

    public function ingerer(SiteSyncEvent $event, string $workspaceId, string $activityRef): IngestOutcome
    {
        return match ($event->eventType) {
            'email_hard_bounced' => $this->rebondDur($event, $workspaceId, $activityRef),
            'newsletter_optout' => $this->desabonnement($event, $workspaceId, $activityRef),
            default => $this->inscriptionOuDemande($event, $workspaceId, $activityRef),
        };
    }

    /**
     * Opposition GÉNÉRALE (art. 21) : tous les abonnements de la personne
     * passent en `desabonne`. Appelée par l'ingestion d'un `opt_out` business
     * et par le refus d'une inscription pour cause d'opposition.
     */
    public function desabonnerSurOpposition(string $workspaceId, string $emailHash, DateTimeImmutable $at, string $motif): int
    {
        $personnes = DB::table('personnes')
            ->where('workspace_id', $workspaceId)
            ->where('email_hash', $emailHash)
            ->pluck('id')
            ->all();

        if ($personnes === []) {
            return 0;
        }

        return DB::table('abonnements')
            ->where('workspace_id', $workspaceId)
            ->whereIn('personne_id', $personnes)
            ->where('statut', 'abonne')
            ->update([
                'statut' => 'desabonne',
                'desabonne_at' => $at,
                'motif_desabonnement' => $motif,
                'updated_at' => now(),
            ]);
    }

    // ── Les trois familles ──────────────────────────────────────────────────

    private function inscriptionOuDemande(SiteSyncEvent $event, string $workspaceId, string $activityRef): IngestOutcome
    {
        $hash = $event->emailHash();

        // Une opposition GÉNÉRALE bloque tout, l'inscription à la lettre comme
        // la demande du guide. Elle survit à l'effacement : c'est
        // l'anti-réinsertion.
        if ($hash !== null && $this->opposee($hash, 'business')) {
            $this->desabonnerSurOpposition($workspaceId, $hash, $event->occurredAt, 'opposition_generale');

            return new IngestOutcome(status: IngestOutcome::OPTED_OUT);
        }

        $existante = $this->trouver($workspaceId, $event);

        if ($event->eventType === 'newsletter_optin' && $hash !== null) {
            $abonnement = $existante === null ? null : $this->abonnement((int) $existante->id);

            // Une opposition de CANAL ne compte que lorsqu'aucun abonnement ne
            // porte déjà l'histoire : s'il existe, c'est sa garde de désordre qui
            // tranche (un désabonnement plus récent l'emporte, une réinscription
            // plus récente aussi — le site a recueilli un NOUVEAU consentement
            // par double opt-in). Sans abonnement, l'opposition n'est levée que
            // par un consentement postérieur à elle.
            if ($abonnement === null && $this->opposeeAuCanalDepuis($hash, $event->consentAt() ?? $event->occurredAt)) {
                return new IngestOutcome(status: IngestOutcome::OPTED_OUT);
            }
        }

        [$personne, $creee] = $this->upsertPersonne($event, $workspaceId, $existante);

        if ($event->eventType === 'newsletter_optin') {
            $this->appliquerAbonnement($event, $workspaceId, $personne, 'abonne');
        }

        $activityId = $this->consigner($event, $workspaceId, $personne, $activityRef);

        return new IngestOutcome(
            status: $creee ? IngestOutcome::CREATED : IngestOutcome::UPDATED,
            subjectType: 'personne',
            subjectId: (int) $personne->id,
            activityId: $activityId,
        );
    }

    private function desabonnement(SiteSyncEvent $event, string $workspaceId, string $activityRef): IngestOutcome
    {
        // Un désabonnement n'est jamais bloqué : c'est un retrait, il s'applique
        // toujours. Il CRÉE la personne si besoin — sans elle, l'inscription plus
        // ancienne qu'on retente ensuite (backoff du site) réabonnerait
        // quelqu'un qui s'est désabonné.
        [$personne, $creee] = $this->upsertPersonne($event, $workspaceId, $this->trouver($workspaceId, $event));

        $this->appliquerAbonnement($event, $workspaceId, $personne, 'desabonne');

        $hash = $event->emailHash();
        if ($hash !== null && ! $this->opposee($hash, 'lettre')) {
            DB::table('opt_out')->insert([
                // JAMAIS l'adresse en clair : l'empreinte suffit.
                'email' => null,
                'email_hash' => $hash,
                'scope' => 'lettre',
                'source' => 'site-sync:newsletter_optout',
                'reason' => $event->str('payload', 'reason'),
                'created_at' => now(),
            ]);
        }

        $activityId = $this->consigner($event, $workspaceId, $personne, $activityRef);

        return new IngestOutcome(
            status: $creee ? IngestOutcome::CREATED : IngestOutcome::UPDATED,
            subjectType: 'personne',
            subjectId: (int) $personne->id,
            activityId: $activityId,
        );
    }

    /**
     * Rebond dur : un FAIT technique, pas une volonté. Il entre dans la liste
     * de suppression (`email_suppressions`, empreinte seule) et s'inscrit dans
     * la timeline de la personne si elle existe. Il ne crée JAMAIS de personne :
     * une adresse qui rebondit n'a, par définition, jamais cliqué — et c'est le
     * clic qui fait entrer un demandeur au CRM (décision D1).
     */
    private function rebondDur(SiteSyncEvent $event, string $workspaceId, string $activityRef): IngestOutcome
    {
        $email = $event->email();
        if ($email !== null) {
            ListeSuppression::inscrire(
                $email,
                ListeSuppression::REBOND_DUR,
                'site-sync:email_hard_bounced',
                'business',
                ['event_id' => $event->eventId],
            );
        }

        $personne = $this->trouver($workspaceId, $event);

        $activityId = $this->consigner($event, $workspaceId, $personne, $activityRef);

        return new IngestOutcome(
            status: IngestOutcome::UPDATED,
            subjectType: $personne === null ? null : 'personne',
            subjectId: $personne === null ? null : (int) $personne->id,
            activityId: $activityId,
        );
    }

    // ── Personne ────────────────────────────────────────────────────────────

    private function trouver(string $workspaceId, SiteSyncEvent $event): ?\stdClass
    {
        $personne = DB::table('personnes')
            ->where('workspace_id', $workspaceId)
            ->where('person_key', $event->personKey())
            ->first();

        $email = $event->email();
        if ($personne === null && $email !== null) {
            // `personnes.email` est en `citext` : pas de `lower()`, qui
            // condamnerait l'index (leçon C21-001).
            $personne = DB::table('personnes')
                ->where('workspace_id', $workspaceId)
                ->where('email', $email)
                ->first();
        }

        return $personne;
    }

    /**
     * @return array{0: \stdClass, 1: bool} [la personne relue, créée ?]
     */
    private function upsertPersonne(SiteSyncEvent $event, string $workspaceId, ?\stdClass $existante): array
    {
        $email = $event->email();
        $firstName = $event->str('person', 'first_name');
        $lastName = $event->str('person', 'last_name');
        $legalBasis = $this->classifier->legalBasis($event);
        $locale = $this->locale($event);

        // RATTACHEMENT AUTOMATIQUE, dans l'autre sens : la personne arrive
        // APRÈS sa fiche contact (un formulaire avec SIREN d'abord, la lettre
        // ensuite). `ContactUpserter` couvre l'ordre inverse.
        $contact = DB::table('contacts')
            ->where('workspace_id', $workspaceId)
            ->where('person_key', $event->personKey())
            // Une fiche en corbeille ne reçoit pas de rattachement.
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->first(['id', 'company_id']);

        if ($existante === null) {
            $declares = array_keys(array_filter(
                ['email' => $email, 'first_name' => $firstName, 'last_name' => $lastName],
                static fn (?string $v): bool => $v !== null,
            ));

            $id = (int) DB::table('personnes')->insertGetId([
                'workspace_id' => $workspaceId,
                'person_key' => $event->personKey(),
                'email' => $email,
                'email_hash' => $event->emailHash(),
                'email_nature' => NatureEmail::de($email),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'locale' => $locale,
                'premiere_source' => $this->source($event),
                'premiere_source_at' => $event->occurredAt,
                'derniere_interaction_at' => $event->occurredAt,
                'legal_basis' => $legalBasis,
                'external_ref' => $event->subjectRef,
                'contact_id' => $contact === null ? null : (int) $contact->id,
                'company_id' => $contact === null ? null : (int) $contact->company_id,
                'rattachee_at' => $contact === null ? null : now(),
                'field_origins' => json_encode(array_fill_keys($declares, 'declared') ?: new \stdClass, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [$this->relire($id), true];
        }

        $update = [
            'legal_basis' => $this->fusionnerBaseLegale(
                is_string($existante->legal_basis ?? null) ? $existante->legal_basis : null,
                $legalBasis,
            ),
            'updated_at' => now(),
        ];

        $derniere = $this->date($existante->derniere_interaction_at ?? null);
        if ($derniere === null || $event->occurredAt > $derniere) {
            $update['derniere_interaction_at'] = $event->occurredAt;
        }

        // Une personne retrouvée par son adresse reçoit la clé du site : c'est
        // elle qui rattache ensuite la timeline et l'effacement.
        if (($existante->person_key ?? null) !== $event->personKey()) {
            $collision = DB::table('personnes')
                ->where('workspace_id', $workspaceId)
                ->where('person_key', $event->personKey())
                ->where('id', '!=', $existante->id)
                ->exists();
            if (! $collision) {
                $update['person_key'] = $event->personKey();
            }
        }

        $origins = $this->decoderOrigines($existante->field_origins ?? null);

        if ($email !== null && ($existante->email ?? null) === null) {
            $prise = DB::table('personnes')
                ->where('workspace_id', $workspaceId)
                ->where('email', $email)
                ->where('id', '!=', $existante->id)
                ->exists();
            if (! $prise) {
                $update['email'] = $email;
                $update['email_hash'] = $event->emailHash();
                $update['email_nature'] = NatureEmail::de($email);
                $origins['email'] = 'declared';
            }
        }

        // Le DÉCLARÉ gagne ; l'absence d'un nom n'efface jamais un nom connu.
        foreach (['first_name' => $firstName, 'last_name' => $lastName] as $colonne => $valeur) {
            if ($valeur !== null) {
                $update[$colonne] = $valeur;
                $origins[$colonne] = 'declared';
            }
        }

        if ($locale !== null) {
            $update['locale'] = $locale;
        }

        if ($contact !== null && ($existante->contact_id ?? null) === null) {
            $update['contact_id'] = (int) $contact->id;
            $update['company_id'] = (int) $contact->company_id;
            $update['rattachee_at'] = now();
        }

        $update['field_origins'] = json_encode($origins ?: new \stdClass, JSON_THROW_ON_ERROR);

        DB::table('personnes')->where('id', $existante->id)->update($update);

        return [$this->relire((int) $existante->id), false];
    }

    private function relire(int $id): \stdClass
    {
        $personne = DB::table('personnes')->where('id', $id)->first();

        if ($personne === null) {
            // Sous RLS, une ligne qu'on vient d'écrire et qu'on ne relit pas
            // signale un contexte d'espace absent : on échoue BRUYAMMENT (500,
            // l'outbox du site rejouera) plutôt que de solder la ligne à vide.
            throw new \RuntimeException('Personne introuvable juste après son écriture : contexte d’espace absent ?');
        }

        return $personne;
    }

    // ── Abonnement ──────────────────────────────────────────────────────────

    private function abonnement(int $personneId): ?\stdClass
    {
        return DB::table('abonnements')
            ->where('personne_id', $personneId)
            ->where('canal', 'lettre')
            ->first();
    }

    /**
     * GARDE DE DÉSORDRE — le cœur de ce service.
     *
     * Le statut ne change que si l'événement est STRICTEMENT plus récent que le
     * dernier appliqué. Sinon il est consigné dans la timeline, et c'est tout :
     * une inscription de T1 rejouée après un désabonnement de T2 ne réabonne
     * pas. Un test la neutralise pour prouver qu'il rougit (preuve par la
     * rougeur, cf. la PR).
     */
    private function appliquerAbonnement(SiteSyncEvent $event, string $workspaceId, \stdClass $personne, string $statut): void
    {
        $existant = $this->abonnement((int) $personne->id);
        $at = $event->occurredAt;

        $champs = $statut === 'abonne'
            ? [
                'statut' => 'abonne',
                'consent_version' => $event->consentVersion(),
                'consent_at' => $event->consentAt() ?? $at,
                'consent_text_ref' => $event->str('consent', 'text_ref'),
                'abonne_at' => $at,
                'desabonne_at' => null,
                'motif_desabonnement' => null,
            ]
            : [
                'statut' => 'desabonne',
                'desabonne_at' => $at,
                'motif_desabonnement' => $event->str('payload', 'reason') ?? 'desabonnement',
            ];

        if ($existant === null) {
            DB::table('abonnements')->insert(array_merge([
                'workspace_id' => $workspaceId,
                'personne_id' => (int) $personne->id,
                'canal' => 'lettre',
                'source_slug' => $this->placement($event),
                'dernier_evenement_at' => $at,
                'identifiants_externes' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ], $champs));

            return;
        }

        // À la SECONDE : l'horodatage est persisté à la seconde pleine (format
        // d'écriture de Laravel), alors que le site émet des millisecondes.
        // Comparer l'instant brut ferait passer pour « plus récent » un
        // événement plus ancien de la même seconde.
        $dernier = $this->date($existant->dernier_evenement_at ?? null);
        if ($dernier !== null && (int) $at->format('U') <= (int) $dernier->format('U')) {
            return;
        }

        $update = array_merge($champs, [
            'dernier_evenement_at' => $at,
            'updated_at' => now(),
        ]);
        if ($statut === 'abonne' && ($existant->source_slug ?? null) === null) {
            $update['source_slug'] = $this->placement($event);
        }

        DB::table('abonnements')->where('id', $existant->id)->update($update);
    }

    // ── Oppositions ─────────────────────────────────────────────────────────

    private function opposee(string $emailHash, string $scope): bool
    {
        // Table GLOBALE, scopée par univers ou canal — pas par espace.
        return DB::table('opt_out')
            ->where('scope', $scope)
            ->where('email_hash', $emailHash)
            ->exists();
    }

    private function opposeeAuCanalDepuis(string $emailHash, DateTimeImmutable $consentAt): bool
    {
        return DB::table('opt_out')
            ->where('scope', 'lettre')
            ->where('email_hash', $emailHash)
            ->where('created_at', '>=', $consentAt)
            ->exists();
    }

    // ── Timeline ────────────────────────────────────────────────────────────

    private function consigner(SiteSyncEvent $event, string $workspaceId, ?\stdClass $personne, string $activityRef): int
    {
        $payload = $event->payload;
        $payload['source_slug'] = $event->sourceSlug;
        $payload['subject_ref'] = $event->subjectRef;
        // Jamais de `pending_match` ici : c'est lui qui faisait entrer
        // l'adresse en clair dans la file d'arbitrage.

        $contactId = $personne !== null && is_numeric($personne->contact_id ?? null) ? (int) $personne->contact_id : null;

        return (int) DB::table('activities')->insertGetId([
            'workspace_id' => $workspaceId,
            'contact_id' => $contactId,
            'type' => $this->classifier->activityKind($event),
            'kind' => $this->classifier->activityKind($event),
            'occurred_at' => $event->occurredAt,
            'person_key' => $event->personKey(),
            'external_ref' => $activityRef,
            'subject_type' => $personne === null ? null : 'personne',
            'subject_id' => $personne === null ? null : (int) $personne->id,
            'title' => match ($event->eventType) {
                'newsletter_optin' => 'Inscription à la lettre',
                'newsletter_optout' => 'Désabonnement de la lettre',
                'lead_magnet_requested' => 'Guide IA entreprise téléchargé',
                'email_hard_bounced' => 'Rebond dur',
                default => str_replace('_', ' ', $event->eventType),
            },
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    // ── Utilitaires ─────────────────────────────────────────────────────────

    /**
     * Provenance PREMIÈRE : `source_slug` (format futur du site), sinon déduite
     * du type — l'inscription d'aujourd'hui n'envoie aucun `source_slug`.
     */
    private function source(SiteSyncEvent $event): string
    {
        return $event->sourceSlug ?? match ($event->eventType) {
            'lead_magnet_requested' => 'guide-ia',
            default => 'newsletter',
        };
    }

    /** Placement d'origine : `payload.placement` (futur) ou `payload.source` (actuel). */
    private function placement(SiteSyncEvent $event): ?string
    {
        return $event->str('payload', 'placement') ?? $event->str('payload', 'source') ?? $event->sourceSlug;
    }

    private function locale(SiteSyncEvent $event): ?string
    {
        $locale = $event->str('payload', 'locale');

        return in_array($locale, ['fr', 'en'], true) ? $locale : null;
    }

    private function fusionnerBaseLegale(?string $actuelle, string $entrante): string
    {
        if ($actuelle === null || ! array_key_exists($actuelle, self::RANG_BASE_LEGALE)) {
            return $entrante;
        }

        return (self::RANG_BASE_LEGALE[$entrante] ?? 0) > self::RANG_BASE_LEGALE[$actuelle] ? $entrante : $actuelle;
    }

    private function date(mixed $valeur): ?DateTimeImmutable
    {
        if (! is_string($valeur) || trim($valeur) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($valeur);
        } catch (\Exception) {
            return null;
        }
    }

    /** @return array<string, string> */
    private function decoderOrigines(mixed $brut): array
    {
        if (! is_string($brut) || $brut === '') {
            return [];
        }

        $decode = json_decode($brut, true);
        if (! is_array($decode)) {
            return [];
        }

        $origines = [];
        foreach ($decode as $cle => $valeur) {
            if (is_string($cle) && is_string($valeur)) {
                $origines[$cle] = $valeur;
            }
        }

        return $origines;
    }
}
