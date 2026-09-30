<?php

namespace App\Crm;

/**
 * SOURCE DE VÉRITÉ UNIQUE de la taxonomie CRM (lot L1).
 *
 * Ces listes sont FERMÉES. Elles alimentent :
 *   - les contraintes CHECK posées par les migrations `2026_08_14_00000{2,3,4}` ;
 *   - la validation applicative ;
 *   - un test (`Feature\Crm\SocleCrmTest`) qui compare les CHECK réellement
 *     présents EN BASE à ces constantes. Ajouter une valeur ici sans écrire la
 *     migration correspondante fait donc ROUGIR la CI — c'est voulu : une
 *     taxonomie « fermée » qu'on peut étendre à la volée n'est pas fermée.
 *
 * Référence : `_PLANS/2026-08-13_PLAN-CRM-contacts-candidats.md` §2.2 et son
 * PRÉAMBULE DE PRÉSÉANCE (mapping de la taxonomie canonique de Will).
 */
final class Taxonomy
{
    /**
     * Univers BUSINESS — `companies.relation_type`.
     *
     * Mapping depuis la taxonomie canonique de Will :
     *   Clients→client · Presse→presse_media · Partenariats→partenaire ·
     *   Investisseurs→investisseur · Conférences→conference ·
     *   Recrutement→univers Vivier ENTIER (jamais une valeur ici) ·
     *   Podcast→vue par tag `src:site-formulaire-podcast` (PAS un type) ·
     *   Autres→vue par défaut (`prospect` sans tag `svc:`, PAS un type).
     *
     * Aucune valeur `candidat_*` : la frontière entre les deux univers est
     * portée par le CHECK SQL, pas par une convention.
     *
     * ⚠️ Toutes ces valeurs ne sont pas atteignables par le canal site → CRM :
     * voir `BUSINESS_RELATION_TYPES_SAISIE_MANUELLE` juste en dessous (B13-008).
     *
     * @var list<string>
     */
    public const BUSINESS_RELATION_TYPES = [
        'prospect',
        'client',
        'presse_media',
        'partenaire',
        'investisseur',
        'conference',
        'newsletter',
        'fournisseur',
    ];

    /**
     * TYPE DE MÉDIA, tel que l'étiquette `media-type:<valeur>` le dit — valeur
     * d'étiquette => libellé. Dérivé de `media.media_type` par
     * `MEDIA_TYPE_VERS_ETIQUETTE` (plusieurs types techniques peuvent donner la
     * même étiquette : `presse_mensuel` et `presse_revue` sont des magazines).
     *
     * @var array<string, string>
     */
    public const MEDIA_TYPES_ETIQUETTE = [
        'presse-quotidienne' => 'Presse quotidienne',
        'presse-hebdomadaire' => 'Presse hebdomadaire',
        'presse-magazine' => 'Presse magazine et revues',
        'presse-journal' => 'Journal (périodicité inconnue)',
        'presse-autre' => 'Publication de presse',
        'radio' => 'Radio',
        'tv' => 'Télévision',
        'emission-tv' => 'Émission de télévision',
        'agence' => 'Agence de presse',
        'web' => 'Presse en ligne',
        'blog' => 'Blog',
        'production' => 'Production audiovisuelle',
    ];

    /**
     * `media.media_type` (CHECK `media_media_type_check`) => valeur d'étiquette.
     * Chaque type technique a sa ligne (garde `PresseHarmonisationTest`).
     *
     * @var array<string, string>
     */
    public const MEDIA_TYPE_VERS_ETIQUETTE = [
        'presse_quotidien' => 'presse-quotidienne',
        'presse_hebdo' => 'presse-hebdomadaire',
        'presse_mensuel' => 'presse-magazine',
        'presse_revue' => 'presse-magazine',
        'presse_journal' => 'presse-journal',
        'presse_autre' => 'presse-autre',
        'radio' => 'radio',
        'tv' => 'tv',
        'tv_emission' => 'emission-tv',
        'agence_presse' => 'agence',
        'portail_web' => 'web',
        'blog' => 'blog',
        'production_audiovisuelle' => 'production',
    ];

    /**
     * ZONE DE DIFFUSION d'un média — `media-zone:<valeur>`. `inconnue` est une
     * valeur à part entière : ne pas savoir n'est pas « local ».
     *
     * @var array<string, string>
     */
    public const MEDIA_ZONES = [
        'national' => 'Nationale',
        'regional' => 'Régionale',
        'departemental' => 'Départementale',
        'local' => 'Locale',
        'inconnue' => 'Inconnue',
    ];

