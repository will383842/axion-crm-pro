/**
 * Lecture d'un fichier CSV dans le navigateur (aucun envoi : la démo n'a pas
 * de serveur réel). Gère les guillemets, le séparateur « ; », « , » ou tabulation,
 * et reconnaît les colonnes par leur titre.
 */
import type { ChampImport, LigneImport } from '../ajout-rapide/types';

export interface FichierLu {
  colonnes: string[];
  lignes: string[][];
}

function detecterSeparateur(entete: string): string {
  const candidats = [';', ',', '\t'];
  return candidats.reduce((meilleur, c) => (entete.split(c).length > entete.split(meilleur).length ? c : meilleur), ';');
}

export function lireCsv(texte: string): FichierLu {
  const brut = texte.replace(/^\uFEFF/, '').replace(/\r\n?/g, '\n');
  const separateur = detecterSeparateur(brut.split('\n', 1)[0] ?? '');
  const lignes: string[][] = [];
  let ligne: string[] = [];
  let champ = '';
  let entreGuillemets = false;
  for (let i = 0; i < brut.length; i += 1) {
    const c = brut[i]!;
    if (entreGuillemets) {
      if (c === '"' && brut[i + 1] === '"') {
        champ += '"';
        i += 1;
      } else if (c === '"') entreGuillemets = false;
      else champ += c;
    } else if (c === '"') entreGuillemets = true;
    else if (c === separateur) {
      ligne.push(champ);
      champ = '';
    } else if (c === '\n') {
      ligne.push(champ);
      lignes.push(ligne);
      ligne = [];
      champ = '';
    } else champ += c;
  }
  if (champ !== '' || ligne.length > 0) {
    ligne.push(champ);
    lignes.push(ligne);
  }
  const utiles = lignes.filter((l) => l.some((c) => c.trim() !== ''));
  const [entete, ...corps] = utiles;
  return { colonnes: (entete ?? []).map((c) => c.trim()), lignes: corps };
}

const sansAccents = (t: string): string => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z]/g, '');

const SYNONYMES: Record<Exclude<ChampImport, 'ignorer'>, string[]> = {
  prenom: ['prenom', 'firstname', 'prenomusuel'],
  nom: ['nom', 'lastname', 'nomdefamille', 'nomfamille'],
  email: ['email', 'mail', 'courriel', 'adresseemail', 'adressemail'],
  organisation: ['organisation', 'entreprise', 'societe', 'denomination', 'organization'],
  fonction: ['fonction', 'poste', 'role', 'titre'],
  provenance: ['provenance', 'source', 'origine'],
};

/** Devine, pour chaque colonne, le champ qu'elle remplit. */
export function deviner(colonnes: readonly string[]): ChampImport[] {
  const pris = new Set<ChampImport>();
  return colonnes.map((c) => {
    const cle = sansAccents(c);
    const trouve = (Object.keys(SYNONYMES) as Array<Exclude<ChampImport, 'ignorer'>>).find((champ) => !pris.has(champ) && SYNONYMES[champ].includes(cle));
    if (trouve === undefined) return 'ignorer';
    pris.add(trouve);
    return trouve;
  });
}

/** Met les lignes lues au format que contrôle et importe le faux serveur. */
export function versLignes(fichier: FichierLu, correspondance: readonly ChampImport[], provenanceParDefaut: string): LigneImport[] {
  return fichier.lignes.map((cellules, i) => {
    const valeur = (champ: ChampImport): string => {
      const rang = correspondance.indexOf(champ);
      return rang === -1 ? '' : (cellules[rang] ?? '').trim();
    };
    return {
      // Numéro de ligne tel que l'affiche un tableur (l'en-tête est la ligne 1).
      ligne: i + 2,
      prenom: valeur('prenom'),
      nom: valeur('nom'),
      email: valeur('email'),
      organisation: valeur('organisation'),
      fonction: valeur('fonction'),
      provenance: valeur('provenance') || provenanceParDefaut,
    };
  });
}

/** Lit un `File` en texte (FileReader : marche aussi sous jsdom). */
export function lireTexte(fichier: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const lecteur = new FileReader();
    lecteur.onload = () => resolve(typeof lecteur.result === 'string' ? lecteur.result : '');
    lecteur.onerror = () => reject(lecteur.error ?? new Error('Lecture impossible'));
    lecteur.readAsText(fichier, 'utf-8');
  });
}
