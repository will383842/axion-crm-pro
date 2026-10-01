/**
 * FICHIER GÉNÉRÉ — NE PAS MODIFIER À LA MAIN.
 *
 * Source : `backend/app/Crm/Taxonomy.php` (référentiel unique, chantier 1),
 * et, pour les métiers, `backend/resources/referentiels/metiers.csv` (chantier 2).
 * Régénérer : `php artisan crm:referentiels:generer-front` (depuis `backend/`).
 * Garde : `backend/tests/Unit/Crm/ReferentielsFrontTest.php` rougit si ce
 * fichier diffère de ce que la commande produirait.
 */

export interface EntreeReferentiel {
  readonly code: string;
  readonly libelle: string;
}

/** Secteurs d'activité — `companies.sector_main`. */
export const SECTEURS = [
  { code: "agriculture", libelle: "Agriculture, sylviculture, pêche" },
  { code: "agroalimentaire", libelle: "Agroalimentaire et boissons" },
  { code: "industrie", libelle: "Industrie" },
  { code: "energie", libelle: "Énergie" },
  { code: "eau_dechets", libelle: "Eau, déchets, dépollution" },
  { code: "btp", libelle: "Bâtiment et travaux publics" },
  { code: "automobile", libelle: "Automobile (commerce et réparation)" },
  { code: "commerce_gros", libelle: "Commerce de gros" },
  { code: "commerce_detail", libelle: "Commerce de détail" },
  { code: "transport_logistique", libelle: "Transport et logistique" },
  { code: "hebergement_tourisme", libelle: "Hébergement et tourisme" },
  { code: "restauration", libelle: "Restauration" },
  { code: "edition_medias", libelle: "Édition, audiovisuel, médias" },
  { code: "numerique_telecoms", libelle: "Numérique et télécoms" },
  { code: "banque_finance", libelle: "Banque et finance" },
  { code: "assurance", libelle: "Assurance" },
  { code: "immobilier", libelle: "Immobilier" },
  { code: "droit", libelle: "Droit" },
  { code: "comptabilite_audit", libelle: "Comptabilité et audit" },
  { code: "conseil_management", libelle: "Conseil et management" },
  { code: "architecture_ingenierie", libelle: "Architecture, ingénierie, contrôle technique" },
  { code: "recherche_developpement", libelle: "Recherche et développement" },
  { code: "marketing_publicite", libelle: "Marketing, publicité, études" },
  { code: "services_specialises", libelle: "Design, photo, traduction et services spécialisés" },
  { code: "services_entreprises", libelle: "Services aux entreprises" },
  { code: "enseignement_formation", libelle: "Enseignement et formation" },
  { code: "sante", libelle: "Santé humaine et vétérinaire" },
  { code: "medico_social", libelle: "Médico-social et action sociale" },
  { code: "culture_sport_loisirs", libelle: "Culture, sport et loisirs" },
  { code: "services_personne", libelle: "Services à la personne" },
  { code: "administration_publique", libelle: "Administration publique" },
  { code: "interprofessionnel", libelle: "Interprofessionnel" },
  { code: "non_classe", libelle: "Non classé" },
] as const satisfies readonly EntreeReferentiel[];

export type CleSecteur = (typeof SECTEURS)[number]['code'];

/** Tailles — `companies.size_category`. */
export const TAILLES = [
  { code: "tpe", libelle: "TPE" },
  { code: "pme", libelle: "PME" },
  { code: "eti", libelle: "ETI" },
  { code: "grand_groupe", libelle: "Grand groupe" },
] as const satisfies readonly EntreeReferentiel[];

export type CleTaille = (typeof TAILLES)[number]['code'];

/** Natures d'entité — `companies.entity_nature`. */
export const NATURES = [
  { code: "entreprise", libelle: "Entreprise" },
  { code: "association", libelle: "Association" },
  { code: "cci", libelle: "Chambre de commerce" },
  { code: "enseignement", libelle: "Enseignement" },
  { code: "cabinet", libelle: "Cabinet (conseil, avocats)" },
  { code: "institution", libelle: "Institution" },
  { code: "media", libelle: "Média" },
  { code: "reseau", libelle: "Réseau ou club d'affaires" },
  { code: "federation", libelle: "Organisation professionnelle" },
] as const satisfies readonly EntreeReferentiel[];

export type CleNature = (typeof NATURES)[number]['code'];