    /**
     * Valeurs de `BUSINESS_RELATION_TYPES` qu'AUCUN événement du canal
     * site → CRM ne peut produire : elles n'existent que par la saisie manuelle
     * en console.
     *
     * B13-008 — mesure du 2026-08-22 : `fournisseur` figure dans la liste
     * canonique et dans l'ordre de priorité, mais `SiteSyncClassifier::relationType()`
     * (SiteSyncClassifier.php:53-70) n'a aucune branche qui le rende — ses deux
     * `default` retombent sur `prospect`. Rien ne le disait, et la liste laissait
     * donc croire que le canal savait poser ce type.
     *
     * Ce n'est PAS un oubli à réparer côté canal : le site ne détient aucun
     * formulaire « sous-traitant », et lui en fabriquer un ferait naître un type
     * de fiche que la console ne gouverne pas encore. On l'écrit noir sur blanc
     * plutôt que de le laisser deviner.
     *
     * Le jour où un émetteur `SousTraitant` existe côté site : retirer la valeur
     * d'ici ET ajouter la branche dans `relationType()`. La garde
     * `tests/Unit/Crm/TaxonomieAtteignableParLeCanalTest.php` rougit tant que
     * les deux ne bougent pas ensemble.
     *
     * @var list<string>
     */
    public const BUSINESS_RELATION_TYPES_SAISIE_MANUELLE = [
        'fournisseur',
    ];

    /**
     * Ordre de priorité pour l'« upgrade » automatique du type : une fiche
     * porte TOUJOURS le type le plus engageant qu'elle a atteint.
     *
     * UN SEUL ordre pour TOUS les automatismes (canal site, import des
     * relations, presse), appliqué par `App\Crm\Relations\PromotionRelation`
     * (2026-10-01, relecture de #265). Deux règles le façonnent :
     *
     *  - les types HORS PROSPECTION (`RelationsProspection::HORS_PROSPECTION`)
     *    sont TOUS au-dessus des types prospectables : aucune promotion ne fait
     *    revenir en prospection une fiche qui en était exclue (un fournisseur
     *    n'est jamais « promu » en `conference`) ;
     *  - `prospect` n'est plus au 2ᵉ rang : c'est la valeur par DÉFAUT des
     *    4,3 M de fiches collectées. Au 2ᵉ rang, un formulaire du site
     *    rétrogradait un partenaire, une presse ou un investisseur en
     *    `prospect`. Il reste au-dessus de `newsletter` : une inscription à la
     *    lettre ne fait pas perdre un statut de prospect.
     *
     * @var list<string>
     */
    public const BUSINESS_RELATION_PRIORITY = [
        'client',
        'investisseur',
        'partenaire',
        'presse_media',
        'fournisseur',
        'conference',
        'prospect',
        'newsletter',
    ];

    /**
     * Univers VIVIER — `candidates.relation_type`.
     *
     * Granularité actée : par FAMILLE de métiers (liste FERMÉE). L'offre
     * précise vit dans le tag `cand-offre:<slug>`. Ajouter une famille = une
     * migration du CHECK, jamais « à la volée ».
     *
     * @var list<string>
     */
    public const CANDIDATE_RELATION_TYPES = [
        'candidat_commercial',
        'candidat_video',
        'candidat_tech',
        'candidat_autre',
    ];

    /** @var list<string> */
    public const BUSINESS_LIFECYCLE_STAGES = [
        'nouveau',
        'qualifie',
        'opportunite',
        'client',
        'dormant',
        'perdu',
    ];

    /** @var list<string> */
    public const CANDIDATE_LIFECYCLE_STAGES = [
        'nouveau',
        'preselection',
        'entretien',
        'retenu',
        'vivier',
        'refuse',
    ];

    /**
     * Bases légales (RGPD). `legitimate_interest_b2b` = prospection B2B sur
     * données professionnelles publiques (considérant 47 + doctrine CNIL B2B),
     * base des fiches SCRAPÉES ; `precontractual` = la personne a demandé à
     * être recontactée ; `consent` = newsletter et conservation en vivier.
     *
     * @var list<string>
     */
    public const LEGAL_BASES = [
        'legitimate_interest_b2b',
        'precontractual',
        'consent',
        'contract',
        'legal_obligation',
    ];

