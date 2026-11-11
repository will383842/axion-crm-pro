/**
 * Jeu factice du CRM : entreprises, contacts, médias, journalistes,
 * fédérations, événements, audiences, listes, étiquettes.
 *
 * Mêmes repères que les maquettes (Boulangerie des Tilleuls, Paul Morin,
 * Yves Roche, Le Courrier du Rhône, Léa Fournier, Fédération BTP Rhône,
 * Salon des artisans de Vienne, étiquette `salon-artisans-2026`).
 */
import { graine } from '../graine';
import { ilYa } from '../horloge';
import { COMPLEMENTS, FONCTIONS, MEDIAS_FICTIFS, METIERS, NOMS, PRENOMS, RUBRIQUES, VILLES } from '../noms-fictifs';
import type {
  Audience,
  Contact,
  Entreprise,
  Etiquette,
  Evenement,
  Federation,
  Journaliste,
  Liste,
  Media,
} from './types';
import { enAscii, sirenInvalide, telephoneFictif } from './outils-donnees';

export const NB_ENTREPRISES = 300;
export const NB_CONTACTS = 900;

export const ETIQUETTES_DEMO: ReadonlyArray<Omit<Etiquette, 'id' | 'usage_count'>> = [
  { name: 'Salon des artisans 2026', slug: 'salon-artisans-2026', color: 'violet' },
  { name: 'Relance printemps', slug: 'relance-printemps', color: 'ambre' },
  { name: 'Clients 2026', slug: 'clients-2026', color: 'vert' },
  { name: 'Presse locale', slug: 'presse-locale', color: 'bleu' },
  { name: 'Prospects chauds', slug: 'prospects-chauds', color: 'terracotta' },
  { name: 'Fédérations BTP', slug: 'federations-btp', color: 'gris' },
  { name: 'Partenaires', slug: 'partenaires', color: 'vert' },
  { name: 'Lettre d’information', slug: 'lettre-information', color: 'bleu' },
  { name: 'À rappeler', slug: 'a-rappeler', color: 'terracotta' },
  { name: 'Salon de Lyon 2027', slug: 'salon-lyon-2027', color: 'violet' },
  { name: 'Commerces de proximité', slug: 'commerces-proximite', color: 'gris' },
  { name: 'Organisateurs', slug: 'organisateurs', color: 'ambre' },
];

/** Nombre d'entreprises marquées par étiquette (repères des maquettes en tête). */
const PART_ETIQUETTE: Record<string, number> = {
  'salon-artisans-2026': 0.45,
  'relance-printemps': 0.11,
  'clients-2026': 0.08,
  'prospects-chauds': 0.12,
  'commerces-proximite': 0.25,
  organisateurs: 0.1,
  'a-rappeler': 0.06,
  'lettre-information': 0.2,
};

const TAILLES: ReadonlyArray<{ code: Entreprise['size_category']; effectif: string; poids: number }> = [
  { code: 'tpe', effectif: '1 à 9 salariés', poids: 0.72 },
  { code: 'pme', effectif: '10 à 49 salariés', poids: 0.22 },
  { code: 'eti', effectif: '250 à 4 999 salariés', poids: 0.05 },
  { code: 'grand_groupe', effectif: '5 000 salariés et plus', poids: 0.01 },
];

/** Nom de la holding fictive du repère (sa fiche existe vraiment, le lien de la fiche mène à elle). */
export const NOM_HOLDING = 'Groupe Tilleuls Holding';

interface Organisation {
  denomination: string;
  domaine: string;
  metier: (typeof METIERS)[number];
  ville: (typeof VILLES)[number];
}

const REPERES_ENTREPRISES: ReadonlyArray<Organisation> = [
  { denomination: 'Boulangerie des Tilleuls', domaine: 'boulangerie-tilleuls.example', metier: METIERS[0]!, ville: VILLES[0]! },
  { denomination: 'Atelier Bois & Forme', domaine: 'bois-forme.example', metier: METIERS[1]!, ville: VILLES[0]! },
  { denomination: 'Imprimerie du Lac', domaine: 'imprimerie-lac.example', metier: METIERS[2]!, ville: VILLES[8]! },
];

export interface CrmGenere {
  entreprises: Entreprise[];
  contacts: Contact[];
  medias: Media[];
  journalistes: Journaliste[];
  federations: Federation[];
  evenements: Evenement[];
  audiences: Audience[];
  listes: Liste[];
  etiquettes: Etiquette[];
}

