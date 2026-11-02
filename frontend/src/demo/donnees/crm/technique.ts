/**
 * Jeu factice de la section « Technique » (DM1-TEC) : vivier Roumanie,
 * historique des collectes, santé du système, moteurs d'IA, serveurs relais,
 * rotation des accès, registre de l'IA et sauvegardes.
 *
 * Zone `technique` de `demoDb` (graine `technique`) : mêmes données à chaque
 * chargement. Aucune clé, aucun nom de serveur réel, aucune adresse réseau :
 * seuls des identifiants parlants (`relais-residentiel-a`) apparaissent.
 */
import type { Alea } from '../../graine';
import { decalage, ilYa } from '../../horloge';
import { COMPLEMENTS, METIERS } from '../../noms-fictifs';

// ── Types (formes JSON des écrans existants) ───────────────────────────────

export interface LigneRoumanie {
  id: number;
  denomination: string;
  city: string;
  website: string | null;
  phone: string | null;
  email_generic: string | null;
  entity_nature: string;
  prospection_status: 'ready_for_outreach' | 'partial_email' | 'pending' | 'archived_no_email';
  siren: null;
  country_code: 'RO';
}

export interface PassageCollecte {
  id: number;
  source: string;
  status: 'pending' | 'running' | 'success' | 'failed' | 'cancelled';
  workspace_id: string;
  company_id: null;
  latency_ms: number | null;
  error: string | null;
  started_at: string | null;
  finished_at: string | null;
  request_payload: { type: string; department: string; limit: number };
  response_payload: { companies_created: number; companies_target: number };
}

export interface CasUsageIa {
  id: number;
  slug: string;
  description: string;
  primary_provider: string;
  model: string;
  fallback_chain: string[];
  prompt_version: number;
  cost_cap_eur: number;
  enabled: boolean;
  last_used_at: string | null;
}

export interface FournisseurRelais {
  id: number;
  slug: string;
  type: 'residential' | 'datacenter' | 'mobile';
  zone: string;
  enabled: boolean;
  weight: number;
  endpoints_count: number;
  last_health_check_at: string | null;
  last_health_status: 'healthy' | 'unhealthy' | null;
}

export interface RotationAcces {
  id: number;
  dimension: 'proxy' | 'user_agent' | 'target' | 'search_engine' | 'llm';
  slug: string;
  weight: number;
  cooldown_seconds: number;
  enabled: boolean;
  last_used_at: string | null;
}

export interface EntreeRegistreIa {
  id: number;
  system_name: string;
  purpose: string;
  risk_class: 'prohibited' | 'high' | 'limited' | 'minimal';
  provider: string;
  model: string;
  impact_assessment: Record<string, unknown>;
  dpia_url: null;
  responsible: string;
  status: 'production' | 'staging' | 'sunset' | 'draft';
  last_review_at: string;
}

export interface EvenementMetier {
  id: number;
  action: string;
  resource_type: string | null;
  resource_id: string | null;
  context: null;
  created_at: string;
}

export interface Sauvegardes {
  derniere_copie_le: string;
  derniere_copie_taille_mo: number;
  copie_hors_site_le: string;
  dernier_exercice_restauration_le: string;
  dernier_exercice_resultat: 'reussi' | 'echec';
}

export interface ReperesSante {
  archivages: Record<string, number>;
  erreurs_24h: number;
  quota_places: { used: number; soft_limit: number; percent: number; pending_companies: number };
  quota_hunter: { used: number; soft_limit: number; percent: number };
  echecs_audience_7j: number;
  evenements: EvenementMetier[];
  sauvegardes: Sauvegardes;
}

export interface TechniqueGeneree {
  roumanie: LigneRoumanie[];
  passages: PassageCollecte[];
  casUsage: CasUsageIa[];
  relais: FournisseurRelais[];
  rotations: RotationAcces[];
  registre: EntreeRegistreIa[];
  /** Un seul élément : repères de la santé du système (pour que les gestes y soient notés). */
  sante: Array<ReperesSante & { id: number }>;
}

// ── Générateurs ────────────────────────────────────────────────────────────

