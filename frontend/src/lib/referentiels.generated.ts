/**
 * FICHIER GÉNÉRÉ — NE PAS MODIFIER À LA MAIN.
 *
 * Source : `backend/app/Crm/Taxonomy.php` (référentiel unique, chantier 1).
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
