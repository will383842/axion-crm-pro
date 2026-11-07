/**
 * Jeu factice de la presse et des réseaux (DM1-PRE) : ce que les écrans
 * « Médias », « Journalistes », « Communiqués envoyés », « Fédérations et
 * ordres » et « Événements » lisent en plus du jeu de base.
 *
 * Zone `presse` de `demoDb` (graine `presse`) : mêmes données à chaque
 * chargement. Aucune donnée réelle : e-mails en `.example`, noms inventés.
 * Rien ne se supprime : on consigne, on change l'étape, on archive.
 */
import type { Alea } from '../../graine';
import { ilYa, jourIso } from '../../horloge';
import type { Evenement, Federation, Journaliste, Media } from '../types';

/** Natures d'échange presse (miroir de `JournalPresse.tsx`). */
export type NatureEchange = 'press_release_sent' | 'press_followup' | 'press_reply' | 'press_coverage' | 'linkedin_message' | 'call';
export const NATURES_PRESSE: readonly NatureEchange[] = ['press_release_sent', 'press_followup', 'press_reply', 'press_coverage'];
export const NATURES_ECHANGE: readonly NatureEchange[] = [...NATURES_PRESSE, 'linkedin_message', 'call'];

export interface Echange {
  id: number;
  sujet: 'media' | 'journaliste';
  sujet_id: number;
  kind: NatureEchange;
  title: string;
  content: string | null;
  occurred_at: string;
  created_at: string;
}

export interface FicheMedia {
  id: number;
  name: string;
  media_type: string;
  media_family: 'editorial' | 'audiovisual_production';
  periodicity: string | null;
  editorial_theme: string | null;
  diffusion_zone: string;
  department_code: string;
  region_code: string;
  city: string;
  postcode: string;
  publisher: string | null;
  website: string | null;
  email: string | null;
  email_confidence: 'A' | 'B' | 'C' | null;
  phone: string | null;
  cppap_number: string | null;
  arcom_id: string | null;
  enrich_status: string;
  site_verifie: string | null;
  site_statut: string;
  siren: string | null;
  source: string;
  parent_id: number | null;
}

export interface FicheJournaliste {
  id: number;
  media_id: number | null;
  media_raw: string | null;
  first_name: string | null;
  last_name: string;
  role: string | null;
  beat: string | null;
  email: string | null;
  phone: string | null;
  opt_out: boolean;
  personne_reelle: boolean;
  acces: 'email_redaction' | 'redaction_prod' | 'linkedin_direct' | 'a_qualifier';
  linkedin_slug: string | null;
  lien_linkedin: string;
  lien_linkedin_le: string | null;
  priorite: number | null;
  score: number | null;
  abonnes: number | null;
  media_portee_raw: string | null;
  media_support_raw: string | null;
  collecte_le: string | null;
  source: string | null;
  source_url: string | null;
}

export interface FicheFederation {
  id: number;
  sigle: string | null;
  entity_nature: string;
  region_code: string;
  secteurs: string[];
  tailles_adherents: string[];
  pertinence: string;
  contactabilite: string;
  certitude: string | null;
  partenariat: string;
  partenariat_relance_at: string | null;
  partenariat_note: string | null;
  tete_id: number | null;
  identifiant: string | null;
  naf: string | null;
  legal_form: string | null;
  effectif_range: string | null;
  address: string | null;
  postcode: string | null;
  sector_main: string | null;
  linkedin_url: string | null;
  phone: string | null;
  contact_form_url: string | null;
  nom_developpe: string | null;
  date_creation: string | null;
  nb_etablissements: number | null;
  origine_classement: string | null;
}

export interface ContactFederation {
  id: number;
  federation_id: number;
  first_name: string | null;
  last_name: string;
  role: string | null;
  email: string | null;
  phone: string | null;
  linkedin_url: string | null;
  informe: boolean;
}