function genererEntreprises(): Entreprise[] {
  const alea = graine('crm-entreprises');
  const organisations: Organisation[] = [...REPERES_ENTREPRISES];
  const prises = new Set(organisations.map((o) => o.denomination));
  while (organisations.length < NB_ENTREPRISES) {
    const metier = alea.choisir(METIERS);
    const complement = alea.choisir(COMPLEMENTS);
    const ville = alea.choisir(VILLES);
    let denomination = `${metier.metier} ${complement.libelle}`;
    let domaine = `${metier.slug}-${complement.slug}.example`;
    if (prises.has(denomination)) {
      denomination = `${denomination} (${ville.ville})`;
      domaine = `${metier.slug}-${complement.slug}-${enAscii(ville.ville)}.example`;
    }
    if (prises.has(denomination)) continue;
    prises.add(denomination);
    organisations.push({ denomination, domaine, metier, ville });
  }

  // Repère de groupe : la dernière fiche est la holding de « Boulangerie des Tilleuls » (lien « Société mère » / « Filiale »).
  organisations[organisations.length - 1] = { denomination: NOM_HOLDING, domaine: 'groupe-tilleuls.example', metier: METIERS[0]!, ville: VILLES[0]! };

  return organisations.map((o, i) => {
    const tirage = alea.suivant();
    let cumul = 0;
    const taille = TAILLES.find((t) => (cumul += t.poids) >= tirage) ?? TAILLES[0]!;
    const etiquettes = Object.entries(PART_ETIQUETTE)
      .filter(([, part]) => alea.booleen(part))
      .map(([slug]) => slug);
    if (i === 0) etiquettes.splice(0, etiquettes.length, 'salon-artisans-2026', 'prospects-chauds', 'commerces-proximite');
    const enrichie = alea.booleen(0.7);
    return {
      id: i + 1,
      siren: sirenInvalide(alea),
      denomination: o.denomination,
      naf: o.metier.naf,
      naf_label: o.metier.naf_label,
      size_category: taille.code,
      effectif_range: taille.effectif,
      city: o.ville.ville,
      postcode: o.ville.cp,
      department_code: o.ville.dep,
      quality_score: alea.entier(38, 98),
      priority: alea.choisir(['haute', 'moyenne', 'moyenne', 'basse', 'basse', 'gelee'] as const),
      enriched_at: enrichie ? ilYa(alea.entier(1, 40), alea.entier(7, 19), alea.entier(0, 59)) : null,
      email_generic: `contact@${o.domaine}`,
      website: `https://www.${o.domaine}`,
      phone: telephoneFictif(alea),
      etiquettes,
      created_at: ilYa(alea.entier(31, 41), alea.entier(8, 18), alea.entier(0, 59)),
    };
  });
}

function genererContacts(entreprises: Entreprise[]): Contact[] {
  const alea = graine('crm-contacts');
  const domaineDe = (e: Entreprise) => e.email_generic.split('@')[1] ?? 'entreprise.example';
  const contacts: Contact[] = [];
  const ajouter = (e: Entreprise, prenom: string, nom: string, role: string, email?: string) => {
    contacts.push({
      id: contacts.length + 1,
      company_id: e.id,
      first_name: prenom,
      last_name: nom,
      role,
      email: email ?? `${enAscii(prenom, '')}.${enAscii(nom, '')}@${domaineDe(e)}`,
      etiquettes: e.etiquettes.filter(() => alea.booleen(0.6)),
      ne_plus_ecrire: false,
      created_at: e.created_at,
    });
  };
  const [tilleuls, boisForme, lac] = entreprises as [Entreprise, Entreprise, Entreprise];
  ajouter(tilleuls, 'Paul', 'Morin', 'Gérant');
  ajouter(tilleuls, 'Julie', 'Morin', 'Responsable commercial');
  ajouter(boisForme, 'Yves', 'Roche', 'Gérant', 'yves@bois-forme.example');
  ajouter(lac, 'Nadia', 'Haddad', 'Directrice');

  const prises = new Set(contacts.map((c) => c.email));
  let i = 0;
  while (contacts.length < NB_CONTACTS) {
    const e = entreprises[i % entreprises.length]!;
    i += 1;
    const prenom = alea.choisir(PRENOMS);
    const nom = alea.choisir(NOMS);
    const email = `${enAscii(prenom, '')}.${enAscii(nom, '')}@${domaineDe(e)}`;
    if (prises.has(email)) continue;
    prises.add(email);
    ajouter(e, prenom, nom, alea.choisir(FONCTIONS), email);
  }
  return contacts;
}