const NATURES_RO = ['entreprise', 'entreprise', 'entreprise', 'association', 'cci', 'enseignement', 'cabinet', 'institution', 'reseau', 'federation'] as const;
const VILLES_RO = ['Bucarest', 'Cluj-Napoca', 'Timisoara', 'Brasov', 'Iasi', 'Constanta', 'Sibiu', 'Oradea'] as const;

function genererRoumanie(alea: Alea): LigneRoumanie[] {
  const lignes: LigneRoumanie[] = [];
  for (let i = 1; i <= 64; i += 1) {
    const metier = alea.choisir(METIERS);
    const complement = alea.choisir(COMPLEMENTS);
    const slug = `${metier.slug}-${complement.slug}-ro`;
    const statut = alea.suivant();
    const prospection_status: LigneRoumanie['prospection_status'] =
      statut < 0.5 ? 'ready_for_outreach' : statut < 0.68 ? 'partial_email' : statut < 0.88 ? 'pending' : 'archived_no_email';
    const avecEmail = prospection_status === 'ready_for_outreach' || prospection_status === 'partial_email';
    lignes.push({
      id: 700_000 + i,
      denomination: `${metier.metier} ${complement.libelle} Roumanie`,
      city: alea.choisir(VILLES_RO),
      website: alea.booleen(0.7) ? `https://${slug}.example` : null,
      phone: alea.booleen(0.6) ? `+40 21 555 ${String(alea.entier(1000, 9999))}` : null,
      email_generic: avecEmail ? `contact@${slug}.example` : null,
      entity_nature: alea.choisir(NATURES_RO),
      prospection_status,
      siren: null,
      country_code: 'RO',
    });
  }
  return lignes;
}

const SOURCES = ['annuaire-chambre-fr-ro', 'registre-entreprises', 'offres-emploi', 'cartes-et-lieux', 'sites-institutionnels'] as const;
const DEPARTEMENTS = ['69', '38', '01', '42', '73', '74', '07', '26'] as const;
const ERREURS = [
  'Le site a répondu trop lentement à trois reprises.',
  'Le quota quotidien de la source est atteint, reprise demain.',
  'La page consultée a changé de structure.',
  'Accès refusé par la source (limite de fréquence).',
] as const;

function genererPassages(alea: Alea): PassageCollecte[] {
  const passages: PassageCollecte[] = [];
  // Deux en cours et un en attente, tout en haut : la page « vit » sans rien bouger.
  const enCours: Array<[PassageCollecte['status'], number]> = [['running', 12], ['running', 41], ['pending', 3]];
  enCours.forEach(([status, minutes], i) => {
    const cible = alea.entier(120, 400);
    passages.push({
      id: 4100 - i,
      source: SOURCES[i % SOURCES.length] ?? SOURCES[0],
      status,
      workspace_id: 'espace-demo',
      company_id: null,
      latency_ms: null,
      error: null,
      started_at: status === 'pending' ? null : decalage({ minutes: -minutes }),
      finished_at: null,
      request_payload: { type: 'department', department: alea.choisir(DEPARTEMENTS), limit: cible },
      response_payload: { companies_created: status === 'pending' ? 0 : Math.floor(cible * 0.35), companies_target: cible },
    });
  });
  for (let i = 3; i < 46; i += 1) {
    const tirage = alea.suivant();
    const status: PassageCollecte['status'] = tirage < 0.74 ? 'success' : tirage < 0.9 ? 'failed' : 'cancelled';
    const cible = alea.entier(80, 500);
    const debutJours = Math.floor(i / 2);
    const debut = ilYa(debutJours, alea.entier(6, 22), alea.entier(0, 59));
    const duree = alea.entier(4, 55);
    passages.push({
      id: 4100 - i,
      source: alea.choisir(SOURCES),
      status,
      workspace_id: 'espace-demo',
      company_id: null,
      latency_ms: alea.entier(300, 4200),
      error: status === 'failed' ? alea.choisir(ERREURS) : null,
      started_at: debut,
      finished_at: new Date(Date.parse(debut) + duree * 60_000).toISOString(),
      request_payload: { type: 'department', department: alea.choisir(DEPARTEMENTS), limit: cible },
      response_payload: {
        companies_created: status === 'success' ? Math.round(cible * (0.8 + alea.suivant() * 0.2)) : status === 'failed' ? Math.round(cible * alea.suivant() * 0.4) : 0,
        companies_target: cible,
      },
    });
  }
  return passages;
}

