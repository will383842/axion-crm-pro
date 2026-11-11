/**
 * Jeu factice de « Alimenter la base » et des personnes (DM1-DIV) :
 *  - les personnes de la lettre et du guide, leurs tâches ;
 *  - les candidats ;
 *  - les collectes (tous les statuts) ;
 *  - la couverture de la France (départements, régions, villes) et une carte
 *    STYLISÉE (un carré par département) servie en local : aucune tuile, aucun
 *    service extérieur.
 *
 * Zone `alimenter` de `demoDb` (graine `alimenter`) : mêmes données à chaque
 * chargement. Données inventées : e-mails en `.example`, aucune personne réelle.
 */
import type { Alea } from '../../graine';
import { ilYa } from '../../horloge';
import { NOMS, PRENOMS } from '../../noms-fictifs';
import { enAscii } from '../outils-donnees';
import type { Campaign, CampaignSource, CampaignStatus, CampaignZone } from '@/features/collectes/types';
import type { CandidateFamily, CandidateRow, CandidateStage, NatureEmail, PersonneRow, StatutLettre } from '@/features/crm-console/types';
import type { Cell } from '@/features/coverage/statsCouverture';
import { FR_CITIES_TOP, FR_DEPARTMENTS, FR_REGIONS } from '@/features/collectes/fr-zones';

// ── Personnes de la lettre et du guide ────────────────────────────────────

export interface PersonneDemo extends PersonneRow {
  /** Abonnement à la lettre (absent : « sans abonnement »). */
  abonnement: { statut: StatutLettre; abonne_at: string | null; desabonne_at: string | null; motif: string | null } | null;
}

export interface TacheDemo {
  id: number;
  personne_id: number;
  title: string;
  due_at: string | null;
  done_at: string | null;
  created_at: string;
}

const ENTREPRISES_RATTACHEES = [
  'Imprimerie du Lac',
  'Transports Rivière',
  'Menuiserie Vidal et fils',
  'Atelier des Quais',
  'Cabinet Alpha Conseil',
  'Pâtisserie du Marché',
  'Garage des Platanes',
  'Électricité Perrin',
] as const;

const DOMAINES_PRO = ['imprimerie-lac', 'cabinet-alpha', 'transports-riviere', 'atelier-quais', 'menuiserie-vidal', 'garage-platanes'] as const;

function personneBase(id: number): Omit<PersonneDemo, 'first_name' | 'last_name' | 'email' | 'email_nature' | 'premiere_source' | 'rattachee' | 'entreprise' | 'abonnement' | 'derniere_interaction_at' | 'premiere_source_at'> {
  return {
    id,
    person_key: `pk-${String(9000 + id).padStart(4, '0')}`,
    locale: 'fr',
    legal_basis: 'legitimate_interest_b2b',
    statut_lettre: null,
    base_lettre: null,
    prospection_autorisee: false,
    placement: null,
    contact_id: null,
    company_id: null,
    purge_prevue_le: null,
  };
}

function creerPersonne(
  id: number,
  p: {
    prenom: string | null;
    nom: string | null;
    email: string;
    nature: NatureEmail;
    source: 'newsletter' | 'guide-ia';
    statut: StatutLettre | null;
    entreprise: string | null;
    jours: number;
  },
): PersonneDemo {
  const arrivee = ilYa(p.jours + 10, 10, 15);
  const interaction = ilYa(p.jours, 9, 0);
  return {
    ...personneBase(id),
    first_name: p.prenom,
    last_name: p.nom,
    email: p.email,
    email_nature: p.nature,
    premiere_source: p.source,
    premiere_source_at: arrivee,
    derniere_interaction_at: interaction,
    statut_lettre: p.statut,
    base_lettre: p.statut === null ? null : p.nature === 'pro' ? 'legitimate_interest_b2b' : 'consent',
    prospection_autorisee: p.statut === 'abonne' && p.nature === 'pro',
    placement: p.statut === null ? null : p.source === 'newsletter' ? 'pied-de-page' : 'page-guide',
    rattachee: p.entreprise !== null,
    contact_id: p.entreprise !== null ? 5000 + id : null,
    company_id: p.entreprise !== null ? 7000 + id : null,
    entreprise: p.entreprise,
    purge_prevue_le: null,
    abonnement:
      p.statut === null
        ? null
        : {
            statut: p.statut,
            abonne_at: arrivee,
            desabonne_at: p.statut === 'desabonne' ? ilYa(p.jours + 2, 8, 0) : null,
            motif: p.statut === 'desabonne' ? 'Ne souhaite plus recevoir la lettre' : null,
          },
  };
}