    /**
     * Vocabulaire FERMÉ de la timeline (`activities.kind`).
     *
     * @var list<string>
     */
    public const ACTIVITY_KINDS = [
        'form_submission',
        'calendly_booked',
        'calendly_completed',
        'calendly_no_show',
        'calendly_canceled',
        'review_posted',
        'newsletter_optin',
        'newsletter_optout',
        'application_submitted',
        'stage_changed',
        'reclassified',
        'scraped',
        'enriched',
        'opt_out',
        'gdpr_export',
        'gdpr_erasure',
        // ── Relations presse (2026-08-25) ──────────────────────────────────
        // La timeline ne connaissait aucun geste de relations presse : un
        // communiqué envoyé à un journaliste n'avait AUCUNE valeur de `kind`
        // dans laquelle se ranger, donc aucun moyen d'être consigné. Le besoin
        // exprimé — « savoir ce que j'ai envoyé à chacun et ce qu'on s'est
        // dit » — se réduisait en grande partie à ces six lignes manquantes.
        //
        // `linkedin_message` et `call` sont volontairement génériques : ils
        // servent la presse comme le reste du CRM. Les dupliquer en
        // `press_call` aurait fabriqué deux vocabulaires pour un même geste.
        'press_release_sent',
        'press_followup',
        'press_reply',
        'press_coverage',
        'linkedin_message',
        'call',
        // ── Personnes : la lettre et le guide (lot L4-C, 2026-09-24) ───────
        // `lead_magnet_requested` et `email_hard_bounced` sont des types
        // d'événement du canal site → CRM : ils DOIVENT figurer ici, sinon
        // `SiteSyncClassifier::activityKind()` les refuse (liste fermée).
        // `task` est la tâche ou la relance posée par un opérateur sur une
        // personne : `activities` porte déjà `due_at` et `done_at` depuis son
        // premier schéma, il n'y avait pas de table à créer, seulement une
        // valeur de vocabulaire.
        'lead_magnet_requested',
        'email_hard_bounced',
        'task',
        // ── Événements et interventions (2026-09-27) ───────────────────────
        // L'HISTORIQUE de la démarche auprès d'un organisateur. L'état courant
        // vit sur `events` (`participation`, `intervention`) pour être listable ;
        // chaque changement laisse ici une ligne datée (payload.event_id).
        'evenement_repere',
        'evenement_inscrit',
        'evenement_rencontre',
        'intervention_proposee',
        'intervention_acceptee',
        'intervention_refusee',
        'intervention_realisee',
        // ── Fédérations : la démarche « partenariat » (chantier 3, 2026-09-29)
        // Même patron que l'intervention : l'état courant vit sur
        // `federations.partenariat` (listable), chaque étape franchie laisse
        // ici une ligne datée (subject_type = 'company').
        'partenariat_propose',
        'partenariat_en_discussion',
        'partenariat_accepte',
        'partenariat_refuse',
    ];

    /**
     * Événements professionnels (table `events`, 2026-09-27) — le type tel que
     * le sourcing le qualifie.
     *
     * @var list<string>
     */
    public const EVENEMENT_TYPES = [
        'salon', 'conference', 'atelier', 'club-affaires', 'reseau-entrepreneurs',
        'afterwork', 'petit-dejeuner', 'table-ronde', 'pitch', 'remise-prix',
        'festival', 'cine-debat', 'autre',
    ];

    /**
     * Où en est Will vis-à-vis de l'événement lui-même.
     *
     * @var list<string>
     */
    public const EVENEMENT_PARTICIPATIONS = ['repere', 'inscrit', 'rencontre'];

    /**
     * Où en est la proposition d'intervention faite à l'organisateur.
     *
     * @var list<string>
     */
    public const EVENEMENT_INTERVENTIONS = ['aucune', 'proposee', 'acceptee', 'refusee', 'realisee'];

    /** @var list<string> */
    public const EVENEMENT_APPELS_INTERVENANTS = ['oui', 'non', 'inconnu'];

    /**
     * Portées d'opposition qui désignent un CANAL et non un UNIVERS (lot L4-C).
     *
     * `opt_out.scope` portait jusqu'ici deux univers, `business` et `vivier`.
     * Se désabonner de la lettre inscrivait une opposition `business` : la
     * personne ne pouvait plus JAMAIS entrer au CRM, pas même en réservant un
     * appel (constat D1 de la revue du 2026-09-24). Retirer son consentement à
     * un canal n'est pas s'opposer à toute prospection (art. 21).
     *
     * `lettre` est donc une portée de CANAL : elle retire la personne de la
     * liste de diffusion (et servira de liste repoussoir à la réimportation
     * d'un fichier d'envoi), mais elle ne bloque ni un formulaire ni un
     * rendez-vous. Elle n'est PAS un univers : un effacement n'a pas à
     * l'écrire — il pose une opposition `business`, qui bloque déjà tout —, et
     * les énumérations d'univers du dépôt n'ont pas à la connaître. Les gardes
     * qui lisent le CHECK de `opt_out` en retirent cette liste, et SEULEMENT
     * elle.
     *
     * @var list<string>
     */
    public const OPT_OUT_SCOPES_CANAL = ['lettre'];

    /**
     * Événements du site orientés vers les PERSONNES (table `personnes`) quand
     * `crm.ingest.personnes_enabled` est ouvert. La lettre et le guide
     * seulement : c'est le périmètre de la décision de Will du 2026-09-24, et
     * rien d'autre (les réservations et formulaires sans SIREN restent dans
     * l'arbitrage, faute d'une décision distincte).
     *
     * @var list<string>
     */
    public const PERSONNES_EVENT_TYPES = [
        'newsletter_optin',
        'newsletter_optout',
        'lead_magnet_requested',
        'email_hard_bounced',
    ];

    /**
     * Les deux types qui n'existaient pas avant le lot L4-C : ils n'ont AUCUN
     * chemin historique. Drapeau fermé, on les refuse en 503 (la ligne reste en
     * attente côté site) plutôt que de les laisser tomber dans l'arbitrage.
     *
     * @var list<string>
     */
    public const PERSONNES_EVENT_TYPES_SANS_CHEMIN_HISTORIQUE = [
        'lead_magnet_requested',
        'email_hard_bounced',
    ];