export interface FicheEvenement {
  id: number;
  type: string;
  heure: string | null;
  recurrence: string | null;
  participation: 'repere' | 'inscrit' | 'rencontre';
  intervention: 'aucune' | 'proposee' | 'acceptee' | 'refusee' | 'realisee';
  prochaine_relance_at: string | null;
  demarche_note: string | null;
  lieu: string | null;
  public_vise: string | null;
  taille: string | null;
  prix: string | null;
  lien_evenement: string | null;
  lien_inscription: string | null;
  appel_intervenants_limite: string | null;
  source_url: string | null;
  notes: string | null;
}

export interface EtapeHistorique {
  id: number;
  sujet: 'federation' | 'evenement';
  sujet_id: number;
  kind: string;
  occurred_at: string;
}

export interface PresseReseauxGenere {
  medias: FicheMedia[];
  journalistes: FicheJournaliste[];
  echanges: Echange[];
  federations: FicheFederation[];
  contactsFederation: ContactFederation[];
  evenements: FicheEvenement[];
  etapes: EtapeHistorique[];
}

/** Région INSEE d'un département du jeu (84 = Auvergne-Rhône-Alpes, 27 = Bourgogne-Franche-Comté). */
export function regionDeDepartement(dep: string): string {
  return dep === '21' || dep === '71' ? '27' : '84';
}

const TYPE_MEDIA: Record<string, string> = {
  Journal: 'presse_journal',
  Hebdomadaire: 'presse_journal',
  Mensuel: 'presse_revue',
  Magazine: 'presse_revue',
  Radio: 'radio',
  Télévision: 'tv',
  'Site d’information': 'portail_web',
};

const TYPE_EVENEMENT: Record<string, string> = {
  salon: 'salon',
  conference: 'conference',
  forum: 'salon',
  foire: 'salon',
  marche: 'salon',
  reunion: 'club-affaires',
  remise_prix: 'remise-prix',
  atelier: 'atelier',
};

const PARTICIPATION: Record<string, FicheEvenement['participation']> = {
  a_decider: 'repere',
  visiteur: 'inscrit',
  exposant: 'inscrit',
  non: 'repere',
};

const INTERVENTION: Record<string, FicheEvenement['intervention']> = {
  pas_encore_proposee: 'aucune',
  proposee: 'proposee',
  acceptee: 'acceptee',
  refusee: 'refusee',
};

const SECTEURS_PAR_FAMILLE: Record<string, string[]> = {
  federation_syndicat_pro: ['btp', 'industrie'],
  association_metier: ['commerce_detail', 'services_personne'],
  association_entreprises: ['interprofessionnel'],
  pole_cluster: ['edition_medias', 'culture_sport_loisirs'],
};

const PRENOMS_CONTACT = ['Aline', 'Bastien', 'Corinne', 'Damien', 'Élodie', 'Fabrice'] as const;
const NOMS_CONTACT = ['Verdier', 'Lambert', 'Chabert', 'Gonthier', 'Perrin', 'Mollard'] as const;

function mediaDetail(m: Media, alea: Alea): FicheMedia {
  const premier = m.id === 1;
  return {
    id: m.id,
    name: m.name,
    media_type: TYPE_MEDIA[m.media_type] ?? 'presse_autre',
    media_family: 'editorial',
    periodicity: m.periodicity,
    editorial_theme: m.media_family === 'presse_pro' ? 'Économie et métiers' : 'Actualité locale',
    diffusion_zone: m.diffusion_zone,
    department_code: m.department_code,
    region_code: regionDeDepartement(m.department_code),
    city: m.city,
    postcode: `${m.department_code}${String(alea.entier(0, 999)).padStart(3, '0')}`.slice(0, 5).padEnd(5, '0'),
    publisher: premier ? 'Éditions du Rhône (démo)' : `Éditions ${m.city} (démo)`,
    website: m.website,
    email: m.email,
    email_confidence: premier ? 'A' : alea.choisir(['A', 'A', 'B', 'C'] as const),
    phone: m.phone,
    cppap_number: m.media_type === 'Radio' || m.media_type === 'Télévision' ? null : `0${alea.entier(100, 999)} Z ${alea.entier(10000, 99999)}`,
    arcom_id: m.media_type === 'Radio' || m.media_type === 'Télévision' ? `ARC-${alea.entier(1000, 9999)}` : null,
    enrich_status: 'enrichi',
    site_verifie: premier || alea.booleen(0.75) ? m.website : null,
    site_statut: premier ? 'verifie' : alea.choisir(['verifie', 'verifie', 'a-confirmer']),
    siren: String(alea.entier(100000000, 999999999)),
    source: 'Annuaire presse',
    parent_id: null,
  };
}