export function genererPersonnes(alea: Alea): { personnes: PersonneDemo[]; taches: TacheDemo[] } {
  const personnes: PersonneDemo[] = [
    creerPersonne(1, { prenom: 'Camille', nom: 'Brun', email: 'camille@imprimerie-lac.example', nature: 'pro', source: 'newsletter', statut: 'abonne', entreprise: 'Imprimerie du Lac', jours: 75 }),
    creerPersonne(2, { prenom: 'Hugo', nom: 'Marchand', email: 'h.marchand@cabinet-alpha.example', nature: 'pro', source: 'guide-ia', statut: null, entreprise: null, jours: 76 }),
    creerPersonne(3, { prenom: null, nom: null, email: 'e.leroy@messagerie.example', nature: 'perso', source: 'newsletter', statut: 'abonne', entreprise: null, jours: 78 }),
    creerPersonne(4, { prenom: 'Noémie', nom: 'Faure', email: 'n.faure@transports-riviere.example', nature: 'pro', source: 'newsletter', statut: 'desabonne', entreprise: 'Transports Rivière', jours: 130 }),
  ];
  for (let i = 5; i <= 52; i += 1) {
    const prenom = alea.choisir(PRENOMS);
    const nom = alea.choisir(NOMS);
    const nature: NatureEmail = alea.choisir(['pro', 'pro', 'pro', 'perso', 'inconnue'] as const);
    const domaine = nature === 'pro' ? `${alea.choisir(DOMAINES_PRO)}.example` : 'messagerie.example';
    const source = alea.booleen(0.55) ? 'newsletter' : 'guide-ia';
    const statut: StatutLettre | null = source === 'newsletter' ? (alea.booleen(0.88) ? 'abonne' : 'desabonne') : alea.booleen(0.3) ? 'abonne' : null;
    const entreprise = alea.booleen(0.4) ? alea.choisir(ENTREPRISES_RATTACHEES) : null;
    personnes.push(
      creerPersonne(i, {
        prenom,
        nom,
        email: `${enAscii(prenom, '.')}.${enAscii(nom, '.')}@${domaine}`,
        nature,
        source,
        statut,
        entreprise,
        jours: alea.entier(3, 160),
      }),
    );
  }
  const taches: TacheDemo[] = [
    { id: 1, personne_id: 2, title: 'Retrouver l’entreprise', due_at: '2026-11-05', done_at: null, created_at: ilYa(2, 9, 30) },
    { id: 2, personne_id: 3, title: 'Vérifier l’adresse personnelle avant tout rattachement', due_at: '2026-11-09', done_at: null, created_at: ilYa(1, 14, 0) },
  ];
  return { personnes, taches };
}

// ── Candidats ─────────────────────────────────────────────────────────────

const OFFRES: Record<CandidateFamily, readonly string[]> = {
  candidat_commercial: ['commercial-terrain', 'assistant-commercial'],
  candidat_video: ['monteur', 'cadreur'],
  candidat_tech: ['developpeur-web', 'analyste-donnees'],
  candidat_autre: ['assistant', 'gestionnaire-paie'],
};

function creerCandidat(
  id: number,
  prenom: string,
  nom: string,
  famille: CandidateFamily,
  etape: CandidateStage,
  offre: string,
  jours: number,
  opposition = false,
): CandidateRow {
  return {
    id,
    first_name: prenom,
    last_name: nom,
    email: `${enAscii(prenom, '.')}.${enAscii(nom, '.')}@example.test`,
    phone: null,
    relation_type: famille,
    lifecycle_stage: etape,
    offer_slug: offre,
    source: 'site',
    consent_version: 'v2',
    consent_vivier_at: ilYa(jours, 11, 0),
    derniere_interaction_at: ilYa(jours, 11, 0),
    purge_prevue_le: null,
    cv_ref: `cv-${String(id).padStart(4, '0')}`,
    opt_out: opposition,
    person_key: null,
    tags: [],
  };
}

