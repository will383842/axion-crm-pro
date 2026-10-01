<?php

namespace App\Crm\Presse;

use App\Crm\Taxonomy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * LE CLASSEMENT D'UN MÉDIA — thèmes, public, format TV, verdict « média
 * possible » (chantier F, décision de Will du 2026-10-01 : pouvoir viser plus
 * tard « les médias économie/PME, public dirigeants, en AURA »).
 *
 * Une seule définition des RÈGLES (ici) ; la lecture de la page d'accueil est
 * `LecturePageAccueil`, le passage en masse `crm:presse:classer-medias`.
 *
 * ── CE QUI EST LU ────────────────────────────────────────────────────────
 *
 * Quatre ZONES de texte, chacune avec son poids :
 *
 *   nom    ×3  nom de la fiche, noms de ses médias (dont le nom de
 *              l'émission), thème éditorial donné par la source ;
 *   titre  ×3  <title>, méta description, og:title / og:description /
 *              og:site_name, <h1> ;
 *   menu   ×2  liens de navigation (<nav>, <header>, role=navigation), <h2> ;
 *   texte  ×1  quelques paragraphes et <h3>.
 *
 * Le texte est ramené en minuscules sans accent ni ponctuation ; un mot-clé
 * est trouvé quand il apparaît en MOT ENTIER (au singulier ou avec un « s » /
 * « x » final). Chaque mot-clé compte UNE fois par zone : répéter « économie »
 * vingt fois dans une page ne la rend pas plus économique.
 *
 * ── LA RÈGLE DE SCORE ────────────────────────────────────────────────────
 *
 *   score = Σ zones Σ mots-clés trouvés (poids du mot × poids de la zone)
 *
 * avec la zone `texte` PLAFONNÉE à 3 (`PLAFOND_TEXTE`) : le corps de page seul
 * ne suffit JAMAIS à classer (un quotidien national qui cite Lyon et
 * Marseille dans deux brèves n'est pas un média régional). Il faut un signal
 * dans le nom, le titre ou le menu.
 *
 * Une valeur est retenue si son score atteint `SEUIL` (4) ET si le signal est
 * SÛR (`retenu()`) : au moins un mot dans le NOM ou le TITRE (titre, meta, h1),
 * ou au moins DEUX mots-clés distincts HORS du corps de page (nom, titre,
 * menu — v3 : le corps n'est plus compté).
 *
 * Règles v3 (échantillon de production du 2026-10-01) :
 *   - `ia-tech` exige un mot SPÉCIFIQUE hors corps (`IA_TECH_SPECIFIQUES` :
 *     IA, intelligence artificielle, tech, startup…) ou deux mots tech hors
 *     corps — « impression numérique », « informatique » seuls ne suffisent pas ;
 *   - format TV seulement si TOUTES les lignes média de la fiche sont TV ;
 *   - « média possible » : aucun thème ni public tant que le verdict n'est pas
 *     `semble-media`.
 * Un seul mot de menu (« Immobilier », « Management ») ne classe jamais ; un
 * mot faible (poids 1) seul dans le titre non plus. Sans aucune valeur
 * retenue : `inconnu`. On n'invente RIEN.
 *
 * Règles v4 (relance de production du 2026-10-01 : un quotidien régional
 * sortait « économie + RH », un hebdomadaire économique perdait ses
 * dirigeants) — la règle « généraliste » de la v3 est REMPLACÉE par :
 *   - DOMINANCE RELATIVE : parmi les thèmes retenus, on ne garde que ceux dont
 *     le score atteint 40 % (`DOMINANCE_MIN`) de celui du thème PRINCIPAL, ou
 *     qui sont dans le NOM du média (la raison sociale, le nom du titre, de
 *     l'émission — pas la méta description, qui énumère les rubriques d'un
 *     généraliste). Presse professionnelle et secteur : idem, en plus du
 *     signal nom/titre déjà exigé ;
 *   - PUBLIC : `dirigeants` seulement si le thème principal HORS régional /
 *     grand public est économie, PME ou RH, que son score atteint celui du
 *     thème grand public, et que le score du public dirigeants dépasse celui
 *     du public grand public ; `pros-secteur`, de même, si ce thème principal
 *     est la presse professionnelle ; `grand-public` si le thème grand public
 *     est le thème PRINCIPAL ; sinon `inconnu`.
 *
 * Thèmes (plusieurs possibles) : `ia-tech`, `economie-entreprise`,
 * `pme-entrepreneurs`, `rh-management`, `metiers-secteurs`, `regional`,
 * `grand-public` — mots-clés dans `THEMES_MOTS`.
 *   - `metiers-secteurs` (presse professionnelle) : signal FORT exigé, dans
 *     le nom ou le titre. Retenu si un SECTEUR précis l'est (BTP, santé,
 *     agriculture… `SECTEURS_MOTS`, posé en plus `media-sujet:secteur-<clé>`,
 *     lui aussi sur signal fort), ou si les mots de la presse professionnelle
 *     (« revue professionnelle », « le journal des professionnels ») y
 *     atteignent le seuil. Un quotidien qui a une rubrique « Immobilier » n'est
 *     pas une presse professionnelle.
 *   - `regional` : reçoit aussi le seuil entier quand la SOURCE a donné une
 *     zone de diffusion régionale, départementale ou locale
 *     (`media.diffusion_zone`) — une donnée, pas une déduction.
 *
 * Public (plusieurs possibles) : `dirigeants`, `pros-secteur`, `grand-public`
 * — mots-clés dans `PUBLICS_MOTS`, plus un BONUS de 2 quand le thème qui va
 * avec est retenu (PME-entrepreneurs → dirigeants ; métiers-secteurs →
 * pros-secteur ; grand-public → grand-public ; économie et RH : 1 vers
 * dirigeants). Le bonus seul (2) n'atteint pas le seuil : il faut au moins un
 * mot du public lui-même, et le signal doit être sûr (le bonus compte pour un
 * signal). `pros-secteur` exige un signal fort (nom, titre) ou une presse
 * professionnelle retenue : un lien « Espace pros » ne suffit pas.
 *
 * Format (TÉLÉVISION seulement : un média `tv` ou `tv_emission`) : UN SEUL,
 * parmi `magazine-eco`, `talk-show`, `jt-info`, `tech`, `fiction-jeu` —
 * mots-clés dans `FORMATS_MOTS`. Retenu si le meilleur est SÛR (seuil et
 * signal sûr) ET vaut PLUS du double du second. `fiction-jeu` ne se lit QUE
 * dans le nom ou le titre : le menu d'une chaîne généraliste (Séries, Films,
 * Jeux, Divertissement, Info) ne dit rien de l'émission — elle reste `inconnu`.
 *   ⚠️ `fiction-jeu` ÉCARTE : une fiction ou un jeu n'invite pas d'expert. Ses
 *   thèmes utiles (IA, économie, PME, RH, métiers) et ses publics
 *   professionnels (dirigeants, pros-secteur) sont retirés — une série
 *   « dans le monde de l'entreprise » n'est pas un média économique.
 *
 * Verdict « média possible » (fiches `media-possible:a-verifier` SEULEMENT,
 * lues sur leur site) : deux scores, SANS plafond de la zone texte —
 *   - signes de MÉDIA (`VERDICT_MEDIA_MOTS`) : « rédaction », « abonnez-vous »,
 *     « à la une », « rubriques », « journalistes »… plus 3 si la page a au
 *     moins 3 <article>, plus 3 si elle porte au moins 3 dates (articles
 *     datés : <time> ou « 12 mars 2026 », « 12/03/2026 ») ;
 *   - signes de NON-MÉDIA (`VERDICT_PAS_MEDIA_MOTS`) : « devis », « nos
 *     services », « nos prestations », « agence web », « création de sites »,
 *     « ajouter au panier »… ;
 *   - `semble-media` si média ≥ 6 ET média ≥ 2 × non-média ET au moins un
 *     signe LEXICAL de média (la structure seule — billets datés d'un blog
 *     d'éditeur de logiciels — ne suffit jamais) ;
 *     `semble-pas-media` si non-média ≥ 6 ET non-média ≥ 2 × média ;
 *     sinon la fiche RESTE `a-verifier`.
 * Le verdict n'est qu'une PROPOSITION : la relation, la nature, la protection
 * de la fiche ne bougent pas — c'est Will qui valide.
 *
 * ── CE QUI EST GARDÉ ─────────────────────────────────────────────────────
 *
 * `companies.metadata.classement_media` : les VALEURS retenues, les scores
 * (des nombres), le mode de lecture, la version des règles et la date. AUCUN
 * texte de la page : ni extrait, ni titre, ni nom de personne, ni adresse.
 *
 * ── LES ÉTIQUETTES ───────────────────────────────────────────────────────
 *
 * DÉRIVÉES de ce classement par `AutoTaggerService::syncTags` (`desirees()`),
 * comme `EtiquettesMedia` : `media-sujet:<thème>`,
 * `media-sujet:secteur-<clé>`, `media-public:<public>`,
 * `media-format:<format>` (TV), `media-possible:semble-media` /
 * `semble-pas-media` (qui remplace alors `a-verifier`). Une resynchro ne les
 * efface pas tant que le classement dit la même chose ; une fiche sans média
 * n'en désire aucune.
 *
 * Une étiquette POSÉE À LA MAIN gagne : si la fiche porte déjà, à la main, une
 * étiquette d'un de ces namespaces (`media-sujet:`, `media-public:`,
 * `media-format:`, ou un verdict `media-possible:`), le classement automatique
 * n'en ajoute AUCUNE dans ce namespace.
 */
final class ClassementMedia
{
    /** Version des règles : la monter fait relire toutes les fiches au passage suivant. */
    public const VERSION = 4;

    /** Clé de `companies.metadata` qui garde le classement. */
    public const CLE = 'classement_media';

    public const SEUIL = 4;

    public const PLAFOND_TEXTE = 3;

    public const SEUIL_VERDICT = 6;

    /** @var array<string, int> */
    public const POIDS_ZONES = ['nom' => 3, 'titre' => 3, 'menu' => 2, 'texte' => 1];

    /** Zones dont un seul mot est un signal SÛR (nom du média, titre / meta / h1). */
    public const ZONES_FORTES = ['nom', 'titre'];

    /** Comment la fiche a été lue. */
    public const LECTURE_SITE = 'site';

    public const LECTURE_NOM = 'nom';

    public const LECTURE_ROBOTS = 'robots-interdit';

    public const LECTURE_INJOIGNABLE = 'injoignable';

    /** Site trop gros, type refusé ou encodage inconnu : lu par le nom, marqué, sauté à la relance. */
    public const LECTURE_ILLISIBLE = 'illisible';

    /** @var list<string> */
    public const LECTURES = [self::LECTURE_SITE, self::LECTURE_NOM, self::LECTURE_ROBOTS, self::LECTURE_INJOIGNABLE, self::LECTURE_ILLISIBLE];

    public const INCONNU = 'inconnu';

    public const VERDICT_MEDIA = 'semble-media';

    public const VERDICT_PAS_MEDIA = 'semble-pas-media';

    public const VERDICT_A_VERIFIER = 'a-verifier';

    /** @var array<string, string> verdict => libellé de l'étiquette */
    public const VERDICTS = [
        self::VERDICT_MEDIA => 'Média possible : semble un média (proposition à valider)',
        self::VERDICT_PAS_MEDIA => 'Média possible : ne semble pas un média (proposition à valider)',
    ];

    /** Types `media.media_type` qui reçoivent un format. */
    public const TYPES_TV = ['tv', 'tv_emission'];

    /**
     * Mots SPÉCIFIQUES de `ia-tech` (v3) : un seul d'entre eux, hors corps de
     * page, suffit. Les autres (« numérique », « informatique », « digital »,
     * « web », « data »…) sont trop larges — une imprimerie numérique, un
     * groupement informatique — et ne comptent qu'à DEUX au moins, hors corps.
     *
     * @var list<string>
     */
    public const IA_TECH_SPECIFIQUES = [
        'intelligence artificielle', 'ia', 'ia generative', 'chatgpt', 'tech', 'high tech',
        'cybersecurite', 'transformation digitale', 'transformation numerique', 'geek', 'startup', 'start up',
    ];

    /** Thèmes de « couverture » : ni l'un ni l'autre ne dit À QUI parle un média. */
    public const THEMES_COUVERTURE = ['regional', 'grand-public'];

    /** Dominance relative (v4), en dixièmes : un thème gardé pèse au moins 4/10 du principal. */
    public const DOMINANCE_MIN_DIXIEMES = 4;

    /** Thème principal (hors couverture) qui ouvre le public `dirigeants`. */
    public const THEMES_DIRIGEANTS = ['economie-entreprise', 'pme-entrepreneurs', 'rh-management'];

    /** Thèmes qu'un format `fiction-jeu` ne garde pas. */
    private const THEMES_GARDES_PAR_FICTION = ['grand-public', 'regional'];

    /** Namespaces que le classement pose (et qu'une étiquette manuelle bloque). */
    public const NAMESPACES = ['media-sujet', 'media-public', 'media-format', 'media-possible'];

    public const PREFIXE_SECTEUR = 'secteur-';

    /**
     * Mots-clés des thèmes (forme normalisée : minuscules, sans accent, la
     * ponctuation devient espace) => poids. 3 : sans ambiguïté ; 2 : fort ;
     * 1 : indice qui ne vaut que confirmé ailleurs.
     *
     * @var array<string, array<string, int>>
     */
    public const THEMES_MOTS = [
        'ia-tech' => [
            'intelligence artificielle' => 3, 'ia' => 2, 'ia generative' => 3, 'chatgpt' => 3,
            'tech' => 2, 'high tech' => 3, 'technologie' => 2, 'numerique' => 2, 'digital' => 1,
            'informatique' => 2, 'cybersecurite' => 3, 'logiciel' => 1, 'data' => 1, 'cloud' => 2,
            'innovation' => 1, 'geek' => 2, 'transformation digitale' => 3, 'transformation numerique' => 3,
            'objets connectes' => 2, 'robotique' => 2, 'telecom' => 2, 'web' => 1,
            'startup' => 1, 'start up' => 1,
        ],
        'economie-entreprise' => [
            'economie' => 3, 'eco' => 2, 'economique' => 2, 'entreprise' => 2, 'business' => 2,
            'finance' => 2, 'bourse' => 3, 'marche' => 1, 'decideur' => 2, 'affaires' => 1,
            'monde des affaires' => 3, 'conjoncture' => 3, 'investissement' => 1, 'industrie' => 1,
            'export' => 1, 'b2b' => 2, 'fiscalite' => 2, 'argent' => 1, 'patrimoine' => 1,
        ],
        'pme-entrepreneurs' => [
            'pme' => 3, 'tpe' => 3, 'eti' => 2, 'entrepreneur' => 3, 'entrepreneuriat' => 3,
            'entreprendre' => 3, 'creation d entreprise' => 3, 'createur d entreprise' => 3,
            'createurs d entreprise' => 3, 'reprise d entreprise' => 3, 'startup' => 2, 'start up' => 2,
            'jeune pousse' => 2, 'independant' => 1, 'freelance' => 1, 'auto entrepreneur' => 3,
            'micro entrepreneur' => 3, 'chef d entreprise' => 3, 'chefs d entreprise' => 3,
            'dirigeant' => 2, 'franchise' => 1, 'artisan' => 1, 'levee de fonds' => 2,
        ],
        'rh-management' => [
            'ressources humaines' => 3, 'rh' => 3, 'drh' => 3, 'management' => 3, 'manager' => 2,
            'recrutement' => 2, 'recruter' => 1, 'emploi' => 1, 'carriere' => 1,
            'formation professionnelle' => 2, 'qvt' => 3, 'qualite de vie au travail' => 3,
            'leadership' => 2, 'talent' => 1, 'paie' => 2, 'droit du travail' => 2, 'droit social' => 2,
            'travail' => 1, 'teletravail' => 2, 'competences' => 1, 'marque employeur' => 3,
            'dialogue social' => 2,
        ],
        'regional' => [
            'regional' => 2, 'regionale' => 2, 'region' => 1, 'local' => 1, 'locale' => 1,
            'actualite locale' => 3, 'info locale' => 3, 'infos locales' => 3, 'votre ville' => 3,
            'votre departement' => 3, 'votre region' => 3, 'pres de chez vous' => 3,
            'quotidien regional' => 3, 'presse regionale' => 3, 'departement' => 1, 'commune' => 1,
            'auvergne' => 2, 'rhone alpes' => 2, 'savoie' => 2, 'dauphine' => 2, 'bretagne' => 2,
            'normandie' => 2, 'occitanie' => 2, 'provence' => 2, 'paca' => 2, 'alsace' => 2,
            'lorraine' => 2, 'champagne' => 1, 'picardie' => 2, 'hauts de france' => 2,
            'ile de france' => 2, 'grand est' => 2, 'bourgogne' => 2, 'franche comte' => 2,
            'centre val de loire' => 2, 'pays de la loire' => 2, 'nouvelle aquitaine' => 2,
            'aquitaine' => 2, 'limousin' => 2, 'poitou' => 2, 'charentes' => 2, 'corse' => 2,
            'lyon' => 2, 'grenoble' => 2, 'saint etienne' => 2, 'clermont ferrand' => 2, 'annecy' => 2,
            'chambery' => 2, 'marseille' => 2, 'toulouse' => 2, 'bordeaux' => 2, 'lille' => 2,
            'nantes' => 2, 'strasbourg' => 2, 'rennes' => 2, 'montpellier' => 2, 'dijon' => 2,
            'rouen' => 2, 'caen' => 2, 'reims' => 2, 'metz' => 2, 'nancy' => 2, 'brest' => 2,
            'toulon' => 2, 'angers' => 2, 'limoges' => 2, 'perpignan' => 2, 'besancon' => 2,
            'orleans' => 2, 'avignon' => 2, 'bayonne' => 2, 'la reunion' => 2, 'guadeloupe' => 2,
            'martinique' => 2, 'guyane' => 2, 'mayotte' => 2,
        ],
        'grand-public' => [
            'grand public' => 3, 'sport' => 2, 'football' => 2, 'foot' => 2, 'rugby' => 2,
            'people' => 3, 'celebrite' => 3, 'meteo' => 2, 'horoscope' => 3, 'faits divers' => 3,
            'fait divers' => 3, 'cuisine' => 2, 'recette' => 2, 'beaute' => 2, 'mode' => 1,
            'loisirs' => 2, 'cinema' => 2, 'musique' => 1, 'programme tv' => 3, 'divertissement' => 2,
            'jardin' => 2, 'maison' => 1, 'famille' => 1, 'voyage' => 1, 'insolite' => 2,
            'toute l actualite' => 2, 'politique' => 1, 'international' => 1, 'societe' => 1,
            'culture' => 1, 'actualite' => 1, 'serie' => 1, 'jeux' => 1,
        ],
    ];

    /**
     * Mots de la presse PROFESSIONNELLE en général (thème `metiers-secteurs`).
     *
     * @var array<string, int>
     */
    public const METIERS_MOTS = [
        'presse professionnelle' => 3, 'revue professionnelle' => 3, 'magazine professionnel' => 3,
        'journal professionnel' => 3, 'professionnel' => 2, 'filiere' => 2, 'metier' => 1,
        'salon professionnel' => 2, 'acteurs du secteur' => 3, 'acteurs de la filiere' => 3,
    ];

    /**
     * Secteurs couverts par une presse professionnelle — clés de
     * `Taxonomy::SECTEURS` (même libellé), mots-clés => poids. Un mot
     * ambigu, qui parle aussi au grand public (« santé », « commerce »),
     * pèse 1.
     *
     * @var array<string, array<string, int>>
     */
    public const SECTEURS_MOTS = [
        'agriculture' => [
            'agriculture' => 2, 'agricole' => 2, 'agriculteur' => 2, 'eleveur' => 2, 'elevage' => 2,
            'viticulture' => 2, 'viticole' => 2, 'vigneron' => 2, 'cereales' => 2,
            'exploitation agricole' => 3, 'machinisme agricole' => 3, 'semences' => 2,
        ],
        'agroalimentaire' => [
            'agroalimentaire' => 3, 'industrie alimentaire' => 3, 'iaa' => 2,
        ],
        'industrie' => [
            'industriel' => 2, 'usine' => 2, 'plasturgie' => 3, 'metallurgie' => 3, 'sous traitance' => 2,
            'production industrielle' => 3, 'maintenance industrielle' => 3, 'industrie' => 1, 'mecanique' => 1,
        ],
        'energie' => [
            'energie' => 2, 'energies renouvelables' => 3, 'photovoltaique' => 3, 'eolien' => 3,
            'nucleaire' => 2, 'hydrogene' => 2, 'electricite' => 1,
        ],
        'btp' => [
            'btp' => 3, 'batiment' => 3, 'travaux publics' => 3, 'construction' => 2, 'chantier' => 2,
            'maconnerie' => 3, 'menuiserie' => 2, 'gros oeuvre' => 3, 'second oeuvre' => 3, 'renovation' => 1,
        ],
        'automobile' => [
            'automobile' => 2, 'garagiste' => 3, 'concessionnaire' => 3, 'apres vente' => 2,
            'pieces detachees' => 2, 'carrosserie' => 2, 'garage' => 1,
        ],
        'commerce_detail' => [
            'commercant' => 2, 'grande distribution' => 3, 'retail' => 2, 'point de vente' => 2,
            'commerce' => 1, 'distribution' => 1, 'magasin' => 1,
        ],
        'transport_logistique' => [
            'transport' => 2, 'logistique' => 3, 'supply chain' => 3, 'routier' => 2, 'fret' => 3,
            'entreposage' => 3, 'transporteur' => 3,
        ],
        'hebergement_tourisme' => [
            'tourisme' => 2, 'hotellerie' => 3, 'camping' => 2, 'agence de voyage' => 2, 'hotel' => 1,
        ],
        'restauration' => [
            'restauration' => 2, 'cafes hotels restaurants' => 3, 'chr' => 3, 'metiers de bouche' => 3,
            'boulangerie' => 2, 'restaurant' => 1,
        ],
        'banque_finance' => [
            'banque' => 2, 'bancaire' => 2, 'fintech' => 3, 'gestion de patrimoine' => 2, 'asset management' => 3,
        ],
        'assurance' => [
            'assurance' => 2, 'assureur' => 3, 'courtier' => 2, 'courtage' => 3, 'mutuelle' => 2,
        ],
        'immobilier' => [
            'immobilier' => 2, 'agent immobilier' => 3, 'promotion immobiliere' => 3, 'promoteur' => 2,
            'syndic' => 2, 'gestion locative' => 3, 'notaire' => 2,
        ],
        'droit' => [
            'avocat' => 2, 'juriste' => 3, 'juridique' => 2, 'jurisprudence' => 3, 'barreau' => 3, 'droit' => 1,
        ],
        'comptabilite_audit' => [
            'expert comptable' => 3, 'experts comptables' => 3, 'comptabilite' => 2, 'comptable' => 2,
            'commissaire aux comptes' => 3, 'audit' => 1,
        ],
        'sante' => [
            'medecin' => 2, 'medical' => 2, 'medicale' => 2, 'pharmacie' => 2, 'pharmacien' => 3, 'officine' => 3,
            'hopital' => 2, 'infirmier' => 2, 'infirmiere' => 2, 'soignant' => 2, 'professionnels de sante' => 3,
            'kinesitherapeute' => 3, 'dentaire' => 2, 'veterinaire' => 2, 'clinique' => 2, 'sante' => 1,
        ],
        'enseignement_formation' => [
            'enseignement' => 2, 'enseignant' => 2, 'pedagogie' => 2, 'education' => 1, 'formation' => 1,
        ],
        'marketing_publicite' => [
            'marketing' => 2, 'publicite' => 2, 'annonceur' => 3, 'agence de communication' => 2, 'communication' => 1,
        ],
    ];

    /** @var array<string, array<string, int>> */
    public const PUBLICS_MOTS = [
        'dirigeants' => [
            'dirigeant' => 3, 'decideur' => 3, 'chef d entreprise' => 3, 'chefs d entreprise' => 3,
            'patron' => 2, 'ceo' => 2, 'pdg' => 2, 'comex' => 3, 'codir' => 3, 'cadre dirigeant' => 3,
            'cadres dirigeants' => 3, 'entrepreneur' => 2, 'daf' => 3, 'drh' => 2, 'dsi' => 2,
            'executive' => 2, 'c level' => 3, 'manager' => 1,
        ],
        'pros-secteur' => [
            'professionnel' => 2, 'pros' => 2, 'les pros' => 3, 'presse professionnelle' => 3,
            'revue professionnelle' => 3, 'magazine professionnel' => 3, 'journal professionnel' => 3,
            'reserve aux professionnels' => 3, 'b2b' => 2, 'filiere' => 2, 'acteurs de la filiere' => 3,
            'salon professionnel' => 2, 'abonnement professionnel' => 3,
        ],
        'grand-public' => [
            'grand public' => 3, 'tous publics' => 3, 'toute la famille' => 3, 'telespectateur' => 2,
            'pour tous' => 1, 'lecteur' => 1, 'auditeur' => 1,
        ],
    ];

    /**
     * Bonus de public quand un thème est retenu : thème => [public => bonus].
     *
     * @var array<string, array<string, int>>
     */
    public const PUBLICS_BONUS = [
        'pme-entrepreneurs' => ['dirigeants' => 2],
        'economie-entreprise' => ['dirigeants' => 1],
        'rh-management' => ['dirigeants' => 1],
        'metiers-secteurs' => ['pros-secteur' => 2],
        'grand-public' => ['grand-public' => 2],
    ];

    /** @var array<string, array<string, int>> */
    public const FORMATS_MOTS = [
        'magazine-eco' => [
            'magazine economique' => 3, 'emission economique' => 3, 'economie' => 2, 'eco' => 2,
            'business' => 2, 'entreprise' => 2, 'bourse' => 2, 'entrepreneur' => 2, 'finance' => 2,
            'argent' => 1, 'consommation' => 1,
        ],
        'talk-show' => [
            'talk show' => 3, 'late show' => 3, 'emission de debat' => 3, 'chroniqueur' => 3,
            'debat' => 2, 'talk' => 2, 'polemique' => 2, 'plateau' => 1, 'invite' => 1, 'interview' => 1,
        ],
        'jt-info' => [
            'journal televise' => 3, 'jt' => 3, 'info en continu' => 3, 'chaine d information' => 3,
            'flash info' => 3, 'le journal' => 2, 'edition speciale' => 2, '13h' => 2, '20h' => 2,
            'information' => 1, 'info' => 1, 'actualite' => 1, 'meteo' => 1,
        ],
        'tech' => [
            'high tech' => 3, 'geek' => 3, 'technologie' => 2, 'numerique' => 2, 'jeux video' => 2,
            'tech' => 2, 'gadget' => 2, 'intelligence artificielle' => 2, 'innovation' => 1,
        ],
        'fiction-jeu' => [
            'telefilm' => 3, 'feuilleton' => 3, 'fiction' => 3, 'jeu televise' => 3, 'quiz' => 3,
            'dessin anime' => 3, 'dessins animes' => 3, 'telerealite' => 3, 'tele realite' => 3,
            'sitcom' => 3, 'soap' => 3, 'cartoon' => 3, 'serie' => 2, 'jeu' => 2, 'saison' => 2,
            'episode' => 2, 'film' => 1, 'divertissement' => 1,
        ],
    ];

    /** @var array<string, int> */
    public const VERDICT_MEDIA_MOTS = [
        'redaction' => 3, 'la redaction' => 3, 'nos journalistes' => 3, 'journaliste' => 2,
        'abonnez vous' => 3, 's abonner' => 3, 'abonnement' => 2, 'a la une' => 3, 'rubrique' => 2,
        'derniere actualite' => 2, 'dernieres actualites' => 2, 'dernieres infos' => 2, 'edito' => 2,
        'editorial' => 2, 'magazine' => 2, 'journal' => 2, 'journaux' => 2, 'kiosque' => 3,
        'lire la suite' => 1, 'lire l article' => 2, 'publie le' => 2, 'mis a jour le' => 2,
        'carte de presse' => 3, 'cppap' => 3, 'presse en ligne' => 3, 'webzine' => 3,
        'webmagazine' => 3, 'replay' => 2, 'podcast' => 1, 'chronique' => 1, 'article' => 1,
        'actualite' => 1,
    ];

    /** @var array<string, int> */
    public const VERDICT_PAS_MEDIA_MOTS = [
        'devis' => 3, 'demande de devis' => 3, 'nos services' => 3, 'nos prestations' => 3,
        'agence web' => 3, 'agence digitale' => 3, 'creation de site' => 3, 'developpement web' => 3,
        'nos solutions' => 3, 'demander une demo' => 3, 'ajouter au panier' => 3, 'esn' => 3,
        'infogerance' => 3, 'maintenance informatique' => 3, 'referencement' => 2, 'seo' => 2,
        'nos clients' => 2, 'nos references' => 2, 'tarif' => 2, 'demo' => 2, 'essai gratuit' => 2,
        'saas' => 2, 'integrateur' => 2, 'hebergement web' => 2, 'panier' => 2, 'nos offres' => 2,
        'nos produits' => 2, 'prendre rendez vous' => 2, 'prestation' => 1, 'site internet' => 1,
        'application mobile' => 1, 'logiciel' => 1, 'solution' => 1, 'boutique' => 1,
        'livraison' => 1, 'expertise' => 1, 'accompagnement' => 1, 'conseil' => 1, 'cgv' => 1,
    ];

    /** Bonus « média » d'une page structurée en articles datés. */
    public const BONUS_STRUCTURE = 3;

    public const STRUCTURE_MIN = 3;

    /**
     * Classe un média.
     *
     * @param  array<string, string>  $zones  textes bruts par zone (`nom`, `titre`, `menu`, `texte`)
     * @param  array{articles?: int, dates?: int}  $structure  compteurs de la page lue
     * @param  list<string>  $typesMedia  `media.media_type` des lignes vivantes
     * @param  list<string>  $zonesDiffusion  `media.diffusion_zone` des lignes vivantes
     * @param  bool  $incertain  la fiche est un média incertain (`MediaIncertain`)
     * @return array{v: int, lecture: string, themes: list<string>, secteurs: list<string>, publics: list<string>, format: ?string, verdict: ?string, scores: array<string, int>}
     */
    public static function classer(array $zones, array $structure, array $typesMedia, array $zonesDiffusion, bool $incertain, string $lecture): array
    {
        $z = [];
        foreach (array_keys(self::POIDS_ZONES) as $zone) {
            $z[$zone] = self::normaliser($zones[$zone] ?? '');
        }
        $scores = [];

        // ── Thèmes ───────────────────────────────────────────────────────
        // Retenu : score au seuil ET signal SÛR — dans le nom ou le titre, ou
        // au moins deux mots distincts. Un seul mot de menu (« Immobilier »,
        // « Management ») ne classe jamais.
        $themes = [];
        $scoreParTheme = [];
        $nomParTheme = [];
        foreach (self::THEMES_MOTS as $theme => $mots) {
            $a = self::analyse($z, $mots);
            if ($theme === 'regional' && self::zoneLocale($zonesDiffusion)) {
                // Zone de diffusion donnée par la SOURCE : une donnée, signal sûr.
                $a = ['score' => $a['score'] + self::SEUIL, 'mots' => $a['mots'] + 1, 'tous' => $a['tous'] + 1, 'fort' => true, 'nom' => $a['nom']];
            }
            $scores['theme:' . $theme] = $a['score'];
            if (! self::retenu($a)) {
                continue;
            }
            // ia-tech : un mot SPÉCIFIQUE hors corps, ou deux mots tech hors corps.
            if ($theme === 'ia-tech' && $a['mots'] < 2) {
                $specifiques = array_intersect_key($mots, array_flip(self::IA_TECH_SPECIFIQUES));
                if (self::analyse(['nom' => $z['nom'], 'titre' => $z['titre'], 'menu' => $z['menu']], $specifiques)['score'] === 0) {
                    continue;
                }
            }
            $themes[] = $theme;
            $scoreParTheme[$theme] = $a['score'];
            $nomParTheme[$theme] = $a['nom'];
        }
        // Presse PROFESSIONNELLE et secteur : signal FORT exigé, dans le nom
        // ou le titre (« le journal du BTP », « la revue des professionnels »).
        // Une rubrique de quotidien n'en fait pas une presse professionnelle.
        $secteurs = [];
        $scoreParSecteur = [];
        $nomParSecteur = [];
        foreach (self::SECTEURS_MOTS as $secteur => $mots) {
            $a = self::analyse($z, $mots);
            if ($a['score'] > 0) {
                $scores['secteur:' . $secteur] = $a['score'];
            }
            if ($a['score'] >= self::SEUIL && $a['fort']) {
                $secteurs[] = $secteur;
                $scoreParSecteur[$secteur] = $a['score'];
                $nomParSecteur[$secteur] = $a['nom'];
            }
        }
        $metiers = self::analyse($z, self::METIERS_MOTS);
        $scores['theme:metiers-secteurs'] = $metiers['score'];
        if ($secteurs !== [] || ($metiers['score'] >= self::SEUIL && $metiers['fort'])) {
            $themes[] = 'metiers-secteurs';
            $scoreParTheme['metiers-secteurs'] = max([$metiers['score'], ...array_values($scoreParSecteur)]);
            $nomParTheme['metiers-secteurs'] = $metiers['nom'] || in_array(true, $nomParSecteur, true);
        }

        // ── Dominance relative (v4) ─────────────────────────────────────────
        // Un quotidien régional a une rubrique économie, emploi, numérique :
        // ces thèmes passent le seuil mais pèsent peu à côté du régional / grand
        // public. On ne garde que les thèmes à 40 % au moins du PRINCIPAL, ou
        // présents dans le NOM du média.
        if ($themes !== []) {
            $retenus = [];
            foreach ($themes as $t) {
                $retenus[$t] = $scoreParTheme[$t] ?? 0;
            }
            $principal = max($retenus);
            $themes = self::dominants($retenus, $nomParTheme);
            $secteurs = in_array('metiers-secteurs', $themes, true)
                ? array_values(array_filter(
                    $secteurs,
                    static fn (string $sec): bool => self::domine($scoreParSecteur[$sec] ?? 0, $principal) || ($nomParSecteur[$sec] ?? false),
                ))
                : [];
        }

        // ── Format (télévision seulement) ──────────────────────────────────
        // `fiction-jeu` ne se lit que dans le NOM ou le TITRE : le menu d'une
        // chaîne généraliste (Séries, Films, Jeux) ne dit rien de l'émission.
        $format = null;
        // v3 : seulement si TOUTES les lignes média de la fiche sont de la
        // télévision — une ligne TV égarée sur une boutique ne fait pas un format.
        if ($typesMedia !== [] && array_diff($typesMedia, self::TYPES_TV) === []) {
            $parFormat = [];
            $surs = [];
            foreach (self::FORMATS_MOTS as $f => $mots) {
                $a = $f === 'fiction-jeu'
                    ? self::analyse(['nom' => $z['nom'], 'titre' => $z['titre']], $mots)
                    : self::analyse($z, $mots);
                $parFormat[$f] = $a['score'];
                $surs[$f] = self::retenu($a);
                $scores['format:' . $f] = $a['score'];
            }
            arsort($parFormat);
            $valeurs = array_values($parFormat);
            $meilleur = (string) array_key_first($parFormat);
            $format = ($surs[$meilleur] && $valeurs[0] > 2 * $valeurs[1]) ? $meilleur : self::INCONNU;
        }
        if ($format === 'fiction-jeu') {
            $themes = array_values(array_intersect($themes, self::THEMES_GARDES_PAR_FICTION));
            $secteurs = [];
        }

        // ── Public (v4) ─────────────────────────────────────────────────────
        // Le public se DÉDUIT du thème principal, il ne se lit pas seul :
        //   - `dirigeants` : le thème principal hors régional / grand public est
        //     économie, PME ou RH, son score atteint celui du thème grand public,
        //     et le public dirigeants l'emporte sur le public grand public ;
        //   - `pros-secteur` : de même, avec la presse professionnelle ;
        //   - `grand-public` : le thème grand public est le thème PRINCIPAL ;
        //   - sinon `inconnu`.
        $scorePublic = [];
        foreach (self::PUBLICS_MOTS as $public => $mots) {
            $bonus = 0;
            foreach (self::PUBLICS_BONUS as $theme => $parPublic) {
                if (in_array($theme, $themes, true)) {
                    $bonus += $parPublic[$public] ?? 0;
                }
            }
            $scorePublic[$public] = self::analyse($z, $mots)['score'] + $bonus;
            $scores['public:' . $public] = $scorePublic[$public];
        }
        $gardes = [];
        foreach ($themes as $t) {
            $gardes[$t] = $scoreParTheme[$t] ?? 0;
        }
        $publics = self::publicsDeduits($gardes, $scorePublic, $scores['theme:grand-public']);
        if ($format === 'fiction-jeu') {
            $publics = array_values(array_intersect($publics, ['grand-public']));
        }

        // ── Verdict « média possible » ────────────────────────────────────
        // La structure (articles, dates) ne suffit jamais seule : un blog
        // d'éditeur de logiciels a aussi des billets datés. Il faut au moins un
        // signe LEXICAL de média.
        $verdict = null;
        if ($incertain) {
            $verdict = self::VERDICT_A_VERIFIER;
            if ($lecture === self::LECTURE_SITE) {
                $lexical = self::analyse($z, self::VERDICT_MEDIA_MOTS, false);
                $media = $lexical['score'];
                if (($structure['articles'] ?? 0) >= self::STRUCTURE_MIN) {
                    $media += self::BONUS_STRUCTURE;
                }
                if (($structure['dates'] ?? 0) >= self::STRUCTURE_MIN) {
                    $media += self::BONUS_STRUCTURE;
                }
                $pas = self::score($z, self::VERDICT_PAS_MEDIA_MOTS, false);
                $scores['verdict:media'] = $media;
                $scores['verdict:pas-media'] = $pas;
                if ($lexical['tous'] > 0 && $media >= self::SEUIL_VERDICT && $media >= 2 * $pas) {
                    $verdict = self::VERDICT_MEDIA;
                } elseif ($pas >= self::SEUIL_VERDICT && $pas >= 2 * $media) {
                    $verdict = self::VERDICT_PAS_MEDIA;
                }
            }
        }

        ksort($scores);

        // v3 : un « média possible » qui ne SEMBLE pas un média n'a pas de
        // ligne éditoriale — aucun thème, aucun public, aucun format (même pas
        // `inconnu`), tant que le verdict n'est pas `semble-media`.
        if ($incertain && $verdict !== self::VERDICT_MEDIA) {
            return [
                'v' => self::VERSION,
                'lecture' => $lecture,
                'themes' => [],
                'secteurs' => [],
                'publics' => [],
                'format' => null,
                'verdict' => $verdict,
                'scores' => array_filter($scores, static fn (int $s): bool => $s > 0),
            ];
        }

        return [
            'v' => self::VERSION,
            'lecture' => $lecture,
            'themes' => $themes === [] ? [self::INCONNU] : array_values(array_unique($themes)),
            'secteurs' => $secteurs,
            'publics' => $publics === [] ? [self::INCONNU] : $publics,
            'format' => $format,
            'verdict' => $verdict,
            'scores' => array_filter($scores, static fn (int $s): bool => $s > 0),
        ];
    }

    /**
     * Le score d'une liste de mots-clés sur les zones NORMALISÉES.
     *
     * @param  array<string, string>  $zones
     * @param  array<string, int>  $mots
     */
    public static function score(array $zones, array $mots, bool $plafonnerTexte = true): int
    {
        return self::analyse($zones, $mots, $plafonnerTexte)['score'];
    }

    /**
     * Score, nombre de mots-clés DISTINCTS trouvés, et signal FORT (un mot
     * trouvé dans le nom ou le titre). `mots` ne compte que les mots trouvés
     * HORS du corps de page (nom, titre, menu) ; `tous` les compte partout ;
     * `nom` : un mot trouvé dans le NOM du média.
     *
     * @param  array<string, string>  $zones
     * @param  array<string, int>  $mots
     * @return array{score: int, mots: int, tous: int, fort: bool, nom: bool}
     */
    public static function analyse(array $zones, array $mots, bool $plafonnerTexte = true): array
    {
        $total = 0;
        $trouves = [];
        $horsCorps = [];
        $fort = false;
        $dansNom = false;
        foreach (self::POIDS_ZONES as $zone => $poidsZone) {
            $texte = $zones[$zone] ?? '';
            if (trim($texte) === '') {
                continue;
            }
            $s = 0;
            foreach ($mots as $mot => $poids) {
                if (self::contient($texte, $mot)) {
                    $s += $poids * $poidsZone;
                    $trouves[$mot] = true;
                    if ($zone !== 'texte') {
                        $horsCorps[$mot] = true;
                    }
                    if (in_array($zone, self::ZONES_FORTES, true)) {
                        $fort = true;
                    }
                    if ($zone === 'nom') {
                        $dansNom = true;
                    }
                }
            }
            if ($zone === 'texte' && $plafonnerTexte) {
                $s = min($s, self::PLAFOND_TEXTE);
            }
            $total += $s;
        }

        return ['score' => $total, 'mots' => count($horsCorps), 'tous' => count($trouves), 'fort' => $fort, 'nom' => $dansNom];
    }

    /**
     * Dominance relative (v4) : parmi les thèmes RETENUS (seuil et signal sûr
     * déjà passés), ceux dont le score atteint `DOMINANCE_MIN_DIXIEMES`/10 du
     * principal, ou qui sont dans le NOM du média.
     *
     * @param  array<string, int>  $retenus  thème => score
     * @param  array<string, bool>  $dansNom  thème => trouvé dans le nom
     * @return list<string>
     */
    public static function dominants(array $retenus, array $dansNom = []): array
    {
        if ($retenus === []) {
            return [];
        }
        $principal = max($retenus);
        $gardes = [];
        foreach ($retenus as $theme => $score) {
            if (self::domine($score, $principal) || ($dansNom[$theme] ?? false)) {
                $gardes[] = $theme;
            }
        }

        return $gardes;
    }

    /** Le score atteint-il 40 % du principal ? */
    public static function domine(int $score, int $principal): bool
    {
        return $score * 10 >= self::DOMINANCE_MIN_DIXIEMES * $principal;
    }

    /**
     * Le public DÉDUIT des thèmes gardés (v4) — voir la règle en tête.
     *
     * @param  array<string, int>  $gardes  thèmes gardés => score
     * @param  array<string, int>  $scorePublic  public => score (mots + bonus)
     * @param  int  $scoreThemeGrandPublic  score du thème grand public, gardé ou non
     * @return list<string>
     */
    public static function publicsDeduits(array $gardes, array $scorePublic, int $scoreThemeGrandPublic): array
    {
        $principalGlobal = self::premierMaximum($gardes);
        $principalPro = self::premierMaximum(array_diff_key($gardes, array_flip(self::THEMES_COUVERTURE)));
        $grandPublic = $scorePublic['grand-public'] ?? 0;
        $publics = [];
        if ($principalPro !== null && $gardes[$principalPro] >= $scoreThemeGrandPublic) {
            if (in_array($principalPro, self::THEMES_DIRIGEANTS, true) && ($scorePublic['dirigeants'] ?? 0) > $grandPublic) {
                $publics[] = 'dirigeants';
            } elseif ($principalPro === 'metiers-secteurs' && ($scorePublic['pros-secteur'] ?? 0) > $grandPublic) {
                $publics[] = 'pros-secteur';
            }
        }
        if ($principalGlobal === 'grand-public') {
            $publics[] = 'grand-public';
        }

        return $publics;
    }

    /**
     * La clé du plus grand score (la première à égalité), ou null si vide.
     *
     * @param  array<string, int>  $scores
     */
    private static function premierMaximum(array $scores): ?string
    {
        $cle = null;
        $max = -1;
        foreach ($scores as $k => $v) {
            if ($v > $max) {
                $max = $v;
                $cle = $k;
            }
        }

        return $cle;
    }

    /**
     * Une valeur est-elle retenue ? Seuil atteint ET signal sûr : fort (nom,
     * titre) ou au moins deux mots-clés distincts HORS du corps de page.
     *
     * @param  array{score: int, mots: int, tous: int, fort: bool, nom: bool}  $analyse
     */
    public static function retenu(array $analyse): bool
    {
        return $analyse['score'] >= self::SEUIL && ($analyse['fort'] || $analyse['mots'] >= 2);
    }

    /** Minuscules, sans accent, ponctuation → espace, entouré d'espaces. */
    public static function normaliser(string $texte): string
    {
        $t = Str::ascii(mb_strtolower($texte));
        $t = (string) preg_replace('/[^a-z0-9]+/', ' ', $t);

        return ' ' . trim($t) . ' ';
    }

    /** Le mot-clé (déjà normalisé, sans espaces autour) est-il un mot entier du texte normalisé ? */
    public static function contient(string $texteNormalise, string $mot): bool
    {
        return str_contains($texteNormalise, ' ' . $mot . ' ')
            || str_contains($texteNormalise, ' ' . $mot . 's ')
            || str_contains($texteNormalise, ' ' . $mot . 'x ');
    }

    /** @param  list<string>  $zonesDiffusion */
    private static function zoneLocale(array $zonesDiffusion): bool
    {
        foreach ($zonesDiffusion as $zone) {
            if (in_array(EtiquettesMedia::zone($zone), ['regional', 'departemental', 'local'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deux classements disent-ils la même chose ? (La date et les scores
     * n'entrent pas en compte : relire une page qui n'a pas changé de sens
     * n'écrit rien.)
     *
     * @param  array<string, mixed>  $a
     */
    public static function memeClassement(array $a, mixed $b): bool
    {
        if (! is_array($b)) {
            return false;
        }
        foreach (['v', 'lecture', 'themes', 'secteurs', 'publics', 'format', 'verdict'] as $cle) {
            if (($a[$cle] ?? null) !== ($b[$cle] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Les étiquettes désirées par le classement d'une fiche (pour
     * `AutoTaggerService::computeDesiredTags`). Vide si la fiche n'a pas de
     * média vivant ou pas de classement. Toute valeur hors référentiel est
     * ignorée (la métadonnée n'est pas une source de slugs libres).
     *
     * @param  mixed  $classement  `companies.metadata.classement_media`
     * @param  bool  $incertain  la fiche est encore un média incertain (`MediaIncertain`)
     * @return array<string, array{name: string, category: string}>
     */
    public static function desirees(int $companyId, mixed $classement, bool $aDesMedias, bool $incertain): array
    {
        if (! $aDesMedias || ! is_array($classement) || ! is_int($classement['v'] ?? null)) {
            return [];
        }

        $tags = [];
        foreach (self::chaines($classement['themes'] ?? null) as $theme) {
            if (isset(Taxonomy::MEDIA_THEMES_CLASSES[$theme])) {
                $tags['media-sujet:' . $theme] = [
                    'name' => 'Sujet du média : ' . Taxonomy::MEDIA_THEMES_CLASSES[$theme],
                    'category' => Taxonomy::TAG_NAMESPACES['media-sujet'],
                ];
            }
        }
        foreach (self::chaines($classement['secteurs'] ?? null) as $secteur) {
            if (isset(self::SECTEURS_MOTS[$secteur], Taxonomy::SECTEURS[$secteur])) {
                $tags['media-sujet:' . self::PREFIXE_SECTEUR . str_replace('_', '-', $secteur)] = [
                    'name' => 'Secteur couvert : ' . Taxonomy::SECTEURS[$secteur],
                    'category' => Taxonomy::TAG_NAMESPACES['media-sujet'],
                ];
            }
        }
        foreach (self::chaines($classement['publics'] ?? null) as $public) {
            if (isset(Taxonomy::MEDIA_PUBLICS[$public])) {
                $tags['media-public:' . $public] = [
                    'name' => 'Public du média : ' . Taxonomy::MEDIA_PUBLICS[$public],
                    'category' => Taxonomy::TAG_NAMESPACES['media-public'],
                ];
            }
        }
        $format = $classement['format'] ?? null;
        if (is_string($format) && isset(Taxonomy::MEDIA_FORMATS[$format])) {
            $tags['media-format:' . $format] = [
                'name' => 'Format TV : ' . Taxonomy::MEDIA_FORMATS[$format],
                'category' => Taxonomy::TAG_NAMESPACES['media-format'],
            ];
        }
        $verdict = $classement['verdict'] ?? null;
        if ($incertain && is_string($verdict) && isset(self::VERDICTS[$verdict])) {
            $tags['media-possible:' . $verdict] = [
                'name' => self::VERDICTS[$verdict],
                'category' => Taxonomy::TAG_NAMESPACES['media-possible'],
            ];
        }

        if ($tags === []) {
            return [];
        }

        // Une étiquette posée À LA MAIN dans un namespace : le classement
        // automatique n'y ajoute rien (la main gagne).
        foreach (self::namespacesManuels($companyId) as $namespace) {
            foreach (array_keys($tags) as $slug) {
                if (str_starts_with($slug, $namespace . ':')) {
                    unset($tags[$slug]);
                }
            }
        }

        return $tags;
    }

    /**
     * Les chaînes d'une valeur lue dans la métadonnée (rien si ce n'est pas une liste).
     *
     * @return list<string>
     */
    private static function chaines(mixed $valeur): array
    {
        $sortie = [];
        foreach (is_array($valeur) ? $valeur : [] as $v) {
            if (is_string($v)) {
                $sortie[] = $v;
            }
        }

        return $sortie;
    }

    /**
     * Le classement propose-t-il un verdict (qui remplace alors `a-verifier`) ?
     *
     * @param  array<string, mixed>  $tags
     */
    public static function aUnVerdict(array $tags): bool
    {
        foreach (array_keys(self::VERDICTS) as $verdict) {
            if (isset($tags['media-possible:' . $verdict])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Les namespaces du classement où la fiche porte une étiquette MANUELLE.
     * Pour `media-possible`, seul un VERDICT manuel compte (pas `a-verifier`).
     *
     * @return list<string>
     */
    public static function namespacesManuels(int $companyId): array
    {
        $slugs = DB::table('company_tag as ct')
            ->join('tags as t', 't.id', '=', 'ct.tag_id')
            ->where('ct.company_id', $companyId)
            ->where(static fn ($q) => $q->where('ct.assigned_by', 'user')->orWhere('t.kind', 'manual'))
            ->where(static function ($q): void {
                foreach (self::NAMESPACES as $namespace) {
                    $q->orWhere('t.slug', 'like', $namespace . ':%');
                }
            })
            ->pluck('t.slug')
            ->all();

        $namespaces = [];
        foreach ($slugs as $slug) {
            $slug = (string) $slug;
            $namespace = substr($slug, 0, (int) strpos($slug, ':'));
            if ($namespace === 'media-possible' && $slug === MediaIncertain::ETIQUETTE) {
                continue;
            }
            $namespaces[$namespace] = true;
        }

        return array_keys($namespaces);
    }
}