function genererMedias(): { medias: Media[]; journalistes: Journaliste[] } {
  const alea = graine('crm-medias');
  const medias: Media[] = MEDIAS_FICTIFS.map((m, i) => ({
    id: i + 1,
    name: m.nom,
    media_type: m.type,
    media_family: m.famille,
    periodicity: m.type === 'Hebdomadaire' ? 'hebdomadaire' : m.type === 'Mensuel' ? 'mensuel' : m.type === 'Journal' ? 'quotidien' : null,
    diffusion_zone: 'Régionale',
    department_code: m.dep,
    city: m.ville,
    website: `https://www.${m.slug}.example`,
    email: `redaction@${m.slug}.example`,
    phone: telephoneFictif(alea),
  }));
  const journalistes: Journaliste[] = [
    { id: 1, media_id: 1, first_name: 'Léa', last_name: 'Fournier', rubrique: 'Économie', email: 'lea.fournier@courrier-rhone.example' },
  ];
  const prises = new Set(journalistes.map((j) => j.email));
  for (const media of medias) {
    const n = media.id === 1 ? 3 : alea.entier(2, 4);
    for (let k = 0; k < n; k += 1) {
      const prenom = alea.choisir(PRENOMS);
      const nom = alea.choisir(NOMS);
      const slug = media.email.split('@')[1] ?? 'media.example';
      const email = `${enAscii(prenom, '')}.${enAscii(nom, '')}@${slug}`;
      if (prises.has(email)) continue;
      prises.add(email);
      journalistes.push({ id: journalistes.length + 1, media_id: media.id, first_name: prenom, last_name: nom, rubrique: alea.choisir(RUBRIQUES), email });
    }
  }
  return { medias, journalistes };
}

const FEDERATIONS_DEMO: ReadonlyArray<{ nom: string; slug: string; famille: string; niveau: Federation['niveau']; ville: string; dep: string }> = [
  { nom: 'Fédération BTP Rhône', slug: 'federation-btp-rhone', famille: 'federation_syndicat_pro', niveau: 'departemental', ville: 'Lyon', dep: '69' },
  { nom: 'Groupement des artisans du Pilat', slug: 'artisans-pilat', famille: 'association_metier', niveau: 'departemental', ville: 'Saint-Étienne', dep: '42' },
  { nom: 'Union des commerçants des Quais', slug: 'commercants-quais', famille: 'association_entreprises', niveau: 'departemental', ville: 'Vienne', dep: '38' },
  { nom: 'Club des entrepreneurs du Lac', slug: 'entrepreneurs-lac', famille: 'association_entreprises', niveau: 'departemental', ville: 'Annecy', dep: '74' },
  { nom: 'Fédération des métiers de bouche Rhône-Alpes', slug: 'metiers-bouche-ra', famille: 'federation_syndicat_pro', niveau: 'regional', ville: 'Lyon', dep: '69' },
  { nom: 'Syndicat des imprimeurs du Sud-Est', slug: 'imprimeurs-sud-est', famille: 'federation_syndicat_pro', niveau: 'regional', ville: 'Valence', dep: '26' },
  { nom: 'Association des fleuristes de Savoie', slug: 'fleuristes-savoie', famille: 'association_metier', niveau: 'departemental', ville: 'Chambéry', dep: '73' },
  { nom: 'Réseau des traiteurs indépendants', slug: 'traiteurs-independants', famille: 'association_metier', niveau: 'national', ville: 'Lyon', dep: '69' },
  { nom: 'Collectif des commerces de Bresse', slug: 'commerces-bresse', famille: 'association_entreprises', niveau: 'departemental', ville: 'Bourg-en-Bresse', dep: '01' },
  { nom: 'Fédération du bois et de l’ameublement Alpes', slug: 'bois-ameublement-alpes', famille: 'federation_syndicat_pro', niveau: 'regional', ville: 'Chambéry', dep: '73' },
  { nom: 'Union des garagistes de la Loire', slug: 'garagistes-loire', famille: 'federation_syndicat_pro', niveau: 'departemental', ville: 'Roanne', dep: '42' },
  { nom: 'Cercle des dirigeants de la Drôme', slug: 'dirigeants-drome', famille: 'association_entreprises', niveau: 'departemental', ville: 'Valence', dep: '26' },
  { nom: 'Association des libraires des Coteaux', slug: 'libraires-coteaux', famille: 'association_metier', niveau: 'departemental', ville: 'Mâcon', dep: '71' },
  { nom: 'Pôle artisanat et design Confluences', slug: 'artisanat-design', famille: 'pole_cluster', niveau: 'regional', ville: 'Lyon', dep: '69' },
  { nom: 'Fédération des électriciens du Dauphiné', slug: 'electriciens-dauphine', famille: 'federation_syndicat_pro', niveau: 'departemental', ville: 'Voiron', dep: '38' },
  { nom: 'Club des jeunes entreprises de l’Ain', slug: 'jeunes-entreprises-ain', famille: 'association_entreprises', niveau: 'departemental', ville: 'Bourg-en-Bresse', dep: '01' },
  { nom: 'Union des cavistes du Rhône', slug: 'cavistes-rhone', famille: 'association_metier', niveau: 'departemental', ville: 'Villefranche-sur-Saône', dep: '69' },
  { nom: 'Fédération des plombiers et chauffagistes Isère', slug: 'plombiers-isere', famille: 'federation_syndicat_pro', niveau: 'departemental', ville: 'Bourgoin-Jallieu', dep: '38' },
];