export function genererCandidats(alea: Alea): CandidateRow[] {
  const liste: CandidateRow[] = [
    creerCandidat(1, 'Inès', 'Garnier', 'candidat_commercial', 'nouveau', 'commercial-terrain', 83),
    creerCandidat(2, 'Rémi', 'Caron', 'candidat_video', 'preselection', 'monteur', 83),
    creerCandidat(3, 'Aya', 'Mendes', 'candidat_tech', 'entretien', 'analyste-donnees', 83),
    creerCandidat(4, 'Lucas', 'Ferry', 'candidat_autre', 'vivier', 'assistant', 83),
  ];
  const familles: CandidateFamily[] = ['candidat_commercial', 'candidat_video', 'candidat_tech', 'candidat_autre'];
  const etapes: CandidateStage[] = ['nouveau', 'nouveau', 'preselection', 'entretien', 'vivier', 'vivier', 'vivier', 'refuse'];
  for (let i = 5; i <= 36; i += 1) {
    const famille = alea.choisir(familles);
    liste.push(
      creerCandidat(i, alea.choisir(PRENOMS), alea.choisir(NOMS), famille, alea.choisir(etapes), alea.choisir(OFFRES[famille]), alea.entier(5, 220), alea.booleen(0.06)),
    );
  }
  return liste;
}

// ── Collectes ─────────────────────────────────────────────────────────────

/** Ce que le serveur annonce selon l'état (miroir de `ScrapingCampaignResource`). */
export function droitsCollecte(statut: CampaignStatus): Pick<Campaign, 'can_start' | 'can_pause' | 'can_resume' | 'can_cancel'> {
  return {
    can_start: statut === 'draft' || statut === 'scheduled',
    can_pause: statut === 'running',
    can_resume: statut === 'paused',
    can_cancel: statut === 'running' || statut === 'paused' || statut === 'scheduled' || statut === 'draft',
  };
}

export interface CollecteDemo extends Campaign {
  /** Dernier passage de chaque source (alimente « Suivi temps réel » et « Passages »). */
  passages: Array<{ id: number; source: string; status: string; started_at: string | null; finished_at: string | null; error: string | null }>;
}

const NOM_SOURCE: Record<string, string> = { insee: 'INSEE Sirene', france_travail: 'France Travail', annuaire: 'Annuaire entreprises', ban: 'BAN géocodage', bodacc: 'BODACC' };
export const nomDeSource = (s: string): string => NOM_SOURCE[s] ?? s;

interface Gabarit {
  nom: string;
  description: string | null;
  statut: CampaignStatus;
  sources: CampaignSource[];
  zones: CampaignZone[];
  max: number;
  duree: number;
  creees: number;
  minutes: number;
  jours: number;
  raisonPause?: string;
}

const dep = (code: string): CampaignZone => ({ type: 'department', code, label: FR_DEPARTMENTS.find((d) => d.code === code)?.name ?? code });