    /**
     * Nature de l'adresse d'une personne. Elle conditionne toute prospection
     * sans consentement : sans consentement, la CNIL n'admet la prospection
     * que d'un professionnel et sur un objet lié à sa profession — jamais sur
     * une adresse personnelle.
     *
     * @var list<string>
     */
    public const PERSONNE_EMAIL_NATURES = ['pro', 'perso', 'inconnue'];

    /**
     * Canaux d'abonnement — 1 canal = 1 future liste de diffusion. Liste
     * FERMÉE, étendue par migration seulement.
     *
     * @var list<string>
     */
    public const ABONNEMENT_CANAUX = ['lettre'];

    /** @var list<string> */
    public const ABONNEMENT_STATUTS = ['abonne', 'desabonne'];

    /**
     * Bases légales d'un ABONNEMENT (amendement de Will du 2026-09-24) :
     * consentement (adresse personnelle, case cochée ; format actuel du site,
     * double opt-in) ou intérêt légitime B2B (adresse professionnelle inscrite
     * à la demande du guide). Sous-ensemble fermé de `LEGAL_BASES`.
     *
     * @var list<string>
     */
    public const ABONNEMENT_LEGAL_BASES = ['consent', 'legitimate_interest_b2b'];

    /**
     * Par quelle PORTE on atteint un contact presse. Liste FERMÉE, et c'est
     * délibéré : contrairement aux motifs d'échange (`crm_activites`, table
     * ouverte et modifiable depuis la console), ceci n'est pas un réglage mais
     * une RÈGLE DE DIFFUSION. Elle décide qui peut recevoir un mailing.
     *
     * Un `redaction_prod` (émission TV, radio, podcast) qui reçoit un
     * communiqué en direct est un contact brûlé : on passe par la production.
     * Un `linkedin_direct` n'a pas d'email du tout. Un `a_qualifier` n'a pas
     * encore de rédaction identifiée. **Seul `email_redaction` est diffusable.**
     * Rendre cette liste modifiable, ce serait permettre d'inventer une
     * cinquième porte sans écrire la règle d'envoi qui va avec.
     *
     * @var list<string>
     */
    public const ACCES_PRESSE = [
        'email_redaction',
        'redaction_prod',
        'linkedin_direct',
        'a_qualifier',
    ];

    /**
     * État de la relation LinkedIn avec un contact.
     *
     * Cinq états utiles et non un booléen : « demande envoyée, sans réponse
     * depuis treize jours » n'est ni « en relation » ni « pas connecté », et
     * c'est pourtant l'état qui appelle un geste. Un `bool $ami` écrase
     * précisément l'information qui sert à piloter.
     *
     * `inconnu` est le défaut et n'est PAS un synonyme de `non_connecte` : ne
     * pas savoir n'est pas savoir que non. Les confondre ferait compter comme
     * « à inviter » des gens qu'on n'a simplement jamais regardés.
     *
     * @var list<string>
     */
    public const LIENS_LINKEDIN = [
        'inconnu',
        'non_connecte',
        'demande_envoyee',
        'connecte',
        'abonne',
        'refuse',
    ];

    /**
     * Namespaces de tags GOUVERNÉS (liste fermée).
     *
     * ⚠️ Ce commentaire affirmait qu'« un tag hors namespace est un tag
     * orphelin, interdit » : c'était FAUX (audit du 2026-09-28) — l'automate
     * pose depuis toujours `sector-btp`, `dept-38`, `nature-cci`… sans
     * namespace. La règle réellement appliquée, et vérifiée par une garde,
     * est celle de `App\Crm\Etiquettes\FamillesEtiquettes` (chantier 2,
     * 2026-09-29) : toute étiquette a UNE famille — un namespace gouverné
     * ci-dessous (`ns:valeur`), une famille automatique (`famille-valeur`),
     * l'IA (`kind = llm`, catégorie `ia`) ou la saisie manuelle.
     *
     * Correspondance avec `tags.category` (colonne déjà contrainte) :
     *   sect:→sector · taille:→size · geo:→geo · svc:/src:→intent ·
     *   cand-*:→candidate (valeur ajoutée au CHECK par la migration L1).
     *
     * @var array<string, string> namespace => tags.category
     */
    public const TAG_NAMESPACES = [
        'sect' => 'sector',
        'taille' => 'size',
        'geo' => 'geo',
        'svc' => 'intent',
        'src' => 'intent',
        'cand-offre' => 'candidate',
        'cand-b2b' => 'candidate',
        'cand-ia' => 'candidate',
        'cand-zone' => 'candidate',
        'cand-dispo' => 'candidate',
        'cand-mobilite' => 'candidate',
        // Fédérations (chantier 3, 2026-09-29) : étiquettes DÉRIVÉES de la
        // table `federations` (`App\Crm\Federations\EtiquettesFederation`),
        // posées et retirées par la synchro automatique — comme `geo:`/`sect:`,
        // leur gouvernance est le namespace, pas une liste de slugs.
        'famille' => 'custom',
        'niveau' => 'geo',
        'secteur' => 'sector',
        'taille-adherents' => 'size',
        'pertinence' => 'custom',
        'contactabilite' => 'custom',
        // Presse (harmonisation des contacts, 2026-09-30) : étiquettes DÉRIVÉES
        // des lignes `media` rattachées à la fiche (`App\Crm\Presse\EtiquettesMedia`),
        // posées et retirées par la même synchro automatique.
        'media-type' => 'custom',
        'media-zone' => 'geo',
        'media-theme' => 'custom',
        // Média INCERTAIN (2026-09-30) : fiche vue comme média par son seul code
        // NAF 63.12Z / 58.19Z (`App\Crm\Presse\MediaIncertain`) — dérivée,
        // posée et retirée par la synchro automatique ; à vérifier (chantier F).
        'media-possible' => 'custom',
    ];

