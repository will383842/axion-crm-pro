/**
 * Jeu factice des contacts (DM1-CON) : une fiche par contact du jeu de base,
 * avec ce que la démo ajoute (provenance, étape, corbeille, rappel), plus
 * l'historique (notes, appels, rendez-vous) et les rapports d'import.
 *
 * Zone `contacts` de `demoDb` (graine `contacts`) : mêmes données à chaque
 * chargement. Aucune donnée réelle : e-mails en `.example`, noms inventés.
 */
import type { Alea } from '../../graine';
import { ilYa } from '../../horloge';
import type { Contact, Entreprise } from '../types';
import type { FicheContact, RapportImport } from '@/features/contacts/ajout-rapide/types';
import type { LifecycleStage, RelationType } from '@/features/crm-console/types';

/** La clé stable d'une personne dans l'adresse de sa fiche (`pk-0001`). */
export function cleDePersonne(id: number): string {
  return `pk-${String(id).padStart(4, '0')}`;
}

/** Retrouve l'identifiant d'une fiche depuis sa clé de personne. */
export function idDeCle(cle: string): number | null {
  const m = /^pk-(\d+)$/.exec(cle);
  return m === null ? null : Number(m[1]);
}

export interface Organisation {
  id: number;
  denomination: string;
  city_name: string;
  relation_type: RelationType;
  lifecycle_stage: LifecycleStage;
}

export interface EntreeHistorique {
  id: number;
  contact_id: number;
  kind: 'note' | 'appel' | 'rendez_vous' | 'questionnaire';
  title: string;
  occurred_at: string;
}

export interface ContactsGeneres {
  fiches: FicheContact[];
  organisations: Organisation[];
  historique: EntreeHistorique[];
  imports: Array<{ id: number; nom_fichier: string; le: string; rapport: RapportImport }>;
}

/** Première organisation ajoutée en démo : au-dessus des identifiants du jeu de base. */
export const PREMIER_ID_ORGANISATION_AJOUTEE = 100_001;

const RELATIONS: ReadonlyArray<{ type: RelationType; poids: number }> = [
  { type: 'prospect', poids: 62 },
  { type: 'client', poids: 12 },
  { type: 'presse_media', poids: 8 },
  { type: 'partenaire', poids: 7 },
  { type: 'investisseur', poids: 2 },
  { type: 'conference', poids: 4 },
  { type: 'fournisseur', poids: 5 },
];

const ETAPES_PAR_RELATION: Record<string, ReadonlyArray<LifecycleStage>> = {
  client: ['client', 'client', 'dormant'],
  prospect: ['nouveau', 'qualifie', 'qualifie', 'opportunite', 'dormant', 'perdu'],
  default: ['nouveau', 'qualifie', 'dormant'],
};

const PROVENANCES_FABRIQUEES = ['salon', 'site', 'rencontre', 'recommandation', 'fichier-client'] as const;

function tirerRelation(alea: Alea): RelationType {
  const total = RELATIONS.reduce((n, r) => n + r.poids, 0);
  let reste = alea.suivant() * total;
  for (const r of RELATIONS) {
    reste -= r.poids;
    if (reste < 0) return r.type;
  }
  return 'prospect';
}

export function genererContactsDemo(alea: Alea, contacts: readonly Contact[], entreprises: readonly Entreprise[]): ContactsGeneres {
  const nomDe = new Map(entreprises.map((e) => [e.id, e.denomination]));

  // Une relation et une étape par ORGANISATION : tous ses contacts les partagent.
  const parOrganisation = new Map<number, { relation: RelationType; etape: LifecycleStage }>();
  entreprises.forEach((e, i) => {
    const relation = i === 0 ? 'prospect' : i === 1 ? 'client' : tirerRelation(alea);
    const etapes = ETAPES_PAR_RELATION[relation] ?? ETAPES_PAR_RELATION['default']!;
    const etape = i === 0 ? 'qualifie' : i === 1 ? 'client' : i === 2 ? 'opportunite' : alea.choisir(etapes);
    parOrganisation.set(e.id, { relation, etape });
  });

  const fiches: FicheContact[] = contacts.map((c) => {
    const o = parOrganisation.get(c.company_id) ?? { relation: 'prospect' as const, etape: 'nouveau' as const };
    return {
      id: c.id,
      company_id: c.company_id,
      first_name: c.first_name,
      last_name: c.last_name,
      email: c.email,
      fonction: c.role,
      organisation: nomDe.get(c.company_id) ?? '',
      relation_type: o.relation,
      lifecycle_stage: o.etape,
      provenance: c.id === 1 ? 'salon' : alea.choisir(PROVENANCES_FABRIQUEES),
      person_key: cleDePersonne(c.id),
      retire_le: null,
      ne_plus_ecrire: c.ne_plus_ecrire,
      verifie_il_y_a_jours: c.id === 1 ? 9 : alea.entier(2, 40),
      rappel_le: null,
      rappel_note: null,
      created_at: c.created_at,
    };
  });

  const historique: EntreeHistorique[] = [];
  const noter = (contact_id: number, kind: EntreeHistorique['kind'], title: string, occurred_at: string) => {
    historique.push({ id: historique.length + 1, contact_id, kind, title, occurred_at });
  };
  // Repères : Paul Morin (id 1) et Julie Morin (id 2).
  noter(1, 'questionnaire', 'Questionnaire reçu (2 sur 4)', ilYa(40, 14, 5));
  noter(1, 'note', 'Note : préfère être appelé le matin', ilYa(9, 10, 40));
  noter(1, 'rendez_vous', 'Rendez-vous prévu : démonstration à l’atelier', ilYa(-2, 10, 0));
  noter(2, 'appel', 'Appel : à rappeler après les fêtes', ilYa(20, 11, 15));
  const TITRES: ReadonlyArray<[EntreeHistorique['kind'], string]> = [
    ['note', 'Note : a demandé le catalogue'],
    ['appel', 'Appel : message laissé'],
    ['note', 'Note : à relancer le mois prochain'],
    ['rendez_vous', 'Rendez-vous : visite de l’atelier'],
  ];
  for (let i = 0; i < 60; i += 1) {
    const c = contacts[alea.entier(4, contacts.length - 1)]!;
    const [kind, title] = alea.choisir(TITRES);
    noter(c.id, kind, title, ilYa(alea.entier(3, 38), alea.entier(8, 18), alea.entier(0, 59)));
  }

  return { fiches, organisations: [], historique, imports: [] };
}