function genererCasUsage(alea: Alea): CasUsageIa[] {
  const defs: Array<[string, string, string, string, string[], number, boolean]> = [
    ['classer-activite', 'Classe les entreprises par activité à partir de leur description.', 'mistral', 'modele-rapide', ['anthropic'], 15, true],
    ['resumer-site', 'Résume le site d’une entreprise en deux phrases.', 'mistral', 'modele-rapide', ['anthropic'], 10, true],
    ['reperer-contact', 'Repère la bonne personne à contacter dans une page d’équipe.', 'anthropic', 'modele-precis', ['mistral'], 25, true],
    ['rediger-premier-message', 'Propose un premier message à relire par une personne.', 'anthropic', 'modele-precis', ['mistral'], 30, true],
    ['analyser-reponse', 'Classe une réponse reçue : intéressé, refus, absence.', 'mistral', 'modele-rapide', ['anthropic'], 12, true],
    ['normaliser-adresse', 'Remet une adresse postale en forme.', 'mistral', 'modele-rapide', [], 5, false],
  ];
  return defs.map(([slug, description, primary_provider, model, fallback_chain, cost_cap_eur, enabled], i) => ({
    id: i + 1,
    slug,
    description,
    primary_provider,
    model,
    fallback_chain,
    prompt_version: alea.entier(1, 5),
    cost_cap_eur,
    enabled,
    last_used_at: enabled ? ilYa(0, alea.entier(6, 9), alea.entier(0, 59)) : null,
  }));
}

function genererRelais(alea: Alea): FournisseurRelais[] {
  const defs: Array<[string, FournisseurRelais['type'], string, boolean, number, number, FournisseurRelais['last_health_status']]> = [
    ['relais-residentiel-a', 'residential', 'eu-ouest', true, 60, 24, 'healthy'],
    ['relais-residentiel-b', 'residential', 'eu-centre', true, 30, 12, 'healthy'],
    ['relais-datacenter-a', 'datacenter', 'eu-ouest', true, 80, 40, 'healthy'],
    ['relais-mobile-a', 'mobile', 'eu-ouest', true, 10, 6, 'unhealthy'],
    ['relais-datacenter-b', 'datacenter', 'eu-nord', false, 0, 0, null],
  ];
  return defs.map(([slug, type, zone, enabled, weight, endpoints_count, last_health_status], i) => ({
    id: i + 1,
    slug,
    type,
    zone,
    enabled,
    weight,
    endpoints_count,
    last_health_check_at: last_health_status === null ? null : ilYa(0, 8, alea.entier(0, 29)),
    last_health_status,
  }));
}

function genererRotations(alea: Alea): RotationAcces[] {
  const defs: Array<[RotationAcces['dimension'], string[]]> = [
    ['proxy', ['relais-residentiel-a', 'relais-residentiel-b', 'relais-datacenter-a']],
    ['user_agent', ['navigateur-bureau-a', 'navigateur-bureau-b', 'navigateur-mobile-a', 'navigateur-tablette-a']],
    ['target', ['annuaire-chambre-fr-ro', 'registre-entreprises', 'offres-emploi']],
    ['search_engine', ['moteur-a', 'moteur-b']],
    ['llm', ['mistral', 'anthropic']],
  ];
  const lignes: RotationAcces[] = [];
  for (const [dimension, slugs] of defs) {
    slugs.forEach((slug, i) => {
      lignes.push({
        id: lignes.length + 1,
        dimension,
        slug,
        weight: [50, 30, 20, 10][i] ?? 10,
        cooldown_seconds: alea.choisir([5, 15, 30, 60]),
        enabled: !(dimension === 'user_agent' && i === 3),
        last_used_at: dimension === 'user_agent' && i === 3 ? null : ilYa(0, alea.entier(7, 9), alea.entier(0, 59)),
      });
    });
  }
  return lignes;
}