    /**
     * Catégories d'étiquette — `tags.category` (CHECK, garde `SocleCrmTest`).
     *
     * `ia` (chantier 2, 2026-09-29, migration `2026_09_30_000030`) : les
     * étiquettes proposées par l'IA (`kind = llm`), jusque-là mêlées aux
     * étiquettes gouvernées `svc:`/`src:` dans `intent`.
     *
     * @var list<string>
     */
    public const TAG_CATEGORIES = ['geo', 'sector', 'size', 'intent', 'custom', 'candidate', 'ia'];

    /**
     * Slug du workspace du vivier candidats. Une fiche `candidates` ne peut
     * PHYSIQUEMENT pas vivre ailleurs (trigger SQL posé par la migration).
     */
    public const VIVIER_WORKSPACE_SLUG = 'vivier-candidats';

    /**
     * Versions de consentement FERMES (contre-vérification 2026-08-13).
     * L'endpoint d'ingestion des candidats (lot suivant) REJETTE toute fiche
     * candidat qui n'en porte pas une.
     *
     * @var list<string>
     */
    public const CANDIDATE_CONSENT_VERSIONS_V2 = [
        'careers-v2-2026-08-13',
        'memo-v2-2026-08-13',
        // Entrée du STOCK d'avant-v2 (option (b) du plan §2.3, décision actée) :
        // l'acte juridique n'est pas une case cochée mais l'email d'information
        // « vivier-information » + 30 jours sans opposition. Le site émet cette
        // version au J+30 (VIVIER_STOCK_CONSENT_VERSION, src/server/vivier) —
        // les deux listes bougent ENSEMBLE, sinon 422 en masse à l'intégration.
        'vivier-stock-2026-08-14',
    ];

    // ════════════════════════════════════════════════════════════════════════
    // RÉFÉRENTIELS DE CLASSEMENT DES ORGANISATIONS (chantier 1, 2026-09-28)
    // ════════════════════════════════════════════════════════════════════════
    //
    // Avant ce chantier, chaque classement existait en plusieurs copies qui se
    // contredisaient : deux classifieurs de secteur (collecte INSEE en 14
    // secteurs, enrichissement en 20, qui écrasait le premier), quatre
    // vocabulaires de taille (`micro`/`grande`, `grande_entreprise`, `artisan`,
    // `taille:ge`), cinq listes de natures (dont un filtre d'écran proposant
    // « Autres », valeur inexistante en base), deux codages de région (`AURA`
    // côté événements, `84` côté organisations).
    //
    // Désormais ces listes vivent ICI, et nulle part ailleurs :
    //   - le calcul (secteur depuis le code NAF, taille depuis l'INSEE, région
    //     depuis le département) est dans `App\Crm\Referentiels\Classement` ;
    //   - la table de passage NAF → secteur est dans `resources/referentiels/`
    //     (sources INSEE, cf. LISEZMOI.md) ;
    //   - l'écran lit `frontend/src/lib/referentiels.generated.ts`, GÉNÉRÉ depuis
    //     ces constantes par `php artisan crm:referentiels:generer-front` — une
    //     garde (`ReferentielsFrontTest`) rougit s'il n'est pas à jour.