/** Régions (code INSEE) — `companies.region_code`, `events.region`. */
export const REGIONS = [
  { code: "84", libelle: "Auvergne-Rhône-Alpes" },
  { code: "27", libelle: "Bourgogne-Franche-Comté" },
  { code: "53", libelle: "Bretagne" },
  { code: "24", libelle: "Centre-Val de Loire" },
  { code: "94", libelle: "Corse" },
  { code: "44", libelle: "Grand Est" },
  { code: "32", libelle: "Hauts-de-France" },
  { code: "11", libelle: "Île-de-France" },
  { code: "28", libelle: "Normandie" },
  { code: "75", libelle: "Nouvelle-Aquitaine" },
  { code: "76", libelle: "Occitanie" },
  { code: "52", libelle: "Pays de la Loire" },
  { code: "93", libelle: "Provence-Alpes-Côte d'Azur" },
  { code: "01", libelle: "Guadeloupe" },
  { code: "02", libelle: "Martinique" },
  { code: "03", libelle: "Guyane" },
  { code: "04", libelle: "La Réunion" },
  { code: "06", libelle: "Mayotte" },
] as const satisfies readonly EntreeReferentiel[];

export type CodeRegion = (typeof REGIONS)[number]['code'];

/** Familles d'organisation professionnelle — `federations.famille`. */
export const FAMILLES_FEDERATION = [
  { code: "confederation", libelle: "Confédération interprofessionnelle" },
  { code: "federation_syndicat_pro", libelle: "Fédération ou syndicat professionnel" },
  { code: "ordre", libelle: "Ordre professionnel" },
  { code: "profession_reglementee", libelle: "Chambre ou compagnie de profession réglementée" },
  { code: "chambre_consulaire", libelle: "Chambre consulaire" },
  { code: "syndicat_salaries", libelle: "Syndicat de salariés" },
  { code: "association_metier", libelle: "Association de métier ou de fonction" },
  { code: "association_entreprises", libelle: "Association d'entreprises ou de dirigeants" },
  { code: "interprofession", libelle: "Interprofession ou organisme technique" },
  { code: "pole_cluster", libelle: "Pôle de compétitivité ou cluster" },
  { code: "financeur_formation", libelle: "Financeur de la formation (OPCO…)" },
  { code: "developpement_economique", libelle: "Développement économique" },
  { code: "association_elus", libelle: "Association d'élus" },
  { code: "mutuelle_agricole", libelle: "Mutuelle ou caisse agricole" },
  { code: "proprietaires_locataires", libelle: "Propriétaires et locataires" },
] as const satisfies readonly EntreeReferentiel[];

export type CleFamilleFederation = (typeof FAMILLES_FEDERATION)[number]['code'];

/** Niveaux — `federations.niveau`. */
export const NIVEAUX_FEDERATION = [
  { code: "national", libelle: "National" },
  { code: "regional", libelle: "Régional" },
  { code: "departemental", libelle: "Départemental" },
  { code: "local", libelle: "Local" },
] as const satisfies readonly EntreeReferentiel[];

export type CleNiveauFederation = (typeof NIVEAUX_FEDERATION)[number]['code'];

/** Contactabilité — `federations.contactabilite`. */
export const CONTACTABILITES = [
  { code: "email_verifie", libelle: "E-mail vérifié" },
  { code: "formulaire_seulement", libelle: "Formulaire seulement" },
  { code: "telephone_seulement", libelle: "Téléphone seulement" },
  { code: "site_ou_linkedin_seulement", libelle: "Site ou LinkedIn seulement" },
  { code: "aucun_contact", libelle: "Aucun contact" },
] as const satisfies readonly EntreeReferentiel[];

export type CleContactabilite = (typeof CONTACTABILITES)[number]['code'];

/** Certitude du classement — `federations.certitude`. */
export const CERTITUDES = [
  { code: "haute", libelle: "Haute" },
  { code: "moyenne", libelle: "Moyenne" },
  { code: "faible", libelle: "Faible" },
] as const satisfies readonly EntreeReferentiel[];

export type CleCertitude = (typeof CERTITUDES)[number]['code'];

/** Pertinence — `federations.pertinence`. */
export const PERTINENCES = [
  { code: "haute", libelle: "Haute" },
  { code: "moyenne", libelle: "Moyenne" },
  { code: "faible", libelle: "Faible" },
] as const satisfies readonly EntreeReferentiel[];

export type ClePertinence = (typeof PERTINENCES)[number]['code'];

/** Démarche « partenariat » — `federations.partenariat`. */
export const PARTENARIATS = [
  { code: "aucun", libelle: "Pas encore proposé" },
  { code: "propose", libelle: "Proposé" },
  { code: "en_discussion", libelle: "En discussion" },
  { code: "accepte", libelle: "Accepté" },
  { code: "refuse", libelle: "Refusé" },
] as const satisfies readonly EntreeReferentiel[];

