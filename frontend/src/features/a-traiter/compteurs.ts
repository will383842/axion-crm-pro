/**
 * PASTILLES « À TRAITER » DU MENU (audit UX du 02/10/2026, lot 8).
 *
 * `GET /crm/a-traiter/compteurs` rend `{ doublons, a_rattacher, relances }` :
 * le total des files « Doublons à vérifier » et « Personnes à rattacher »,
 * calculé par les MÊMES requêtes que leurs écrans (le chiffre du menu = le
 * total de l'écran), et — depuis le nouvel accueil (03/10/2026) — celui des
 * « Relances à faire » de l'écran Événements. `relances` n'a pas de pastille
 * dans le menu : seul l'accueil le montre.
 *
 * `propositions` (N13, 03/10/2026) : les « Propositions à valider » (valeurs
 * venues d'un tiers). Rôle owner seulement : le serveur rend `null` à tout
 * autre rôle, donc aucune pastille.
 *
 * Règles d'affichage :
 *  - un compteur `null` (échec, file inexistante dans cet univers) ou absent
 *    n'affiche RIEN — jamais un 0 inventé ;
 *  - 0 n'affiche rien non plus : une pastille dit « il y a quelque chose » ;
 *  - au-delà de 999, la pastille dit « 999+ » ; le libellé lu par les
 *    lecteurs d'écran garde le nombre exact, au format français (1 234).
 */
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

export type CleCompteur = 'doublons' | 'a_rattacher' | 'propositions';

/**
 * `relances` est ABSENT (`undefined`) quand le serveur ne l'envoie pas (image
 * antérieure au nouvel accueil) : l'accueil n'affiche alors pas la carte —
 * ni chiffre inventé, ni fausse panne. `null` = le serveur n'a pas pu compter.
 */
export type CompteursATraiter = Record<'doublons' | 'a_rattacher', number | null> & {
  relances?: number | null;
  /** Absent d'une image serveur antérieure à N13 ; `null` pour tout rôle autre que owner. */
  propositions?: number | null;
};

export const COMPTEURS_A_TRAITER_KEY = ['crm', 'a-traiter', 'compteurs'] as const;

/** Rafraîchissement : le serveur garde le résultat 60 s, inutile de demander plus souvent. */
export const RAFRAICHISSEMENT_MS = 60_000;

/**
 * Ne garde que des entiers positifs ou nuls ; tout le reste (absent, texte,
 * négatif, non entier) devient `null` — donc pas de pastille.
 */
function entierOuNull(valeur: unknown): number | null {
  return typeof valeur === 'number' && Number.isInteger(valeur) && valeur >= 0 ? valeur : null;
}

export function normaliserCompteurs(brut: unknown): CompteursATraiter {
  const objet = (typeof brut === 'object' && brut !== null ? brut : {}) as Record<string, unknown>;
  return {
    doublons: entierOuNull(objet['doublons']),
    a_rattacher: entierOuNull(objet['a_rattacher']),
    ...('relances' in objet ? { relances: entierOuNull(objet['relances']) } : {}),
    ...('propositions' in objet ? { propositions: entierOuNull(objet['propositions']) } : {}),
  };
}

/**
 * `actif` : la route vit derrière le drapeau de la console (404 sinon) — on
 * ne la demande pas quand la console est fermée.
 *
 * Toutes les 60 s, et JAMAIS onglet caché (`refetchIntervalInBackground:
 * false`) : un menu ouvert dans un onglet oublié ne doit pas interroger le
 * serveur toute la journée. Le retour sur l'onglet relance une lecture.
 */
export function useCompteursATraiter(actif: boolean) {
  return useQuery<CompteursATraiter>({
    queryKey: COMPTEURS_A_TRAITER_KEY,
    queryFn: async () => normaliserCompteurs((await api.get<unknown>('/crm/a-traiter/compteurs')).data),
    enabled: actif,
    refetchInterval: RAFRAICHISSEMENT_MS,
    refetchIntervalInBackground: false,
    refetchOnWindowFocus: true,
    staleTime: RAFRAICHISSEMENT_MS - 5_000,
    // Une pastille manquante n'est pas une panne : pas de rafale de réessais.
    retry: false,
  });
}

const FORMATEUR = new Intl.NumberFormat('fr-FR');

/** Le nombre au format français : 1 234 (espace fine insécable). */
export function formaterNombre(n: number): string {
  return FORMATEUR.format(n);
}

/** Ce que la pastille AFFICHE : le nombre, ou « 999+ » au-delà. */
export function texteDePastille(n: number): string {
  return n > 999 ? '999+' : formaterNombre(n);
}

/** Ce que la pastille DIT aux lecteurs d'écran, avec le nombre exact. */
export const LIBELLES_COMPTEUR: Record<CleCompteur, (n: number) => string> = {
  doublons: (n) => (n === 1 ? '1 doublon à vérifier' : `${formaterNombre(n)} doublons à vérifier`),
  a_rattacher: (n) => (n === 1 ? '1 personne à rattacher' : `${formaterNombre(n)} personnes à rattacher`),
  propositions: (n) => (n === 1 ? '1 proposition à valider' : `${formaterNombre(n)} propositions à valider`),
};

/**
 * Total d'une section du menu REPLIÉE (03/10/2026) : la somme des compteurs
 * CONNUS de ses entrées. Un compteur `null` ou absent est ignoré — il ne vaut
 * pas 0, il ne vaut rien ; si AUCUN n'est connu, le total est `null` (pas de
 * pastille), jamais un 0 inventé.
 */
export function totalDesCompteurs(
  compteurs: CompteursATraiter | undefined,
  cles: ReadonlyArray<CleCompteur>,
): number | null {
  if (compteurs === undefined) return null;
  let total: number | null = null;
  for (const cle of cles) {
    const valeur = compteurs[cle];
    if (typeof valeur === 'number') total = (total ?? 0) + valeur;
  }
  return total;
}

/** Ce que dit la pastille du TITRE de section repliée. */
export function libelleTotalATraiter(n: number): string {
  return n === 1 ? '1 élément à traiter' : `${formaterNombre(n)} éléments à traiter`;
}