const GABARITS: Gabarit[] = [
  { nom: 'Rhône — PME artisanat', description: 'Semaine 44', statut: 'running', sources: ['insee', 'france_travail'], zones: [dep('69')], max: 1000, duree: 180, creees: 620, minutes: 132, jours: 0 },
  { nom: 'Isère — commerces', description: 'Avant le salon', statut: 'paused', sources: ['insee'], zones: [dep('38')], max: 400, duree: 180, creees: 400, minutes: 60, jours: 1, raisonPause: 'quota_companies' },
  { nom: 'Savoie — tourisme', description: 'Saison d’hiver', statut: 'completed', sources: ['insee', 'annuaire', 'ban'], zones: [dep('73'), dep('74')], max: 1000, duree: 180, creees: 850, minutes: 170, jours: 3 },
  { nom: 'Bretagne — restauration', description: 'À lancer après validation', statut: 'draft', sources: ['insee', 'annuaire'], zones: [dep('22'), dep('29'), dep('35'), dep('56')], max: 2000, duree: 240, creees: 0, minutes: 0, jours: 0 },
  { nom: 'Ain — artisans du bâtiment', description: null, statut: 'completed', sources: ['insee'], zones: [dep('01')], max: 800, duree: 120, creees: 800, minutes: 96, jours: 6 },
  { nom: 'Loire — transport', description: null, statut: 'completed', sources: ['insee', 'france_travail'], zones: [dep('42')], max: 600, duree: 120, creees: 574, minutes: 118, jours: 9 },
  { nom: 'Drôme — agriculture', description: 'Maraîchage et vergers', statut: 'completed', sources: ['insee', 'annuaire'], zones: [dep('26')], max: 500, duree: 90, creees: 488, minutes: 80, jours: 12 },
  { nom: 'Ardèche — petites entreprises', description: null, statut: 'completed', sources: ['insee'], zones: [dep('07')], max: 500, duree: 90, creees: 431, minutes: 74, jours: 14 },
  { nom: 'Nord — imprimeurs', description: null, statut: 'completed', sources: ['insee', 'bodacc'], zones: [dep('59')], max: 1200, duree: 180, creees: 1200, minutes: 151, jours: 17 },
  { nom: 'Gironde — viticulture', description: 'Saison des vendanges', statut: 'completed', sources: ['insee', 'annuaire'], zones: [dep('33')], max: 900, duree: 150, creees: 877, minutes: 140, jours: 20 },
  { nom: 'Occitanie — formation', description: null, statut: 'completed', sources: ['insee', 'france_travail'], zones: [dep('31'), dep('34')], max: 1000, duree: 180, creees: 961, minutes: 160, jours: 24 },
  { nom: 'Alsace — industrie', description: null, statut: 'completed', sources: ['insee', 'annuaire'], zones: [dep('67'), dep('68')], max: 700, duree: 120, creees: 700, minutes: 105, jours: 27 },
  { nom: 'Île-de-France — conseil', description: null, statut: 'completed', sources: ['insee', 'france_travail', 'bodacc'], zones: [dep('75'), dep('92')], max: 1500, duree: 240, creees: 1403, minutes: 215, jours: 29 },
  { nom: 'Var — nautisme', description: null, statut: 'completed', sources: ['insee'], zones: [dep('83')], max: 450, duree: 90, creees: 436, minutes: 70, jours: 31 },
];

export function genererCollectes(): CollecteDemo[] {
  return GABARITS.map((g, i): CollecteDemo => {
    const id = i + 1;
    const demarree = g.statut === 'draft' ? null : ilYa(g.jours, 8, 30);
    const termine = g.statut === 'completed';
    const runsTotal = g.statut === 'draft' ? 0 : g.statut === 'running' ? 66 : 24 + (id % 5) * 6;
    const runsFaits = g.statut === 'running' ? 41 : termine ? runsTotal : g.statut === 'paused' ? 18 : 0;
    const restantes = Math.max(0, g.max - g.creees);
    const passages = g.statut === 'draft'
      ? []
      : g.sources.map((s, k) => ({
          id: id * 100 + k,
          source: s,
          status: g.statut === 'running' && k === 1 ? 'running' : k === 0 && g.statut === 'running' ? 'failed' : 'success',
          started_at: ilYa(g.jours, 10, 40 + k),
          finished_at: g.statut === 'running' && k === 1 ? null : ilYa(g.jours, 10, 42 + k),
          error: g.statut === 'running' && k === 0 ? 'délai dépassé' : null,
        }));
    return {
      archived_at: null,
      id,
      workspace_id: 'axion-ia',
      created_by: 'utilisateur-demo',
      name: g.nom,
      description: g.description,
      status: g.statut,
      sources: g.sources,
      zones: g.zones,
      max_companies: g.max,
      max_duration_minutes: g.duree,
      max_requests_per_minute: 30,
      per_source_limits: null,
      scheduled_at: null,
      expires_at: null,
      companies_created: g.creees,
      requests_made: g.creees * 3,
      runs_completed: runsFaits,
      runs_total: runsTotal,
      duration_seconds_used: g.minutes * 60,
      progress_percent: g.max === 0 ? 0 : Math.round((g.creees / g.max) * 100),
      elapsed_minutes: g.minutes,
      remaining_minutes: termine ? 0 : Math.max(0, g.duree - g.minutes),
      companies_remaining: restantes,
      started_at: demarree,
      paused_at: g.statut === 'paused' ? ilYa(g.jours, 9, 30) : null,
      finished_at: termine ? ilYa(g.jours, 11, 20) : null,
      paused_reason: g.raisonPause ?? null,
      ...droitsCollecte(g.statut),
      created_at: ilYa(g.jours + 1, 17, 0),
      updated_at: ilYa(g.jours, 11, 20),
      passages,
    };
  });
}

