/**
 * Données de démo du ciblage (DM1-CIB) : critères des audiences, membres des
 * listes manuelles, état des listes et des étiquettes.
 *
 * Tout se dérive du jeu de base (`demoDb`) et de la graine de la zone
 * « ciblage » : mêmes données à chaque démarrage. Rien ne se supprime
 * (règle n° 2) : une liste va à la corbeille, une étiquette s'archive, une
 * fiche retirée d'une liste garde sa ligne (`retire_le`).
 */
import type { AudienceCriteria } from '@/features/audiences/AudiencesListPage';
import { demoDb } from '../../base';
import type { Alea } from '../../graine';

export interface AudienceCiblage {
  id: number;
  criteria: AudienceCriteria;
  destinataires_mode: 'personne_sinon_generique' | 'generique' | 'nominatives' | 'les_deux';
  archivee: boolean;
}

export interface MembreCiblage {
  id: number;
  liste_id: number;
  type: 'organisation' | 'personne';
  origine: 'coche' | 'import';
  ajoute_le: string;
  company_id: number | null;
  contact_id: number | null;
  retire_le: string | null;
}

export interface ListeEtat {
  id: number;
  deleted_at: string | null;
}

export interface EtiquetteMeta {
  /** Le slug de l'étiquette. */
  id: string;
  categorie: 'geo' | 'sector' | 'size' | 'intent' | 'custom' | 'ia';
  description: string | null;
  archivee: boolean;
}

export interface DonneesCiblage {
  audiences: AudienceCiblage[];
  membres: MembreCiblage[];
  listesEtat: ListeEtat[];
  etiquettesMeta: EtiquetteMeta[];
}

/** Critères des huit audiences du jeu de base (dans l'ordre de leur identifiant). */
const CRITERES: AudienceCriteria[] = [
  { all: [{ field: 'joignabilite', op: 'in', value: ['email_valide'] }] },
  { all: [{ field: 'region_code', op: 'in', value: ['84'] }, { field: 'tags', op: 'contains_any', value: ['organisateurs'] }] },
  { all: [{ field: 'region_code', op: 'in', value: ['84'] }, { field: 'tags', op: 'contains_any', value: ['presse-locale'] }] },
  { all: [{ field: 'department_code', op: 'in', value: ['73', '74'] }], not: [{ field: 'relation_type', op: 'in', value: ['client'] }] },
  { all: [{ field: 'department_code', op: 'in', value: ['42', '69'] }, { field: 'tags', op: 'contains_any', value: ['commerces-proximite'] }] },
  { all: [{ field: 'tags', op: 'contains_any', value: ['salon-artisans-2026'] }], not: [{ field: 'relation_type', op: 'in', value: ['client'] }] },
  { all: [{ field: 'sector_main', op: 'in', value: ['restauration'] }] },
  { all: [{ field: 'department_code', op: 'in', value: ['69'] }, { field: 'size_category', op: 'in', value: ['tpe'] }] },
];

export function fabriquerCiblage(alea: Alea): DonneesCiblage {
  const audiences: AudienceCiblage[] = demoDb.audiences.tous().map((a, i) => ({
    id: a.id,
    criteria: structuredClone(CRITERES[i % CRITERES.length]!),
    destinataires_mode: 'personne_sinon_generique',
    archivee: false,
  }));

  const entreprises = demoDb.entreprises.tous();
  const contacts = demoDb.contacts.tous();
  const membres: MembreCiblage[] = [];
  let decalage = 0;
  for (const liste of demoDb.listes.tous()) {
    for (let k = 0; k < liste.organisations; k += 1) {
      const e = entreprises[(decalage + k) % entreprises.length]!;
      membres.push({
        id: membres.length + 1, liste_id: liste.id, type: 'organisation',
        origine: alea.booleen(0.3) ? 'import' : 'coche', ajoute_le: liste.created_at,
        company_id: e.id, contact_id: null, retire_le: null,
      });
    }
    for (let k = 0; k < liste.personnes; k += 1) {
      const c = contacts[(decalage * 2 + k) % contacts.length]!;
      membres.push({
        id: membres.length + 1, liste_id: liste.id, type: 'personne',
        origine: alea.booleen(0.3) ? 'import' : 'coche', ajoute_le: liste.created_at,
        company_id: null, contact_id: c.id, retire_le: null,
      });
    }
    decalage += 17;
  }

  const categories: Record<string, EtiquetteMeta['categorie']> = {
    'salon-artisans-2026': 'custom', 'relance-printemps': 'custom', 'clients-2026': 'custom',
    'a-rappeler': 'custom', 'salon-lyon-2027': 'custom', partenaires: 'custom',
    'presse-locale': 'intent', 'prospects-chauds': 'intent', 'federations-btp': 'intent',
    'lettre-information': 'intent', 'commerces-proximite': 'sector', organisateurs: 'intent',
  };
  const etiquettesMeta: EtiquetteMeta[] = demoDb.etiquettes.tous().map((e) => ({
    id: e.slug, categorie: categories[e.slug] ?? 'custom', description: null, archivee: false,
  }));

  return {
    audiences,
    membres,
    listesEtat: demoDb.listes.tous().map((l) => ({ id: l.id, deleted_at: null })),
    etiquettesMeta,
  };
}
