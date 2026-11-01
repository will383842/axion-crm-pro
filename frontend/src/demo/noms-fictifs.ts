/**
 * Listes de noms INVENTÉS pour le jeu factice de la démo.
 *
 * Personnes : prénoms et noms de famille courants, combinés au hasard (graine
 * fixe) ; aucune combinaison ne vise une personne réelle (garde :
 * `tests/lib/demo-donnees-factices.test.ts`). Organisations : raisons sociales
 * fabriquées (métier + complément), jamais un nom d'entreprise existante.
 * Domaines en `.example`, adresses en `@example.test`, téléphones dans la
 * tranche réservée à la fiction (04 65 71 xx xx).
 *
 * Les repères des maquettes (Paul Morin, Yves Roche, Boulangerie des Tilleuls,
 * Le Courrier du Rhône, Léa Fournier…) sont posés à part, dans les données.
 */

export const PRENOMS = [
  'Paul', 'Julie', 'Nadia', 'Hugo', 'Claire', 'Sophie', 'Lucas', 'Emma', 'Louis', 'Chloé',
  'Antoine', 'Camille', 'Thomas', 'Manon', 'Nicolas', 'Inès', 'Julien', 'Sarah', 'Maxime', 'Léna',
  'Quentin', 'Pauline', 'Romain', 'Mathilde', 'Adrien', 'Élodie', 'Bastien', 'Margaux', 'Florian', 'Anaïs',
  'Damien', 'Laura', 'Mehdi', 'Amandine', 'Yanis', 'Céline', 'Karim', 'Aurélie', 'Simon', 'Noémie',
  'Victor', 'Lucie', 'Gaël', 'Agathe', 'Rémi', 'Justine', 'Arthur', 'Coralie', 'Benoît', 'Estelle',
  'Fabien', 'Hélène', 'Gilles', 'Isabelle', 'Hervé', 'Jeanne', 'Loïc', 'Marion', 'Olivier', 'Rose',
] as const;

export const NOMS = [
  'Morin', 'Roche', 'Haddad', 'Fournier', 'Garnier', 'Chevalier', 'Perrin', 'Lambert', 'Marchand', 'Vidal',
  'Brunet', 'Blanchard', 'Gauthier', 'Masson', 'Bonnet', 'Rolland', 'Faure', 'Mercier', 'Barbier', 'Arnaud',
  'Giraud', 'Picard', 'Renaud', 'Colin', 'Lemaire', 'Aubert', 'Caron', 'Boyer', 'Fleury', 'Royer',
  'Vasseur', 'Collet', 'Bouvier', 'Charpentier', 'Benard', 'Rey', 'Leclerc', 'Payet', 'Durand', 'Noël',
  'Meunier', 'Jacquet', 'Hamon', 'Besson', 'Prevost', 'Poulain', 'Tessier', 'Valette', 'Carre', 'Pons',
  'Reynaud', 'Bertin', 'Maillard', 'Cordier', 'Delorme', 'Ferrand', 'Gilbert', 'Huet', 'Labbé', 'Million',
] as const;

/** Villes (réelles, ce sont des lieux) avec code postal et département. */
export const VILLES: ReadonlyArray<{ ville: string; cp: string; dep: string }> = [
  { ville: 'Vienne', cp: '38200', dep: '38' },
  { ville: 'Lyon', cp: '69003', dep: '69' },
  { ville: 'Villeurbanne', cp: '69100', dep: '69' },
  { ville: 'Givors', cp: '69700', dep: '69' },
  { ville: 'Tarare', cp: '69170', dep: '69' },
  { ville: 'Villefranche-sur-Saône', cp: '69400', dep: '69' },
  { ville: 'Saint-Étienne', cp: '42000', dep: '42' },
  { ville: 'Roanne', cp: '42300', dep: '42' },
  { ville: 'Annecy', cp: '74000', dep: '74' },
  { ville: 'Chambéry', cp: '73000', dep: '73' },
  { ville: 'Valence', cp: '26000', dep: '26' },
  { ville: 'Bourg-en-Bresse', cp: '01000', dep: '01' },
  { ville: 'Voiron', cp: '38500', dep: '38' },
  { ville: 'Bourgoin-Jallieu', cp: '38300', dep: '38' },
  { ville: 'Annonay', cp: '07100', dep: '07' },
  { ville: 'Montélimar', cp: '26200', dep: '26' },
  { ville: 'Clermont-Ferrand', cp: '63000', dep: '63' },
  { ville: 'Dijon', cp: '21000', dep: '21' },
  { ville: 'Mâcon', cp: '71000', dep: '71' },
  { ville: 'Aix-les-Bains', cp: '73100', dep: '73' },
];

