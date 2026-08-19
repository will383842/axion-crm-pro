<?php

namespace App\Crm;

/**
 * ACTIVITÉS et MOTIFS D'ÉCHANGE — cahier des charges §2.3, étape 1a.
 *
 * ── Ce que dit le cahier des charges ────────────────────────────────────────
 * « Le TYPE dit *qui* est la personne ; l'ACTIVITÉ dit *de quoi* on parle avec
 * elle. Les deux sont indépendants. » Et : « un même appel peut commencer sur
 * une formation et finir sur une implémentation ».
 *
 * Chaque activité et chaque motif détermine la trame d'entretien proposée, la
 * grille de notation, les modèles de messages, le catalogue pré-sélectionné à
 * la création du devis, les événements de livraison et les indicateurs.
 *
 * ── Pourquoi ce n'est PAS une taxonomie fermée, contrairement à `Taxonomy` ──
 * C'est la différence la plus importante de ce fichier, et elle est voulue.
 *
 * `App\Crm\Taxonomy` porte des listes FERMÉES, gardées par un `CHECK` SQL :
 * ajouter une valeur sans migration fait rougir la CI. C'est le bon régime
 * pour `relation_type`, qui structure la base et le cloisonnement des univers.
 *
 * Ici, le §2.3 dit « livrées d'origine, **extensibles depuis la console du
 * CRM** », et le §29 critère 2 exige qu'« aucun paramétrage courant n'exige
 * d'intervention technique ». Un `CHECK` interdirait précisément cela : ajouter
 * un motif demanderait une migration, donc un développeur, donc un
 * déploiement. Les activités et motifs vivent donc dans des TABLES, semées avec
 * les valeurs d'origine — un plancher, jamais un plafond.
 *
 * Cette classe n'est donc pas une contrainte : c'est le **contenu livré
 * d'origine**, la référence du semis et des tests. Rien n'empêche la console
 * d'en ajouter ; c'est le but.
 *
 * ── Ce qui NE doit pas être re-semé ─────────────────────────────────────────
 * Le semis est en `insertOrIgnore` sur le slug, jamais en `upsert` : un libellé
 * modifié depuis la console doit SURVIVRE au déploiement suivant. Un `upsert`
 * qui met à jour `label` rendrait la promesse « extensible depuis la console »
 * fausse une fois par déploiement, en silence — le genre de défaut qu'on ne
 * découvre que le jour où quelqu'un se plaint que « ça revient tout seul ».
 */
final class ActivitesEtMotifs
{
    /**
     * Les CINQ activités d'AXION IA (§2.3). L'activité n'existe que pour les
     * motifs COMMERCIAUX : « une fiche peut n'avoir aucune activité — un
     * journaliste, un candidat, un partenaire, un fournisseur ne sont concernés
     * par aucune ligne d'offre ».
     *
     * `qualiopi` : le §2.3 est explicite — la formation est « la seule activité
     * soumise à Qualiopi ». Ce n'est pas décoratif : c'est ce drapeau qui dira
     * plus tard quels dossiers relèvent des obligations du référentiel.
     *
     * @var array<string, array{label: string, qualiopi: bool, ordre: int}>
     */
    public const ACTIVITES = [
        'formation' => [
            'label' => 'Formation',
            'qualiopi' => true,
            'ordre' => 10,
        ],
        'accompagnement-1-1' => [
            'label' => 'Accompagnement individuel 1:1',
            'qualiopi' => false,
            'ordre' => 20,
        ],
        'audit-ia' => [
            'label' => 'Audit IA',
            'qualiopi' => false,
            'ordre' => 30,
        ],
        'implementation' => [
            'label' => 'Implémentation et automatisation',
            'qualiopi' => false,
            'ordre' => 40,
        ],
        'site-web-saas' => [
            'label' => 'Site web et SaaS',
            'qualiopi' => false,
            'ordre' => 50,
        ],
    ];

    /**
     * Les ONZE motifs non commerciaux (§2.3), livrés d'origine.
     *
     * `espace` reprend la seule variance que le §2.1 reconnaisse au type sur le
     * choix d'une trame : « une trame "candidature" n'existe que dans l'espace
     * vivier ». Tout le reste est `business` ou `tous`.
     *
     * La FAMILLE d'une candidature (commerciale, vidéo, technique, autre) n'est
     * PAS une colonne d'ici : elle est déjà portée par
     * `Taxonomy::CANDIDATE_RELATION_TYPES`, qui est la source de vérité du
     * vivier. La dupliquer ici en ferait deux listes qui divergeraient — la
     * faute exacte relevée sur le barème de commission le 18/08.
     *
     * @var array<string, array{label: string, espace: string, ordre: int}>
     */
    public const MOTIFS = [
        'presse' => [
            'label' => 'Presse',
            'espace' => 'business',
            'ordre' => 10,
        ],
        'partenariat' => [
            'label' => 'Partenariat',
            'espace' => 'business',
            'ordre' => 20,
        ],
        'candidature' => [
            'label' => 'Candidature',
            'espace' => 'vivier',
            'ordre' => 30,
        ],
        'investisseur' => [
            'label' => 'Investisseur',
            'espace' => 'business',
            'ordre' => 40,
        ],
        'conference' => [
            'label' => 'Conférence / prise de parole',
            'espace' => 'business',
            'ordre' => 50,
        ],
        'podcast' => [
            'label' => 'Podcast',
            'espace' => 'business',
            'ordre' => 60,
        ],
        'support-reclamation' => [
            'label' => 'Support ou réclamation client',
            'espace' => 'business',
            'ordre' => 70,
        ],
        'fournisseur' => [
            'label' => 'Fournisseur',
            'espace' => 'business',
            'ordre' => 80,
        ],
        'sous-traitant' => [
            'label' => 'Sous-traitant / intervenant',
            'espace' => 'business',
            'ordre' => 90,
        ],
        'organisme-financeur' => [
            'label' => 'Organisme financeur (OPCO, France Travail, région…)',
            'espace' => 'business',
            'ordre' => 100,
        ],
        'autre' => [
            'label' => 'Autre',
            'espace' => 'tous',
            'ordre' => 999,
        ],
    ];

    /**
     * Espaces reconnus par `crm_motifs.espace`. Liste FERMÉE, celle-là : elle
     * décrit le cloisonnement business / vivier, qui est structurel et gardé
     * par des triggers SQL — pas un paramétrage de console.
     *
     * @var list<string>
     */
    public const ESPACES = ['business', 'vivier', 'tous'];
}