function journalisteDetail(j: Journaliste, medias: readonly Media[], alea: Alea, opposeId: number | null): FicheJournaliste {
  const media = medias.find((m) => m.id === j.media_id);
  const prive = media !== undefined && (media.media_type === 'Télévision' || media.media_type === 'Radio');
  const lea = j.id === 1;
  return {
    id: j.id,
    media_id: j.media_id,
    media_raw: media?.name ?? null,
    first_name: j.first_name,
    last_name: j.last_name,
    role: lea ? 'Rédactrice en chef' : alea.choisir(['Journaliste', 'Chef de rubrique', 'Reporter', 'Secrétaire de rédaction']),
    beat: j.rubrique,
    email: j.email,
    phone: null,
    opt_out: j.id === opposeId,
    personne_reelle: true,
    acces: prive ? 'redaction_prod' : 'email_redaction',
    linkedin_slug: `${j.first_name}-${j.last_name}`.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z]+/g, '-') + '-factice',
    lien_linkedin: lea ? 'connecte' : alea.choisir(['inconnu', 'non_connecte', 'demande_envoyee', 'connecte']),
    lien_linkedin_le: lea ? '2026-09-14' : null,
    priorite: lea ? 1 : alea.entier(1, 3),
    score: lea ? 92 : alea.entier(40, 88),
    abonnes: lea ? 3100 : alea.entier(300, 2400),
    media_portee_raw: 'Régionale',
    media_support_raw: media?.media_type === 'Radio' ? 'Radio' : media?.media_type === 'Télévision' ? 'Télévision' : 'Presse écrite',
    collecte_le: '2026-09-12',
    source: 'Annuaire presse',
    source_url: null,
  };
}

/** Lignes que l'annuaire a importées sans être des personnes (visibles dans « À vérifier »). */
function lignesAVerifier(medias: readonly Media[], premierId: number): FicheJournaliste[] {
  const tv = medias.find((m) => m.media_type === 'Télévision');
  const base = {
    phone: null,
    email: null,
    opt_out: false,
    personne_reelle: false,
    linkedin_slug: null,
    lien_linkedin: 'inconnu',
    lien_linkedin_le: null,
    priorite: null,
    score: null,
    abonnes: null,
    media_portee_raw: 'Régionale',
    collecte_le: '2026-09-12',
    source: 'Annuaire presse',
    source_url: null,
    beat: null,
    first_name: null,
  } as const;
  return [
    { ...base, id: premierId, media_id: tv?.id ?? null, media_raw: tv?.name ?? null, last_name: 'Émission « Le Rendez-vous des artisans »', role: 'Production', acces: 'redaction_prod', media_support_raw: 'Télévision' },
    { ...base, id: premierId + 1, media_id: null, media_raw: 'Chroniqueur, TV Confluences, dirigeant de l’Atelier Mollard', last_name: 'Chronique économie locale', role: 'Chroniqueur', acces: 'a_qualifier', media_support_raw: null },
  ];
}