export const METIERS: ReadonlyArray<{ metier: string; naf: string; naf_label: string; slug: string }> = [
  { metier: 'Boulangerie', naf: '10.71C', naf_label: 'Boulangerie et boulangerie-pâtisserie', slug: 'boulangerie' },
  { metier: 'Atelier', naf: '16.23Z', naf_label: 'Fabrication de charpentes et d’autres menuiseries', slug: 'atelier' },
  { metier: 'Imprimerie', naf: '18.12Z', naf_label: 'Autre imprimerie (labeur)', slug: 'imprimerie' },
  { metier: 'Menuiserie', naf: '43.32A', naf_label: 'Travaux de menuiserie bois et PVC', slug: 'menuiserie' },
  { metier: 'Garage', naf: '45.20A', naf_label: 'Entretien et réparation de véhicules automobiles légers', slug: 'garage' },
  { metier: 'Fromagerie', naf: '47.29Z', naf_label: 'Autres commerces de détail alimentaires en magasin spécialisé', slug: 'fromagerie' },
  { metier: 'Librairie', naf: '47.61Z', naf_label: 'Commerce de détail de livres en magasin spécialisé', slug: 'librairie' },
  { metier: 'Plomberie', naf: '43.22A', naf_label: 'Travaux d’installation d’eau et de gaz en tous locaux', slug: 'plomberie' },
  { metier: 'Traiteur', naf: '56.21Z', naf_label: 'Services des traiteurs', slug: 'traiteur' },
  { metier: 'Fleuriste', naf: '47.76Z', naf_label: 'Commerce de détail de fleurs, plantes, graines', slug: 'fleuriste' },
  { metier: 'Ébénisterie', naf: '31.09B', naf_label: 'Fabrication d’autres meubles et industries connexes de l’ameublement', slug: 'ebenisterie' },
  { metier: 'Serrurerie', naf: '43.32B', naf_label: 'Travaux de menuiserie métallique et serrurerie', slug: 'serrurerie' },
  { metier: 'Cave', naf: '47.25Z', naf_label: 'Commerce de détail de boissons en magasin spécialisé', slug: 'cave' },
  { metier: 'Charcuterie', naf: '10.13B', naf_label: 'Charcuterie', slug: 'charcuterie' },
  { metier: 'Électricité', naf: '43.21A', naf_label: 'Travaux d’installation électrique dans tous locaux', slug: 'electricite' },
  { metier: 'Studio', naf: '74.20Z', naf_label: 'Activités photographiques', slug: 'studio' },
  { metier: 'Agence', naf: '73.11Z', naf_label: 'Activités des agences de publicité', slug: 'agence' },
  { metier: 'Cabinet', naf: '69.20Z', naf_label: 'Activités comptables', slug: 'cabinet' },
  { metier: 'Pâtisserie', naf: '10.71D', naf_label: 'Pâtisserie', slug: 'patisserie' },
  { metier: 'Torréfaction', naf: '10.83Z', naf_label: 'Transformation du thé et du café', slug: 'torrefaction' },
];

export const COMPLEMENTS: ReadonlyArray<{ libelle: string; slug: string }> = [
  { libelle: 'des Tilleuls', slug: 'tilleuls' }, { libelle: 'du Lac', slug: 'lac' },
  { libelle: 'de la Gare', slug: 'gare' }, { libelle: 'du Vieux Pont', slug: 'vieux-pont' },
  { libelle: 'des Remparts', slug: 'remparts' }, { libelle: 'du Marché', slug: 'marche' },
  { libelle: 'des Platanes', slug: 'platanes' }, { libelle: 'de la Colline', slug: 'colline' },
  { libelle: 'du Moulin', slug: 'moulin' }, { libelle: 'des Quais', slug: 'quais' },
  { libelle: 'du Belvédère', slug: 'belvedere' }, { libelle: 'des Glycines', slug: 'glycines' },
  { libelle: 'de l’Horloge', slug: 'horloge' }, { libelle: 'des Cerisiers', slug: 'cerisiers' },
  { libelle: 'du Grand Chêne', slug: 'grand-chene' }, { libelle: 'des Lavandières', slug: 'lavandieres' },
  { libelle: 'de la Fontaine', slug: 'fontaine' }, { libelle: 'du Prieuré', slug: 'prieure' },
  { libelle: 'des Coteaux', slug: 'coteaux' }, { libelle: 'du Rivage', slug: 'rivage' },
  { libelle: 'de la Halle', slug: 'halle' }, { libelle: 'des Acacias', slug: 'acacias' },
  { libelle: 'du Pressoir', slug: 'pressoir' }, { libelle: 'des Vergers', slug: 'vergers' },
  { libelle: 'de la Forge', slug: 'forge' }, { libelle: 'du Lavoir', slug: 'lavoir' },
  { libelle: 'des Mésanges', slug: 'mesanges' }, { libelle: 'du Clos Fleuri', slug: 'clos-fleuri' },
];