// ── Couverture de la France ───────────────────────────────────────────────

const REGION_DE: Record<string, string[]> = {
  '84': ['01', '03', '07', '15', '26', '38', '42', '43', '63', '69', '73', '74'],
  '27': ['21', '25', '39', '58', '70', '71', '89', '90'],
  '53': ['22', '29', '35', '56'],
  '24': ['18', '28', '36', '37', '41', '45'],
  '94': ['2A', '2B'],
  '44': ['08', '10', '51', '52', '54', '55', '57', '67', '68', '88'],
  '32': ['02', '59', '60', '62', '80'],
  '11': ['75', '77', '78', '91', '92', '93', '94', '95'],
  '28': ['14', '27', '50', '61', '76'],
  '75': ['16', '17', '19', '23', '24', '33', '40', '47', '64', '79', '86', '87'],
  '76': ['09', '11', '12', '30', '31', '32', '34', '46', '48', '65', '66', '81', '82'],
  '52': ['44', '49', '53', '72', '85'],
  '93': ['04', '05', '06', '13', '83', '84'],
  '01': ['971'],
  '02': ['972'],
  '03': ['973'],
  '04': ['974'],
  '06': ['976'],
};

/** Totaux imposés par la maquette ; les autres départements couverts sont tirés. */
const TOTAUX_FIXES: Record<string, number> = { '69': 4120, '38': 2980, '74': 1330, '01': 1240, '73': 1010 };

export interface CelluleDemo extends Cell {
  /** `level:code` : identifiant de la collection. */
  id: string;
  level: 'region' | 'department' | 'city';
}

export function genererCouverture(alea: Alea): CelluleDemo[] {
  const departements: CelluleDemo[] = FR_DEPARTMENTS.map((d) => {
    const fixe = TOTAUX_FIXES[d.code];
    const couvert = fixe !== undefined || alea.booleen(0.34);
    const total = fixe ?? (couvert ? alea.entier(80, 1800) : 0);
    const complete = Math.round(total * 0.18);
    const partial = Math.round(total * 0.52);
    return { id: `department:${d.code}`, level: 'department', code: d.code, name: d.name, total, complete, partial };
  });
  const regions: CelluleDemo[] = FR_REGIONS.map((r) => {
    const membres = departements.filter((d) => (REGION_DE[r.code] ?? []).includes(d.code));
    return {
      id: `region:${r.code}`,
      level: 'region',
      code: r.code,
      name: r.name,
      total: membres.reduce((s, d) => s + d.total, 0),
      complete: membres.reduce((s, d) => s + (d.complete ?? 0), 0),
      partial: membres.reduce((s, d) => s + (d.partial ?? 0), 0),
    };
  });
  // Les villes de la démo : celles de la liste du CRM, sauf une que les règles de communication écartent (code 38185).
  const villes: CelluleDemo[] = FR_CITIES_TOP.filter((v) => v.code !== '38185')
    .slice(0, 14)
    .map((v, i) => {
      const total = i === 2 ? 1840 : alea.entier(0, 620);
      return { id: `city:${v.code}`, level: 'city', code: v.code, name: v.name, total, complete: Math.round(total * 0.2), partial: Math.round(total * 0.5) };
    });
  return [...departements, ...regions, ...villes];
}

// ── Carte stylisée (un carré par département métropolitain) ───────────────