    /**
     * Secteurs d'activité — `companies.sector_main`. Liste validée par Will le
     * 2026-09-28 (31 secteurs adossés aux 88 divisions de la NAF rév. 2, plus
     * deux clés réservées). L'ordre est celui de l'écran.
     *
     * `interprofessionnel` n'est produit par AUCUN code NAF : il est réservé aux
     * organisations multi-secteurs (MEDEF, CPME, U2P…), posé par le modèle
     * « fédérations » (chantier 3). `non_classe` = aucune activité connue (NAF
     * vide ou `00…`) — et, provisoirement, la division 94 (organisations
     * professionnelles), dont le secteur utile est celui qu'elles REPRÉSENTENT.
     *
     * Doit rester identique à `resources/referentiels/secteurs.csv` (garde
     * `ReferentielsTest`).
     *
     * @var array<string, string> clé => libellé
     */
    public const SECTEURS = [
        'agriculture' => 'Agriculture, sylviculture, pêche',
        'agroalimentaire' => 'Agroalimentaire et boissons',
        'industrie' => 'Industrie',
        'energie' => 'Énergie',
        'eau_dechets' => 'Eau, déchets, dépollution',
        'btp' => 'Bâtiment et travaux publics',
        'automobile' => 'Automobile (commerce et réparation)',
        'commerce_gros' => 'Commerce de gros',
        'commerce_detail' => 'Commerce de détail',
        'transport_logistique' => 'Transport et logistique',
        'hebergement_tourisme' => 'Hébergement et tourisme',
        'restauration' => 'Restauration',
        'edition_medias' => 'Édition, audiovisuel, médias',
        'numerique_telecoms' => 'Numérique et télécoms',
        'banque_finance' => 'Banque et finance',
        'assurance' => 'Assurance',
        'immobilier' => 'Immobilier',
        'droit' => 'Droit',
        'comptabilite_audit' => 'Comptabilité et audit',
        'conseil_management' => 'Conseil et management',
        'architecture_ingenierie' => 'Architecture, ingénierie, contrôle technique',
        'recherche_developpement' => 'Recherche et développement',
        'marketing_publicite' => 'Marketing, publicité, études',
        'services_specialises' => 'Design, photo, traduction et services spécialisés',
        'services_entreprises' => 'Services aux entreprises',
        'enseignement_formation' => 'Enseignement et formation',
        'sante' => 'Santé humaine et vétérinaire',
        'medico_social' => 'Médico-social et action sociale',
        'culture_sport_loisirs' => 'Culture, sport et loisirs',
        'services_personne' => 'Services à la personne',
        'administration_publique' => 'Administration publique',
        'interprofessionnel' => 'Interprofessionnel',
        'non_classe' => 'Non classé',
    ];

    public const SECTEUR_NON_CLASSE = 'non_classe';

    /**
     * Tailles — `companies.size_category`. Quatre valeurs, au sens INSEE
     * (catégorie d'entreprise, décret 2008-1354) : TPE (< 10 salariés), PME
     * (10-249), ETI (250-4 999), grand groupe (5 000 et plus).
     *
     * @var array<string, string> clé => libellé
     */
    public const TAILLES = [
        'tpe' => 'TPE',
        'pme' => 'PME',
        'eti' => 'ETI',
        'grand_groupe' => 'Grand groupe',
    ];

    /**
     * Anciens vocabulaires de taille, et la valeur qui les remplace. Seules ces
     * valeurs ont été vues en base (production, 2026-09-28) : `micro` 702 k
     * (enrichissement), `grande_entreprise` 21,9 k (collecte), `grande` 62
     * (enrichissement). `ge` est la forme du tag gouverné `taille:ge`.
     *
     * @var array<string, string>
     */
    public const TAILLES_ANCIENNES = [
        'micro' => 'tpe',
        'grande' => 'grand_groupe',
        'grande_entreprise' => 'grand_groupe',
        'ge' => 'grand_groupe',
    ];

    /**
     * Natures d'entité — `companies.entity_nature`. C'est la liste du CHECK
     * `companies_entity_nature_check` (garde `SocleCrmTest`). Les fiches venues
     * de l'INSEE (sociétés commerciales) portent `entreprise`.
     *
     * @var array<string, string> clé => libellé
     */
    public const ENTITY_NATURES = [
        'entreprise' => 'Entreprise',
        'association' => 'Association',
        'cci' => 'Chambre de commerce',
        'enseignement' => 'Enseignement',
        'cabinet' => 'Cabinet (conseil, avocats)',
        'institution' => 'Institution',
        'media' => 'Média',
        'reseau' => 'Réseau ou club d\'affaires',
        // Chantier 3 (2026-09-29) : fédérations, confédérations, ordres,
        // chambres de métiers et d'agriculture, syndicats patronaux et de
        // salariés, associations de métiers… Les CCI restent `cci`. Le détail
        // (famille, niveau, secteurs représentés) vit dans `federations`.
        'federation' => 'Organisation professionnelle',
    ];

    // ════════════════════════════════════════════════════════════════════════
    // FÉDÉRATIONS ET ORGANISATIONS PROFESSIONNELLES (chantier 3, 2026-09-29)
    // ════════════════════════════════════════════════════════════════════════
    //
    // Colonnes de la table `federations` (une ligne par fiche `companies`).
    // Chaque liste est fermée par un CHECK (garde `SocleCrmTest`) et exportée à
    // l'écran par `crm:referentiels:generer-front`. Décisions de Will :
    // `_FEDERATIONS/CADRAGE.md` §1, §3, §5, §7, §7 bis (hors dépôt).