export type ClePartenariat = (typeof PARTENARIATS)[number]['code'];

/** Métiers — étiquette automatique `metier-<code>` (depuis la sous-classe NAF rév. 2). */
export const METIERS = [
  { code: "professions-juridiques", libelle: "Avocats, notaires et professions juridiques" },
  { code: "experts-comptables", libelle: "Experts-comptables et cabinets comptables" },
  { code: "medecins", libelle: "Médecins généralistes et spécialistes" },
  { code: "dentistes", libelle: "Dentistes" },
  { code: "pharmacies", libelle: "Pharmacies" },
  { code: "infirmiers-sages-femmes", libelle: "Infirmiers et sages-femmes" },
  { code: "kines-reeducation", libelle: "Kinésithérapeutes, orthophonistes, podologues (rééducation)" },
  { code: "autres-praticiens-sante", libelle: "Autres praticiens de santé (psychologues, ostéopathes…)" },
  { code: "laboratoires-analyses", libelle: "Laboratoires d'analyses médicales" },
  { code: "ambulances", libelle: "Ambulances" },
  { code: "hopitaux-cliniques", libelle: "Hôpitaux et cliniques" },
  { code: "ehpad-hebergement-medicalise", libelle: "EHPAD et hébergement médicalisé (âge, handicap)" },
  { code: "residences-autonomie", libelle: "Résidences autonomie et hébergement social pour personnes âgées" },
  { code: "aide-a-domicile", libelle: "Aide à domicile" },
  { code: "creches", libelle: "Crèches et accueil de jeunes enfants" },
  { code: "veterinaires", libelle: "Vétérinaires" },
  { code: "opticiens", libelle: "Opticiens" },
  { code: "architectes", libelle: "Architectes" },
  { code: "geometres", libelle: "Géomètres-experts" },
  { code: "bureaux-etudes", libelle: "Bureaux d'études, ingénierie et économistes de la construction" },
  { code: "controle-technique", libelle: "Contrôle technique, analyses et diagnostics" },
  { code: "agents-immobiliers", libelle: "Agents immobiliers et administrateurs de biens" },
  { code: "promoteurs-marchands-biens", libelle: "Promoteurs immobiliers et marchands de biens" },
  { code: "location-immobiliere", libelle: "Location et gestion de biens immobiliers (SCI…)" },
  { code: "holdings-sieges", libelle: "Holdings et sièges sociaux" },
  { code: "banques-credit", libelle: "Banques et établissements de crédit" },
  { code: "assurance", libelle: "Assurance (compagnies, agents et courtiers)" },
  { code: "gestion-financiere", libelle: "Courtage de valeurs et gestion de fonds" },
  { code: "garages-carrosseries", libelle: "Garages, mécanique et carrosserie" },
  { code: "commerce-automobile", libelle: "Vente de véhicules, motos et pièces automobiles" },
  { code: "location-vehicules", libelle: "Location de véhicules" },
  { code: "taxis-vtc", libelle: "Taxis et VTC" },
  { code: "autocars", libelle: "Autocaristes et transport routier de voyageurs" },
  { code: "transport-routier", libelle: "Transport routier de marchandises" },
  { code: "demenageurs", libelle: "Déménageurs" },
  { code: "logistique-messagerie", libelle: "Logistique, entreposage, messagerie et livraison" },
  { code: "maconnerie-gros-oeuvre", libelle: "Maçonnerie, gros œuvre et construction de bâtiments" },
  { code: "travaux-publics", libelle: "Travaux publics, terrassement et démolition" },
  { code: "electriciens", libelle: "Électriciens" },
  { code: "plombiers-chauffagistes", libelle: "Plombiers, chauffagistes et climatisation" },
  { code: "menuisiers", libelle: "Menuisiers, serruriers et agenceurs" },
  { code: "peintres", libelle: "Peintres et vitriers" },
  { code: "platriers-isolation", libelle: "Plâtriers, plaquistes et isolation" },
  { code: "carreleurs-revetements", libelle: "Carreleurs et revêtements de sols et murs" },
  { code: "couvreurs-charpentiers", libelle: "Couvreurs, charpentiers et étancheurs" },
  { code: "paysagistes", libelle: "Paysagistes" },
  { code: "nettoyage", libelle: "Nettoyage et propreté" },
  { code: "securite-privee", libelle: "Sécurité privée" },
  { code: "interim-recrutement", libelle: "Intérim, recrutement et placement" },
  { code: "services-informatiques", libelle: "Services informatiques (ESN, développement, conseil)" },
  { code: "editeurs-logiciels", libelle: "Éditeurs de logiciels" },
  { code: "telecoms", libelle: "Opérateurs de télécommunications" },
  { code: "agences-communication", libelle: "Agences de communication et relations publiques" },
  { code: "agences-publicite", libelle: "Agences de publicité, régies et études de marché" },
  { code: "conseil-gestion", libelle: "Conseil en gestion et management" },
  { code: "design-graphisme", libelle: "Designers, graphistes et architectes d'intérieur" },
  { code: "photographes", libelle: "Photographes" },
  { code: "traducteurs", libelle: "Traducteurs et interprètes" },
  { code: "evenementiel-salons", libelle: "Organisateurs de salons, foires et congrès" },
  { code: "secretariat-domiciliation", libelle: "Secrétariat, domiciliation et centres d'appels" },
  { code: "agents-commerciaux", libelle: "Agents commerciaux et intermédiaires du commerce" },
  { code: "organismes-formation", libelle: "Organismes de formation continue" },
  { code: "auto-ecoles", libelle: "Auto-écoles" },
  { code: "coiffeurs", libelle: "Coiffeurs" },
  { code: "esthetique", libelle: "Instituts de beauté et soins du corps" },
  { code: "boulangeries-patisseries", libelle: "Boulangeries et pâtisseries" },
  { code: "boucheries-charcuteries", libelle: "Boucheries et charcuteries" },
  { code: "commerces-alimentaires", libelle: "Épiceries, primeurs, cavistes et commerces alimentaires" },
  { code: "grande-distribution", libelle: "Supermarchés, hypermarchés et grands magasins" },
  { code: "tabac-presse", libelle: "Tabac et presse" },
  { code: "restaurants", libelle: "Restaurants (y compris restauration rapide)" },
  { code: "traiteurs-restauration-collective", libelle: "Traiteurs et restauration collective" },
  { code: "cafes-bars", libelle: "Cafés et bars" },
  { code: "hotels-hebergement", libelle: "Hôtels, campings et hébergement touristique" },
  { code: "agences-voyage", libelle: "Agences de voyage et voyagistes" },
  { code: "fleuristes", libelle: "Fleuristes, jardineries et animaleries" },
  { code: "habillement-chaussures", libelle: "Magasins d'habillement et de chaussures" },
  { code: "bijouteries", libelle: "Bijouteries et horlogeries" },
  { code: "equipement-maison", libelle: "Meubles, bricolage et équipement de la maison" },
  { code: "vente-distance", libelle: "Vente à distance et e-commerce" },
  { code: "vente-domicile", libelle: "Vente à domicile" },
  { code: "pompes-funebres", libelle: "Pompes funèbres" },
  { code: "salles-sport-clubs", libelle: "Salles de sport et clubs sportifs" },
  { code: "arts-spectacle", libelle: "Arts du spectacle et création artistique" },
  { code: "production-audiovisuelle", libelle: "Production audiovisuelle et musicale" },
  { code: "edition-presse", libelle: "Édition et presse" },
  { code: "imprimeries", libelle: "Imprimeries" },
  { code: "agriculteurs-eleveurs", libelle: "Agriculteurs et éleveurs" },
  { code: "viticulture", libelle: "Viticulteurs et vinification" },
  { code: "mecanique-industrielle", libelle: "Mécanique industrielle, usinage et maintenance" },
  { code: "metallerie-chaudronnerie", libelle: "Métallerie, chaudronnerie et charpente métallique" },
] as const satisfies readonly EntreeReferentiel[];

