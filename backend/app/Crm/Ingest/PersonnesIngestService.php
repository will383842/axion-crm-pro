<?php

namespace App\Crm\Ingest;

use App\Crm\Personnes\NatureEmail;
use App\Crm\Taxonomy;
use App\Support\ListeSuppression;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * BRANCHE « PERSONNES » DE L'INGESTION — la lettre et le guide (lot L4-C).
 *
 * Appelée par `SiteSyncIngestService::apply()` APRÈS le contrôle d'idempotence
 * et AVANT `upsertBusiness`, dans la même transaction et sous le même contexte
 * d'espace. Elle ne s'applique qu'aux quatre types de
 * `Taxonomy::PERSONNES_EVENT_TYPES`, et seulement drapeau ouvert
 * (`crm.ingest.personnes_enabled`). Drapeau fermé : aucune lecture ni
 * écriture de `personnes` ou `abonnements`, nulle part (ni ici, ni dans
 * `ContactUpserter`, ni sur une opposition générale) — c'est le retour
 * arrière, et c'est ce qui rend sûre la fenêtre de déploiement où le nouveau
 * code tourne avant la migration.
 *
 * ── Quel `newsletter_optout` est un désabonnement de la LETTRE ──────────────
 *
 * Le site émet `newsletter_optout` depuis DEUX endroits :
 *   - le lien de désinscription de la lettre (`newsletter/actions.ts`,
 *     `subject_ref = site:newsletter_subscriber:<id>`) → retrait du CANAL ;
 *   - l'opposition GÉNÉRALE à toute sollicitation (`email/opposition.ts`,
 *     `subject_ref = site:email_opposition:<id>`) : lien présent dans tous les
 *     e-mails, oppositions reçues au téléphone et saisies en console. C'est
 *     l'art. 21, et la prospection humaine ne lit QUE l'opposition `business`.
 * Seul le premier est pris ici ; tout autre `subject_ref` garde le chemin
 * historique (opposition `business` + désabonnement de tous les canaux). Liste
 * fermée : un nouveau chemin de désinscription du site retombe du côté
 * PROTECTEUR tant qu'il n'est pas ajouté à `PREFIXES_DESABONNEMENT_LETTRE`.
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
    /**
     * `subject_ref` d'un désabonnement de la LETTRE seule. Tout autre
     * `newsletter_optout` est une opposition générale (voir l'en-tête).
     *
     * @var list<string>
     */
    public const PREFIXES_DESABONNEMENT_LETTRE = ['site:newsletter_subscriber:'];

    /**
     * Bases légales qu'une INSCRIPTION à la lettre peut porter (amendement de
     * Will du 2026-09-24) : consentement (adresse personnelle, case cochée) ou
     * intérêt légitime B2B (adresse professionnelle, inscription à la demande
     * du guide). Lue dans `payload.base_legale` ; absente → `consent`, qui est
     * le format actuel du site (double opt-in).
     *
     * @var list<string>
     */
    public const BASES_LEGALES_INSCRIPTION = Taxonomy::ABONNEMENT_LEGAL_BASES;

    /**
     * Clés de `payload` recopiées dans la timeline. Liste FERMÉE : si le site
     * ajoute un jour une adresse ou une IP dans `payload`, elle n'entre pas en
     * clair dans `activities`.
     *
     * @var list<string>
     */
    public const CLES_PAYLOAD_CONSIGNEES = [
        'source', 'placement', 'locale', 'reason', 'base_legale', 'email_nature',
        'aimant', 'edition', 'verifie',
    ];

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

    /**
     * FICHE DEMANDE (lot C1) : types d'événement dont une demande SANS SIREN
     * fait une personne. Liste FERMÉE et explicite : jamais `opt_out`,
     * `newsletter_optout` ni `review_posted` (une opposition ou un avis ne
     * crée pas de fiche), jamais un futur `calendly_*` non listé ici.
     *
     * @var list<string>
     */
    public const DEMANDE_EVENT_TYPES = [
        'form_submission',
        'calendly_booked',
        'calendly_completed',
        'calendly_no_show',
        'calendly_canceled',
    ];

    /**
     * Types de formulaire qui ne font JAMAIS une personne par ce chemin (même
     * liste que la fiche console du site) : ce ne sont pas des demandes
     * commerciales. Le recrutement est déjà écarté par l'univers vivier.
     *
     * @var list<string>
     */
    public const FORMULAIRES_EXCLUS = ['partenariat', 'presse', 'speaker', 'investisseur', 'support_client', 'recrutement'];

    /** Mot qui, dans le nom du type d'événement Calendly, désigne un échange apporteur. */
    private const MOT_CLE_ECHANGE_APPORTEUR = 'apporteur';

    /** Fiche demande : drapeau fermé par défaut, indépendant de la lettre et du guide. */
    public static function drapeauDemandesOuvert(): bool
    {
        return filter_var(config('crm.ingest.demandes_personne_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Cet événement, privé de SIREN, doit-il faire une personne ?
     *
     * AUCUNE requête avant d'avoir répondu : drapeau fermé, on rend `false`
     * sans toucher à la base (retour arrière et fenêtre de déploiement).
     *
     * Jamais pour :
     *  - un autre univers que `business` (candidats : pas de fiche ici) ;
     *  - un formulaire de recrutement ;
     *  - un échange apporteur (nom du type d'événement Calendly, même règle
     *    que le site : le site ne l'envoie pas, le CRM le refuse aussi) ;
     *  - un événement sans adresse (rien n'identifie la personne).
     */
    public function concerneUneDemande(SiteSyncEvent $event, string $universe): bool
    {
        return self::drapeauDemandesOuvert() && $this->estUneDemande($event, $universe);
    }

    /**
     * Les mêmes règles que `concerneUneDemande`, SANS le drapeau : le
     * rattrapage (`crm:personnes-depuis-demandes`) est un geste délibéré, il ne
     * dépend pas de l'ouverture du flux en direct. Aucune requête.
     */
    public function estUneDemande(SiteSyncEvent $event, string $universe): bool
    {
        if ($universe !== 'business' || ! in_array($event->eventType, self::DEMANDE_EVENT_TYPES, true)) {
            return false;
        }
        if ($event->eventType === 'form_submission' && in_array($event->formType, self::FORMULAIRES_EXCLUS, true)) {
            return false;
        }
        if (self::estEchangeApporteur($event)) {
            return false;
        }

        return $event->email() !== null;
    }

    /** Vrai si le type d'événement Calendly est un échange avec un candidat apporteur. */
    public static function estEchangeApporteur(SiteSyncEvent $event): bool
    {
        $nom = $event->payload['eventTypeName'] ?? null;
        if (! is_string($nom) || $nom === '') {
            return false;
        }

        return str_contains(Str::lower(Str::ascii($nom)), self::MOT_CLE_ECHANGE_APPORTEUR);
    }

    public const DEMANDE_A_CREER = 'a_creer';

    public const DEMANDE_EXISTANTE = 'existante';

    public const DEMANDE_ECARTEE = 'ecartee';

    /**
     * Ce que ferait `assurerDepuisDemande`, SANS RIEN ÉCRIRE : une personne à
     * créer, une personne déjà là, ou une demande écartée (opposition
     * générale reçue, ou personne mise à l'écart). Sert au rattrapage à blanc.
     */
    public function evaluerDemande(SiteSyncEvent $event, string $workspaceId): string
    {
        $hash = $event->emailHash();
        if ($hash !== null && $this->opposee($hash, 'business')) {
            return self::DEMANDE_ECARTEE;
        }

        $existante = $this->trouver($workspaceId, $event);
        if ($existante === null) {
            return self::DEMANDE_A_CREER;
        }

        return ($existante->opposition_at ?? null) !== null ? self::DEMANDE_ECARTEE : self::DEMANDE_EXISTANTE;
    }

    /**
     * Une demande SANS SIREN fait (ou retrouve) une PERSONNE.
     *
     * Ne rend JAMAIS un sujet, exprès : l'appelant garde son retour
     * `PENDING_MATCH` sans sujet. Un sujet non nul ferait écrire à
     * `attachTags` un id de personne dans `company_tag`. La valeur rendue est
     * l'ÉTAT (`DEMANDE_*`), que l'appelant en direct ignore.
     *
     * Ce que cette méthode ne fait JAMAIS : abonnement (donc jamais éligible à
     * une diffusion), opposition, suppression, écrasement d'une valeur saisie
     * à la main, nom fabriqué depuis l'adresse. Une personne mise à l'écart
     * n'est pas touchée.
     *
     * @throws SiteSyncRejection 503 si les colonnes de la migration manquent :
     *                           la ligne reste en attente côté site.
     */
    public function assurerDepuisDemande(SiteSyncEvent $event, string $workspaceId): string
    {
        if (! self::colonnesDemandePresentes()) {
            throw SiteSyncRejection::unavailable(
                'demandes_personne_non_ecrivables',
                'Fiche demande : colonnes absentes (migration non passée). La ligne reste en attente côté site.',
            );
        }

        $etat = $this->evaluerDemande($event, $workspaceId);
        if ($etat === self::DEMANDE_ECARTEE) {
            return $etat;
        }

        // Un numéro déjà opposé (anti-réinsertion, variantes comprises) n'est
        // jamais enregistré ; la personne, elle, reste.
        $telephone = $this->borne($event->str('person', 'phone'), 40);
        if ($telephone !== null && $this->telephoneOppose($telephone)) {
            $telephone = null;
        }

        $this->upsertPersonne($event, $workspaceId, $this->trouver($workspaceId, $event), true, null, [
            'phone' => $telephone,
            'entreprise_declaree' => $this->borne($event->str('company', 'name'), 200),
        ]);

        return $etat;
    }

    /** Opposition (univers business) sur ce numéro, quelle que soit son écriture. */
    private function telephoneOppose(string $telephone): bool
    {
        $variantes = ListeSuppression::variantesTelephone($telephone);
        if ($variantes === []) {
            return false;
        }

        return DB::table('opt_out')
            ->where('scope', 'business')
            ->whereRaw(
                "regexp_replace(phone, '[^0-9]', '', 'g') IN (" . implode(', ', array_fill(0, count($variantes), '?')) . ')',
                $variantes,
            )
            ->exists();
    }

    /** Seul un résultat POSITIF est gardé : un worker démarré avant `migrate` ne reste pas aveugle. */
    public static function colonnesDemandePresentes(): bool
    {
        static $presentes = false;

        return $presentes = $presentes
            || (Schema::hasColumn('personnes', 'phone') && Schema::hasColumn('personnes', 'entreprise_declaree'));
    }

    private function borne(?string $valeur, int $max): ?string
    {
        return $valeur === null ? null : mb_substr($valeur, 0, $max);
    }

    public function prendEnCharge(SiteSyncEvent $event): bool
    {
        if (! self::drapeauOuvert() || ! in_array($event->eventType, Taxonomy::PERSONNES_EVENT_TYPES, true)) {
            return false;
        }

        // Une opposition GÉNÉRALE voyage aussi en `newsletter_optout` : elle
        // n'est pas à nous (voir l'en-tête).
        return $event->eventType !== 'newsletter_optout' || self::estDesabonnementLettre($event);
    }

    public static function estDesabonnementLettre(SiteSyncEvent $event): bool
    {
        foreach (self::PREFIXES_DESABONNEMENT_LETTRE as $prefixe) {
            if (str_starts_with($event->subjectRef, $prefixe)) {
                return true;
            }
        }

        return false;
    }

    public function ingerer(SiteSyncEvent $event, string $workspaceId, string $activityRef): IngestOutcome
    {
        $accords = $event->contenusAccords;

        // PV-6 : l'opposition reçue gagne AVANT tout le reste — elle est
        // inscrite à la liste d'opposition d'abord, donc l'inscription qui suit
        // dans le même événement la rencontre et s'arrête.
        if ($accords !== null && $accords->oppose()) {
            $this->poserOpposition($event, $workspaceId);
        }

        $outcome = match ($event->eventType) {
            'email_hard_bounced' => $this->rebondDur($event, $workspaceId, $activityRef),
            'newsletter_optout' => $this->desabonnement($event, $workspaceId, $activityRef),
            default => $this->inscriptionOuDemande($event, $workspaceId, $activityRef),
        };

        if ($accords !== null && self::colonnesAccordsPresentes()) {
            $this->enregistrerAccords($event, $workspaceId, $accords, $outcome);
        }

        return $outcome;
    }

    /** Vrai si la migration PV-6 est passée (fenêtre de déploiement : sinon, on ignore les champs). */
    private static function colonnesAccordsPresentes(): bool
    {
        // Seul un résultat POSITIF est gardé : un worker démarré avant `migrate`
        // ne doit pas ignorer les champs pour toujours.
        static $presentes = false;

        return $presentes = $presentes || Schema::hasColumn('personnes', 'accords_at');
    }

    /**
     * Opposition reçue par la synchro : liste d'opposition `business` (empreinte
     * seule, jamais l'adresse) et désabonnement de tous les canaux. Aucune donnée
     * n'est supprimée. Une opposition déjà posée n'est jamais levée.
     */
    private function poserOpposition(SiteSyncEvent $event, string $workspaceId): void
    {
        $hash = $event->emailHash();
        if ($hash === null) {
            return;
        }

        if (! $this->opposee($hash, 'business')) {
            DB::table('opt_out')->insert([
                'email' => null,
                'email_hash' => $hash,
                'scope' => 'business',
                'source' => 'site-sync:accords',
                'reason' => 'opposition_synchro',
                'created_at' => now(),
            ]);
        }

        $this->desabonnerSurOpposition($workspaceId, $hash, $event->occurredAt, 'opposition_generale');
    }

    /**
     * Stockage ADDITIF et règles de la synchro : listes en union (rien ne se
     * retire), origine la plus récente, opposition posée une fois pour toutes.
     */
    private function enregistrerAccords(SiteSyncEvent $event, string $workspaceId, ContenusAccords $accords, IngestOutcome $outcome): void
    {
        $personne = $outcome->subjectType === 'personne' && $outcome->subjectId !== null
            ? DB::table('personnes')->where('id', $outcome->subjectId)->first()
            : $this->trouver($workspaceId, $event);

        if ($personne === null) {
            return;
        }

        $update = [];

        $update['origine_contenu'] = $this->json(ContenusAccords::fusionnerOrigine(
            $this->decoder($personne->origine_contenu ?? null),
            $this->date($personne->accords_at ?? null),
            $accords->origine,
            $event->occurredAt,
        ));
        if ($accords->origine !== null) {
            $deja = $this->date($personne->accords_at ?? null);
            if ($deja === null || $this->plusRecent($event->occurredAt, $deja)) {
                $update['accords_at'] = $event->occurredAt;
            }
        }

        if ($accords->guides !== null) {
            $update['guides_recus'] = $this->json(ContenusAccords::fusionnerListes($this->decoder($personne->guides_recus ?? null) ?? [], $accords->guides));
        }
        if ($accords->consentements !== null) {
            $update['consentements'] = $this->json(ContenusAccords::fusionnerListes($this->decoder($personne->consentements ?? null) ?? [], $accords->consentements));
        }

        if ($accords->oppose() && ($personne->opposition_at ?? null) === null) {
            $dateSite = ($accords->opposition['date'] ?? null) ? new DateTimeImmutable((string) $accords->opposition['date']) : $event->occurredAt;
            $maintenant = new DateTimeImmutable('now');
            // Une date future n'a pas de sens : bornée à maintenant.
            $update['opposition_at'] = $dateSite > $maintenant ? $maintenant : $dateSite;
        }

        DB::table('personnes')->where('id', $personne->id)->update($update + ['updated_at' => now()]);

        if ($accords->origines !== null) {
            $this->enregistrerOrigines((int) $personne->id, $workspaceId, $accords->origines);
        }
    }

    /**
     * Contrat v3 : union SANS RETRAIT de l'historique des origines. L'écriture
     * de l'archive et la réduction du tableau se font dans UNE transaction,
     * sous verrou de ligne (ADR I4) : une entrée n'est retirée du tableau que
     * si elle est déjà dans l'archive. Rien n'est jamais supprimé.
     *
     * @param  list<array<string, string>>  $entrantes
     */
    private function enregistrerOrigines(int $personneId, string $workspaceId, array $entrantes): void
    {
        // Jamais d'abandon silencieux : une charge qui porte des origines et qui
        // ne peut pas les écrire n'est PAS acquittée. `assurerOriginesEcrivables()`
        // le refuse en amont (503, la charge reste en attente côté site) ; ce
        // garde-fou refuse de même, dans la transaction, si l'état a changé entre
        // les deux (interrupteur éteint ou migration retirée en cours de route).
        $this->assurerOriginesEcrivablesOuRefuser();

        DB::transaction(function () use ($personneId, $workspaceId, $entrantes): void {
            $ligne = DB::table('personnes')->where('id', $personneId)->lockForUpdate()->first(['origines']);
            if ($ligne === null) {
                return;
            }

            $fusion = ContenusAccords::fusionnerOrigines($this->decoder($ligne->origines ?? null) ?? [], $entrantes);

            $archiveAvant = $fusion['archive'] === [] ? 0 : DB::table('personne_origines_archive')->where('personne_id', $personneId)->count();

            foreach ($fusion['archive'] as $entree) {
                DB::table('personne_origines_archive')->insertOrIgnore([
                    'workspace_id' => $workspaceId,
                    'personne_id' => $personneId,
                    'cle' => ContenusAccords::cleOrigine($entree),
                    'origine' => $this->json($entree),
                    'archive_le' => now(),
                    'masque_le' => $entree['masque_le'] ?? null,
                ]);
            }

            DB::table('personnes')->where('id', $personneId)->update([
                'origines' => $this->json($fusion['garde']),
            ]);

            // ADR I3 : l'archive d'une personne dépasse 200 entrées → alerte (une fois).
            if ($fusion['archive'] !== []) {
                AlerteArchiveOrigines::siFranchi(
                    $workspaceId,
                    $personneId,
                    $archiveAvant,
                    DB::table('personne_origines_archive')->where('personne_id', $personneId)->count(),
                );
            }
        });
    }

    /**
     * Vrai si une charge qui porte des `origines` peut les ÉCRIRE : interrupteur
     * v3 allumé, branche « personnes » ouverte pour ce type d'événement, colonne
     * `origines`, colonnes d'accords et table d'archive présentes.
     */
    public function originesEcrivables(SiteSyncEvent $event): bool
    {
        return $this->prendEnCharge($event) && $this->stockageOriginesPresent();
    }

    /**
     * Le site garde sa ligne tant qu'il ne reçoit pas de 2xx : une charge avec
     * des `origines` qu'on ne peut pas écrire reçoit donc un 503 (code sans
     * valeur reçue), jamais un accusé de réception qui les perdrait en silence.
     * Sans `origines` (v1/v2, ou liste vide), rien n'est exigé : inchangé.
     *
     * @throws SiteSyncRejection
     */
    public function assurerOriginesEcrivables(SiteSyncEvent $event): void
    {
        $origines = $event->contenusAccords?->origines;
        if ($origines === null || $origines === [] || ! (bool) config('origines.v3_enabled', false)) {
            return;
        }
        if (! $this->originesEcrivables($event)) {
            throw $this->refusOriginesNonEcrivables();
        }
    }

    private function assurerOriginesEcrivablesOuRefuser(): void
    {
        if (! (bool) config('origines.v3_enabled', false) || ! $this->stockageOriginesPresent()) {
            throw $this->refusOriginesNonEcrivables();
        }
    }

    private function refusOriginesNonEcrivables(): SiteSyncRejection
    {
        return SiteSyncRejection::unavailable(
            'origines_v3_non_ecrivables',
            'Contrat v3 de l\'origine : stockage indisponible, la charge reste en attente côté site.',
        );
    }

    private function stockageOriginesPresent(): bool
    {
        return Schema::hasColumn('personnes', 'origines')
            && Schema::hasColumn('personnes', 'accords_at')
            && Schema::hasTable('personne_origines_archive');
    }

    private function json(mixed $valeur): ?string
    {
        return $valeur === null ? null : json_encode($valeur, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<mixed>|null */
    private function decoder(mixed $brut): ?array
    {
        if (is_array($brut)) {
            return $brut;
        }
        if (! is_string($brut) || $brut === '') {
            return null;
        }
        $d = json_decode($brut, true);

        return is_array($d) ? $d : null;
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
        $inscription = $event->eventType === 'newsletter_optin';
        $abonnement = $existante === null ? null : $this->abonnement((int) $existante->id);
        $base = $this->baseLegaleInscription($event);

        if ($inscription && $hash !== null) {
            // Une opposition de CANAL ne compte que lorsqu'aucun abonnement ne
            // porte déjà l'histoire : s'il existe, c'est sa garde de désordre qui
            // tranche (un désabonnement plus récent l'emporte, une réinscription
            // plus récente aussi — le site a recueilli un NOUVEAU consentement
            // par double opt-in). Sans abonnement, l'opposition n'est levée que
            // par un consentement postérieur à elle.
            if ($abonnement === null && $this->opposeeAuCanalDepuis($hash, $event->consentAt() ?? $event->occurredAt)) {
                return new IngestOutcome(status: IngestOutcome::OPTED_OUT);
            }

            // L'intérêt légitime ne lève JAMAIS une opposition : quelqu'un qui
            // s'est désabonné de la lettre n'y revient que par un
            // CONSENTEMENT (art. 21 : l'opposition met fin au traitement fondé
            // sur l'intérêt légitime).
            if ($base === 'legitimate_interest_b2b' && $this->opposee($hash, 'lettre')) {
                return new IngestOutcome(status: IngestOutcome::OPTED_OUT);
            }
        }

        // Une inscription PLUS ANCIENNE qu'un désabonnement déjà appliqué ne
        // réabonne pas (garde de désordre) — et ne remet pas non plus
        // l'adresse en clair sur une fiche créée sans elle par ce
        // désabonnement. Elle est consignée, c'est tout.
        if ($inscription && $existante !== null && $abonnement !== null
            && ($abonnement->statut ?? null) === 'desabonne'
            && ! $this->plusRecent($event->occurredAt, $abonnement->dernier_evenement_at ?? null)) {
            return new IngestOutcome(
                status: IngestOutcome::UPDATED,
                subjectType: 'personne',
                subjectId: (int) $existante->id,
                activityId: $this->consigner($event, $workspaceId, $existante, $activityRef),
            );
        }

        // Adresse PERSONNELLE sans consentement : jamais inscrite (amendement
        // de Will, L.34-5 CPCE). Le site le décide ; le CRM le vérifie aussi,
        // et une seule des deux listes qui dit « perso » suffit.
        $refusee = $inscription && $base === 'legitimate_interest_b2b' && $this->estPerso($event);

        [$personne, $creee] = $this->upsertPersonne(
            $event,
            $workspaceId,
            $existante,
            true,
            // Refusée ou non, une inscription porte SA base (l'intérêt légitime
            // quand elle est refusée) : jamais le `consent` par défaut du
            // classificateur pour quelqu'un qui n'a rien coché.
            $inscription ? $base : null,
        );

        if ($inscription && ! $refusee) {
            $this->appliquerAbonnement($event, $workspaceId, $personne, 'abonne');
        }

        $activityId = $this->consigner(
            $event,
            $workspaceId,
            $personne,
            $activityRef,
            $refusee ? 'Inscription à la lettre refusée : adresse personnelle sans consentement' : null,
        );

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
        // quelqu'un qui s'est désabonné. Mais il la crée SANS ADRESSE NI NOM :
        // la clé du site et l'empreinte suffisent à la garde de désordre, et on
        // ne conserve pas en clair l'adresse de quelqu'un qui vient de dire
        // stop (l'information du site ne parle que d'une liste d'opposition).
        [$personne, $creee] = $this->upsertPersonne($event, $workspaceId, $this->trouver($workspaceId, $event), false);

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
     * @param  array<string, ?string>  $demande  fiche demande : `phone` et `entreprise_declaree`
     *                                           déclarés ([] hors fiche demande : colonnes jamais touchées)
     * @return array{0: \stdClass, 1: bool} [la personne relue, créée ?]
     */
    private function upsertPersonne(
        SiteSyncEvent $event,
        string $workspaceId,
        ?\stdClass $existante,
        bool $avecIdentite = true,
        ?string $baseInscription = null,
        array $demande = [],
    ): array {
        // Sans identité (désabonnement) : ni adresse ni nom ne sont écrits.
        $email = $avecIdentite ? $event->email() : null;
        $firstName = $avecIdentite ? $event->str('person', 'first_name') : null;
        $lastName = $avecIdentite ? $event->str('person', 'last_name') : null;
        // Une inscription porte SA base (consentement ou intérêt légitime B2B) ;
        // les autres événements, celle du classificateur.
        $legalBasis = $baseInscription ?? $this->classifier->legalBasis($event);
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
                ['email' => $email, 'first_name' => $firstName, 'last_name' => $lastName] + $demande,
                static fn (?string $v): bool => $v !== null,
            ));

            $id = (int) DB::table('personnes')->insertGetId($demande + [
                'workspace_id' => $workspaceId,
                'person_key' => $event->personKey(),
                'email' => $email,
                'email_hash' => $event->emailHash(),
                'email_nature' => $email === null ? 'inconnue' : $this->nature($event),
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

        $update = ['updated_at' => now()];

        // N5 : une demande ne change JAMAIS la base légale d'une personne
        // EXISTANTE (seule une personne créée par une demande est
        // précontractuelle). Hors demande, la base monte de rang, jamais ne
        // descend, avec UNE exception : une personne née d'une demande
        // (précontractuelle, donc hors prospection tant qu'elle n'est pas
        // abonnée) qui demande ensuite le guide prend la base du guide
        // (intérêt légitime B2B), comme tout demandeur du guide. Rien de plus :
        // l'adresse personnelle reste exclue de toute prospection sans
        // abonnement (`Abonnements::prospectionAutorisee`).
        if ($demande === []) {
            $actuelle = is_string($existante->legal_basis ?? null) ? $existante->legal_basis : null;
            $update['legal_basis'] = $actuelle === 'precontractual' && $legalBasis === 'legitimate_interest_b2b'
                ? $legalBasis
                : $this->fusionnerBaseLegale($actuelle, $legalBasis);
        }

        $derniere = $this->date($existante->derniere_interaction_at ?? null);
        if ($derniere === null || $event->occurredAt > $derniere) {
            $update['derniere_interaction_at'] = $event->occurredAt;
        }
        // Garde de date (À LA SECONDE, voir `plusRecent` : l'égalité n'est pas « plus
        // récent ») : un événement pas plus récent que la dernière interaction connue ne remplace pas une valeur
        // déjà connue ; il ne fait que combler un vide.
        $ancien = $derniere !== null && ! $this->plusRecent($event->occurredAt, $existante->derniere_interaction_at ?? null);

        // Une personne retrouvée par son adresse reçoit la clé du site : c'est
        // elle qui rattache ensuite la timeline et l'effacement.
        if ($demande === [] && ($existante->person_key ?? null) !== $event->personKey()) {
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
                $update['email_nature'] = $this->nature($event);
                $origins['email'] = 'declared';
            }
        }

        // Le DÉCLARÉ gagne ; l'absence d'un nom n'efface jamais un nom connu.
        foreach (['first_name' => $firstName, 'last_name' => $lastName] as $colonne => $valeur) {
            // Chemin des demandes (formulaire public, rejoué par le rattrapage de
            // la plus ancienne à la plus récente) : un nom déjà connu n'est
            // JAMAIS remplacé par celui d'un formulaire.
            if ($demande !== [] && ($existante->{$colonne} ?? null) !== null) {
                continue;
            }
            // CRM-G1 — guide-réseau uniquement : un prénom reçu ne remplace le prénom
            // connu que si l'événement est plus récent (à la seconde) que la dernière
            // interaction enregistrée, ou si le prénom connu est vide. Les autres
            // sources gardent leur comportement.
            if ($colonne === 'first_name' && $this->estGuideReseau($event)
                && ($existante->first_name ?? null) !== null && $ancien) {
                continue;
            }
            if ($valeur !== null) {
                $update[$colonne] = $valeur;
                $origins[$colonne] = 'declared';
            }
        }

        // Fiche demande : une valeur saisie À LA MAIN (ou par un autre chemin
        // que la déclaration) n'est jamais écrasée ; seule une valeur absente
        // ou elle-même déclarée est remplacée.
        foreach ($demande as $colonne => $valeur) {
            if ($valeur === null) {
                continue;
            }
            if (($existante->{$colonne} ?? null) === null || (($origins[$colonne] ?? null) === 'declared' && ! $ancien)) {
                $update[$colonne] = $valeur;
                $origins[$colonne] = 'declared';
            }
        }

        if ($locale !== null && ($demande === [] || ! $ancien || ($existante->locale ?? null) === null)) {
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

        $base = $this->baseLegaleInscription($event);

        $champs = $statut === 'abonne'
            ? [
                'statut' => 'abonne',
                // Déjà abonné : on ne redescend jamais (consentement > intérêt
                // légitime). Réinscription après un désabonnement : la base de
                // CETTE inscription, puisque l'ancienne a pris fin.
                'legal_basis' => $existant !== null && ($existant->statut ?? null) === 'abonne'
                    ? $this->fusionnerBaseLegale(is_string($existant->legal_basis ?? null) ? $existant->legal_basis : null, $base)
                    : $base,
                'consent_version' => $event->consentVersion(),
                // Un consentement se date ; une inscription par intérêt légitime
                // n'a pas de date de consentement à inventer.
                'consent_at' => $event->consentAt() ?? ($base === 'consent' ? $at : null),
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

        if (! $this->plusRecent($at, $existant->dernier_evenement_at ?? null)) {
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

    private function consigner(SiteSyncEvent $event, string $workspaceId, ?\stdClass $personne, string $activityRef, ?string $titre = null): int
    {
        $payload = array_intersect_key($event->payload, array_flip(self::CLES_PAYLOAD_CONSIGNEES));
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
            'title' => $titre ?? match ($event->eventType) {
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
        // Jamais `newsletter` par défaut pour une demande : une personne née
        // d'un formulaire ou d'un rendez-vous n'a rien demandé à la lettre.
        return $event->sourceSlug ?? match (true) {
            $event->eventType === 'lead_magnet_requested' => 'guide-ia',
            $event->eventType === 'form_submission' => 'formulaire',
            str_starts_with($event->eventType, 'calendly_') => 'rendez-vous',
            default => 'newsletter',
        };
    }

    /** Placement d'origine : `payload.placement` (futur) ou `payload.source` (actuel). */
    private function placement(SiteSyncEvent $event): ?string
    {
        return $event->str('payload', 'placement') ?? $event->str('payload', 'source') ?? $event->sourceSlug;
    }

    /**
     * Base légale d'une inscription : `payload.base_legale` en liste fermée,
     * `consent` par défaut (format actuel du site : double opt-in).
     */
    private function baseLegaleInscription(SiteSyncEvent $event): string
    {
        $declaree = $event->str('payload', 'base_legale');

        return in_array($declaree, self::BASES_LEGALES_INSCRIPTION, true) ? (string) $declaree : 'consent';
    }

    /**
     * Nature de l'adresse : celle que le SITE a décidée (`payload.email_nature`,
     * amendement de Will : nature « décidée côté serveur » du site) si elle est
     * valide, sinon celle de `NatureEmail`.
     */
    private function nature(SiteSyncEvent $event): string
    {
        $declaree = $event->str('payload', 'email_nature');
        if (in_array($declaree, ['pro', 'perso'], true)) {
            return (string) $declaree;
        }

        return NatureEmail::de($event->email());
    }

    /** Vrai si le site OU la liste du CRM dit « perso » : le doute protège. */
    private function estPerso(SiteSyncEvent $event): bool
    {
        return $this->nature($event) === 'perso' || NatureEmail::de($event->email()) === 'perso';
    }

    /**
     * À la SECONDE : l'horodatage est persisté à la seconde pleine (format
     * d'écriture de Laravel), alors que le site émet des millisecondes.
     * Comparer l'instant brut ferait passer pour « plus récent » un événement
     * plus ancien de la même seconde.
     */
    /**
     * Contrat réel du site (axion-ia, `guides-reseau/synchro-v3.ts` l.147 et l.230 :
     * `source_slug = "guide-reseau"` sur l'événement de la page ; l.126 : l'événement
     * d'origines n'a PAS de `source_slug` mais un `subject_ref` en `site:guide-reseau:`).
     * Les deux marques reconnaissent la même famille d'événements.
     */
    private function estGuideReseau(SiteSyncEvent $event): bool
    {
        return $event->sourceSlug === 'guide-reseau'
            || str_starts_with($event->subjectRef, 'site:guide-reseau:');
    }

    private function plusRecent(DateTimeImmutable $at, mixed $dernier): bool
    {
        $dernier = $this->date($dernier);

        return $dernier === null || (int) $at->format('U') > (int) $dernier->format('U');
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