    /**
     * Famille d'organisation — CADRAGE §1 et décisions du 28/09 (catégories
     * limites gardées, chacune dans sa famille, ciblables ou excluables).
     *
     * @var array<string, string>
     */
    public const FEDERATION_FAMILLES = [
        'confederation' => 'Confédération interprofessionnelle',
        'federation_syndicat_pro' => 'Fédération ou syndicat professionnel',
        'ordre' => 'Ordre professionnel',
        'profession_reglementee' => 'Chambre ou compagnie de profession réglementée',
        'chambre_consulaire' => 'Chambre consulaire',
        'syndicat_salaries' => 'Syndicat de salariés',
        'association_metier' => 'Association de métier ou de fonction',
        'association_entreprises' => 'Association d\'entreprises ou de dirigeants',
        'interprofession' => 'Interprofession ou organisme technique',
        'pole_cluster' => 'Pôle de compétitivité ou cluster',
        'financeur_formation' => 'Financeur de la formation (OPCO…)',
        'developpement_economique' => 'Développement économique',
        'association_elus' => 'Association d\'élus',
        'mutuelle_agricole' => 'Mutuelle ou caisse agricole',
        'proprietaires_locataires' => 'Propriétaires et locataires',
    ];

    /** @var array<string, string> */
    public const FEDERATION_NIVEAUX = [
        'national' => 'National',
        'regional' => 'Régional',
        'departemental' => 'Départemental',
        'local' => 'Local',
    ];

    /**
     * Ce qu'on a trouvé pour joindre l'organisme, du meilleur au pire. Calculé
     * par la recherche des contacts (hors CRM) ; la fiche n'est « finie » que
     * si elle est joignable par e-mail (CADRAGE §7 bis).
     *
     * @var array<string, string>
     */
    public const FEDERATION_CONTACTABILITES = [
        'email_verifie' => 'E-mail vérifié',
        'formulaire_seulement' => 'Formulaire seulement',
        'telephone_seulement' => 'Téléphone seulement',
        'site_ou_linkedin_seulement' => 'Site ou LinkedIn seulement',
        'aucun_contact' => 'Aucun contact',
    ];

    /**
     * Certitude du classement (famille, niveau, secteurs) : `haute` = relu ou
     * incontestable, `faible` = deviné. NULL = classé par règle, sans examen.
     *
     * @var array<string, string>
     */
    public const FEDERATION_CERTITUDES = [
        'haute' => 'Haute',
        'moyenne' => 'Moyenne',
        'faible' => 'Faible',
    ];

    /**
     * Pertinence pour Axion-IA — règle du CADRAGE §5, validée le 28/09.
     * `faible` est exclue des campagnes PAR DÉFAUT (décision du 28/09).
     *
     * @var array<string, string>
     */
    public const FEDERATION_PERTINENCES = [
        'haute' => 'Haute',
        'moyenne' => 'Moyenne',
        'faible' => 'Faible',
    ];

    /**
     * Pertinences RETENUES par le segment de campagne `federations`. Une
     * liste de ce qu'on vise, et non de ce qu'on exclut : une fiche sans
     * classement (pas de ligne `federations`) n'y est pas, donc n'est pas
     * visée. `faible` s'ajoute par option explicite (décision du 28/09).
     *
     * @var list<string>
     */
    public const FEDERATION_PERTINENCES_EN_CAMPAGNE = ['haute', 'moyenne'];

    /**
     * Familles écartées du segment de campagne SAUF option explicite.
     * `syndicat_salaries` : l'appartenance syndicale est une donnée de l'art. 9
     * du RGPD (décision de Will du 28/09 ; base : art. 9.2.e, données rendues
     * manifestement publiques par les responsables syndicaux).
     *
     * @var list<string>
     */
    public const FEDERATION_FAMILLES_HORS_CAMPAGNE = ['syndicat_salaries'];

    /**
     * Démarche « partenariat » auprès de l'organisme (en plus de
     * l'intervention, qui vit sur ses événements). Jamais écrite par un import.
     *
     * @var array<string, string>
     */
    public const FEDERATION_PARTENARIATS = [
        'aucun' => 'Pas encore proposé',
        'propose' => 'Proposé',
        'en_discussion' => 'En discussion',
        'accepte' => 'Accepté',
        'refuse' => 'Refusé',
    ];

    /** Nombre maximal de secteurs représentés par un organisme. */
    public const FEDERATION_SECTEURS_MAX = 3;

    /**
     * Secteurs qu'un organisme peut REPRÉSENTER : le référentiel des secteurs,
     * sans `non_classe` (un secteur représenté est une information, pas un
     * défaut). `interprofessionnel` en fait partie.
     *
     * @return list<string>
     */
    public static function secteursRepresentables(): array
    {
        $cles = [];
        foreach (array_keys(self::SECTEURS) as $cle) {
            if ($cle !== self::SECTEUR_NON_CLASSE) {
                $cles[] = $cle;
            }
        }

        return $cles;
    }