/** Centres approximatifs (longitude, latitude) : la carte est un fond de démo, pas un relevé. */
const CENTRES: Record<string, [number, number]> = {
  '01': [5.35, 46.1], '02': [3.6, 49.5], '03': [3.2, 46.4], '04': [6.2, 44.1], '05': [6.3, 44.7], '06': [7.1, 43.9], '07': [4.4, 44.8], '08': [4.6, 49.6],
  '09': [1.4, 42.9], '10': [4.1, 48.3], '11': [2.4, 43.1], '12': [2.7, 44.3], '13': [5.1, 43.55], '14': [-0.35, 49.1], '15': [2.7, 45.05], '16': [0.2, 45.7],
  '17': [-0.8, 45.8], '18': [2.5, 47.0], '19': [1.9, 45.3], '21': [4.8, 47.4], '22': [-2.9, 48.4], '23': [2.0, 46.0], '24': [0.7, 45.1], '25': [6.3, 47.2],
  '26': [5.1, 44.7], '27': [1.1, 49.1], '28': [1.4, 48.4], '29': [-4.1, 48.3], '2A': [8.9, 41.9], '2B': [9.2, 42.4], '30': [4.2, 44.0], '31': [1.2, 43.4],
  '32': [0.5, 43.7], '33': [-0.6, 44.8], '34': [3.4, 43.6], '35': [-1.7, 48.15], '36': [1.6, 46.8], '37': [0.7, 47.25], '38': [5.6, 45.3], '39': [5.7, 46.8],
  '40': [-0.8, 43.95], '41': [1.4, 47.6], '42': [4.2, 45.7], '43': [3.8, 45.1], '44': [-1.7, 47.35], '45': [2.3, 47.9], '46': [1.6, 44.6], '47': [0.5, 44.35],
  '48': [3.5, 44.5], '49': [-0.55, 47.4], '50': [-1.3, 49.0], '51': [4.1, 48.95], '52': [5.2, 48.1], '53': [-0.65, 48.15], '54': [6.1, 48.8], '55': [5.4, 49.0],
  '56': [-2.8, 47.85], '57': [6.6, 49.0], '58': [3.5, 47.1], '59': [3.2, 50.4], '60': [2.4, 49.4], '61': [0.1, 48.6], '62': [2.3, 50.5], '63': [3.1, 45.7],
  '64': [-0.8, 43.2], '65': [0.15, 43.0], '66': [2.5, 42.6], '67': [7.55, 48.65], '68': [7.25, 47.9], '69': [4.65, 45.85], '70': [6.1, 47.65], '71': [4.5, 46.65],
  '72': [0.2, 48.0], '73': [6.4, 45.5], '74': [6.4, 46.0], '75': [2.35, 48.86], '76': [1.0, 49.65], '77': [3.0, 48.6], '78': [1.85, 48.8], '79': [-0.4, 46.5],
  '80': [2.3, 49.95], '81': [2.2, 43.8], '82': [1.3, 44.0], '83': [6.3, 43.45], '84': [5.2, 44.0], '85': [-1.3, 46.7], '86': [0.45, 46.6], '87': [1.2, 45.9],
  '88': [6.4, 48.2], '89': [3.6, 47.85], '90': [6.9, 47.6], '91': [2.2, 48.5], '92': [2.2, 48.85], '93': [2.45, 48.92], '94': [2.45, 48.78], '95': [2.1, 49.1],
};

const PETITS = new Set(['75', '92', '93', '94']);
const MOYENS = new Set(['77', '78', '91', '95']);

/** Fond de carte stylisé : un carré par département, au format GeoJSON (WGS84). */
export function carteStylisee(): { type: 'FeatureCollection'; features: unknown[] } {
  const features = Object.entries(CENTRES).map(([code, [lon, lat]]) => {
    const dx = PETITS.has(code) ? 0.07 : MOYENS.has(code) ? 0.18 : 0.31;
    const dy = PETITS.has(code) ? 0.05 : MOYENS.has(code) ? 0.13 : 0.23;
    return {
      type: 'Feature',
      properties: { code, nom: FR_DEPARTMENTS.find((d) => d.code === code)?.name ?? code },
      geometry: {
        type: 'Polygon',
        coordinates: [[[lon - dx, lat - dy], [lon + dx, lat - dy], [lon + dx, lat + dy], [lon - dx, lat + dy], [lon - dx, lat - dy]]],
      },
    };
  });
  return { type: 'FeatureCollection', features };
}