export type CleMetier = (typeof METIERS)[number]['code'];

/** Préfixe du slug de l'étiquette d'un métier : `metier-` + code. */
export const PREFIXE_ETIQUETTE_METIER = "metier-";

/** Joignabilité calculée — `companies.joignabilite`, `contacts.joignabilite`. */
export const JOIGNABILITES = [
  { code: "email_valide", libelle: "E-mail vérifié valide (part en campagne)" },
  { code: "email_partage", libelle: "E-mail partagé (cabinet, domiciliation) — exclu par défaut" },
  { code: "email_non_verifie", libelle: "E-mail non vérifié" },
  { code: "email_personnel", libelle: "E-mail personnel (jamais en campagne)" },
  { code: "email_invalide", libelle: "E-mail invalide (gardé, jamais envoyé)" },
  { code: "email_interdit", libelle: "E-mail interdit (opposition ou suppression)" },
  { code: "sans_email_avec_telephone", libelle: "Sans e-mail, avec téléphone" },
  { code: "sans_contact", libelle: "Sans contact" },
] as const satisfies readonly EntreeReferentiel[];

export type CleJoignabilite = (typeof JOIGNABILITES)[number]['code'];

/** Types de relation qu'une audience de PROSPECTION exclut par défaut (`RelationsProspection`). */
export const RELATIONS_HORS_PROSPECTION = ["client", "partenaire", "presse_media", "fournisseur", "investisseur"] as const;

