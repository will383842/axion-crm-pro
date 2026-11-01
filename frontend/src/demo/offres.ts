/**
 * Les trois offres de la démo (« Voir comme » du bandeau) : les deux produits,
 * CRM seul, Envoi seul. Le choix vit dans la session du navigateur et se lit
 * dans `/config/features` (`produits.crm`, `produits.envoi`).
 */
export type Offre = 'les-deux' | 'crm' | 'envoi';
export type EtatProduit = 'actif' | 'suspendu' | 'resilie' | 'absent';

export const OFFRES: ReadonlyArray<{ valeur: Offre; libelle: string }> = [
  { valeur: 'les-deux', libelle: 'Les deux produits' },
  { valeur: 'crm', libelle: 'CRM seul' },
  { valeur: 'envoi', libelle: 'Envoi seul' },
];

export const CLE_OFFRE = 'axion-demo:offre';

export function estOffre(v: unknown): v is Offre {
  return v === 'les-deux' || v === 'crm' || v === 'envoi';
}

export function lireOffre(): Offre {
  try {
    const v = globalThis.sessionStorage?.getItem(CLE_OFFRE);
    return estOffre(v) ? v : 'les-deux';
  } catch {
    return 'les-deux';
  }
}

export function choisirOffre(offre: Offre): void {
  try {
    globalThis.sessionStorage?.setItem(CLE_OFFRE, offre);
  } catch {
    // stockage indisponible : l'offre revient à « les deux » au rechargement.
  }
}

/** Droits par produit d'une offre. */
export function produitsDeLOffre(offre: Offre): { crm: EtatProduit; envoi: EtatProduit } {
  return {
    crm: offre === 'envoi' ? 'absent' : 'actif',
    envoi: offre === 'crm' ? 'absent' : 'actif',
  };
}