export const FONCTIONS = [
  'Gérant', 'Gérante', 'Directrice', 'Directeur', 'Responsable commercial', 'Responsable communication',
  'Assistante de direction', 'Associé', 'Chargée de projets', 'Responsable des achats',
] as const;

export const MEDIAS_FICTIFS: ReadonlyArray<{ nom: string; slug: string; type: string; famille: string; ville: string; dep: string }> = [
  { nom: 'Le Courrier du Rhône', slug: 'courrier-rhone', type: 'Journal', famille: 'presse_ecrite', ville: 'Lyon', dep: '69' },
  { nom: 'L’Écho des Monts', slug: 'echo-monts', type: 'Hebdomadaire', famille: 'presse_ecrite', ville: 'Annecy', dep: '74' },
  { nom: 'La Gazette du Pilat', slug: 'gazette-pilat', type: 'Hebdomadaire', famille: 'presse_ecrite', ville: 'Saint-Étienne', dep: '42' },
  { nom: 'Radio Vallée Bleue', slug: 'radio-vallee-bleue', type: 'Radio', famille: 'radio', ville: 'Valence', dep: '26' },
  { nom: 'Le Petit Viennois', slug: 'petit-viennois', type: 'Mensuel', famille: 'presse_ecrite', ville: 'Vienne', dep: '38' },
  { nom: 'Artisans & Territoires', slug: 'artisans-territoires', type: 'Magazine', famille: 'presse_pro', ville: 'Lyon', dep: '69' },
  { nom: 'Les Nouvelles de Bresse', slug: 'nouvelles-bresse', type: 'Hebdomadaire', famille: 'presse_ecrite', ville: 'Bourg-en-Bresse', dep: '01' },
  { nom: 'TV Confluences', slug: 'tv-confluences', type: 'Télévision', famille: 'television', ville: 'Lyon', dep: '69' },
  { nom: 'Le Journal des Coteaux', slug: 'journal-coteaux', type: 'Journal', famille: 'presse_ecrite', ville: 'Mâcon', dep: '71' },
  { nom: 'Commerce Plus Rhône-Alpes', slug: 'commerce-plus', type: 'Magazine', famille: 'presse_pro', ville: 'Villeurbanne', dep: '69' },
  { nom: 'La Voix du Lac', slug: 'voix-lac', type: 'Hebdomadaire', famille: 'presse_ecrite', ville: 'Aix-les-Bains', dep: '73' },
  { nom: 'Radio Trois Rivières', slug: 'radio-trois-rivieres', type: 'Radio', famille: 'radio', ville: 'Roanne', dep: '42' },
  { nom: 'Le Messager de Savoie', slug: 'messager-savoie', type: 'Journal', famille: 'presse_ecrite', ville: 'Chambéry', dep: '73' },
  { nom: 'Ateliers d’Aujourd’hui', slug: 'ateliers-aujourdhui', type: 'Magazine', famille: 'presse_pro', ville: 'Lyon', dep: '69' },
  { nom: 'Le Réveil Drômois', slug: 'reveil-dromois', type: 'Hebdomadaire', famille: 'presse_ecrite', ville: 'Montélimar', dep: '26' },
  { nom: 'Infos Ardèche Nord', slug: 'infos-ardeche-nord', type: 'Site d’information', famille: 'web', ville: 'Annonay', dep: '07' },
];

export const RUBRIQUES = ['Économie', 'Vie locale', 'Artisanat', 'Commerce', 'Société', 'Culture', 'Emploi'] as const;