function genererFederations(): Federation[] {
  const alea = graine('crm-federations');
  return FEDERATIONS_DEMO.map((f, i) => ({
    id: 1000 + i + 1,
    siren: sirenInvalide(alea),
    denomination: f.nom,
    famille: f.famille,
    niveau: f.niveau,
    department_code: f.dep,
    city: f.ville,
    email_generic: `contact@${f.slug}.example`,
    website: `https://www.${f.slug}.example`,
    adherents: i === 0 ? 412 : alea.entier(25, 380),
  }));
}

const EVENEMENTS_DEMO: ReadonlyArray<{ nom: string; type: string; debut: string; jours: number; ville: string; dep: string; orga: number | null }> = [
  { nom: 'Salon des artisans de Vienne', type: 'salon', debut: '2027-03-12', jours: 3, ville: 'Vienne', dep: '38', orga: 1003 },
  { nom: 'Rencontres du commerce de Lyon', type: 'conference', debut: '2027-02-04', jours: 1, ville: 'Lyon', dep: '69', orga: 1005 },
  { nom: 'Forum de l’emploi local de Givors', type: 'forum', debut: '2027-01-28', jours: 1, ville: 'Givors', dep: '69', orga: null },
  { nom: 'Foire de printemps de Bourg', type: 'foire', debut: '2027-04-09', jours: 4, ville: 'Bourg-en-Bresse', dep: '01', orga: 1009 },
  { nom: 'Journées du bois et de l’ameublement', type: 'salon', debut: '2027-03-25', jours: 2, ville: 'Chambéry', dep: '73', orga: 1010 },
  { nom: 'Petit-déjeuner des dirigeants drômois', type: 'reunion', debut: '2027-01-21', jours: 1, ville: 'Valence', dep: '26', orga: 1012 },
  { nom: 'Marché des créateurs du Lac', type: 'marche', debut: '2027-05-15', jours: 2, ville: 'Annecy', dep: '74', orga: 1004 },
  { nom: 'Trophées de l’artisanat du Pilat', type: 'remise_prix', debut: '2027-02-18', jours: 1, ville: 'Saint-Étienne', dep: '42', orga: 1002 },
  { nom: 'Salon des métiers de bouche', type: 'salon', debut: '2027-03-05', jours: 3, ville: 'Lyon', dep: '69', orga: 1005 },
  { nom: 'Atelier « Vendre en ligne » des Quais', type: 'atelier', debut: '2027-01-26', jours: 1, ville: 'Vienne', dep: '38', orga: 1003 },
  { nom: 'Soirée des partenaires Confluences', type: 'reunion', debut: '2027-02-11', jours: 1, ville: 'Lyon', dep: '69', orga: 1014 },
  { nom: 'Fête du livre des Coteaux', type: 'foire', debut: '2027-04-17', jours: 2, ville: 'Mâcon', dep: '71', orga: 1013 },
];