function genererRegistre(): EntreeRegistreIa[] {
  const defs: Array<[string, string, EntreeRegistreIa['risk_class'], string, string, EntreeRegistreIa['status'], string, number]> = [
    ['Classement par activité', 'Classer les entreprises par secteur pour mieux les cibler.', 'minimal', 'mistral', 'modele-rapide', 'production', 'Équipe données', 40],
    ['Résumé de site', 'Résumer le site d’une entreprise pour préparer un échange.', 'minimal', 'mistral', 'modele-rapide', 'production', 'Équipe données', 40],
    ['Repérage de contact', 'Repérer une personne à contacter dans une page publique.', 'limited', 'anthropic', 'modele-precis', 'production', 'Responsable des données', 62],
    ['Aide à la rédaction', 'Proposer un message relu par une personne avant tout envoi.', 'limited', 'anthropic', 'modele-precis', 'production', 'Responsable des données', 62],
    ['Tri des réponses', 'Classer une réponse reçue (intéressé, refus, absence).', 'high', 'mistral', 'modele-rapide', 'staging', 'Responsable des données', 95],
    ['Normalisation d’adresses', 'Remettre une adresse postale en forme.', 'minimal', 'mistral', 'modele-rapide', 'sunset', 'Équipe données', 190],
  ];
  return defs.map(([system_name, purpose, risk_class, provider, model, status, responsible, joursDepuisRevue], i) => ({
    id: i + 1,
    system_name,
    purpose,
    risk_class,
    provider,
    model,
    impact_assessment: {
      human_oversight: risk_class === 'high' ? 'Chaque classement est relu par une personne avant toute action.' : 'Contrôle par échantillon chaque mois.',
      opt_out_route: '/rgpd/requests',
      mesures: ['Aucune décision automatique sans relecture', 'Données minimales envoyées au moteur'],
    },
    dpia_url: null,
    responsible,
    status,
    last_review_at: ilYa(joursDepuisRevue),
  }));
}

function genererSante(alea: Alea): Array<ReperesSante & { id: number }> {
  const actions = ['audience.refreshed', 'company.enriched', 'company.tags_synced', 'email.verified', 'company.archived'];
  const evenements: EvenementMetier[] = [];
  for (let i = 0; i < 50; i += 1) {
    const action = alea.choisir(actions);
    const estEmail = action === 'email.verified';
    evenements.push({
      id: 9000 - i,
      action,
      resource_type: estEmail ? 'email' : action.startsWith('audience') ? 'audience' : 'company',
      resource_id: estEmail ? `contact${i}@example.test` : String(alea.entier(1, 400)),
      context: null,
      created_at: decalage({ minutes: -(i * 17 + alea.entier(1, 10)) }),
    });
  }
  return [
    {
      id: 1,
      archivages: { entreprise_radiee: 128, no_email: 342, low_quality_score: 57, duplicate: 23, manual: 9 },
      erreurs_24h: 4,
      quota_places: { used: 7420, soft_limit: 12000, percent: 62, pending_companies: 1180 },
      quota_hunter: { used: 310, soft_limit: 500, percent: 62 },
      echecs_audience_7j: 0,
      evenements,
      sauvegardes: {
        derniere_copie_le: ilYa(0, 3, 10),
        derniere_copie_taille_mo: 842,
        copie_hors_site_le: ilYa(0, 4, 30),
        dernier_exercice_restauration_le: ilYa(21, 14, 0),
        dernier_exercice_resultat: 'reussi',
      },
    },
  ];
}

/** Toute la zone, d'un seul tirage ordonné (même graine, mêmes données). */
export function genererTechniqueDemo(alea: Alea): TechniqueGeneree {
  return {
    roumanie: genererRoumanie(alea),
    passages: genererPassages(alea),
    casUsage: genererCasUsage(alea),
    relais: genererRelais(alea),
    rotations: genererRotations(alea),
    registre: genererRegistre(),
    sante: genererSante(alea),
  };
}
