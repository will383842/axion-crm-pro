/**
 * Ce que la personne a saisi dans l'assistant, et sa traduction vers le
 * contrat (`ContenuEtape`) pour l'enregistrement du brouillon à chaque étape.
 */
import type { Brouillon, Cible, ContenuEtape, EtapeSequence } from './api';
import { FENETRES, ajouterJours, estOuvre, parisVersIso } from './outils';

export interface LigneSequence {
  message_id: string;
  /** Jours ouvrés après le message précédent (0 pour le premier). */
  delai_jours: number;
}

export interface Saisie {
  nom: string;
  nomModifie: boolean;
  cible: { type: Cible['type']; reference: string; libelle: string } | null;
  exclusionsLibres: string;
  reverifier: boolean;
  sequence: LigneSequence[];
  ab: boolean;
  objetB: string;
  repli: Record<string, string>;
  suivreOuvertures: boolean;
  depart: 'immediat' | 'programme';
  jour: string;
  heure: string;
  fenetre: (typeof FENETRES)[number]['id'];
  boites: string[];
  /** Vrai dès que la personne a coché ou décoché une boîte (sinon : les boîtes prêtes). */
  boitesChoisies: boolean;
  plafond: number;
}

export const SAISIE_INITIALE: Saisie = {
  nom: '',
  nomModifie: false,
  cible: null,
  exclusionsLibres: '',
  reverifier: true,
  sequence: [{ message_id: 'msg-1', delai_jours: 0 }],
  ab: false,
  objetB: '',
  repli: {},
  // Décision de Will : la mesure des ouvertures est désactivée par défaut.
  suivreOuvertures: false,
  depart: 'programme',
  jour: '',
  heure: '09:00',
  fenetre: '09-17',
  boites: [],
  boitesChoisies: false,
  plafond: 80,
};

export function cibleDeContrat(s: Saisie): Cible | null {
  if (s.cible === null) return null;
  const libres = s.exclusionsLibres.split(/[,;\n]/).map((x) => x.trim()).filter((x) => x !== '');
  return { type: s.cible.type, reference: s.cible.reference, libelle: s.cible.libelle, reverifier_anciennes: s.reverifier, ...(libres.length > 0 ? { exclusions_libres: libres } : {}) };
}

export function sequenceDeContrat(s: Saisie): EtapeSequence[] {
  return s.sequence.map((l, i) => ({
    etape: i + 1,
    message_id: l.message_id,
    delai_jours: i === 0 ? 0 : l.delai_jours,
    ...(i === 0 && s.ab && s.objetB.trim() !== '' ? { objet_b: s.objetB.trim() } : {}),
  }));
}

/** Les clés de l'étape, et seulement elles (le serveur ne lit que celles-là). */
export function contenuEtape(etape: number, s: Saisie): ContenuEtape {
  if (etape === 1) {
    const cible = cibleDeContrat(s);
    return { nom: s.nom, ...(cible ? { cible } : {}) };
  }
  if (etape === 2) {
    return { sequence: sequenceDeContrat(s), repli: s.repli };
  }
  if (etape === 3) {
    const fenetre = FENETRES.find((f) => f.id === s.fenetre) ?? FENETRES[0];
    const jours: Array<'lun' | 'mar' | 'mer' | 'jeu' | 'ven'> = ['lun', 'mar', 'mer', 'jeu', 'ven'];
    return {
      depart_immediat: s.depart === 'immediat',
      ...(s.depart === 'programme' && s.jour !== '' ? { debut_le: parisVersIso(s.jour, s.heure) } : {}),
      jours,
      heures: { de: fenetre.de, a: fenetre.a },
      boites: s.boites,
      plafond_jour: Math.max(1, s.plafond),
    };
  }
  return {};
}

/** Reprend un brouillon enregistré. */
export function saisieDepuis(b: Brouillon): Saisie {
  const c = b.contenu;
  const s: Saisie = { ...SAISIE_INITIALE, nom: b.nom, nomModifie: true };
  if (c.cible) {
    s.cible = { type: c.cible.type, reference: c.cible.reference, libelle: c.cible.libelle ?? c.cible.reference };
    s.exclusionsLibres = (c.cible.exclusions_libres ?? []).join(', ');
    s.reverifier = c.cible.reverifier_anciennes !== false;
  }
  if (c.sequence && c.sequence.length > 0) {
    s.sequence = c.sequence.map((e) => ({ message_id: e.message_id, delai_jours: e.delai_jours }));
    const b2 = c.sequence[0]?.objet_b;
    s.ab = typeof b2 === 'string' && b2 !== '';
    s.objetB = typeof b2 === 'string' ? b2 : '';
  }
  s.repli = c.repli ?? {};
  if (c.depart_immediat === true) s.depart = 'immediat';
  if (c.debut_le) {
    const d = new Date(c.debut_le);
    const p = new Intl.DateTimeFormat('fr-CA', { timeZone: 'Europe/Paris', year: 'numeric', month: '2-digit', day: '2-digit' }).format(d);
    const h = new Intl.DateTimeFormat('fr-FR', { timeZone: 'Europe/Paris', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(d);
    s.jour = p;
    s.heure = h;
  }
  if (c.heures) s.fenetre = FENETRES.find((f) => f.de === c.heures?.de)?.id ?? '09-17';
  if (c.boites) {
    s.boites = c.boites;
    s.boitesChoisies = true;
  }
  if (c.plafond_jour) s.plafond = c.plafond_jour;
  return s;
}

/** Premier jour d'envoi réel : le jour choisi, ou le prochain jour ouvré. */
export function premierJourOuvre(jour: string): string {
  let j = jour;
  while (!estOuvre(j)) j = ajouterJours(j, 1);
  return j;
}