function genererEvenements(): Evenement[] {
  const alea = graine('crm-evenements');
  return EVENEMENTS_DEMO.map((e, i) => {
    const fin = new Date(Date.parse(`${e.debut}T12:00:00.000Z`) + (e.jours - 1) * 86_400_000).toISOString().slice(0, 10);
    return {
      id: i + 1,
      nom: e.nom,
      type: e.type,
      date_debut: e.debut,
      date_fin: fin,
      ville: e.ville,
      departement_code: e.dep,
      region: e.dep === '21' || e.dep === '71' ? 'Bourgogne-Franche-Comté' : 'Auvergne-Rhône-Alpes',
      appel_intervenants: i === 0 ? 'ouvert' : alea.choisir(['ouvert', 'ferme', 'inconnu']),
      verifie: i === 0 ? true : alea.booleen(0.7),
      participation: i === 0 ? 'exposant' : alea.choisir(['a_decider', 'visiteur', 'exposant', 'non']),
      intervention: i === 0 ? 'proposee' : alea.choisir(['pas_encore_proposee', 'proposee', 'acceptee', 'refusee']),
      organisateur_id: e.orga,
    };
  });
}

function genererAudiences(): Audience[] {
  const alea = graine('crm-audiences');
  const def: ReadonlyArray<[string, string, number]> = [
    ['Prospects contactables', 'Joignables vérifiés, recalculée chaque nuit.', 642],
    ['Organisateurs Rhône-Alpes', 'Organisateurs de salons et de foires de la région.', 290],
    ['Audience presse AURA', 'Rédactions régionales et presse professionnelle.', 186],
    ['Clubs d’entreprises Savoie', 'Clubs et cercles de dirigeants en Savoie.', 212],
    ['Artisans du Pilat', 'Artisans du massif du Pilat et alentours.', 140],
    ['Exposants potentiels du salon', 'Artisans et commerçants à inviter au salon de Vienne.', 180],
    ['Traiteurs et événements', 'Traiteurs, salles et organisateurs de réceptions.', 120],
    ['Nouveaux commerces de Lyon', 'Commerces ouverts depuis moins d’un an.', 150],
  ];
  return def.map(([name, description, n], i) => ({
    id: i + 1,
    name,
    description,
    is_active: true,
    auto_refresh: i === 0 || alea.booleen(0.5),
    member_count: n,
    refreshed_at: ilYa(i === 0 ? 0 : alea.entier(1, 6), 3, 10),
    created_at: ilYa(alea.entier(32, 40), 10, 0),
  }));
}

function genererListes(): Liste[] {
  const def: ReadonlyArray<[string, string | null, number, number]> = [
    ['Invités salon', 'Invités du salon des artisans de Vienne.', 127, 141],
    ['À rappeler en janvier', null, 34, 38],
    ['Partenaires 2026', 'Partenaires à remercier en début d’année.', 96, 102],
    ['Presse amie', 'Rédactions qui nous ont déjà relayés.', 12, 18],
    ['Presse spécialisée artisanat', null, 64, 64],
  ];
  return def.map(([nom, description, organisations, personnes], i) => ({
    id: i + 1,
    nom,
    description,
    organisations,
    personnes,
    created_at: ilYa(36 - i * 3, 11, 0),
    updated_at: ilYa(3 + i, 15, 30),
  }));
}

export function genererCrm(): CrmGenere {
  const entreprises = genererEntreprises();
  const contacts = genererContacts(entreprises);
  const { medias, journalistes } = genererMedias();
  const etiquettes: Etiquette[] = ETIQUETTES_DEMO.map((e, i) => ({
    ...e,
    id: i + 1,
    usage_count:
      e.slug === 'salon-artisans-2026'
        ? 388
        : e.slug === 'relance-printemps'
          ? 96
          : entreprises.filter((x) => x.etiquettes.includes(e.slug)).length +
            contacts.filter((c) => c.etiquettes.includes(e.slug)).length,
  }));
  return {
    entreprises,
    contacts,
    medias,
    journalistes,
    federations: genererFederations(),
    evenements: genererEvenements(),
    audiences: genererAudiences(),
    listes: genererListes(),
    etiquettes,
  };
}