export function genererPresseReseaux(alea: Alea, base: { medias: readonly Media[]; journalistes: readonly Journaliste[]; federations: readonly Federation[]; evenements: readonly Evenement[] }): PresseReseauxGenere {
  // ── Médias (+ une émission rattachée à la télévision) ─────────────────
  const medias: FicheMedia[] = base.medias.map((m) => mediaDetail(m, alea));
  const tv = base.medias.find((m) => m.media_type === 'Télévision');
  if (tv) {
    medias.push({
      ...mediaDetail({ ...tv, id: 101, name: 'Le Rendez-vous des artisans', media_type: 'Télévision' }, alea),
      id: 101,
      name: 'Le Rendez-vous des artisans',
      media_type: 'tv_emission',
      parent_id: tv.id,
      email: null,
      email_confidence: null,
      website: null,
      site_verifie: null,
      site_statut: 'a-confirmer',
    });
  }

  // ── Journalistes : le second du Courrier du Rhône s'est opposé aux envois ─
  const opposeId = base.journalistes.find((j) => j.media_id === 1 && j.id !== 1)?.id ?? null;
  const journalistes: FicheJournaliste[] = base.journalistes.map((j) => journalisteDetail(j, base.medias, alea, opposeId));
  journalistes.push(...lignesAVerifier(base.medias, 501));

  // ── Échanges (communiqués, relances, réponses, retombées) ─────────────
  const echanges: Echange[] = [];
  const noter = (sujet: Echange['sujet'], sujet_id: number, kind: NatureEchange, jours: number, title: string, content: string | null = null): void => {
    const quand = ilYa(jours, 10 + (echanges.length % 6), (echanges.length * 7) % 60);
    echanges.push({ id: echanges.length + 1, sujet, sujet_id, kind, title, content, occurred_at: quand, created_at: quand });
  };
  // Le fil du Courrier du Rhône et de Léa Fournier (repère des démos).
  noter('journaliste', 1, 'press_release_sent', 71, 'Communiqué : salon des artisans de Vienne');
  noter('media', 1, 'press_release_sent', 71, 'Communiqué : salon des artisans de Vienne', 'Envoyé à la rédaction.');
  noter('journaliste', 1, 'press_coverage', 64, 'Article publié, page 6', 'Un quart de page, photo à l’appui.');
  if (opposeId !== null) {
    noter('journaliste', opposeId, 'press_release_sent', 71, 'Communiqué : salon des artisans de Vienne');
    noter('journaliste', opposeId, 'press_reply', 66, 'Demande de ne plus recevoir de communiqués', 'Opposition notée : plus aucun envoi.');
  }
  // Quelques autres échanges, tirés de la graine.
  const natures: NatureEchange[] = ['press_release_sent', 'press_followup', 'press_reply', 'press_coverage'];
  const objets = ['Salon des artisans 2026', 'Invitation presse du 12 mars', 'Trophées de l’artisanat', 'Ouverture de la billetterie'];
  for (const m of base.medias.slice(1, 11)) {
    const n = alea.entier(1, 2);
    for (let k = 0; k < n; k += 1) {
      const kind = natures[Math.min(natures.length - 1, k + alea.entier(0, 1))] as NatureEchange;
      const objet = alea.choisir(objets);
      const cible = base.journalistes.find((j) => j.media_id === m.id);
      if (cible && alea.booleen(0.5)) noter('journaliste', cible.id, kind, alea.entier(3, 60), objet);
      else noter('media', m.id, kind, alea.entier(3, 60), objet);
    }
  }
  // Un échange rattaché à aucune rédaction (journaliste « à rattacher »).
  noter('journaliste', 502, 'press_followup', 12, 'Relance par message privé', null);

  // ── Fédérations ───────────────────────────────────────────────────────
  const regionaux = base.federations.filter((f) => f.niveau === 'regional');
  const federations: FicheFederation[] = base.federations.map((f, i) => {
    const tete = f.niveau === 'departemental' ? regionaux.find((r) => r.famille === f.famille) ?? null : null;
    const premier = i === 0;
    return {
      id: f.id,
      sigle: premier ? 'FBR' : null,
      entity_nature: 'federation',
      region_code: regionDeDepartement(f.department_code),
      secteurs: SECTEURS_PAR_FAMILLE[f.famille] ?? ['interprofessionnel'],
      tailles_adherents: premier ? ['tpe', 'pme'] : alea.echantillon(['tpe', 'pme', 'eti'], alea.entier(1, 2)),
      pertinence: premier ? 'haute' : alea.choisir(['haute', 'moyenne', 'moyenne', 'faible']),
      contactabilite: premier ? 'email_verifie' : alea.choisir(['email_verifie', 'formulaire_seulement', 'telephone_seulement']),
      certitude: premier ? 'haute' : alea.choisir(['haute', 'moyenne']),
      partenariat: premier ? 'en_discussion' : alea.choisir(['aucun', 'aucun', 'propose', 'accepte']),
      partenariat_relance_at: premier ? jourIso(-5) : null,
      partenariat_note: premier ? 'Premier échange avec le délégué, intéressé par un stand commun au salon.' : null,
      tete_id: tete?.id ?? null,
      identifiant: null,
      naf: '94.12Z',
      legal_form: 'Association loi 1901',
      effectif_range: '1 à 5',
      address: `${alea.entier(2, 80)} rue de la Fédération`,
      postcode: `${f.department_code}000`,
      sector_main: SECTEURS_PAR_FAMILLE[f.famille]?.[0] ?? 'interprofessionnel',
      linkedin_url: null,
      phone: null,
      contact_form_url: `${f.website}/contact`,
      nom_developpe: null,
      date_creation: `${alea.entier(1950, 2012)}-01-01`,
      nb_etablissements: null,
      origine_classement: 'Annuaire des fédérations',
    };
  });
  const contactsFederation: ContactFederation[] = [];
  for (const f of base.federations) {
    const n = f.id === 1001 ? 3 : alea.entier(1, 2);
    for (let k = 0; k < n; k += 1) {
      const prenom = alea.choisir(PRENOMS_CONTACT);
      const nom = alea.choisir(NOMS_CONTACT);
      contactsFederation.push({
        id: contactsFederation.length + 1,
        federation_id: f.id,
        first_name: prenom,
        last_name: nom,
        role: k === 0 ? 'Délégué général' : 'Chargé de mission',
        email: `${prenom.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')}.${nom.toLowerCase()}@${f.email_generic.split('@')[1] ?? 'federation.example'}`,
        phone: null,
        linkedin_url: null,
        informe: k === 0 && f.id === 1001,
      });
    }
  }

  // ── Événements ────────────────────────────────────────────────────────
  const evenements: FicheEvenement[] = base.evenements.map((e, i) => {
    const premier = i === 0;
    return {
      id: e.id,
      type: TYPE_EVENEMENT[e.type] ?? 'autre',
      heure: premier ? '9 h à 18 h' : null,
      recurrence: null,
      participation: premier ? 'inscrit' : PARTICIPATION[e.participation] ?? 'repere',
      intervention: premier ? 'proposee' : INTERVENTION[e.intervention] ?? 'aucune',
      prochaine_relance_at: premier ? jourIso(0) : i === 2 ? jourIso(9) : i === 5 ? jourIso(-3) : null,
      demarche_note: premier ? 'Dossier d’intervention envoyé, réponse attendue.' : null,
      lieu: `Salle des fêtes de ${e.ville}`,
      public_vise: 'Artisans, commerçants et élus locaux',
      taille: premier ? 'Plus de 2 000 visiteurs' : null,
      prix: premier ? 'Entrée libre' : null,
      lien_evenement: null,
      lien_inscription: null,
      appel_intervenants_limite: e.appel_intervenants === 'ouvert' ? jourIso(-30) : null,
      source_url: null,
      notes: null,
    };
  });
  const etapes: EtapeHistorique[] = [];
  const marquer = (sujet: EtapeHistorique['sujet'], sujet_id: number, kind: string, jours: number): void => {
    etapes.push({ id: etapes.length + 1, sujet, sujet_id, kind, occurred_at: ilYa(jours, 11, 0) });
  };
  marquer('evenement', 1, 'evenement_repere', 48);
  marquer('evenement', 1, 'evenement_inscrit', 30);
  marquer('evenement', 1, 'intervention_proposee', 9);
  marquer('federation', 1001, 'partenariat_propose', 40);
  marquer('federation', 1001, 'partenariat_en_discussion', 14);

  return { medias, journalistes, echanges, federations, contactsFederation, evenements, etapes };
}
