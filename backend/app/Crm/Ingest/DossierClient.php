<?php

namespace App\Crm\Ingest;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * CONTRAT du DOSSIER CLIENT envoyé par la console du site (25/10/2026).
 *
 * Décision de Will (25/10) : la synchro console → CRM est AUTOMATIQUE, sans
 * clic, à chaque changement de la fiche côté console. Le CRM n'envoie aucun
 * e-mail : la synchro est interne. Le traitement vit dans
 * `DossierClientIngest`, ce fichier ne fait que VÉRIFIER.
 *
 * ── Enveloppe (`POST /api/internal/site-sync`, schéma v1) ──────────────────
 *   event_type   = "dossier_client_synchro"
 *   event_id     = identifiant unique et STABLE du message (8 à 128 car.) —
 *                  rejouer le même event_id ne fait rien (idempotence)
 *   occurred_at  = date ISO 8601 du changement côté console (sert à « le
 *                  plus récent gagne »)
 *   subject_ref  = "site:client:<uuid du client dans la console>"
 *   company      = { siren?, name?, postcode?, city? } — RIEN d'autre
 *   person, consent, candidate : ABSENTS ou vides ; tags : absents ou [] ;
 *   form_type, source_slug : absents
 *   payload      = { "dossier": { … } } — RIEN d'autre
 *
 * ── `payload.dossier` (liste FERMÉE, toute autre clé = 422) ────────────────
 *   version          entier ≥ 1, OBLIGATOIRE, croissant par client : un
 *                    message de version ≤ à la dernière appliquée est ignoré
 *   statut           "prospect" | "client", OBLIGATOIRE
 *   facture_emise    booléen, OBLIGATOIRE — « un prospect devient client
 *                    lorsqu'une facture a été établie » (Will, 25/10) :
 *                    statut = "client" SI ET SEULEMENT SI facture_emise = true.
 *                    Jamais de montant ni de numéro de facture.
 *   rue              texte ≤ 255 (adresse, ligne rue)
 *   telephone        texte ≤ 32 (standard de l'entreprise)
 *   axi_cli          "AXI-CLI-<chiffres>"
 *   effectif_tranche texte ≤ 32 (tranche déclarée, ex. « 10-19 »)
 *   url_console      URL https de la fiche dans la console (≤ 500)
 *   questionnaire    { envoye_le?: ISO 8601|null, recu_le?: ISO 8601|null,
 *                      nb_reponses_equipe?: entier ≥ 0|null } — l'ÉTAT
 *                    seulement, jamais une réponse
 *   contacts         liste (≤ 50) de { ref (obligatoire, ≤ 64, identifiant
 *                    stable du contact dans la console), prenom?, nom?,
 *                    email?, telephone?, fonction?, person_key? (sha256 hex),
 *                    role_decision? }
 *
 * ── Fiche complète (29/10/2026) : clés FACULTATIVES, listes FERMÉES ────────
 *   Le CRM accepte d'abord, la console enverra ensuite. Une clé ABSENTE ou
 *   `null` n'a AUCUN effet (la valeur déjà gardée reste) : une ancienne version
 *   de la console reste acceptée telle quelle. Rien d'autre que des états, des
 *   dates et des nombres — jamais un montant, jamais un texte de note.
 *   etape            "prospect" | "devis_envoye" | "client" | "archive" —
 *                    gardée dans `metadata.console` ; ne change JAMAIS
 *                    `lifecycle_stage` (c'est `facture_emise` + le drapeau
 *                    `CRM_SYNC_DOSSIER_CLIENT_STAGE` qui décident) ; « archive »
 *                    pose seulement `archive_reason = manual` (rien n'est supprimé)
 *   cloture_prevue   date AAAA-MM-JJ
 *   prochaine_action { type: "relance" | "rappel" | "envoi_devis" |
 *                    "rendez_vous" | "autre" (obligatoire), date? } — pas de texte
 *   taches_ouvertes  { nb: entier ≥ 0 (obligatoire), prochaine_le? }
 *   nb_emails_console, nb_notes_console   entiers ≥ 0 (des nombres, pas des textes)
 *   sante            "bonne" | "a_surveiller" | "a_risque"
 *   questionnaire.attendus     entier de 1 à 1000 (personnes attendues) ; hors
 *                    bornes ou non entier = 422 `invalid_type`, comme les
 *                    autres nombres du questionnaire ; absent ou null = sans effet
 *   questionnaire.date_limite  date
 *   contacts[].role_decision   "decideur" | "financeur" | "influenceur" |
 *                    "utilisateur" | "referent" | "bloqueur" | "autre"
 *   Les dates se donnent en AAAA-MM-JJ (ou en ISO 8601, ramenée au jour).
 *   Un contact « parti » n'a pas de clé : il n'est ni accepté ni créé.
 *
 * Une clé ABSENTE ou `null` ne change rien côté CRM : AUCUNE suppression ne se
 * propage (ni d'un champ, ni d'un contact, ni de la fiche).
 *
 * ── Clés REFUSÉES partout (422 `forbidden_field`) ──────────────────────────
 * Le texte des réponses au questionnaire (dirigeant ou équipe) et les montants
 * ne traversent JAMAIS le canal : `CLES_INTERDITES`, cherchées à toute
 * profondeur de `payload`, avant même la liste fermée — pour que le refus dise
 * POURQUOI.
 */
final class DossierClient
{
    public const EVENT_TYPE = 'dossier_client_synchro';

    public const STATUT_PROSPECT = 'prospect';

    public const STATUT_CLIENT = 'client';

    public const MAX_CONTACTS = 50;

    public const ETAPES = ['prospect', 'devis_envoye', 'client', 'archive'];

    public const ACTIONS = ['relance', 'rappel', 'envoi_devis', 'rendez_vous', 'autre'];

    public const SANTES = ['bonne', 'a_surveiller', 'a_risque'];

    public const ROLES_DECISION = ['decideur', 'financeur', 'influenceur', 'utilisateur', 'referent', 'bloqueur', 'autre'];

    private const CLES_DOSSIER = [
        'version', 'statut', 'facture_emise', 'rue', 'telephone', 'axi_cli',
        'effectif_tranche', 'url_console', 'questionnaire', 'contacts',
        'etape', 'cloture_prevue', 'prochaine_action', 'taches_ouvertes',
        'nb_emails_console', 'nb_notes_console', 'sante',
    ];

    private const CLES_QUESTIONNAIRE = ['envoye_le', 'recu_le', 'nb_reponses_equipe', 'attendus', 'date_limite'];

    private const CLES_CONTACT = ['ref', 'prenom', 'nom', 'email', 'telephone', 'fonction', 'person_key', 'role_decision'];

    private const CLES_ENTREPRISE = ['siren', 'name', 'postcode', 'city'];

    /**
     * Refusées à toute profondeur : réponses du questionnaire, texte libre,
     * montants, numéros de pièce.
     *
     * @var list<string>
     */
    public const CLES_INTERDITES = [
        'reponse', 'reponses', 'reponses_equipe', 'reponses_dirigeant', 'texte',
        'texte_libre', 'commentaire', 'commentaires', 'notes', 'donnees',
        'montant', 'montant_ht', 'montant_ttc', 'prix', 'tarif', 'total',
        'ca', 'chiffre_affaires', 'numero_facture', 'facture_numero',
        'numero_devis', 'devis', 'outils',
    ];

    /**
     * @param  list<array{ref: string, prenom: ?string, nom: ?string, email: ?string, telephone: ?string, fonction: ?string, person_key: ?string, role_decision: ?string}>  $contacts
     * @param  array<string, mixed>  $complements  fiche complète : SEULEMENT les clés reçues et non nulles
     */
    private function __construct(
        public readonly string $clientUuid,
        public readonly int $version,
        public readonly string $statut,
        public readonly bool $factureEmise,
        public readonly ?string $rue,
        public readonly ?string $telephone,
        public readonly ?string $axiCli,
        public readonly ?string $effectifTranche,
        public readonly ?string $urlConsole,
        public readonly ?string $questionnaireEnvoyeLe,
        public readonly ?string $questionnaireRecuLe,
        public readonly ?int $nbReponsesEquipe,
        public readonly array $contacts,
        public readonly array $complements = [],
    ) {}

    /**
     * @param  array<mixed>  $raw
     * @param  array<string, mixed>  $person
     * @param  array<string, mixed>  $company
     * @param  array<string, mixed>  $consent
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $payload
     *
     * @throws SiteSyncRejection
     */
    public static function depuisEvenement(
        string $subjectRef,
        ?string $formType,
        array $raw,
        array $person,
        array $company,
        array $consent,
        array $candidate,
        array $payload,
    ): self {
        if (preg_match('/^site:client:([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/', $subjectRef, $m) !== 1) {
            throw SiteSyncRejection::invalid('invalid_subject_ref', 'Dossier client : subject_ref doit valoir « site:client:<uuid en minuscules> ».');
        }

        self::refuserClesInterdites($payload, 'payload');

        if ($person !== [] || $consent !== [] || $candidate !== []) {
            throw SiteSyncRejection::invalid('invalid_dossier', 'Dossier client : « person », « consent » et « candidate » doivent être absents (les interlocuteurs voyagent dans payload.dossier.contacts).');
        }
        if ($formType !== null || ($raw['source_slug'] ?? null) !== null || ($raw['tags'] ?? []) !== []) {
            throw SiteSyncRejection::invalid('invalid_dossier', 'Dossier client : « form_type », « source_slug » et « tags » doivent être absents.');
        }
        self::seulement($company, self::CLES_ENTREPRISE, 'company');
        foreach (['name' => 255, 'postcode' => 10, 'city' => 255] as $cle => $max) {
            self::texte($company, $cle, $max, 'company');
        }

        self::seulement($payload, ['dossier'], 'payload');
        $dossier = $payload['dossier'] ?? null;
        if (! is_array($dossier) || array_is_list($dossier) && $dossier !== []) {
            throw SiteSyncRejection::invalid('invalid_dossier', 'Dossier client : payload.dossier est obligatoire et doit être un objet.');
        }
        self::seulement($dossier, self::CLES_DOSSIER, 'payload.dossier');

        $version = $dossier['version'] ?? null;
        if (! is_int($version) || $version < 1) {
            throw SiteSyncRejection::invalid('invalid_dossier_version', 'Dossier client : payload.dossier.version doit être un entier ≥ 1.');
        }

        $statut = $dossier['statut'] ?? null;
        if (! in_array($statut, [self::STATUT_PROSPECT, self::STATUT_CLIENT], true)) {
            throw SiteSyncRejection::invalid('invalid_dossier_statut', 'Dossier client : payload.dossier.statut doit valoir « prospect » ou « client ».');
        }
        $facture = $dossier['facture_emise'] ?? null;
        if (! is_bool($facture)) {
            throw SiteSyncRejection::invalid('invalid_dossier_facture', 'Dossier client : payload.dossier.facture_emise est obligatoire (booléen).');
        }
        // « Un prospect devient client lorsqu'une facture a été établie »
        // (Will, 25/10) : les deux champs doivent dire la même chose.
        if (($statut === self::STATUT_CLIENT) !== $facture) {
            throw SiteSyncRejection::invalid('dossier_statut_incoherent', 'Dossier client : statut « client » si et seulement si facture_emise = true.');
        }

        $axiCli = self::texte($dossier, 'axi_cli', 32, 'payload.dossier');
        if ($axiCli !== null && preg_match('/^AXI-CLI-\d{1,10}$/', $axiCli) !== 1) {
            throw SiteSyncRejection::invalid('invalid_axi_cli', 'Dossier client : axi_cli doit être de la forme « AXI-CLI-<chiffres> ».');
        }
        $url = self::texte($dossier, 'url_console', 500, 'payload.dossier');
        if ($url !== null && (! str_starts_with($url, 'https://') || filter_var($url, FILTER_VALIDATE_URL) === false)) {
            throw SiteSyncRejection::invalid('invalid_url_console', 'Dossier client : url_console doit être une adresse https.');
        }

        [$envoye, $recu, $nb, $questionnaireComplet] = self::questionnaire($dossier['questionnaire'] ?? null);
        $complements = self::complements($dossier);
        if ($questionnaireComplet !== []) {
            $complements['questionnaire'] = $questionnaireComplet;
        }

        return new self(
            clientUuid: $m[1],
            version: $version,
            statut: $statut,
            factureEmise: $facture,
            rue: self::texte($dossier, 'rue', 255, 'payload.dossier'),
            telephone: self::texte($dossier, 'telephone', 32, 'payload.dossier'),
            axiCli: $axiCli,
            effectifTranche: self::texte($dossier, 'effectif_tranche', 32, 'payload.dossier'),
            urlConsole: $url,
            questionnaireEnvoyeLe: $envoye,
            questionnaireRecuLe: $recu,
            nbReponsesEquipe: $nb,
            contacts: self::contacts($dossier['contacts'] ?? null),
            complements: $complements,
        );
    }

    /** La facture est établie : seul cas où le CRM peut viser l'étape « client ». */
    public function estClient(): bool
    {
        return $this->statut === self::STATUT_CLIENT && $this->factureEmise;
    }

    /** L'étape « archive » : le CRM pose seulement `archive_reason`, rien n'est supprimé. */
    public function estArchive(): bool
    {
        return ($this->complements['etape'] ?? null) === 'archive';
    }

    /**
     * `metadata.console` à écrire : l'ancien contenu, recouvert par ce message.
     * Les clés de la fiche complète ABSENTES du message gardent leur valeur
     * (absence = aucun effet) ; les objets (`prochaine_action`,
     * `taches_ouvertes`) sont remplacés d'un bloc, le questionnaire clé à clé.
     *
     * @param  array<mixed>  $ancien
     * @return array<string, mixed>
     */
    public function metadonneesFusionnees(array $ancien): array
    {
        $nouveau = $this->metadonnees();
        $questionnaire = array_merge(
            is_array($ancien['questionnaire'] ?? null) ? $ancien['questionnaire'] : [],
            $nouveau['questionnaire'],
        );
        $fusion = array_merge($ancien, $nouveau);
        $fusion['questionnaire'] = $questionnaire;

        return $fusion;
    }

    /**
     * Ce que le CRM garde du dossier dans `companies.metadata.console` — l'état,
     * jamais une réponse ni un montant.
     *
     * @return array<string, mixed>
     */
    public function metadonnees(): array
    {
        $complements = $this->complements;
        $questionnaire = $complements['questionnaire'] ?? [];
        unset($complements['questionnaire']);

        return $complements + [
            'client_ref' => 'site:client:' . $this->clientUuid,
            'version' => $this->version,
            'statut' => $this->statut,
            'facture_emise' => $this->factureEmise,
            'axi_cli' => $this->axiCli,
            'effectif_tranche' => $this->effectifTranche,
            'url_console' => $this->urlConsole,
            'questionnaire' => [
                'envoye_le' => $this->questionnaireEnvoyeLe,
                'recu_le' => $this->questionnaireRecuLe,
                'nb_reponses_equipe' => $this->nbReponsesEquipe,
            ] + $questionnaire,
        ];
    }

    /**
     * Forme rejouable (arbitrage manuel) — exactement le contrat d'entrée.
     *
     * @return array<string, mixed>
     */
    public function enTableau(): array
    {
        $complements = $this->complements;
        $questionnaire = $complements['questionnaire'] ?? [];
        unset($complements['questionnaire']);

        return $complements + [
            'version' => $this->version,
            'statut' => $this->statut,
            'facture_emise' => $this->factureEmise,
            'rue' => $this->rue,
            'telephone' => $this->telephone,
            'axi_cli' => $this->axiCli,
            'effectif_tranche' => $this->effectifTranche,
            'url_console' => $this->urlConsole,
            'questionnaire' => [
                'envoye_le' => $this->questionnaireEnvoyeLe,
                'recu_le' => $this->questionnaireRecuLe,
                'nb_reponses_equipe' => $this->nbReponsesEquipe,
            ] + $questionnaire,
            'contacts' => $this->contacts,
        ];
    }

    /**
     * Relit un dossier conservé dans une ligne d'arbitrage (`activities.payload`).
     *
     * @param  array<mixed>  $dossier
     */
    public static function depuisArbitrage(string $subjectRef, array $dossier): self
    {
        return self::depuisEvenement($subjectRef, null, [], [], [], [], [], ['dossier' => $dossier]);
    }

    // ── Validation ──────────────────────────────────────────────────────────

    private static function refuserClesInterdites(mixed $valeur, string $ou): void
    {
        if (! is_array($valeur)) {
            return;
        }
        foreach ($valeur as $cle => $enfant) {
            if (is_string($cle) && in_array(mb_strtolower($cle), self::CLES_INTERDITES, true)) {
                throw SiteSyncRejection::invalid(
                    'forbidden_field',
                    "Dossier client : la clé « {$cle} » ({$ou}) est INTERDITE — jamais de réponse de questionnaire, de texte libre ni de montant.",
                    ['forbidden' => $cle],
                );
            }
            self::refuserClesInterdites($enfant, $ou . '.' . $cle);
        }
    }

    /**
     * @param  array<mixed>  $donnees
     * @param  list<string>  $permises
     */
    private static function seulement(array $donnees, array $permises, string $ou): void
    {
        $inconnues = array_diff(array_map('strval', array_keys($donnees)), $permises);
        if ($inconnues !== []) {
            throw SiteSyncRejection::invalid(
                'unknown_field',
                'Champ(s) inconnu(s) dans « ' . $ou . ' » : ' . implode(', ', $inconnues) . '.',
                ['unknown' => array_values($inconnues)],
            );
        }
    }

    /** @param  array<mixed>  $donnees */
    private static function texte(array $donnees, string $cle, int $max, string $ou): ?string
    {
        $valeur = $donnees[$cle] ?? null;
        if ($valeur === null) {
            return null;
        }
        if (! is_string($valeur)) {
            throw SiteSyncRejection::invalid('invalid_type', "« {$ou}.{$cle} » doit être une chaîne.");
        }
        $valeur = trim($valeur);
        if (mb_strlen($valeur) > $max) {
            throw SiteSyncRejection::invalid('field_too_long', "« {$ou}.{$cle} » dépasse {$max} caractères.");
        }

        return $valeur === '' ? null : $valeur;
    }

    /**
     * Les clés FACULTATIVES de la fiche complète, validées une à une ; une clé
     * absente ou nulle n'apparaît pas dans le résultat.
     *
     * @param  array<mixed>  $dossier
     * @return array<string, mixed>
     */
    private static function complements(array $dossier): array
    {
        $ou = 'payload.dossier';
        $c = [];

        foreach (['etape' => self::ETAPES, 'sante' => self::SANTES] as $cle => $permises) {
            $v = self::dansListe($dossier[$cle] ?? null, $permises, "{$ou}.{$cle}");
            if ($v !== null) {
                $c[$cle] = $v;
            }
        }
        $jour = self::jour($dossier['cloture_prevue'] ?? null, "{$ou}.cloture_prevue");
        if ($jour !== null) {
            $c['cloture_prevue'] = $jour;
        }

        $action = self::objet($dossier['prochaine_action'] ?? null, ['type', 'date'], "{$ou}.prochaine_action");
        if ($action !== null) {
            $type = self::dansListe($action['type'] ?? null, self::ACTIONS, "{$ou}.prochaine_action.type");
            if ($type === null) {
                throw SiteSyncRejection::invalid('missing_field', "« {$ou}.prochaine_action.type » est obligatoire.");
            }
            $c['prochaine_action'] = ['type' => $type, 'date' => self::jour($action['date'] ?? null, "{$ou}.prochaine_action.date")];
        }

        $taches = self::objet($dossier['taches_ouvertes'] ?? null, ['nb', 'prochaine_le'], "{$ou}.taches_ouvertes");
        if ($taches !== null) {
            $nb = self::entier($taches['nb'] ?? null, "{$ou}.taches_ouvertes.nb");
            if ($nb === null) {
                throw SiteSyncRejection::invalid('missing_field', "« {$ou}.taches_ouvertes.nb » est obligatoire.");
            }
            $c['taches_ouvertes'] = ['nb' => $nb, 'prochaine_le' => self::jour($taches['prochaine_le'] ?? null, "{$ou}.taches_ouvertes.prochaine_le")];
        }

        foreach (['nb_emails_console', 'nb_notes_console'] as $cle) {
            $n = self::entier($dossier[$cle] ?? null, "{$ou}.{$cle}");
            if ($n !== null) {
                $c[$cle] = $n;
            }
        }

        return $c;
    }

    /** @param  list<string>  $permises */
    private static function dansListe(mixed $valeur, array $permises, string $ou): ?string
    {
        if ($valeur === null) {
            return null;
        }
        if (! is_string($valeur) || ! in_array($valeur, $permises, true)) {
            throw SiteSyncRejection::invalid('invalid_enum', "« {$ou} » doit valoir l'une de : " . implode(', ', $permises) . '.');
        }

        return $valeur;
    }

    private static function entier(mixed $valeur, string $ou): ?int
    {
        if ($valeur === null) {
            return null;
        }
        if (! is_int($valeur) || $valeur < 0) {
            throw SiteSyncRejection::invalid('invalid_type', "« {$ou} » doit être un entier ≥ 0.");
        }

        return $valeur;
    }

    /**
     * @param  list<string>  $permises
     * @return ?array<mixed>
     */
    private static function objet(mixed $valeur, array $permises, string $ou): ?array
    {
        if ($valeur === null) {
            return null;
        }
        if (! is_array($valeur) || (array_is_list($valeur) && $valeur !== [])) {
            throw SiteSyncRejection::invalid('invalid_type', "« {$ou} » doit être un objet.");
        }
        self::seulement($valeur, $permises, $ou);

        return $valeur;
    }

    /** Un JOUR (AAAA-MM-JJ), ou un instant ISO 8601 ramené à son jour (fuseau de l'application). */
    private static function jour(mixed $valeur, string $ou): ?string
    {
        if ($valeur === null || $valeur === '') {
            return null;
        }
        if (! is_string($valeur)) {
            throw SiteSyncRejection::invalid('invalid_date', "« {$ou} » doit être une date AAAA-MM-JJ.");
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valeur, $m) === 1) {
            if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw SiteSyncRejection::invalid('invalid_date', "« {$ou} » n'est pas une date valide.");
            }

            return $valeur;
        }
        try {
            $date = new DateTimeImmutable($valeur);
        } catch (Exception) {
            throw SiteSyncRejection::invalid('invalid_date', "« {$ou} » n'est pas une date valide (AAAA-MM-JJ).");
        }

        return $date->setTimezone(new DateTimeZone((string) config('app.timezone', 'UTC')))->format('Y-m-d');
    }

    /** @return array{0: ?string, 1: ?string, 2: ?int, 3: array<string, mixed>} */
    private static function questionnaire(mixed $q): array
    {
        if ($q === null) {
            return [null, null, null, []];
        }
        if (! is_array($q) || (array_is_list($q) && $q !== [])) {
            throw SiteSyncRejection::invalid('invalid_type', '« payload.dossier.questionnaire » doit être un objet.');
        }
        self::seulement($q, self::CLES_QUESTIONNAIRE, 'payload.dossier.questionnaire');

        $nb = $q['nb_reponses_equipe'] ?? null;
        if ($nb !== null && (! is_int($nb) || $nb < 0)) {
            throw SiteSyncRejection::invalid('invalid_type', '« payload.dossier.questionnaire.nb_reponses_equipe » doit être un entier ≥ 0.');
        }

        $complet = [];
        $attendus = $q['attendus'] ?? null;
        if ($attendus !== null && (! is_int($attendus) || $attendus < 1 || $attendus > 1000)) {
            throw SiteSyncRejection::invalid('invalid_type', '« payload.dossier.questionnaire.attendus » doit être un entier de 1 à 1000.');
        }
        if ($attendus !== null) {
            $complet['attendus'] = $attendus;
        }
        $limite = self::jour($q['date_limite'] ?? null, 'payload.dossier.questionnaire.date_limite');
        if ($limite !== null) {
            $complet['date_limite'] = $limite;
        }

        return [self::date($q, 'envoye_le'), self::date($q, 'recu_le'), $nb, $complet];
    }

    /** @param  array<mixed>  $q */
    private static function date(array $q, string $cle): ?string
    {
        $valeur = $q[$cle] ?? null;
        if ($valeur === null || $valeur === '') {
            return null;
        }
        if (! is_string($valeur)) {
            throw SiteSyncRejection::invalid('invalid_date', "« payload.dossier.questionnaire.{$cle} » doit être une date ISO 8601.");
        }
        try {
            $date = new DateTimeImmutable($valeur);
        } catch (Exception) {
            throw SiteSyncRejection::invalid('invalid_date', "« payload.dossier.questionnaire.{$cle} » n'est pas une date ISO 8601 valide.");
        }

        // Même règle que `SiteSyncEvent::parseDate` : l'instant ne bouge pas,
        // sa représentation passe dans le fuseau de l'application.
        return $date->setTimezone(new DateTimeZone((string) config('app.timezone', 'UTC')))->format(DATE_ATOM);
    }

    /**
     * @return list<array{ref: string, prenom: ?string, nom: ?string, email: ?string, telephone: ?string, fonction: ?string, person_key: ?string, role_decision: ?string}>
     */
    private static function contacts(mixed $liste): array
    {
        if ($liste === null) {
            return [];
        }
        if (! is_array($liste) || ! array_is_list($liste)) {
            throw SiteSyncRejection::invalid('invalid_type', '« payload.dossier.contacts » doit être une liste.');
        }
        if (count($liste) > self::MAX_CONTACTS) {
            throw SiteSyncRejection::invalid('too_many_contacts', 'Dossier client : au plus ' . self::MAX_CONTACTS . ' contacts par message.');
        }

        $propres = [];
        $refs = [];
        foreach ($liste as $i => $contact) {
            $ou = "payload.dossier.contacts[{$i}]";
            if (! is_array($contact) || (array_is_list($contact) && $contact !== [])) {
                throw SiteSyncRejection::invalid('invalid_type', "« {$ou} » doit être un objet.");
            }
            self::seulement($contact, self::CLES_CONTACT, $ou);

            $ref = self::texte($contact, 'ref', 64, $ou);
            if ($ref === null) {
                throw SiteSyncRejection::invalid('missing_field', "« {$ou}.ref » est obligatoire.");
            }
            if (isset($refs[$ref])) {
                throw SiteSyncRejection::invalid('duplicate_contact_ref', "« {$ou}.ref » apparaît deux fois.");
            }
            $refs[$ref] = true;

            $email = self::texte($contact, 'email', 255, $ou);
            if ($email !== null) {
                $email = mb_strtolower($email);
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    throw SiteSyncRejection::invalid('invalid_email', "« {$ou}.email » doit être une adresse électronique.");
                }
            }
            $cle = self::texte($contact, 'person_key', 64, $ou);
            if ($cle !== null && preg_match('/^[0-9a-f]{64}$/', $cle) !== 1) {
                throw SiteSyncRejection::invalid('invalid_person_key', "« {$ou}.person_key » doit être un sha256 hexadécimal.");
            }

            $propres[] = [
                'ref' => $ref,
                'prenom' => self::texte($contact, 'prenom', 120, $ou),
                'nom' => self::texte($contact, 'nom', 120, $ou),
                'email' => $email,
                'telephone' => self::texte($contact, 'telephone', 32, $ou),
                'fonction' => self::texte($contact, 'fonction', 120, $ou),
                'person_key' => $cle,
                'role_decision' => self::dansListe($contact['role_decision'] ?? null, self::ROLES_DECISION, "{$ou}.role_decision"),
            ];
        }

        return $propres;
    }
}
