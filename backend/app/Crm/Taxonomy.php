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
     * Ordre de priorité pour l'« upgrade » automatique du type : une fiche
     * porte TOUJOURS le type le plus engageant qu'elle a atteint.
     *
     * @var list<string>
     */
    public const BUSINESS_RELATION_PRIORITY = [
        'client',
        'prospect',
        'investisseur',
        'partenaire',
        'presse_media',
        'conference',
        'fournisseur',
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
    ];

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
     * Namespaces de tags GOUVERNÉS (liste fermée). Un tag hors namespace est
     * un tag orphelin : interdit par la gouvernance.
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
    ];

    /** @var list<string> */
    public const TAG_CATEGORIES = ['geo', 'sector', 'size', 'intent', 'custom', 'candidate'];

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
