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