    /**
     * Régions — code INSEE de région (`companies.region_code`, `events.region`).
     * 13 régions métropolitaines + 5 DROM. `FrenchRegionsSeeder` en tire les
     * libellés de la table `regions`.
     *
     * ⚠️ PHP convertit une clé « 84 » en ENTIER (seules « 01 »… « 06 »,
     * zéro de tête, restent des chaînes). Toute lecture des CLÉS passe donc par
     * `(string)` — c'est fait dans `Classement` et dans le générateur du front.
     *
     * @var array<int|string, string> code INSEE => libellé
     */
    public const REGIONS = [
        '84' => 'Auvergne-Rhône-Alpes',
        '27' => 'Bourgogne-Franche-Comté',
        '53' => 'Bretagne',
        '24' => 'Centre-Val de Loire',
        '94' => 'Corse',
        '44' => 'Grand Est',
        '32' => 'Hauts-de-France',
        '11' => 'Île-de-France',
        '28' => 'Normandie',
        '75' => 'Nouvelle-Aquitaine',
        '76' => 'Occitanie',
        '52' => 'Pays de la Loire',
        '93' => 'Provence-Alpes-Côte d\'Azur',
        '01' => 'Guadeloupe',
        '02' => 'Martinique',
        '03' => 'Guyane',
        '04' => 'La Réunion',
        '06' => 'Mayotte',
    ];

    /**
     * Sigles usuels d'une région, tels que le sourcing des événements les
     * écrit (`AURA`, `IDF`…). Ils ne sont JAMAIS stockés : on les convertit en
     * code INSEE à l'entrée (`Classement::region()`).
     *
     * @var array<string, string> sigle => code INSEE
     */
    public const REGIONS_SIGLES = [
        'AURA' => '84', 'ARA' => '84',
        'BFC' => '27',
        'BZH' => '53', 'BRE' => '53',
        'CVL' => '24',
        'COR' => '94',
        'GE' => '44', 'GES' => '44',
        'HDF' => '32',
        'IDF' => '11',
        'NOR' => '28',
        'NA' => '75', 'NAQ' => '75',
        'OCC' => '76',
        'PDL' => '52',
        'PACA' => '93', 'PAC' => '93', 'SUD' => '93',
    ];

    /**
     * Département → région (codes INSEE). Source :
     * https://www.insee.fr/fr/information/2114819
     *
     * Clés « 38 », « 971 » converties en entiers par PHP (cf. `REGIONS`) ;
     * les VALEURS restent des chaînes.
     *
     * @var array<int|string, string>
     */
    public const REGION_PAR_DEPARTEMENT = [
        // Auvergne-Rhône-Alpes (84)
        '01' => '84', '03' => '84', '07' => '84', '15' => '84', '26' => '84',
        '38' => '84', '42' => '84', '43' => '84', '63' => '84', '69' => '84',
        '73' => '84', '74' => '84',
        // Bourgogne-Franche-Comté (27)
        '21' => '27', '25' => '27', '39' => '27', '58' => '27', '70' => '27',
        '71' => '27', '89' => '27', '90' => '27',
        // Bretagne (53)
        '22' => '53', '29' => '53', '35' => '53', '56' => '53',
        // Centre-Val de Loire (24)
        '18' => '24', '28' => '24', '36' => '24', '37' => '24', '41' => '24', '45' => '24',
        // Corse (94)
        '2A' => '94', '2B' => '94',
        // Grand Est (44)
        '08' => '44', '10' => '44', '51' => '44', '52' => '44', '54' => '44',
        '55' => '44', '57' => '44', '67' => '44', '68' => '44', '88' => '44',
        // Hauts-de-France (32)
        '02' => '32', '59' => '32', '60' => '32', '62' => '32', '80' => '32',
        // Île-de-France (11)
        '75' => '11', '77' => '11', '78' => '11', '91' => '11', '92' => '11',
        '93' => '11', '94' => '11', '95' => '11',
        // Normandie (28)
        '14' => '28', '27' => '28', '50' => '28', '61' => '28', '76' => '28',
        // Nouvelle-Aquitaine (75)
        '16' => '75', '17' => '75', '19' => '75', '23' => '75', '24' => '75',
        '33' => '75', '40' => '75', '47' => '75', '64' => '75', '79' => '75',
        '86' => '75', '87' => '75',
        // Occitanie (76)
        '09' => '76', '11' => '76', '12' => '76', '30' => '76', '31' => '76',
        '32' => '76', '34' => '76', '46' => '76', '48' => '76', '65' => '76',
        '66' => '76', '81' => '76', '82' => '76',
        // Pays de la Loire (52)
        '44' => '52', '49' => '52', '53' => '52', '72' => '52', '85' => '52',
        // Provence-Alpes-Côte d'Azur (93)
        '04' => '93', '05' => '93', '06' => '93', '13' => '93', '83' => '93', '84' => '93',
        // DROM
        '971' => '01', '972' => '02', '973' => '03', '974' => '04', '976' => '06',
    ];

    /**
     * Nomenclature détectée du code d'activité (`companies.naf_nomenclature`).
     * `inconnue` : un code présent mais inexploitable (`00.00Z`, forme
     * inconnue). NULL : aucun code.
     *
     * @var list<string>
     */
    public const NAF_NOMENCLATURES = ['naf_rev2', 'naf_rev1', 'nap_1973', 'inconnue'];

    /**
     * Rend une liste utilisable dans un CHECK SQL : 'a', 'b', 'c'.
     *
     * @param  list<string>  $values
     */
    public static function sqlList(array $values): string
    {
        return implode(', ', array_map(static fn (string $v): string => "'" . str_replace("'", "''", $v) . "'", $values));
    }
}
