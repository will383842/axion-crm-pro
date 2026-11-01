/**
 * DM0-COQ — LE REGISTRE DES COMMANDES DE CTRL K.
 *
 * Chaque lot ajoute ses actions dans SON fichier
 * `src/features/<produit>/<zone>/commandes.ts` :
 *
 *     export const commandes: CommandeRecherche[] = [
 *       { id: 'envoi-chercher', libelle: 'Chercher un envoi', produit: 'envoi',
 *         motsCles: ['adresse'], aller: '/envoi/sante/chercher' },
 *     ];
 *
 * Ce registre les charge tout seul (`import.meta.glob`, au build) : personne
 * ne touche `GlobalSearch.tsx`. Un module sans export `commandes`, une
 * commande sans `aller` ni `agir`, ou deux commandes de même identifiant
 * lèvent une erreur NOMMÉE dès le chargement : un oubli ne passe pas en
 * silence.
 */
import type { Produit } from '../produit/useProduitCourant';
import type { EspaceProduit } from '../produit/dernierePage';

export interface CommandeRecherche {
  /** Identifiant stable, unique dans tout le registre (préfixé par la zone). */
  id: string;
  /** Ce que la palette affiche (verbe à l'infinitif ou nom d'écran). */
  libelle: string;
  /** Marque de produit affichée à côté du libellé. */
  produit: Produit;
  /** Autres mots qui doivent trouver la commande. */
  motsCles: readonly string[];
  /** Chemin interne où mener. */
  aller?: string;
  /** Ou : une action immédiate. */
  agir?: () => void;
}

export type ModulesDeCommandes = Record<string, { commandes?: readonly CommandeRecherche[] }>;

/** Lit des modules `commandes.ts` (forme rendue par `import.meta.glob`, `eager`). */
export function chargerCommandes(modules: ModulesDeCommandes): CommandeRecherche[] {
  const commandes: CommandeRecherche[] = [];
  const vus = new Map<string, string>();
  for (const [fichier, module] of Object.entries(modules)) {
    const brut: unknown = module.commandes;
    const liste = module.commandes;
    if (liste === undefined || !Array.isArray(brut)) {
      throw new Error(`Ctrl K : ${fichier} n’exporte pas « commandes » (export const commandes: CommandeRecherche[]).`);
    }
    for (const commande of liste) {
      if (commande.aller === undefined && commande.agir === undefined) {
        throw new Error(`Ctrl K : la commande « ${commande.id} » de ${fichier} n’a ni « aller » ni « agir ».`);
      }
      const deja = vus.get(commande.id);
      if (deja !== undefined) {
        throw new Error(`Ctrl K : l’identifiant « ${commande.id} » est déclaré deux fois (${deja} et ${fichier}).`);
      }
      vus.set(commande.id, fichier);
      commandes.push(commande);
    }
  }
  return commandes;
}

const MODULES = import.meta.glob<{ commandes?: readonly CommandeRecherche[] }>('../../**/commandes.ts', { eager: true });

let enCache: CommandeRecherche[] | null = null;

/** Les commandes de tous les `src/features/**\/commandes.ts`. */
export function commandesDuRegistre(): CommandeRecherche[] {
  enCache ??= chargerCommandes(MODULES);
  return enCache;
}

function normaliser(texte: string): string {
  return texte.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
}

/**
 * Les commandes qui répondent à la recherche, pour les produits visibles. Une
 * commande « commun » se montre dès qu'un produit est visible.
 */
export function filtrerCommandes(
  commandes: readonly CommandeRecherche[],
  recherche: string,
  visibles: Record<EspaceProduit, boolean>,
): CommandeRecherche[] {
  const q = normaliser(recherche);
  if (q === '') return [];
  return commandes.filter((c) => {
    const produitVisible = c.produit === 'commun' ? visibles.crm || visibles.envoi : visibles[c.produit];
    if (!produitVisible) return false;
    return [c.libelle, ...c.motsCles].some((mot) => normaliser(mot).includes(q));
  });
}