/** Types de média — étiquette automatique `media-type:<code>`. */
export const TYPES_MEDIA = [
  { code: "presse-quotidienne", libelle: "Presse quotidienne" },
  { code: "presse-hebdomadaire", libelle: "Presse hebdomadaire" },
  { code: "presse-magazine", libelle: "Presse magazine et revues" },
  { code: "presse-journal", libelle: "Journal (périodicité inconnue)" },
  { code: "presse-autre", libelle: "Publication de presse" },
  { code: "radio", libelle: "Radio" },
  { code: "tv", libelle: "Télévision" },
  { code: "emission-tv", libelle: "Émission de télévision" },
  { code: "agence", libelle: "Agence de presse" },
  { code: "web", libelle: "Presse en ligne" },
  { code: "blog", libelle: "Blog" },
  { code: "production", libelle: "Production audiovisuelle" },
] as const satisfies readonly EntreeReferentiel[];

export type CleTypeMedia = (typeof TYPES_MEDIA)[number]['code'];

/** Zones de diffusion — étiquette automatique `media-zone:<code>`. */
export const ZONES_MEDIA = [
  { code: "national", libelle: "Nationale" },
  { code: "regional", libelle: "Régionale" },
  { code: "departemental", libelle: "Départementale" },
  { code: "local", libelle: "Locale" },
  { code: "inconnue", libelle: "Inconnue" },
] as const satisfies readonly EntreeReferentiel[];

export type CleZoneMedia = (typeof ZONES_MEDIA)[number]['code'];

/** Préfixes des étiquettes d'un média : type et zone de diffusion. */
export const PREFIXE_ETIQUETTE_TYPE_MEDIA = "media-type:";
export const PREFIXE_ETIQUETTE_ZONE_MEDIA = "media-zone:";

/** Thèmes de média (lecture du site) — étiquette automatique `media-sujet:<code>`. */
export const THEMES_MEDIA = [
  { code: "ia-tech", libelle: "IA et technologie" },
  { code: "economie-entreprise", libelle: "Économie et entreprise" },
  { code: "pme-entrepreneurs", libelle: "PME et entrepreneurs" },
  { code: "rh-management", libelle: "RH et management" },
  { code: "metiers-secteurs", libelle: "Métiers et secteurs (presse professionnelle)" },
  { code: "regional", libelle: "Régional et local" },
  { code: "grand-public", libelle: "Grand public" },
  { code: "inconnu", libelle: "Thème inconnu" },
] as const satisfies readonly EntreeReferentiel[];

export type CleThemeMedia = (typeof THEMES_MEDIA)[number]['code'];

/** Publics de média — étiquette automatique `media-public:<code>`. */
export const PUBLICS_MEDIA = [
  { code: "dirigeants", libelle: "Dirigeants et décideurs" },
  { code: "pros-secteur", libelle: "Professionnels d'un secteur" },
  { code: "grand-public", libelle: "Grand public" },
  { code: "inconnu", libelle: "Public inconnu" },
] as const satisfies readonly EntreeReferentiel[];

export type ClePublicMedia = (typeof PUBLICS_MEDIA)[number]['code'];

/** Formats TV — étiquette automatique `media-format:<code>`. */
export const FORMATS_MEDIA = [
  { code: "magazine-eco", libelle: "Magazine économique" },
  { code: "talk-show", libelle: "Talk-show, débat" },
  { code: "jt-info", libelle: "Journal télévisé, information" },
  { code: "tech", libelle: "Émission tech" },
  { code: "fiction-jeu", libelle: "Fiction ou jeu (non utile)" },
  { code: "inconnu", libelle: "Format inconnu" },
] as const satisfies readonly EntreeReferentiel[];

export type CleFormatMedia = (typeof FORMATS_MEDIA)[number]['code'];

/** Préfixes des étiquettes du classement d'un média : thème, public, format TV. */
export const PREFIXE_ETIQUETTE_THEME_MEDIA = "media-sujet:";
export const PREFIXE_ETIQUETTE_PUBLIC_MEDIA = "media-public:";
export const PREFIXE_ETIQUETTE_FORMAT_MEDIA = "media-format:";