// ── La zone entière ───────────────────────────────────────────────────────

export interface AlimenterGenere {
  personnes: PersonneDemo[];
  taches: TacheDemo[];
  candidats: CandidateRow[];
  collectes: CollecteDemo[];
  couverture: CelluleDemo[];
}

export function genererAlimenterDemo(alea: Alea): AlimenterGenere {
  const { personnes, taches } = genererPersonnes(alea);
  return { personnes, taches, candidats: genererCandidats(alea), collectes: genererCollectes(), couverture: genererCouverture(alea) };
}

// ── Origines des contacts (exemple) ───────────────────────────────────────
// Quatorze personnes fictives « exemple », adresses en `exemple.invalid`. Mêmes repères que les
// maquettes validées (Personne exemple 01…14). `post_ref` est un identifiant opaque inventé.

export interface OrigineDemo {
  famille: string | null; detail: string | null; canal: string | null; reseau: string | null; compte: string | null;
  mot: string | null; guide: string | null; date: string; post_ref: string | null;
}
export interface PersonneOrigineDemo {
  id: number; numero: string; opposee: boolean; origines: OrigineDemo[];
}

const o = (
  famille: string | null, detail: string | null, canal: string | null, reseau: string | null, mot: string | null,
  guide: string | null, jours: number, post_ref: string | null,
): OrigineDemo => ({ famille, detail, canal, reseau, compte: reseau === null ? null : 'Page principale', mot, guide, date: ilYa(jours, 14, 7), post_ref });

export function genererOriginesDemo(): PersonneOrigineDemo[] {
  const p = (n: number, opposee: boolean, origines: OrigineDemo[]): PersonneOrigineDemo => ({ id: 900000 + n, numero: String(n).padStart(2, '0'), opposee, origines });
  return [
    p(1, true, [o('guide_reseau', 'guide_ia', 'commentaire', 'instagram', 'guide', 'Guide exemple A', 2, 'pr_Xk29aF7Qm1'), o('cas_concret', null, 'commentaire', 'facebook', 'cas', 'Cas exemple B', 14, 'pr_7TnQ0vLb4Zc'), o(null, null, null, null, null, null, 27, null)]),
    p(2, false, [o('guide_reseau', 'guide_ia', 'message_prive', 'facebook', 'guide', 'Guide exemple A', 2, 'pr_Hd83LmQ2xW'), o('guide_reseau', 'guide_ia', 'commentaire', 'facebook', 'guide', 'Guide exemple A', 15, 'pr_Ab12Cd34Ef5')]),
    p(3, false, [o('guide_reseau', 'guide_ia', 'commentaire', 'instagram', 'guide', null, 3, 'pr_Zt40BvN7cK')]),
    p(4, true, [o('cas_concret', null, 'commentaire', 'linkedin', 'cas', 'Cas exemple C', 5, 'pr_Qw17PxR9eD')]),
    p(5, false, [o('video', null, 'publication', 'tiktok', 'video', 'Vidéo exemple A', 6, 'pr_Vn52TgY8sA')]),
    p(6, false, [o('autre', 'autre_non_classe', null, null, null, null, 8, null)]),
    p(7, true, [o('cas_concret', null, null, null, null, 'Cas exemple A', 7, null)]),
    p(8, true, [o('autre', 'autre_non_classe', null, null, null, null, 26, null)]),
    p(9, true, [o('guide_reseau', 'guide_ia', 'message_prive', 'facebook', 'guide', 'Guide exemple A', 20, 'pr_Ke66DsF1zQ')]),
    p(10, true, [o('carrousel', null, 'publication', 'instagram', 'modele', 'Carrousel exemple A', 30, 'pr_Lm90RtU2yB')]),
    p(11, false, [o('guide_reseau', null, null, null, null, 'Guide exemple A', 4, null)]),
    p(12, false, [o('carrousel', null, 'publication', 'linkedin', 'modele', null, 15, 'pr_Fg35HkP8nC')]),
    p(13, true, [o('tour_france_ia', null, null, null, null, null, 68, null)]),
    p(14, true, [o(null, null, null, null, null, null, 56, null)]),
  ];
}
